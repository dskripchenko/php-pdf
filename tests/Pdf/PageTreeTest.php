<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 220: Balanced Page Tree (PDF spec §7.7.3.3) tests.
 */
final class PageTreeTest extends TestCase
{
    private function buildPdf(int $pageCount): string
    {
        $pdf = PdfDocument::new(compressStreams: false);
        for ($i = 0; $i < $pageCount; $i++) {
            $page = $pdf->addPage();
            $page->showText("Page $i", 100, 700, StandardFont::Helvetica, 12);
        }

        return $pdf->toBytes();
    }

    #[Test]
    public function small_document_uses_flat_tree(): void
    {
        // 5 pages < threshold 32 → flat tree.
        $bytes = $this->buildPdf(5);
        // Only one /Pages object (root, без intermediates).
        $count = preg_match_all('@/Type /Pages\b@', $bytes);
        self::assertSame(1, $count);
    }

    #[Test]
    public function threshold_boundary_32_pages_still_flat(): void
    {
        // 32 pages = threshold (inclusive) → still flat tree.
        $bytes = $this->buildPdf(32);
        $count = preg_match_all('@/Type /Pages\b@', $bytes);
        self::assertSame(1, $count);
    }

    #[Test]
    public function above_threshold_uses_balanced_tree(): void
    {
        // 33 pages > threshold → intermediate /Pages nodes emitted.
        $bytes = $this->buildPdf(33);
        $count = preg_match_all('@/Type /Pages\b@', $bytes);
        // Root + intermediates ≥ 2.
        self::assertGreaterThan(1, $count);
    }

    #[Test]
    public function large_document_has_multiple_intermediates(): void
    {
        // 100 pages → ~7 intermediates (chunk size = ceil(100/16) = 7).
        $bytes = $this->buildPdf(100);
        $totalPagesNodes = preg_match_all('@/Type /Pages\b@', $bytes);
        self::assertGreaterThanOrEqual(2, $totalPagesNodes); // root + ≥1 intermediate
    }

    #[Test]
    public function root_kids_count_matches_total_pages(): void
    {
        $bytes = $this->buildPdf(100);
        // Find root /Pages dict (the one referenced from Catalog).
        // Root has /Count == total pages.
        self::assertMatchesRegularExpression('@/Type /Pages /Kids \[[^\]]+\] /Count 100 >>@', $bytes);
    }

    #[Test]
    public function intermediate_kids_have_parent_back_к_root(): void
    {
        $bytes = $this->buildPdf(50);
        // Intermediate /Pages with /Parent ref.
        $hasIntermediate = preg_match('@/Type /Pages /Parent \d+ 0 R /Kids@', $bytes);
        self::assertSame(1, $hasIntermediate, 'Intermediate /Pages должен have /Parent back-reference');
    }

    #[Test]
    public function pages_reference_intermediate_parent(): void
    {
        $bytes = $this->buildPdf(50);
        // Each Page /Parent points к some /Pages object. Find all Page parents.
        preg_match_all('@/Type /Page /Parent (\d+) 0 R@', $bytes, $matches);
        self::assertNotEmpty($matches[1]);

        // С balanced tree, не все pages share same parent (multiple intermediates).
        $uniqueParents = array_unique($matches[1]);
        self::assertGreaterThan(1, count($uniqueParents),
            'Balanced tree pages должны иметь различные parent IDs (intermediates)');
    }

    #[Test]
    public function root_kids_are_intermediates_не_pages(): void
    {
        $bytes = $this->buildPdf(50);
        // Find root /Pages /Kids list.
        preg_match('@/Type /Pages /Kids \[([^\]]+)\] /Count 50@', $bytes, $m);
        self::assertNotEmpty($m);

        // Extract kid IDs.
        preg_match_all('@(\d+) 0 R@', $m[1], $kidMatches);
        $rootKidIds = $kidMatches[1];

        // Number of root kids должен быть ≤ FANOUT (16).
        self::assertLessThanOrEqual(16, count($rootKidIds));

        // Каждый root kid должен быть /Type /Pages (intermediate), not /Page.
        foreach ($rootKidIds as $kidId) {
            // Find object с этим ID.
            preg_match('@\n'.preg_quote((string) $kidId).' 0 obj\n(.*?)\nendobj@s', $bytes, $objMatch);
            self::assertNotEmpty($objMatch, "Object $kidId not found");
            self::assertStringContainsString('/Type /Pages', $objMatch[1],
                "Root kid $kidId должен быть intermediate /Pages, не leaf /Page");
        }
    }

    #[Test]
    public function pdf_structure_remains_valid(): void
    {
        $bytes = $this->buildPdf(100);
        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringContainsString("%%EOF\n", $bytes);
    }

    #[Test]
    public function very_large_document_300_pages(): void
    {
        // Adaptive chunk size: 300 / 16 = 19 ≥ FANOUT, so chunkSize = ceil(300/16) = 19.
        // Root kids = 16 chunks of ~19 pages each.
        $bytes = $this->buildPdf(300);
        // Catalog /Pages ref still single root.
        $rootMatches = preg_match_all('@/Type /Pages /Kids \[([^\]]+)\] /Count 300\b@', $bytes);
        self::assertSame(1, $rootMatches);
    }
}
