<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Merge;

use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfNull;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;

/**
 * Serializes a flat object set (from {@see ObjectImporter}) into a complete PDF
 * file with a classic cross-reference table.
 *
 * The output is unencrypted; imported stream bodies keep their original
 * `/Filter` chain, and `/Length` is restated from the actual byte count so
 * decrypted (AES-shortened) streams stay valid.
 */
final class MergeSerializer
{
    private const HEADER = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";

    /**
     * @param array<int,mixed> $objects newId → value (contiguous IDs from 1)
     * @param int              $rootId  object ID of the document catalog
     * @param array<string,mixed> $trailerExtra extra trailer entries (e.g. /Info)
     */
    public function serialize(array $objects, int $rootId, array $trailerExtra = []): string
    {
        $out = self::HEADER;
        $count = count($objects);
        $offsets = [];

        for ($id = 1; $id <= $count; $id++) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n";
            $out .= $this->encode($objects[$id] ?? PdfNull::instance());
            $out .= "\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $size = $count + 1;

        $out .= "xref\n";
        $out .= "0 {$size}\n";
        $out .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $count; $id++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        $out .= "trailer\n";
        $out .= $this->encode(new PdfDictionary(array_merge([
            'Size' => $size,
            'Root' => new PdfReference($rootId, 0),
        ], $trailerExtra)));
        $out .= "\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return $out;
    }

    private function encode(mixed $value): string
    {
        if ($value instanceof PdfStream) {
            return $this->encodeStream($value);
        }
        if ($value instanceof PdfDictionary) {
            return $this->encodeDictionary($value->all());
        }
        if ($value instanceof PdfReference) {
            return $value->number . ' 0 R';
        }
        if ($value instanceof PdfName) {
            return '/' . $this->encodeName($value->value);
        }
        if ($value instanceof PdfString) {
            return '<' . bin2hex($value->bytes) . '>';
        }
        if ($value instanceof PdfNull) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return $this->encodeFloat($value);
        }
        if (is_array($value)) {
            return '[' . implode(' ', array_map(fn ($v) => $this->encode($v), $value)) . ']';
        }
        return 'null';
    }

    /**
     * @param array<string,mixed> $items
     */
    private function encodeDictionary(array $items): string
    {
        $out = '<<';
        foreach ($items as $key => $value) {
            $out .= '/' . $this->encodeName((string) $key) . ' ' . $this->encode($value);
        }
        return $out . '>>';
    }

    private function encodeStream(PdfStream $stream): string
    {
        $items = $stream->dict->all();
        $items['Length'] = strlen($stream->raw); // authoritative, corrects source /Length
        return $this->encodeDictionary($items) . "\nstream\n" . $stream->raw . "\nendstream";
    }

    private function encodeName(string $name): string
    {
        return preg_replace_callback(
            '/[^\x21-\x7E]|[#\/()<>\[\]{}%]/',
            static fn (array $m): string => '#' . bin2hex($m[0]),
            $name,
        ) ?? $name;
    }

    private function encodeFloat(float $value): string
    {
        if ($value === floor($value) && abs($value) < 1e15) {
            return (string) (int) $value;
        }
        return rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
    }
}
