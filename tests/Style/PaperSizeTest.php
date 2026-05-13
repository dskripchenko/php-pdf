<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Style;

use Dskripchenko\PhpPdf\Style\Orientation;
use Dskripchenko\PhpPdf\Style\PaperSize;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaperSizeTest extends TestCase
{
    #[Test]
    public function a4_dimensions(): void
    {
        // ISO 216 A4 = 210mm × 297mm = 595.28 × 841.89 pt.
        self::assertEqualsWithDelta(595.28, PaperSize::A4->widthPt(), 0.01);
        self::assertEqualsWithDelta(841.89, PaperSize::A4->heightPt(), 0.01);
    }

    #[Test]
    public function us_letter_dimensions(): void
    {
        self::assertSame(612.0, PaperSize::Letter->widthPt());
        self::assertSame(792.0, PaperSize::Letter->heightPt());
    }

    #[Test]
    public function us_legal_dimensions(): void
    {
        self::assertSame(612.0, PaperSize::Legal->widthPt());
        self::assertSame(1008.0, PaperSize::Legal->heightPt());
    }

    #[Test]
    public function a3_relates_to_a4_per_iso_216(): void
    {
        // ISO 216: A3 width = A4 height; A3 height = 2 × A4 width
        // (A_n derived от A0 половинным разрезанием wider side'а).
        self::assertGreaterThan(PaperSize::A4->heightPt(), PaperSize::A3->heightPt());
        self::assertEqualsWithDelta(PaperSize::A4->heightPt(), PaperSize::A3->widthPt(), 0.01);
        self::assertEqualsWithDelta(2 * PaperSize::A4->widthPt(), PaperSize::A3->heightPt(), 0.01);
    }

    #[Test]
    public function portrait_keeps_dimensions(): void
    {
        [$w, $h] = Orientation::Portrait->applyTo(PaperSize::A4);
        self::assertSame(595.28, $w);
        self::assertSame(841.89, $h);
    }

    #[Test]
    public function landscape_swaps_dimensions(): void
    {
        [$w, $h] = Orientation::Landscape->applyTo(PaperSize::A4);
        self::assertSame(841.89, $w);
        self::assertSame(595.28, $h);
    }
}
