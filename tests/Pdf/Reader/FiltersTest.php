<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Reader;

use Dskripchenko\PhpPdf\Pdf\Reader\Filters;
use Dskripchenko\PhpPdf\Pdf\Reader\Lexer;
use Dskripchenko\PhpPdf\Pdf\Reader\ObjectParser;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Reader\StreamDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase P3: stream filter decoders + orchestration.
 */
final class FiltersTest extends TestCase
{
    #[Test]
    public function flate_round_trip(): void
    {
        $plain = str_repeat('The quick brown fox. ', 50);
        self::assertSame($plain, Filters::flate(gzcompress($plain)));
    }

    #[Test]
    public function ascii_hex_decode(): void
    {
        self::assertSame('Hello', Filters::asciiHex('48 65 6C 6C 6F>'));
        // Odd trailing nibble is padded with zero.
        self::assertSame("A@", Filters::asciiHex('414>'));
    }

    #[Test]
    public function ascii85_decode_with_z_shortcut(): void
    {
        // 'z' expands to four zero bytes.
        self::assertSame("\0\0\0\0", Filters::ascii85('z~>'));
        // Round-trip a known payload.
        $plain = 'Man is distinguished';
        $encoded = self::ascii85Encode($plain);
        self::assertSame($plain, Filters::ascii85($encoded . '~>'));
    }

    #[Test]
    public function run_length_decode(): void
    {
        // literal "AB" (len byte 1 => 2 bytes), then 5x 'C' (257-252=5), EOD 128.
        $encoded = chr(1) . 'AB' . chr(252) . 'C' . chr(128);
        self::assertSame('ABCCCCC', Filters::runLength($encoded));
    }

    #[Test]
    public function lzw_round_trip_against_encoder(): void
    {
        $plain = str_repeat('-----A---B', 20);
        self::assertSame($plain, Filters::lzw(self::lzwEncode($plain)));
    }

    #[Test]
    public function decodes_large_lzw_image_past_9_bit_codes(): void
    {
        // A real ImageMagick (libtiff) LZW stream whose dictionary grows past
        // 9-bit codes — the ground-truth check that code-width widening and
        // EarlyChange are handled correctly (a 4×4 image never reaches it).
        $path = __DIR__ . '/../../fixtures/external/imagemagick-lzw-large.pdf';
        if (!is_file($path)) {
            self::markTestSkipped('large LZW fixture not present');
        }
        $doc = ReaderDocument::fromBytes((string) file_get_contents($path));
        $resources = $doc->deref($doc->pages()[0]->dict->get('Resources'));
        $xobjects = $doc->deref($resources->get('XObject'));
        self::assertInstanceOf(\Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary::class, $xobjects);

        $found = false;
        foreach ($xobjects->all() as $ref) {
            $img = $doc->deref($ref);
            if ($img instanceof PdfStream) {
                // 48×48 RGB → exactly 6912 decoded bytes; must not throw.
                self::assertSame(6912, strlen($doc->streamData($img)));
                $found = true;
            }
        }
        self::assertTrue($found, 'expected an LZW image XObject');
    }

    #[Test]
    public function png_up_predictor_reverses(): void
    {
        // Two rows of 3 bytes, PNG "Up" (filter type 2), colors=3 bpc=8.
        $row1 = "\x10\x20\x30";
        $row2raw = "\x01\x02\x03";
        $encoded = "\x02" . $row1 . "\x02" . self::sub($row2raw, $row1);
        $decoded = Filters::applyPredictor($encoded, 12, 3, 8, 1);
        self::assertSame($row1 . $row2raw, $decoded);
    }

    #[Test]
    public function png_predictor_16bit_is_byte_oriented(): void
    {
        // colors=1, bpc=16, columns=3 → 6-byte rows. PNG "Up" is byte-level.
        $row1 = "\x10\x00\x20\x00\x30\x00";
        $row2 = "\x01\x02\x03\x04\x05\x06";
        $encoded = "\x02" . $row1 . "\x02" . self::sub($row2, $row1);
        self::assertSame($row1 . $row2, Filters::applyPredictor($encoded, 12, 1, 16, 3));
    }

    #[Test]
    public function png_predictor_supports_sub_byte_depth(): void
    {
        // 4-bpc, colors=1, columns=4 → 2-byte rows; PNG filtering stays byte-wise.
        $row1 = "\xAB\xCD";
        $row2 = "\x12\x34";
        $encoded = "\x02" . $row1 . "\x02" . self::sub($row2, $row1);
        self::assertSame($row1 . $row2, Filters::applyPredictor($encoded, 12, 1, 4, 4));
    }

    #[Test]
    public function tiff_predictor2_16bit_horizontal_difference(): void
    {
        // colors=1, bpc=16, columns=4: samples recovered by adding the previous.
        $raw = pack('n*', 1000, 1005, 1010, 1020);
        $encoded = pack('n*', 1000, 5, 5, 10);
        self::assertSame($raw, Filters::applyPredictor($encoded, 2, 1, 16, 4));
    }

    #[Test]
    public function tiff_predictor2_rejects_sub_byte_depth(): void
    {
        $this->expectException(\Dskripchenko\PhpPdf\Pdf\Reader\PdfParseException::class);
        Filters::applyPredictor("\x00\x00", 2, 1, 4, 4);
    }

    #[Test]
    public function stream_decoder_flate_via_document(): void
    {
        $plain = 'stream decoder end-to-end';
        $raw = gzcompress($plain);
        $src = "<< /Length " . strlen($raw) . " /Filter /FlateDecode >>\nstream\n{$raw}\nendstream";
        $stream = (new ObjectParser(new Lexer($src)))->parseValue();
        self::assertInstanceOf(PdfStream::class, $stream);

        $doc = ReaderDocument::fromBytes(self::minimalPdf());
        $decoder = new StreamDecoder($doc);
        self::assertSame($plain, $decoder->decode($stream));
    }

    #[Test]
    public function image_filter_is_terminal_passthrough(): void
    {
        $raw = "\xFF\xD8\xFF\xE0jpeg-bytes";
        $src = "<< /Length " . strlen($raw) . " /Filter /DCTDecode >>\nstream\n{$raw}\nendstream";
        $stream = (new ObjectParser(new Lexer($src)))->parseValue();
        $doc = ReaderDocument::fromBytes(self::minimalPdf());
        self::assertSame($raw, (new StreamDecoder($doc))->decode($stream));
    }

    // --- helpers -----------------------------------------------------------

    private static function minimalPdf(): string
    {
        $pdf = new \Dskripchenko\PhpPdf\Pdf\Document();
        $pdf->addPage();
        return $pdf->toBytes();
    }

    private static function sub(string $row, string $prev): string
    {
        $out = '';
        for ($i = 0; $i < strlen($row); $i++) {
            $out .= chr((ord($row[$i]) - ord($prev[$i])) & 0xFF);
        }
        return $out;
    }

    private static function ascii85Encode(string $data): string
    {
        $out = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i += 4) {
            $chunk = substr($data, $i, 4);
            $pad = 4 - strlen($chunk);
            $chunk = str_pad($chunk, 4, "\0");
            $tuple = (ord($chunk[0]) << 24) | (ord($chunk[1]) << 16) | (ord($chunk[2]) << 8) | ord($chunk[3]);
            $group = '';
            for ($j = 0; $j < 5; $j++) {
                $group = chr(($tuple % 85) + 33) . $group;
                $tuple = intdiv($tuple, 85);
            }
            $out .= substr($group, 0, 5 - $pad);
        }
        return $out;
    }

    private static function lzwEncode(string $data): string
    {
        $dict = [];
        for ($i = 0; $i < 256; $i++) {
            $dict[chr($i)] = $i;
        }
        $next = 258;
        $codeWidth = 9;
        $bitBuffer = 0;
        $bitCount = 0;
        $out = '';

        $emit = static function (int $code) use (&$bitBuffer, &$bitCount, &$codeWidth, &$out): void {
            $bitBuffer = ($bitBuffer << $codeWidth) | $code;
            $bitCount += $codeWidth;
            while ($bitCount >= 8) {
                $bitCount -= 8;
                $out .= chr(($bitBuffer >> $bitCount) & 0xFF);
            }
        };

        $emit(256); // clear
        $w = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $c = $data[$i];
            $wc = $w . $c;
            if (isset($dict[$wc])) {
                $w = $wc;
            } else {
                $emit($dict[$w]);
                $dict[$wc] = $next++;
                // EarlyChange=1: widen at next == 2^width - 1 (mirrors decoder).
                if ($next + 1 >= (1 << $codeWidth) && $codeWidth < 12) {
                    $codeWidth++;
                }
                $w = $c;
            }
        }
        if ($w !== '') {
            $emit($dict[$w]);
        }
        $emit(257); // EOD
        if ($bitCount > 0) {
            $out .= chr(($bitBuffer << (8 - $bitCount)) & 0xFF);
        }
        return $out;
    }
}
