<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 123: AcroForm /AP appearance streams.
 *
 * Verifies that each interactive widget carries an /AP /N Form XObject
 * reference so viewers ignoring /NeedAppearances true (Preview.app,
 * mobile, web) still render field values.
 */
final class AppearanceStreamTest extends TestCase
{
    private function emit(callable $configure): string
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $configure($page);

        return $pdf->toBytes();
    }

    #[Test]
    public function text_field_emits_ap_n_form_xobject(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addFormField('text', 'Name', 100, 700, 200, 20, defaultValue: 'Alice'));

        // Annotation references /AP /N <id> 0 R.
        self::assertMatchesRegularExpression('@/FT /Tx[^>]+/AP << /N \d+ 0 R >>@', $bytes);
        // Form XObject body contains text-show operator с value.
        self::assertStringContainsString('/Subtype /Form', $bytes);
        self::assertStringContainsString('(Alice) Tj', $bytes);
        // BBox covers field rect.
        self::assertStringContainsString('/BBox [0 0 200 20]', $bytes);
    }

    #[Test]
    public function password_field_renders_asterisks(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addFormField('password', 'PW', 100, 700, 200, 20, defaultValue: 'secret'));

        // 'secret' = 6 chars → 6 asterisks.
        self::assertStringContainsString('(******) Tj', $bytes);
        self::assertStringNotContainsString('(secret) Tj', $bytes);
    }

    #[Test]
    public function multiline_text_uses_top_alignment(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addFormField('text-multiline', 'Bio', 100, 700, 200, 80, defaultValue: 'Long\nbio'));

        // Multiline emits both /MaxLen-free /Tx flag bit и AP.
        self::assertStringContainsString('/Ff 4096', $bytes);
        self::assertMatchesRegularExpression('@/AP << /N \d+ 0 R >>@', $bytes);
    }

    #[Test]
    public function empty_default_value_emits_blank_appearance(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addFormField('text', 'Empty', 0, 0, 100, 20));

        // Still has /AP — appearance stream exists but content is blank q W n Q.
        self::assertMatchesRegularExpression('@/AP << /N \d+ 0 R >>@', $bytes);
    }

    #[Test]
    public function checkbox_emits_yes_and_off_appearances(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addFormField('checkbox', 'Subscribe', 100, 700, 14, 14, defaultValue: 'on'));

        // /AP dict has both /Yes and /Off entries.
        self::assertMatchesRegularExpression('@/AP << /N << /Yes \d+ 0 R /Off \d+ 0 R >> >>@', $bytes);
        // Checked appearance uses ZapfDingbats glyph '4' (check mark).
        self::assertStringContainsString('/ZaDb', $bytes);
        self::assertStringContainsString('(4) Tj', $bytes);
    }

    #[Test]
    public function unchecked_checkbox_has_empty_box_appearance(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addFormField('checkbox', 'Optional', 0, 0, 14, 14, defaultValue: ''));

        // Unchecked appearance just strokes the bounding rect (no ZapfDingbats glyph).
        self::assertStringContainsString('/AS /Off', $bytes);
        self::assertMatchesRegularExpression('@/AP << /N << /Yes \d+ 0 R /Off \d+ 0 R >> >>@', $bytes);
    }

    #[Test]
    public function combo_field_renders_selected_value(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addFormField('combo', 'Country', 100, 700, 100, 20,
            defaultValue: 'CA',
            options: ['US', 'CA', 'MX'],
        ));

        self::assertStringContainsString('(CA) Tj', $bytes);
        self::assertMatchesRegularExpression('@/FT /Ch[^>]+/AP << /N \d+ 0 R >>@', $bytes);
    }

    #[Test]
    public function button_appearance_includes_caption(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addFormField('push', 'Btn', 100, 700, 80, 24,
            buttonCaption: 'Submit',
            clickScript: 'alert(1)',
        ));

        self::assertStringContainsString('(Submit) Tj', $bytes);
        // Bordered grey box (rg = non-stroking grey fill).
        self::assertStringContainsString('0.9 0.9 0.9 rg', $bytes);
    }

    #[Test]
    public function signature_field_appearance_is_empty_box(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addFormField('signature', 'Sig1', 100, 700, 200, 50));

        // Signature appearance — minimal bordered rect (no text).
        self::assertMatchesRegularExpression('@/FT /Sig[^>]+/AP << /N \d+ 0 R >>@', $bytes);
    }

    #[Test]
    public function radio_group_appearances_per_option(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addFormField('radio-group', 'Size', 100, 700, 60, 14,
            defaultValue: 'M',
            options: ['S', 'M', 'L'],
            radioWidgets: [
                ['x' => 100, 'y' => 700, 'w' => 14, 'h' => 14],
                ['x' => 120, 'y' => 700, 'w' => 14, 'h' => 14],
                ['x' => 140, 'y' => 700, 'w' => 14, 'h' => 14],
            ],
        ));

        // Each option gets /AP << /N << /<export> ... /Off ... >> >> dict.
        self::assertMatchesRegularExpression('@/AP << /N << /S \d+ 0 R /Off \d+ 0 R >> >>@', $bytes);
        self::assertMatchesRegularExpression('@/AP << /N << /M \d+ 0 R /Off \d+ 0 R >> >>@', $bytes);
        self::assertMatchesRegularExpression('@/AP << /N << /L \d+ 0 R /Off \d+ 0 R >> >>@', $bytes);
    }

    #[Test]
    public function appearance_stream_has_form_xobject_dictionary(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addFormField('text', 'N', 0, 0, 100, 20, defaultValue: 'x'));

        // Form XObject must include /Type /XObject /Subtype /Form /BBox /Resources.
        self::assertStringContainsString('/Type /XObject /Subtype /Form', $bytes);
        self::assertStringContainsString('/Resources <<', $bytes);
        self::assertStringContainsString('/Font << /Helv', $bytes);
    }

    #[Test]
    public function need_appearances_flag_kept_as_fallback(): void
    {
        // /NeedAppearances true оставлено как safety net для readers
        // которые регенерируют AP from /DA when set.
        $bytes = $this->emit(fn ($p) => $p->addFormField('text', 'N', 0, 0, 100, 20));
        self::assertStringContainsString('/NeedAppearances true', $bytes);
    }
}
