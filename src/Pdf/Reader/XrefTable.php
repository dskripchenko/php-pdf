<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Reader;

/**
 * Result of reading the cross-reference chain: a map from object number to its
 * byte offset in the file, the matching generations, and the newest trailer.
 */
final readonly class XrefTable
{
    /**
     * @param array<int,int> $offsets object number → byte offset
     * @param array<int,int> $generations object number → generation
     */
    public function __construct(
        public array $offsets,
        public array $generations,
        public PdfDictionary $trailer,
    ) {
    }

    public function hasObject(int $number): bool
    {
        return array_key_exists($number, $this->offsets);
    }

    public function offsetOf(int $number): ?int
    {
        return $this->offsets[$number] ?? null;
    }
}
