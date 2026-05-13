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

    private string $pdfVersion = '1.7';

    public function __construct(
        public PaperSize $defaultPaperSize = PaperSize::A4,
        public Orientation $defaultOrientation = Orientation::Portrait,
    ) {}

    public static function new(
        PaperSize $defaultPaperSize = PaperSize::A4,
        Orientation $defaultOrientation = Orientation::Portrait,
    ): self {
        return new self($defaultPaperSize, $defaultOrientation);
    }

    public function pdfVersion(string $version): self
    {
        $this->pdfVersion = $version;

        return $this;
    }

    /**
     * Добавляет новую page. Если paperSize/orientation не переданы —
     * используется document-level default.
     */
    public function addPage(?PaperSize $paperSize = null, ?Orientation $orientation = null): Page
    {
        $page = new Page(
            $paperSize ?? $this->defaultPaperSize,
            $orientation ?? $this->defaultOrientation,
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
                    $embeddedFontObjectIds[$f] = $f->registerWith($writer);
                }
            }
        }

        // Images.
        /** @var \SplObjectStorage<\Dskripchenko\PhpPdf\Image\PdfImage, int> */
        $imageObjectIds = new \SplObjectStorage;
        foreach ($this->pages as $page) {
            foreach ($page->images() as $img) {
                if (! isset($imageObjectIds[$img])) {
                    $imageObjectIds[$img] = $img->registerWith($writer);
                }
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
            $contentId = $writer->addObject(sprintf(
                "<< /Length %d >>\nstream\n%sendstream",
                strlen($contentStreamBody),
                $contentStreamBody,
            ));

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

            $resourcesParts = [];
            if ($resourcesFont !== '') {
                $resourcesParts[] = '/Font <<'.$resourcesFont.' >>';
            }
            if ($resourcesXObj !== '') {
                $resourcesParts[] = '/XObject <<'.$resourcesXObj.' >>';
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
        if ($this->namedDestinations !== []) {
            $entries = [];
            // ISO 32000-1 §7.9.6: names в Names array должны быть sorted
            // ASCII-asc.
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
            $namesId = $writer->addObject(sprintf('<< /Dests %d 0 R >>', $destsId));
            $namesRef = ' /Names '.$namesId.' 0 R';
        }

        // 5. Pages tree (after все pages созданы — знаем все IDs).
        $kidsRefs = implode(' ', array_map(fn ($id) => "$id 0 R", $pageIds));
        $writer->setObject($pagesId, sprintf(
            '<< /Type /Pages /Kids [%s] /Count %d >>',
            $kidsRefs, count($pageIds),
        ));

        // 6. Catalog.
        $writer->setObject($catalogId, "<< /Type /Catalog /Pages $pagesId 0 R$namesRef >>");

        $writer->setRoot($catalogId);

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
