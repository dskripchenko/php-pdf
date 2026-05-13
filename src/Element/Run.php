<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Style\RunStyle;

/**
 * Run — непрерывный кусок текста с одним RunStyle.
 *
 * В AST text всегда живёт внутри Run'а (а Run внутри Paragraph). Это
 * позволяет менять стиль mid-paragraph: `[Run('foo', plain), Run('bar', bold)]`.
 *
 * Тext хранится как UTF-8. Перекодировка в glyph-IDs происходит в Layout
 * engine через PdfFont.
 */
final readonly class Run implements InlineElement
{
    public function __construct(
        public string $text,
        public RunStyle $style = new RunStyle,
    ) {}
}
