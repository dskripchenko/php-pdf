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
     *                         or the raw `/FT` name (`Btn`, `Ch`, `Sig`, …)
     *                         for field types without a more specific mapping.
     * @param  list<string>  $options  On-state export names for checkbox/radio,
     *                                  read from the widget(s)' own `/AP/N` keys.
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $value,
        public array $options,
        public bool $required,
        public bool $readOnly,
    ) {
    }
}
