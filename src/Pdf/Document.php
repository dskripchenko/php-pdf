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

        // 3. Создаём Page objects + content streams.
        $pageIds = [];
        foreach ($this->pages as $page) {
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

            $pageIds[] = $writer->addObject(sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] '
                .'/Contents %d 0 R /Resources %s >>',
                $pagesId,
                $this->fmt($page->widthPt()),
                $this->fmt($page->heightPt()),
                $contentId,
                $resourcesDict,
            ));
        }

        // 4. Pages tree (after все pages созданы — знаем все IDs).
        $kidsRefs = implode(' ', array_map(fn ($id) => "$id 0 R", $pageIds));
        $writer->setObject($pagesId, sprintf(
            '<< /Type /Pages /Kids [%s] /Count %d >>',
            $kidsRefs, count($pageIds),
        ));

        // 5. Catalog.
        $writer->setObject($catalogId, "<< /Type /Catalog /Pages $pagesId 0 R >>");

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
