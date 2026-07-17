# Миграция с mpdf

`mpdf/mpdf` распространяется под GPL-2.0-only: включение его в проприетарный
или OEM-продукт требует либо поставки под GPL, либо коммерческой лицензии.
`dskripchenko/php-pdf` — MIT, и при этом [быстрее](BENCHMARKS.md) во всех
измеряемых нами HTML→PDF сценариях.

Есть два маршрута миграции; ниже оба.

## Маршрут 1 — compat-фасад (самый быстрый)

Для подавляюще типового использования mpdf — `WriteHTML()` + `Output()` —
достаточно поменять импорт, не трогая вызовы:

```php
// было
$mpdf = new \Mpdf\Mpdf(['format' => 'A4', 'margin_left' => 15]);
$mpdf->WriteHTML($html);
$mpdf->Output('invoice.pdf', 'F');

// стало
$mpdf = new \Dskripchenko\PhpPdf\Compat\Mpdf(['format' => 'A4', 'margin_left' => 15]);
$mpdf->WriteHTML($html);
$mpdf->Output('invoice.pdf', 'F');
```

Фасад покрывает: повторные вызовы `WriteHTML()`, `AddPage()`, все четыре
назначения `Output()` (`F` файл / `S` строка / `D` скачивание / `I`
инлайн), `SetTitle` / `SetAuthor` / `SetCreator` / `SetSubject` /
`SetKeywords`, конфиг-ключи `format` (включая суффиксы `A4-L`),
`orientation`, `margin_left/right/top/bottom` (в мм, как в mpdf).

Сознательно **не** покрыты: mpdf-специфичные HTML-расширения
(`<pagebreak>`, `<barcode>`, `<watermarktext>`, …), режимы `WriteHTML()`,
шорткоды `SetHeader`/`SetFooter` и массивы font-конфигурации — здесь
нативный API (маршрут 2) строго лучше. `toDocument()` отдаёт собранный
нативный `Document`, когда фасада становится мало.

**Нелатинский текст:** mpdf возит с собой DejaVu и подхватывает его сам.
Движок фасада по умолчанию использует base-14 шрифты PDF (WinAnsi —
только латиница). Для кириллицы/греческого/арабского/CJK передайте движок
со встроенными TTF:

```php
use Dskripchenko\PhpPdf\Compat\Mpdf;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;

$engine = new Engine(defaultFont: new PdfFont(TtfFile::fromFile('/path/DejaVuSans.ttf')));
$mpdf = new Mpdf([], $engine);
```

## Маршрут 2 — нативный API

| mpdf | php-pdf |
|---|---|
| `new \Mpdf\Mpdf()` | *(для HTML-входа конфиг-объект не нужен)* |
| `$mpdf->WriteHTML($html)` | `$doc = Document::fromHtml($html)` |
| `$mpdf->Output($f, 'F')` | `$doc->toFile($f)` |
| `$mpdf->Output('', 'S')` | `$doc->toBytes()` |
| `$mpdf->Output('x.pdf', 'D')` | заголовки + `echo $doc->toBytes()` (Laravel: `response()->pdf(...)` из dskripchenko/laravel-php-pdf) |
| `$mpdf->Output('', 'I')` | заголовки + `echo $doc->toBytes()` |
| `['format' => 'A4-L', 'margin_left' => 15]` | `new Section($blocks, pageSetup: new PageSetup(paperSize: PaperSize::A4, orientation: Orientation::Landscape, margins: new PageMargins(leftPt: 15 * 72 / 25.4)))` |
| `$mpdf->SetTitle('T')` / `SetAuthor` | `new Document($section, metadata: ['Title' => 'T', 'Author' => ...])` или `Document::fromHtml($html, metadata: [...])` |
| `$mpdf->AddPage()` | элемент `new PageBreak` между блоками |
| `SetHeader('text')` / `SetFooter` | `Section(headerBlocks: [...], footerBlocks: [...])` — полноценные блоки, не шорткоды |
| `SetWatermarkText('DRAFT')` | `Section(watermarkText: 'DRAFT')` |
| свои TTF (`fontdata` в конфиге) | `new Engine(defaultFont: new PdfFont(TtfFile::fromFile($path)), boldFont: ..., fontProvider: ...)` в `toBytes()/toFile()` |
| `SetProtection([...], $user, $owner)` | `new Document($section, encryption: new EncryptionParams($user, $owner, ...))` |
| флаг `PDFA` в конфиге | `new Document($section, pdfA: new PdfAConfig($iccPath, ...))` — [валидируется veraPDF в CI](../en/CONFORMANCE.md) |
| цифровая подпись (внешними тулами) | встроено: `new Document($section, signature: new SignatureConfig($certPem, $keyPem))` |
| `<barcode code="..." type="QR">` | элемент `new Barcode('...', BarcodeFormat::Qr)` (12 линейных + 4 2D формата) |

## Cheat-sheet find/replace

| Найти | Заменить на |
|---|---|
| `use Mpdf\Mpdf;` | `use Dskripchenko\PhpPdf\Compat\Mpdf;` |
| `new Mpdf(` | `new Mpdf(` *(с фасадным импортом — без изменений)* |
| `\Mpdf\Output\Destination::FILE` | `'F'` |
| `\Mpdf\Output\Destination::STRING_RETURN` | `'S'` |
| `\Mpdf\Output\Destination::DOWNLOAD` | `'D'` |
| `\Mpdf\Output\Destination::INLINE` | `'I'` |
| `\Mpdf\MpdfException` | `\Throwable` (php-pdf бросает SPL-исключения) |

## Гочи

- **Шрифты**: ничего не встраивается автоматически. Base-14 покрывает
  латиницу (WinAnsi); всё остальное — TTF через `Engine` (см. выше).
  В отличие от mpdf, TTF сабсетится по умолчанию — вывод остаётся
  компактным.
- **Покрытие CSS** отличается: php-pdf парсит подмножество HTML5 и
  инлайн-CSS (см. [руководство](USAGE.md)); mpdf-специфичные теги и
  правила `@page` не распознаются. Сложные `@page` переезжают в
  `Section`/`PageSetup`.
- **Единицы**: конфиги mpdf в мм; нативный API — в пунктах
  (`1 мм = 72 / 25.4 pt`). Compat-фасад конвертирует сам.
- **Временные каталоги**: php-pdf ничего не пишет на диск — нет
  `tempDir`, нет кэша `ttfontdata`, который надо чистить.
- **Исключения**: `MpdfException` нет; ошибки — SPL-исключения
  (`InvalidArgumentException`, `LogicException`, `RuntimeException`).

Оба маршрута покрыты тест-сьютом (`tests/Compat/MpdfCompatTest.php`) —
примеры выше именно из тестов, а не из благих намерений.

---

Язык: [English](../en/MIGRATION-FROM-MPDF.md) · [Русский](MIGRATION-FROM-MPDF.md) · [中文](../zh/MIGRATION-FROM-MPDF.md) · [Deutsch](../de/MIGRATION-FROM-MPDF.md)
