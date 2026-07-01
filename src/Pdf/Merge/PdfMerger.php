<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Merge;

use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
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

    /** @var list<array{source: PdfSource, page: int, onPages: ?list<int>, placement: Placement}> */
    private array $stamps = [];

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

    /**
     * Embed (stamp) a source page on top of already-appended output pages.
     *
     * @param int            $page      1-based page number in the overlay source
     * @param list<int>|null $onPages   1-based output page numbers to stamp; null = all
     * @param Placement|null $placement how to size/position the overlay (default: fit)
     */
    public function stamp(PdfSource $source, int $page = 1, ?array $onPages = null, ?Placement $placement = null): self
    {
        $this->stamps[] = [
            'source' => $source,
            'page' => $page,
            'onPages' => $onPages,
            'placement' => $placement ?? Placement::fit(),
        ];
        return $this;
    }

    public function toBytes(): string
    {
        $importer = new ObjectImporter();

        // Reserve fixed IDs for the catalog and page-tree root so pages can
        // reference the tree before it is filled in.
        $catalogId = $importer->allocate(null);
        $pagesId = $importer->allocate(null);

        /** @var list<int> $pageIds output page object IDs in order */
        $pageIds = [];
        /** @var list<array{float,float,float,float}> $pageBoxes matching MediaBoxes */
        $pageBoxes = [];

        foreach ($this->jobs as $job) {
            $doc = $job['source']->document();
            $selected = $this->select($doc->pages(), $job['pages']);

            $importer->useSource($doc);
            foreach ($selected as $page) {
                $pageIds[] = $this->importPage($importer, $page, $pagesId);
                $pageBoxes[] = $page->mediaBox;
            }
        }

        $this->applyStamps($importer, $pageIds, $pageBoxes);

        $importer->set($pagesId, new PdfDictionary([
            'Type' => new PdfName('Pages'),
            'Kids' => array_map(fn (int $id) => new PdfReference($id, 0), $pageIds),
            'Count' => count($pageIds),
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
     * Apply every stamp onto its target output pages.
     *
     * @param list<int>                        $pageIds
     * @param list<array{float,float,float,float}> $pageBoxes
     */
    private function applyStamps(ObjectImporter $importer, array $pageIds, array $pageBoxes): void
    {
        if ($this->stamps === []) {
            return;
        }

        $builder = new PageXObjectBuilder($importer);
        $xobjCache = [];
        $wrapped = [];
        $qRef = null;
        $qEndRef = null;

        foreach ($this->stamps as $stamp) {
            $doc = $stamp['source']->document();
            $overlayPages = $doc->pages();
            $idx = $stamp['page'] - 1;
            if ($idx < 0 || $idx >= count($overlayPages)) {
                throw new \OutOfRangeException("Overlay page {$stamp['page']} does not exist");
            }

            $key = spl_object_id($doc) . ':' . $idx;
            $xobjCache[$key] ??= $builder->build($doc, $overlayPages[$idx]);
            [$xRef, $w, $h] = $xobjCache[$key];

            $targets = $stamp['onPages'] ?? range(1, count($pageIds));
            foreach ($targets as $t) {
                if ($t < 1 || $t > count($pageIds)) {
                    throw new \OutOfRangeException("Output page {$t} does not exist");
                }
                $i = $t - 1;

                if (!isset($wrapped[$i])) {
                    $qRef ??= new PdfReference($importer->allocate(new PdfStream(new PdfDictionary([]), 'q')), 0);
                    $qEndRef ??= new PdfReference($importer->allocate(new PdfStream(new PdfDictionary([]), 'Q')), 0);
                    $this->wrapPageContents($importer, $pageIds[$i], $qRef, $qEndRef);
                    $wrapped[$i] = true;
                }

                $cm = $stamp['placement']->cm($w, $h, $pageBoxes[$i]);
                $name = 'FX' . $xRef->number;
                $draw = 'q ' . $this->formatMatrix($cm) . ' cm /' . $name . " Do Q\n";
                $drawRef = new PdfReference($importer->allocate(new PdfStream(new PdfDictionary([]), $draw)), 0);

                $this->addOverlay($importer, $pageIds[$i], $name, $xRef, $drawRef);
            }
        }
    }

    /**
     * Wrap a page's existing content in q/Q so overlays draw from the default
     * graphics state regardless of what the base content left behind.
     */
    private function wrapPageContents(ObjectImporter $importer, int $pageId, PdfReference $qRef, PdfReference $qEndRef): void
    {
        $dict = $importer->get($pageId);
        if (!$dict instanceof PdfDictionary) {
            return;
        }
        $contents = array_merge([$qRef], $this->contentsList($dict->get('Contents')), [$qEndRef]);
        $importer->set($pageId, $this->withKey($dict, 'Contents', $contents));
    }

    private function addOverlay(ObjectImporter $importer, int $pageId, string $name, PdfReference $xRef, PdfReference $drawRef): void
    {
        $dict = $importer->get($pageId);
        if (!$dict instanceof PdfDictionary) {
            return;
        }

        // Append the draw stream to /Contents.
        $contents = $this->contentsList($dict->get('Contents'));
        $contents[] = $drawRef;

        // Register the XObject under /Resources /XObject.
        $resources = $dict->get('Resources');
        $resources = $resources instanceof PdfDictionary ? $resources : new PdfDictionary([]);
        $xobjs = $resources->get('XObject');
        $xobjItems = $xobjs instanceof PdfDictionary ? $xobjs->all() : [];
        $xobjItems[$name] = $xRef;
        $resources = $this->withKey($resources, 'XObject', new PdfDictionary($xobjItems));

        $dict = $this->withKey($dict, 'Contents', $contents);
        $dict = $this->withKey($dict, 'Resources', $resources);
        $importer->set($pageId, $dict);
    }

    /** @return list<mixed> */
    private function contentsList(mixed $contents): array
    {
        if ($contents === null) {
            return [];
        }
        return is_array($contents) ? array_values($contents) : [$contents];
    }

    private function withKey(PdfDictionary $dict, string $key, mixed $value): PdfDictionary
    {
        $items = $dict->all();
        $items[$key] = $value;
        return new PdfDictionary($items);
    }

    /**
     * @param array{float,float,float,float,float,float} $m
     */
    private function formatMatrix(array $m): string
    {
        return implode(' ', array_map(function (float $v): string {
            if ($v === floor($v) && abs($v) < 1e15) {
                return (string) (int) $v;
            }
            return rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
        }, $m));
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
