<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

use Dskripchenko\PhpPdf\Style\Orientation;
use Dskripchenko\PhpPdf\Style\PaperSize;

/**
 * High-level PDF document — entry point Phase 1 API.
 *
 * Скрывает low-level Writer/ContentStream детали: client просто
 * добавляет pages, рисует на них, и эмитит bytes:
 *
 *   $doc = Document::new(PaperSize::A4);
 *   $page = $doc->addPage();
 *   $page->showText('Hello, world!', 72, 720, StandardFont::TimesRoman, 12);
 *   $bytes = $doc->toBytes();
 *
 * Document отвечает за:
 *  - Создание Writer и orchestrating его low-level API
 *  - Регистрацию unique fonts/images (deduplication через identity)
 *  - Эмиссию Catalog + Pages tree + per-page Page object + Content stream
 *  - Cross-reference table + trailer + EOF
 *
 * Phase 1 НЕ включает (планируется в более поздних phase'ах):
 *  - Text layout / wrapping / line breaking (Phase 3)
 *  - Headers/footers/watermarks (Phase 8)
 *  - Multi-page paragraphs с auto page break (Phase 3+5)
 *  - Bookmarks/outline (Phase 7)
 */
final class Document
{
    /** @var list<Page> */
    private array $pages = [];

    /**
     * Named destinations для internal-links и bookmarks.
     *
     * @var array<string, array{page: Page, x: float, y: float}>
     */
    private array $namedDestinations = [];

    /**
     * Outline (bookmarks panel) entries в flat list. Tree-структура
     * восстанавливается в toBytes() по level'у.
     *
     * @var list<array{level: int, title: string, page: Page, x: float, y: float}>
     */
    private array $outlineEntries = [];

    private string $pdfVersion = '1.7';

    /**
     * @var array<string, string>  metadata fields (Title, Author, Subject,
     *                              Keywords, Creator, Producer). Values
     *                              shown в PDF reader's "Document Properties"
     *                              dialog. Все optional; emit'ятся в /Info
     *                              dict если хотя бы одно задано.
     */
    private array $metadata = [];

    /**
     * Phase 41: optional encryption config. Если задан — emit /Encrypt
     * в trailer + encrypt все stream content на per-object level.
     */
    private ?Encryption $encryption = null;

    /** @var list<int> Phase 43: form field object IDs (filled во время toBytes). */
    private array $collectedFormFieldIds = [];

    /** @var list<int> Phase 97: field IDs с /AA /C calculate scripts (для AcroForm /CO). */
    private array $calculatedFieldIds = [];

    /** Phase 47: PDF/A-1b configuration. */
    private ?PdfAConfig $pdfA = null;

    /** Phase 48: Tagged PDF mode (accessibility). */
    private bool $tagged = false;

    /**
     * Phase 84: Open action — applied when document is opened.
     * Options:
     *  - 'fit-page' — zoom to fit entire page.
     *  - 'fit-width' — zoom to fit page width.
     *  - 'actual-size' — 100%.
     *  - 'xyz' (default) — explicit x/y/zoom or null = reader default.
     *
     * @var array<string, mixed>|null  Form: ['mode' => string, 'page' => int (1-based), ...].
     */
    private ?array $openAction = null;

    /**
     * Phase 84: Page display mode на open.
     * Options: 'use-none' (default), 'use-outlines', 'use-thumbs',
     *          'use-oc' (optional content), 'full-screen'.
     */
    private ?string $pageMode = null;

    /**
     * Phase 84: Page layout mode.
     * Options: 'single-page' (default), 'one-column', 'two-column-left',
     *          'two-column-right', 'two-page-left', 'two-page-right'.
     */
    private ?string $pageLayout = null;

    /**
     * Phase 88: /ViewerPreferences entries.
     *
     * @var array<string, mixed>
     */
    private array $viewerPreferences = [];

    /** Phase 89: BCP 47 language tag для /Lang в Catalog. */
    private ?string $lang = null;

    /**
     * Phase 93: Custom struct role aliases. Maps non-standard struct type
     * names к standard PDF/UA roles.
     *
     * @var array<string, string>  custom → standard.
     */
    private array $structRoleMap = [];

    /**
     * Phase 93: Configure role map для custom struct types.
     *
     * @param  array<string, string>  $roleMap
     */
    public function setStructRoleMap(array $roleMap): self
    {
        $this->structRoleMap = $roleMap;

        return $this;
    }

    public function setLang(string $lang): self
    {
        $this->lang = $lang;

        return $this;
    }

    /**
     * Phase 88: Configure viewer preferences. Keys: hideToolbar,
     * hideMenubar, hideWindowUI, fitWindow, centerWindow,
     * displayDocTitle (bool); direction ('L2R'|'R2L'); printScaling
     * ('None'|'AppDefault'); duplex ('Simplex'|'DuplexFlipShortEdge'|
     * 'DuplexFlipLongEdge').
     *
     * @param  array<string, mixed>  $prefs
     */
    public function setViewerPreferences(array $prefs): self
    {
        $this->viewerPreferences = $prefs;

        return $this;
    }

    /**
     * Phase 87: Page label ranges per ISO 32000-1 §12.4.2.
     *
     * @var list<array{startPage: int, style?: string, prefix?: string, firstNumber?: int}>
     */
    private array $pageLabelRanges = [];

    /**
     * Phase 87: Configure page label numbering style per page range.
     * Styles: 'decimal' (1, 2, 3), 'upper-roman' (I, II, III),
     * 'lower-roman' (i, ii, iii), 'upper-alpha' (A, B, C),
     * 'lower-alpha' (a, b, c).
     *
     * @param  list<array{startPage: int, style?: string, prefix?: string, firstNumber?: int}>  $ranges
     */
    public function setPageLabels(array $ranges): self
    {
        $this->pageLabelRanges = $ranges;

        return $this;
    }

    /**
     * Phase 84: Set open action — zoom + page on document open.
     */
    public function setOpenAction(string $mode = 'fit-page', int $pageIndex = 1, ?float $x = null, ?float $y = null, ?float $zoom = null): self
    {
        $this->openAction = [
            'mode' => $mode,
            'page' => $pageIndex,
            'x' => $x, 'y' => $y, 'zoom' => $zoom,
        ];

        return $this;
    }

    public function setPageMode(string $mode): self
    {
        $this->pageMode = $mode;

        return $this;
    }

    public function setPageLayout(string $layout): self
    {
        $this->pageLayout = $layout;

        return $this;
    }

    /**
     * Phase 49: Embedded files (attachments).
     *
     * @var list<array{name: string, bytes: string, mimeType: ?string, description: ?string}>
     */
    private array $embeddedFiles = [];

    /**
     * Phase 49: Attach file к PDF. File doesn't show in content; available
     * via reader's attachments panel (Acrobat Reader, Foxit etc.).
     */
    public function attachFile(string $name, string $bytes, ?string $mimeType = null, ?string $description = null): self
    {
        $this->embeddedFiles[] = [
            'name' => $name,
            'bytes' => $bytes,
            'mimeType' => $mimeType,
            'description' => $description,
        ];

        return $this;
    }

    /**
     * @var list<array{type: string, mcid: int, page: Page, altText?: ?string}>
     *   Struct elements collected при rendering — emitted в StructTreeRoot/K.
     */
    private array $structElements = [];

    /**
     * Phase 48: Enable Tagged PDF (accessibility).
     *
     * Adds /MarkInfo /Marked true + /StructTreeRoot к Catalog. Each rendered
     * paragraph wrapped в BDC /P << /MCID N >> ... EMC content marking.
     * Structure tree contains один /Document root → flat list of /P children.
     *
     * NOT full PDF/UA compliance:
     *  - Все блоки tagged как /P (нет H1/H2/Table/Caption distinction).
     *  - Alt text для images / figure не emit'ится.
     *  - Reading order сохраняется но не explicit'но через /StructParents.
     */
    public function enableTagged(): self
    {
        $this->tagged = true;

        return $this;
    }

    public function isTagged(): bool
    {
        return $this->tagged;
    }

    /**
     * @internal Engine использует для registration struct elements.
     */
    public function addStructElement(string $type, int $mcid, Page $page, ?string $altText = null): void
    {
        $this->structElements[] = [
            'type' => $type, 'mcid' => $mcid, 'page' => $page, 'altText' => $altText,
        ];
    }

    /**
     * Phase 47: Enable PDF/A-1b compliance mode.
     */
    public function enablePdfA(PdfAConfig $config): self
    {
        if ($this->encryption !== null) {
            throw new \LogicException('PDF/A-1b disallows encryption — call enablePdfA() перед encrypt() либо не вызывайте encrypt().');
        }
        $this->pdfA = $config;
        $this->pdfVersion = '1.4';

        return $this;
    }

    /**
     * Phase 41-42: enable PDF encryption.
     *
     * RC4-128 (V2 R3) — default, supported widely включая старые readers.
     * AES-128 (V4 R4) — modern, deprecates RC4. Requires openssl ext.
     */
    public function encrypt(
        string $userPassword,
        ?string $ownerPassword = null,
        int $permissions = Encryption::PERM_PRINT | Encryption::PERM_COPY | Encryption::PERM_PRINT_HIGH,
        EncryptionAlgorithm $algorithm = EncryptionAlgorithm::Rc4_128,
    ): self {
        if ($this->pdfA !== null) {
            throw new \LogicException('PDF/A-1b disallows encryption');
        }
        $this->encryption = new Encryption($userPassword, $ownerPassword, $permissions, $algorithm);
        // PDF 1.6 required для AES-128; 1.7 для AES-256 V5.
        if ($algorithm === EncryptionAlgorithm::Aes_128 && version_compare($this->pdfVersion, '1.6', '<')) {
            $this->pdfVersion = '1.6';
        }
        if ($algorithm === EncryptionAlgorithm::Aes_256 && version_compare($this->pdfVersion, '1.7', '<')) {
            $this->pdfVersion = '1.7';
        }

        return $this;
    }

    /**
     * @param  array{0: float, 1: float}|null  $defaultCustomDimensionsPt
     */
    public function __construct(
        public PaperSize $defaultPaperSize = PaperSize::A4,
        public Orientation $defaultOrientation = Orientation::Portrait,
        public ?array $defaultCustomDimensionsPt = null,
        /**
         * Если true — content streams сжимаются через FlateDecode (~3-5×
         * меньше для text-heavy документов). Default = true; set false
         * для debug-просмотра raw streams.
         */
        public bool $compressStreams = true,
    ) {}

    public static function new(
        PaperSize $defaultPaperSize = PaperSize::A4,
        Orientation $defaultOrientation = Orientation::Portrait,
        bool $compressStreams = true,
    ): self {
        return new self(
            defaultPaperSize: $defaultPaperSize,
            defaultOrientation: $defaultOrientation,
            compressStreams: $compressStreams,
        );
    }

    public function pdfVersion(string $version): self
    {
        $this->pdfVersion = $version;

        return $this;
    }

    /**
     * Устанавливает PDF metadata (/Info dict). Все параметры optional.
     * Только заданные fields эмитятся (не-null). CreationDate авто-
     * заполняется если не передан.
     */
    public function metadata(
        ?string $title = null,
        ?string $author = null,
        ?string $subject = null,
        ?string $keywords = null,
        ?string $creator = null,
        ?string $producer = null,
        ?\DateTimeInterface $creationDate = null,
    ): self {
        if ($title !== null) {
            $this->metadata['Title'] = $title;
        }
        if ($author !== null) {
            $this->metadata['Author'] = $author;
        }
        if ($subject !== null) {
            $this->metadata['Subject'] = $subject;
        }
        if ($keywords !== null) {
            $this->metadata['Keywords'] = $keywords;
        }
        if ($creator !== null) {
            $this->metadata['Creator'] = $creator;
        }
        if ($producer !== null) {
            $this->metadata['Producer'] = $producer;
        }
        if ($creationDate !== null) {
            $this->metadata['CreationDate'] = $this->formatPdfDate($creationDate);
        }

        return $this;
    }

    private function formatPdfDate(\DateTimeInterface $dt): string
    {
        // PDF date format: D:YYYYMMDDHHmmSS+TZ'mm'
        return 'D:'.$dt->format('YmdHis').'+00\'00\'';
    }

    /**
     * Добавляет новую page. Если paperSize/orientation не переданы —
     * используется document-level default.
     */
    /**
     * @param  array{0: float, 1: float}|null  $customDimensionsPt
     */
    public function addPage(
        ?PaperSize $paperSize = null,
        ?Orientation $orientation = null,
        ?array $customDimensionsPt = null,
    ): Page {
        $page = new Page(
            $paperSize ?? $this->defaultPaperSize,
            $orientation ?? $this->defaultOrientation,
            $customDimensionsPt ?? $this->defaultCustomDimensionsPt,
        );
        $this->pages[] = $page;

        return $page;
    }

    /**
     * @return list<Page>
     */
    public function pages(): array
    {
        return $this->pages;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /**
     * Регистрирует named destination — позиция (x, y) на $page будет
     * jump target'ом для internal-link'ов с этим именем.
     *
     * Last-write wins: если name уже есть, перезаписывает.
     */
    public function registerDestination(string $name, Page $page, float $x, float $y): self
    {
        $this->namedDestinations[$name] = ['page' => $page, 'x' => $x, 'y' => $y];

        return $this;
    }

    /**
     * @return array<string, array{page: Page, x: float, y: float}>
     */
    public function namedDestinations(): array
    {
        return $this->namedDestinations;
    }

    /**
     * Регистрирует outline entry для bookmarks-panel viewer'а.
     * $level (1..N) определяет вложенность: level 1 = top-level,
     * level 2 = ребёнок последнего level-1, и т.д.
     */
    public function registerOutlineEntry(
        int $level, string $title, Page $page, float $x, float $y,
        ?string $color = null, bool $bold = false, bool $italic = false,
    ): self {
        $this->outlineEntries[] = compact(
            'level', 'title', 'page', 'x', 'y', 'color', 'bold', 'italic',
        );

        return $this;
    }

    /**
     * @return list<array{level: int, title: string, page: Page, x: float, y: float}>
     */
    public function outlineEntries(): array
    {
        return $this->outlineEntries;
    }

    /**
     * Сериализует document в PDF bytes.
     */
    public function toBytes(): string
    {
        if ($this->pages === []) {
            // Empty document — add blank A4 page чтобы PDF был валидным
            // (PDF спецификация требует ≥ 1 page в page tree).
            $this->addPage();
        }

        $writer = new Writer($this->pdfVersion);

        // 1. Резервируем top-level IDs.
        $catalogId = $writer->reserveObject();
        $pagesId = $writer->reserveObject();

        // 2. Регистрируем все unique fonts/images через identity-dedupe.
        //    Standard fonts: один PDF object per unique StandardFont enum.
        //    Embedded fonts: один объект-граф per PdfFont instance.
        //    Images: один XObject per PdfImage instance.

        // Map StandardFont enum → object ID (single registration).
        /** @var array<string, int> */
        $standardFontObjectIds = [];
        foreach ($this->pages as $page) {
            foreach ($page->standardFonts() as $sf) {
                if (! isset($standardFontObjectIds[$sf->value])) {
                    $standardFontObjectIds[$sf->value] = $writer->addObject($sf->pdfDictionary());
                }
            }
        }

        // Embedded PdfFonts — Pdf font dispatches self-registration.
        // PdfFont сам идемпотентен (double registerWith returns same id).
        // Map PdfFont instance → object ID.
        /** @var \SplObjectStorage<PdfFont, int> */
        $embeddedFontObjectIds = new \SplObjectStorage;
        foreach ($this->pages as $page) {
            foreach ($page->embeddedFonts() as $f) {
                if (! isset($embeddedFontObjectIds[$f])) {
                    $embeddedFontObjectIds[$f] = $f->registerWith($writer, $this->compressStreams);
                }
            }
        }

        // Images. Phase 29: dedup by content hash (not just instance).
        // Same file loaded twice → одна XObject запись.
        /** @var \SplObjectStorage<\Dskripchenko\PhpPdf\Image\PdfImage, int> */
        $imageObjectIds = new \SplObjectStorage;
        /** @var array<string, int> hash → existing object ID */
        $imageHashMap = [];
        foreach ($this->pages as $page) {
            foreach ($page->images() as $img) {
                if (isset($imageObjectIds[$img])) {
                    continue;
                }
                $hash = md5($img->imageData);
                if (isset($imageHashMap[$hash])) {
                    $imageObjectIds[$img] = $imageHashMap[$hash];

                    continue;
                }
                $id = $img->registerWith($writer);
                $imageObjectIds[$img] = $id;
                $imageHashMap[$hash] = $id;
            }
        }

        // 3. Reserve page IDs upfront (needed для internal-link Dest references,
        //    т.к. annotation объекты могут ссылаться на pages до их emission).
        $pageIds = [];
        foreach ($this->pages as $i => $page) {
            $pageIds[$i] = $writer->reserveObject();
        }

        // Карта Page-instance → object ID (для annotation /Dest references).
        $pageObjectIdMap = new \SplObjectStorage;
        foreach ($this->pages as $i => $page) {
            $pageObjectIdMap[$page] = $pageIds[$i];
        }

        // 4. Создаём Page objects + content streams + annotations.
        foreach ($this->pages as $i => $page) {
            $contentStreamBody = $page->buildContentStream();
            if ($this->compressStreams && $contentStreamBody !== '') {
                $compressed = (string) gzcompress($contentStreamBody, 6);
                $contentId = $writer->addObject(sprintf(
                    "<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
                    strlen($compressed),
                    $compressed,
                ));
            } else {
                $contentId = $writer->addObject(sprintf(
                    "<< /Length %d >>\nstream\n%sendstream",
                    strlen($contentStreamBody),
                    $contentStreamBody,
                ));
            }

            // Build Page /Resources dict для использованных fonts/images.
            $resourcesFont = '';
            foreach ($page->standardFonts() as $name => $sf) {
                $resourcesFont .= sprintf(' /%s %d 0 R', $name, $standardFontObjectIds[$sf->value]);
            }
            foreach ($page->embeddedFonts() as $name => $f) {
                $resourcesFont .= sprintf(' /%s %d 0 R', $name, $embeddedFontObjectIds[$f]);
            }
            $resourcesXObj = '';
            foreach ($page->images() as $name => $img) {
                $resourcesXObj .= sprintf(' /%s %d 0 R', $name, $imageObjectIds[$img]);
            }

            // Phase 31: ExtGState objects (opacity и др.). Каждая ExtGState
            // — separate PDF object, referenced from page /Resources.
            $resourcesExtGState = '';
            foreach ($page->extGStates() as $name => $gs) {
                $gsId = $writer->addObject($gs->toDictBody());
                $resourcesExtGState .= sprintf(' /%s %d 0 R', $name, $gsId);
            }

            // Phase 82+90: Pattern objects — emit Function (Type 2 либо
            // Type 3 stitching с sub-functions) + Shading + Pattern.
            $resourcesPattern = '';
            foreach ($page->patterns() as $name => $pattern) {
                $fn = $pattern->shading->function;
                if ($fn instanceof \Dskripchenko\PhpPdf\Pdf\PdfStitchingFunction) {
                    $subIds = [];
                    foreach ($fn->subFunctions as $sub) {
                        $subIds[] = $writer->addObject($sub->toDictBody());
                    }
                    $funcId = $writer->addObject($fn->toDictBody($subIds));
                } else {
                    $funcId = $writer->addObject($fn->toDictBody());
                }
                $shadingId = $writer->addObject($pattern->shading->toDictBody($funcId));
                $patternId = $writer->addObject($pattern->toDictBody($shadingId));
                $resourcesPattern .= sprintf(' /%s %d 0 R', $name, $patternId);
            }

            $resourcesParts = [];
            if ($resourcesFont !== '') {
                $resourcesParts[] = '/Font <<'.$resourcesFont.' >>';
            }
            if ($resourcesXObj !== '') {
                $resourcesParts[] = '/XObject <<'.$resourcesXObj.' >>';
            }
            if ($resourcesExtGState !== '') {
                $resourcesParts[] = '/ExtGState <<'.$resourcesExtGState.' >>';
            }
            if ($resourcesPattern !== '') {
                $resourcesParts[] = '/Pattern <<'.$resourcesPattern.' >>';
            }
            $resourcesDict = $resourcesParts === []
                ? '<< >>'
                : '<< '.implode(' ', $resourcesParts).' >>';

            // Annotations: emit /Annot objects + reference array.
            $annotIds = [];
            foreach ($page->linkAnnotations() as $ann) {
                $rect = sprintf(
                    '[%s %s %s %s]',
                    $this->fmt($ann['x1']), $this->fmt($ann['y1']),
                    $this->fmt($ann['x2']), $this->fmt($ann['y2']),
                );
                if ($ann['kind'] === 'uri') {
                    $body = sprintf(
                        '<< /Type /Annot /Subtype /Link /Rect %s '
                        .'/Border [0 0 0] /A << /S /URI /URI %s >> >>',
                        $rect,
                        $this->pdfString($ann['target']),
                    );
                } else {
                    $body = sprintf(
                        '<< /Type /Annot /Subtype /Link /Rect %s '
                        .'/Border [0 0 0] /Dest %s >>',
                        $rect,
                        $this->pdfNameString($ann['target']),
                    );
                }
                $linkAnnotId = $writer->addObject($body);
                $annotIds[] = $linkAnnotId;

                // Phase 72: tagged PDF — register /Link struct element
                // referencing this annotation through /OBJR.
                if ($this->tagged) {
                    $this->structElements[] = [
                        'type' => 'Link',
                        'mcid' => -1, // sentinel — uses /OBJR instead of /K MCID.
                        'page' => $page,
                        'objr' => $linkAnnotId,
                    ];
                }
            }
            // Phase 43+46+67: AcroForm widgets — emit widget annotations +
            // optional /AA JavaScript actions + collect field object IDs.
            foreach ($page->formFields() as $field) {
                $tooltipPart = $field['tooltip'] !== null
                    ? ' /TU '.$this->pdfString($field['tooltip'])
                    : '';
                $namePart = '/T '.$this->pdfString($field['name']);

                if ($field['type'] === 'radio-group') {
                    [$parentId, $childIds] = $this->emitRadioGroupFields(
                        $writer, $pageIds[$i], $field, $namePart, $tooltipPart,
                    );
                    foreach ($childIds as $cid) {
                        $annotIds[] = $cid;
                    }
                    $this->collectedFormFieldIds[] = $parentId;

                    continue;
                }

                // Phase 67: emit JavaScript action objects + /AA dict ref.
                $aaPart = $this->emitFieldActions($writer, $field);

                $body = $this->buildSimpleFieldObject($field, $pageIds[$i], $namePart, $tooltipPart, $aaPart);
                $fieldId = $writer->addObject($body);
                $annotIds[] = $fieldId;
                $this->collectedFormFieldIds[] = $fieldId;

                // Phase 97: track fields с calculate scripts.
                if (! empty($field['calculateScript'])) {
                    $this->calculatedFieldIds[] = $fieldId;
                }
            }
            $annotsRef = $annotIds === []
                ? ''
                : ' /Annots ['.implode(' ', array_map(fn ($id) => "$id 0 R", $annotIds)).']';

            // Phase 85: page transitions + auto-advance.
            $transRef = '';
            $trans = $page->transition();
            if ($trans !== null) {
                $extras = '';
                if ($trans['dimension'] !== null) {
                    $extras .= ' /Dm /'.$trans['dimension'];
                }
                if ($trans['direction'] !== null) {
                    $extras .= ' /Di '.$trans['direction'];
                }
                $transRef = sprintf(
                    ' /Trans << /Type /Trans /S /%s /D %s%s >>',
                    $trans['style'], $this->fmt($trans['duration']), $extras,
                );
            }
            $durRef = '';
            $dur = $page->autoAdvanceDuration();
            if ($dur !== null) {
                $durRef = ' /Dur '.$this->fmt($dur);
            }

            // Phase 92: /StructParents key linking page к /ParentTree entry.
            $structParentsRef = $this->tagged ? " /StructParents $i" : '';
            // Phase 94: page rotation /Rotate.
            $rotateRef = $page->rotation() !== 0 ? ' /Rotate '.$page->rotation() : '';

            $writer->setObject($pageIds[$i], sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] '
                .'/Contents %d 0 R /Resources %s%s%s%s%s%s >>',
                $pagesId,
                $this->fmt($page->widthPt()),
                $this->fmt($page->heightPt()),
                $contentId,
                $resourcesDict,
                $annotsRef,
                $transRef,
                $durRef,
                $structParentsRef,
                $rotateRef,
            ));
        }

        // Named destinations — emit /Names tree если есть.
        $namesRef = '';
        $namesEntries = []; // parts собираемые для root /Names dict.
        if ($this->namedDestinations !== []) {
            $entries = [];
            $names = array_keys($this->namedDestinations);
            sort($names, SORT_STRING);
            foreach ($names as $name) {
                $dest = $this->namedDestinations[$name];
                $pageObjId = $pageObjectIdMap[$dest['page']];
                $entries[] = sprintf(
                    '%s [%d 0 R /XYZ %s %s 0]',
                    $this->pdfString($name),
                    $pageObjId,
                    $this->fmt($dest['x']),
                    $this->fmt($dest['y']),
                );
            }
            $destsId = $writer->addObject('<< /Names ['.implode(' ', $entries).'] >>');
            $namesEntries[] = "/Dests $destsId 0 R";
        }

        // Phase 49: Embedded files (attachments) — /EmbeddedFiles в Names tree.
        if ($this->embeddedFiles !== []) {
            $efEntries = [];
            // Sort by name для deterministic output.
            $sortedFiles = $this->embeddedFiles;
            usort($sortedFiles, fn ($a, $b) => strcmp($a['name'], $b['name']));
            foreach ($sortedFiles as $file) {
                // Embedded file stream — bytes + Subtype + Params /Size.
                $fileSize = strlen($file['bytes']);
                $efStreamBody = sprintf(
                    '<< /Type /EmbeddedFile%s /Length %d /Params << /Size %d >> >>',
                    $file['mimeType'] !== null ? ' /Subtype /'.$this->sanitizeMimeName($file['mimeType']) : '',
                    $fileSize,
                    $fileSize,
                );
                $efStreamId = $writer->addObject(sprintf(
                    "%s\nstream\n%s\nendstream",
                    $efStreamBody, $file['bytes'],
                ));
                // Filespec dict.
                $descPart = $file['description'] !== null
                    ? ' /Desc '.$this->pdfString($file['description'])
                    : '';
                $filespecId = $writer->addObject(sprintf(
                    '<< /Type /Filespec /F %s /UF %s%s '
                    .'/EF << /F %d 0 R /UF %d 0 R >> >>',
                    $this->pdfString($file['name']),
                    $this->pdfString($file['name']),
                    $descPart,
                    $efStreamId,
                    $efStreamId,
                ));
                $efEntries[] = $this->pdfString($file['name'])." $filespecId 0 R";
            }
            $efNamesId = $writer->addObject('<< /Names ['.implode(' ', $efEntries).'] >>');
            $namesEntries[] = "/EmbeddedFiles $efNamesId 0 R";
        }

        if ($namesEntries !== []) {
            $namesId = $writer->addObject('<< '.implode(' ', $namesEntries).' >>');
            $namesRef = ' /Names '.$namesId.' 0 R';
        }

        // Outline tree — bookmarks panel viewer.
        $outlinesRef = '';
        if ($this->outlineEntries !== []) {
            $outlinesId = $this->emitOutlineTree($writer, $pageObjectIdMap);
            $outlinesRef = ' /Outlines '.$outlinesId.' 0 R /PageMode /UseOutlines';
        }

        // 5. Pages tree (after все pages созданы — знаем все IDs).
        $kidsRefs = implode(' ', array_map(fn ($id) => "$id 0 R", $pageIds));
        $writer->setObject($pagesId, sprintf(
            '<< /Type /Pages /Kids [%s] /Count %d >>',
            $kidsRefs, count($pageIds),
        ));

        // Phase 43+97+99: AcroForm reference в Catalog.
        // /CO — calc field order (Phase 97).
        // /DA + /DR — default appearance string + font resources (Phase 99).
        $acroFormRef = '';
        if ($this->collectedFormFieldIds !== []) {
            $fieldsArray = implode(' ', array_map(fn ($id) => "$id 0 R", $this->collectedFormFieldIds));
            $coRef = '';
            if ($this->calculatedFieldIds !== []) {
                $coArray = implode(' ', array_map(fn ($id) => "$id 0 R", $this->calculatedFieldIds));
                $coRef = " /CO [$coArray]";
            }
            // Phase 99: default appearance — Helvetica 11pt black.
            $defaultFontId = $writer->addObject(
                '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica '
                .'/Encoding /WinAnsiEncoding >>',
            );
            $daPart = ' /DA (/Helv 11 Tf 0 g)';
            $drPart = sprintf(' /DR << /Font << /Helv %d 0 R >> >>', $defaultFontId);
            $acroFormId = $writer->addObject(sprintf(
                '<< /Fields [%s] /NeedAppearances true%s%s%s >>',
                $fieldsArray, $coRef, $daPart, $drPart,
            ));
            $acroFormRef = " /AcroForm $acroFormId 0 R";
        }

        // Phase 48: Tagged PDF — StructTreeRoot + StructElem children +
        // MarkInfo dict.
        $taggedRef = '';
        if ($this->tagged && $this->structElements !== []) {
            $structRootId = $writer->reserveObject();
            $childIds = [];
            // Phase 92: track struct element IDs per page для /ParentTree.
            $structElemsPerPage = [];
            foreach ($this->structElements as $elem) {
                $pageId = $pageObjectIdMap[$elem['page']] ?? null;
                if ($pageId === null) {
                    continue;
                }
                $altPart = '';
                if (! empty($elem['altText'])) {
                    $altPart = ' /Alt '.$this->pdfString((string) $elem['altText']);
                }
                if (! empty($elem['objr'])) {
                    $body = sprintf(
                        '<< /Type /StructElem /S /%s /P %d 0 R '
                        .'/Pg %d 0 R /K << /Type /OBJR /Obj %d 0 R >>%s >>',
                        $elem['type'], $structRootId, $pageId, $elem['objr'], $altPart,
                    );
                } else {
                    $body = sprintf(
                        '<< /Type /StructElem /S /%s /P %d 0 R '
                        .'/Pg %d 0 R /K %d%s >>',
                        $elem['type'], $structRootId, $pageId, $elem['mcid'], $altPart,
                    );
                }
                $elemId = $writer->addObject($body);
                $childIds[] = $elemId;

                $pageIdx = array_search($elem['page'], $this->pages, true);
                if ($pageIdx !== false) {
                    $structElemsPerPage[$pageIdx] ??= [];
                    $structElemsPerPage[$pageIdx][] = $elemId;
                }
            }
            $kidsArray = '['.implode(' ', array_map(fn ($id) => "$id 0 R", $childIds)).']';

            // Phase 92: emit /ParentTree (number tree) — per-page arrays
            // listing struct elements rendered на каждой page.
            ksort($structElemsPerPage);
            $parentTreeNums = [];
            foreach ($structElemsPerPage as $pageIdx => $elemIds) {
                $refs = implode(' ', array_map(fn ($id) => "$id 0 R", $elemIds));
                $parentTreeNums[] = "$pageIdx [$refs]";
            }
            $parentTreeId = $writer->addObject(
                '<< /Nums ['.implode(' ', $parentTreeNums).'] >>',
            );

            // Phase 93: optional /RoleMap dict.
            $roleMapRef = '';
            if ($this->structRoleMap !== []) {
                $entries = [];
                foreach ($this->structRoleMap as $custom => $standard) {
                    $entries[] = '/'.$custom.' /'.$standard;
                }
                $roleMapRef = ' /RoleMap << '.implode(' ', $entries).' >>';
            }

            $writer->setObject($structRootId, sprintf(
                '<< /Type /StructTreeRoot /K %s /ParentTree %d 0 R '
                .'/ParentTreeNextKey %d%s >>',
                $kidsArray,
                $parentTreeId,
                count($this->pages),
                $roleMapRef,
            ));
            $taggedRef = sprintf(
                ' /MarkInfo << /Marked true >> /StructTreeRoot %d 0 R',
                $structRootId,
            );
        }

        // Phase 47: PDF/A-1b — Metadata stream + OutputIntent + /Lang.
        $pdfARef = '';
        if ($this->pdfA !== null) {
            $xmp = $this->pdfA->xmpMetadata();
            // Metadata stream — НЕ filtered, НЕ encrypted.
            $metadataId = $writer->addObject(sprintf(
                "<< /Type /Metadata /Subtype /XML /Length %d >>\nstream\n%s\nendstream",
                strlen($xmp),
                $xmp,
            ));
            // ICC profile embedded — Flate-compressed stream с /N 3 (RGB).
            $iccBytes = $this->pdfA->iccProfileBytes();
            $iccCompressed = (string) gzcompress($iccBytes, 6);
            $iccId = $writer->addObject(sprintf(
                "<< /N 3 /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
                strlen($iccCompressed),
                $iccCompressed,
            ));
            $outputIntentBody = sprintf(
                '<< /Type /OutputIntent /S /GTS_PDFA1 '
                .'/OutputConditionIdentifier %s '
                .'/Info %s '
                .'/DestOutputProfile %d 0 R >>',
                $this->pdfString($this->pdfA->iccProfileName),
                $this->pdfString($this->pdfA->iccProfileName),
                $iccId,
            );
            $outputIntentId = $writer->addObject($outputIntentBody);
            $pdfARef = sprintf(
                ' /Metadata %d 0 R /OutputIntents [%d 0 R] /Lang %s',
                $metadataId,
                $outputIntentId,
                $this->pdfString($this->pdfA->lang),
            );
        }

        // 6. Catalog.
        // Phase 84: optional /OpenAction, /PageMode, /PageLayout.
        $openActionRef = '';
        if ($this->openAction !== null) {
            $pIdx = max(0, $this->openAction['page'] - 1);
            $pageId = $pageIds[$pIdx] ?? $pageIds[0];
            $mode = $this->openAction['mode'];
            $action = match ($mode) {
                'fit-page' => sprintf('[%d 0 R /Fit]', $pageId),
                'fit-width' => sprintf('[%d 0 R /FitH null]', $pageId),
                'actual-size' => sprintf('[%d 0 R /XYZ null null 1]', $pageId),
                'xyz' => sprintf(
                    '[%d 0 R /XYZ %s %s %s]',
                    $pageId,
                    $this->openAction['x'] !== null ? $this->fmt($this->openAction['x']) : 'null',
                    $this->openAction['y'] !== null ? $this->fmt($this->openAction['y']) : 'null',
                    $this->openAction['zoom'] !== null ? $this->fmt($this->openAction['zoom']) : 'null',
                ),
                default => sprintf('[%d 0 R /Fit]', $pageId),
            };
            $openActionRef = " /OpenAction $action";
        }
        $pageModeRef = '';
        if ($this->pageMode !== null) {
            $pageModeRef = ' /PageMode /'.match ($this->pageMode) {
                'use-outlines' => 'UseOutlines',
                'use-thumbs' => 'UseThumbs',
                'use-oc' => 'UseOC',
                'full-screen' => 'FullScreen',
                default => 'UseNone',
            };
        }
        $pageLayoutRef = '';
        if ($this->pageLayout !== null) {
            $pageLayoutRef = ' /PageLayout /'.match ($this->pageLayout) {
                'one-column' => 'OneColumn',
                'two-column-left' => 'TwoColumnLeft',
                'two-column-right' => 'TwoColumnRight',
                'two-page-left' => 'TwoPageLeft',
                'two-page-right' => 'TwoPageRight',
                default => 'SinglePage',
            };
        }

        // Phase 87: /PageLabels number tree.
        $pageLabelsRef = '';
        if ($this->pageLabelRanges !== []) {
            $styleMap = [
                'decimal' => 'D',
                'upper-roman' => 'R',
                'lower-roman' => 'r',
                'upper-alpha' => 'A',
                'lower-alpha' => 'a',
            ];
            $numsEntries = [];
            foreach ($this->pageLabelRanges as $range) {
                $parts = [];
                if (isset($range['style']) && isset($styleMap[$range['style']])) {
                    $parts[] = '/S /'.$styleMap[$range['style']];
                }
                if (isset($range['prefix']) && $range['prefix'] !== '') {
                    $parts[] = '/P '.$this->pdfString($range['prefix']);
                }
                if (isset($range['firstNumber'])) {
                    $parts[] = '/St '.$range['firstNumber'];
                }
                $dict = '<< '.implode(' ', $parts).' >>';
                $numsEntries[] = $range['startPage'].' '.$dict;
            }
            $pageLabelsRef = ' /PageLabels << /Nums ['.implode(' ', $numsEntries).'] >>';
        }

        // Phase 88: /ViewerPreferences dict.
        $viewerPrefsRef = '';
        if ($this->viewerPreferences !== []) {
            $entries = [];
            $boolKeys = [
                'hideToolbar' => 'HideToolbar', 'hideMenubar' => 'HideMenubar',
                'hideWindowUI' => 'HideWindowUI', 'fitWindow' => 'FitWindow',
                'centerWindow' => 'CenterWindow', 'displayDocTitle' => 'DisplayDocTitle',
            ];
            foreach ($boolKeys as $k => $name) {
                if (isset($this->viewerPreferences[$k])) {
                    $entries[] = "/$name ".($this->viewerPreferences[$k] ? 'true' : 'false');
                }
            }
            if (isset($this->viewerPreferences['direction'])) {
                $entries[] = '/Direction /'.$this->viewerPreferences['direction'];
            }
            if (isset($this->viewerPreferences['printScaling'])) {
                $entries[] = '/PrintScaling /'.$this->viewerPreferences['printScaling'];
            }
            if (isset($this->viewerPreferences['duplex'])) {
                $entries[] = '/Duplex /'.$this->viewerPreferences['duplex'];
            }
            if ($entries !== []) {
                $viewerPrefsRef = ' /ViewerPreferences << '.implode(' ', $entries).' >>';
            }
        }

        // Phase 89: /Lang entry в Catalog (PDF/UA requirement).
        $langRef = '';
        if ($this->lang !== null && $this->lang !== '') {
            // Avoid double-emission if PDF/A mode already injects /Lang.
            if ($this->pdfA === null) {
                $langRef = ' /Lang '.$this->pdfString($this->lang);
            }
        }

        $writer->setObject($catalogId, "<< /Type /Catalog /Pages $pagesId 0 R$namesRef$outlinesRef$acroFormRef$pdfARef$taggedRef$openActionRef$pageModeRef$pageLayoutRef$pageLabelsRef$viewerPrefsRef$langRef >>");

        $writer->setRoot($catalogId);

        // Phase 20: /Info dictionary (PDF metadata).
        if ($this->metadata !== []) {
            // Auto-default Producer + CreationDate.
            $meta = $this->metadata + [
                'Producer' => 'dskripchenko/php-pdf',
                'CreationDate' => $this->formatPdfDate(new \DateTimeImmutable),
            ];
            $entries = [];
            foreach ($meta as $key => $value) {
                $entries[] = '/'.$key.' '.$this->pdfString((string) $value);
            }
            $infoId = $writer->addObject('<< '.implode(' ', $entries).' >>');
            $writer->setInfo($infoId);
        }

        // Phase 41-42: emit /Encrypt object и hook encryption в writer.
        if ($this->encryption !== null) {
            $enc = $this->encryption;
            $oHex = bin2hex($enc->oValue);
            $uHex = bin2hex($enc->uValue);
            if ($enc->algorithm === EncryptionAlgorithm::Aes_256) {
                // V5 R5 + Crypt Filter AESV3.
                $oeHex = bin2hex($enc->oeValue);
                $ueHex = bin2hex($enc->ueValue);
                $permsHex = bin2hex($enc->permsValue);
                $encryptBody = sprintf(
                    '<< /Filter /Standard /V 5 /R 5 /Length 256 '
                    .'/CF << /StdCF << /CFM /AESV3 /Length 32 /AuthEvent /DocOpen >> >> '
                    .'/StmF /StdCF /StrF /StdCF '
                    .'/O <%s> /U <%s> /OE <%s> /UE <%s> /Perms <%s> /P %d >>',
                    $oHex, $uHex, $oeHex, $ueHex, $permsHex, $enc->permissions,
                );
            } elseif ($enc->algorithm === EncryptionAlgorithm::Aes_128) {
                // V4 R4 + Crypt Filter AESV2.
                $encryptBody = sprintf(
                    '<< /Filter /Standard /V 4 /R 4 /Length 128 '
                    .'/CF << /StdCF << /CFM /AESV2 /Length 16 /AuthEvent /DocOpen >> >> '
                    .'/StmF /StdCF /StrF /StdCF '
                    .'/O <%s> /U <%s> /P %d >>',
                    $oHex,
                    $uHex,
                    $enc->permissions,
                );
            } else {
                $encryptBody = sprintf(
                    '<< /Filter /Standard /V 2 /R 3 /Length 128 /O <%s> /U <%s> /P %d >>',
                    $oHex,
                    $uHex,
                    $enc->permissions,
                );
            }
            $encryptId = $writer->addObject($encryptBody);
            $writer->setEncryption($enc, $encryptId);
        }

        return $writer->toBytes();
    }

    /**
     * Сериализует в файл. Возвращает количество записанных байт.
     */
    public function toFile(string $path): int
    {
        $bytes = $this->toBytes();
        $written = file_put_contents($path, $bytes);
        if ($written === false) {
            throw new \RuntimeException('Failed to write PDF to '.$path);
        }

        return $written;
    }

    /**
     * Эмитит outline tree (bookmarks panel). Берёт flat $outlineEntries
     * и строит nested структуру по level'у через stack:
     *  - Level 1 → child of Outlines root
     *  - Level N+1 → child of last level-N entry
     *  - Skip-levels (1→3) treated as 1→2 (no virtual filler)
     *
     * @param  \SplObjectStorage<Page, int>  $pageObjectIdMap
     */
    private function emitOutlineTree(Writer $writer, \SplObjectStorage $pageObjectIdMap): int
    {
        $count = count($this->outlineEntries);

        // Phase 1: reserve IDs.
        $entryIds = [];
        for ($i = 0; $i < $count; $i++) {
            $entryIds[$i] = $writer->reserveObject();
        }
        $outlinesId = $writer->reserveObject();

        // Phase 2: compute hierarchy.
        // parents[i] = index of parent entry, or null если top-level.
        // children[parentIdx] = list<childIdx>.
        $parents = [];
        $children = [];
        $stack = []; // stack of [entryIdx, level]
        foreach ($this->outlineEntries as $i => $entry) {
            $level = $entry['level'];
            while ($stack !== [] && end($stack)[1] >= $level) {
                array_pop($stack);
            }
            $parentIdx = $stack === [] ? null : end($stack)[0];
            $parents[$i] = $parentIdx;
            if ($parentIdx === null) {
                $children[-1] = $children[-1] ?? [];
                $children[-1][] = $i;
            } else {
                $children[$parentIdx] = $children[$parentIdx] ?? [];
                $children[$parentIdx][] = $i;
            }
            $stack[] = [$i, $level];
        }
        $topLevel = $children[-1] ?? [];

        // Phase 3: emit each entry.
        foreach ($this->outlineEntries as $i => $entry) {
            $parentIdx = $parents[$i];
            $parentRef = $parentIdx === null
                ? $outlinesId.' 0 R'
                : $entryIds[$parentIdx].' 0 R';

            // Find siblings (same parent's children list).
            $siblings = $parentIdx === null ? $topLevel : ($children[$parentIdx] ?? []);
            $position = array_search($i, $siblings, strict: true);
            $prevRef = ($position !== false && $position > 0)
                ? $entryIds[$siblings[$position - 1]].' 0 R'
                : null;
            $nextRef = ($position !== false && $position < count($siblings) - 1)
                ? $entryIds[$siblings[$position + 1]].' 0 R'
                : null;

            // First/Last child.
            $myChildren = $children[$i] ?? [];
            $firstChildRef = $myChildren !== [] ? $entryIds[$myChildren[0]].' 0 R' : null;
            $lastChildRef = $myChildren !== [] ? $entryIds[$myChildren[count($myChildren) - 1]].' 0 R' : null;

            // Count of descendants (positive — open by default).
            $countDesc = $this->countDescendants($i, $children);

            $pageObjId = $pageObjectIdMap[$entry['page']];
            $dest = sprintf('[%d 0 R /XYZ %s %s 0]',
                $pageObjId, $this->fmt($entry['x']), $this->fmt($entry['y']));

            $parts = [
                '/Title '.$this->pdfString($entry['title']),
                '/Parent '.$parentRef,
                '/Dest '.$dest,
            ];
            if ($prevRef !== null) {
                $parts[] = '/Prev '.$prevRef;
            }
            if ($nextRef !== null) {
                $parts[] = '/Next '.$nextRef;
            }
            if ($firstChildRef !== null) {
                $parts[] = '/First '.$firstChildRef;
                $parts[] = '/Last '.$lastChildRef;
                $parts[] = '/Count '.$countDesc;
            }
            // Phase 100: optional /C (color RGB) + /F (style flags).
            if (! empty($entry['color'])) {
                [$r, $g, $b] = $this->hexToRgb01((string) $entry['color']);
                $parts[] = sprintf('/C [%s %s %s]',
                    $this->fmt($r), $this->fmt($g), $this->fmt($b));
            }
            $flags = 0;
            if (! empty($entry['italic'])) {
                $flags |= 1;
            }
            if (! empty($entry['bold'])) {
                $flags |= 2;
            }
            if ($flags !== 0) {
                $parts[] = '/F '.$flags;
            }
            $writer->setObject($entryIds[$i], '<< '.implode(' ', $parts).' >>');
        }

        // Phase 4: emit Outlines root.
        $topCount = count($topLevel);
        $totalDesc = 0;
        foreach ($topLevel as $idx) {
            $totalDesc += 1 + $this->countDescendants($idx, $children);
        }
        $rootParts = ['/Type /Outlines'];
        if ($topLevel !== []) {
            $rootParts[] = '/First '.$entryIds[$topLevel[0]].' 0 R';
            $rootParts[] = '/Last '.$entryIds[$topLevel[count($topLevel) - 1]].' 0 R';
            $rootParts[] = '/Count '.$totalDesc;
        }
        $writer->setObject($outlinesId, '<< '.implode(' ', $rootParts).' >>');

        return $outlinesId;
    }

    /**
     * Recursive count of descendants для /Count field outline entry.
     *
     * @param  array<int, list<int>>  $children
     */
    private function countDescendants(int $idx, array $children): int
    {
        $myChildren = $children[$idx] ?? [];
        $count = count($myChildren);
        foreach ($myChildren as $childIdx) {
            $count += $this->countDescendants($childIdx, $children);
        }

        return $count;
    }

    /**
     * Phase 67: emit JavaScript action objects + return /AA dict string.
     * Returns empty string если no actions defined.
     *
     * @param  array<string, mixed>  $field
     */
    private function emitFieldActions(Writer $writer, array $field): string
    {
        $entries = [];
        $scriptMap = [
            'K' => 'keystrokeScript',
            'V' => 'validateScript',
            'C' => 'calculateScript',
            'F' => 'formatScript',
        ];
        foreach ($scriptMap as $entry => $key) {
            $script = $field[$key] ?? null;
            if ($script === null) {
                continue;
            }
            $actionBody = sprintf(
                '<< /Type /Action /S /JavaScript /JS %s >>',
                $this->pdfString($script),
            );
            $actionId = $writer->addObject($actionBody);
            $entries[] = "/$entry $actionId 0 R";
        }
        if ($entries === []) {
            return '';
        }

        return ' /AA << '.implode(' ', $entries).' >>';
    }

    /**
     * Phase 43+46: build single-widget AcroForm field object body.
     *
     * @param  array<string, mixed>  $field
     */
    private function buildSimpleFieldObject(array $field, int $pageId, string $namePart, string $tooltipPart, string $aaPart = ''): string
    {
        $rect = sprintf(
            '[%s %s %s %s]',
            $this->fmt($field['x']), $this->fmt($field['y']),
            $this->fmt($field['x'] + $field['w']),
            $this->fmt($field['y'] + $field['h']),
        );
        $flags = 0;
        if ($field['readOnly']) {
            $flags |= 1;
        }
        if ($field['required']) {
            $flags |= 2;
        }
        $type = $field['type'];
        // Type-specific flags (high-numbered bits per ISO 32000-1 Table 228).
        if ($type === 'text-multiline') {
            $flags |= 4096; // Multiline bit 13.
        }
        if ($type === 'password') {
            $flags |= 8192; // Password bit 14.
        }
        if ($type === 'combo') {
            $flags |= 131072; // Combo bit 18.
        }

        if (in_array($type, ['text', 'text-multiline', 'password'], true)) {
            $valuePart = $field['defaultValue'] !== ''
                ? ' /V '.$this->pdfString($field['defaultValue'])
                    .' /DV '.$this->pdfString($field['defaultValue'])
                : '';

            return sprintf(
                '<< /Type /Annot /Subtype /Widget /FT /Tx /Rect %s '
                .'%s%s%s /Ff %d /P %d 0 R%s >>',
                $rect, $namePart, $valuePart, $tooltipPart, $flags, $pageId, $aaPart,
            );
        }
        if ($type === 'checkbox') {
            $isChecked = strcasecmp($field['defaultValue'], 'on') === 0
                || strcasecmp($field['defaultValue'], 'yes') === 0
                || $field['defaultValue'] === '1';
            $vPart = $isChecked ? ' /V /Yes /DV /Yes' : ' /V /Off /DV /Off';

            return sprintf(
                '<< /Type /Annot /Subtype /Widget /FT /Btn /Rect %s '
                .'%s%s%s /Ff %d /P %d 0 R /AS %s%s >>',
                $rect, $namePart, $vPart, $tooltipPart, $flags, $pageId,
                $isChecked ? '/Yes' : '/Off',
                $aaPart,
            );
        }
        if ($type === 'signature') {
            return sprintf(
                '<< /Type /Annot /Subtype /Widget /FT /Sig /Rect %s '
                .'%s%s /Ff %d /P %d 0 R%s >>',
                $rect, $namePart, $tooltipPart, $flags, $pageId, $aaPart,
            );
        }
        if ($type === 'submit' || $type === 'reset' || $type === 'push') {
            // Phase 83: button с pushbutton flag (bit 17 = 65536).
            $flags |= 65536;
            $caption = $field['buttonCaption'] ?? ucfirst($type);
            $mkPart = ' /MK << /CA '.$this->pdfString($caption).' >>';

            // /A action dict.
            $actionPart = '';
            if ($type === 'submit') {
                $url = $field['submitUrl'] ?? '';
                $actionPart = ' /A << /Type /Action /S /SubmitForm '
                    .'/F << /FS /URL /F '.$this->pdfString($url).' >> '
                    .'/Flags 0 >>';
            } elseif ($type === 'reset') {
                $actionPart = ' /A << /Type /Action /S /ResetForm >>';
            } elseif ($type === 'push' && ! empty($field['clickScript'])) {
                $actionPart = sprintf(
                    ' /A << /Type /Action /S /JavaScript /JS %s >>',
                    $this->pdfString($field['clickScript']),
                );
            }

            return sprintf(
                '<< /Type /Annot /Subtype /Widget /FT /Btn /Rect %s '
                .'%s%s /Ff %d /P %d 0 R%s%s%s >>',
                $rect, $namePart, $tooltipPart, $flags, $pageId,
                $mkPart, $actionPart, $aaPart,
            );
        }
        if ($type === 'combo' || $type === 'list') {
            $optsArray = '['.implode(' ', array_map(fn ($o) => $this->pdfString($o), $field['options'])).']';
            $valuePart = $field['defaultValue'] !== ''
                ? ' /V '.$this->pdfString($field['defaultValue'])
                    .' /DV '.$this->pdfString($field['defaultValue'])
                : '';

            return sprintf(
                '<< /Type /Annot /Subtype /Widget /FT /Ch /Rect %s '
                .'%s%s%s /Opt %s /Ff %d /P %d 0 R%s >>',
                $rect, $namePart, $valuePart, $tooltipPart, $optsArray, $flags, $pageId, $aaPart,
            );
        }
        throw new \LogicException("Unsupported field type: $type");
    }

    /**
     * Phase 46: emit radio-group parent + N child Widget annotations.
     *
     * @param  array<string, mixed>  $field
     * @return array{0: int, 1: list<int>}  [parentObjId, childObjIds]
     */
    private function emitRadioGroupFields(Writer $writer, int $pageId, array $field, string $namePart, string $tooltipPart): array
    {
        // Reserve parent ID upfront — children reference его через /Parent.
        $parentId = $writer->reserveObject();

        // Flags: Radio bit 16 (val 32768) + NoToggleToOff bit 15 (val 16384).
        $flags = 32768 | 16384;
        if ($field['readOnly']) {
            $flags |= 1;
        }
        if ($field['required']) {
            $flags |= 2;
        }

        $childIds = [];
        foreach ($field['radioWidgets'] as $idx => $widget) {
            $optionLabel = $field['options'][$idx];
            $isChecked = $optionLabel === $field['defaultValue'];
            $rect = sprintf(
                '[%s %s %s %s]',
                $this->fmt($widget['x']), $this->fmt($widget['y']),
                $this->fmt($widget['x'] + $widget['w']),
                $this->fmt($widget['y'] + $widget['h']),
            );
            // Child widget — pure annotation, /T inherited от parent.
            // /AS = export value (escaped как name); /Off если не selected.
            $exportName = '/'.preg_replace('@[^A-Za-z0-9_-]@', '_', $optionLabel);
            $body = sprintf(
                '<< /Type /Annot /Subtype /Widget /Rect %s '
                .'/Parent %d 0 R /AS %s >>',
                $rect, $parentId, $isChecked ? $exportName : '/Off',
            );
            $childIds[] = $writer->addObject($body);
        }

        $kidsArray = '['.implode(' ', array_map(fn ($id) => "$id 0 R", $childIds)).']';
        $vPart = $field['defaultValue'] !== ''
            ? ' /V /'.preg_replace('@[^A-Za-z0-9_-]@', '_', $field['defaultValue'])
                .' /DV /'.preg_replace('@[^A-Za-z0-9_-]@', '_', $field['defaultValue'])
            : '';
        $parentBody = sprintf(
            '<< /FT /Btn %s%s /Ff %d /Kids %s >>',
            $namePart, $vPart.$tooltipPart, $flags, $kidsArray,
        );
        $writer->setObject($parentId, $parentBody);

        return [$parentId, $childIds];
    }

    /**
     * Phase 100: parse hex #rrggbb / #rgb → list<float> в [0..1].
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function hexToRgb01(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return [0, 0, 0];
        }

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    /**
     * Phase 49: PDF name objects only allow specific chars. Mime types
     * с '/' нужно конвертировать в hash-escaped form (#2F).
     */
    private function sanitizeMimeName(string $mime): string
    {
        $out = '';
        for ($i = 0; $i < strlen($mime); $i++) {
            $c = $mime[$i];
            if (preg_match('@[A-Za-z0-9]@', $c)) {
                $out .= $c;
            } else {
                $out .= '#'.sprintf('%02X', ord($c));
            }
        }

        return $out;
    }

    /**
     * Escape string for PDF literal: wrap в `()`, escape `(`, `)`, `\`.
     * Используется для URI и name values в Names tree.
     */
    private function pdfString(string $s): string
    {
        return '('.strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']).')';
    }

    /**
     * For /Dest references — use bytestring form `(name)` consistently
     * с Names tree (matches ISO 32000-1 §12.3.2.3 — destinations referenced
     * by name resolve через /Names).
     */
    private function pdfNameString(string $name): string
    {
        return $this->pdfString($name);
    }

    /**
     * Format float без trailing zeros, без locale-зависимого decimal-
     * separator'а.
     */
    private function fmt(float $n): string
    {
        if ((int) $n == $n) {
            return (string) (int) $n;
        }

        return rtrim(rtrim(sprintf('%.4f', $n), '0'), '.');
    }
}
