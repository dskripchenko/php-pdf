<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageTransitionTest extends TestCase
{
    #[Test]
    public function transition_emitted(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setTransition('Wipe', duration: 1.5, direction: 90);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Trans <<', $bytes);
        self::assertStringContainsString('/S /Wipe', $bytes);
        self::assertStringContainsString('/D 1.5', $bytes);
        self::assertStringContainsString('/Di 90', $bytes);
    }

    #[Test]
    public function split_transition_with_dimension(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setTransition('Split', dimension: 'H');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/S /Split', $bytes);
        self::assertStringContainsString('/Dm /H', $bytes);
    }

    #[Test]
    public function auto_advance_emits_dur(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setAutoAdvance(5.0);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Dur 5', $bytes);
    }

    #[Test]
    public function no_transition_no_trans_entry(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        self::assertStringNotContainsString('/Trans', $bytes);
        self::assertStringNotContainsString('/Dur', $bytes);
    }

    #[Test]
    public function dissolve_transition_default_dur(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setTransition('Dissolve');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/S /Dissolve', $bytes);
        self::assertStringContainsString('/D 1', $bytes);
    }
}
