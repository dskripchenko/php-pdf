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

    /** @var array<int,array{data:string, offsets:array<int,int>, first:int}> objstm number → parsed header */
    private array $objStmCache = [];

    private function __construct(
        private readonly string $data,
        private readonly XrefTable $xref,
    ) {
    }

    public static function fromBytes(string $data): self
    {
        try {
            $doc = new self($data, (new XrefReader($data))->read());
            if ($doc->isNavigable()) {
                return $doc;
            }
        } catch (PdfParseException) {
            // Fall through to recovery.
        }

        return new self($data, (new XrefRecovery($data))->rebuild());
    }

    /**
     * Build a document over an already-computed cross-reference table
     * (used by the recovery scanner to bootstrap object-stream indexing).
     */
    public static function fromXref(string $data, XrefTable $xref): self
    {
        return new self($data, $xref);
    }

    /**
     * True when the catalog and page-tree root resolve to dictionaries — the
     * cheap probe that decides whether a parsed xref is trustworthy or the
     * recovery scanner should take over.
     */
    private function isNavigable(): bool
    {
        try {
            $catalog = $this->deref($this->trailer()->get('Root'));
            if (!$catalog instanceof PdfDictionary) {
                return false;
            }
            return $this->deref($catalog->get('Pages')) instanceof PdfDictionary;
        } catch (PdfParseException) {
            return false;
        }
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
        if ($offset !== null) {
            $parsed = (new ObjectParser(new Lexer($this->data, $offset)))->parseIndirectObject();
            return $this->cache[$number] = $parsed['value'];
        }

        $loc = $this->xref->compressedOf($number);
        if ($loc !== null) {
            return $this->cache[$number] = $this->getCompressedObject($number, $loc[0], $loc[1]);
        }

        return PdfNull::instance();
    }

    /**
     * Resolve an object stored inside an object stream (§7.5.7).
     *
     * @param int $streamNumber the containing /ObjStm object
     * @param int $index        the object's position within that stream
     */
    private function getCompressedObject(int $number, int $streamNumber, int $index): mixed
    {
        $header = $this->objStmCache[$streamNumber] ?? null;
        if ($header === null) {
            $stream = $this->getObject($streamNumber);
            if (!$stream instanceof PdfStream) {
                return PdfNull::instance();
            }
            $data = $this->streamData($stream);
            $n = $this->deref($stream->dict->get('N'));
            $first = $this->deref($stream->dict->get('First'));
            if (!is_int($n) || !is_int($first)) {
                return PdfNull::instance();
            }

            // Header: N pairs of (object-number, relative-offset).
            $lexer = new Lexer($data, 0);
            $offsets = [];
            for ($i = 0; $i < $n; $i++) {
                $numTok = $lexer->nextToken();
                $offTok = $lexer->nextToken();
                if ($numTok->type !== TokenType::Number || $offTok->type !== TokenType::Number) {
                    break;
                }
                $offsets[$i] = (int) $offTok->value;
            }

            $header = ['data' => $data, 'offsets' => $offsets, 'first' => $first];
            $this->objStmCache[$streamNumber] = $header;
        }

        if (!array_key_exists($index, $header['offsets'])) {
            return PdfNull::instance();
        }

        $start = $header['first'] + $header['offsets'][$index];
        return (new ObjectParser(new Lexer($header['data'], $start)))->parseValue();
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
