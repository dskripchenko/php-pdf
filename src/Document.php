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
    /**
     * @param  array<string, string>  $metadata  PDF /Info dict fields
     *                                            (Title, Author, Subject,
     *                                            Keywords, Creator, Producer).
     * @param  list<Section>  $additionalSections  Phase 34: extra sections,
     *                                              rendered after primary
     *                                              section. Каждая создаёт
     *                                              forced page break + переход
     *                                              на её PageSetup / header /
     *                                              footer / watermark.
     */
    public function __construct(
        public Section $section,
        public array $metadata = [],
        public array $additionalSections = [],
        /** Phase 48: enable Tagged PDF (accessibility) при emission. */
        public bool $tagged = false,
        /**
         * Phase 89: Document language hint (BCP 47 language tag — e.g. 'en',
         * 'ru', 'en-US'). Emitted as /Lang в Catalog. PDF/UA requires
         * this entry для tagged documents — screen readers used для
         * default speech locale.
         */
        public ?string $lang = null,
        /**
         * Phase 208: emit xref как PDF 1.5 XRef stream object instead of
         * classic `xref...trailer` table. Saves ~50% metadata bytes для
         * large object counts. Не compatible с PKCS#7 signing — fallback
         * к classic xref when signature configured.
         */
        public bool $useXrefStream = false,
    ) {}

    /**
     * @return list<Section>
     */
    public function sections(): array
    {
        return [$this->section, ...$this->additionalSections];
    }

    public function toBytes(?Engine $engine = null): string
    {
        $engine ??= new Engine;
        $pdf = $engine->render($this);
        if ($this->metadata !== []) {
            $pdf->metadata(
                title: $this->metadata['Title'] ?? null,
                author: $this->metadata['Author'] ?? null,
                subject: $this->metadata['Subject'] ?? null,
                keywords: $this->metadata['Keywords'] ?? null,
                creator: $this->metadata['Creator'] ?? null,
                producer: $this->metadata['Producer'] ?? null,
            );
        }
        if ($this->useXrefStream) {
            $pdf->useXrefStream();
        }

        return $pdf->toBytes();
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
