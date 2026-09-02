<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Merge\MergeSerializer;
use Dskripchenko\PhpPdf\Pdf\Merge\ObjectImporter;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfSource;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
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

    private ?ObjectImporter $importer = null;

    private ?int $catalogId = null;

    /** Source object number of `/AcroForm`, or null when the source has none. */
    private ?int $acroFormObjNum = null;

    private bool $mutated = false;

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

    /**
     * Set one field's value by its fully-qualified dotted name (see
     * {@see fields()}). For a checkbox, any of `on`/`yes`/`true`/`1` (case
     * insensitive) or the exact on-state export name checks it, anything
     * else unchecks it. For a radio group, the value must match one of the
     * group's option export names (case insensitive) to select it.
     */
    public function setValue(string $name, string $value): self
    {
        $node = $this->tree()[$name] ?? null;
        if ($node === null) {
            throw new \InvalidArgumentException("Unknown field: {$name}");
        }

        $importer = $this->importer();
        (new FieldValueSetter())->apply($importer, $node, $value);
        $this->mutated = true;

        return $this;
    }

    /** @param array<string,string> $values */
    public function setValues(array $values): self
    {
        foreach ($values as $name => $value) {
            $this->setValue($name, $value);
        }

        return $this;
    }

    public function toBytes(): string
    {
        $importer = $this->importer();

        if ($this->mutated && $this->acroFormObjNum !== null) {
            $acroFormId = $importer->importObject($this->acroFormObjNum)->number;
            $acroForm = $importer->get($acroFormId);
            if ($acroForm instanceof PdfDictionary) {
                $items = $acroForm->all();
                $items['NeedAppearances'] = true;
                $importer->set($acroFormId, new PdfDictionary($items));
            }
        }

        return (new MergeSerializer())->serialize($importer->objects(), $this->catalogId());
    }

    public function toFile(string $path): int
    {
        $bytes = $this->toBytes();
        $written = @file_put_contents($path, $bytes);
        if ($written === false) {
            throw new \RuntimeException("Cannot write PDF file: {$path}");
        }

        return $written;
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

    private function importer(): ObjectImporter
    {
        if ($this->importer !== null) {
            return $this->importer;
        }

        $doc = $this->document();
        $rootRef = $doc->trailer()->get('Root');
        if (!$rootRef instanceof PdfReference) {
            throw new \RuntimeException('Document catalog (/Root) is not an indirect reference');
        }

        $importer = new ObjectImporter();
        $importer->useSource($doc);
        $this->catalogId = $importer->importObject($rootRef->number)->number;

        $acroFormRaw = $doc->catalog()->get('AcroForm');
        $this->acroFormObjNum = $acroFormRaw instanceof PdfReference ? $acroFormRaw->number : null;

        return $this->importer = $importer;
    }

    private function catalogId(): int
    {
        $this->importer(); // ensure catalogId is populated
        if ($this->catalogId === null) {
            throw new \LogicException('Catalog was not imported');
        }

        return $this->catalogId;
    }
}
