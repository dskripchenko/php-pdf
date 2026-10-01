<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms\Appearance;

use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;

/**
 * A single-byte WinAnsi font: a base-14 font, or a fully embedded simple font
 * from the form's `/DR` whose `/Widths` are known.
 *
 * @internal
 */
final class SimpleFont implements AppearanceFont
{
    /**
     * @param array<int,float>|null $widths code → width (1/1000 em) from the
     *                                      font's own /Widths, or null to use
     *                                      the base-14 metrics of `$baseFont`
     */
    public function __construct(
        private readonly PdfReference $reference,
        private readonly string $baseFont,
        private readonly ?array $widths = null,
        private readonly bool $asciiOnly = false,
    ) {
    }

    public function reference(): PdfReference
    {
        return $this->reference;
    }

    public function canShow(string $utf8): bool
    {
        if ($this->asciiOnly && preg_match('/^[\x20-\x7E]*$/', $utf8) !== 1) {
            return false;
        }

        return WinAnsi::encode($utf8) !== null;
    }

    public function encode(string $utf8): string
    {
        $bytes = WinAnsi::encode($utf8) ?? '';
        $out = '(';
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $c = $bytes[$i];
            $code = ord($c);
            $out .= match (true) {
                $c === '\\' || $c === '(' || $c === ')' => '\\'.$c,
                $code < 0x20 || $code > 0x7E => sprintf('\\%03o', $code),
                default => $c,
            };
        }

        return $out.')';
    }

    public function width(string $utf8, float $size): float
    {
        $bytes = WinAnsi::encode($utf8) ?? '';
        $units = 0.0;
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $code = ord($bytes[$i]);
            $units += $this->widths !== null
                ? ($this->widths[$code] ?? 500.0)
                : StandardFontMetrics::width($this->baseFont, $code);
        }

        return $units * $size / 1000;
    }

    public function capHeight(): float
    {
        return str_starts_with($this->baseFont, 'Times') ? 662.0 : (str_starts_with($this->baseFont, 'Courier') ? 571.0 : 718.0);
    }

    public function ascent(): float
    {
        return str_starts_with($this->baseFont, 'Times') ? 683.0 : (str_starts_with($this->baseFont, 'Courier') ? 629.0 : 718.0);
    }
}
