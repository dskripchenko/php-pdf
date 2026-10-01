<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Forms\ExistingFormFiller;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Real-world form shapes the authoring side never produces: inherited page
 * resources, inline or missing /DR fonts, auto-size /DA, non-Latin values,
 * /Opt, deep hierarchies, rotated and cropped pages, XFA, signatures.
 */
final class ExistingFormFillerShapesTest extends TestCase
{
    private const CHECKBOX_AP = "<< /Type /XObject /Subtype /Form /BBox [0 0 14 14] /Length 13 >>\nstream\n0 0 14 14 re f\nendstream";

    #[Test]
    public function flatten_keeps_resources_the_page_inherits(): void
    {
        $pdf = new FormPdf();
        $pdf->pagesAttributes = '/MediaBox [0 0 612 792] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Times-Roman >> >> >>';
        $pdf->pageAttributes = '';
        $pdf->textField('name');

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())
            ->setValue('name', 'Jane')
            ->flatten()
            ->stampImage(0, __DIR__.'/../../fixtures/sample.png', 10, 10, 20, 20)
            ->toBytes();

        $resources = ReaderDocument::fromBytes($out)->pages()[0]->resources;
        $doc = ReaderDocument::fromBytes($out);
        $fonts = $doc->deref($resources?->get('Font'));
        self::assertInstanceOf(PdfDictionary::class, $fonts);
        self::assertTrue($fonts->has('F1'), 'the inherited font must survive');
        $xobjects = $doc->deref($resources->get('XObject'));
        self::assertInstanceOf(PdfDictionary::class, $xobjects);
        self::assertCount(2, $xobjects->all(), 'the flattened field and the stamp');
    }

    #[Test]
    public function existing_content_is_isolated_from_appended_drawing(): void
    {
        $pdf = new FormPdf();
        // Leaves a scaling transformation active, as some generators do.
        $pdf->content = '0.5 0 0 0.5 0 0 cm BT /F1 12 Tf 72 760 Td (Scaled) Tj ET';
        $pdf->textField('name');

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())->setValue('name', 'Jane')->flatten()->toBytes();
        $doc = ReaderDocument::fromBytes($out);
        $contents = $doc->deref($doc->pages()[0]->dict->get('Contents'));
        self::assertIsArray($contents);

        $first = $doc->deref($contents[0]);
        $last = $doc->deref($contents[count($contents) - 1]);
        self::assertInstanceOf(PdfStream::class, $first);
        self::assertInstanceOf(PdfStream::class, $last);
        self::assertSame('q', trim($doc->streamData($first)));
        self::assertStringStartsWith("Q\n", $doc->streamData($last));
    }

    #[Test]
    public function auto_size_da_gets_a_real_font_size(): void
    {
        $pdf = new FormPdf();
        $pdf->textField('name', extra: '/DA (/Helv 0 Tf 0 g)');

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())->setValue('name', 'AutoSize')->flatten()->toBytes();
        $drawn = FormPdf::drawn(ReaderDocument::fromBytes($out));

        self::assertMatchesRegularExpression('@/F0 (\d+(\.\d+)?) Tf@', $drawn);
        preg_match('@/F0 (\d+(?:\.\d+)?) Tf@', $drawn, $m);
        self::assertGreaterThan(4.0, (float) $m[1]);
        self::assertStringContainsString('(AutoSize) Tj', $drawn);
    }

    #[Test]
    public function an_inline_dr_font_is_registered_with_the_appearance(): void
    {
        $pdf = new FormPdf();
        $pdf->acroFormAttributes = '/DR << /Font << /Helv << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >>';
        $pdf->textField('name');

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())->setValue('name', 'Jane')->toBytes();
        $doc = ReaderDocument::fromBytes($out);
        $ap = $doc->deref($doc->deref(self::annot($doc, 0)->get('AP'))->get('N'));

        self::assertInstanceOf(PdfStream::class, $ap);
        $fonts = $doc->deref($doc->deref($ap->dict->get('Resources'))->get('Font'));
        self::assertInstanceOf(PdfDictionary::class, $fonts->get('F0') instanceof PdfReference ? $doc->deref($fonts->get('F0')) : null);
        self::assertStringContainsString('/F0 10 Tf', $doc->streamData($ap));
    }

    #[Test]
    public function a_missing_dr_falls_back_to_the_standard_font(): void
    {
        $pdf = new FormPdf();
        $pdf->acroFormAttributes = '';
        $pdf->textField('name', extra: '/DA (/TiRo 11 Tf 0 g)');

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())->setValue('name', 'Jane')->flatten()->toBytes();

        self::assertStringContainsString('/BaseFont /Times-Roman', $out);
        self::assertStringContainsString('(Jane) Tj', FormPdf::drawn(ReaderDocument::fromBytes($out)));
    }

    #[Test]
    public function non_ascii_values_are_stored_as_utf16_text_strings(): void
    {
        $font = FormPdf::liberationSans() ?? self::markTestSkipped('Liberation fonts not fetched');
        $pdf = new FormPdf();
        $pdf->textField('name');

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())->useFont($font)->setValue('name', 'Иван Петров')->toBytes();
        $doc = ReaderDocument::fromBytes($out);

        $v = self::annot($doc, 0)->get('V');
        self::assertInstanceOf(PdfString::class, $v);
        self::assertStringStartsWith("\xFE\xFF", $v->bytes);
        self::assertSame('Иван Петров', ExistingFormFiller::fromBytes($out)->fields()['name']->value);
    }

    #[Test]
    public function non_ascii_values_are_drawn_with_an_embedded_unicode_font(): void
    {
        $font = FormPdf::liberationSans() ?? self::markTestSkipped('Liberation fonts not fetched');
        $pdf = new FormPdf();
        $pdf->textField('name');

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())->useFont($font)->setValue('name', 'Иван')->flatten()->toBytes();

        self::assertStringContainsString('/Subtype /Type0', $out);
        self::assertStringContainsString('/Encoding /Identity-H', $out);
        self::assertMatchesRegularExpression('@<[0-9A-F]{16}> Tj@', FormPdf::drawn(ReaderDocument::fromBytes($out)));
    }

    #[Test]
    public function non_ascii_values_without_a_unicode_font_fail_clearly(): void
    {
        if (class_exists('Dskripchenko\\PhpPdfFontsLiberation\\LiberationFontProvider')) {
            self::markTestSkipped('php-pdf-fonts-liberation is installed and supplies the font');
        }
        $pdf = new FormPdf();
        $pdf->textField('name');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('useFont()');
        ExistingFormFiller::fromBytes($pdf->toBytes())->setValue('name', 'Иван');
    }

    #[Test]
    public function latin1_values_stay_in_the_form_font(): void
    {
        $pdf = new FormPdf();
        $pdf->textField('name');

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())->setValue('name', 'Zoë Müller')->flatten()->toBytes();

        self::assertStringNotContainsString('/Type0', $out);
        self::assertStringContainsString('(Zo\\353 M\\374ller) Tj', FormPdf::drawn(ReaderDocument::fromBytes($out)));
    }

    #[Test]
    public function flatten_draws_the_checked_appearance_of_a_checkbox(): void
    {
        $pdf = new FormPdf();
        $on = $pdf->add(self::CHECKBOX_AP);
        $off = $pdf->add("<< /Type /XObject /Subtype /Form /BBox [0 0 14 14] /Length 0 >>\nstream\n\nendstream");
        $box = $pdf->add(sprintf(
            '<< /Type /Annot /Subtype /Widget /FT /Btn /T (agree) /Rect [100 650 114 664] /P %d 0 R /F 4 /V /Off /AS /Off /AP << /N << /Ja %d 0 R /Off %d 0 R >> >> >>',
            $pdf->page,
            $on,
            $off,
        ));
        $pdf->field($box)->widget($box);

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())->setValue('agree', true)->flatten()->toBytes();
        $drawn = FormPdf::drawn(ReaderDocument::fromBytes($out));

        self::assertStringContainsString('0 0 14 14 re f', $drawn, 'the /Ja (on) appearance is painted');
        self::assertMatchesRegularExpression('@q 1 0 0 1 100 650 cm /FlatAP\d+ Do Q@', $drawn);
    }

    #[Test]
    public function a_checkbox_without_appearance_gets_a_generated_mark(): void
    {
        $pdf = new FormPdf();
        $box = $pdf->add(sprintf(
            '<< /Type /Annot /Subtype /Widget /FT /Btn /T (agree) /Rect [100 650 114 664] /P %d 0 R /F 4 /V /Yes /AS /Yes /MK << /BC [0] >> >>',
            $pdf->page,
        ));
        $pdf->field($box)->widget($box);

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())->flatten()->toBytes();

        self::assertStringContainsString('/BaseFont /ZapfDingbats', $out);
        self::assertStringContainsString('(4) Tj', FormPdf::drawn(ReaderDocument::fromBytes($out)));
    }

    #[Test]
    public function hidden_widgets_are_not_drawn(): void
    {
        $pdf = new FormPdf();
        $pdf->textField('name', extra: '/DA (/Helv 10 Tf 0 g) /V (Secret)');
        $hidden = $pdf->add(sprintf(
            '<< /Type /Annot /Subtype /Widget /FT /Tx /T (hidden) /Rect [100 600 300 620] /P %d 0 R /F 6 /DA (/Helv 10 Tf 0 g) /V (Invisible) >>',
            $pdf->page,
        ));
        $pdf->field($hidden)->widget($hidden);
        $pdf->acroFormAttributes .= ' /NeedAppearances true';

        $drawn = FormPdf::drawn(ReaderDocument::fromBytes(ExistingFormFiller::fromBytes($pdf->toBytes())->flatten()->toBytes()));

        self::assertStringContainsString('(Secret) Tj', $drawn);
        self::assertStringNotContainsString('Invisible', $drawn);
    }

    #[Test]
    public function stale_appearances_are_regenerated_when_the_form_asks_for_it(): void
    {
        $pdf = new FormPdf();
        $stale = $pdf->add("<< /Type /XObject /Subtype /Form /BBox [0 0 200 20] /Length 27 >>\nstream\nBT (Old value) Tj ET 0 0 m\nendstream");
        $pdf->textField('name', extra: sprintf('/DA (/Helv 10 Tf 0 g) /V (New value) /AP << /N %d 0 R >>', $stale));
        $pdf->acroFormAttributes .= ' /NeedAppearances true';

        $drawn = FormPdf::drawn(ReaderDocument::fromBytes(ExistingFormFiller::fromBytes($pdf->toBytes())->flatten()->toBytes()));

        self::assertStringContainsString('(New value) Tj', $drawn);
        self::assertStringNotContainsString('Old value', $drawn);
    }

    #[Test]
    public function an_existing_appearance_is_mapped_through_its_matrix(): void
    {
        $pdf = new FormPdf();
        // BBox 0..100 x 0..10 scaled 2x by /Matrix, placed on a 100x10 rect.
        $ap = $pdf->add("<< /Type /XObject /Subtype /Form /BBox [0 0 100 10] /Matrix [2 0 0 2 0 0] /Length 13 >>\nstream\n0 0 10 10 re f\nendstream");
        $pdf->textField('name', '50 50 150 60', sprintf('/DA (/Helv 10 Tf 0 g) /AP << /N %d 0 R >>', $ap));

        $drawn = FormPdf::drawn(ReaderDocument::fromBytes(ExistingFormFiller::fromBytes($pdf->toBytes())->flatten()->toBytes()));

        self::assertMatchesRegularExpression('@q 0\.5 0 0 0\.5 50 50 cm /FlatAP\d+ Do Q@', $drawn);
    }

    #[Test]
    public function comb_fields_place_one_character_per_cell(): void
    {
        $pdf = new FormPdf();
        $pdf->textField('code', '0 0 100 20', '/DA (/Helv 10 Tf 0 g) /Ff 16777216 /MaxLen 4');

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())->setValue('code', '1234')->flatten()->toBytes();
        $drawn = FormPdf::drawn(ReaderDocument::fromBytes($out));

        foreach (['(1) Tj', '(2) Tj', '(3) Tj', '(4) Tj'] as $glyph) {
            self::assertStringContainsString($glyph, $drawn);
        }
        // Helvetica digits are 556/1000 wide: cell 25pt, (25 - 5.56) / 2 = 9.72.
        self::assertStringContainsString('1 0 0 1 9.72', $drawn);
        self::assertStringContainsString('1 0 0 1 34.72', $drawn);
    }

    #[Test]
    public function max_length_is_enforced(): void
    {
        $pdf = new FormPdf();
        $pdf->textField('state', extra: '/DA (/Helv 10 Tf 0 g) /MaxLen 2');

        $this->expectException(\InvalidArgumentException::class);
        ExistingFormFiller::fromBytes($pdf->toBytes())->setValue('state', 'Texas');
    }

    #[Test]
    public function multiline_text_is_wrapped_inside_the_field(): void
    {
        $pdf = new FormPdf();
        $pdf->textField('notes', '0 0 120 80', '/DA (/Helv 10 Tf 0 g) /Ff 4096');

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())
            ->setValue('notes', "First paragraph that is long enough to wrap\nSecond")
            ->flatten()
            ->toBytes();

        self::assertGreaterThanOrEqual(3, substr_count(FormPdf::drawn(ReaderDocument::fromBytes($out)), ' Tj'));
    }

    #[Test]
    public function a_rotated_widget_gets_a_rotated_appearance(): void
    {
        $pdf = new FormPdf();
        $pdf->textField('vertical', '0 0 20 100', '/DA (/Helv 10 Tf 0 g) /MK << /R 90 >>');

        $doc = ReaderDocument::fromBytes(ExistingFormFiller::fromBytes($pdf->toBytes())->setValue('vertical', 'Up')->toBytes());
        $ap = $doc->deref($doc->deref(self::annot($doc, 0)->get('AP'))->get('N'));

        self::assertInstanceOf(PdfStream::class, $ap);
        self::assertSame([0, 0, 100, 20], $ap->dict->get('BBox'));
        self::assertSame([0, 1, -1, 0, 20, 0], $ap->dict->get('Matrix'));
    }

    #[Test]
    public function choice_values_are_validated_against_opt(): void
    {
        $pdf = new FormPdf();
        $pdf->textField('car', extra: '/FT /Ch /Ff 131072 /DA (/Helv 10 Tf 0 g) /Opt [[(hon) (Honda)] [(toy) (Toyota)]]');
        $filler = ExistingFormFiller::fromBytes($pdf->toBytes());

        self::assertSame(['hon', 'toy'], $filler->fields()['car']->options);
        self::assertSame(['hon' => 'Honda', 'toy' => 'Toyota'], $filler->fields()['car']->optionLabels);

        $out = $filler->setValue('car', 'Toyota')->flatten()->toBytes();
        self::assertStringContainsString('(Toyota) Tj', FormPdf::drawn(ReaderDocument::fromBytes($out)));

        $this->expectException(\InvalidArgumentException::class);
        ExistingFormFiller::fromBytes($pdf->toBytes())->setValue('car', 'Lada');
    }

    #[Test]
    public function a_multi_select_list_box_takes_several_values(): void
    {
        $pdf = new FormPdf();
        $pdf->textField('langs', '0 0 100 60', '/FT /Ch /Ff 2097152 /DA (/Helv 10 Tf 0 g) /Opt [(en) (de) (ru)]');

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())->setValue('langs', ['ru', 'en'])->toBytes();
        $doc = ReaderDocument::fromBytes($out);
        $widget = self::annot($doc, 0);

        self::assertSame([0, 2], $widget->get('I'));
        self::assertSame(['ru', 'en'], ExistingFormFiller::fromBytes($out)->fields()['langs']->value);
    }

    #[Test]
    public function radio_options_come_from_opt_when_present(): void
    {
        $pdf = new FormPdf();
        $on = $pdf->add(self::CHECKBOX_AP);
        $group = $pdf->reserve();
        $kids = [];
        foreach (['0', '1'] as $i => $state) {
            $kids[] = $kid = $pdf->add(sprintf(
                '<< /Type /Annot /Subtype /Widget /Parent %d 0 R /Rect [%d 650 %d 664] /P %d 0 R /F 4 /AS /Off /AP << /N << /%s %d 0 R /Off %d 0 R >> >> >>',
                $group,
                100 + 20 * $i,
                114 + 20 * $i,
                $pdf->page,
                $state,
                $on,
                $on,
            ));
            $pdf->widget($kid);
        }
        $pdf->set($group, sprintf('<< /FT /Btn /Ff 49152 /T (plan) /V /Off /Opt [(Basic) (Premium)] /Kids [%d 0 R %d 0 R] >>', ...$kids));
        $pdf->field($group);

        $filler = ExistingFormFiller::fromBytes($pdf->toBytes());
        self::assertSame(['Basic', 'Premium'], $filler->fields()['plan']->options);

        $doc = ReaderDocument::fromBytes($filler->setValue('plan', 'premium')->toBytes());
        self::assertSame('Off', self::annot($doc, 0)->get('AS')->value);
        self::assertSame('1', self::annot($doc, 1)->get('AS')->value);
    }

    #[Test]
    public function unknown_button_values_are_rejected(): void
    {
        $pdf = new FormPdf();
        $on = $pdf->add(self::CHECKBOX_AP);
        $box = $pdf->add(sprintf(
            '<< /Type /Annot /Subtype /Widget /FT /Btn /T (agree) /Rect [100 650 114 664] /P %d 0 R /AS /Off /AP << /N << /Yes %d 0 R /Off %d 0 R >> >> >>',
            $pdf->page,
            $on,
            $on,
        ));
        $pdf->field($box)->widget($box);

        $this->expectException(\InvalidArgumentException::class);
        ExistingFormFiller::fromBytes($pdf->toBytes())->setValue('agree', 'maybe');
    }

    #[Test]
    public function signature_and_push_button_fields_cannot_be_set(): void
    {
        $pdf = new FormPdf();
        $sig = $pdf->add(sprintf('<< /Type /Annot /Subtype /Widget /FT /Sig /T (sig) /Rect [0 0 100 30] /P %d 0 R >>', $pdf->page));
        $pdf->field($sig)->widget($sig);
        $filler = ExistingFormFiller::fromBytes($pdf->toBytes());

        self::assertSame('signature', $filler->fields()['sig']->type);
        $this->expectException(\LogicException::class);
        $filler->setValue('sig', 'x');
    }

    #[Test]
    public function flattening_every_leaf_of_a_deep_hierarchy_drops_the_acroform(): void
    {
        $pdf = new FormPdf();
        $root = $pdf->reserve();
        $middle = $pdf->reserve();
        $leaf = $pdf->add(sprintf(
            '<< /Type /Annot /Subtype /Widget /FT /Tx /T (street) /Parent %d 0 R /Rect [0 0 100 20] /P %d 0 R /DA (/Helv 10 Tf 0 g) >>',
            $middle,
            $pdf->page,
        ));
        $pdf->set($middle, sprintf('<< /T (home) /Parent %d 0 R /Kids [%d 0 R] >>', $root, $leaf));
        $pdf->set($root, sprintf('<< /T (address) /Kids [%d 0 R] >>', $middle));
        $pdf->field($root)->widget($leaf);

        $filler = ExistingFormFiller::fromBytes($pdf->toBytes());
        self::assertArrayHasKey('address.home.street', $filler->fields());

        $out = $filler->setValue('address.home.street', 'Main St')->flatten(['address.home.street'])->toBytes();

        self::assertSame([], ExistingFormFiller::fromBytes($out)->fields());
        self::assertArrayNotHasKey('AcroForm', ReaderDocument::fromBytes($out)->catalog()->all());
    }

    #[Test]
    public function xfa_is_dropped_once_values_change(): void
    {
        $pdf = new FormPdf();
        $pdf->textField('name');
        $pdf->acroFormAttributes .= ' /XFA [(template) (<template/>)]';
        $pdf->catalogAttributes = '/NeedsRendering false';

        $bytes = $pdf->toBytes();
        $untouched = ReaderDocument::fromBytes(ExistingFormFiller::fromBytes($bytes)->toBytes());
        self::assertTrue($untouched->deref($untouched->catalog()->get('AcroForm'))->has('XFA'));

        $doc = ReaderDocument::fromBytes(ExistingFormFiller::fromBytes($bytes)->setValue('name', 'Jane')->toBytes());
        self::assertFalse($doc->deref($doc->catalog()->get('AcroForm'))->has('XFA'));
        self::assertFalse($doc->catalog()->has('NeedsRendering'));
    }

    #[Test]
    public function document_info_and_id_survive(): void
    {
        $pdf = new FormPdf();
        $pdf->info = '<< /Title (Gov form) /Author (Agency) >>';
        $pdf->textField('name');
        $bytes = $pdf->toBytes();
        $sourceId = ReaderDocument::fromBytes($bytes)->trailer()->get('ID');

        $doc = ReaderDocument::fromBytes(ExistingFormFiller::fromBytes($bytes)->setValue('name', 'x')->toBytes());
        $info = $doc->deref($doc->trailer()->get('Info'));
        $id = $doc->trailer()->get('ID');

        self::assertInstanceOf(PdfDictionary::class, $info);
        self::assertSame('Gov form', $info->get('Title')->bytes);
        self::assertSame($sourceId[0]->bytes, $id[0]->bytes, 'the permanent identifier is kept');
        self::assertNotSame($sourceId[1]->bytes, $id[1]->bytes, 'a changed file gets a new second identifier');
    }

    #[Test]
    public function stamp_coordinates_follow_the_crop_box(): void
    {
        $pdf = new FormPdf();
        $pdf->pageAttributes = '/MediaBox [0 0 612 792] /CropBox [50 50 562 742]';

        $filler = ExistingFormFiller::fromBytes($pdf->toBytes());
        self::assertSame(['width' => 512.0, 'height' => 692.0], $filler->pageSize(0));
        $drawn = FormPdf::drawn(ReaderDocument::fromBytes(
            $filler->stampImage(0, __DIR__.'/../../fixtures/sample.png', 0, 0, 100, 40)->toBytes(),
        ));

        // Top-left of the visible page is (50, 742): the image spans y 702..742.
        self::assertMatchesRegularExpression('@q 100 0 0 40 50 702 cm /Stamp\d+ Do Q@', $drawn);
    }

    #[Test]
    public function stamps_are_upright_on_rotated_pages(): void
    {
        $pdf = new FormPdf();
        $pdf->pageAttributes = '/MediaBox [0 0 612 792] /Rotate 90';

        $filler = ExistingFormFiller::fromBytes($pdf->toBytes());
        self::assertSame(['width' => 792.0, 'height' => 612.0], $filler->pageSize(0));
        $drawn = FormPdf::drawn(ReaderDocument::fromBytes(
            $filler->stampImage(0, __DIR__.'/../../fixtures/sample.png', 10, 20, 100, 40)->toBytes(),
        ));

        // Displayed (dx, dy) maps to user (dy, dx): the image's x axis runs up
        // the page and its y axis towards x = 0, so it reads upright once the
        // viewer turns the page 90° clockwise.
        self::assertMatchesRegularExpression('@q 0 100 -40 0 60 10 cm /Stamp\d+ Do Q@', $drawn);
    }

    /** The n-th annotation of the first page (output renumbers objects, order is kept). */
    private static function annot(ReaderDocument $doc, int $index): PdfDictionary
    {
        $annots = $doc->deref($doc->pages()[0]->dict->get('Annots'));
        $annot = is_array($annots) ? $doc->deref($annots[$index] ?? null) : null;
        self::assertInstanceOf(PdfDictionary::class, $annot);

        return $annot;
    }
}
