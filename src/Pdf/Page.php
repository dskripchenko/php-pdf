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

    /** @var array<string, PdfExtGState> name → extGState (Phase 31) */
    private array $extGStates = [];

    private int $extGStateCounter = 0;

    /**
     * Link annotations накопленные на этой page.
     *
     * @var list<array{kind: 'uri'|'internal', x1: float, y1: float, x2: float, y2: float, target: string}>
     */
    private array $linkAnnotations = [];

    /**
     * Phase 43: AcroForm fields on этой page.
     *
     * @var list<array{type: 'text'|'checkbox', name: string, x: float, y: float, w: float, h: float, defaultValue: string, tooltip: ?string, required: bool, readOnly: bool}>
     */
    private array $formFields = [];

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
        float $letterSpacingPt = 0,
    ): self {
        $resourceName = $this->registerStandardFont($font);
        $this->stream->text($resourceName, $sizePt, $x, $y, $text, $r, $g, $b, $letterSpacingPt);

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
        float $letterSpacingPt = 0,
    ): self {
        $resourceName = $this->registerEmbeddedFont($font);
        $tjOps = $font->encodeTextTjArray($text);
        if (count($tjOps) === 1) {
            $this->stream->textHexString($resourceName, $sizePt, $x, $y, $tjOps[0], $r, $g, $b, $letterSpacingPt);
        } else {
            // textTjArray не имеет letter-spacing поддержки (TJ кernit'инг
            // делает adjustments сам); если задан letter-spacing — fall
            // back на Tc + hex single string.
            if ($letterSpacingPt !== 0.0) {
                $hex = $font->encodeText($text);
                $this->stream->textHexString($resourceName, $sizePt, $x, $y, $hex, $r, $g, $b, $letterSpacingPt);
            } else {
                $this->stream->textTjArray($resourceName, $sizePt, $x, $y, $tjOps, $r, $g, $b);
            }
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
        ?float $opacity = null,
    ): self {
        $resourceName = $this->registerStandardFont($font);
        $gsName = $this->maybeRegisterOpacityGs($opacity);
        if ($gsName !== null) {
            $this->stream->pushGraphicsStateWithGs($gsName);
        }
        $this->stream->rotatedText($resourceName, $sizePt, $cx, $cy, $angleRad, $text, $r, $g, $b);
        if ($gsName !== null) {
            $this->stream->popGraphicsState();
        }

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
        ?float $opacity = null,
    ): self {
        $resourceName = $this->registerEmbeddedFont($font);
        $hex = $font->encodeText($text);
        $gsName = $this->maybeRegisterOpacityGs($opacity);
        if ($gsName !== null) {
            $this->stream->pushGraphicsStateWithGs($gsName);
        }
        $this->stream->rotatedText($resourceName, $sizePt, $cx, $cy, $angleRad, $hex, $r, $g, $b, isHex: true);
        if ($gsName !== null) {
            $this->stream->popGraphicsState();
        }

        return $this;
    }

    /**
     * Phase 31: для opacity ∈ (0, 1) регистрирует ExtGState и возвращает
     * имя ресурса; null/1.0/out-of-range → null (no-op).
     */
    private function maybeRegisterOpacityGs(?float $opacity): ?string
    {
        if ($opacity === null || $opacity >= 1.0) {
            return null;
        }
        $clamped = max(0.0, $opacity);

        return $this->registerExtGState(new PdfExtGState(fillOpacity: $clamped, strokeOpacity: $clamped));
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
     * Filled rounded rectangle. radius=0 фолбэк на fillRect.
     */
    public function fillRoundedRect(
        float $x, float $y, float $width, float $height, float $radius,
        float $r = 0, float $g = 0, float $b = 0,
    ): self {
        if ($radius <= 0) {
            return $this->fillRect($x, $y, $width, $height, $r, $g, $b);
        }
        $this->stream->fillRoundedRectangle($x, $y, $width, $height, $radius, $r, $g, $b);

        return $this;
    }

    /**
     * Stroked rounded rectangle.
     */
    public function strokeRoundedRect(
        float $x, float $y, float $width, float $height, float $radius,
        float $lineWidthPt = 0.5,
        float $r = 0, float $g = 0, float $b = 0,
    ): self {
        if ($radius <= 0) {
            return $this->strokeRect($x, $y, $width, $height, $lineWidthPt, $r, $g, $b);
        }
        $this->stream->strokeRoundedRectangle($x, $y, $width, $height, $radius, $lineWidthPt, $r, $g, $b);

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
     * Phase 44: stroked straight line.
     */
    public function strokeLine(
        float $x1, float $y1, float $x2, float $y2,
        float $lineWidthPt = 0.5,
        float $r = 0, float $g = 0, float $b = 0,
    ): self {
        $this->stream->strokeLine($x1, $y1, $x2, $y2, $lineWidthPt, $r, $g, $b);

        return $this;
    }

    /**
     * Phase 45: filled polygon (closed path).
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    public function fillPolygon(array $points, float $r = 0, float $g = 0, float $b = 0): self
    {
        $this->stream->fillPolygon($points, $r, $g, $b);

        return $this;
    }

    /**
     * Phase 45: stroked polyline (not closed).
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    public function strokePolyline(
        array $points,
        float $lineWidthPt = 1.0,
        float $r = 0, float $g = 0, float $b = 0,
    ): self {
        $this->stream->strokePolyline($points, $lineWidthPt, $r, $g, $b);

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
     * Phase 31: drawImage с opacity. opacity ∈ (0, 1) — fill alpha
     * через ExtGState `/ca`. 1.0 эквивалентен plain drawImage.
     */
    public function drawImageWithOpacity(
        PdfImage $image,
        float $x,
        float $y,
        float $widthPt,
        float $heightPt,
        float $opacity,
    ): self {
        $resourceName = $this->registerImage($image);
        if ($opacity >= 1.0) {
            $this->stream->drawImage($resourceName, $x, $y, $widthPt, $heightPt);

            return $this;
        }
        $gsName = $this->registerExtGState(new PdfExtGState(fillOpacity: max(0.0, $opacity)));
        $this->stream->drawImageWithGs($resourceName, $gsName, $x, $y, $widthPt, $heightPt);

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
     * Phase 43: Add interactive form field widget на page.
     */
    public function addFormField(
        string $type,
        string $name,
        float $x,
        float $y,
        float $width,
        float $height,
        string $defaultValue = '',
        ?string $tooltip = null,
        bool $required = false,
        bool $readOnly = false,
    ): self {
        $this->formFields[] = [
            'type' => $type,
            'name' => $name,
            'x' => $x,
            'y' => $y,
            'w' => $width,
            'h' => $height,
            'defaultValue' => $defaultValue,
            'tooltip' => $tooltip,
            'required' => $required,
            'readOnly' => $readOnly,
        ];

        return $this;
    }

    /**
     * @return list<array{type: 'text'|'checkbox', name: string, x: float, y: float, w: float, h: float, defaultValue: string, tooltip: ?string, required: bool, readOnly: bool}>
     *
     * @internal
     */
    public function formFields(): array
    {
        return $this->formFields;
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

    /**
     * Phase 31: Register ExtGState (opacity). Dedup по key() —
     * одинаковые opacity tuples переиспользуют resource.
     *
     * @return string Resource name (`Gs1`, `Gs2`, ...).
     */
    public function registerExtGState(PdfExtGState $state): string
    {
        $key = $state->key();
        foreach ($this->extGStates as $name => $existing) {
            if ($existing->key() === $key) {
                return $name;
            }
        }
        $name = 'Gs'.(++$this->extGStateCounter);
        $this->extGStates[$name] = $state;

        return $name;
    }

    /**
     * @return array<string, PdfExtGState>
     *
     * @internal
     */
    public function extGStates(): array
    {
        return $this->extGStates;
    }
}
