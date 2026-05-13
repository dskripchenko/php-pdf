<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Style\Alignment;

/**
 * Phase 52: SVG element — embeds vector graphics в PDF.
 *
 * Supported SVG primitives:
 *  - <rect x y width height fill stroke stroke-width>
 *  - <line x1 y1 x2 y2 stroke stroke-width>
 *  - <circle cx cy r fill stroke stroke-width>
 *  - <ellipse cx cy rx ry fill stroke stroke-width>
 *  - <polygon points fill stroke stroke-width>
 *  - <polyline points stroke stroke-width>
 *  - <path d="M...L...Z"> (only M/L/Z; no curves, no arcs)
 *  - <text x y font-size>...</text> (basic)
 *
 * Attributes:
 *  - fill / stroke: hex colors (#rgb, #rrggbb), 'none', named (subset).
 *  - stroke-width: numeric.
 *  - opacity / fill-opacity / stroke-opacity: ignored для simplicity.
 *
 * NOT supported:
 *  - Path C/S/Q/T/A (curves and arcs).
 *  - Transforms (translate / rotate / scale / matrix).
 *  - Groups (<g>) — children processed flatly без inheritance.
 *  - <defs>, <use>, gradients, patterns, masks, filters.
 *  - CSS styles (<style>) — только inline attributes.
 *  - viewBox transform (используется raw coords).
 *
 * Source coordinates: SVG Y-axis grows down; output transformed к PDF
 * (Y grows up).
 */
final readonly class SvgElement implements BlockElement
{
    public function __construct(
        public string $svgXml,
        public float $widthPt = 100.0,
        public float $heightPt = 100.0,
        public Alignment $alignment = Alignment::Start,
        public float $spaceBeforePt = 0,
        public float $spaceAfterPt = 0,
    ) {
        if ($svgXml === '') {
            throw new \InvalidArgumentException('SvgElement requires non-empty SVG content');
        }
    }
}
