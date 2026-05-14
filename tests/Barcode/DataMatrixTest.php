<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\DataMatrixEncoder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataMatrixTest extends TestCase
{
    #[Test]
    public function picks_10x10_size_for_short_input(): void
    {
        $enc = new DataMatrixEncoder('Hi');
        // 2 ASCII chars = 2 codewords → fits 10×10 (3 data CW).
        self::assertSame(10, $enc->size());
        self::assertSame(3, $enc->dataCw);
        self::assertSame(5, $enc->eccCw);
    }

    #[Test]
    public function size_grows_with_input(): void
    {
        $enc = new DataMatrixEncoder(str_repeat('A', 30));
        self::assertGreaterThanOrEqual(20, $enc->size());
    }

    #[Test]
    public function digit_pair_compression(): void
    {
        // 4 digits = 2 paired codewords + extras.
        $enc = new DataMatrixEncoder('1234');
        // 2 codewords (12 → 130+12=142, 34 → 164).
        // Fits в 10×10.
        self::assertSame(10, $enc->size());
    }

    #[Test]
    public function reed_solomon_known_pattern(): void
    {
        // 3 zero data codewords + 5 ECC.
        $ecc = DataMatrixEncoder::reedSolomon([0, 0, 0], 5);
        self::assertCount(5, $ecc);
        // All zeros for zero data.
        self::assertSame([0, 0, 0, 0, 0], $ecc);
    }

    #[Test]
    public function reed_solomon_non_zero(): void
    {
        $ecc = DataMatrixEncoder::reedSolomon([1, 2, 3], 5);
        self::assertCount(5, $ecc);
        // ECC should be non-zero for non-zero input.
        self::assertGreaterThan(0, array_sum($ecc));
    }

    #[Test]
    public function modules_size_matches(): void
    {
        $enc = new DataMatrixEncoder('AB');
        $modules = $enc->modules();
        self::assertCount(10, $modules);
        foreach ($modules as $row) {
            self::assertCount(10, $row);
        }
    }

    #[Test]
    public function finder_pattern_left_column_solid(): void
    {
        $enc = new DataMatrixEncoder('Hi');
        $modules = $enc->modules();
        // Left edge — solid black (finder L).
        for ($i = 0; $i < 10; $i++) {
            self::assertTrue($modules[$i][0], "Left edge row $i should be black");
        }
    }

    #[Test]
    public function non_ascii_byte_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DataMatrixEncoder("\xFF\xFF"); // byte > 127.
    }

    #[Test]
    public function oversized_input_rejected(): void
    {
        // 26×26 holds 44 data codewords. 100 chars → too long.
        $this->expectException(\InvalidArgumentException::class);
        new DataMatrixEncoder(str_repeat('A', 200));
    }

    #[Test]
    public function empty_input_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DataMatrixEncoder('');
    }

    #[Test]
    public function renders_via_engine(): void
    {
        $doc = new Document(new Section([
            new Barcode('TEST123', BarcodeFormat::DataMatrix, widthPt: 60),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThan(5, $count);
    }

    #[Test]
    public function format_is_2d(): void
    {
        self::assertTrue(BarcodeFormat::DataMatrix->is2D());
    }
}
