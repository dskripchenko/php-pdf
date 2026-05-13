<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Forced page break.
 *
 * Implements both BlockElement и InlineElement — может быть как
 * стандалоном на верхнем уровне document'а, так и embedded в paragraph
 * (там после текущей строки начнётся новая страница).
 */
final readonly class PageBreak implements BlockElement, InlineElement
{
}
