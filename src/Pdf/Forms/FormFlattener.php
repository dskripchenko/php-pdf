<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Forms\Appearance\AppearanceBuilder;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderPage;

/**
 * Bakes widgets into page content the way Acrobat and pdftk do: each widget's
 * normal appearance (`/AP /N`, or its `/AS` state) is drawn on the page as a
 * Form XObject, mapped from its `/BBox` and `/Matrix` onto the widget's
 * `/Rect` (ISO 32000-1 §12.5.5), and the widget annotation is removed.
 *
 * That keeps everything the form author drew — check marks, radio dots,
 * backgrounds, borders, the font — instead of re-rendering values. A text or
 * choice appearance is regenerated first when it is missing, or when the form
 * says its appearances are stale (`/NeedAppearances true`).
 *
 * @internal
 */
final class FormFlattener
{
    private const FLAG_HIDDEN = 2;

    /** @var array<int,ReaderPage>|null source widget object number → page */
    private ?array $widgetPages = null;

    public function __construct(
        private readonly FormGraph $graph,
        private readonly PageEditor $pages,
        private readonly AppearanceBuilder $appearances,
        private readonly ?PdfDictionary $acroForm,
        private readonly ?PdfDictionary $dr,
        private readonly bool $needAppearances,
    ) {
    }

    /**
     * Flatten every widget annotation of the document.
     */
    public function flattenAll(): void
    {
        $byPage = [];
        foreach ($this->widgetPages() as $widgetObjNum => $page) {
            $byPage[$page->objectNumber][] = $widgetObjNum;
        }
        foreach ($byPage as $widgetObjNums) {
            $this->flattenWidgets($widgetObjNums, null);
        }
    }

    /**
     * Flatten the widgets of the given fields only.
     *
     * @param list<FieldNode> $nodes
     */
    public function flattenFields(array $nodes): void
    {
        $byPage = [];
        $map = $this->widgetPages();
        foreach ($nodes as $node) {
            foreach ($node->widgetObjNums as $widgetObjNum) {
                if (isset($map[$widgetObjNum])) {
                    $byPage[$map[$widgetObjNum]->objectNumber][$widgetObjNum] = $node->name;
                }
            }
        }
        foreach ($byPage as $widgets) {
            $this->flattenWidgets(array_keys($widgets), $widgets);
        }
    }

    /**
     * @param list<int>               $widgetObjNums all on the same page
     * @param array<int,string>|null  $names         widget → field name, for messages
     */
    private function flattenWidgets(array $widgetObjNums, ?array $names): void
    {
        $page = $this->widgetPages()[$widgetObjNums[0]];
        $pageId = $this->graph->id($page->objectNumber);
        $ops = [];
        foreach ($widgetObjNums as $widgetObjNum) {
            $widgetId = $this->graph->id($widgetObjNum);
            $draw = $this->drawOps($page, $widgetId, $names[$widgetObjNum] ?? null);
            if ($draw !== null) {
                $ops[] = $draw;
            }
            $this->graph->removeFromArray($pageId, 'Annots', $widgetId);
        }
        if ($ops !== []) {
            $this->pages->appendContent($page, implode("\n", $ops));
        }
    }

    private function drawOps(ReaderPage $page, int $widgetId, ?string $fieldName): ?string
    {
        $widget = $this->graph->dict($widgetId);
        if ($widget === null) {
            return null;
        }
        $flags = $this->graph->resolve($widget->get('F'));
        if (is_int($flags) && ($flags & self::FLAG_HIDDEN) !== 0) {
            return null;
        }

        $appearance = $this->appearance($widgetId, $widget, $fieldName);
        $stream = $appearance !== null ? $this->graph->get($appearance->number) : null;
        $rect = $this->numbers($widget->get('Rect'), 4);
        if (!$stream instanceof PdfStream || $rect === null) {
            return null;
        }
        $bbox = $this->numbers($stream->dict->get('BBox'), 4);
        if ($bbox === null) {
            return null;
        }
        if (!$stream->dict->get('Subtype') instanceof PdfName) {
            $this->graph->update($appearance->number, ['Type' => new PdfName('XObject'), 'Subtype' => new PdfName('Form')]);
        }

        // §12.5.5 algorithm: transform BBox by Matrix, then fit the result to Rect.
        $m = $this->numbers($stream->dict->get('Matrix'), 6) ?? [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $xs = $ys = [];
        foreach ([[$bbox[0], $bbox[1]], [$bbox[2], $bbox[1]], [$bbox[0], $bbox[3]], [$bbox[2], $bbox[3]]] as [$x, $y]) {
            $xs[] = $m[0] * $x + $m[2] * $y + $m[4];
            $ys[] = $m[1] * $x + $m[3] * $y + $m[5];
        }
        [$bx0, $bx1, $by0, $by1] = [min($xs), max($xs), min($ys), max($ys)];
        [$rx0, $rx1] = [min($rect[0], $rect[2]), max($rect[0], $rect[2])];
        [$ry0, $ry1] = [min($rect[1], $rect[3]), max($rect[1], $rect[3])];
        if ($bx1 - $bx0 <= 0 || $by1 - $by0 <= 0) {
            return null;
        }
        $sx = ($rx1 - $rx0) / ($bx1 - $bx0);
        $sy = ($ry1 - $ry0) / ($by1 - $by0);

        $name = $this->pages->addResource($page, 'XObject', 'FlatAP', $appearance);

        return sprintf(
            "q %s 0 0 %s %s %s cm /%s Do Q",
            self::num($sx),
            self::num($sy),
            self::num($rx0 - $bx0 * $sx),
            self::num($ry0 - $by0 * $sy),
            $name,
        );
    }

    /** The appearance stream to draw for a widget, generating one when needed. */
    private function appearance(int $widgetId, PdfDictionary $widget, ?string $fieldName): ?PdfReference
    {
        $attributes = FieldAttributes::forWidget($this->graph, $widgetId, $this->acroForm);
        $normal = $this->graph->dict($widget->get('AP'))?->get('N');
        $normalValue = $this->graph->resolve($normal);

        if (in_array($attributes->ft, ['Tx', 'Ch'], true)
            && ($this->needAppearances || !$normalValue instanceof PdfStream)) {
            return $this->appearances->variableText($attributes, $widget, $this->dr, $fieldName ?? $this->fallbackName($widgetId));
        }

        if ($normalValue instanceof PdfStream) {
            return $normal instanceof PdfReference ? $normal : $this->graph->allocate($normalValue);
        }

        $state = $widget->get('AS');
        $state = $state instanceof PdfName ? $state->value : 'Off';
        if ($normalValue instanceof PdfDictionary) {
            $chosen = $normalValue->get($state);
            if ($chosen instanceof PdfReference && $this->graph->get($chosen->number) instanceof PdfStream) {
                return $chosen;
            }

            return null;
        }

        if ($attributes->ft === 'Btn' && ($attributes->flags & FieldAttributes::FF_PUSHBUTTON) === 0) {
            $generated = $this->appearances->button($widget, ($attributes->flags & FieldAttributes::FF_RADIO) !== 0);

            return $generated === null ? null : ($state !== 'Off' ? $generated[0] : $generated[1]);
        }

        return null;
    }

    /**
     * Every widget annotation reachable from a page's `/Annots`, mapped to its
     * page (source object numbers).
     *
     * @return array<int,ReaderPage>
     */
    private function widgetPages(): array
    {
        if ($this->widgetPages !== null) {
            return $this->widgetPages;
        }
        $this->widgetPages = [];
        $doc = $this->graph->source;
        foreach ($this->pages->pages() as $page) {
            $annots = $doc->deref($page->dict->get('Annots'));
            foreach (is_array($annots) ? $annots : [] as $ref) {
                if (!$ref instanceof PdfReference || isset($this->widgetPages[$ref->number])) {
                    continue;
                }
                $annot = $doc->deref($ref);
                $subtype = $annot instanceof PdfDictionary ? $annot->get('Subtype') : null;
                if ($subtype instanceof PdfName && $subtype->value === 'Widget') {
                    $this->widgetPages[$ref->number] = $page;
                }
            }
        }

        return $this->widgetPages;
    }

    private function fallbackName(int $widgetId): string
    {
        $parts = [];
        $id = $widgetId;
        for ($depth = 0; $id !== null && $depth < 64; $depth++) {
            $dict = $this->graph->dict($id);
            $partial = TextString::decode($dict?->get('T'));
            if ($partial !== null) {
                array_unshift($parts, $partial);
            }
            $parent = $dict?->get('Parent');
            $id = $parent instanceof PdfReference ? $parent->number : null;
        }

        return implode('.', $parts);
    }

    /** @return list<float>|null */
    private function numbers(mixed $value, int $count): ?array
    {
        $array = $this->graph->array($value);
        if ($array === null || count($array) !== $count) {
            return null;
        }
        $out = [];
        foreach ($array as $v) {
            $v = $this->graph->resolve($v);
            if (!is_int($v) && !is_float($v)) {
                return null;
            }
            $out[] = (float) $v;
        }

        return $out;
    }

    private static function num(float $v): string
    {
        return FormGraph::number($v, 5);
    }
}
