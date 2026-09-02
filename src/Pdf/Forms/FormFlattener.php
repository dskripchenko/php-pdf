<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Merge\ObjectImporter;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;

/**
 * Bakes one field's current value into its page's content stream and
 * removes the interactive widget annotation, on an already-imported (deep
 * copied) object graph.
 *
 * Only text/text-multiline fields get their value drawn — the appearance is
 * built from the field's own `/DA` (font/size, resolved via the AcroForm's
 * imported `/DR` so no font is redeclared) and its widget's `/Rect`, single
 * line or naive fixed-leading multi-line. Other field types (checkbox,
 * radio, choice, …) just lose their widget annotation; baking in a checked
 * glyph or a choice's display text is not attempted here.
 *
 * Known limitation carried over from the original spike: an *unfilled*
 * flattened field only has its annotation removed — its placeholder
 * appearance (e.g. an `/MK /BG` background) is not baked in, so it
 * disappears rather than surviving as static content the way pdftk's
 * flatten does.
 */
final class FormFlattener
{
    public function flatten(
        ObjectImporter $importer,
        ReaderDocument $doc,
        FieldNode $node,
        ?PdfDictionary $importedDr,
        ?string $acroFormDa,
    ): void {
        // Read the field's *current* value from the already-imported copy,
        // not the FieldNode snapshot — a prior setValue() call has already
        // mutated the copy, and the snapshot reflects only the source.
        $fieldDict = $importer->get($importer->importObject($node->fieldObjNum)->number);
        $currentValue = $fieldDict instanceof PdfDictionary ? $this->stringValue($fieldDict->get('V')) : $node->value;

        foreach ($node->widgetObjNums as $widgetObjNum) {
            $this->flattenWidget($importer, $doc, $node, $currentValue, $widgetObjNum, $importedDr, $acroFormDa);
        }
    }

    private function flattenWidget(
        ObjectImporter $importer,
        ReaderDocument $doc,
        FieldNode $node,
        string $currentValue,
        int $widgetObjNum,
        ?PdfDictionary $importedDr,
        ?string $acroFormDa,
    ): void {
        $sourcePageObjNum = $this->findSourcePage($doc, $widgetObjNum);
        if ($sourcePageObjNum === null) {
            return;
        }
        $pageId = $importer->importObject($sourcePageObjNum)->number;
        $widgetId = $importer->importObject($widgetObjNum)->number;
        $widget = $importer->get($widgetId);
        if (!$widget instanceof PdfDictionary) {
            return;
        }

        if (in_array($node->type, ['text', 'text-multiline'], true) && $currentValue !== '') {
            $rect = $this->rect($widget);
            if ($rect !== null) {
                // buildContentStream() always returns a stream (it draws
                // whatever font it could resolve, or none at all), so there
                // is no null case to guard against here.
                [$content, $fontKey, $fontRef] = $this->buildContentStream($node, $currentValue, $rect, $node->da ?? $acroFormDa, $importedDr);
                $this->appendContent($importer, $pageId, $content, $fontKey, $fontRef);
            }
        }

        $this->removeAnnotation($importer, $pageId, $widgetId);
    }

    private function stringValue(mixed $value): string
    {
        if ($value instanceof PdfString) {
            return $value->bytes;
        }
        if ($value instanceof PdfName) {
            return $value->value;
        }

        return '';
    }

    private function findSourcePage(ReaderDocument $doc, int $widgetObjNum): ?int
    {
        $widget = $doc->deref(new PdfReference($widgetObjNum, 0));
        if ($widget instanceof PdfDictionary) {
            $p = $widget->get('P');
            if ($p instanceof PdfReference) {
                return $p->number;
            }
        }
        foreach ($doc->pages() as $page) {
            $annots = $doc->deref($page->dict->get('Annots'));
            if (!is_array($annots)) {
                continue;
            }
            foreach ($annots as $ref) {
                if ($ref instanceof PdfReference && $ref->number === $widgetObjNum) {
                    return $page->objectNumber;
                }
            }
        }

        return null;
    }

    /** @return array{float,float,float,float}|null */
    private function rect(PdfDictionary $widget): ?array
    {
        $rect = $widget->get('Rect');
        if (!is_array($rect) || count($rect) !== 4) {
            return null;
        }
        $values = array_map(static fn ($v) => is_numeric($v) ? (float) $v : null, $rect);
        if (in_array(null, $values, true)) {
            return null;
        }

        return [$values[0], $values[1], $values[2], $values[3]];
    }

    /**
     * @param  array{float,float,float,float}  $rect
     * @return array{string, ?string, ?PdfReference}
     */
    private function buildContentStream(FieldNode $node, string $value, array $rect, ?string $da, ?PdfDictionary $importedDr): array
    {
        [$fontKey, $fontSize] = $this->parseDa($da);
        $fontRef = null;
        if ($fontKey !== null && $importedDr instanceof PdfDictionary) {
            $fonts = $importedDr->get('Font');
            if ($fonts instanceof PdfDictionary) {
                $ref = $fonts->get($fontKey);
                if ($ref instanceof PdfReference) {
                    $fontRef = $ref;
                }
            }
        }

        [$llx, $lly, , $ury] = $rect;
        $lines = $node->type === 'text-multiline' ? explode("\n", $value) : [$value];
        $leading = $fontSize * 1.15;

        $ops = ['q', 'BT'];
        if ($fontKey !== null && $fontRef !== null) {
            $ops[] = sprintf('/%s %s Tf', $fontKey, $this->fmt($fontSize));
        }
        $ops[] = '0 g';
        $x = $llx + 2.0;
        $y = $node->type === 'text-multiline'
            ? $ury - $fontSize - 2.0
            : $lly + max(0.0, ($ury - $lly - $fontSize) / 2) + 1.0;
        $ops[] = sprintf('%s %s Td', $this->fmt($x), $this->fmt($y));
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $ops[] = sprintf('0 %s Td', $this->fmt(-$leading));
            }
            $ops[] = sprintf('(%s) Tj', $this->escape($line));
        }
        $ops[] = 'ET';
        $ops[] = 'Q';

        return [implode("\n", $ops), $fontKey, $fontRef];
    }

    /** @return array{?string, float} */
    private function parseDa(?string $da): array
    {
        if ($da !== null && preg_match('@/(\S+)\s+([\d.eE+-]+)\s+Tf@', $da, $m) === 1) {
            return [$m[1], (float) $m[2]];
        }

        return [null, 9.0];
    }

    private function escape(string $text): string
    {
        $out = '';
        for ($i = 0; $i < strlen($text); $i++) {
            $c = $text[$i];
            $ord = ord($c);
            if ($c === '\\' || $c === '(' || $c === ')') {
                $out .= '\\'.$c;
            } elseif ($ord < 0x20 || $ord > 0x7E) {
                $out .= sprintf('\\%03o', $ord);
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    private function fmt(float $v): string
    {
        if ($v === floor($v) && abs($v) < 1e9) {
            return (string) (int) $v;
        }

        return rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
    }

    private function appendContent(ObjectImporter $importer, int $pageId, string $content, ?string $fontKey, ?PdfReference $fontRef): void
    {
        $dict = $importer->get($pageId);
        if (!$dict instanceof PdfDictionary) {
            return;
        }
        $items = $dict->all();

        $contents = $items['Contents'] ?? null;
        $list = match (true) {
            is_array($contents) => array_values($contents),
            $contents !== null => [$contents],
            default => [],
        };
        $list[] = new PdfReference($importer->allocate(new PdfStream(new PdfDictionary([]), $content)), 0);
        $items['Contents'] = $list;

        if ($fontKey !== null && $fontRef !== null) {
            $resources = $this->resolveDict($importer, $items['Resources'] ?? null) ?? new PdfDictionary([]);
            $fonts = $this->resolveDict($importer, $resources->get('Font')) ?? new PdfDictionary([]);
            $fontItems = $fonts->all();
            if (!isset($fontItems[$fontKey])) {
                $fontItems[$fontKey] = $fontRef;
                $resItems = $resources->all();
                $resItems['Font'] = new PdfDictionary($fontItems);
                $resources = new PdfDictionary($resItems);
            }
            $items['Resources'] = $resources;
        }

        $importer->set($pageId, new PdfDictionary($items));
    }

    private function removeAnnotation(ObjectImporter $importer, int $pageId, int $widgetId): void
    {
        $dict = $importer->get($pageId);
        if (!$dict instanceof PdfDictionary) {
            return;
        }
        $annots = $this->resolveArray($importer, $dict->get('Annots'));
        if ($annots === null) {
            return;
        }
        $filtered = array_values(array_filter(
            $annots,
            static fn ($ref) => !($ref instanceof PdfReference && $ref->number === $widgetId),
        ));
        $items = $dict->all();
        $items['Annots'] = $filtered;
        $importer->set($pageId, new PdfDictionary($items));
    }

    /**
     * A dictionary value already read off an imported object may itself be an
     * indirect reference (ISO 32000-1 allows any value to be indirect) rather
     * than inlined — resolve it against the importer's own object map before
     * treating an absent match as "no such dictionary".
     */
    private function resolveDict(ObjectImporter $importer, mixed $value): ?PdfDictionary
    {
        if ($value instanceof PdfReference) {
            $value = $importer->get($value->number);
        }

        return $value instanceof PdfDictionary ? $value : null;
    }

    /**
     * Same as {@see resolveDict()}, but for an array-valued entry (e.g. /Annots).
     *
     * @return list<mixed>|null
     */
    private function resolveArray(ObjectImporter $importer, mixed $value): ?array
    {
        if ($value instanceof PdfReference) {
            $value = $importer->get($value->number);
        }

        return is_array($value) ? $value : null;
    }
}
