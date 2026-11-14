<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Reader\Text;

use Dskripchenko\PhpPdf\Pdf\Reader\Lexer;
use Dskripchenko\PhpPdf\Pdf\Reader\TokenType;

/**
 * A font's /ToUnicode CMap (§9.10.3): the code space ranges, which say how
 * many bytes a code takes, and the `bfchar` / `bfrange` mappings of codes to
 * UTF-16BE text. Unparseable parts are skipped; what was read is kept.
 */
final class ToUnicodeMap
{
    /** A range wider than this is not expanded (a malformed or hostile map). */
    private const MAX_RANGE = 0x10000;

    /** @var array<string,string> code bytes → UTF-8 text */
    private array $map = [];

    /** @var list<array{int,string,string}> code space ranges: byte length, low, high */
    private array $spaces = [];

    public static function parse(string $data): self
    {
        $cmap = new self();
        $lexer = new Lexer($data);
        $mode = null;
        $operands = [];
        while (true) {
            $token = $lexer->nextToken();
            if ($token->type === TokenType::Eof) {
                break;
            }
            if ($token->type === TokenType::Keyword) {
                $word = (string) $token->value;
                if (in_array($word, ['begincodespacerange', 'beginbfchar', 'beginbfrange'], true)) {
                    $mode = $word;
                    $operands = [];
                } elseif (in_array($word, ['endcodespacerange', 'endbfchar', 'endbfrange'], true)) {
                    $cmap->apply((string) $mode, $operands);
                    $mode = null;
                    $operands = [];
                }
                continue;
            }
            if ($mode === null) {
                continue;
            }
            if ($token->type === TokenType::Str || $token->type === TokenType::HexStr) {
                $operands[] = (string) $token->value;
            } elseif ($token->type === TokenType::ArrayOpen) {
                $items = [];
                while (($inner = $lexer->nextToken())->type !== TokenType::ArrayClose && $inner->type !== TokenType::Eof) {
                    if ($inner->type === TokenType::Str || $inner->type === TokenType::HexStr) {
                        $items[] = (string) $inner->value;
                    }
                }
                $operands[] = $items;
            }
        }

        return $cmap;
    }

    public function isEmpty(): bool
    {
        return $this->map === [];
    }

    /** The text of a code, or null when the map does not have it. */
    public function lookup(string $code): ?string
    {
        return $this->map[$code] ?? null;
    }

    /**
     * Bytes the next code takes at `$offset`, by the code space ranges; null
     * when the map declares none (the caller decides).
     */
    public function codeLength(string $bytes, int $offset): ?int
    {
        if ($this->spaces === []) {
            return null;
        }
        foreach ($this->spaces as [$length, $low, $high]) {
            $code = substr($bytes, $offset, $length);
            if (strlen($code) === $length && strcmp($code, $low) >= 0 && strcmp($code, $high) <= 0) {
                return $length;
            }
        }

        return null;
    }

    /**
     * @param list<string|list<string>> $operands
     */
    private function apply(string $mode, array $operands): void
    {
        if ($mode === 'begincodespacerange') {
            for ($i = 0; $i + 1 < count($operands); $i += 2) {
                [$low, $high] = [$operands[$i], $operands[$i + 1]];
                if (is_string($low) && is_string($high) && strlen($low) === strlen($high) && $low !== '') {
                    $this->spaces[] = [strlen($low), $low, $high];
                }
            }
            usort($this->spaces, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        } elseif ($mode === 'beginbfchar') {
            for ($i = 0; $i + 1 < count($operands); $i += 2) {
                if (is_string($operands[$i]) && is_string($operands[$i + 1])) {
                    $this->map[$operands[$i]] = self::utf16($operands[$i + 1]);
                }
            }
        } elseif ($mode === 'beginbfrange') {
            for ($i = 0; $i + 2 < count($operands); $i += 3) {
                $this->range($operands[$i], $operands[$i + 1], $operands[$i + 2]);
            }
        }
    }

    /**
     * @param string|list<string> $low
     * @param string|list<string> $high
     * @param string|list<string> $target
     */
    private function range(string|array $low, string|array $high, string|array $target): void
    {
        if (!is_string($low) || !is_string($high) || strlen($low) !== strlen($high) || $low === '') {
            return;
        }
        $from = self::number($low);
        $to = self::number($high);
        if ($to < $from || $to - $from >= self::MAX_RANGE) {
            return;
        }
        $width = strlen($low);
        for ($n = $from; $n <= $to; $n++) {
            $code = substr(pack('J', $n), -$width);
            if (is_array($target)) {
                $item = $target[$n - $from] ?? null;
                if (is_string($item)) {
                    $this->map[$code] = self::utf16($item);
                }
                continue;
            }
            // The last UTF-16 unit of the target grows with the code.
            $units = array_values(unpack('n*', strlen($target) % 2 === 0 ? $target : "\0".$target) ?: []);
            if ($units === []) {
                return;
            }
            $units[count($units) - 1] += $n - $from;
            $this->map[$code] = self::utf16(pack('n*', ...array_map(static fn (int $u): int => $u & 0xFFFF, $units)));
        }
    }

    private static function number(string $bytes): int
    {
        $n = 0;
        foreach (str_split($bytes) as $byte) {
            $n = ($n << 8) | ord($byte);
        }

        return $n;
    }

    private static function utf16(string $bytes): string
    {
        if (strlen($bytes) % 2 === 1) {
            $bytes = "\0".$bytes;
        }
        $text = mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');

        return is_string($text) ? $text : '';
    }
}
