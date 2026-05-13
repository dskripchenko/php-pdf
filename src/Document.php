<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf;

use Dskripchenko\PhpPdf\Layout\Engine;

/**
 * Document — AST root для high-level API.
 *
 * Immutable VO. Содержит одну Section с body content и page setup.
 * Multi-section с разными paper sizes не поддерживается в v0.1.
 *
 * Сериализация в PDF bytes происходит через Layout\Engine, который
 * выполняет walk over AST + использует Phase 2 font infrastructure
 * для рендеринга text/images/lines на абсолютные координаты в Pdf\Document.
 *
 * Convenience: `toBytes()` использует default Engine (Helvetica fallback).
 * Для рендера с embedded TTF font'ом и custom settings'ами — передавать
 * Engine явно.
 */
final readonly class Document
{
    public function __construct(
        public Section $section,
    ) {}

    public function toBytes(?Engine $engine = null): string
    {
        $engine ??= new Engine;

        return $engine->render($this)->toBytes();
    }

    public function toFile(string $path, ?Engine $engine = null): int
    {
        $bytes = $this->toBytes($engine);
        $written = file_put_contents($path, $bytes);
        if ($written === false) {
            throw new \RuntimeException('Failed to write PDF to '.$path);
        }

        return $written;
    }
}
