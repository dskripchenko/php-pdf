<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\Ean8Encoder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 200: EAN-8 short barcode encoder tests.
 */
final class Ean8EncoderTest extends TestCase
{
    #[Test]
    public function computes_check_digit_for_known_example(): void
    {
        // Wikipedia EAN-8 example: 9638507X → checksum X = 4.
        self::assertSame(4, Ean8Encoder::computeCheckDigit('9638507'));
        // All zeros → 0.
        self::assertSame(0, Ean8Encoder::computeCheckDigit('0000000'));
    }

    #[Test]
    public function appends_checksum_for_7_digit_input(): void
    {
        $enc = new Ean8Encoder('9638507');
        self::assertSame('96385074', $enc->canonical);
    }

    #[Test]
    public function accepts_8_digit_input_with_valid_checksum(): void
    {
        $enc = new Ean8Encoder('96385074');
        self::assertSame('96385074', $enc->canonical);
    }

    #[Test]
    public function rejects_invalid_checksum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Ean8Encoder('96385073');
    }

    #[Test]
    public function rejects_non_digit_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Ean8Encoder('96385-07');
    }

    #[Test]
    public function rejects_wrong_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Ean8Encoder('123456');
    }

    #[Test]
    public function rejects_excess_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Ean8Encoder('123456789');
    }

    #[Test]
    public function check_digit_rejects_wrong_arg_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ean8Encoder::computeCheckDigit('12345');
    }

    #[Test]
    public function module_count_is_67(): void
    {
        $enc = new Ean8Encoder('9638507');
        // 3 + 4*7 + 5 + 4*7 + 3 = 67.
        self::assertSame(67, $enc->moduleCount());
    }

    #[Test]
    public function quiet_zone_padding_default_7(): void
    {
        $enc = new Ean8Encoder('9638507');
        $padded = $enc->modulesWithQuietZone();
        self::assertCount(67 + 14, $padded);
    }

    #[Test]
    public function quiet_zone_padding_custom(): void
    {
        $enc = new Ean8Encoder('9638507');
        $padded = $enc->modulesWithQuietZone(10);
        self::assertCount(67 + 20, $padded);
    }

    #[Test]
    public function start_guard_is_101(): void
    {
        $enc = new Ean8Encoder('9638507');
        $modules = $enc->modules();
        // Positions 0..2 = "101".
        self::assertTrue($modules[0]);
        self::assertFalse($modules[1]);
        self::assertTrue($modules[2]);
    }

    #[Test]
    public function center_guard_at_correct_position(): void
    {
        // 3 (start) + 4*7 = 31 → modules 31..35 = "01010".
        $enc = new Ean8Encoder('9638507');
        $modules = $enc->modules();
        self::assertFalse($modules[31]);
        self::assertTrue($modules[32]);
        self::assertFalse($modules[33]);
        self::assertTrue($modules[34]);
        self::assertFalse($modules[35]);
    }

    #[Test]
    public function end_guard_at_correct_position(): void
    {
        // Last 3 modules — "101".
        $enc = new Ean8Encoder('9638507');
        $modules = $enc->modules();
        $n = count($modules);
        self::assertTrue($modules[$n - 3]);
        self::assertFalse($modules[$n - 2]);
        self::assertTrue($modules[$n - 1]);
    }

    #[Test]
    public function left_digits_use_l_code(): void
    {
        // First left digit '9' → L-code '0001011'.
        $enc = new Ean8Encoder('9638507');
        $modules = $enc->modules();
        // Position 3..9 = первая left digit '9'.
        $bits = '';
        for ($i = 3; $i < 10; $i++) {
            $bits .= $modules[$i] ? '1' : '0';
        }
        self::assertSame('0001011', $bits);
    }

    #[Test]
    public function right_digits_use_r_code(): void
    {
        // First right digit (position 5 в 8-digit canonical '96385074') = '5'.
        // R-code для 5 = '1001110'.
        $enc = new Ean8Encoder('9638507');
        $modules = $enc->modules();
        // Position 36..42 = первая right digit.
        $bits = '';
        for ($i = 36; $i < 43; $i++) {
            $bits .= $modules[$i] ? '1' : '0';
        }
        self::assertSame('1001110', $bits);
    }

    #[Test]
    public function ean8_renders_via_engine(): void
    {
        $doc = new Document(new Section([
            new Barcode('9638507', BarcodeFormat::Ean8, widthPt: 150),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Caption — canonical 8-digit form.
        self::assertStringContainsString('(96385074) Tj', $bytes);
        // Bars rendered.
        $count = preg_match_all('@^f$@m', $bytes);
        self::assertGreaterThan(5, $count);
    }

    #[Test]
    public function ean8_format_is_not_2d_not_stacked(): void
    {
        self::assertFalse(BarcodeFormat::Ean8->is2D());
        self::assertFalse(BarcodeFormat::Ean8->isStacked());
    }
}
