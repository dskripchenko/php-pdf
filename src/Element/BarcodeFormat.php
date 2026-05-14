<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Phase 32: Поддерживаемые форматы barcode.
 *
 * Все 6 форматов production-ready (по состоянию v1.3+):
 *  - Code128 — Set A/B/C auto-switching + GS1-128 (Phase 164-165)
 *  - Ean13/UpcA — Phase 35
 *  - Qr — V1-V10 + L/M/Q/H + Numeric/Alphanumeric/Byte/Kanji
 *    + Structured Append + ECI (Phase 36-38, 101, 183-184)
 *  - DataMatrix — ECC 200 square/rect + multi-region
 *    + C40/Text/X12/EDIFACT/Base 256 (Phase 104, 127, 176-180)
 *  - Pdf417 — stacked + Text/Numeric compaction + Macro (Phase 124, 181-182, 185)
 *  - Aztec — Compact 1-4L + Full 5-32L (Phase 125-126)
 *
 * Deferred к v1.4+: QR V11-V40, DataMatrix 144×144, Aztec Rune mode,
 * Aztec Structured Append/ECI.
 */
enum BarcodeFormat: string
{
    case Code128 = 'code128';
    case Ean13 = 'ean13';
    case Ean8 = 'ean8';      // Phase 200: short EAN variant (7+1 digits).
    case UpcA = 'upca';
    case UpcE = 'upce';      // Phase 201: zero-suppressed UPC-A (6+NSD+check).
    case Qr = 'qr';
    case DataMatrix = 'datamatrix';
    case Pdf417 = 'pdf417'; // Phase 124: stacked linear 2D barcode.
    case Aztec = 'aztec';   // Phase 125: square 2D barcode (compact 1-4L).

    public function is2D(): bool
    {
        return match ($this) {
            self::Qr, self::DataMatrix, self::Aztec => true,
            default => false,
        };
    }

    /** Phase 124: stacked-linear barcodes need per-row rendering. */
    public function isStacked(): bool
    {
        return $this === self::Pdf417;
    }
}
