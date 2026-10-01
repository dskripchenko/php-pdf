<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms\Appearance;

/**
 * A parsed `/DA` string (ISO 32000-1 §12.7.3.3): the font resource name, the
 * size (0 = auto) and the colour operator text is drawn with.
 *
 * @internal
 */
final readonly class DefaultAppearance
{
    public function __construct(
        public ?string $fontName,
        public float $fontSize,
        public string $color,
    ) {
    }

    public static function parse(?string $da): self
    {
        $fontName = null;
        $fontSize = 0.0;
        $color = '0 g';
        if ($da === null) {
            return new self($fontName, $fontSize, $color);
        }

        $tokens = preg_split('/\s+/', trim($da)) ?: [];
        $operands = [];
        foreach ($tokens as $token) {
            switch ($token) {
                case 'Tf':
                    if (count($operands) >= 2) {
                        $fontName = ltrim((string) $operands[count($operands) - 2], '/');
                        $fontSize = (float) $operands[count($operands) - 1];
                    }
                    break;
                case 'g':
                case 'rg':
                case 'k':
                    $arity = ['g' => 1, 'rg' => 3, 'k' => 4][$token];
                    $values = array_slice($operands, -$arity);
                    if (count($values) === $arity && array_filter($values, 'is_numeric') === $values) {
                        $color = implode(' ', $values).' '.$token;
                    }
                    break;
                default:
                    $operands[] = $token;
                    continue 2;
            }
            $operands = [];
        }

        return new self($fontName !== '' ? $fontName : null, max(0.0, $fontSize), $color);
    }
}
