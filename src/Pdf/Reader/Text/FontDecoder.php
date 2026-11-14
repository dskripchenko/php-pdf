<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Reader\Text;

use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Throwable;

/**
 * What the bytes of a shown string mean in one font (§9.4.3, §9.10): codes
 * split by the font's code length, each code's text — from /ToUnicode, else
 * from the simple font's encoding and /Differences — and its advance width
 * in thousandths of the font size.
 */
final class FontDecoder
{
    private ?ToUnicodeMap $toUnicode = null;

    /** @var array<int,string> one-byte code → text, simple fonts */
    private array $encoding = [];

    /** @var array<int,float> code → width */
    private array $widths = [];

    private float $defaultWidth = 500.0;

    /** Thousandths per glyph space unit: 1 except for Type 3 fonts. */
    private float $scale = 1.0;

    private bool $composite = false;

    public static function fromDictionary(ReaderDocument $doc, PdfDictionary $font): self
    {
        $decoder = new self();
        $subtype = self::name($doc->deref($font->get('Subtype')));
        $toUnicode = $doc->deref($font->get('ToUnicode'));
        if ($toUnicode instanceof PdfStream) {
            try {
                $map = ToUnicodeMap::parse($doc->streamData($toUnicode));
                $decoder->toUnicode = $map->isEmpty() ? null : $map;
            } catch (Throwable) {
                $decoder->toUnicode = null;
            }
        }
        if ($subtype === 'Type0') {
            $decoder->composite = true;
            $decoder->defaultWidth = 1000.0;
            $descendants = $doc->deref($font->get('DescendantFonts'));
            $cid = is_array($descendants) ? $doc->deref($descendants[0] ?? null) : null;
            if ($cid instanceof PdfDictionary) {
                $decoder->cidWidths($doc, $cid);
            }

            return $decoder;
        }
        $decoder->simpleEncoding($doc, $font, $subtype);
        $decoder->simpleWidths($doc, $font);
        if ($subtype === 'Type3') {
            $matrix = $doc->deref($font->get('FontMatrix'));
            $a = is_array($matrix) ? $doc->deref($matrix[0] ?? null) : null;
            $decoder->scale = is_int($a) || is_float($a) ? (float) $a * 1000.0 : 1.0;
        }

        return $decoder;
    }

    /**
     * The codes of a string: each code's text (empty when unknown), width and
     * whether it is the single-byte space that word spacing applies to.
     *
     * @return list<array{string,float,bool}>
     */
    public function decode(string $bytes): array
    {
        $out = [];
        $length = strlen($bytes);
        $i = 0;
        while ($i < $length) {
            $n = $this->toUnicode?->codeLength($bytes, $i) ?? ($this->composite ? 2 : 1);
            $code = substr($bytes, $i, $n);
            $i += $n;
            $number = 0;
            foreach (str_split($code) as $byte) {
                $number = ($number << 8) | ord($byte);
            }
            $text = $this->toUnicode?->lookup($code);
            if ($text === null && !$this->composite) {
                $text = $this->encoding[$number] ?? '';
            }
            $out[] = [$text ?? '', ($this->widths[$number] ?? $this->defaultWidth) * $this->scale, $n === 1 && $number === 32];
        }

        return $out;
    }

    private function simpleEncoding(ReaderDocument $doc, PdfDictionary $font, ?string $subtype): void
    {
        $encoding = $doc->deref($font->get('Encoding'));
        $base = $subtype === 'TrueType' ? 'WinAnsiEncoding' : 'StandardEncoding';
        $differences = null;
        if ($encoding instanceof PdfName) {
            $base = $encoding->value;
        } elseif ($encoding instanceof PdfDictionary) {
            $named = self::name($doc->deref($encoding->get('BaseEncoding')));
            $base = $named ?? $base;
            $differences = $doc->deref($encoding->get('Differences'));
        }
        $this->encoding = SimpleEncodings::map($base);
        if (is_array($differences)) {
            $code = 0;
            foreach ($differences as $item) {
                $item = $doc->deref($item);
                if (is_int($item)) {
                    $code = $item;
                } elseif ($item instanceof PdfName) {
                    $text = GlyphNames::toUnicode($item->value);
                    if ($text !== null) {
                        $this->encoding[$code] = $text;
                    } else {
                        unset($this->encoding[$code]);
                    }
                    $code++;
                }
            }
        }
    }

    private function simpleWidths(ReaderDocument $doc, PdfDictionary $font): void
    {
        $first = $doc->deref($font->get('FirstChar'));
        $widths = $doc->deref($font->get('Widths'));
        if (!is_int($first) || !is_array($widths)) {
            return;
        }
        foreach (array_values($widths) as $i => $w) {
            $w = $doc->deref($w);
            if (is_int($w) || is_float($w)) {
                $this->widths[$first + $i] = (float) $w;
            }
        }
    }

    /** /W: `c [w1 w2 …]` and `cFirst cLast w` entries; /DW the default. */
    private function cidWidths(ReaderDocument $doc, PdfDictionary $cid): void
    {
        $dw = $doc->deref($cid->get('DW'));
        if (is_int($dw) || is_float($dw)) {
            $this->defaultWidth = (float) $dw;
        }
        $w = $doc->deref($cid->get('W'));
        if (!is_array($w)) {
            return;
        }
        $items = array_values(array_map(fn (mixed $v): mixed => $doc->deref($v), $w));
        $count = count($items);
        for ($i = 0; $i < $count;) {
            $start = $items[$i];
            $next = $items[$i + 1] ?? null;
            if (is_int($start) && is_array($next)) {
                foreach (array_values($next) as $k => $width) {
                    $width = $doc->deref($width);
                    if (is_int($width) || is_float($width)) {
                        $this->widths[$start + $k] = (float) $width;
                    }
                }
                $i += 2;
            } elseif (is_int($start) && is_int($next) && isset($items[$i + 2]) && (is_int($items[$i + 2]) || is_float($items[$i + 2]))) {
                if ($next - $start < 0x10000) {
                    for ($c = $start; $c <= $next; $c++) {
                        $this->widths[$c] = (float) $items[$i + 2];
                    }
                }
                $i += 3;
            } else {
                $i++;
            }
        }
    }

    private static function name(mixed $value): ?string
    {
        return $value instanceof PdfName ? $value->value : null;
    }
}
