<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;

/**
 * The effective attributes of one widget's field, read from the editable graph:
 * inheritable entries (§12.7.3.1 Table 220, §12.7.4) are taken from the nearest
 * ancestor that sets them, then from the AcroForm (`/DA`, `/Q`, `/DR`).
 *
 * @internal
 */
final readonly class FieldAttributes
{
    public const FF_READ_ONLY = 1;
    public const FF_REQUIRED = 1 << 1;
    public const FF_MULTILINE = 1 << 12;
    public const FF_PASSWORD = 1 << 13;
    public const FF_NO_TOGGLE_TO_OFF = 1 << 14;
    public const FF_RADIO = 1 << 15;
    public const FF_PUSHBUTTON = 1 << 16;
    public const FF_COMBO = 1 << 17;
    public const FF_EDIT = 1 << 18;
    public const FF_MULTI_SELECT = 1 << 21;
    public const FF_COMB = 1 << 24;

    /**
     * @param list<array{string,string}> $options choice options as [export, display]
     * @param list<string>|string|null   $value
     */
    public function __construct(
        public int $widgetId,
        public int $fieldId,
        public ?string $ft,
        public int $flags,
        public ?string $da,
        public int $quadding,
        public ?int $maxLength,
        public array|string|null $value,
        public array $options,
        public int $topIndex,
    ) {
    }

    public static function forWidget(FormGraph $graph, int $widgetId, ?PdfDictionary $acroForm): self
    {
        $found = [];
        $fieldId = null;
        $id = $widgetId;
        for ($depth = 0; $id !== null && $depth < 64; $depth++) {
            $dict = $graph->dict($id);
            if ($dict === null) {
                break;
            }
            if ($fieldId === null && ($dict->has('T') || $dict->has('FT') || !$dict->has('Parent'))) {
                $fieldId = $id;
            }
            foreach (['FT', 'Ff', 'DA', 'Q', 'MaxLen', 'V', 'Opt', 'TI'] as $key) {
                if (!array_key_exists($key, $found) && $dict->has($key)) {
                    $found[$key] = $graph->resolve($dict->get($key));
                }
            }
            $parent = $dict->get('Parent');
            $id = $parent instanceof PdfReference ? $parent->number : null;
        }

        foreach (['DA', 'Q'] as $key) {
            if (!array_key_exists($key, $found) && $acroForm?->has($key)) {
                $found[$key] = $graph->resolve($acroForm->get($key));
            }
        }

        $value = $found['V'] ?? null;
        $value = match (true) {
            is_array($value) => array_values(array_filter(array_map(
                static fn ($v) => TextString::decode($graph->resolve($v)),
                $value,
            ), 'is_string')),
            default => TextString::decode($value),
        };

        $options = [];
        foreach ($graph->array($found['Opt'] ?? null) ?? [] as $opt) {
            $opt = $graph->resolve($opt);
            if (is_array($opt) && count($opt) >= 2) {
                $export = TextString::decode($graph->resolve($opt[0])) ?? '';
                $options[] = [$export, TextString::decode($graph->resolve($opt[1])) ?? $export];
            } elseif (($text = TextString::decode($opt)) !== null) {
                $options[] = [$text, $text];
            }
        }

        return new self(
            widgetId: $widgetId,
            fieldId: $fieldId ?? $widgetId,
            ft: ($found['FT'] ?? null) instanceof PdfName ? $found['FT']->value : null,
            flags: is_int($found['Ff'] ?? null) ? $found['Ff'] : 0,
            da: ($found['DA'] ?? null) instanceof PdfString ? $found['DA']->bytes : null,
            quadding: is_int($found['Q'] ?? null) ? $found['Q'] : 0,
            maxLength: is_int($found['MaxLen'] ?? null) ? $found['MaxLen'] : null,
            value: $value,
            options: $options,
            topIndex: is_int($found['TI'] ?? null) ? $found['TI'] : 0,
        );
    }

    public function has(int $flag): bool
    {
        return ($this->flags & $flag) !== 0;
    }

    /** The value as one display string (a choice shows its option label). */
    public function displayValue(): string
    {
        $value = is_array($this->value) ? ($this->value[0] ?? '') : ($this->value ?? '');
        foreach ($this->options as [$export, $display]) {
            if ($export === $value) {
                return $display;
            }
        }

        return $value;
    }
}
