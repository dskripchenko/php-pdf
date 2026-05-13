<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Style;

/**
 * Вертикальное выравнивание контента внутри cell'а.
 *
 * Mirror'ит php-docx VerticalAlign. Применяется при cell rendering'е:
 * влияет на Y-coord начала контента относительно cell-bounding-box'а.
 */
enum VerticalAlignment: string
{
    case Top = 'top';
    case Center = 'center';
    case Bottom = 'bottom';
}
