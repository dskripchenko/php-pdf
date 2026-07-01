<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Merge;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfMerger;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfSource;
use Dskripchenko\PhpPdf\Pdf\Merge\Placement;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase P10: embedding a source page onto another via a Form XObject.
 */
final class PdfEmbedTest extends TestCase
{
    /** @param list<string> $labels */
    private function pdf(array $labels, float $w = 400.0, float $h = 600.0, int $rotate = 0): string
    {
        $pdf = new PdfDocument();
        foreach ($labels as $label) {
            $page = $pdf->addPage(customDimensionsPt: [$w, $h]);
            if ($rotate !== 0) {
                $page->setRotation($rotate);
            }
            $page->showText($label, 40, 300, StandardFont::Helvetica, 14);
        }
        return $pdf->toBytes();
    }

    /** Resolve the first XObject form registered on an output page. */
    private function firstXObject(ReaderDocument $doc, int $pageIndex): ?PdfStream
    {
        $resources = $doc->deref($doc->pages()[$pageIndex]->dict->get('Resources'));
        if (!$resources instanceof PdfDictionary) {
            return null;
        }
        $xobjs = $doc->deref($resources->get('XObject'));
        if (!$xobjs instanceof PdfDictionary) {
            return null;
        }
        foreach ($xobjs->all() as $ref) {
            $obj = $doc->deref($ref);
            if ($obj instanceof PdfStream) {
                return $obj;
            }
        }
        return null;
    }

    private function pageContent(ReaderDocument $doc, int $pageIndex): string
    {
        $contents = $doc->deref($doc->pages()[$pageIndex]->dict->get('Contents'));
        $list = is_array($contents) ? $contents : [$contents];
        $out = '';
        foreach ($list as $entry) {
            $stream = $doc->deref($entry);
            if ($stream instanceof PdfStream) {
                $out .= $doc->streamData($stream) . "\n";
            }
        }
        return $out;
    }

    #[Test]
    public function embeds_overlay_as_form_xobject(): void
    {
        $base = PdfSource::fromBytes($this->pdf(['BASE']));
        $overlay = PdfSource::fromBytes($this->pdf(['WATERMARK']));

        $bytes = PdfMerger::create()
            ->append($base)
            ->stamp($overlay, page: 1, onPages: [1], placement: Placement::fit())
            ->toBytes();

        $out = ReaderDocument::fromBytes($bytes);
        self::assertSame(1, $out->pageCount());

        // The overlay lives in a Form XObject whose body carries its content.
        $form = $this->firstXObject($out, 0);
        self::assertInstanceOf(PdfStream::class, $form);
        self::assertSame('Form', $form->dict->get('Subtype')?->value);
        self::assertStringContainsString('WATERMARK', $out->streamData($form));

        // The page invokes it with a Do operator, and keeps its own content.
        $content = $this->pageContent($out, 0);
        self::assertStringContainsString('Do', $content);
        self::assertStringContainsString('BASE', $content);
    }

    #[Test]
    public function stamps_all_pages_when_on_pages_is_null(): void
    {
        $base = PdfSource::fromBytes($this->pdf(['P1', 'P2', 'P3']));
        $overlay = PdfSource::fromBytes($this->pdf(['STAMP']));

        $bytes = PdfMerger::create()->append($base)->stamp($overlay)->toBytes();
        $out = ReaderDocument::fromBytes($bytes);

        self::assertSame(3, $out->pageCount());
        foreach ([0, 1, 2] as $i) {
            self::assertInstanceOf(PdfStream::class, $this->firstXObject($out, $i), "page {$i} stamped");
        }
    }

    #[Test]
    public function form_bbox_matches_source_crop_box(): void
    {
        $base = PdfSource::fromBytes($this->pdf(['B']));
        $overlay = PdfSource::fromBytes($this->pdf(['O'], w: 250.0, h: 350.0));

        $bytes = PdfMerger::create()->append($base)->stamp($overlay, placement: Placement::at(10, 10))->toBytes();
        $out = ReaderDocument::fromBytes($bytes);

        $form = $this->firstXObject($out, 0);
        self::assertSame([0.0, 0.0, 250.0, 350.0], array_map('floatval', $form->dict->get('BBox')));
    }

    #[Test]
    public function rotated_overlay_bakes_matrix(): void
    {
        $base = PdfSource::fromBytes($this->pdf(['B']));
        $overlay = PdfSource::fromBytes($this->pdf(['R'], w: 200.0, h: 400.0, rotate: 90));

        $bytes = PdfMerger::create()->append($base)->stamp($overlay)->toBytes();
        $out = ReaderDocument::fromBytes($bytes);

        $form = $this->firstXObject($out, 0);
        self::assertInstanceOf(PdfStream::class, $form);
        // /Matrix must be present and non-identity for a rotated source.
        $matrix = array_map('floatval', $form->dict->get('Matrix'));
        self::assertNotSame([1.0, 0.0, 0.0, 1.0, 0.0, 0.0], $matrix);
    }

    #[Test]
    public function embed_result_is_rereadable_and_valid(): void
    {
        $base = PdfSource::fromBytes($this->pdf(['A', 'B']));
        $overlay = PdfSource::fromBytes($this->pdf(['LOGO']));

        $bytes = PdfMerger::create()
            ->append($base)
            ->stamp($overlay, page: 1, onPages: [2], placement: Placement::at(50, 50, 0.5))
            ->toBytes();

        $out = ReaderDocument::fromBytes($bytes);
        self::assertSame(2, $out->pageCount());
        // Page 1 untouched, page 2 carries the overlay.
        self::assertNull($this->firstXObject($out, 0));
        self::assertInstanceOf(PdfStream::class, $this->firstXObject($out, 1));
    }
}
