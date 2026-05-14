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

    /** Phase 208: emit xref как XRef stream object (PDF 1.5+) instead of classic table. */
    private bool $useXrefStream = false;

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

    /** Phase 108: PKCS#7 signing config (PDF signed at toBytes). */
    private ?SignatureConfig $signatureConfig = null;

    /** @var list<PdfLayer> Phase 112: Optional Content Groups (layers). */
    private array $layers = [];

    /** @var array<string, string> Phase 119: Document-level /AA actions (event → JS). */
    private array $documentActions = [];

    /** Phase 108: reserved sig dict ID, set transient during emit(). */
    private ?int $signatureDictId = null;

    /** Phase 108: tracks whether /V was already attached to one widget. */
    private bool $signatureFieldLinked = false;

    /** Phase 123: reserved Helvetica font ID for AcroForm AppearanceStreams. */
    private ?int $appearanceFontId = null;

    /** Phase 123: reserved ZapfDingbats font ID for checkbox/radio glyphs. */
    private ?int $appearanceZapfId = null;

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
     * Phase 151: structElement index → /StructParent number tree key for tagged
     * Link annotations. Used to wire /Link annot back к its StructElem in ParentTree.
     *
     * @var array<int, int>
     */
    private array $structParentLinkKeys = [];

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
     * Phase 190: PDF/A-1a (accessibility) variant — requires Tagged PDF.
     */
    public function enablePdfA(PdfAConfig $config): self
    {
        if ($this->encryption !== null) {
            throw new \LogicException('PDF/A disallows encryption — call enablePdfA() перед encrypt() либо не вызывайте encrypt().');
        }
        $this->pdfA = $config;
        $this->pdfVersion = '1.4';
        // Phase 190: PDF/A-1a (and PDF/A-2a, 3a) require Tagged PDF structure.
        // Auto-enable tagging если conformance = 'A'.
        if ($config->conformance === PdfAConfig::CONFORMANCE_A && ! $this->tagged) {
            $this->enableTagged();
        }

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
        // Phase 106: R6 — PDF 2.0 (ISO 32000-2). Bump version unconditionally.
        if ($algorithm === EncryptionAlgorithm::Aes_256_R6) {
            $this->pdfVersion = '2.0';
        }

        return $this;
    }

    /**
     * Phase 108: enable PKCS#7 detached signing.
     *
     * Requires at least one form field of type 'signature' to be added
     * (will receive /V referencing the actual signature dictionary).
     *
     * The PDF bytes are patched in toBytes() after assembly:
     *   1. /ByteRange [a b c d] computed from actual /Contents position.
     *   2. Bytes outside /Contents hashed + signed via openssl_pkcs7_sign.
     *   3. /Contents placeholder filled с hex-encoded DER PKCS#7 envelope.
     */
    public function sign(SignatureConfig $config): self
    {
        $this->signatureConfig = $config;

        return $this;
    }

    /**
     * Phase 112: register a layer (Optional Content Group). Returned
     * instance is passed к Page::beginLayer() to mark content.
     */
    public function addLayer(string $name, bool $defaultVisible = true, string $intent = 'View'): PdfLayer
    {
        $layer = new PdfLayer($name, $defaultVisible, $intent);
        $this->layers[] = $layer;

        return $layer;
    }

    /**
     * Phase 119: register a document-level Additional Action.
     *
     * Events (PDF spec §12.6.3 Table 197):
     *  - WillClose (WC), WillSave (WS), DidSave (DS),
     *    WillPrint (WP), DidPrint (DP).
     *  Note: DocumentOpen — use {@see setOpenAction()} (separate /OpenAction entry).
     *
     * Emits /AA << /WC <<...>> /WS <<...>> ... >> в Catalog.
     */
    public function setDocumentAction(string $event, string $script): self
    {
        $valid = ['WC', 'WS', 'DS', 'WP', 'DP'];
        if (! in_array($event, $valid, true)) {
            throw new \InvalidArgumentException('Document action event must be one of: ' . implode(', ', $valid));
        }
        $this->documentActions[$event] = $script;

        return $this;
    }

    /**
     * @return list<PdfLayer>
     *
     * @internal
     */
    public function layers(): array
    {
        return $this->layers;
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
     * Phase 208: enable XRef stream cross-reference table (PDF 1.5+).
     *
     * Replaces classic `xref...trailer` keywords с binary-packed FlateDecode
     * object — ~50% smaller metadata footprint. Auto-bumps PDF version к 1.5+
     * если currently below. Не compatible с PKCS#7 signing (signing path
     * keeps classic xref для simpler /ByteRange handling — when both are
     * configured, classic xref wins).
     */
    public function useXrefStream(bool $enabled = true): self
    {
        $this->useXrefStream = $enabled;
        if ($enabled && version_compare($this->pdfVersion, '1.5', '<')) {
            $this->pdfVersion = '1.5';
        }

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
        return $this->buildWriter()->toBytes();
    }

    /**
     * Phase 129: build configured Writer (ready to emit). Extracted из
     * toBytes() для shared use с toStream().
     */
    private function buildWriter(): Writer
    {
        if ($this->pages === []) {
            // Empty document — add blank A4 page чтобы PDF был валидным
            // (PDF спецификация требует ≥ 1 page в page tree).
            $this->addPage();
        }

        // Phase 208: xref streams auto-disabled при PKCS#7 signing — that
        // path patches /ByteRange + /Contents в classic xref layout.
        $useXref = $this->useXrefStream && $this->signatureConfig === null;
        $writer = new Writer($this->pdfVersion, useXrefStream: $useXref);

        // 1. Резервируем top-level IDs.
        $catalogId = $writer->reserveObject();
        $pagesId = $writer->reserveObject();

        // Phase 108: reserve signature dictionary ID upfront so widget /V
        // can reference it during field emission.
        $this->signatureDictId = null;
        $this->signatureFieldLinked = false;
        if ($this->signatureConfig !== null) {
            $this->signatureDictId = $writer->reserveObject();
        }

        // Phase 123: reset appearance font IDs — assigned lazy in buildAppearance*.
        $this->appearanceFontId = null;
        $this->appearanceZapfId = null;

        // 2. Регистрируем все unique fonts/images через identity-dedupe.
        //    Standard fonts: один PDF object per unique StandardFont enum.
        //    Embedded fonts: один объект-граф per PdfFont instance.
        //    Images: один XObject per PdfImage instance.

        // Phase 112: emit OCG objects upfront so page Properties references
        // them by ID.
        /** @var \SplObjectStorage<PdfLayer, int> */
        $layerObjectIds = new \SplObjectStorage;
        foreach ($this->layers as $layer) {
            $layerObjectIds[$layer] = $writer->addObject($layer->dictBody());
        }

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

        // Phase 107: Form XObjects — shared content streams referenced by
        // /Do. Identity-dedup per instance (same PdfFormXObject used on
        // multiple pages → один XObject в output).
        /** @var \SplObjectStorage<PdfFormXObject, int> */
        $formXObjectIds = new \SplObjectStorage;
        foreach ($this->pages as $page) {
            foreach ($page->formXObjects() as $form) {
                if (isset($formXObjectIds[$form])) {
                    continue;
                }
                $body = $form->contentStream;
                if ($this->compressStreams && $body !== '') {
                    $compressed = (string) gzcompress($body, 6);
                    $formId = $writer->addObject(sprintf(
                        "<< %s /Resources << >> /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
                        $form->dictHead(),
                        strlen($compressed),
                        $compressed,
                    ));
                } else {
                    $formId = $writer->addObject(sprintf(
                        "<< %s /Resources << >> /Length %d >>\nstream\n%s\nendstream",
                        $form->dictHead(),
                        strlen($body),
                        $body,
                    ));
                }
                $formXObjectIds[$form] = $formId;
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
            // Phase 107: Form XObjects share /XObject namespace на page.
            foreach ($page->formXObjects() as $name => $form) {
                $resourcesXObj .= sprintf(' /%s %d 0 R', $name, $formXObjectIds[$form]);
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
            // Phase 111: Tiling Patterns (Type 1) — stream-bearing pattern
            // objects emitted в same /Pattern resource namespace.
            foreach ($page->tilingPatterns() as $name => $tp) {
                $body = $tp->contentStream;
                if ($this->compressStreams && $body !== '') {
                    $compressed = (string) gzcompress($body, 6);
                    $tpId = $writer->addObject(sprintf(
                        "<< %s /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
                        $tp->dictHead(),
                        strlen($compressed),
                        $compressed,
                    ));
                } else {
                    $tpId = $writer->addObject(sprintf(
                        "<< %s /Length %d >>\nstream\n%s\nendstream",
                        $tp->dictHead(),
                        strlen($body),
                        $body,
                    ));
                }
                $resourcesPattern .= sprintf(' /%s %d 0 R', $name, $tpId);
            }

            // Phase 112: /Properties для Optional Content references (`/OC /name BDC`).
            $resourcesProperties = '';
            foreach ($page->layerProperties() as $name => $layer) {
                if (! isset($layerObjectIds[$layer])) {
                    continue;
                }
                $resourcesProperties .= sprintf(' /%s %d 0 R', $name, $layerObjectIds[$layer]);
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
            if ($resourcesProperties !== '') {
                $resourcesParts[] = '/Properties <<'.$resourcesProperties.' >>';
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

                // Phase 151: reserve /StructParent number for tagged links —
                // counter starts after page indices. Annotation dict includes
                // /StructParent N; ParentTree maps N → struct elem ID.
                $structParentPart = '';
                $structParentKey = -1;
                if ($this->tagged) {
                    $structParentKey = count($this->pages) + count($this->structParentLinkKeys);
                    $structParentPart = sprintf(' /StructParent %d', $structParentKey);
                }
                $body = match ($ann['kind']) {
                    'uri' => sprintf(
                        '<< /Type /Annot /Subtype /Link /Rect %s '
                        .'/Border [0 0 0] /A << /S /URI /URI %s >>%s >>',
                        $rect,
                        $this->pdfString($ann['target']),
                        $structParentPart,
                    ),
                    'named' => sprintf(
                        '<< /Type /Annot /Subtype /Link /Rect %s '
                        .'/Border [0 0 0] /A << /Type /Action /S /Named /N /%s >>%s >>',
                        $rect,
                        $ann['target'],
                        $structParentPart,
                    ),
                    'javascript' => sprintf(
                        '<< /Type /Annot /Subtype /Link /Rect %s '
                        .'/Border [0 0 0] /A << /Type /Action /S /JavaScript /JS %s >>%s >>',
                        $rect,
                        $this->pdfString($ann['target']),
                        $structParentPart,
                    ),
                    'launch' => sprintf(
                        '<< /Type /Annot /Subtype /Link /Rect %s '
                        .'/Border [0 0 0] /A << /Type /Action /S /Launch /F %s >>%s >>',
                        $rect,
                        $this->pdfString($ann['target']),
                        $structParentPart,
                    ),
                    default => sprintf(
                        '<< /Type /Annot /Subtype /Link /Rect %s '
                        .'/Border [0 0 0] /Dest %s%s >>',
                        $rect,
                        $this->pdfNameString($ann['target']),
                        $structParentPart,
                    ),
                };
                $linkAnnotId = $writer->addObject($body);
                $annotIds[] = $linkAnnotId;

                // Phase 72/151: tagged PDF — register /Link struct element
                // referencing this annotation через /OBJR; reserve ParentTree
                // entry under reserved StructParent key.
                if ($this->tagged) {
                    $structIdx = count($this->structElements);
                    $this->structElements[] = [
                        'type' => 'Link',
                        'mcid' => -1,
                        'page' => $page,
                        'objr' => $linkAnnotId,
                    ];
                    $this->structParentLinkKeys[$structIdx] = $structParentKey;
                }
            }
            // Phase 109: markup annotations (Text/Highlight/Underline/StrikeOut/FreeText).
            foreach ($page->markupAnnotations() as $ann) {
                $annotIds[] = $writer->addObject($this->buildMarkupAnnotation($ann));
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

                $body = $this->buildSimpleFieldObject($writer, $field, $pageIds[$i], $namePart, $tooltipPart, $aaPart);
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
            // Phase 110: optional page boxes (/CropBox /BleedBox /TrimBox /ArtBox).
            $boxRef = '';
            foreach ([
                'CropBox' => $page->cropBox(),
                'BleedBox' => $page->bleedBox(),
                'TrimBox' => $page->trimBox(),
                'ArtBox' => $page->artBox(),
            ] as $name => $box) {
                if ($box !== null) {
                    $boxRef .= sprintf(
                        ' /%s [%s %s %s %s]',
                        $name,
                        $this->fmt($box[0]), $this->fmt($box[1]),
                        $this->fmt($box[2]), $this->fmt($box[3]),
                    );
                }
            }

            // Phase 115: Page-level /AA Additional Actions (open/close JavaScript).
            $aaRef = '';
            $openScript = $page->openActionScript();
            $closeScript = $page->closeActionScript();
            if ($openScript !== null || $closeScript !== null) {
                $aaParts = [];
                if ($openScript !== null) {
                    $aaParts[] = '/O << /Type /Action /S /JavaScript /JS '
                        . $this->pdfString($openScript) . ' >>';
                }
                if ($closeScript !== null) {
                    $aaParts[] = '/C << /Type /Action /S /JavaScript /JS '
                        . $this->pdfString($closeScript) . ' >>';
                }
                $aaRef = ' /AA << ' . implode(' ', $aaParts) . ' >>';
            }

            $writer->setObject($pageIds[$i], sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] '
                .'/Contents %d 0 R /Resources %s%s%s%s%s%s%s%s >>',
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
                $boxRef,
                $aaRef,
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
            // Phase 123: reuse appearance font ID if already emitted by
            // appearance-stream builders to avoid duplicate Helvetica objects.
            $defaultFontId = $this->ensureAppearanceFont($writer);
            $daPart = ' /DA (/Helv 11 Tf 0 g)';
            $drPart = sprintf(' /DR << /Font << /Helv %d 0 R >> >>', $defaultFontId);
            // Phase 108: /SigFlags 3 (SignaturesExist | AppendOnly) when signing.
            $sigFlagsPart = ($this->signatureConfig !== null && $this->signatureFieldLinked)
                ? ' /SigFlags 3'
                : '';
            $acroFormId = $writer->addObject(sprintf(
                '<< /Fields [%s] /NeedAppearances true%s%s%s%s >>',
                $fieldsArray, $coRef, $daPart, $drPart, $sigFlagsPart,
            ));
            $acroFormRef = " /AcroForm $acroFormId 0 R";
        }

        // Phase 108: emit signature dictionary body + hook writer для post-emit
        // patching. Validates at least one signature widget received /V.
        if ($this->signatureConfig !== null) {
            if (! $this->signatureFieldLinked) {
                throw new \LogicException(
                    'Document::sign() requires at least one form field of type "signature"',
                );
            }
            $cfg = $this->signatureConfig;
            $sigDictBody = $this->buildSignatureDictBody($cfg);
            $writer->setObject($this->signatureDictId, $sigDictBody);
            $writer->setSignature($cfg, $this->signatureDictId);
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
            // Phase 151: per-Link /StructParent entries — map key к single
            // struct elem reference (NOT an array; OBJR convention).
            $maxParentKey = count($this->pages);
            foreach ($this->structParentLinkKeys as $structIdx => $key) {
                $elemId = $childIds[$structIdx] ?? null;
                if ($elemId !== null) {
                    $parentTreeNums[] = "$key $elemId 0 R";
                    if ($key + 1 > $maxParentKey) {
                        $maxParentKey = $key + 1;
                    }
                }
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
                $maxParentKey,
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

        // Phase 119: Document-level /AA additional actions (Will*/Did*).
        $documentAARef = '';
        if ($this->documentActions !== []) {
            $aaParts = [];
            foreach ($this->documentActions as $event => $script) {
                $aaParts[] = sprintf(
                    '/%s << /Type /Action /S /JavaScript /JS %s >>',
                    $event, $this->pdfString($script),
                );
            }
            $documentAARef = ' /AA << ' . implode(' ', $aaParts) . ' >>';
        }

        // Phase 112: /OCProperties для Optional Content Groups (layers).
        $ocPropertiesRef = '';
        if ($this->layers !== []) {
            $ocgArray = [];
            $onArray = [];
            $offArray = [];
            $orderArray = [];
            foreach ($this->layers as $layer) {
                $id = $layerObjectIds[$layer];
                $ocgArray[] = "$id 0 R";
                $orderArray[] = "$id 0 R";
                if ($layer->defaultVisible) {
                    $onArray[] = "$id 0 R";
                } else {
                    $offArray[] = "$id 0 R";
                }
            }
            $onPart = $onArray === [] ? '' : ' /ON ['.implode(' ', $onArray).']';
            $offPart = $offArray === [] ? '' : ' /OFF ['.implode(' ', $offArray).']';
            $ocPropertiesRef = sprintf(
                ' /OCProperties << /OCGs [%s] /D << /Order [%s]%s%s >> >>',
                implode(' ', $ocgArray),
                implode(' ', $orderArray),
                $onPart,
                $offPart,
            );
        }

        $writer->setObject($catalogId, "<< /Type /Catalog /Pages $pagesId 0 R$namesRef$outlinesRef$acroFormRef$pdfARef$taggedRef$openActionRef$pageModeRef$pageLayoutRef$pageLabelsRef$viewerPrefsRef$langRef$ocPropertiesRef$documentAARef >>");

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
            if ($enc->algorithm === EncryptionAlgorithm::Aes_256
                || $enc->algorithm === EncryptionAlgorithm::Aes_256_R6) {
                // V5 R5 (Adobe Supplement) или V5 R6 (PDF 2.0) + Crypt Filter AESV3.
                $revision = $enc->algorithm === EncryptionAlgorithm::Aes_256_R6 ? 6 : 5;
                $oeHex = bin2hex($enc->oeValue);
                $ueHex = bin2hex($enc->ueValue);
                $permsHex = bin2hex($enc->permsValue);
                $encryptBody = sprintf(
                    '<< /Filter /Standard /V 5 /R %d /Length 256 '
                    .'/CF << /StdCF << /CFM /AESV3 /Length 32 /AuthEvent /DocOpen >> >> '
                    .'/StmF /StdCF /StrF /StdCF '
                    .'/O <%s> /U <%s> /OE <%s> /UE <%s> /Perms <%s> /P %d >>',
                    $revision, $oHex, $uHex, $oeHex, $ueHex, $permsHex, $enc->permissions,
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

        return $writer;
    }

    /**
     * Сериализует в файл. Возвращает количество записанных байт.
     *
     * Phase 129: использует streaming Writer::toStream для избежания
     * full-document копии в string memory.
     */
    public function toFile(string $path): int
    {
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('Failed to open ' . $path . ' for writing');
        }
        try {
            return $this->toStream($fp);
        } finally {
            fclose($fp);
        }
    }

    /**
     * Phase 129: Streaming PDF output к external stream resource.
     *
     * Use case: large PDFs (тысячи страниц), HTTP response без full
     * document in memory, file output без double buffering.
     *
     * Currently emits final-document к stream (objects accumulated в
     * memory as before, но final assembly streamed). Полный per-object
     * streaming требует deeper API rewrite — отложен.
     *
     * @param  resource  $stream
     * @return int  bytes written
     */
    public function toStream($stream): int
    {
        $writer = $this->buildWriter();

        return $writer->toStream($stream);
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
    private function buildSimpleFieldObject(Writer $writer, array $field, int $pageId, string $namePart, string $tooltipPart, string $aaPart = ''): string
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
            // Phase 123: appearance stream rendering the field value.
            $apId = $this->buildTextFieldAppearance(
                $writer,
                (float) $field['w'], (float) $field['h'],
                $type === 'password' ? str_repeat('*', mb_strlen((string) $field['defaultValue'], 'UTF-8')) : (string) $field['defaultValue'],
                multiline: $type === 'text-multiline',
            );
            $apRef = sprintf(' /AP << /N %d 0 R >>', $apId);

            return sprintf(
                '<< /Type /Annot /Subtype /Widget /FT /Tx /Rect %s '
                .'%s%s%s /Ff %d /P %d 0 R%s%s >>',
                $rect, $namePart, $valuePart, $tooltipPart, $flags, $pageId, $apRef, $aaPart,
            );
        }
        if ($type === 'checkbox') {
            $isChecked = strcasecmp($field['defaultValue'], 'on') === 0
                || strcasecmp($field['defaultValue'], 'yes') === 0
                || $field['defaultValue'] === '1';
            $vPart = $isChecked ? ' /V /Yes /DV /Yes' : ' /V /Off /DV /Off';
            // Phase 123: AP dict with both states (Yes + Off).
            $yesId = $this->buildCheckboxAppearance($writer, (float) $field['w'], (float) $field['h'], checked: true);
            $offId = $this->buildCheckboxAppearance($writer, (float) $field['w'], (float) $field['h'], checked: false);
            $apRef = sprintf(' /AP << /N << /Yes %d 0 R /Off %d 0 R >> >>', $yesId, $offId);

            return sprintf(
                '<< /Type /Annot /Subtype /Widget /FT /Btn /Rect %s '
                .'%s%s%s /Ff %d /P %d 0 R /AS %s%s%s >>',
                $rect, $namePart, $vPart, $tooltipPart, $flags, $pageId,
                $isChecked ? '/Yes' : '/Off',
                $apRef, $aaPart,
            );
        }
        if ($type === 'signature') {
            // Phase 108: signature widget references signature dictionary
            // через /V; only first signature widget receives /V (subsequent
            // widgets remain unsigned placeholders).
            $vPart = '';
            if ($this->signatureDictId !== null && ! $this->signatureFieldLinked) {
                $vPart = sprintf(' /V %d 0 R', $this->signatureDictId);
                $this->signatureFieldLinked = true;
            }
            // Phase 123: minimal appearance (blank box with optional caption).
            $apId = $this->buildSignatureAppearance($writer, (float) $field['w'], (float) $field['h']);
            $apRef = sprintf(' /AP << /N %d 0 R >>', $apId);

            return sprintf(
                '<< /Type /Annot /Subtype /Widget /FT /Sig /Rect %s '
                .'%s%s%s /Ff %d /P %d 0 R%s%s >>',
                $rect, $namePart, $vPart, $tooltipPart, $flags, $pageId, $apRef, $aaPart,
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
            // Phase 123: appearance — bordered rectangle с caption.
            $apId = $this->buildButtonAppearance(
                $writer, (float) $field['w'], (float) $field['h'], (string) $caption,
            );
            $apRef = sprintf(' /AP << /N %d 0 R >>', $apId);

            return sprintf(
                '<< /Type /Annot /Subtype /Widget /FT /Btn /Rect %s '
                .'%s%s /Ff %d /P %d 0 R%s%s%s%s >>',
                $rect, $namePart, $tooltipPart, $flags, $pageId,
                $mkPart, $actionPart, $apRef, $aaPart,
            );
        }
        if ($type === 'combo' || $type === 'list') {
            $optsArray = '['.implode(' ', array_map(fn ($o) => $this->pdfString($o), $field['options'])).']';
            $valuePart = $field['defaultValue'] !== ''
                ? ' /V '.$this->pdfString($field['defaultValue'])
                    .' /DV '.$this->pdfString($field['defaultValue'])
                : '';
            // Phase 123: appearance shows the selected value (single line).
            $apId = $this->buildTextFieldAppearance(
                $writer, (float) $field['w'], (float) $field['h'],
                (string) $field['defaultValue'], multiline: false,
            );
            $apRef = sprintf(' /AP << /N %d 0 R >>', $apId);

            return sprintf(
                '<< /Type /Annot /Subtype /Widget /FT /Ch /Rect %s '
                .'%s%s%s /Opt %s /Ff %d /P %d 0 R%s%s >>',
                $rect, $namePart, $valuePart, $tooltipPart, $optsArray, $flags, $pageId, $apRef, $aaPart,
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
            // Phase 123: appearance — selected (filled circle) + Off (empty).
            $onId = $this->buildRadioAppearance($writer, (float) $widget['w'], (float) $widget['h'], selected: true);
            $offId = $this->buildRadioAppearance($writer, (float) $widget['w'], (float) $widget['h'], selected: false);
            $exportKey = ltrim($exportName, '/');
            $apRef = sprintf(' /AP << /N << /%s %d 0 R /Off %d 0 R >> >>', $exportKey, $onId, $offId);
            $body = sprintf(
                '<< /Type /Annot /Subtype /Widget /Rect %s '
                .'/Parent %d 0 R /AS %s%s >>',
                $rect, $parentId, $isChecked ? $exportName : '/Off', $apRef,
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
     * Phase 123: lazy-init Helvetica font object для appearance streams.
     */
    private function ensureAppearanceFont(Writer $writer): int
    {
        if ($this->appearanceFontId === null) {
            $this->appearanceFontId = $writer->addObject(
                '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica '
                .'/Encoding /WinAnsiEncoding >>',
            );
        }

        return $this->appearanceFontId;
    }

    /**
     * Phase 123: lazy-init ZapfDingbats font для checkbox/radio glyphs.
     */
    private function ensureAppearanceZapfFont(Writer $writer): int
    {
        if ($this->appearanceZapfId === null) {
            $this->appearanceZapfId = $writer->addObject(
                '<< /Type /Font /Subtype /Type1 /BaseFont /ZapfDingbats >>',
            );
        }

        return $this->appearanceZapfId;
    }

    /**
     * Phase 123: emit a Form XObject as appearance stream.
     */
    private function emitAppearanceStream(Writer $writer, float $w, float $h, string $content, string $resources): int
    {
        $bbox = sprintf('[0 0 %s %s]', $this->fmt($w), $this->fmt($h));
        if ($this->compressStreams) {
            $compressed = (string) gzcompress($content, 6);
            $body = sprintf(
                "<< /Type /XObject /Subtype /Form /BBox %s /Resources %s /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
                $bbox, $resources, strlen($compressed), $compressed,
            );
        } else {
            $body = sprintf(
                "<< /Type /XObject /Subtype /Form /BBox %s /Resources %s /Length %d >>\nstream\n%s\nendstream",
                $bbox, $resources, strlen($content), $content,
            );
        }

        return $writer->addObject($body);
    }

    /**
     * Phase 123: build text field appearance Form XObject.
     */
    private function buildTextFieldAppearance(Writer $writer, float $w, float $h, string $text, bool $multiline): int
    {
        $fontId = $this->ensureAppearanceFont($writer);
        $resources = sprintf('<< /Font << /Helv %d 0 R >> >>', $fontId);

        $fontSize = 11.0;
        // Vertical center for single-line; top-aligned для multiline.
        $padding = 2.0;
        $textY = $multiline
            ? ($h - $fontSize - $padding)
            : (($h - $fontSize) / 2.0 + 1.0);
        $textPdf = $text === ''
            ? ''
            : sprintf("BT\n/Helv %s Tf\n0 g\n%s %s Td\n%s Tj\nET\n",
                $this->fmt($fontSize),
                $this->fmt($padding), $this->fmt($textY),
                $this->pdfString($text),
            );
        // q .. Q wrap + clip к bbox.
        $content = sprintf(
            "q\n0 0 %s %s re W n\n%sQ\n",
            $this->fmt($w), $this->fmt($h), $textPdf,
        );

        return $this->emitAppearanceStream($writer, $w, $h, $content, $resources);
    }

    /**
     * Phase 123: build checkbox appearance Form XObject — uses ZapfDingbats
     * check mark glyph для checked state.
     */
    private function buildCheckboxAppearance(Writer $writer, float $w, float $h, bool $checked): int
    {
        $resources = '<< >>';
        if ($checked) {
            $zaId = $this->ensureAppearanceZapfFont($writer);
            $resources = sprintf('<< /Font << /ZaDb %d 0 R >> >>', $zaId);
            // ZapfDingbats octal 064 (decimal 52) = '4' code = ✓.
            $fontSize = min($w, $h) * 0.85;
            $tx = ($w - $fontSize * 0.7) / 2.0;
            $ty = ($h - $fontSize) / 2.0 + $fontSize * 0.15;
            $content = sprintf(
                "q\n1 w\n0 0 %s %s re S\nBT\n/ZaDb %s Tf\n0 g\n%s %s Td\n(4) Tj\nET\nQ\n",
                $this->fmt($w), $this->fmt($h),
                $this->fmt($fontSize),
                $this->fmt($tx), $this->fmt($ty),
            );
        } else {
            // Empty bordered box.
            $content = sprintf(
                "q\n1 w\n0 0 %s %s re S\nQ\n",
                $this->fmt($w), $this->fmt($h),
            );
        }

        return $this->emitAppearanceStream($writer, $w, $h, $content, $resources);
    }

    /**
     * Phase 123: build push/submit/reset button appearance — bordered grey
     * box с centered caption.
     */
    private function buildButtonAppearance(Writer $writer, float $w, float $h, string $caption): int
    {
        $fontId = $this->ensureAppearanceFont($writer);
        $resources = sprintf('<< /Font << /Helv %d 0 R >> >>', $fontId);
        $fontSize = 11.0;
        // Approximate caption width — Helvetica avg ~5pt per char @ 11pt.
        $captionWidth = strlen($caption) * $fontSize * 0.5;
        $tx = max(2.0, ($w - $captionWidth) / 2.0);
        $ty = ($h - $fontSize) / 2.0 + 1.0;
        $content = sprintf(
            "q\n0.9 0.9 0.9 rg\n0 0 %s %s re f\n0.5 w\n0 0 %s %s re S\nBT\n/Helv %s Tf\n0 g\n%s %s Td\n%s Tj\nET\nQ\n",
            $this->fmt($w), $this->fmt($h),
            $this->fmt($w), $this->fmt($h),
            $this->fmt($fontSize),
            $this->fmt($tx), $this->fmt($ty),
            $this->pdfString($caption),
        );

        return $this->emitAppearanceStream($writer, $w, $h, $content, $resources);
    }

    /**
     * Phase 123: build signature widget appearance — empty bordered box.
     */
    private function buildSignatureAppearance(Writer $writer, float $w, float $h): int
    {
        $content = sprintf(
            "q\n0.5 w\n0 0 %s %s re S\nQ\n",
            $this->fmt($w), $this->fmt($h),
        );

        return $this->emitAppearanceStream($writer, $w, $h, $content, '<< >>');
    }

    /**
     * Phase 123: build radio button appearance (selected = filled circle,
     * unselected = empty circle).
     */
    private function buildRadioAppearance(Writer $writer, float $w, float $h, bool $selected): int
    {
        $resources = '<< >>';
        $cx = $w / 2.0;
        $cy = $h / 2.0;
        $r = min($w, $h) / 2.0 - 1.0;
        // Cubic-bezier circle approximation (kappa ≈ 0.5523).
        $k = $r * 0.5523;
        $border = sprintf(
            "%s %s m\n"
            ."%s %s %s %s %s %s c\n"
            ."%s %s %s %s %s %s c\n"
            ."%s %s %s %s %s %s c\n"
            ."%s %s %s %s %s %s c\n",
            $this->fmt($cx + $r), $this->fmt($cy),
            $this->fmt($cx + $r), $this->fmt($cy + $k),
            $this->fmt($cx + $k), $this->fmt($cy + $r),
            $this->fmt($cx),      $this->fmt($cy + $r),
            $this->fmt($cx - $k), $this->fmt($cy + $r),
            $this->fmt($cx - $r), $this->fmt($cy + $k),
            $this->fmt($cx - $r), $this->fmt($cy),
            $this->fmt($cx - $r), $this->fmt($cy - $k),
            $this->fmt($cx - $k), $this->fmt($cy - $r),
            $this->fmt($cx),      $this->fmt($cy - $r),
            $this->fmt($cx + $k), $this->fmt($cy - $r),
            $this->fmt($cx + $r), $this->fmt($cy - $k),
            $this->fmt($cx + $r), $this->fmt($cy),
        );
        if ($selected) {
            // Filled smaller circle inside.
            $rIn = $r * 0.55;
            $kIn = $rIn * 0.5523;
            $fill = sprintf(
                "%s %s m\n"
                ."%s %s %s %s %s %s c\n"
                ."%s %s %s %s %s %s c\n"
                ."%s %s %s %s %s %s c\n"
                ."%s %s %s %s %s %s c\n",
                $this->fmt($cx + $rIn), $this->fmt($cy),
                $this->fmt($cx + $rIn), $this->fmt($cy + $kIn),
                $this->fmt($cx + $kIn), $this->fmt($cy + $rIn),
                $this->fmt($cx),        $this->fmt($cy + $rIn),
                $this->fmt($cx - $kIn), $this->fmt($cy + $rIn),
                $this->fmt($cx - $rIn), $this->fmt($cy + $kIn),
                $this->fmt($cx - $rIn), $this->fmt($cy),
                $this->fmt($cx - $rIn), $this->fmt($cy - $kIn),
                $this->fmt($cx - $kIn), $this->fmt($cy - $rIn),
                $this->fmt($cx),        $this->fmt($cy - $rIn),
                $this->fmt($cx + $kIn), $this->fmt($cy - $rIn),
                $this->fmt($cx + $rIn), $this->fmt($cy - $kIn),
                $this->fmt($cx + $rIn), $this->fmt($cy),
            );
            $content = sprintf(
                "q\n1 w\n%sS\n0 g\n%sf\nQ\n",
                $border, $fill,
            );
        } else {
            $content = sprintf(
                "q\n1 w\n%sS\nQ\n",
                $border,
            );
        }

        return $this->emitAppearanceStream($writer, $w, $h, $content, $resources);
    }

    /**
     * Phase 109: build markup annotation body (Text/Highlight/Underline/
     * StrikeOut/FreeText).
     *
     * @param  array<string, mixed>  $ann
     */
    private function buildMarkupAnnotation(array $ann): string
    {
        $rect = sprintf(
            '[%s %s %s %s]',
            $this->fmt((float) $ann['x1']), $this->fmt((float) $ann['y1']),
            $this->fmt((float) $ann['x2']), $this->fmt((float) $ann['y2']),
        );
        $contentsPart = '';
        if (! empty($ann['contents'])) {
            $contentsPart = ' /Contents ' . $this->pdfString((string) $ann['contents']);
        }
        $colorPart = '';
        if (! empty($ann['color'])) {
            $color = $ann['color'];
            $colorPart = sprintf(' /C [%s %s %s]', $this->fmt((float) $color[0]), $this->fmt((float) $color[1]), $this->fmt((float) $color[2]));
        }
        $titlePart = '';
        if (! empty($ann['title'])) {
            $titlePart = ' /T ' . $this->pdfString((string) $ann['title']);
        }

        switch ($ann['kind']) {
            case 'text':
                return sprintf(
                    '<< /Type /Annot /Subtype /Text /Rect %s%s%s%s /Name /%s >>',
                    $rect, $contentsPart, $titlePart, $colorPart, $ann['icon'],
                );
            case 'highlight':
            case 'underline':
            case 'strikeout':
                $subtype = match ($ann['kind']) {
                    'highlight' => 'Highlight',
                    'underline' => 'Underline',
                    'strikeout' => 'StrikeOut',
                };
                // QuadPoints — 8 numbers per quad: (x1 y1) bot-left, (x2 y2) bot-right,
                // (x3 y3) top-left, (x4 y4) top-right (PDF spec §12.5.6.10 ordering varies
                // by vendor; this matches Acrobat default).
                $qp = sprintf(
                    '[%s %s %s %s %s %s %s %s]',
                    $this->fmt((float) $ann['x1']), $this->fmt((float) $ann['y2']),
                    $this->fmt((float) $ann['x2']), $this->fmt((float) $ann['y2']),
                    $this->fmt((float) $ann['x1']), $this->fmt((float) $ann['y1']),
                    $this->fmt((float) $ann['x2']), $this->fmt((float) $ann['y1']),
                );

                return sprintf(
                    '<< /Type /Annot /Subtype /%s /Rect %s /QuadPoints %s%s%s >>',
                    $subtype, $rect, $qp, $contentsPart, $colorPart,
                );
            case 'freetext':
                $fontSize = (float) ($ann['fontSize'] ?? 11.0);
                // /DA — default appearance string. Color: use /C если задан, else black.
                $daColor = '0 g';
                if (! empty($ann['color'])) {
                    $color = $ann['color'];
                    $daColor = sprintf(
                        '%s %s %s rg',
                        $this->fmt((float) $color[0]),
                        $this->fmt((float) $color[1]),
                        $this->fmt((float) $color[2]),
                    );
                }
                $da = sprintf('(/Helv %s Tf %s)', $this->fmt($fontSize), $daColor);

                return sprintf(
                    '<< /Type /Annot /Subtype /FreeText /Rect %s%s /DA %s >>',
                    $rect, $contentsPart, $da,
                );
            case 'square':
            case 'circle':
                $subtype = $ann['kind'] === 'square' ? 'Square' : 'Circle';
                $bs = sprintf(' /BS << /Type /Border /W %s /S /S >>', $this->fmt((float) $ann['borderWidth']));
                $icPart = '';
                if (! empty($ann['fillColor'])) {
                    $fc = $ann['fillColor'];
                    $icPart = sprintf(' /IC [%s %s %s]',
                        $this->fmt((float) $fc[0]), $this->fmt((float) $fc[1]), $this->fmt((float) $fc[2]));
                }

                return sprintf(
                    '<< /Type /Annot /Subtype /%s /Rect %s%s%s%s%s >>',
                    $subtype, $rect, $colorPart, $icPart, $bs, $contentsPart,
                );
            case 'line':
                $linePart = sprintf(' /L [%s %s %s %s]',
                    $this->fmt((float) $ann['lineX1']), $this->fmt((float) $ann['lineY1']),
                    $this->fmt((float) $ann['lineX2']), $this->fmt((float) $ann['lineY2']),
                );
                $bs = sprintf(' /BS << /Type /Border /W %s /S /S >>', $this->fmt((float) $ann['borderWidth']));

                return sprintf(
                    '<< /Type /Annot /Subtype /Line /Rect %s%s%s%s%s >>',
                    $rect, $linePart, $colorPart, $bs, $contentsPart,
                );
            case 'stamp':
                return sprintf(
                    '<< /Type /Annot /Subtype /Stamp /Rect %s /Name /%s%s >>',
                    $rect, $ann['stampName'], $contentsPart,
                );
            case 'ink':
                $strokeArrays = [];
                foreach ($ann['inkStrokes'] as $stroke) {
                    $pts = [];
                    foreach ($stroke as [$x, $y]) {
                        $pts[] = $this->fmt((float) $x) . ' ' . $this->fmt((float) $y);
                    }
                    $strokeArrays[] = '[' . implode(' ', $pts) . ']';
                }
                $inkList = ' /InkList [' . implode(' ', $strokeArrays) . ']';
                $bs = sprintf(' /BS << /Type /Border /W %s /S /S >>', $this->fmt((float) $ann['borderWidth']));

                return sprintf(
                    '<< /Type /Annot /Subtype /Ink /Rect %s%s%s%s%s >>',
                    $rect, $inkList, $colorPart, $bs, $contentsPart,
                );
            case 'polygon':
            case 'polyline':
                $subtype = $ann['kind'] === 'polygon' ? 'Polygon' : 'PolyLine';
                $verts = [];
                foreach ($ann['vertices'] as [$vx, $vy]) {
                    $verts[] = $this->fmt((float) $vx) . ' ' . $this->fmt((float) $vy);
                }
                $verticesPart = ' /Vertices [' . implode(' ', $verts) . ']';
                $icPart = '';
                if (! empty($ann['fillColor'])) {
                    $fc = $ann['fillColor'];
                    $icPart = sprintf(' /IC [%s %s %s]',
                        $this->fmt((float) $fc[0]), $this->fmt((float) $fc[1]), $this->fmt((float) $fc[2]));
                }
                $bs = sprintf(' /BS << /Type /Border /W %s /S /S >>', $this->fmt((float) $ann['borderWidth']));

                return sprintf(
                    '<< /Type /Annot /Subtype /%s /Rect %s%s%s%s%s%s >>',
                    $subtype, $rect, $verticesPart, $colorPart, $icPart, $bs, $contentsPart,
                );
            default:
                throw new \LogicException('Unknown markup annotation kind: ' . $ann['kind']);
        }
    }

    /**
     * Phase 108: build PKCS#7 signature dictionary body с placeholders
     * для /ByteRange (4×10-digit zero fields, padded с spaces) и /Contents
     * (16384 hex zeros = room для 8KB DER PKCS#7 envelope).
     */
    private function buildSignatureDictBody(SignatureConfig $cfg): string
    {
        $optionalParts = '';
        if ($cfg->signerName !== null) {
            $optionalParts .= ' /Name ' . $this->pdfString($cfg->signerName);
        }
        if ($cfg->reason !== null) {
            $optionalParts .= ' /Reason ' . $this->pdfString($cfg->reason);
        }
        if ($cfg->location !== null) {
            $optionalParts .= ' /Location ' . $this->pdfString($cfg->location);
        }
        if ($cfg->contactInfo !== null) {
            $optionalParts .= ' /ContactInfo ' . $this->pdfString($cfg->contactInfo);
        }
        $signedAt = ' /M ' . $this->pdfString($cfg->pdfSignedAt());

        // ByteRange: 4 entries padded к 10 digits each + единичный pad
        // space, чтобы post-emit substr_replace fit обновлённые values.
        $byteRange = '/ByteRange [0          0          0          0         ]';
        // Contents: 16384 hex zeros (room для ~8KB PKCS#7 DER blob).
        $contents = '/Contents <' . str_repeat('0', 16384) . '>';

        return sprintf(
            '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached '
            .'%s%s%s %s >>',
            $byteRange, $contents, $signedAt, $optionalParts,
        );
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
