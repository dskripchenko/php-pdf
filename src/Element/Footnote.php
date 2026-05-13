<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Phase 40: Footnote / endnote inline element.
 *
 * Inline marker (auto-numbered superscript) in text flow; footnote
 * content collected per-section и rendered как endnotes block после
 * body. True page-bottom footnotes (с per-page reserved zone) — deferred.
 *
 * Auto-numbering: footnotes в section numbered 1..N в порядке встречи.
 */
final readonly class Footnote implements InlineElement
{
    public function __construct(public string $content) {}
}
