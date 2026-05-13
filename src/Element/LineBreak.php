<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Forced line break внутри параграфа (HTML `<br>`).
 *
 * Не путать с soft-wrap (line-breaking algorithm). LineBreak — explicit
 * line ending, paragraph остаётся одним.
 */
final readonly class LineBreak implements InlineElement
{
}
