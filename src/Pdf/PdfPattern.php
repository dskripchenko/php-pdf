<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

/**
 * Phase 82: PDF Pattern object (ISO 32000-1 §8.7).
 *
 * Type 2 (shading pattern) — references PdfShading. Used для gradient
 * fills via SVG linearGradient / radialGradient.
 *
 * Type 1 (tiling pattern) — для repeated tiles. TODO.
 */
final readonly class PdfPattern
{
    public const TYPE_SHADING = 2;

    public function __construct(
        public PdfShading $shading,
    ) {}

    /**
     * Body of /Type /Pattern /PatternType 2 dict.
     * Caller emits Shading separately; passes its object ID.
     */
    public function toDictBody(int $shadingObjId): string
    {
        return sprintf(
            '<< /Type /Pattern /PatternType 2 /Shading %d 0 R >>',
            $shadingObjId,
        );
    }
}
