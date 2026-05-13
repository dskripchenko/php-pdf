<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Style;

use Dskripchenko\PhpPdf\Style\Orientation;
use Dskripchenko\PhpPdf\Style\PageMargins;
use Dskripchenko\PhpPdf\Style\PageSetup;
use Dskripchenko\PhpPdf\Style\PaperSize;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageSetupAdvancedTest extends TestCase
{
    #[Test]
    public function default_margins_have_no_mirror_or_gutter(): void
    {
        $m = new PageMargins;
        self::assertFalse($m->mirrored);
        self::assertSame(0.0, $m->gutterPt);
        [$l, $r] = $m->effectiveLeftRightFor(1);
        self::assertSame($m->leftPt, $l);
        self::assertSame($m->rightPt, $r);
    }

    #[Test]
    public function gutter_adds_to_left_on_single_sided(): void
    {
        $m = new PageMargins(leftPt: 50, rightPt: 50, gutterPt: 20);
        [$l1, $r1] = $m->effectiveLeftRightFor(1);
        [$l2, $r2] = $m->effectiveLeftRightFor(2);
        self::assertSame(70.0, $l1);
        self::assertSame(50.0, $r1);
        // Не mirrored — gutter всегда слева.
        self::assertSame(70.0, $l2);
        self::assertSame(50.0, $r2);
    }

    #[Test]
    public function mirrored_swaps_margins_on_even_page(): void
    {
        $m = new PageMargins(leftPt: 30, rightPt: 50, mirrored: true, gutterPt: 15);
        [$l1, $r1] = $m->effectiveLeftRightFor(1);  // odd
        [$l2, $r2] = $m->effectiveLeftRightFor(2);  // even

        // Odd: binding слева, gutter добавляется к leftPt.
        self::assertSame(45.0, $l1);
        self::assertSame(50.0, $r1);
        // Even: gutter переходит на rightPt = 30+15; outer = leftPt = 50?
        // По логике effectiveLeftRightFor: even mirrored returns [rightPt, leftPt+gutter].
        self::assertSame(50.0, $l2);
        self::assertSame(45.0, $r2);
    }

    #[Test]
    public function custom_dimensions_override_paper_size(): void
    {
        $setup = new PageSetup(
            paperSize: PaperSize::A4,
            customDimensionsPt: [200, 300],
        );
        [$w, $h] = $setup->dimensions();
        self::assertSame(200, $w);
        self::assertSame(300, $h);
    }

    #[Test]
    public function custom_dimensions_respect_orientation(): void
    {
        $setup = new PageSetup(
            paperSize: PaperSize::A4,
            orientation: Orientation::Landscape,
            customDimensionsPt: [200, 300],
        );
        [$w, $h] = $setup->dimensions();
        self::assertSame(300, $w);
        self::assertSame(200, $h);
    }

    #[Test]
    public function first_page_number_default_one(): void
    {
        self::assertSame(1, (new PageSetup)->firstPageNumber);
    }

    #[Test]
    public function content_width_per_page_uses_mirrored_margins(): void
    {
        $setup = new PageSetup(
            margins: new PageMargins(leftPt: 30, rightPt: 50, mirrored: true, gutterPt: 10),
        );
        // A4 width = 595.28.
        // Odd page: left = 30+10 = 40, right = 50 → content = 595.28 - 90 = 505.28
        self::assertEqualsWithDelta(505.28, $setup->contentWidthPtForPage(1), 0.01);
        // Even page: swap → left = 50, right = 40 → content = 505.28
        self::assertEqualsWithDelta(505.28, $setup->contentWidthPtForPage(2), 0.01);
    }

    #[Test]
    public function leftx_for_page_returns_effective_left_margin(): void
    {
        $setup = new PageSetup(
            margins: new PageMargins(leftPt: 30, rightPt: 50, mirrored: true),
        );
        self::assertSame(30.0, $setup->leftXForPage(1));
        self::assertSame(50.0, $setup->leftXForPage(2));
    }
}
