<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

/**
 * A resolved terminal AcroForm field found while walking a source document's
 * `/AcroForm /Fields` tree — carries the source object numbers needed to
 * later locate and mutate the field once it has been copied by an
 * {@see \Dskripchenko\PhpPdf\Pdf\Merge\ObjectImporter}.
 */
final readonly class FieldNode
{
    /**
     * @param  list<int>  $widgetObjNums  Source object numbers of the field's
     *                                     own widget annotation(s) — a single
     *                                     entry for a plain field, or one per
     *                                     option for a radio group.
     */
    public function __construct(
        public string $name,
        public string $type,
        public int $fieldObjNum,
        public array $widgetObjNums,
        public ?int $parentObjNum,
        public ?string $da,
        public ?string $tu,
        public string $value,
        /** @var list<string> */
        public array $options,
        public bool $required,
        public bool $readOnly,
    ) {
    }
}
