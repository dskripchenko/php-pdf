<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Merge;

use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderPage;

/**
 * Concatenates whole or selected pages from one or more existing PDF documents
 * into a new document (pdftk-style page merge).
 *
 * Each output page is rebuilt from the source page's flattened attributes
 * (MediaBox/CropBox/Rotate/Resources) plus its imported /Contents, so the
 * source page tree — and its /Parent back-references — never drag sibling
 * pages into the result.
 *
 * v1 non-goals (see docs/design/pdf-merge.md): AcroForm field trees, structure
 * tags, page annotations, outlines, and named destinations are not carried
 * over.
 */
final class PdfMerger
{
    /** @var list<array{source: PdfSource, pages: ?list<int>}> */
    private array $jobs = [];

    public static function create(): self
    {
        return new self();
    }

    /**
     * Append pages from a source. `$pages` is a list of 1-based page numbers in
     * the desired output order; null appends every page in reading order.
     *
     * @param list<int>|null $pages
     */
    public function append(PdfSource $source, ?array $pages = null): self
    {
        $this->jobs[] = ['source' => $source, 'pages' => $pages];
        return $this;
    }

    public function toBytes(): string
    {
        $importer = new ObjectImporter();

        // Reserve fixed IDs for the catalog and page-tree root so pages can
        // reference the tree before it is filled in.
        $catalogId = $importer->allocate(null);
        $pagesId = $importer->allocate(null);

        /** @var list<PdfReference> $kids */
        $kids = [];

        foreach ($this->jobs as $job) {
            $doc = $job['source']->document();
            $allPages = $doc->pages();
            $selected = $this->select($allPages, $job['pages']);

            $importer->useSource($doc);
            foreach ($selected as $page) {
                $kids[] = new PdfReference($this->importPage($importer, $page, $pagesId), 0);
            }
        }

        $importer->set($pagesId, new PdfDictionary([
            'Type' => new PdfName('Pages'),
            'Kids' => $kids,
            'Count' => count($kids),
        ]));
        $importer->set($catalogId, new PdfDictionary([
            'Type' => new PdfName('Catalog'),
            'Pages' => new PdfReference($pagesId, 0),
        ]));

        return (new MergeSerializer())->serialize($importer->objects(), $catalogId);
    }

    public function toFile(string $path): int
    {
        $bytes = $this->toBytes();
        $written = @file_put_contents($path, $bytes);
        if ($written === false) {
            throw new \RuntimeException("Cannot write PDF file: {$path}");
        }
        return $written;
    }

    /**
     * @param list<ReaderPage> $pages
     * @param list<int>|null   $selection 1-based page numbers
     * @return list<ReaderPage>
     */
    private function select(array $pages, ?array $selection): array
    {
        if ($selection === null) {
            return $pages;
        }
        $out = [];
        foreach ($selection as $n) {
            if ($n < 1 || $n > count($pages)) {
                throw new \OutOfRangeException("Page {$n} does not exist (document has " . count($pages) . ' pages)');
            }
            $out[] = $pages[$n - 1];
        }
        return $out;
    }

    /**
     * Build one output page from a source page and return its new object ID.
     */
    private function importPage(ObjectImporter $importer, ReaderPage $page, int $pagesId): int
    {
        $items = [
            'Type' => new PdfName('Page'),
            'Parent' => new PdfReference($pagesId, 0),
            'MediaBox' => $page->mediaBox,
            'Resources' => $importer->importValue($page->resources ?? new PdfDictionary([])),
        ];

        $contents = $page->dict->get('Contents');
        if ($contents !== null) {
            $items['Contents'] = $importer->importValue($contents);
        }
        if ($page->rotate !== 0) {
            $items['Rotate'] = $page->rotate;
        }
        if ($page->cropBox !== $page->mediaBox) {
            $items['CropBox'] = $page->cropBox;
        }

        return $importer->allocate(new PdfDictionary($items));
    }
}
