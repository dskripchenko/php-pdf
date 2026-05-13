<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\QrEccLevel;
use Dskripchenko\PhpPdf\Barcode\QrEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QrEccLevelTest extends TestCase
{
    #[Test]
    public function format_bits_match_spec(): void
    {
        self::assertSame(0b01, QrEccLevel::L->formatBits());
        self::assertSame(0b00, QrEccLevel::M->formatBits());
        self::assertSame(0b11, QrEccLevel::Q->formatBits());
        self::assertSame(0b10, QrEccLevel::H->formatBits());
    }

    #[Test]
    public function level_l_default_when_omitted(): void
    {
        $enc = new QrEncoder('hello');
        self::assertSame(QrEccLevel::L, $enc->eccLevel);
    }

    #[Test]
    public function level_h_picks_higher_version_for_same_data(): void
    {
        // Lowercase forces byte mode. V1 ECC L byte cap = 17,
        // V1 ECC H = 7. 12-byte input → V1 для L, V2+ для H.
        $payload = 'hello world!';
        $l = new QrEncoder($payload, QrEccLevel::L);
        $h = new QrEncoder($payload, QrEccLevel::H);

        self::assertSame(1, $l->version);
        self::assertGreaterThanOrEqual(2, $h->version);
    }

    #[Test]
    public function level_m_works_at_v1(): void
    {
        $enc = new QrEncoder('hi', QrEccLevel::M);
        self::assertSame(1, $enc->version);
        self::assertSame(QrEccLevel::M, $enc->eccLevel);
    }

    #[Test]
    public function level_q_works_at_v1(): void
    {
        $enc = new QrEncoder('hi', QrEccLevel::Q);
        self::assertSame(1, $enc->version);
        self::assertSame(QrEccLevel::Q, $enc->eccLevel);
    }

    #[Test]
    public function v5_only_ecc_l_supported(): void
    {
        // V5 ECC L max=106 bytes; M/Q/H для V5+ — deferred → expect exception.
        $this->expectException(\InvalidArgumentException::class);
        new QrEncoder(str_repeat('A', 100), QrEccLevel::M);
    }

    #[Test]
    public function max_capacity_per_level_helper(): void
    {
        self::assertSame(271, QrEncoder::maxCapacityForLevel('L')); // V10
        self::assertSame(62, QrEncoder::maxCapacityForLevel('M')); // V4 max
        self::assertSame(46, QrEncoder::maxCapacityForLevel('Q'));
        self::assertSame(34, QrEncoder::maxCapacityForLevel('H'));
    }

    #[Test]
    public function different_ecc_levels_yield_different_matrices(): void
    {
        $data = 'Hello';
        $l = new QrEncoder($data, QrEccLevel::L);
        $m = new QrEncoder($data, QrEccLevel::M);

        // Format info bits отличаются → разные матрицы (даже если version одинаков).
        self::assertNotEquals($l->modules(), $m->modules());
    }

    #[Test]
    public function format_bits_encoded_in_matrix(): void
    {
        // Format bits для L=01000, M=00000 (mask=000).
        // Самые младшие 2 bits данных = ECC level.
        // Position (0, 8) — bit 0 (low) format bit;
        // Position (1, 8) — bit 1.
        $l = new QrEncoder('x', QrEccLevel::L);
        $m = new QrEncoder('x', QrEccLevel::M);

        // L != M в format positions около TL finder.
        $different = false;
        for ($i = 0; $i < 9; $i++) {
            if ($l->module($i, 8) !== $m->module($i, 8)) {
                $different = true;
                break;
            }
        }
        self::assertTrue($different, 'Format info must differ between L and M');
    }
}
