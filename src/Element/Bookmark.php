<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Named destination — target для внутренних Hyperlink'ов.
 *
 * Layout engine: после render'а wrapped content создаёт named destination
 * в Catalog.Dests с position = current cursor (X, Y, page).
 *
 * Имена destinations должны быть unique в пределах document'а.
 *
 * Может содержать nested children (wrapped Hyperlink target text) или
 * быть «empty» (просто mark в потоке без visible content).
 */
final readonly class Bookmark implements InlineElement
{
    /**
     * @param  list<InlineElement>  $children
     */
    public function __construct(
        public string $name,
        public array $children = [],
    ) {}
}
