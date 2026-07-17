<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\QrEncoder;
use Dskripchenko\PhpPdf\Barcode\QrEccLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The 15-bit format information (ECC level + mask, BCH-protected) used to
 * be written LSB-first into both matrix copies — a mirrored, BCH-invalid
 * codeword that made every external decoder (zbar, ZXing) reject every
 * symbol while all-internal tests kept passing. These tests validate the
 * emitted bits exactly the way a decoder reads them.
 */
final class QrFormatInfoTest extends TestCase
{
    /**
     * Read format copy 1 the way ISO/IEC 18004 prescribes: bit 14 at
     * (8,0) … bit 0 at (0,8).
     *
     * @param  list<list<bool>>  $m
     */
    private static function copy1(array $m): int
    {
        $bits = [];
        foreach ([0, 1, 2, 3, 4, 5, 7] as $c) {
            $bits[] = $m[8][$c];
        }
        $bits[] = $m[8][8];
        $bits[] = $m[7][8];
        foreach ([5, 4, 3, 2, 1, 0] as $r) {
            $bits[] = $m[$r][8];
        }

        return self::toInt($bits);
    }

    /**
     * @param  list<list<bool>>  $m
     */
    private static function copy2(array $m, int $size): int
    {
        $bits = [];
        for ($i = 0; $i < 7; $i++) {
            $bits[] = $m[$size - 1 - $i][8];
        }
        for ($c = $size - 8; $c < $size; $c++) {
            $bits[] = $m[8][$c];
        }

        return self::toInt($bits);
    }

    /**
     * @param  list<bool>  $bits
     */
    private static function toInt(array $bits): int
    {
        $v = 0;
        foreach ($bits as $b) {
            $v = ($v << 1) | ($b ? 1 : 0);
        }

        return $v;
    }

    private static function bchValid(int $word): bool
    {
        $unmasked = $word ^ 0b101010000010010;
        $rem = $unmasked;
        for ($i = 14; $i >= 10; $i--) {
            if ($rem & (1 << $i)) {
                $rem ^= 0b10100110111 << ($i - 10);
            }
        }

        return $rem === 0;
    }

    public static function payloads(): array
    {
        return [
            'short L' => ['HELLO', QrEccLevel::L],
            'url M' => ['https://github.com/dskripchenko/php-pdf', QrEccLevel::M],
            'utf8 Q' => ['Мультибайт 日本語', QrEccLevel::Q],
            'numeric H' => ['1234567890', QrEccLevel::H],
        ];
    }

    #[Test]
    #[DataProvider('payloads')]
    public function format_information_is_bch_valid_in_both_copies(string $payload, QrEccLevel $ecc): void
    {
        $enc = new QrEncoder($payload, $ecc);
        $m = $enc->modules();
        $size = $enc->size();

        $c1 = self::copy1($m);
        $c2 = self::copy2($m, $size);

        self::assertSame($c1, $c2, 'Both format copies must be identical');
        self::assertTrue(self::bchValid($c1), sprintf('Format word %015b fails BCH', $c1));

        // The unmasked data field must carry this ECC level.
        $data = ($c1 ^ 0b101010000010010) >> 10;
        self::assertSame($ecc->formatBits(), $data >> 3, 'ECC bits must round-trip');
    }

    #[Test]
    public function zbar_decodes_a_rendered_symbol_end_to_end(): void
    {
        exec('zbarimg --version 2>/dev/null', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('zbarimg not available');
        }
        exec('pdftoppm -v 2>/dev/null', $out2, $code2);
        if ($code2 !== 0 && $code2 !== 99) {
            self::markTestSkipped('pdftoppm not available');
        }

        $doc = new \Dskripchenko\PhpPdf\Document(new \Dskripchenko\PhpPdf\Section([
            new \Dskripchenko\PhpPdf\Element\Barcode(
                'e2e decode check',
                \Dskripchenko\PhpPdf\Element\BarcodeFormat::Qr,
                heightPt: 120,
                showText: false,
            ),
        ]));
        $pdf = tempnam(sys_get_temp_dir(), 'phppdf-qr-');
        file_put_contents($pdf, $doc->toBytes());
        $png = $pdf.'-img';
        exec(sprintf('pdftoppm -r 150 -png -singlefile %s %s', escapeshellarg($pdf), escapeshellarg($png)));
        $decoded = trim((string) shell_exec('zbarimg -q '.escapeshellarg("$png.png").' 2>/dev/null'));
        unlink($pdf);
        @unlink("$png.png");

        self::assertSame('QR-Code:e2e decode check', $decoded);
    }
}
