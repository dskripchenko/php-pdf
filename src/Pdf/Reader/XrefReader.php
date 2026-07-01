<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Reader;

/**
 * Reads classic cross-reference tables (ISO 32000-1 §7.5.4) and their
 * trailers, following the `/Prev` chain across incremental updates.
 *
 * Cross-reference *streams* (§7.5.8) and hybrid `/XRefStm` files are handled
 * in Phase P4; this phase covers the classic `xref ... trailer` form only.
 */
final class XrefReader
{
    public function __construct(private readonly string $data)
    {
    }

    /**
     * Locate and parse the full cross-reference chain.
     *
     * @return XrefTable object-number → byte-offset map plus the newest trailer
     */
    public function read(): XrefTable
    {
        $offset = $this->findStartXref();

        /** @var array<int,int> $offsets objnum → byte offset (newest wins) */
        $offsets = [];
        /** @var array<int,int> $gens objnum → generation */
        $gens = [];
        $trailer = null;

        $seen = [];
        while ($offset !== null) {
            if (isset($seen[$offset])) {
                break; // /Prev cycle guard
            }
            $seen[$offset] = true;

            [$sectionOffsets, $sectionGens, $sectionTrailer] = $this->readSection($offset);

            foreach ($sectionOffsets as $num => $off) {
                if (!array_key_exists($num, $offsets)) {
                    $offsets[$num] = $off;
                    $gens[$num] = $sectionGens[$num];
                }
            }

            // The first (newest) trailer wins for /Root, /Encrypt, /ID.
            $trailer ??= $sectionTrailer;

            $prev = $sectionTrailer->get('Prev');
            $offset = is_int($prev) ? $prev : null;
        }

        if ($trailer === null) {
            throw new PdfParseException('No trailer found');
        }

        return new XrefTable($offsets, $gens, $trailer);
    }

    private function findStartXref(): int
    {
        $pos = strrpos($this->data, 'startxref');
        if ($pos === false) {
            throw new PdfParseException('No startxref marker found');
        }
        $lexer = new Lexer($this->data, $pos + strlen('startxref'));
        $tok = $lexer->nextToken();
        if ($tok->type !== TokenType::Number || !is_int($tok->value)) {
            throw new PdfParseException('Malformed startxref offset');
        }
        return $tok->value;
    }

    /**
     * @return array{0: array<int,int>, 1: array<int,int>, 2: PdfDictionary}
     */
    private function readSection(int $offset): array
    {
        $lexer = new Lexer($this->data, $offset);

        $head = $lexer->nextToken();
        if (!$head->isKeyword('xref')) {
            throw new PdfParseException(
                "Expected 'xref' at offset {$offset}, got '{$head->value}'"
            );
        }

        $offsets = [];
        $gens = [];

        while (true) {
            $save = $lexer->position();
            $tok = $lexer->nextToken();
            if ($tok->isKeyword('trailer')) {
                break;
            }
            if ($tok->type !== TokenType::Number || !is_int($tok->value)) {
                throw new PdfParseException('Malformed xref subsection header');
            }
            $start = $tok->value;
            $countTok = $lexer->nextToken();
            if ($countTok->type !== TokenType::Number || !is_int($countTok->value)) {
                throw new PdfParseException('Malformed xref subsection count');
            }
            $count = $countTok->value;

            for ($i = 0; $i < $count; $i++) {
                $offTok = $lexer->nextToken();
                $genTok = $lexer->nextToken();
                $typeTok = $lexer->nextToken();
                if ($offTok->type !== TokenType::Number
                    || $genTok->type !== TokenType::Number
                    || $typeTok->type !== TokenType::Keyword
                ) {
                    throw new PdfParseException('Malformed xref entry');
                }
                $num = $start + $i;
                if ($typeTok->value === 'n') {
                    $offsets[$num] = (int) $offTok->value;
                    $gens[$num] = (int) $genTok->value;
                }
            }
            unset($save);
        }

        $trailer = (new ObjectParser($lexer))->parseValue();
        if (!$trailer instanceof PdfDictionary) {
            throw new PdfParseException('Trailer is not a dictionary');
        }

        return [$offsets, $gens, $trailer];
    }
}
