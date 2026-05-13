<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\RunStyle;

/**
 * Paragraph — block-level контейнер inline-content.
 *
 * `$headingLevel = 1..6` означает что параграф — heading того уровня
 * (Layout engine применит heading style — bigger size + bold).
 * `null` — обычный параграф.
 *
 * `$defaultRunStyle` — inherited-style для всех children Run'ов которые
 * не имеют explicit fontFamily/size. Это эквивалент CSS-inheritance.
 * Children Run.style.inheritFrom($defaultRunStyle) даёт effective style.
 */
final readonly class Paragraph implements BlockElement
{
    /**
     * @param  list<InlineElement>  $children
     */
    public function __construct(
        public array $children = [],
        public ParagraphStyle $style = new ParagraphStyle,
        public ?int $headingLevel = null,
        public RunStyle $defaultRunStyle = new RunStyle,
    ) {}

    public function isEmpty(): bool
    {
        return $this->children === [];
    }
}
