<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Build;

use Closure;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\BlockElement;
use Dskripchenko\PhpPdf\Element\HorizontalRule;
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Element\PageBreak;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\PageSetup;
use Dskripchenko\PhpPdf\Style\RunStyle;

/**
 * Fluent builder для Document'а.
 *
 * Mirror'ит php-docx DocumentBuilder для API-симметрии — единый API
 * между Word- и PDF-генерацией позволяет переключать backend в одну строку.
 *
 * Pattern:
 *   DocumentBuilder::new()
 *       ->pageSetup(new PageSetup(...))
 *       ->heading(1, 'Invoice')
 *       ->paragraph('Customer: Acme Co.')
 *       ->paragraph(fn($p) => $p->text('Mix ')->bold('here'))
 *       ->pageBreak()
 *       ->toBytes();
 *
 * Tables (Phase 5), bullet/ordered lists (Phase 6), hyperlinks/bookmarks
 * (Phase 7), headers/footers (Phase 8) пока не покрыты.
 */
final class DocumentBuilder
{
    /** @var list<BlockElement> */
    private array $body = [];

    private PageSetup $pageSetup;

    public function __construct()
    {
        $this->pageSetup = new PageSetup;
    }

    public static function new(): self
    {
        return new self;
    }

    // ── Page setup ────────────────────────────────────────────────

    public function pageSetup(PageSetup $setup): self
    {
        $this->pageSetup = $setup;

        return $this;
    }

    // ── Block content ─────────────────────────────────────────────

    /**
     * Добавляет параграф. $content может быть:
     *  - string — простой single-run text
     *  - Closure(ParagraphBuilder) — для сложного inline-content/стиля
     *  - Paragraph — готовый AST-node
     */
    public function paragraph(string|Closure|Paragraph $content): self
    {
        if ($content instanceof Paragraph) {
            $this->body[] = $content;

            return $this;
        }
        if (is_string($content)) {
            $this->body[] = new Paragraph([new Run($content)]);

            return $this;
        }

        $b = new ParagraphBuilder;
        $content($b);
        $this->body[] = $b->build();

        return $this;
    }

    /**
     * Heading paragraph уровня 1..6. $content — string или Closure(ParagraphBuilder).
     */
    public function heading(int $level, string|Closure $content): self
    {
        if ($level < 1 || $level > 6) {
            throw new \InvalidArgumentException("Heading level must be 1..6, got $level.");
        }

        $b = new ParagraphBuilder;
        $b->heading($level);
        if (is_string($content)) {
            $b->text($content);
        } else {
            $content($b);
        }
        $this->body[] = $b->build();

        return $this;
    }

    public function pageBreak(): self
    {
        $this->body[] = new PageBreak;

        return $this;
    }

    public function horizontalRule(): self
    {
        $this->body[] = new HorizontalRule;

        return $this;
    }

    /**
     * Пустая строка — empty paragraph (полезно для visual spacing).
     */
    public function emptyLine(): self
    {
        $this->body[] = new Paragraph;

        return $this;
    }

    /**
     * Прямое добавление BlockElement'а в AST. Escape-hatch для типов
     * не покрытых fluent-методами.
     */
    public function block(BlockElement $element): self
    {
        $this->body[] = $element;

        return $this;
    }

    /**
     * Block-level image. $source — file path (string), готовый PdfImage,
     * или AST Image. Sizing/alignment через keyword arguments.
     *
     * Examples:
     *   ->image('/path/to/logo.png')
     *   ->image('/path/to/photo.jpg', widthPt: 200, alignment: Alignment::Center)
     *   ->image($pdfImage, widthPt: 300, heightPt: 200)
     */
    /**
     * Block-level table. $content — Closure(TableBuilder) или готовый Table.
     */
    public function table(Closure|Table $content): self
    {
        if ($content instanceof Table) {
            $this->body[] = $content;

            return $this;
        }
        $b = new TableBuilder;
        $content($b);
        $this->body[] = $b->build();

        return $this;
    }

    public function image(
        string|PdfImage|Image $source,
        ?float $widthPt = null,
        ?float $heightPt = null,
        Alignment $alignment = Alignment::Start,
        float $spaceBeforePt = 0,
        float $spaceAfterPt = 0,
    ): self {
        if ($source instanceof Image) {
            $this->body[] = $source;

            return $this;
        }
        $pdfImage = $source instanceof PdfImage ? $source : PdfImage::fromPath($source);
        $this->body[] = new Image(
            source: $pdfImage,
            widthPt: $widthPt,
            heightPt: $heightPt,
            alignment: $alignment,
            spaceBeforePt: $spaceBeforePt,
            spaceAfterPt: $spaceAfterPt,
        );

        return $this;
    }

    // ── Convenience aliases ───────────────────────────────────────

    public function text(string $text, ?RunStyle $style = null): self
    {
        return $this->paragraph(fn(ParagraphBuilder $p) => $p->text($text, $style));
    }

    // ── Finalizers ────────────────────────────────────────────────

    public function build(): Document
    {
        return new Document(new Section(
            body: $this->body,
            pageSetup: $this->pageSetup,
        ));
    }

    public function toBytes(?Engine $engine = null): string
    {
        return $this->build()->toBytes($engine);
    }

    public function toFile(string $path, ?Engine $engine = null): int
    {
        return $this->build()->toFile($path, $engine);
    }
}
