<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

/**
 * Phase 79: PDF Shading object (ISO 32000-1 §8.7.4).
 *
 * Supports axial (linear, Type 2) и radial (Type 3) shadings.
 * Multi-stop functions через stitching Type 3 — TODO.
 *
 * Used by PdfPattern (shading pattern) для SVG linearGradient / radialGradient.
 */
final readonly class PdfShading
{
    public const TYPE_AXIAL = 2;

    public const TYPE_RADIAL = 3;

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgbStart  RGB 0..255
     * @param  array{0: int, 1: int, 2: int}  $rgbEnd
     * @param  list<float>  $coords  [x0, y0, x1, y1] для axial; [cx0, cy0, r0, cx1, cy1, r1] для radial.
     */
    public function __construct(
        public int $shadingType,
        public array $coords,
        public array $rgbStart,
        public array $rgbEnd,
    ) {
        if ($shadingType !== self::TYPE_AXIAL && $shadingType !== self::TYPE_RADIAL) {
            throw new \InvalidArgumentException('PdfShading supports type 2 (axial) или 3 (radial) only');
        }
    }
}
