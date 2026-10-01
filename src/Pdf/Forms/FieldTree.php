<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;

/**
 * Walks a source document's `/AcroForm /Fields` tree, resolving each
 * terminal field's fully-qualified dotted name from its `/Parent` chain
 * (ISO 32000-1 §12.7.3.2) and inheriting `/FT`, `/Ff`, `/V`, `/MaxLen` from
 * ancestors that don't redeclare them.
 *
 * A `/Kids` entry is a *child field* (recursed into) when it has its own
 * `/T`; a `/Kids` entry without `/T` is just one more widget annotation of
 * the current field (e.g. one option of a radio group, or the same field
 * repeated on several pages) rather than a new name level.
 *
 * @internal
 */
final class FieldTree
{
    private const MAX_DEPTH = 64;

    /** @return array<string, FieldNode> keyed by fully-qualified name */
    public function build(ReaderDocument $doc): array
    {
        $acroForm = $doc->deref($doc->catalog()->get('AcroForm'));
        if (!$acroForm instanceof PdfDictionary) {
            return [];
        }

        $fields = $doc->deref($acroForm->get('Fields'));
        if (!is_array($fields)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($fields as $ref) {
            $this->walk($doc, $ref, null, [], [], $out, $seen, 0);
        }

        return $out;
    }

    /**
     * @param list<int>             $ancestors source object numbers, nearest first
     * @param array<string,mixed>   $inherited inheritable entries from ancestors
     * @param array<string, FieldNode> $out
     * @param array<int,true>       $seen
     */
    private function walk(
        ReaderDocument $doc,
        mixed $ref,
        ?string $parentName,
        array $ancestors,
        array $inherited,
        array &$out,
        array &$seen,
        int $depth,
    ): void {
        if ($depth > self::MAX_DEPTH || !$ref instanceof PdfReference || isset($seen[$ref->number])) {
            return;
        }
        $seen[$ref->number] = true;
        $dict = $doc->deref($ref);
        if (!$dict instanceof PdfDictionary) {
            return;
        }

        $partial = TextString::decode($dict->get('T'));
        $name = $partial !== null
            ? ($parentName !== null ? $parentName.'.'.$partial : $partial)
            : $parentName;

        foreach (['FT', 'Ff', 'V', 'MaxLen', 'Opt'] as $key) {
            if ($dict->has($key)) {
                $inherited[$key] = $doc->deref($dict->get($key));
            }
        }

        $kids = $doc->deref($dict->get('Kids'));
        $childFieldRefs = [];
        $widgetObjNums = [];
        if (is_array($kids)) {
            foreach ($kids as $kidRef) {
                $kidDict = $doc->deref($kidRef);
                if ($kidDict instanceof PdfDictionary && $kidDict->has('T')) {
                    $childFieldRefs[] = $kidRef;
                } elseif ($kidRef instanceof PdfReference && $kidDict instanceof PdfDictionary) {
                    $widgetObjNums[] = $kidRef->number;
                }
            }
        } else {
            $widgetObjNums[] = $ref->number;
        }

        foreach ($childFieldRefs as $kidRef) {
            $this->walk($doc, $kidRef, $name, [$ref->number, ...$ancestors], $inherited, $out, $seen, $depth + 1);
        }

        $ft = ($inherited['FT'] ?? null) instanceof PdfName ? $inherited['FT']->value : null;
        if ($name === null || $ft === null || $widgetObjNums === [] || isset($out[$name])) {
            return;
        }

        $ff = is_int($inherited['Ff'] ?? null) ? $inherited['Ff'] : 0;
        $type = $this->resolveType($ft, $ff);
        $choiceOptions = $this->choiceOptions($doc, $inherited['Opt'] ?? null);
        $widgetExports = in_array($type, ['checkbox', 'radio'], true)
            ? $this->widgetExports($doc, $widgetObjNums, $choiceOptions)
            : [];

        $out[$name] = new FieldNode(
            name: $name,
            type: $type,
            fieldObjNum: $ref->number,
            widgetObjNums: $widgetObjNums,
            ancestorObjNums: $ancestors,
            tooltip: TextString::decode($dict->get('TU')),
            value: $this->value($doc, $inherited['V'] ?? null),
            options: match (true) {
                $widgetExports !== [] => array_values(array_unique(array_values($widgetExports))),
                default => array_column($choiceOptions, 0),
            },
            optionLabels: array_column($choiceOptions, 1, 0),
            widgetExports: $widgetExports,
            flags: $ff,
            maxLength: is_int($inherited['MaxLen'] ?? null) ? $inherited['MaxLen'] : null,
        );
    }

    private function resolveType(string $ft, int $ff): string
    {
        return match ($ft) {
            'Tx' => ($ff & FieldAttributes::FF_MULTILINE) !== 0 ? 'text-multiline' : 'text',
            'Btn' => match (true) {
                ($ff & FieldAttributes::FF_PUSHBUTTON) !== 0 => 'push',
                ($ff & FieldAttributes::FF_RADIO) !== 0 => 'radio',
                default => 'checkbox',
            },
            'Ch' => ($ff & FieldAttributes::FF_COMBO) !== 0 ? 'combo' : 'list',
            'Sig' => 'signature',
            default => $ft,
        };
    }

    /** @return list<array{string,string}> [export, display] */
    private function choiceOptions(ReaderDocument $doc, mixed $opt): array
    {
        $out = [];
        foreach (is_array($opt) ? $opt : [] as $entry) {
            $entry = $doc->deref($entry);
            if (is_array($entry) && count($entry) >= 2) {
                $export = TextString::decode($doc->deref($entry[0])) ?? '';
                $out[] = [$export, TextString::decode($doc->deref($entry[1])) ?? $export];
            } elseif (($text = TextString::decode($entry)) !== null) {
                $out[] = [$text, $text];
            }
        }

        return $out;
    }

    /**
     * The export value of each check box / radio widget: its on-state name in
     * `/AP /N`, or — when the field has `/Opt` — the option at the widget's
     * index (§12.7.4.2.3), since on-states are then just "0", "1", ….
     *
     * @param  list<int>                  $widgetObjNums
     * @param  list<array{string,string}> $options
     * @return array<int,string> widget object number → export value
     */
    private function widgetExports(ReaderDocument $doc, array $widgetObjNums, array $options): array
    {
        $out = [];
        foreach ($widgetObjNums as $i => $objNum) {
            $onKey = self::onState($doc->deref(new PdfReference($objNum, 0)), $doc);
            if ($onKey === null) {
                continue;
            }
            $out[$objNum] = count($options) === count($widgetObjNums) ? $options[$i][0] : $onKey;
        }

        return $out;
    }

    /** The non-`Off` appearance state of a button widget, if any. */
    public static function onState(mixed $widget, ReaderDocument $doc): ?string
    {
        if (!$widget instanceof PdfDictionary) {
            return null;
        }
        $ap = $doc->deref($widget->get('AP'));
        $n = $ap instanceof PdfDictionary ? $doc->deref($ap->get('N')) : null;
        if ($n instanceof PdfDictionary) {
            foreach (array_keys($n->all()) as $key) {
                if ($key !== 'Off') {
                    return (string) $key;
                }
            }
        }
        // A widget without an appearance still names its on-state in /AS.
        $as = $widget->get('AS');

        return $as instanceof PdfName && $as->value !== 'Off' ? $as->value : null;
    }

    /** @return string|list<string> */
    private function value(ReaderDocument $doc, mixed $value): string|array
    {
        if (is_array($value)) {
            return array_values(array_filter(
                array_map(static fn ($v) => TextString::decode($doc->deref($v)), $value),
                'is_string',
            ));
        }

        return TextString::decode($value instanceof PdfString || $value instanceof PdfName ? $value : null) ?? '';
    }
}
