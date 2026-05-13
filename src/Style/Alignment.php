<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Style;

/**
 * Horizontal alignment параграфа / table cell.
 *
 * Naming follows OOXML w:jc values для compatibility с php-docx AST
 * (`start`/`center`/`end`/`both`/`distribute`). HTML mapping:
 *  - Start = left (for LTR scripts)
 *  - End = right (for LTR scripts)
 *  - Both = justify
 *  - Distribute = justify (включая last line)
 */
enum Alignment: string
{
    case Start = 'start';
    case Center = 'center';
    case End = 'end';
    case Both = 'both';           // justify
    case Distribute = 'distribute';
}
