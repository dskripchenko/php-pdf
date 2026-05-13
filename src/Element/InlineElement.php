<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Marker interface для inline-level элементов в Document AST.
 *
 * Inline elements живут в Paragraph.children и flow горизонтально
 * (с line-wrap'ом когда переполняют line width).
 *
 * Inline elements:
 *  - Run (text + RunStyle)
 *  - LineBreak (forced new line внутри параграфа)
 *  - PageBreak (forced page-break — также block-level)
 *  - Hyperlink (содержит nested inline children)
 *  - Bookmark (содержит nested inline children — anchor + wrapped content)
 *  - Image (inline-positioned; also block-level)
 *  - Field (PAGE / NUMPAGES / DATE field-codes)
 */
interface InlineElement
{
}
