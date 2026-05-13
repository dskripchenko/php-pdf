<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Style\Orientation;
use Dskripchenko\PhpPdf\Style\PaperSize;

/**
 * Single PDF page с накопленными draw-командами.
 *
 * Phase 1 scope: text + simple shapes + images, всё на absolute-positioned
 * координатах. Никакого layout / wrapping / line-breaking — это работа
 * Phase 3 (Layout Engine).
 *
 * Coordinate system: PDF origin = lower-left угол. X растёт вправо,
 * Y вверх. Единица — 1 pt (1/72 inch).
 *
 * Используется через Document::addPage():
 *
 *   $doc = Document::new(PaperSize::A4);
 *   $page = $doc->addPage();
 *   $page->showText('Hello', x: 72, y: 720, font: StandardFont::TimesRoman, sizePt: 12);
 */
final class Page
{
    /** Накопление content stream commands — отложенный рендер на toBytes(). */
    private ContentStream $stream;

    /** @var array<string, StandardFont> name → font (resource registration) */
    private array $standardFonts = [];

    /** @var array<string, PdfFont> name → embedded font */
    private array $embeddedFonts = [];

    /** @var array<string, PdfImage> name → image */
    private array $images = [];

    /**
     * Link annotations накопленные на этой page.
     *
     * @var list<array{kind: 'uri'|'internal', x1: float, y1: float, x2: float, y2: float, target: string}>
     */
    private array $linkAnnotations = [];

    private int $fontCounter = 0;

    private int $imageCounter = 0;

    /**
     * @param  array{0: float, 1: float}|null  $customDimensionsPt  [widthPt, heightPt]
     *                                                              в portrait orientation; orientation swap applied automatically.
     */
    public function __construct(
        public readonly PaperSize $paperSize,
        public readonly Orientation $orientation = Orientation::Portrait,
        public readonly ?array $customDimensionsPt = null,
    ) {
        $this->stream = new ContentStream;
    }

    public function widthPt(): float
    {
        if ($this->customDimensionsPt !== null) {
            return $this->orientation === Orientation::Portrait
                ? $this->customDimensionsPt[0]
                : $this->customDimensionsPt[1];
        }

        return $this->orientation->applyTo($this->paperSize)[0];
    }

    public function heightPt(): float
    {
        if ($this->customDimensionsPt !== null) {
            return $this->orientation === Orientation::Portrait
                ? $this->customDimensionsPt[1]
                : $this->customDimensionsPt[0];
        }

        return $this->orientation->applyTo($this->paperSize)[1];
    }

    /**
     * Show text using a PDF base-14 font. Encoding — WinAnsi (Latin-1).
     * Для Cyrillic / Unicode используй showEmbeddedText() с PdfFont.
     */
    public function showText(
        string $text, float $x, float $y, StandardFont $font, float $sizePt,
        ?float $r = null, ?float $g = null, ?float $b = null,
    ): self {
        $resourceName = $this->registerStandardFont($font);
        $this->stream->text($resourceName, $sizePt, $x, $y, $text, $r, $g, $b);

        return $this;
    }

    /**
     * Show text using embedded TTF font (via PdfFont). Поддерживает Unicode
     * (Cyrillic, Greek, и т.д.).
     *
     * Auto-применяет kerning если font имеет GPOS table (Liberation,
     * Noto и большинство modern font'ов). Без kerning'а — fall back на
     * простой Tj operator.
     */
    public function showEmbeddedText(
        string $text, float $x, float $y, PdfFont $font, float $sizePt,
        ?float $r = null, ?float $g = null, ?float $b = null,
    ): self {
        $resourceName = $this->registerEmbeddedFont($font);
        $tjOps = $font->encodeTextTjArray($text);
        if (count($tjOps) === 1) {
            $this->stream->textHexString($resourceName, $sizePt, $x, $y, $tjOps[0], $r, $g, $b);
        } else {
            $this->stream->textTjArray($resourceName, $sizePt, $x, $y, $tjOps, $r, $g, $b);
        }

        return $this;
    }

    /**
     * Drawn watermark — large diagonal text behind content. Use StandardFont
     * вариант для base14 или embedded для PdfFont.
     */
    public function drawWatermark(
        string $text,
        float $cx,
        float $cy,
        StandardFont $font,
        float $sizePt,
        float $angleRad = -0.7854,    // -45° (down-right diagonal)
        float $r = 0.88, float $g = 0.88, float $b = 0.88,
    ): self {
        $resourceName = $this->registerStandardFont($font);
        $this->stream->rotatedText($resourceName, $sizePt, $cx, $cy, $angleRad, $text, $r, $g, $b);

        return $this;
    }

    public function drawWatermarkEmbedded(
        string $text,
        float $cx,
        float $cy,
        PdfFont $font,
        float $sizePt,
        float $angleRad = -0.7854,
        float $r = 0.88, float $g = 0.88, float $b = 0.88,
    ): self {
        $resourceName = $this->registerEmbeddedFont($font);
        $hex = $font->encodeText($text);
        $this->stream->rotatedText($resourceName, $sizePt, $cx, $cy, $angleRad, $hex, $r, $g, $b, isHex: true);

        return $this;
    }

    /**
     * Filled rectangle (RGB 0..1).
     */
    public function fillRect(
        float $x, float $y, float $width, float $height,
        float $r = 0, float $g = 0, float $b = 0,
    ): self {
        $this->stream->fillRectangle($x, $y, $width, $height, $r, $g, $b);

        return $this;
    }

    /**
     * Outline rectangle (RGB 0..1).
     */
    public function strokeRect(
        float $x, float $y, float $width, float $height,
        float $lineWidthPt = 0.5,
        float $r = 0, float $g = 0, float $b = 0,
    ): self {
        $this->stream->strokeRectangle($x, $y, $width, $height, $lineWidthPt, $r, $g, $b);

        return $this;
    }

    /**
     * Draw image (PNG/JPEG) at (x, y) with scaling to (widthPt, heightPt).
     */
    public function drawImage(PdfImage $image, float $x, float $y, float $widthPt, float $heightPt): self
    {
        $resourceName = $this->registerImage($image);
        $this->stream->drawImage($resourceName, $x, $y, $widthPt, $heightPt);

        return $this;
    }

    /**
     * Внешний URL link — клик в Rect открывает $uri.
     */
    public function addExternalLink(float $x, float $y, float $width, float $height, string $uri): self
    {
        $this->linkAnnotations[] = [
            'kind' => 'uri',
            'x1' => $x, 'y1' => $y,
            'x2' => $x + $width, 'y2' => $y + $height,
            'target' => $uri,
        ];

        return $this;
    }

    /**
     * Internal link — клик в Rect переходит к named destination $destName.
     */
    public function addInternalLink(float $x, float $y, float $width, float $height, string $destName): self
    {
        $this->linkAnnotations[] = [
            'kind' => 'internal',
            'x1' => $x, 'y1' => $y,
            'x2' => $x + $width, 'y2' => $y + $height,
            'target' => $destName,
        ];

        return $this;
    }

    /**
     * @return list<array{kind: 'uri'|'internal', x1: float, y1: float, x2: float, y2: float, target: string}>
     *
     * @internal
     */
    public function linkAnnotations(): array
    {
        return $this->linkAnnotations;
    }

    /**
     * Низкоуровневый escape-hatch — append raw content stream command'ы.
     * Используется для unsupported операторов (Bezier curves, text matrices,
     * graphic state save/restore manual'но). Caller отвечает за валидность
     * PDF syntax'а.
     */
    public function rawContentStream(): ContentStream
    {
        return $this->stream;
    }

    /**
     * Build content stream body для эмиссии в Writer.
     *
     * @internal Used by Document.
     */
    public function buildContentStream(): string
    {
        return $this->stream->toString();
    }

    /**
     * Resource map: standard fonts (resourceName → StandardFont).
     *
     * @return array<string, StandardFont>
     *
     * @internal
     */
    public function standardFonts(): array
    {
        return $this->standardFonts;
    }

    /**
     * @return array<string, PdfFont>
     *
     * @internal
     */
    public function embeddedFonts(): array
    {
        return $this->embeddedFonts;
    }

    /**
     * @return array<string, PdfImage>
     *
     * @internal
     */
    public function images(): array
    {
        return $this->images;
    }

    /**
     * Register standard font for use on this page. Возвращает resource name.
     * Если font уже зарегистрирован — возвращает existing name.
     */
    private function registerStandardFont(StandardFont $font): string
    {
        foreach ($this->standardFonts as $name => $f) {
            if ($f === $font) {
                return $name;
            }
        }
        $name = 'F'.(++$this->fontCounter);
        $this->standardFonts[$name] = $font;

        return $name;
    }

    private function registerEmbeddedFont(PdfFont $font): string
    {
        foreach ($this->embeddedFonts as $name => $f) {
            if ($f === $font) {
                return $name;
            }
        }
        $name = 'F'.(++$this->fontCounter);
        $this->embeddedFonts[$name] = $font;

        return $name;
    }

    private function registerImage(PdfImage $image): string
    {
        foreach ($this->images as $name => $img) {
            if ($img === $image) {
                return $name;
            }
        }
        $name = 'Im'.(++$this->imageCounter);
        $this->images[$name] = $image;

        return $name;
    }
}
