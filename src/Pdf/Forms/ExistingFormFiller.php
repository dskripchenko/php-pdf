<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Merge\PdfSource;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;

/**
 * Opens an already-existing PDF and fills in the values of its own AcroForm
 * fields by name — as opposed to {@see \Dskripchenko\PhpPdf\Element\FormField},
 * which authors a brand-new form from scratch.
 *
 * The source document is never mutated: every operation works on a deep copy
 * (via {@see \Dskripchenko\PhpPdf\Pdf\Merge\ObjectImporter}, the same generic
 * object-graph copier {@see \Dskripchenko\PhpPdf\Pdf\Merge\PdfMerger} uses for
 * pages) of the *entire* document graph, so anything not touched — outlines,
 * metadata, other annotations — survives untouched into the output.
 *
 * ```php
 * $bytes = ExistingFormFiller::fromFile('template.pdf')
 *     ->setValue('full_name', 'Jane Roe')
 *     ->setValue('subscribe', 'Yes')
 *     ->toBytes();
 * ```
 */
final class ExistingFormFiller
{
    private readonly PdfSource $source;

    /** @var array<string, FieldNode>|null */
    private ?array $fieldTree = null;

    private function __construct(PdfSource $source)
    {
        $this->source = $source;
    }

    public static function fromFile(string $path, string $password = ''): self
    {
        return new self(PdfSource::fromFile($path, $password));
    }

    public static function fromBytes(string $bytes, string $password = ''): self
    {
        return new self(PdfSource::fromBytes($bytes, $password));
    }

    /**
     * Enumerate the source document's AcroForm fields by fully-qualified
     * name. Always reflects the original source, not pending edits.
     *
     * @return array<string, FieldInfo>
     */
    public function fields(): array
    {
        $out = [];
        foreach ($this->tree() as $name => $node) {
            $out[$name] = new FieldInfo(
                name: $node->name,
                type: $node->type,
                value: $node->value,
                options: $node->options,
                required: $node->required,
                readOnly: $node->readOnly,
            );
        }

        return $out;
    }

    /** @return array<string, FieldNode> */
    private function tree(): array
    {
        return $this->fieldTree ??= (new FieldTree())->build($this->document());
    }

    private function document(): ReaderDocument
    {
        return $this->source->document();
    }
}
