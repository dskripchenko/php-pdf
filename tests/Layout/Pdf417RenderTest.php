<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 124: PDF417 rendering integration.
 */
final class Pdf417RenderTest extends TestCase
{
    private function renderPdf(string $value, ?float $width = null): string
    {
        $doc = new Document(new Section([
            new Barcode($value, BarcodeFormat::Pdf417, widthPt: $width),
        ]));

        return $doc->toBytes(new Engine(compressStreams: false));
    }

    #[Test]
    public function pdf417_emits_multiple_fillrects_per_row(): void
    {
        $bytes = $this->renderPdf('Hello PDF417 World', 300);
        $count = preg_match_all('@^f$@m', $bytes);
        // PDF417 emits many rectangles — at least 50 contiguous bars over
        // ~14+ logical rows.
        self::assertGreaterThan(50, $count, 'PDF417 produces multi-row stacked rectangles');
    }

    #[Test]
    public function pdf417_isStacked_helper(): void
    {
        self::assertTrue(BarcodeFormat::Pdf417->isStacked());
        self::assertFalse(BarcodeFormat::Code128->isStacked());
        self::assertFalse(BarcodeFormat::Pdf417->is2D(), 'PDF417 is stacked-linear, не 2D matrix');
    }

    #[Test]
    public function pdf417_with_short_data_renders(): void
    {
        $bytes = $this->renderPdf('AB');
        self::assertStringContainsString('endobj', $bytes);
        // At least some fill operations occurred.
        self::assertGreaterThan(10, preg_match_all('@^f$@m', $bytes));
    }

    #[Test]
    public function pdf417_with_binary_data_renders(): void
    {
        $bytes = $this->renderPdf("\x01\x02\x03\x04\x05\x06", 200);
        self::assertStringContainsString('endobj', $bytes);
    }
}
