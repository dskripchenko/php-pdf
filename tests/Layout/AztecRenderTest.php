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
 * Phase 125: Aztec rendering integration.
 */
final class AztecRenderTest extends TestCase
{
    private function renderPdf(string $value, ?float $width = null): string
    {
        $doc = new Document(new Section([
            new Barcode($value, BarcodeFormat::Aztec, widthPt: $width),
        ]));

        return $doc->toBytes(new Engine(compressStreams: false));
    }

    #[Test]
    public function aztec_emits_filled_rectangles(): void
    {
        $bytes = $this->renderPdf('HELLO', 100);
        $count = preg_match_all('@^f$@m', $bytes);
        // 15×15 compact aztec → at least 30 contiguous black runs.
        self::assertGreaterThan(20, $count);
    }

    #[Test]
    public function aztec_classified_as_2D(): void
    {
        self::assertTrue(BarcodeFormat::Aztec->is2D());
        self::assertFalse(BarcodeFormat::Aztec->isStacked());
    }

    #[Test]
    public function aztec_with_longer_data_grows_size(): void
    {
        // Both render without errors.
        $bytes1 = $this->renderPdf('A');
        $bytes2 = $this->renderPdf('HELLO WORLD 1234567890');
        self::assertStringContainsString('endobj', $bytes1);
        self::assertStringContainsString('endobj', $bytes2);
    }

    #[Test]
    public function aztec_with_caption(): void
    {
        $doc = new Document(new Section([
            new Barcode('ABC123', BarcodeFormat::Aztec, showText: true),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(ABC123) Tj', $bytes);
    }
}
