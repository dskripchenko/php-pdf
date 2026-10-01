<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

/**
 * A resolved terminal AcroForm field found while walking a source document's
 * `/AcroForm /Fields` tree — carries the source object numbers needed to
 * later locate and mutate the field once it has been copied by an
 * {@see \Dskripchenko\PhpPdf\Pdf\Merge\ObjectImporter}.
 *
 * @internal
 */
final readonly class FieldNode
{
    /**
     * @param list<int>          $widgetObjNums   the field's widget annotation(s): one
     *                                            for a plain field, one per option for
     *                                            a radio group, one per page for a
     *                                            repeated field
     * @param list<int>          $ancestorObjNums non-terminal parents, nearest first
     * @param string|list<string> $value
     * @param list<string>       $options         export values (button or choice)
     * @param array<string,string> $optionLabels  choice export value → display text
     * @param array<int,string>  $widgetExports   button widget → its export value
     */
    public function __construct(
        public string $name,
        public string $type,
        public int $fieldObjNum,
        public array $widgetObjNums,
        public array $ancestorObjNums,
        public ?string $tooltip,
        public string|array $value,
        public array $options,
        public array $optionLabels,
        public array $widgetExports,
        public int $flags,
        public ?int $maxLength,
    ) {
    }

    public function has(int $flag): bool
    {
        return ($this->flags & $flag) !== 0;
    }
}
