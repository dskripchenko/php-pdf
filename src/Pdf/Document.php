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

    /** Phase 47: PDF/A-1b configuration. */
    private ?PdfAConfig $pdfA = null;

    /** Phase 48: Tagged PDF mode (accessibility). */
    private bool $tagged = false;

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
    public function registerOutlineEntry(int $level, string $title, Page $page, float $x, float $y): self
    {
        $this->outlineEntries[] = compact('level', 'title', 'page', 'x', 'y');

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
                $annotIds[] = $writer->addObject($body);
            }
            // Phase 43+46: AcroForm widgets — emit widget annotations +
            // collect field object IDs для AcroForm.Fields array.
            foreach ($page->formFields() as $field) {
                $tooltipPart = $field['tooltip'] !== null
                    ? ' /TU '.$this->pdfString($field['tooltip'])
                    : '';
                $namePart = '/T '.$this->pdfString($field['name']);

                if ($field['type'] === 'radio-group') {
                    [$parentId, $childIds] = $this->emitRadioGroupFields(
                        $writer, $pageIds[$i], $field, $namePart, $tooltipPart,
                    );
                    // Parent объект in /Fields; children in page /Annots.
                    foreach ($childIds as $cid) {
                        $annotIds[] = $cid;
                    }
                    $this->collectedFormFieldIds[] = $parentId;

                    continue;
                }

                $body = $this->buildSimpleFieldObject($field, $pageIds[$i], $namePart, $tooltipPart);
                $fieldId = $writer->addObject($body);
                $annotIds[] = $fieldId;
                $this->collectedFormFieldIds[] = $fieldId;
            }
            $annotsRef = $annotIds === []
                ? ''
                : ' /Annots ['.implode(' ', array_map(fn ($id) => "$id 0 R", $annotIds)).']';

            $writer->setObject($pageIds[$i], sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] '
                .'/Contents %d 0 R /Resources %s%s >>',
                $pagesId,
                $this->fmt($page->widthPt()),
                $this->fmt($page->heightPt()),
                $contentId,
                $resourcesDict,
                $annotsRef,
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

        // Phase 43: AcroForm reference в Catalog.
        $acroFormRef = '';
        if ($this->collectedFormFieldIds !== []) {
            $fieldsArray = implode(' ', array_map(fn ($id) => "$id 0 R", $this->collectedFormFieldIds));
            $acroFormId = $writer->addObject(sprintf(
                '<< /Fields [%s] /NeedAppearances true >>',
                $fieldsArray,
            ));
            $acroFormRef = " /AcroForm $acroFormId 0 R";
        }

        // Phase 48: Tagged PDF — StructTreeRoot + StructElem children +
        // MarkInfo dict.
        $taggedRef = '';
        if ($this->tagged && $this->structElements !== []) {
            // Reserve root ID first (children reference it as /P).
            $structRootId = $writer->reserveObject();
            $childIds = [];
            foreach ($this->structElements as $elem) {
                $pageId = $pageObjectIdMap[$elem['page']] ?? null;
                if ($pageId === null) {
                    continue; // Page not registered — shouldn't happen.
                }
                $altPart = '';
                if (! empty($elem['altText'])) {
                    $altPart = ' /Alt '.$this->pdfString((string) $elem['altText']);
                }
                $body = sprintf(
                    '<< /Type /StructElem /S /%s /P %d 0 R '
                    .'/Pg %d 0 R /K %d%s >>',
                    $elem['type'], $structRootId, $pageId, $elem['mcid'], $altPart,
                );
                $childIds[] = $writer->addObject($body);
            }
            $kidsArray = '['.implode(' ', array_map(fn ($id) => "$id 0 R", $childIds)).']';
            $writer->setObject($structRootId, sprintf(
                '<< /Type /StructTreeRoot /K %s >>',
                $kidsArray,
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
        $writer->setObject($catalogId, "<< /Type /Catalog /Pages $pagesId 0 R$namesRef$outlinesRef$acroFormRef$pdfARef$taggedRef >>");

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
     * Phase 43+46: build single-widget AcroForm field object body.
     *
     * @param  array<string, mixed>  $field
     */
    private function buildSimpleFieldObject(array $field, int $pageId, string $namePart, string $tooltipPart): string
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
                .'%s%s%s /Ff %d /P %d 0 R >>',
                $rect, $namePart, $valuePart, $tooltipPart, $flags, $pageId,
            );
        }
        if ($type === 'checkbox') {
            $isChecked = strcasecmp($field['defaultValue'], 'on') === 0
                || strcasecmp($field['defaultValue'], 'yes') === 0
                || $field['defaultValue'] === '1';
            $vPart = $isChecked ? ' /V /Yes /DV /Yes' : ' /V /Off /DV /Off';

            return sprintf(
                '<< /Type /Annot /Subtype /Widget /FT /Btn /Rect %s '
                .'%s%s%s /Ff %d /P %d 0 R /AS %s >>',
                $rect, $namePart, $vPart, $tooltipPart, $flags, $pageId,
                $isChecked ? '/Yes' : '/Off',
            );
        }
        if ($type === 'signature') {
            // Phase 56: Signature field — placeholder без real signing.
            // /V можно set later through external signing tools.
            return sprintf(
                '<< /Type /Annot /Subtype /Widget /FT /Sig /Rect %s '
                .'%s%s /Ff %d /P %d 0 R >>',
                $rect, $namePart, $tooltipPart, $flags, $pageId,
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
                .'%s%s%s /Opt %s /Ff %d /P %d 0 R >>',
                $rect, $namePart, $valuePart, $tooltipPart, $optsArray, $flags, $pageId,
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
