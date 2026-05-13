<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Style\ParagraphStyle;

/**
 * Phase 61: Heading element с semantic level (1-6).
 *
 * Rendered как paragraph с bold + larger font (auto-styled by level).
 * В tagged PDF mode emits как /H1.../H6 struct element instead of /P
 * — accessibility readers can navigate heading hierarchy.
 *
 * Auto-styled font sizes (default ParagraphStyle):
 *   H1=24pt, H2=20pt, H3=16pt, H4=14pt, H5=12pt, H6=11pt
 *
 * Caller может override через `style` parameter если нужны кастомные
 * margins или indent.
 */
final readonly class Heading implements BlockElement
{
    /**
     * @param  list<InlineElement>  $children
     */
    public function __construct(
        public int $level,
        public array $children,
        public ?ParagraphStyle $style = null,
    ) {
        if ($level < 1 || $level > 6) {
            throw new \InvalidArgumentException('Heading level must be 1..6');
        }
    }

    /**
     * Default font size by level.
     */
    public function defaultFontSizePt(): float
    {
        return match ($this->level) {
            1 => 24.0,
            2 => 20.0,
            3 => 16.0,
            4 => 14.0,
            5 => 12.0,
            6 => 11.0,
            default => 11.0,
        };
    }
}
