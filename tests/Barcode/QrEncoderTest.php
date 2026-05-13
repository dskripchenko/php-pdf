<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\QrEncoder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QrEncoderTest extends TestCase
{
    #[Test]
    public function picks_version_1_for_short_input(): void
    {
        $enc = new QrEncoder('Hi');
        self::assertSame(1, $enc->version);
        self::assertSame(21, $enc->size());
    }

    #[Test]
    public function version_2_for_18_byte_input(): void
    {
        // Lowercase forces byte mode (alphanumeric не допускает lowercase).
        // V1 byte mode ECC L max = 17 bytes, V2 = 32 bytes.
        $enc = new QrEncoder(str_repeat('a', 18));
        self::assertSame(2, $enc->version);
        self::assertSame(25, $enc->size());
    }

    #[Test]
    public function rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QrEncoder('');
    }

    #[Test]
    public function rejects_oversized_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // V10 max byte mode ECC L = 271 bytes; lowercase forces byte mode.
        new QrEncoder(str_repeat('a', 300));
    }

    #[Test]
    public function matrix_size_consistent(): void
    {
        $enc = new QrEncoder('test');
        $matrix = $enc->modules();
        self::assertCount(21, $matrix);
        foreach ($matrix as $row) {
            self::assertCount(21, $row);
        }
    }

    #[Test]
    public function finder_patterns_at_three_corners(): void
    {
        $enc = new QrEncoder('hello');
        // Finder = 7×7. Top-left corner и inner 3×3 — black.
        // Sample (0,0), (6,6), (0,6), (6,0) — black (outer ring).
        self::assertTrue($enc->module(0, 0));
        self::assertTrue($enc->module(6, 6));
        self::assertTrue($enc->module(6, 0));
        self::assertTrue($enc->module(0, 6));
        // Inner 3×3 dark square (2..4, 2..4).
        self::assertTrue($enc->module(2, 2));
        self::assertTrue($enc->module(4, 4));
        // Module (1, 1) — white (часть white ring).
        self::assertFalse($enc->module(1, 1));

        // Top-right finder anchored at x=14 для V1.
        self::assertTrue($enc->module(14, 0));
        self::assertTrue($enc->module(20, 6));
        // Bottom-left finder anchored at y=14.
        self::assertTrue($enc->module(0, 14));
        self::assertTrue($enc->module(6, 20));
    }

    #[Test]
    public function dark_module_at_required_position(): void
    {
        // QR spec: dark module always at (8, 4*version + 9).
        $enc = new QrEncoder('x');
        $expectedY = 4 * 1 + 9; // = 13.
        self::assertTrue($enc->module(8, $expectedY));
    }

    #[Test]
    public function timing_pattern_alternates(): void
    {
        $enc = new QrEncoder('test');
        // Row 6 timing pattern должен alternating black/white (col 8..size-9).
        $size = $enc->size();
        for ($i = 8; $i < $size - 8; $i++) {
            $expected = $i % 2 === 0;
            self::assertSame($expected, $enc->module($i, 6), "Timing pattern mismatch at row 6, col $i");
        }
    }

    #[Test]
    public function different_input_yields_different_matrix(): void
    {
        $a = new QrEncoder('AAA');
        $b = new QrEncoder('BBB');
        self::assertNotEquals($a->modules(), $b->modules());
    }

    #[Test]
    public function qr_renders_via_engine(): void
    {
        $doc = new Document(new Section([
            new Barcode('https://example.com', BarcodeFormat::Qr, widthPt: 100, showText: false),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Multiple fillRect operators expected для всех black squares.
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThan(50, $count, 'QR must emit many filled rects');
    }

    #[Test]
    public function qr_with_caption_renders_text(): void
    {
        $doc = new Document(new Section([
            new Barcode('QRDATA', BarcodeFormat::Qr, widthPt: 100, showText: true),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(QRDATA) Tj', $bytes);
    }

    #[Test]
    public function format_is_2d_helper(): void
    {
        self::assertTrue(BarcodeFormat::Qr->is2D());
        self::assertFalse(BarcodeFormat::Code128->is2D());
        self::assertFalse(BarcodeFormat::Ean13->is2D());
    }
}
