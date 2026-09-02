<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\Merge\MergeSerializer;
use Dskripchenko\PhpPdf\Pdf\Merge\ObjectImporter;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfSource;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;
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

    /**
     * Bake the current value of the given fields into their page content and
     * remove their interactive widgets. `null` (default) flattens every
     * field. When flattening leaves no fields behind, `/AcroForm` is dropped
     * from the output catalog entirely.
     *
     * @param  list<string>|null  $fieldNames
     */
    public function flatten(?array $fieldNames = null): self
    {
        $tree = $this->tree();
        $names = $fieldNames ?? array_keys($tree);
        $importer = $this->importer();
        $doc = $this->document();
        $flattener = new FormFlattener();
        // Both depend only on the fixed /AcroForm object, not on the field
        // being flattened — compute them once rather than per field.
        $dr = $this->importedDr($importer);
        $acroFormDa = $this->acroFormLevelDa($doc);

        foreach ($names as $name) {
            $node = $tree[$name] ?? null;
            if ($node === null) {
                throw new \InvalidArgumentException("Unknown field: {$name}");
            }
            $flattener->flatten($importer, $doc, $node, $dr, $acroFormDa);
            $this->removeFromAcroForm($importer, $node);
        }

        $this->dropAcroFormIfEmpty($importer);

        return $this;
    }

    /**
     * Place a PNG/JPEG image (with alpha preserved for PNG) onto the given
     * 0-based page, at `$x,$y,$w,$h` in top-left-origin points. Independent
     * of any AcroForm field — composes with {@see setValue()} and
     * {@see flatten()} on the same page.
     */
    public function stampImage(int $pageIndex, string $imagePath, float $x, float $y, float $width, float $height): self
    {
        return $this->stampImageBytes($pageIndex, (string) file_get_contents($imagePath), $x, $y, $width, $height);
    }

    public function stampImageBytes(int $pageIndex, string $bytes, float $x, float $y, float $width, float $height): self
    {
        $doc = $this->document();
        $pages = $doc->pages();
        if ($pageIndex < 0 || $pageIndex >= count($pages)) {
            throw new \OutOfRangeException("Page {$pageIndex} does not exist (document has " . count($pages) . ' pages)');
        }
        $sourcePage = $pages[$pageIndex];

        $importer = $this->importer();
        $pageId = $importer->importObject($sourcePage->objectNumber)->number;
        $image = PdfImage::fromBytes($bytes);

        (new ImageStamper())->stamp($importer, $pageId, $image, $x, $y, $width, $height, $sourcePage->height());

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

    private function importedDr(ObjectImporter $importer): ?PdfDictionary
    {
        if ($this->acroFormObjNum === null) {
            return null;
        }
        $acroForm = $importer->get($importer->importObject($this->acroFormObjNum)->number);
        if (!$acroForm instanceof PdfDictionary) {
            return null;
        }
        $dr = $acroForm->get('DR');
        if ($dr instanceof PdfReference) {
            $dr = $importer->get($dr->number);
        }

        return $dr instanceof PdfDictionary ? $dr : null;
    }

    private function acroFormLevelDa(ReaderDocument $doc): ?string
    {
        if ($this->acroFormObjNum === null) {
            return null;
        }
        $acroForm = $doc->deref(new PdfReference($this->acroFormObjNum, 0));
        if (!$acroForm instanceof PdfDictionary) {
            return null;
        }
        $da = $acroForm->get('DA');

        return $da instanceof PdfString ? $da->bytes : null;
    }

    private function removeFromAcroForm(ObjectImporter $importer, FieldNode $node): void
    {
        $fieldNewId = $importer->importObject($node->fieldObjNum)->number;

        if ($node->parentObjNum !== null) {
            $parentId = $importer->importObject($node->parentObjNum)->number;
            $this->removeRefFromArrayKey($importer, $parentId, 'Kids', $fieldNewId);

            // A non-terminal parent left with no /Kids is a dangling field
            // node — drop it from /AcroForm /Fields too, or dropAcroFormIfEmpty()
            // would never see the AcroForm as empty once every terminal field
            // reachable only through it has been flattened.
            $parentDict = $importer->get($parentId);
            $kids = $parentDict instanceof PdfDictionary ? $parentDict->get('Kids') : null;
            if ($kids instanceof PdfReference) {
                $kids = $importer->get($kids->number);
            }
            if (is_array($kids) && $kids === [] && $this->acroFormObjNum !== null) {
                $acroFormId = $importer->importObject($this->acroFormObjNum)->number;
                $this->removeRefFromArrayKey($importer, $acroFormId, 'Fields', $parentId);
            }

            return;
        }

        if ($this->acroFormObjNum === null) {
            return;
        }
        $acroFormId = $importer->importObject($this->acroFormObjNum)->number;
        $this->removeRefFromArrayKey($importer, $acroFormId, 'Fields', $fieldNewId);
    }

    private function removeRefFromArrayKey(ObjectImporter $importer, int $objId, string $key, int $targetNewId): void
    {
        $dict = $importer->get($objId);
        if (!$dict instanceof PdfDictionary) {
            return;
        }
        $arr = $dict->get($key);

        // The array itself may be an indirect object (ISO 32000-1 allows any
        // value to be indirect) rather than inlined into the dictionary.
        $arrObjId = null;
        if ($arr instanceof PdfReference) {
            $arrObjId = $arr->number;
            $arr = $importer->get($arrObjId);
        }
        if (!is_array($arr)) {
            return;
        }

        $filtered = array_values(array_filter(
            $arr,
            static fn ($ref) => !($ref instanceof PdfReference && $ref->number === $targetNewId),
        ));

        if ($arrObjId !== null) {
            $importer->set($arrObjId, $filtered);

            return;
        }

        $items = $dict->all();
        $items[$key] = $filtered;
        $importer->set($objId, new PdfDictionary($items));
    }

    private function dropAcroFormIfEmpty(ObjectImporter $importer): void
    {
        if ($this->acroFormObjNum === null) {
            return;
        }
        $acroFormId = $importer->importObject($this->acroFormObjNum)->number;
        $acroForm = $importer->get($acroFormId);
        if (!$acroForm instanceof PdfDictionary) {
            return;
        }
        $fields = $acroForm->get('Fields');
        if ($fields instanceof PdfReference) {
            $fields = $importer->get($fields->number);
        }
        if (!is_array($fields) || $fields !== []) {
            return;
        }

        $catalog = $importer->get($this->catalogId());
        if ($catalog instanceof PdfDictionary) {
            $items = $catalog->all();
            unset($items['AcroForm']);
            $importer->set($this->catalogId(), new PdfDictionary($items));
        }
        $this->acroFormObjNum = null;
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
