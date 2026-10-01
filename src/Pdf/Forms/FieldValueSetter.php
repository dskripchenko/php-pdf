<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Forms\Appearance\AppearanceBuilder;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;

/**
 * Applies a value to one field of the editable graph — `/V` (and `/I` for a
 * list box) on the field, `/AS` on button widgets, and a regenerated `/AP` on
 * text and choice widgets so the value shows in any viewer, not only those
 * that honour `/NeedAppearances`.
 *
 * @internal
 */
final class FieldValueSetter
{
    private const TRUTHY = ['on', 'yes', 'true', '1'];
    private const FALSY = ['off', 'no', 'false', '0', ''];

    public function __construct(
        private readonly FormGraph $graph,
        private readonly AppearanceBuilder $appearances,
        private readonly ?PdfDictionary $acroForm,
        private readonly ?PdfDictionary $dr,
    ) {
    }

    /** @param string|bool|list<string> $value */
    public function apply(FieldNode $node, string|bool|array $value): void
    {
        match ($node->type) {
            'checkbox' => $this->applyCheckbox($node, $value),
            'radio' => $this->applyRadio($node, $value),
            'combo', 'list' => $this->applyChoice($node, $value),
            'text', 'text-multiline' => $this->applyText($node, $value),
            default => throw new \LogicException(sprintf('Field "%s" is a %s field and cannot be given a value.', $node->name, $node->type)),
        };
    }

    /** @param string|bool|list<string> $value */
    private function applyText(FieldNode $node, string|bool|array $value): void
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Field "%s" takes a string value.', $node->name));
        }
        if ($node->type === 'text') {
            $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        }
        if ($node->maxLength !== null && mb_strlen($value, 'UTF-8') > $node->maxLength) {
            throw new \InvalidArgumentException(sprintf(
                'Field "%s" accepts at most %d characters, got %d.',
                $node->name,
                $node->maxLength,
                mb_strlen($value, 'UTF-8'),
            ));
        }
        $this->graph->update($this->graph->id($node->fieldObjNum), ['V' => TextString::encode($value)]);
        $this->refreshAppearances($node);
    }

    /** @param string|bool|list<string> $value */
    private function applyChoice(FieldNode $node, string|bool|array $value): void
    {
        if (is_bool($value)) {
            throw new \InvalidArgumentException(sprintf('Field "%s" takes a string value.', $node->name));
        }
        $values = is_array($value) ? array_values($value) : [$value];
        if (count($values) > 1 && ($node->type !== 'list' || !$node->has(FieldAttributes::FF_MULTI_SELECT))) {
            throw new \InvalidArgumentException(sprintf('Field "%s" does not allow multiple selections.', $node->name));
        }

        $indices = [];
        $normalized = [];
        $editable = $node->type === 'combo' && $node->has(FieldAttributes::FF_EDIT);
        foreach ($values as $v) {
            if (!is_string($v)) {
                throw new \InvalidArgumentException(sprintf('Field "%s" takes string values.', $node->name));
            }
            $index = array_search($v, $node->options, true);
            if ($index === false) {
                // Accept the displayed label too — that is what users see.
                $export = array_search($v, $node->optionLabels, true);
                $index = $export !== false ? array_search((string) $export, $node->options, true) : false;
                $v = $export !== false ? (string) $export : $v;
            }
            if ($index === false && !$editable && $node->options !== []) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" has no option "%s" (options: %s).',
                    $node->name,
                    $v,
                    implode(', ', $node->options),
                ));
            }
            if ($index !== false) {
                $indices[] = (int) $index;
            }
            $normalized[] = $v;
        }

        $changes = [
            'V' => count($normalized) === 1 ? TextString::encode($normalized[0]) : array_map(TextString::encode(...), $normalized),
        ];
        if ($node->type === 'list') {
            sort($indices);
            $changes['I'] = $indices !== [] ? $indices : null;
        }
        $this->graph->update($this->graph->id($node->fieldObjNum), $changes);
        $this->refreshAppearances($node);
    }

    /** @param string|bool|list<string> $value */
    private function applyCheckbox(FieldNode $node, string|bool|array $value): void
    {
        if (is_array($value)) {
            throw new \InvalidArgumentException(sprintf('Field "%s" takes a single value.', $node->name));
        }
        $export = null;
        if (is_bool($value)) {
            $export = $value ? ($node->options[0] ?? 'Yes') : null;
        } elseif (in_array($value, $node->options, true)) {
            $export = $value;
        } elseif (in_array(strtolower($value), self::TRUTHY, true)) {
            $export = $node->options[0] ?? 'Yes';
        } elseif (!in_array(strtolower($value), self::FALSY, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Field "%s" is a check box: pass true/false, "Off" or its export value (%s).',
                $node->name,
                implode(', ', $node->options),
            ));
        }
        $this->applyButtonState($node, $export);
    }

    /** @param string|bool|list<string> $value */
    private function applyRadio(FieldNode $node, string|bool|array $value): void
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Field "%s" takes one of its option names.', $node->name));
        }
        $export = null;
        if (!in_array(strtolower($value), ['off', ''], true)) {
            foreach ($node->options as $option) {
                if (strcasecmp($option, $value) === 0) {
                    $export = $option;
                    break;
                }
            }
            if ($export === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" has no option "%s" (options: %s).',
                    $node->name,
                    $value,
                    implode(', ', $node->options),
                ));
            }
        }
        $this->applyButtonState($node, $export);
    }

    /**
     * Turn on the widget(s) exporting `$export` and every other one off; `/V`
     * is the on-state name (with `/Opt`, the index-named state of the first
     * matching widget).
     */
    private function applyButtonState(FieldNode $node, ?string $export): void
    {
        $state = 'Off';
        foreach ($node->widgetObjNums as $widgetObjNum) {
            $widgetId = $this->graph->id($widgetObjNum);
            $onKey = $this->onKey($widgetId) ?? 'Yes';
            $on = $export !== null && ($node->widgetExports[$widgetObjNum] ?? $onKey) === $export;
            if ($on && $state === 'Off') {
                $state = $onKey;
            }
            $this->graph->update($widgetId, ['AS' => new PdfName($on ? $onKey : 'Off')]);
        }
        $this->graph->update($this->graph->id($node->fieldObjNum), ['V' => new PdfName($state)]);
    }

    private function onKey(int $widgetId): ?string
    {
        $widget = $this->graph->dict($widgetId);
        $n = $this->graph->dict($this->graph->dict($widget?->get('AP'))?->get('N'));
        foreach (array_keys($n?->all() ?? []) as $key) {
            if ($key !== 'Off') {
                return (string) $key;
            }
        }
        $as = $widget?->get('AS');

        return $as instanceof PdfName && $as->value !== 'Off' ? $as->value : null;
    }

    private function refreshAppearances(FieldNode $node): void
    {
        foreach ($node->widgetObjNums as $widgetObjNum) {
            $widgetId = $this->graph->id($widgetObjNum);
            $widget = $this->graph->dict($widgetId);
            if ($widget === null) {
                continue;
            }
            $attributes = FieldAttributes::forWidget($this->graph, $widgetId, $this->acroForm);
            $ap = $this->appearances->variableText($attributes, $widget, $this->dr, $node->name);
            $this->graph->update($widgetId, ['AP' => $ap !== null ? new PdfDictionary(['N' => $ap]) : null]);
        }
    }
}
