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
        /**
         * Phase 210: target PDF version (header `%PDF-X.Y`). Defaults к null
         * — Engine uses its default (`'1.7'`), и subsystems auto-bump if
         * required (AES-128 → 1.6, AES-256 → 1.7, PDF 2.0 features → 2.0,
         * XRef stream → 1.5). Set explicitly если требуется compatibility
         * older readers (e.g., '1.4' для legacy printer firmware).
         */
        public ?string $pdfVersion = null,
        /**
         * Phase 214: emit Object Streams (PDF 1.5+) — pack uncompressed dict
         * objects в single FlateDecode-compressed stream. Saves additional
         * ~15-30% output size beyond XRef streams. Auto-enables `useXrefStream`.
         * Не compatible с PKCS#7 signing / encryption.
         */
        public bool $useObjectStreams = false,
        /**
         * Phase 217: declarative encryption setup. Null = no encryption.
         * Otherwise applies password, permissions, algorithm via
         * Pdf\Document::encrypt() during emission.
         */
        public ?EncryptionParams $encryption = null,
        /**
         * Phase 217: declarative PKCS#7 detached signing. Requires AcroForm
         * с signature placeholder field. Null = no signing.
         */
        public ?\Dskripchenko\PhpPdf\Pdf\SignatureConfig $signature = null,
        /**
         * Phase 217: declarative PDF/A conformance. Null = no PDF/A
         * enforcement. Otherwise applies enablePdfA() during emission
         * (auto-enables Tagged PDF при conformance='A').
         */
        public ?\Dskripchenko\PhpPdf\Pdf\PdfAConfig $pdfA = null,
    ) {}

    /**
     * @return list<Section>
     */
    public function sections(): array
    {
        return [$this->section, ...$this->additionalSections];
    }

    /**
     * Phase 223: Merge multiple Documents в single output (batch use case).
     *
     * Sections concatenated в order (с natural section breaks → new page
     * per section's PageSetup). Metadata inherited от first non-empty
     * source; Document-level flags (tagged, useXrefStream, encryption etc.)
     * также от first document.
     *
     * Use case: generate per-invoice/per-record Documents в loop, then
     * concat для single combined PDF.
     *
     * Example:
     *   $combined = Document::concat([\$doc1, \$doc2, \$doc3]);
     *   \$combined->toFile('combined.pdf');
     */
    public static function concat(array $documents): self
    {
        if ($documents === []) {
            throw new \InvalidArgumentException('Document::concat requires at least one document');
        }
        $first = $documents[0];
        $sections = [];
        foreach ($documents as $doc) {
            if (! $doc instanceof self) {
                throw new \InvalidArgumentException('All elements must be Document instances');
            }
            foreach ($doc->sections() as $s) {
                $sections[] = $s;
            }
        }
        $primarySection = $sections[0];
        $additional = array_slice($sections, 1);

        return new self(
            section: $primarySection,
            metadata: $first->metadata,
            additionalSections: $additional,
            tagged: $first->tagged,
            lang: $first->lang,
            useXrefStream: $first->useXrefStream,
            pdfVersion: $first->pdfVersion,
            useObjectStreams: $first->useObjectStreams,
            encryption: $first->encryption,
            signature: $first->signature,
            pdfA: $first->pdfA,
        );
    }

    /**
     * Phase 219: Convenience factory — parse HTML и wrap в Document.
     *
     * Supports HTML5 subset:
     *  - Block tags: p, div, section, article, h1-h6, hr, ul/ol/li,
     *    table/tr/td/th (с thead/tbody/tfoot wrappers), blockquote, pre
     *  - Inline: span, b/strong, i/em, u, s/strike/del, sup/sub, br, img, a
     *  - Inline CSS via style="" attribute (color, font-family, font-size,
     *    font-weight, font-style, text-decoration, letter-spacing,
     *    background-color)
     *
     * NOT supported: external CSS, <style>, complex selectors, JS, forms.
     * Preprocess HTML через external CSS inliner (tijsverkoyen/css-to-
     * inline-styles) если нужна <style>-blocks или class-based styling.
     *
     * @param  string  $html  HTML markup (no <html>/<body> wrapper required)
     * @param  array<string, string>  $metadata  Optional /Info fields
     */
    public static function fromHtml(
        string $html,
        array $metadata = [],
        ?Section $sectionTemplate = null,
        bool $tagged = false,
        ?string $lang = null,
        bool $useXrefStream = false,
        ?string $pdfVersion = null,
        bool $useObjectStreams = false,
        ?EncryptionParams $encryption = null,
        ?\Dskripchenko\PhpPdf\Pdf\SignatureConfig $signature = null,
        ?\Dskripchenko\PhpPdf\Pdf\PdfAConfig $pdfA = null,
    ): self {
        $parser = new \Dskripchenko\PhpPdf\Html\HtmlParser;
        $blocks = $parser->parse($html);

        if ($sectionTemplate !== null) {
            // Preserve template's page setup / headers / footers / watermark,
            // override body с parsed blocks.
            $section = new Section(
                body: $blocks,
                pageSetup: $sectionTemplate->pageSetup,
                headerBlocks: $sectionTemplate->headerBlocks,
                footerBlocks: $sectionTemplate->footerBlocks,
                watermarkText: $sectionTemplate->watermarkText,
                firstPageHeaderBlocks: $sectionTemplate->firstPageHeaderBlocks,
                firstPageFooterBlocks: $sectionTemplate->firstPageFooterBlocks,
                watermarkImage: $sectionTemplate->watermarkImage,
                watermarkImageWidthPt: $sectionTemplate->watermarkImageWidthPt,
                watermarkImageOpacity: $sectionTemplate->watermarkImageOpacity,
                watermarkTextOpacity: $sectionTemplate->watermarkTextOpacity,
            );
        } else {
            $section = new Section($blocks);
        }

        return new self(
            section: $section,
            metadata: $metadata,
            tagged: $tagged,
            lang: $lang,
            useXrefStream: $useXrefStream,
            pdfVersion: $pdfVersion,
            useObjectStreams: $useObjectStreams,
            encryption: $encryption,
            signature: $signature,
            pdfA: $pdfA,
        );
    }

    public function toBytes(?Engine $engine = null): string
    {
        return $this->prepare($engine)->toBytes();
    }

    /**
     * Phase 216: streaming output — emits PDF к writable stream resource без
     * accumulating full document в memory string. Use case: large documents,
     * HTTP response (php://output), file uploads.
     *
     * @param  resource  $stream  Writable stream resource (fopen, php://memory etc).
     * @return int  Bytes written.
     */
    public function toStream($stream, ?Engine $engine = null): int
    {
        return $this->prepare($engine)->toStream($stream);
    }

    public function toFile(string $path, ?Engine $engine = null): int
    {
        $fp = @fopen($path, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('Failed to open PDF file для writing: '.$path);
        }
        try {
            return $this->toStream($fp, $engine);
        } finally {
            fclose($fp);
        }
    }

    /**
     * Phase 216: shared preparation — runs Engine + applies metadata/xref/objstm
     * flags. Returns ready-to-emit Pdf\Document.
     */
    private function prepare(?Engine $engine): \Dskripchenko\PhpPdf\Pdf\Document
    {
        $engine ??= new Engine;
        $pdf = $engine->render($this);
        if ($this->pdfVersion !== null) {
            $pdf->pdfVersion($this->pdfVersion);
        }
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
        // Phase 217: PDF/A must apply ДО encryption (PDF/A disallows encryption,
        // throws при wrong order).
        if ($this->pdfA !== null) {
            $pdf->enablePdfA($this->pdfA);
        }
        if ($this->encryption !== null) {
            $pdf->encrypt(
                userPassword: $this->encryption->userPassword,
                ownerPassword: $this->encryption->ownerPassword,
                permissions: $this->encryption->permissions,
                algorithm: $this->encryption->algorithm,
            );
        }
        if ($this->signature !== null) {
            $pdf->sign($this->signature);
        }
        if ($this->useXrefStream) {
            $pdf->useXrefStream();
        }
        if ($this->useObjectStreams) {
            $pdf->useObjectStreams();
        }

        return $pdf;
    }
}
