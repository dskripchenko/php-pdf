<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 119: Document-level /AA additional actions.
 */
final class DocumentActionsTest extends TestCase
{
    #[Test]
    public function will_print_action_emitted(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setDocumentAction('WP', 'app.alert("printing")');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/AA << /WP <<', $bytes);
        self::assertStringContainsString('/Type /Action /S /JavaScript', $bytes);
        self::assertStringContainsString('app.alert', $bytes);
    }

    #[Test]
    public function multiple_events_in_one_aa(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->setDocumentAction('WS', 'saveScript');
        $pdf->setDocumentAction('DS', 'savedScript');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/WS << /Type /Action', $bytes);
        self::assertStringContainsString('/DS << /Type /Action', $bytes);
    }

    #[Test]
    public function invalid_event_rejected(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $this->expectException(\InvalidArgumentException::class);
        $pdf->setDocumentAction('Bogus', 'x');
    }

    #[Test]
    public function no_actions_omits_aa_in_catalog(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        // Catalog dict should not contain /AA.
        if (preg_match('@/Type /Catalog .+?>>@s', $bytes, $m)) {
            self::assertStringNotContainsString('/AA <<', $m[0]);
        }
    }
}
