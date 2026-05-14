<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 115: Page /AA — Open/Close JavaScript actions.
 */
final class PageActionsTest extends TestCase
{
    #[Test]
    public function open_action_emits_aa_o(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setOpenActionScript("app.alert('opened')");
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/AA <<', $bytes);
        self::assertStringContainsString('/O << /Type /Action /S /JavaScript /JS', $bytes);
        self::assertStringContainsString("(app.alert\\('opened'\\))", $bytes);
    }

    #[Test]
    public function close_action_emits_aa_c(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setCloseActionScript('console.log("bye")');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/C << /Type /Action /S /JavaScript /JS', $bytes);
    }

    #[Test]
    public function both_actions_in_same_aa_dict(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setOpenActionScript('open()');
        $page->setCloseActionScript('close()');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/JS (open\\(\\))', $bytes);
        self::assertStringContainsString('/JS (close\\(\\))', $bytes);
        self::assertMatchesRegularExpression('@/AA << /O << .+? /C << .+? >> >>@s', $bytes);
    }

    #[Test]
    public function no_action_omits_aa(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        // Page dict should not contain /AA when no action set. Other
        // /AA usages may exist (e.g., form fields) — restrict check к
        // page dict via /Type /Page lookbehind would be fragile, so
        // checking the simpler page-only setup is sufficient.
        self::assertStringNotContainsString('/AA <<', $bytes);
    }

    #[Test]
    public function accessors_return_set_values(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->setOpenActionScript('o');
        $page->setCloseActionScript('c');

        self::assertSame('o', $page->openActionScript());
        self::assertSame('c', $page->closeActionScript());
    }
}
