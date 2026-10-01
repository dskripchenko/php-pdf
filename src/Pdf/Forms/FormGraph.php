<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Merge\ObjectImporter;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;

/**
 * The editable copy of a source document: an {@see ObjectImporter} holding the
 * whole graph reachable from the catalog, plus the read/update helpers every
 * form operation needs. Objects are addressed by their *new* id; `id()` maps a
 * source object number onto it.
 *
 * @internal
 */
final class FormGraph
{
    private const MAX_DEREF = 32;

    public function __construct(
        public readonly ObjectImporter $importer,
        public readonly ReaderDocument $source,
    ) {
    }

    /** New id of a source object (importing it on first use). */
    public function id(int $sourceObjNum): int
    {
        return $this->importer->importObject($sourceObjNum)->number;
    }

    public function get(int $id): mixed
    {
        return $this->importer->get($id);
    }

    /** Follow indirect references within the copied graph. */
    public function resolve(mixed $value): mixed
    {
        for ($i = 0; $value instanceof PdfReference && $i < self::MAX_DEREF; $i++) {
            $value = $this->importer->get($value->number);
        }

        return $value instanceof PdfReference ? null : $value;
    }

    public function dict(mixed $value): ?PdfDictionary
    {
        $value = is_int($value) ? $this->importer->get($value) : $this->resolve($value);
        if ($value instanceof PdfStream) {
            return $value->dict;
        }

        return $value instanceof PdfDictionary ? $value : null;
    }

    /** @return list<mixed>|null */
    public function array(mixed $value): ?array
    {
        $value = $this->resolve($value);

        return is_array($value) ? array_values($value) : null;
    }

    /**
     * Merge entries into the dictionary (or stream dictionary) stored at `$id`;
     * a null value removes the key.
     *
     * @param array<string,mixed> $changes
     */
    public function update(int $id, array $changes): void
    {
        $object = $this->importer->get($id);
        $dict = $object instanceof PdfStream ? $object->dict : $object;
        $items = $dict instanceof PdfDictionary ? $dict->all() : [];
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($items[$key]);
            } else {
                $items[$key] = $value;
            }
        }
        $updated = new PdfDictionary($items);
        $this->importer->set($id, $object instanceof PdfStream ? new PdfStream($updated, $object->raw) : $updated);
    }

    public function set(int $id, mixed $value): void
    {
        $this->importer->set($id, $value);
    }

    public function allocate(mixed $value): PdfReference
    {
        return new PdfReference($this->importer->allocate($value), 0);
    }

    /** A flate-compressed stream object. */
    public function allocateStream(PdfDictionary $dict, string $content): PdfReference
    {
        $items = $dict->all();
        $items['Filter'] = new PdfName('FlateDecode');

        return $this->allocate(new PdfStream(new PdfDictionary($items), (string) gzcompress($content)));
    }

    /**
     * Remove every reference to `$targetId` from the array stored under `$key`
     * of the dictionary at `$ownerId` (the array may itself be indirect).
     * Returns the number of entries left, or null when there is no such array.
     */
    public function removeFromArray(int $ownerId, string $key, int $targetId): ?int
    {
        $owner = $this->dict($ownerId);
        if ($owner === null) {
            return null;
        }
        $raw = $owner->get($key);
        $array = $this->array($raw);
        if ($array === null) {
            return null;
        }
        $filtered = array_values(array_filter(
            $array,
            static fn ($ref) => !($ref instanceof PdfReference && $ref->number === $targetId),
        ));
        if ($raw instanceof PdfReference) {
            $this->importer->set($raw->number, $filtered);
        } else {
            $this->update($ownerId, [$key => $filtered]);
        }

        return count($filtered);
    }

    /** A number as a content-stream operand: integers bare, others trimmed. */
    public static function number(float $value, int $precision = 4): string
    {
        if (abs($value - round($value)) < 1e-6) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(sprintf("%.{$precision}F", $value), '0'), '.');
    }
}
