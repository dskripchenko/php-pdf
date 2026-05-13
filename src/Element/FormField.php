<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Phase 43: AcroForm interactive form field.
 *
 * Минимальная подмножка PDF AcroForm для типичных бизнес use cases:
 *  - Text input (single-line)
 *  - Checkbox
 *
 * Не реализовано:
 *  - Multi-line text (textarea)
 *  - Radio button groups
 *  - Combo box / list box
 *  - Signature fields
 *  - Calculation/validation actions (JavaScript)
 *  - Appearance streams (полагаемся на reader default rendering)
 *
 * Field name должен быть уникальным внутри document'а (PDF spec
 * требование для form data submission).
 */
final readonly class FormField implements BlockElement
{
    public const TYPE_TEXT = 'text';

    public const TYPE_CHECKBOX = 'checkbox';

    public function __construct(
        public string $name,
        public string $type = self::TYPE_TEXT,
        public string $defaultValue = '',
        public float $widthPt = 200.0,
        public float $heightPt = 20.0,
        public float $spaceBeforePt = 4.0,
        public float $spaceAfterPt = 4.0,
        public ?string $tooltip = null,
        public bool $required = false,
        public bool $readOnly = false,
    ) {
        if ($type !== self::TYPE_TEXT && $type !== self::TYPE_CHECKBOX) {
            throw new \InvalidArgumentException("Unsupported FormField type: $type");
        }
    }
}
