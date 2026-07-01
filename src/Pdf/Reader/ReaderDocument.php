<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Reader;

/**
 * A parsed, navigable view over an existing PDF file.
 *
 * Objects are resolved lazily from their cross-reference offsets and cached.
 * Reference chains are dereferenced with a cycle guard. This phase (P2) reads
 * classic-xref files; xref/object streams (P4) and decryption (P7) plug in
 * later without changing this surface.
 */
final class ReaderDocument
{
    /** @var array<int,mixed> objnum → resolved value */
    private array $cache = [];

    private function __construct(
        private readonly string $data,
        private readonly XrefTable $xref,
    ) {
    }

    public static function fromBytes(string $data): self
    {
        return new self($data, (new XrefReader($data))->read());
    }

    public function trailer(): PdfDictionary
    {
        return $this->xref->trailer;
    }

    /**
     * Resolve an indirect object by number, or return {@see PdfNull} when the
     * object is absent from the cross-reference table.
     */
    public function getObject(int $number): mixed
    {
        if (array_key_exists($number, $this->cache)) {
            return $this->cache[$number];
        }

        $offset = $this->xref->offsetOf($number);
        if ($offset === null) {
            return PdfNull::instance();
        }

        $parser = new ObjectParser(new Lexer($this->data, $offset));
        $parsed = $parser->parseIndirectObject();

        return $this->cache[$number] = $parsed['value'];
    }

    /**
     * Follow a chain of indirect references to the concrete value it points at.
     */
    public function deref(mixed $value): mixed
    {
        $guard = [];
        while ($value instanceof PdfReference) {
            $key = $value->number;
            if (isset($guard[$key])) {
                return PdfNull::instance(); // reference cycle
            }
            $guard[$key] = true;
            $value = $this->getObject($value->number);
        }
        return $value;
    }

    /**
     * Fully decode a stream's `/Filter` chain into its plain bytes.
     */
    public function streamData(PdfStream $stream): string
    {
        return (new StreamDecoder($this))->decode($stream);
    }

    /**
     * The document catalog (`/Root`).
     */
    public function catalog(): PdfDictionary
    {
        $root = $this->deref($this->trailer()->get('Root'));
        if (!$root instanceof PdfDictionary) {
            throw new PdfParseException('Document catalog (/Root) is missing or invalid');
        }
        return $root;
    }

    /**
     * Number of pages in the document.
     *
     * Reads `/Count` from the page-tree root. Full leaf-walking (for producers
     * that omit or misreport `/Count`) lands with the page-tree flattener (P6).
     */
    public function pageCount(): int
    {
        $pages = $this->deref($this->catalog()->get('Pages'));
        if (!$pages instanceof PdfDictionary) {
            throw new PdfParseException('Page tree root (/Pages) is missing or invalid');
        }
        $count = $this->deref($pages->get('Count'));
        if (!is_int($count)) {
            throw new PdfParseException('Page tree /Count is missing or invalid');
        }
        return $count;
    }
}
