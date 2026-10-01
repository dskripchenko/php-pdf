<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Font\FontProvider;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\Forms\Appearance\AppearanceBuilder;
use Dskripchenko\PhpPdf\Pdf\Forms\Appearance\FontResolver;
use Dskripchenko\PhpPdf\Pdf\Merge\MergeSerializer;
use Dskripchenko\PhpPdf\Pdf\Merge\ObjectImporter;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfSource;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderPage;

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
 * Filled text and choice fields get a generated appearance stream, so values
 * show in every viewer. Text the form's own fonts cannot show (Cyrillic, CJK,
 * …) is drawn with an embedded TrueType font: pass one to {@see useFont()},
 * or install `dskripchenko/php-pdf-fonts-liberation` to have Liberation used
 * automatically.
 *
 * The output is a full rewrite, unencrypted: existing digital signatures no
 * longer verify, and an XFA form definition is dropped once anything is
 * changed (viewers would otherwise show the XFA layer instead of the values).
 *
 * ```php
 * $bytes = ExistingFormFiller::fromFile('template.pdf')
 *     ->setValue('full_name', 'Jane Roe')
 *     ->setValue('subscribe', true)
 *     ->flatten()
 *     ->toBytes();
 * ```
 */
final class ExistingFormFiller
{
    private readonly PdfSource $source;

    /** @var array<string, FieldNode>|null */
    private ?array $fieldTree = null;

    private ?FormGraph $graph = null;

    private ?PageEditor $pageEditor = null;

    private ?FontResolver $fontResolver = null;

    private ?AppearanceBuilder $appearanceBuilder = null;

    private ?int $catalogId = null;

    /** New id of the (indirect) `/AcroForm`, or null when there is none. */
    private ?int $acroFormId = null;

    private bool $mutated = false;

    private ?TtfFile $font = null;

    private ?FontProvider $fontProvider = null;

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
     * The TrueType font for values the form's own fonts cannot show. Call it
     * before setting values.
     */
    public function useFont(TtfFile|string $font): self
    {
        $this->assertNotStarted(__FUNCTION__);
        $this->font = is_string($font) ? TtfFile::fromFile($font) : $font;

        return $this;
    }

    /**
     * Resolve the fallback font by family instead (`LiberationSans-Regular`,
     * `LiberationSerif-Bold`, … following the field's own font style).
     */
    public function useFontProvider(FontProvider $provider): self
    {
        $this->assertNotStarted(__FUNCTION__);
        $this->fontProvider = $provider;

        return $this;
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
                required: $node->has(FieldAttributes::FF_REQUIRED),
                readOnly: $node->has(FieldAttributes::FF_READ_ONLY),
                tooltip: $node->tooltip,
                maxLength: $node->maxLength,
                optionLabels: $node->optionLabels,
            );
        }

        return $out;
    }

    public function pageCount(): int
    {
        return count($this->document()->pages());
    }

    /**
     * The size of a 0-based page as a viewer shows it (CropBox, after
     * `/Rotate`) — the coordinate space of {@see stampImage()}.
     *
     * @return array{width: float, height: float}
     */
    public function pageSize(int $pageIndex): array
    {
        return PageEditor::displaySize($this->page($pageIndex));
    }

    /**
     * Set one field's value by its fully-qualified dotted name (see
     * {@see fields()}).
     *
     *  - text: a string (`/MaxLen` is enforced; a single-line field turns line
     *    breaks into spaces);
     *  - check box: `true`/`false`, `on`/`yes`/`true`/`1`, `off`/`no`/`false`/
     *    `0`/`''`, or its export value;
     *  - radio group: one of its options (case-insensitive), or `Off`;
     *  - combo / list box: an option's export value or its displayed label
     *    (a list of them for a multi-select list box; an editable combo box
     *    takes any text).
     *
     * @param string|bool|list<string> $value
     */
    public function setValue(string $name, string|bool|array $value): self
    {
        $node = $this->tree()[$name] ?? null;
        if ($node === null) {
            throw new \InvalidArgumentException("Unknown field: {$name}");
        }

        (new FieldValueSetter($this->graph(), $this->appearances(), $this->acroForm(), $this->dr()))->apply($node, $value);
        $this->markMutated();

        return $this;
    }

    /** @param array<string,string|bool|list<string>> $values */
    public function setValues(array $values): self
    {
        foreach ($values as $name => $value) {
            $this->setValue((string) $name, $value);
        }

        return $this;
    }

    /**
     * Bake the given fields into static page content and remove them from the
     * form. `null` (default) flattens every field — every widget annotation of
     * the document — and drops `/AcroForm` altogether.
     *
     * @param  list<string>|null  $fieldNames
     */
    public function flatten(?array $fieldNames = null): self
    {
        $tree = $this->tree();
        $nodes = [];
        foreach ($fieldNames ?? [] as $name) {
            $nodes[] = $tree[$name] ?? throw new \InvalidArgumentException("Unknown field: {$name}");
        }

        $acroForm = $this->acroForm();
        $flattener = new FormFlattener(
            $this->graph(),
            $this->pages(),
            $this->appearances(),
            $acroForm,
            $this->dr(),
            $acroForm?->get('NeedAppearances') === true,
        );

        if ($fieldNames === null) {
            $flattener->flattenAll();
            $this->dropAcroForm();
        } else {
            $flattener->flattenFields($nodes);
            foreach ($nodes as $node) {
                $this->detachField($node);
            }
            $fields = $this->graph()->array($this->acroForm()?->get('Fields'));
            if ($fields === []) {
                $this->dropAcroForm();
            }
        }
        $this->markMutated();

        return $this;
    }

    /**
     * Place a PNG/JPEG image (with alpha preserved for PNG) onto the given
     * 0-based page. `$x,$y` is the image's top-left corner in points from the
     * top-left of the page as displayed (see {@see pageSize()}). Independent
     * of any AcroForm field — composes with {@see setValue()} and
     * {@see flatten()} on the same page.
     */
    public function stampImage(int $pageIndex, string $imagePath, float $x, float $y, float $width, float $height): self
    {
        $bytes = @file_get_contents($imagePath);
        if ($bytes === false) {
            throw new \RuntimeException("Cannot read image file: {$imagePath}");
        }

        return $this->stampImageBytes($pageIndex, $bytes, $x, $y, $width, $height);
    }

    public function stampImageBytes(int $pageIndex, string $bytes, float $x, float $y, float $width, float $height): self
    {
        $page = $this->page($pageIndex);
        (new ImageStamper($this->graph(), $this->pages()))->stamp($page, PdfImage::fromBytes($bytes), $x, $y, $width, $height);

        return $this;
    }

    public function toBytes(): string
    {
        $graph = $this->graph();
        $this->fonts()->embed();

        $trailer = [];
        $info = $this->document()->trailer()->get('Info');
        if ($info !== null) {
            $trailer['Info'] = $graph->importer->importValue($info);
        }
        // Keep the permanent identifier; a changed file gets a new second one (§14.4).
        $id = $this->document()->deref($this->document()->trailer()->get('ID'));
        $first = is_array($id) && ($id[0] ?? null) instanceof PdfString ? $id[0]->bytes : random_bytes(16);
        $second = is_array($id) && ($id[1] ?? null) instanceof PdfString ? $id[1]->bytes : $first;
        $trailer['ID'] = [new PdfString($first), new PdfString($this->mutated ? random_bytes(16) : $second)];

        return (new MergeSerializer())->serialize($graph->importer->objects(), $this->catalogId(), $trailer);
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

    private function page(int $pageIndex): ReaderPage
    {
        $pages = $this->document()->pages();
        if ($pageIndex < 0 || $pageIndex >= count($pages)) {
            throw new \OutOfRangeException("Page {$pageIndex} does not exist (document has ".count($pages).' pages)');
        }

        return $pages[$pageIndex];
    }

    private function graph(): FormGraph
    {
        if ($this->graph !== null) {
            return $this->graph;
        }

        $doc = $this->document();
        $rootRef = $doc->trailer()->get('Root');
        if (!$rootRef instanceof PdfReference) {
            throw new \RuntimeException('Document catalog (/Root) is not an indirect reference');
        }

        $importer = new ObjectImporter();
        $importer->useSource($doc);
        $graph = new FormGraph($importer, $doc);
        $this->catalogId = $graph->id($rootRef->number);

        // Keep /AcroForm indirect so it can be edited in one place.
        $acroForm = $graph->dict($this->catalogId)?->get('AcroForm');
        if ($acroForm instanceof PdfReference) {
            $this->acroFormId = $acroForm->number;
        } elseif ($acroForm instanceof PdfDictionary) {
            $ref = $graph->allocate($acroForm);
            $graph->update($this->catalogId, ['AcroForm' => $ref]);
            $this->acroFormId = $ref->number;
        }

        return $this->graph = $graph;
    }

    private function pages(): PageEditor
    {
        return $this->pageEditor ??= new PageEditor($this->graph());
    }

    private function fonts(): FontResolver
    {
        return $this->fontResolver ??= new FontResolver($this->graph(), $this->font, $this->fontProvider);
    }

    private function appearances(): AppearanceBuilder
    {
        return $this->appearanceBuilder ??= new AppearanceBuilder($this->graph(), $this->fonts());
    }

    private function acroForm(): ?PdfDictionary
    {
        $graph = $this->graph();

        return $this->acroFormId !== null ? $graph->dict($this->acroFormId) : null;
    }

    private function dr(): ?PdfDictionary
    {
        return $this->graph()->dict($this->acroForm()?->get('DR'));
    }

    /**
     * Once values change, an XFA definition (§12.7.8) would make XFA-aware
     * viewers show stale data — drop it so the AcroForm is authoritative.
     */
    private function markMutated(): void
    {
        $this->mutated = true;
        if ($this->acroFormId !== null && $this->acroForm()?->has('XFA')) {
            $this->graph()->update($this->acroFormId, ['XFA' => null]);
        }
        if ($this->graph()->dict($this->catalogId())?->has('NeedsRendering')) {
            $this->graph()->update($this->catalogId(), ['NeedsRendering' => null]);
        }
    }

    /**
     * Remove a flattened field from the field hierarchy, pruning parents left
     * without kids all the way up to `/AcroForm /Fields`.
     */
    private function detachField(FieldNode $node): void
    {
        $graph = $this->graph();
        $current = $graph->id($node->fieldObjNum);
        if ($this->acroFormId !== null) {
            $graph->removeFromArray($this->acroFormId, 'CO', $current);
        }
        foreach ($node->ancestorObjNums as $ancestorObjNum) {
            $parentId = $graph->id($ancestorObjNum);
            $left = $graph->removeFromArray($parentId, 'Kids', $current);
            if ($left !== 0) {
                return;
            }
            $current = $parentId;
        }
        if ($this->acroFormId !== null) {
            $graph->removeFromArray($this->acroFormId, 'Fields', $current);
        }
    }

    private function dropAcroForm(): void
    {
        if ($this->acroFormId === null) {
            return;
        }
        $this->graph()->update($this->catalogId(), ['AcroForm' => null, 'NeedsRendering' => null]);
        $this->acroFormId = null;
    }

    private function assertNotStarted(string $method): void
    {
        if ($this->fontResolver !== null) {
            throw new \LogicException("{$method}() must be called before any value is set.");
        }
    }

    private function catalogId(): int
    {
        $this->graph();

        return $this->catalogId ?? throw new \LogicException('Catalog was not imported');
    }
}
