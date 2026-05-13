<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\Ean13Encoder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Ean13EncoderTest extends TestCase
{
    #[Test]
    public function computes_check_digit_for_known_example(): void
    {
        // Wikipedia EAN-13 example: 400638133393X → checksum X = 1.
        self::assertSame(1, Ean13Encoder::computeCheckDigit('400638133393'));
        // Generic test: all zeros.
        self::assertSame(0, Ean13Encoder::computeCheckDigit('000000000000'));
    }

    #[Test]
    public function appends_checksum_for_12_digit_input(): void
    {
        $enc = new Ean13Encoder('400638133393');
        self::assertSame('4006381333931', $enc->canonical);
    }

    #[Test]
    public function accepts_13_digit_input_with_valid_checksum(): void
    {
        $enc = new Ean13Encoder('4006381333931');
        self::assertSame('4006381333931', $enc->canonical);
    }

    #[Test]
    public function rejects_invalid_checksum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // 13-digit с неправильной checksum.
        new Ean13Encoder('4006381333930');
    }

    #[Test]
    public function rejects_non_digit_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Ean13Encoder('400638-13393');
    }

    #[Test]
    public function rejects_wrong_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Ean13Encoder('1234');
    }

    #[Test]
    public function module_count_is_95(): void
    {
        $enc = new Ean13Encoder('400638133393');
        // 3 + 6*7 + 5 + 6*7 + 3 = 95.
        self::assertSame(95, $enc->moduleCount());
    }

    #[Test]
    public function upc_a_prepended_with_zero(): void
    {
        // UPC-A 11-digit + computed checksum.
        $enc = new Ean13Encoder('03600029145', upcA: true);
        // EAN-13 = '0' + UPC-A 11 + checksum.
        self::assertStringStartsWith('00360002914', $enc->canonical);
        self::assertSame(13, strlen($enc->canonical));
    }

    #[Test]
    public function quiet_zone_padding(): void
    {
        $enc = new Ean13Encoder('400638133393');
        $padded = $enc->modulesWithQuietZone(9);
        self::assertCount(95 + 18, $padded);
    }

    #[Test]
    public function ean13_renders_via_engine(): void
    {
        $doc = new Document(new Section([
            new Barcode('400638133393', BarcodeFormat::Ean13, widthPt: 200),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Caption должен быть canonical (с checksum).
        self::assertStringContainsString('(4006381333931) Tj', $bytes);
        // Многократные fillRect для bars.
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThan(10, $count);
    }

    #[Test]
    public function upc_a_renders_via_engine(): void
    {
        $doc = new Document(new Section([
            new Barcode('03600029145', BarcodeFormat::UpcA, widthPt: 200),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Caption — full EAN-13 form ('0' + UPC-A + checksum).
        self::assertMatchesRegularExpression('@\(0036000291\d{3}\) Tj@', $bytes);
    }
}
