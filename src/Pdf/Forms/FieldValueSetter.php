<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Merge\ObjectImporter;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;

/**
 * Applies a value to one already-imported (deep-copied) AcroForm field,
 * mutating the copies held by an {@see ObjectImporter} in place. The source
 * document is never touched — `$importer->importObject()` deduplicates, so
 * re-requesting a field or widget already copied returns the same new id.
 */
final class FieldValueSetter
{
    /**
     * Values considered "checked" for a checkbox when no exact export-name
     * match is found among the widget's own `/AP/N` keys.
     */
    private const TRUTHY = ['on', 'yes', 'true', '1'];

    public function apply(ObjectImporter $importer, FieldNode $node, string $value): void
    {
        match ($node->type) {
            'checkbox' => $this->applyCheckbox($importer, $node, $value),
            'radio' => $this->applyRadio($importer, $node, $value),
            default => $this->applyText($importer, $node, $value),
        };
    }

    private function applyText(ObjectImporter $importer, FieldNode $node, string $value): void
    {
        $fieldId = $importer->importObject($node->fieldObjNum)->number;
        $this->setItems($importer, $fieldId, ['V' => new PdfString($value)]);
        // Drop any stale appearance so /NeedAppearances forces the reader to
        // regenerate it from the new /V rather than showing the old value.
        $this->removeAp($importer, $fieldId);

        foreach ($node->widgetObjNums as $widgetObjNum) {
            if ($widgetObjNum === $node->fieldObjNum) {
                continue;
            }
            $this->removeAp($importer, $importer->importObject($widgetObjNum)->number);
        }
    }

    private function applyCheckbox(ObjectImporter $importer, FieldNode $node, string $value): void
    {
        $state = 'Off';
        foreach ($node->widgetObjNums as $widgetObjNum) {
            $widgetId = $importer->importObject($widgetObjNum)->number;
            $onKey = $this->onStateKey($importer, $widgetId);
            if ($onKey !== null && $this->matches($value, $onKey)) {
                $state = $onKey;
            }
        }

        // A second pass, since every widget must agree on the same state
        // (e.g. the same checkbox repeated on several pages) even though
        // only one of them may own the matching /AP/N export name.
        foreach ($node->widgetObjNums as $widgetObjNum) {
            $widgetId = $importer->importObject($widgetObjNum)->number;
            $onKey = $this->onStateKey($importer, $widgetId);
            $this->setItems($importer, $widgetId, ['AS' => new PdfName($onKey === $state ? $state : 'Off')]);
        }

        $fieldId = $importer->importObject($node->fieldObjNum)->number;
        $this->setItems($importer, $fieldId, ['V' => new PdfName($state)]);
    }

    private function applyRadio(ObjectImporter $importer, FieldNode $node, string $value): void
    {
        $selected = null;
        foreach ($node->widgetObjNums as $widgetObjNum) {
            $widgetId = $importer->importObject($widgetObjNum)->number;
            $onKey = $this->onStateKey($importer, $widgetId);
            // Exact match only: unlike a checkbox, a radio group has more
            // than one "on" state, so the generic yes/true/1 TRUTHY aliases
            // (meant for a single on/off toggle) must not spuriously match
            // whichever option happens to be checked last in widget order.
            if ($onKey !== null && strcasecmp($value, $onKey) === 0) {
                $selected = $onKey;
            }
        }

        foreach ($node->widgetObjNums as $widgetObjNum) {
            $widgetId = $importer->importObject($widgetObjNum)->number;
            $onKey = $this->onStateKey($importer, $widgetId);
            $state = ($selected !== null && $onKey === $selected) ? $selected : 'Off';
            $this->setItems($importer, $widgetId, ['AS' => new PdfName($state)]);
        }

        $fieldId = $importer->importObject($node->fieldObjNum)->number;
        $this->setItems($importer, $fieldId, ['V' => new PdfName($selected ?? 'Off')]);
    }

    private function matches(string $value, string $onKey): bool
    {
        if (strcasecmp($value, $onKey) === 0) {
            return true;
        }

        return in_array(strtolower($value), self::TRUTHY, true);
    }

    /**
     * The single non-`Off` key of a button widget's own `/AP/N` sub-dictionary
     * — the export name a reader shows when that widget is selected. Not read
     * from a fixed convention, since it's author-defined per widget.
     */
    private function onStateKey(ObjectImporter $importer, int $widgetId): ?string
    {
        $widget = $importer->get($widgetId);
        if (!$widget instanceof PdfDictionary) {
            return null;
        }
        $ap = $widget->get('AP');
        if (!$ap instanceof PdfDictionary) {
            return null;
        }
        $n = $ap->get('N');
        if (!$n instanceof PdfDictionary) {
            return null;
        }
        foreach (array_keys($n->all()) as $key) {
            if ($key !== 'Off') {
                return $key;
            }
        }

        return null;
    }

    private function removeAp(ObjectImporter $importer, int $objId): void
    {
        $dict = $importer->get($objId);
        if (!$dict instanceof PdfDictionary || !$dict->has('AP')) {
            return;
        }
        $items = $dict->all();
        unset($items['AP']);
        $importer->set($objId, new PdfDictionary($items));
    }

    /** @param array<string,mixed> $changes */
    private function setItems(ObjectImporter $importer, int $objId, array $changes): void
    {
        $dict = $importer->get($objId);
        $items = $dict instanceof PdfDictionary ? $dict->all() : [];
        $importer->set($objId, new PdfDictionary(array_merge($items, $changes)));
    }
}
