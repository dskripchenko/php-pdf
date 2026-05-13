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
}
