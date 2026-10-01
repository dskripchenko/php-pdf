<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms\Appearance;

use Dskripchenko\PhpPdf\Pdf\Forms\FieldAttributes;
use Dskripchenko\PhpPdf\Pdf\Forms\FormGraph;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;

/**
 * Generates widget appearance streams (§12.5.5, §12.7.3.3) for variable-text
 * fields — text, combo and list boxes — and for check boxes and radio buttons
 * that come without one. The look follows what Acrobat produces: `/MK`
 * background and border, `/DA` font and colour (auto size when 0), `/Q`
 * alignment, comb cells, multi-line wrapping, text clipped to the field.
 *
 * @internal
 */
final class AppearanceBuilder
{
    private const LINE_HEIGHT = 1.16;
    private const AUTO_MAX = 12.0;
    private const AUTO_MIN = 4.0;

    /** ZapfDingbats advances (1/1000 em) of the usual check/radio marks. */
    private const DINGBAT_WIDTHS = ['4' => 846, 'l' => 791, '8' => 759, 'u' => 759, 'n' => 761, 'H' => 816];

    public function __construct(
        private readonly FormGraph $graph,
        private readonly FontResolver $fonts,
    ) {
    }

    /**
     * The normal appearance of a text or choice widget showing its current
     * value, or null when the widget has no usable `/Rect`.
     */
    public function variableText(FieldAttributes $field, PdfDictionary $widget, ?PdfDictionary $dr, string $fieldName): ?PdfReference
    {
        $box = $this->box($widget);
        if ($box === null) {
            return null;
        }
        [$w, $h, $matrix] = $box;
        $mk = $this->graph->dict($widget->get('MK'));
        $border = $this->borderWidth($widget, $mk);
        $da = DefaultAppearance::parse($field->da);

        $isList = $field->ft === 'Ch' && !$field->has(FieldAttributes::FF_COMBO);
        $text = $isList ? '' : $field->displayValue();
        if ($field->has(FieldAttributes::FF_PASSWORD)) {
            $text = str_repeat('*', mb_strlen($text, 'UTF-8'));
        }
        $sample = (string) preg_replace('/[\r\n]+/', '', $isList ? implode('', array_column($field->options, 1)) : $text);
        $font = $this->fonts->resolve($da->fontName, $dr, $sample, $fieldName);

        $ops = $this->decoration($w, $h, $mk, $widget, $border);
        $ops[] = '/Tx BMC';
        $ops[] = 'q';
        $pad = max(2.0, 2 * $border);
        $ops[] = sprintf('%s %s %s %s re W n', self::num($border), self::num($border), self::num(max(0.0, $w - 2 * $border)), self::num(max(0.0, $h - 2 * $border)));

        if ($isList) {
            array_push($ops, ...$this->listBox($field, $font, $da, $w, $h, $pad));
        } elseif ($text !== '') {
            $textOps = match (true) {
                $field->ft === 'Tx' && $field->has(FieldAttributes::FF_MULTILINE) => $this->multiLine($field, $font, $da, $text, $w, $h, $pad),
                $field->ft === 'Tx' && $field->has(FieldAttributes::FF_COMB) && ($field->maxLength ?? 0) > 0 => $this->comb($field, $font, $da, $text, $w, $h, $border),
                default => $this->singleLine($field, $font, $da, $text, $w, $h, $pad, $border),
            };
            array_push($ops, ...$textOps);
        }
        $ops[] = 'Q';
        $ops[] = 'EMC';

        return $this->formXObject($w, $h, $matrix, implode("\n", $ops), ['Font' => new PdfDictionary(['F0' => $font->reference()])]);
    }

    /**
     * `[onAppearance, offAppearance]` for a check box or radio button without
     * an appearance of its own.
     *
     * @return array{PdfReference,PdfReference}|null
     */
    public function button(PdfDictionary $widget, bool $radio): ?array
    {
        $box = $this->box($widget);
        if ($box === null) {
            return null;
        }
        [$w, $h, $matrix] = $box;
        $mk = $this->graph->dict($widget->get('MK'));
        $border = $this->borderWidth($widget, $mk);
        $caption = $mk?->get('CA') instanceof PdfString ? $mk->get('CA')->bytes : '';
        $mark = $caption !== '' ? $caption[0] : ($radio ? 'l' : '4');

        $decoration = $this->decoration($w, $h, $mk, $widget, $border);
        $size = max(1.0, (min($w, $h) - 2 * $border) * 0.8);
        $markWidth = (self::DINGBAT_WIDTHS[$mark] ?? 800) * $size / 1000;
        $on = $decoration;
        $on[] = 'q';
        $on[] = 'BT';
        $on[] = sprintf('/ZaDb %s Tf', self::num($size));
        $on[] = $this->color($mk?->get('BC'), fill: true) ?? '0 g';
        $on[] = sprintf('%s %s Td', self::num(($w - $markWidth) / 2), self::num(($h - 0.705 * $size) / 2));
        $on[] = sprintf('(%s) Tj', addcslashes($mark, '()\\'));
        $on[] = 'ET';
        $on[] = 'Q';
        $resources = ['Font' => new PdfDictionary(['ZaDb' => $this->fonts->dingbats()])];

        return [
            $this->formXObject($w, $h, $matrix, implode("\n", $on), $resources),
            $this->formXObject($w, $h, $matrix, implode("\n", $decoration), []),
        ];
    }

    /** @return list<string> */
    private function singleLine(FieldAttributes $field, AppearanceFont $font, DefaultAppearance $da, string $text, float $w, float $h, float $pad, float $border): array
    {
        $size = $da->fontSize;
        if ($size <= 0) {
            $size = ($h - 2 * $border - 2) / self::LINE_HEIGHT;
            $unit = $font->width($text, 1.0);
            if ($unit > 0) {
                $size = min($size, ($w - 2 * $pad) / $unit);
            }
            $size = max(self::AUTO_MIN, min($size, $h));
        }
        $width = $font->width($text, $size);
        $x = match ($field->quadding) {
            1 => ($w - $width) / 2,
            2 => $w - $pad - $width,
            default => $pad,
        };
        $y = ($h - $font->capHeight() / 1000 * $size) / 2;

        return [
            'BT',
            sprintf('/F0 %s Tf', self::num($size)),
            $da->color,
            sprintf('%s %s Td', self::num($x), self::num($y)),
            $font->encode($text).' Tj',
            'ET',
        ];
    }

    /** @return list<string> */
    private function multiLine(FieldAttributes $field, AppearanceFont $font, DefaultAppearance $da, string $text, float $w, float $h, float $pad): array
    {
        $size = $da->fontSize;
        $lines = [];
        if ($size <= 0) {
            for ($size = self::AUTO_MAX; $size > self::AUTO_MIN; $size -= 0.5) {
                $lines = $this->wrap($font, $text, $size, $w - 2 * $pad);
                if (count($lines) * $size * self::LINE_HEIGHT <= $h - 2 * $pad) {
                    break;
                }
            }
        }
        if ($lines === []) {
            $lines = $this->wrap($font, $text, $size, $w - 2 * $pad);
        }

        $leading = $size * self::LINE_HEIGHT;
        $ops = ['BT', sprintf('/F0 %s Tf', self::num($size)), $da->color];
        $y = $h - $pad - $font->ascent() / 1000 * $size;
        foreach ($lines as $line) {
            $width = $font->width($line, $size);
            $x = match ($field->quadding) {
                1 => ($w - $width) / 2,
                2 => $w - $pad - $width,
                default => $pad,
            };
            $ops[] = sprintf('1 0 0 1 %s %s Tm', self::num($x), self::num($y));
            $ops[] = $font->encode($line).' Tj';
            $y -= $leading;
        }
        $ops[] = 'ET';

        return $ops;
    }

    /** @return list<string> */
    private function comb(FieldAttributes $field, AppearanceFont $font, DefaultAppearance $da, string $text, float $w, float $h, float $border): array
    {
        $cells = (int) $field->maxLength;
        $cell = $w / $cells;
        $chars = array_slice(mb_str_split($text, 1, 'UTF-8'), 0, $cells);
        $size = $da->fontSize;
        if ($size <= 0) {
            $size = max(self::AUTO_MIN, min(($h - 2 * $border - 2) / self::LINE_HEIGHT, $cell * 1.2));
        }
        $y = ($h - $font->capHeight() / 1000 * $size) / 2;
        $ops = ['BT', sprintf('/F0 %s Tf', self::num($size)), $da->color];
        foreach ($chars as $i => $char) {
            $x = $i * $cell + ($cell - $font->width($char, $size)) / 2;
            $ops[] = sprintf('1 0 0 1 %s %s Tm', self::num($x), self::num($y));
            $ops[] = $font->encode($char).' Tj';
        }
        $ops[] = 'ET';

        return $ops;
    }

    /** @return list<string> */
    private function listBox(FieldAttributes $field, AppearanceFont $font, DefaultAppearance $da, float $w, float $h, float $pad): array
    {
        $size = $da->fontSize > 0 ? $da->fontSize : self::AUTO_MAX;
        $leading = $size * self::LINE_HEIGHT;
        $selected = is_array($field->value) ? $field->value : [$field->value];
        $ops = [];
        $y = $h - $pad;
        foreach (array_slice($field->options, $field->topIndex) as [$export, $display]) {
            if ($y - $leading < 0) {
                break;
            }
            if (in_array($export, $selected, true)) {
                $ops[] = sprintf('0.6 0.75 0.875 rg 0 %s %s %s re f', self::num($y - $leading), self::num($w), self::num($leading));
            }
            $ops[] = 'BT';
            $ops[] = sprintf('/F0 %s Tf', self::num($size));
            $ops[] = $da->color;
            $ops[] = sprintf('%s %s Td', self::num($pad), self::num($y - $leading + ($leading - $font->capHeight() / 1000 * $size) / 2));
            $ops[] = $font->encode($display).' Tj';
            $ops[] = 'ET';
            $y -= $leading;
        }

        return $ops;
    }

    /** @return list<string> */
    private function wrap(AppearanceFont $font, string $text, float $size, float $maxWidth): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $paragraph) {
            $line = '';
            foreach (preg_split('/(\s+)/u', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $token) {
                $candidate = $line.$token;
                if ($line === '' || $font->width(rtrim($candidate), $size) <= $maxWidth) {
                    $line = $candidate;
                    continue;
                }
                $lines[] = rtrim($line);
                $line = ltrim($token);
            }
            // Hard-break a word that alone is wider than the field.
            while ($line !== '' && $font->width($line, $size) > $maxWidth && mb_strlen($line, 'UTF-8') > 1) {
                $chars = mb_str_split($line, 1, 'UTF-8');
                $head = '';
                foreach ($chars as $char) {
                    if ($head !== '' && $font->width($head.$char, $size) > $maxWidth) {
                        break;
                    }
                    $head .= $char;
                }
                $lines[] = $head;
                $line = mb_substr($line, mb_strlen($head, 'UTF-8'), null, 'UTF-8');
            }
            $lines[] = rtrim($line);
        }

        return $lines;
    }

    /**
     * Background and border from `/MK` and `/BS` (§12.5.6.19, §12.5.4).
     *
     * @return list<string>
     */
    private function decoration(float $w, float $h, ?PdfDictionary $mk, PdfDictionary $widget, float $border): array
    {
        $ops = [];
        $bg = $this->color($mk?->get('BG'), fill: true);
        if ($bg !== null) {
            $ops[] = sprintf('%s 0 0 %s %s re f', $bg, self::num($w), self::num($h));
        }
        $bc = $this->color($mk?->get('BC'), fill: false);
        if ($bc !== null && $border > 0) {
            $style = $this->graph->dict($widget->get('BS'))?->get('S');
            $style = $style instanceof PdfName ? $style->value : 'S';
            $half = $border / 2;
            $ops[] = sprintf('%s %s w', $bc, self::num($border));
            if ($style === 'D') {
                $ops[] = '[3] 0 d';
            }
            $ops[] = $style === 'U'
                ? sprintf('0 %s m %s %s l S', self::num($half), self::num($w), self::num($half))
                : sprintf('%s %s %s %s re S', self::num($half), self::num($half), self::num($w - $border), self::num($h - $border));
        }

        return $ops;
    }

    private function borderWidth(PdfDictionary $widget, ?PdfDictionary $mk): float
    {
        $bs = $this->graph->dict($widget->get('BS'));
        $width = $bs !== null ? $this->graph->resolve($bs->get('W')) : null;
        if ($width === null) {
            $legacy = $this->graph->array($widget->get('Border'));
            $width = $legacy !== null && count($legacy) >= 3 ? $this->graph->resolve($legacy[2]) : null;
        }
        if (!is_int($width) && !is_float($width)) {
            $width = $mk?->has('BC') ? 1 : 0;
        }

        return max(0.0, (float) $width);
    }

    private function color(mixed $value, bool $fill): ?string
    {
        $components = $this->graph->array($value);
        if ($components === null) {
            return null;
        }
        $components = array_map(fn ($c) => $this->graph->resolve($c), $components);
        if (array_filter($components, static fn ($c) => is_int($c) || is_float($c)) !== $components) {
            return null;
        }
        $operator = match (count($components)) {
            1 => 'g',
            3 => 'rg',
            4 => 'k',
            default => null,
        };
        if ($operator === null) {
            return null;
        }

        return implode(' ', array_map(fn ($c) => self::num((float) $c), $components)).' '.($fill ? $operator : strtoupper($operator));
    }

    /**
     * Width, height and matrix of the appearance box: `/MK /R` rotates the
     * content, so a 90° field lays its text along the rectangle's height.
     *
     * @return array{float,float,list<float>|null}|null
     */
    private function box(PdfDictionary $widget): ?array
    {
        $rect = $this->graph->array($widget->get('Rect'));
        if ($rect === null || count($rect) !== 4) {
            return null;
        }
        $rect = array_map(fn ($v) => $this->graph->resolve($v), $rect);
        if (array_filter($rect, static fn ($v) => is_int($v) || is_float($v)) !== $rect) {
            return null;
        }
        $w = abs((float) $rect[2] - (float) $rect[0]);
        $h = abs((float) $rect[3] - (float) $rect[1]);
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        $rotation = $this->graph->resolve($this->graph->dict($widget->get('MK'))?->get('R'));
        $rotation = is_int($rotation) ? (($rotation % 360) + 360) % 360 : 0;

        return match ($rotation) {
            90 => [$h, $w, [0.0, 1.0, -1.0, 0.0, $w, 0.0]],
            180 => [$w, $h, [-1.0, 0.0, 0.0, -1.0, $w, $h]],
            270 => [$h, $w, [0.0, -1.0, 1.0, 0.0, 0.0, $h]],
            default => [$w, $h, null],
        };
    }

    /**
     * @param list<float>|null    $matrix
     * @param array<string,mixed> $resources
     */
    private function formXObject(float $w, float $h, ?array $matrix, string $content, array $resources): PdfReference
    {
        $items = [
            'Type' => new PdfName('XObject'),
            'Subtype' => new PdfName('Form'),
            'BBox' => [0.0, 0.0, $w, $h],
            'Resources' => new PdfDictionary($resources),
        ];
        if ($matrix !== null) {
            $items['Matrix'] = $matrix;
        }

        return $this->graph->allocateStream(new PdfDictionary($items), $content);
    }

    private static function num(float $v): string
    {
        return FormGraph::number($v, 3);
    }
}
