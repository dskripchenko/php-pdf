<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Barcode\QrEccLevel;
use Dskripchenko\PhpPdf\Style\Alignment;

/**
 * Phase 32: Barcode block element.
 *
 * Рендерится как набор чёрных вертикальных bars + опциональный human-readable
 * text caption под кодом.
 *
 *  - $value — данные для encode'а. Validation/encoding делает encoder.
 *  - $format — BarcodeFormat (пока только Code128).
 *  - $widthPt — общая ширина barcode'а (без quiet zones). Если null —
 *    auto: modules × 1pt (rough default; обычно нужен tweak).
 *  - $heightPt — высота bars. Default = 40pt.
 *  - $showText — рендерить caption ниже bars.
 *  - $textSizePt — размер caption (default 8pt).
 *  - $alignment — горизонтальное выравнивание блока в content area.
 */
final readonly class Barcode implements BlockElement
{
    public function __construct(
        public string $value,
        public BarcodeFormat $format = BarcodeFormat::Code128,
        public ?float $widthPt = null,
        public float $heightPt = 40.0,
        public bool $showText = true,
        public float $textSizePt = 8.0,
        public Alignment $alignment = Alignment::Start,
        public float $spaceBeforePt = 0,
        public float $spaceAfterPt = 0,
        // Phase 37: ECC level для 2D barcodes (QR). Linear barcodes ignore.
        public ?QrEccLevel $eccLevel = null,
    ) {}
}
