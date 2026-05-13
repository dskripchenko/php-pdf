<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Marker interface для block-level элементов в Document AST.
 *
 * Block elements:
 *  - Paragraph (включая heading)
 *  - PageBreak (also implements InlineElement)
 *  - HorizontalRule
 *  - Table (Phase 5)
 *  - ListNode (Phase 6)
 *  - Image (block-positioned; also implements InlineElement)
 *
 * Block elements живут в Section.body / TableCell.children / список
 * block-level контента документа. Они flow один за другим vertically.
 */
interface BlockElement
{
}
