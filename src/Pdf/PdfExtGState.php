<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

/**
 * Phase 31: PDF Extended Graphics State (ExtGState).
 *
 * Описывает параметры graphics state, которые не выражаются inline
 * операторами (например opacity / transparency). Применяется в content
 * stream через оператор `<name> gs`.
 *
 * ISO 32000-1 §8.4.5 / §11.3 — Transparent imaging model.
 * Для прозрачности нужен PDF 1.4+. php-pdf header'ит 1.7 → OK.
 *
 * Поля:
 *  - $fillOpacity (/ca, 0..1) — alpha для fill operators (включая image Do).
 *  - $strokeOpacity (/CA, 0..1) — alpha для stroke operators.
 *
 * Хеширование: VO с readonly + равенство по содержимому делегируется
 * вызывающему коду (см. Page::registerExtGState).
 */
final readonly class PdfExtGState
{
    public function __construct(
        public ?float $fillOpacity = null,
        public ?float $strokeOpacity = null,
    ) {}

    /**
     * Уникальный ключ для dedup'а внутри page (one ExtGState на
     * comparable opacity tuple).
     */
    public function key(): string
    {
        return sprintf(
            'ca=%s,CA=%s',
            $this->fillOpacity === null ? '-' : (string) $this->fillOpacity,
            $this->strokeOpacity === null ? '-' : (string) $this->strokeOpacity,
        );
    }

    /**
     * Body для PDF object emission.
     */
    public function toDictBody(): string
    {
        $parts = ['/Type /ExtGState'];
        if ($this->fillOpacity !== null) {
            $parts[] = '/ca '.$this->formatNumber($this->fillOpacity);
        }
        if ($this->strokeOpacity !== null) {
            $parts[] = '/CA '.$this->formatNumber($this->strokeOpacity);
        }

        return '<< '.implode(' ', $parts).' >>';
    }

    private function formatNumber(float $n): string
    {
        if ($n === floor($n)) {
            return (string) (int) $n;
        }

        return rtrim(rtrim(sprintf('%.4F', $n), '0'), '.');
    }
}
