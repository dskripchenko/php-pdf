<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\AztecEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * From Lower mode, code 28 is U/S — an UPPER SHIFT valid for exactly one
 * character (there is no direct Lower→Upper latch in Aztec). The encoder
 * used to treat it as a latch: its mode variable went to Upper while a
 * decoder returns to Lower after one character, so letter case came out
 * inverted until the next real latch ("Aztec-Test" decoded as
 * "Aztec-TEst"). Verified against ZXing after the fix.
 */
final class AztecUpperShiftTest extends TestCase
{
    #[Test]
    public function upper_shift_from_lower_covers_one_char_only(): void
    {
        // "aTe": U/L start → L/L latch (28) + 'a' (2), U/S shift (28) +
        // 'T' (21), then 'e' (6) plain — still in Lower, NO extra switch.
        self::assertSame(
            '11100'.'00010'.'11100'.'10101'.'00110',
            AztecEncoder::encodeToBits('aTe'),
        );
    }

    #[Test]
    public function consecutive_shifted_uppercase_letters_each_get_their_own_shift(): void
    {
        // "aTT": each shifted capital costs its own U/S prefix.
        self::assertSame(
            '11100'.'00010'.'11100'.'10101'.'11100'.'10101',
            AztecEncoder::encodeToBits('aTT'),
        );
    }
}
