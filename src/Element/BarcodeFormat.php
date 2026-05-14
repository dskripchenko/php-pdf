<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Phase 32: Поддерживаемые форматы barcode.
 *
 * Code128 — линейный barcode, Set B (ASCII 32..126). Самый универсальный
 * для бизнес-документов (invoices, shipping labels, SKU).
 *
 * Будущие форматы (deferred):
 *  - Code 128 Set A (control chars)
 *  - Code 128 Set C (compressed numeric)
 *  - EAN-13 / UPC-A (products)
 *  - QR Code (2D, Reed-Solomon)
 *  - DataMatrix (2D)
 */
enum BarcodeFormat: string
{
    case Code128 = 'code128';
    case Ean13 = 'ean13';
    case UpcA = 'upca';
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
