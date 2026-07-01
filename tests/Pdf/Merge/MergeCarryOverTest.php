<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Merge;

use Dskripchenko\PhpPdf\Pdf\Merge\PdfMerger;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfSource;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Annotation and outline (bookmark) carry-over during merge, with internal
 * destinations remapped to the new pages. Exercised against real third-party
 * fixtures (FPDF2 annotations, hyperref/pdfTeX named-destination outlines).
 */
final class MergeCarryOverTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/external';

    private function source(string $file): PdfSource
    {
        $path = self::DIR . '/' . $file;
        if (!is_file($path)) {
            self::markTestSkipped("Fixture {$file} not present");
        }
        return PdfSource::fromFile($path);
    }

    private function annotCount(ReaderDocument $doc, int $page): int
    {
        $annots = $doc->deref($doc->pages()[$page]->dict->get('Annots'));
        return is_array($annots) ? count($annots) : 0;
    }

    /** Depth-first count of items in the merged outline tree. */
    private function outlineItemCount(ReaderDocument $doc): int
    {
        $outlines = $doc->deref($doc->catalog()->get('Outlines'));
        if (!$outlines instanceof PdfDictionary) {
            return 0;
        }
        $count = 0;
        $stack = [$outlines->get('First')];
        while ($stack !== []) {
            $cur = array_pop($stack);
            while ($cur instanceof PdfReference) {
                $item = $doc->deref($cur);
                if (!$item instanceof PdfDictionary) {
                    break;
                }
                $count++;
                if ($item->get('First') !== null) {
                    $stack[] = $item->get('First');
                }
                $cur = $item->get('Next');
            }
        }
        return $count;
    }

    #[Test]
    public function carries_page_annotations_by_default(): void
    {
        $out = ReaderDocument::fromBytes(
            PdfMerger::create()->append($this->source('annotated.pdf'))->toBytes()
        );
        self::assertSame(3, $this->annotCount($out, 0));
    }

    #[Test]
    public function without_annotations_drops_them(): void
    {
        $out = ReaderDocument::fromBytes(
            PdfMerger::create()->append($this->source('annotated.pdf'))->withoutAnnotations()->toBytes()
        );
        self::assertSame(0, $this->annotCount($out, 0));
    }

    #[Test]
    public function carries_outline_with_named_destinations(): void
    {
        // pdflatex-outline.pdf has 9 bookmarks using named destinations; all
        // four pages are included, so every bookmark survives.
        $out = ReaderDocument::fromBytes(
            PdfMerger::create()->append($this->source('pdflatex-outline.pdf'))->toBytes()
        );
        self::assertSame(9, $this->outlineItemCount($out));
    }

    #[Test]
    public function outline_destinations_point_to_new_pages(): void
    {
        $out = ReaderDocument::fromBytes(
            PdfMerger::create()->append($this->source('pdflatex-outline.pdf'))->toBytes()
        );
        $outputPageNums = array_map(fn ($p) => $p->objectNumber, $out->pages());

        $outlines = $out->deref($out->catalog()->get('Outlines'));
        $item = $out->deref($outlines->get('First'));
        self::assertInstanceOf(PdfDictionary::class, $item);

        // Resolve the first item's destination (explicit array after remap).
        $dest = $item->get('Dest');
        if ($dest === null) {
            $action = $out->deref($item->get('A'));
            $dest = $action instanceof PdfDictionary ? $action->get('D') : null;
        }
        $dest = $out->deref($dest);
        self::assertIsArray($dest, 'first outline item should have an explicit dest');
        self::assertInstanceOf(PdfReference::class, $dest[0]);
        self::assertContains($dest[0]->number, $outputPageNums, 'dest must point at an output page');
    }

    #[Test]
    public function dangling_bookmarks_are_dropped(): void
    {
        // Only page 1 is included; bookmarks targeting pages 2-4 (and their
        // subtrees) are dropped per the merge policy.
        $out = ReaderDocument::fromBytes(
            PdfMerger::create()->append($this->source('pdflatex-outline.pdf'), pages: [1])->toBytes()
        );
        self::assertLessThan(9, $this->outlineItemCount($out));
    }

    #[Test]
    public function without_outlines_drops_them(): void
    {
        $out = ReaderDocument::fromBytes(
            PdfMerger::create()->append($this->source('pdflatex-outline.pdf'))->withoutOutlines()->toBytes()
        );
        self::assertFalse($out->deref($out->catalog()->get('Outlines')) instanceof PdfDictionary);
    }

    #[Test]
    public function merged_outline_is_rereadable_and_valid(): void
    {
        $bytes = PdfMerger::create()
            ->append($this->source('pdflatex-outline.pdf'))
            ->append($this->source('annotated.pdf'))
            ->toBytes();
        $out = ReaderDocument::fromBytes($bytes);
        self::assertSame(5, $out->pageCount());
        // Outline root wiring is intact (First/Last present, walkable).
        $outlines = $out->deref($out->catalog()->get('Outlines'));
        self::assertInstanceOf(PdfDictionary::class, $outlines);
        self::assertInstanceOf(PdfReference::class, $outlines->get('First'));
        self::assertGreaterThan(0, $this->outlineItemCount($out));
    }
}
