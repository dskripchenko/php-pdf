<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

/**
 * A read-only snapshot of one AcroForm field in a source document, keyed by
 * its fully-qualified dotted name (ISO 32000-1 §12.7.3.2).
 */
final readonly class FieldInfo
{
    /**
     * @param  string  $type  'text', 'text-multiline', 'checkbox', 'radio',
     *                         'push', 'combo', 'list', 'signature', or the raw
     *                         `/FT` name for anything else.
     * @param  string|list<string>  $value  The current value — a list for a
     *                                        multi-select list box; the on-state
     *                                        name (or `Off`) for a button.
     * @param  list<string>  $options  Accepted values: export names of a check
     *                                  box / radio group, export values of a
     *                                  combo / list box.
     * @param  array<string,string>  $optionLabels  Choice export value → the
     *                                               text the box displays.
     */
    public function __construct(
        public string $name,
        public string $type,
        public string|array $value,
        public array $options,
        public bool $required,
        public bool $readOnly,
        /** The field's `/TU` (alternate field name / tooltip), if any. */
        public ?string $tooltip = null,
        /** `/MaxLen` of a text field, if any. */
        public ?int $maxLength = null,
        public array $optionLabels = [],
    ) {
    }
}
