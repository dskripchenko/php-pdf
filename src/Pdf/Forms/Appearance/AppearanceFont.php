<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms\Appearance;

use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;

/**
 * A font an appearance stream can show text with.
 *
 * @internal
 */
interface AppearanceFont
{
    /** The font dictionary to list under the stream's `/Resources /Font`. */
    public function reference(): PdfReference;

    /** Whether every character of the text can be shown. */
    public function canShow(string $utf8): bool;

    /** The text as a string operand for `Tj` (literal or hex). */
    public function encode(string $utf8): string;

    /** Advance width of the text at the given size, in points. */
    public function width(string $utf8, float $size): float;

    /** Cap height, in 1/1000 em — used to centre a line vertically. */
    public function capHeight(): float;

    /** Ascent, in 1/1000 em — the first baseline of a multi-line block. */
    public function ascent(): float;
}
