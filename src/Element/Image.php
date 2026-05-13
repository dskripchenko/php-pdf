<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Style\Alignment;

/**
 * Image — block-level raster image (PNG/JPEG).
 *
 * Source хранится как готовый PdfImage (eager-parsed) для простоты —
 * PDFs обычно имеют немного изображений, и парсинг происходит один
 * раз при construction'е.
 *
 * Sizing:
 *  - Если оба widthPt/heightPt заданы — используются как есть.
 *  - Если только один — другой вычисляется из aspect ratio.
 *  - Если оба null — native dimensions @ 72 DPI (1px = 1pt).
 *
 * Alignment действует на block-level — X-position на content area:
 *  - Start — flush left
 *  - Center — horizontally centered
 *  - End — flush right
 *  - Both/Distribute — деградируют к Start (для image'й не имеют смысла)
 *
 * Phase 16: Image теперь implements обоих интерфейсов. Engine routing:
 *  - На top-level (Section.body) → block mode (full-line с alignment)
 *  - Внутри Paragraph.children → inline mode (image как atom в текстовом
 *    потоке; line-height accommodates image height)
 */
final readonly class Image implements BlockElement, InlineElement
{
    public function __construct(
        public PdfImage $source,
        public ?float $widthPt = null,
        public ?float $heightPt = null,
        public Alignment $alignment = Alignment::Start,
        public float $spaceBeforePt = 0,
        public float $spaceAfterPt = 0,
        // Phase 62: PDF/UA alt-text для screen readers (emitted в /Alt
        // entry struct element). null = no alt text.
        public ?string $altText = null,
    ) {}

    public static function fromPath(
        string $path,
        ?float $widthPt = null,
        ?float $heightPt = null,
        Alignment $alignment = Alignment::Start,
        float $spaceBeforePt = 0,
        float $spaceAfterPt = 0,
        ?string $altText = null,
    ): self {
        return new self(
            source: PdfImage::fromPath($path),
            widthPt: $widthPt,
            heightPt: $heightPt,
            alignment: $alignment,
            spaceBeforePt: $spaceBeforePt,
            spaceAfterPt: $spaceAfterPt,
            altText: $altText,
        );
    }

    public static function fromBytes(
        string $bytes,
        ?float $widthPt = null,
        ?float $heightPt = null,
        Alignment $alignment = Alignment::Start,
        float $spaceBeforePt = 0,
        float $spaceAfterPt = 0,
        ?string $altText = null,
    ): self {
        return new self(
            source: PdfImage::fromBytes($bytes),
            widthPt: $widthPt,
            heightPt: $heightPt,
            alignment: $alignment,
            spaceBeforePt: $spaceBeforePt,
            spaceAfterPt: $spaceAfterPt,
            altText: $altText,
        );
    }

    /**
     * Effective rendered dimensions в pt. Применяет defaulting + aspect
     * ratio preservation.
     *
     * @return array{0: float, 1: float} [widthPt, heightPt]
     */
    public function effectiveSizePt(): array
    {
        $nativeW = (float) $this->source->widthPx;
        $nativeH = (float) $this->source->heightPx;

        if ($this->widthPt !== null && $this->heightPt !== null) {
            return [$this->widthPt, $this->heightPt];
        }
        if ($this->widthPt !== null) {
            $ratio = $nativeH / max($nativeW, 1);

            return [$this->widthPt, $this->widthPt * $ratio];
        }
        if ($this->heightPt !== null) {
            $ratio = $nativeW / max($nativeH, 1);

            return [$this->heightPt * $ratio, $this->heightPt];
        }

        // Native: 1px = 1pt (72 DPI).
        return [$nativeW, $nativeH];
    }
}
