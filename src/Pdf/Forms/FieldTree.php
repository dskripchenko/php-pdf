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
 * (ISO 32000-1 §12.7.3.2) and inheriting `/FT`, `/DA` and `/Ff` from
 * ancestors that don't redeclare them.
 *
 * A `/Kids` entry is a *child field* (recursed into) when it has its own
 * `/T`; a `/Kids` entry without `/T` is just one more widget annotation of
 * the current field (e.g. one option of a radio group, or the same field
 * repeated on several pages) rather than a new name level.
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
        foreach ($fields as $ref) {
            $this->walk($doc, $ref, null, null, null, null, null, $out, 0);
        }

        return $out;
    }

    /**
     * @param array<string, FieldNode> $out
     */
    private function walk(
        ReaderDocument $doc,
        mixed $ref,
        ?string $parentName,
        ?int $parentObjNum,
        ?string $inheritedFt,
        ?string $inheritedDa,
        ?int $inheritedFf,
        array &$out,
        int $depth,
    ): void {
        if ($depth > self::MAX_DEPTH || !$ref instanceof PdfReference) {
            return;
        }
        $dict = $doc->deref($ref);
        if (!$dict instanceof PdfDictionary) {
            return;
        }

        $partial = $this->decodeTextString($dict->get('T'));
        $name = $partial !== null
            ? ($parentName !== null ? $parentName . '.' . $partial : $partial)
            : $parentName;

        $ft = $this->nameValue($dict->get('FT')) ?? $inheritedFt;
        $da = $dict->get('DA') instanceof PdfString ? $this->decodeName($dict->get('DA')) : $inheritedDa;
        $ff = is_int($dict->get('Ff')) ? $dict->get('Ff') : $inheritedFf;
        $tu = $this->decodeTextString($dict->get('TU'));

        $kids = $doc->deref($dict->get('Kids'));
        $childFieldRefs = [];
        $widgetRefs = [];
        if (is_array($kids)) {
            foreach ($kids as $kidRef) {
                $kidDict = $doc->deref($kidRef);
                if ($kidDict instanceof PdfDictionary && $kidDict->has('T')) {
                    $childFieldRefs[] = $kidRef;
                } else {
                    $widgetRefs[] = $kidRef;
                }
            }
        }

        if ($childFieldRefs !== []) {
            foreach ($childFieldRefs as $kidRef) {
                $this->walk($doc, $kidRef, $name, $ref->number, $ft, $da, $ff, $out, $depth + 1);
            }
            return;
        }

        if ($name === null || $ft === null) {
            return;
        }

        $widgetObjNums = [];
        if ($widgetRefs !== []) {
            foreach ($widgetRefs as $widgetRef) {
                if ($widgetRef instanceof PdfReference) {
                    $widgetObjNums[] = $widgetRef->number;
                }
            }
        } else {
            $widgetObjNums = [$ref->number];
        }

        $type = $this->resolveType($ft, $ff ?? 0);
        $value = $this->stringValue($doc->deref($dict->get('V')));
        $options = $this->collectOptions($doc, $widgetObjNums);

        $out[$name] = new FieldNode(
            name: $name,
            type: $type,
            fieldObjNum: $ref->number,
            widgetObjNums: $widgetObjNums,
            parentObjNum: $parentObjNum,
            da: $da,
            tu: $tu,
            value: $value,
            options: $options,
            required: (($ff ?? 0) & 2) !== 0,
            readOnly: (($ff ?? 0) & 1) !== 0,
        );
    }

    private function resolveType(string $ft, int $ff): string
    {
        return match ($ft) {
            'Tx' => ($ff & 4096) !== 0 ? 'text-multiline' : 'text',
            'Btn' => match (true) {
                ($ff & 32768) !== 0 => 'radio',
                ($ff & 65536) !== 0 => 'push',
                default => 'checkbox',
            },
            'Ch' => ($ff & 131072) !== 0 ? 'combo' : 'list',
            default => $ft,
        };
    }

    /**
     * @param  list<int>  $widgetObjNums
     * @return list<string>
     */
    private function collectOptions(ReaderDocument $doc, array $widgetObjNums): array
    {
        $options = [];
        foreach ($widgetObjNums as $objNum) {
            $widget = $doc->deref(new PdfReference($objNum, 0));
            if (!$widget instanceof PdfDictionary) {
                continue;
            }
            $ap = $doc->deref($widget->get('AP'));
            if (!$ap instanceof PdfDictionary) {
                continue;
            }
            $n = $doc->deref($ap->get('N'));
            if (!$n instanceof PdfDictionary) {
                continue;
            }
            foreach (array_keys($n->all()) as $key) {
                if ($key !== 'Off' && !in_array($key, $options, true)) {
                    $options[] = $key;
                }
            }
        }

        return $options;
    }

    private function nameValue(mixed $value): ?string
    {
        return $value instanceof PdfName ? $value->value : null;
    }

    private function decodeName(mixed $value): ?string
    {
        return $value instanceof PdfString ? $value->bytes : null;
    }

    /**
     * Decodes a PDF text string (ISO 32000-1 §7.9.2.2): UTF-16BE with a
     * `\xFE\xFF` byte order mark, or PDFDocEncoding (ASCII-compatible for
     * the characters this library writes) when the BOM is absent.
     */
    private function decodeTextString(mixed $value): ?string
    {
        if (!$value instanceof PdfString) {
            return null;
        }

        $bytes = $value->bytes;
        if (!str_starts_with($bytes, "\xFE\xFF")) {
            return $bytes;
        }

        $decoded = @iconv('UTF-16BE', 'UTF-8', substr($bytes, 2));

        return $decoded !== false ? $decoded : $bytes;
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
}
