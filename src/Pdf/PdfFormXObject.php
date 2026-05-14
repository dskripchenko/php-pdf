<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

/**
 * Phase 107: PDF Form XObject (ISO 32000-1 §8.10).
 *
 * Reusable content stream referenced via `/Name Do` from any number of
 * pages. Useful для repeated logos, watermarks, headers, footers — body
 * stored once, drawn N times.
 *
 * Layout in output PDF:
 *   N 0 obj
 *     << /Type /XObject /Subtype /Form /BBox [llx lly urx ury]
 *        /Resources <<...>> /Length L /Filter /FlateDecode >>
 *     stream
 *       <content commands>
 *     endstream
 *   endobj
 *
 * Caller positions via Page::useFormXObject(x, y, w, h) — emits cm
 * scaling/translation around `Do` так что bbox aligns с requested rect.
 *
 * Not supported (scope kept small):
 *  - Form-XObject-local fonts / images (use rectangles, lines, colors).
 *  - Tiling patterns derived from Form XObject.
 *  - PDF/A группа transparency overrides.
 */
final readonly class PdfFormXObject
{
    public function __construct(
        public string $contentStream,
        public float $bboxLlx,
        public float $bboxLly,
        public float $bboxUrx,
        public float $bboxUry,
        /** Optional /Matrix [a b c d e f] — default identity. */
        public ?array $matrix = null,
    ) {
        if ($bboxUrx <= $bboxLlx || $bboxUry <= $bboxLly) {
            throw new \InvalidArgumentException('Form XObject /BBox must have positive width/height');
        }
        if ($matrix !== null && count($matrix) !== 6) {
            throw new \InvalidArgumentException('Form XObject /Matrix must have 6 entries');
        }
    }

    public function bboxWidth(): float
    {
        return $this->bboxUrx - $this->bboxLlx;
    }

    public function bboxHeight(): float
    {
        return $this->bboxUry - $this->bboxLly;
    }

    /**
     * Build the dictionary head fragment (без /Length и stream body).
     *
     * Returns тело между `<< ... >>` для использования внутри Writer.
     */
    public function dictHead(): string
    {
        $bbox = sprintf(
            '[%s %s %s %s]',
            self::fmt($this->bboxLlx), self::fmt($this->bboxLly),
            self::fmt($this->bboxUrx), self::fmt($this->bboxUry),
        );
        $matrixPart = '';
        if ($this->matrix !== null) {
            $matrixPart = ' /Matrix ['
                . implode(' ', array_map([self::class, 'fmt'], $this->matrix))
                . ']';
        }

        return '/Type /XObject /Subtype /Form /BBox ' . $bbox . $matrixPart;
    }

    private static function fmt(float $v): string
    {
        if ($v == floor($v) && abs($v) < 1e9) {
            return (string) (int) $v;
        }

        return rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
    }
}
