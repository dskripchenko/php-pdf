<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderPage;

/**
 * Appends drawing to pages of a {@see FormGraph} without disturbing what is
 * already there:
 *
 *  - the page's existing content is wrapped in `q … Q` before the first append,
 *    so a transformation or colour it leaves active cannot leak into ours;
 *  - inherited `/Resources` (§7.7.3.4) are copied onto the page before a name
 *    is added, so the page does not lose the fonts and images of its ancestors;
 *  - resource names are chosen so they never shadow an existing entry.
 *
 * @internal
 */
final class PageEditor
{
    /** @var array<int,true> new page ids whose content is already wrapped */
    private array $wrapped = [];

    /** @var array<int,ReaderPage>|null source page object number → page */
    private ?array $pagesByObjNum = null;

    public function __construct(private readonly FormGraph $graph)
    {
    }

    /** @return list<ReaderPage> */
    public function pages(): array
    {
        return $this->graph->source->pages();
    }

    public function page(int $sourceObjNum): ?ReaderPage
    {
        if ($this->pagesByObjNum === null) {
            $this->pagesByObjNum = [];
            foreach ($this->pages() as $page) {
                $this->pagesByObjNum[$page->objectNumber] = $page;
            }
        }

        return $this->pagesByObjNum[$sourceObjNum] ?? null;
    }

    /**
     * Register `$value` in the page's `$category` resources (`Font`,
     * `XObject`, …) under a fresh name starting with `$prefix`.
     */
    public function addResource(ReaderPage $page, string $category, string $prefix, PdfReference $value): string
    {
        $pageId = $this->graph->id($page->objectNumber);
        $resourcesRaw = $this->ownResources($page, $pageId);
        $resourcesId = $resourcesRaw instanceof PdfReference ? $resourcesRaw->number : null;
        $resources = $this->graph->dict($resourcesRaw) ?? new PdfDictionary([]);

        $categoryRaw = $resources->get($category);
        $entries = $this->graph->dict($categoryRaw)?->all() ?? [];
        for ($n = 0; isset($entries[$prefix.$n]); $n++) {
        }
        $name = $prefix.$n;
        $entries[$name] = $value;

        if ($categoryRaw instanceof PdfReference) {
            $this->graph->set($categoryRaw->number, new PdfDictionary($entries));

            return $name;
        }
        $items = $resources->all();
        $items[$category] = new PdfDictionary($entries);
        if ($resourcesId !== null) {
            $this->graph->set($resourcesId, new PdfDictionary($items));
        } else {
            $this->graph->update($pageId, ['Resources' => new PdfDictionary($items)]);
        }

        return $name;
    }

    /** Append a content stream to the page (wrapping the existing content first). */
    public function appendContent(ReaderPage $page, string $content): void
    {
        $pageId = $this->graph->id($page->objectNumber);
        $dict = $this->graph->dict($pageId);
        if ($dict === null) {
            return;
        }
        $raw = $dict->get('Contents');
        $streams = match (true) {
            $raw instanceof PdfReference && is_array($this->graph->get($raw->number)) => $this->graph->array($raw) ?? [],
            $raw instanceof PdfReference => [$raw],
            is_array($raw) => array_values($raw),
            default => [],
        };

        if (!isset($this->wrapped[$pageId])) {
            $this->wrapped[$pageId] = true;
            if ($streams !== []) {
                array_unshift($streams, $this->graph->allocateStream(new PdfDictionary([]), "q\n"));
                $content = "Q\n".$content;
            }
        }
        $streams[] = $this->graph->allocateStream(new PdfDictionary([]), $content);
        $this->graph->update($pageId, ['Contents' => $streams]);
    }

    /**
     * The affine transform `[a b c d e f]` from the page *as displayed* — top-
     * left origin, y down, in points, over the visible CropBox and after
     * `/Rotate` — to the page's default user space.
     *
     * @return array{float,float,float,float,float,float}
     */
    public static function displayToUser(ReaderPage $page): array
    {
        [$x0, $y0, $x1, $y1] = $page->cropBox;
        [$x0, $x1] = [min($x0, $x1), max($x0, $x1)];
        [$y0, $y1] = [min($y0, $y1), max($y0, $y1)];

        // (dx, dy) on the displayed page → (X, Y) = (a·dx + c·dy + e, b·dx + d·dy + f)
        return match ($page->rotate) {
            90 => [0.0, 1.0, 1.0, 0.0, $x0, $y0],
            180 => [-1.0, 0.0, 0.0, 1.0, $x1, $y0],
            270 => [0.0, -1.0, -1.0, 0.0, $x1, $y1],
            default => [1.0, 0.0, 0.0, -1.0, $x0, $y1],
        };
    }

    /** @return array{width: float, height: float} the page size as displayed */
    public static function displaySize(ReaderPage $page): array
    {
        $w = abs($page->cropBox[2] - $page->cropBox[0]);
        $h = abs($page->cropBox[3] - $page->cropBox[1]);

        return $page->rotate % 180 === 0 ? ['width' => $w, 'height' => $h] : ['width' => $h, 'height' => $w];
    }

    /**
     * The page's own `/Resources` value (inline dictionary or reference),
     * materialising inherited resources onto the page first.
     */
    private function ownResources(ReaderPage $page, int $pageId): mixed
    {
        $dict = $this->graph->dict($pageId);
        $own = $dict?->get('Resources');
        if ($own !== null) {
            return $own;
        }
        $inherited = $page->resources !== null
            ? $this->graph->importer->importValue($page->resources)
            : new PdfDictionary([]);
        $this->graph->update($pageId, ['Resources' => $inherited]);

        return $inherited;
    }
}
