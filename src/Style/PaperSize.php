<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Style;

/**
 * Стандартные форматы страницы. Размеры в pt (1 inch = 72 pt).
 *
 * Соответствует ISO 216 (A-series) и US standard letter/legal.
 *
 * `widthPt()`/`heightPt()` возвращают portrait dimensions; для landscape
 * вызвать `widthPt()`/`heightPt()` с swapped order вручную (см.
 * `Orientation::applyTo` для готового resolver'а).
 */
enum PaperSize
{
    // A-series ISO 216 — calculated from sqrt(2)-aspect from A0 (841×1189mm).
    case A3;
    case A4;
    case A5;
    case A6;

    // US standard.
    case Letter;
    case Legal;
    case Tabloid;
    case Executive;

    public function widthPt(): float
    {
        return match ($this) {
            self::A3 => 841.89,
            self::A4 => 595.28,
            self::A5 => 419.53,
            self::A6 => 297.64,
            self::Letter => 612.0,
            self::Legal => 612.0,
            self::Tabloid => 792.0,
            self::Executive => 522.0,
        };
    }

    public function heightPt(): float
    {
        return match ($this) {
            self::A3 => 1190.55,
            self::A4 => 841.89,
            self::A5 => 595.28,
            self::A6 => 419.53,
            self::Letter => 792.0,
            self::Legal => 1008.0,
            self::Tabloid => 1224.0,
            self::Executive => 756.0,
        };
    }
}
