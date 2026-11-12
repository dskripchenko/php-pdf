<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * placeLinkInStructure() puts a link's struct element where the link is in
 * reading order, instead of at the end of the root, and lets a Link
 * element hold both the link text and the annotation (ISO 14289-1 §7.18.5).
 */
final class TaggedLinkPlacementTest extends TestCase
{
    #[Test]
    public function a_placed_link_sits_inside_its_container_in_reading_order(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->enableTagged();
        $page = $pdf->addPage();
        $pdf->beginStructContainer('Sect', $page);
        $pdf->addStructElement('P', $page->nextMcid(), $page);
        $page->addExternalLink(10, 10, 50, 10, 'https://example.com', 'Example');
        $pdf->placeLinkInStructure($page);
        $pdf->addStructElement('P', $page->nextMcid(), $page);
        $pdf->endStructContainer();
        $bytes = $pdf->toBytes();

        $elems = self::structElements($bytes);
        $sect = self::single($elems, 'Sect');
        $kids = self::refs($sect['K']);
        self::assertCount(3, $kids);
        self::assertSame(['P', 'Link', 'P'], array_map(fn (int $id) => $elems[$id]['S'], $kids));
        // Exactly one Link element: the placed one, not a second one at the root.
        self::assertSame(1, substr_count($bytes, '/S /Link'));
    }

    #[Test]
    public function a_link_container_holds_its_text_and_its_annotation(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->enableTagged();
        $page = $pdf->addPage();
        $pdf->beginStructContainer('Link', $page);
        $pdf->addStructElement('Span', $page->nextMcid(), $page);
        $page->addExternalLink(10, 10, 50, 10, 'https://example.com', 'Example');
        $pdf->placeLinkInStructure($page);
        $pdf->endStructContainer();
        $bytes = $pdf->toBytes();

        $link = self::single(self::structElements($bytes), 'Link');
        self::assertMatchesRegularExpression('@^\[\d+ 0 R << /Type /OBJR /Obj \d+ 0 R >>\]$@', $link['K']);
        self::assertSame(1, substr_count($bytes, '/S /Link'));
    }

    #[Test]
    public function the_annotation_points_back_at_the_placed_element(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->enableTagged();
        $page = $pdf->addPage();
        $pdf->beginStructContainer('Sect', $page);
        $page->addExternalLink(10, 10, 50, 10, 'https://example.com', 'Example');
        $pdf->placeLinkInStructure($page);
        $pdf->endStructContainer();
        $bytes = $pdf->toBytes();

        preg_match('@/Subtype /Link\b.*?/StructParent (\d+)@s', $bytes, $m);
        $key = $m[1];
        preg_match('@/ParentTree (\d+) 0 R@', $bytes, $pt);
        preg_match('@\n'.$pt[1].' 0 obj\s*<< /Nums \[(.*?)\] >>@s', $bytes, $nums);
        preg_match('@(?:^|\s)'.$key.' (\d+) 0 R@', $nums[1], $target);
        $linkId = array_search('Link', array_map(fn (array $e) => $e['S'], self::structElements($bytes)), true);
        self::assertSame($linkId, (int) $target[1]);
    }

    #[Test]
    public function links_that_are_not_placed_still_go_at_the_root(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->enableTagged();
        $page = $pdf->addPage();
        $pdf->beginStructContainer('Sect', $page);
        $pdf->addStructElement('P', $page->nextMcid(), $page);
        $page->addExternalLink(10, 10, 50, 10, 'https://example.com');
        $pdf->endStructContainer();

        $elems = self::structElements($pdf->toBytes());
        self::assertCount(1, self::refs(self::single($elems, 'Sect')['K']));
    }

    #[Test]
    public function placing_on_an_untagged_document_does_nothing(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->addExternalLink(10, 10, 50, 10, 'https://example.com');
        $pdf->placeLinkInStructure($page);

        self::assertStringNotContainsString('/StructTreeRoot', $pdf->toBytes());
    }

    #[Test]
    public function placing_without_a_link_throws(): void
    {
        $pdf = PdfDocument::new();
        $pdf->enableTagged();
        $page = $pdf->addPage();

        $this->expectException(\LogicException::class);
        $pdf->placeLinkInStructure($page);
    }

    /** @return array<int, array{S: string, K: string}> struct elements by object id */
    private static function structElements(string $bytes): array
    {
        preg_match_all('@\n(\d+) 0 obj\s*<< /Type /StructElem /S /(\w+) .*?/K (\[.*?\]|<< .*? >>|\d+)(?: /| >>)@s', $bytes, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $match) {
            $out[(int) $match[1]] = ['S' => $match[2], 'K' => $match[3]];
        }

        return $out;
    }

    /**
     * @param  array<int, array{S: string, K: string}>  $elems
     * @return array{S: string, K: string}
     */
    private static function single(array $elems, string $type): array
    {
        $found = array_values(array_filter($elems, fn (array $e) => $e['S'] === $type));
        self::assertCount(1, $found);

        return $found[0];
    }

    /** @return list<int> */
    private static function refs(string $k): array
    {
        preg_match_all('@(\d+) 0 R@', preg_replace('@<< /Type /OBJR .*? >>@', '', $k) ?? $k, $m);

        return array_map(intval(...), $m[1]);
    }
}
