<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Hyperlink — external URL или internal anchor.
 *
 * Если `$anchor !== null` — internal (jump to bookmark).
 * Если `$href !== null` — external URL.
 * Оба null — invalid; constructor можно опционально проверить.
 *
 * `$children` — inline content (обычно Run с подчёркнутым/синим стилем).
 *
 * Layout engine: после render'а children'а добавляет /Link annotation
 * на page с Rect = bounding box rendered text'а.
 */
final readonly class Hyperlink implements InlineElement
{
    /**
     * @param  list<InlineElement>  $children
     */
    public function __construct(
        public array $children,
        public ?string $href = null,
        public ?string $anchor = null,
    ) {}

    public function isInternal(): bool
    {
        return $this->anchor !== null;
    }

    /**
     * @param  list<InlineElement>  $children
     */
    public static function external(string $href, array $children): self
    {
        return new self($children, href: $href);
    }

    /**
     * @param  list<InlineElement>  $children
     */
    public static function internal(string $anchor, array $children): self
    {
        return new self($children, anchor: $anchor);
    }
}
