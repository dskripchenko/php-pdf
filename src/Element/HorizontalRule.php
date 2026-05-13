<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Horizontal rule — full-width line separator (HTML `<hr>`).
 *
 * Layout engine рендерит как 0.5pt серую линию на всю content-width,
 * с small spacing выше/ниже.
 */
final readonly class HorizontalRule implements BlockElement
{
}
