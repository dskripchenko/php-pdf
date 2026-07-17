# dskripchenko/php-pdf

> **Один MIT-пакет вместо mpdf + FPDI — без GPL-трений и
> [быстрее](../en/BENCHMARKS.md).** PDF-тулкит на чистом PHP: **генерация,
> чтение и объединение** PDF. GPL-free замена mpdf для HTML→PDF и бесплатная
> альтернатива FPDI для импорта/штамповки — включая xref-stream и
> зашифрованные исходники. Миграция механическая:
> [с mpdf](../en/MIGRATION-FROM-MPDF.md) · [с FPDI](../en/MIGRATION-FROM-FPDI.md).

[![Tests](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-pdf/tests.yml?branch=main&label=tests&logo=github)](https://github.com/dskripchenko/php-pdf/actions/workflows/tests.yml)
[![Conformance](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-pdf/conformance.yml?branch=main&label=PDF%2FA%20%C2%B7%20PDF%2FX%20%C2%B7%20visual&logo=github)](../en/CONFORMANCE.md)
[![Latest Version](https://img.shields.io/packagist/v/dskripchenko/php-pdf?logo=packagist&logoColor=white)](https://packagist.org/packages/dskripchenko/php-pdf)
[![Total Downloads](https://img.shields.io/packagist/dt/dskripchenko/php-pdf)](https://packagist.org/packages/dskripchenko/php-pdf)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://www.php.net)

**Языки:** [English](../en/README.md) · [Русский](README.md) · [中文](../zh/README.md) · [Deutsch](../de/README.md)

---

## Содержание

- [Зачем эта библиотека](#зачем-эта-библиотека)
- [Установка](#установка)
- [Быстрый старт](#быстрый-старт)
- [Ключевые возможности](#ключевые-возможности)
- [Документация](#документация)
- [Производительность](#производительность)
- [Требования](#требования)
- [Тестирование](#тестирование)
- [Лицензия](#лицензия)

---

## Зачем эта библиотека

**Лицензирование.** MIT — самая разрешительная лицензия в мире PHP:
используйте код где угодно, включая закрытые продукты. Сравните с
основным стеком PHP-генераторов PDF:

| Библиотека               | Лицензия       | OEM / проприетарная сборка |
|--------------------------|----------------|----------------------------|
| **dskripchenko/php-pdf** | **MIT**        | ✅ без трений |
| mpdf/mpdf                | GPL-2.0-only   | ❌ требует GPL-сборки или коммерческой лицензии |
| tecnickcom/tcpdf         | LGPL-2.1+      | ⚠️ нюансы статической линковки |
| dompdf/dompdf            | LGPL-2.1       | ⚠️ то же, что и tcpdf |
| setasign/fpdf            | re-licensable  | ✅ но дополнения проприетарные |

**Инженерия.**

- **Современный PHP 8.2+** — readonly-классы, перечисления, именованные
  аргументы, строгая типизация. Чистая, типобезопасная поверхность API.
- **Двухслойная архитектура** — `Pdf\Document` для низкоуровневой эмиссии,
  *fluent*-билдеры `Build\*` для высокоуровневых документов,
  `Document::fromHtml()` для входа в виде HTML/CSS.
- **Качественная типографика** — алгоритм переносов Кнута–Пласса,
  TTF-сабсеттинг с кернингом, GSUB-лигатуры, ToUnicode CMaps,
  экземпляры variable-шрифтов, Bidi (UAX#9), арабская формовка, базовая
  индийская формовка, вертикальное письмо.
- **Самый широкий охват штрихкодов** — 12 линейных + 4 двумерных
  формата, включая редкие Pharmacode, MSI Plessey, ITF-14, add-on
  EAN-2/5.
- **Продакшен-криптография** — RC4-128, AES-128, AES-256 (V5 R5 и R6
  по ISO 32000-2 / PDF 2.0).
- **Подпись PKCS#7 detached** с автопатчингом плейсхолдера /ByteRange.
- **Соответствие PDF/A-1a / 1b / 2u и PDF/X-1a / 3 / 4** со встроенным
  ICC-профилем sRGB и XMP-метаданными.
- **Tagged PDF / PDF/UA-ready** — дерево структуры с H1–H6, Table/TR/TD,
  L/LI, пользовательской RoleMap и числовым деревом ParentTree.
- **Потоковый вывод** в stream-ресурс для больших документов.
- **XRef-потоки** (PDF 1.5+) и Object Streams для компактного вывода.

---

## Установка

```bash
composer require dskripchenko/php-pdf
```

PHP 8.2 или новее. Обязательные расширения: `mbstring`, `zlib`, `dom`.
Добавьте `openssl` для AES-шифрования или подписи PKCS#7.

---

## Быстрый старт

### HTML → PDF

```php
use Dskripchenko\PhpPdf\Document;

$doc = Document::fromHtml(<<<'HTML'
<h1>Invoice #1234</h1>
<p>Customer: <strong>Acme Corp</strong></p>
<table>
  <thead><tr><th>Item</th><th>Price</th></tr></thead>
  <tbody>
    <tr><td>Widget</td><td>$10.00</td></tr>
    <tr><td>Gadget</td><td>$25.00</td></tr>
  </tbody>
</table>
HTML);

$doc->toFile('invoice.pdf');
```

### Программный *builder*

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\RowBuilder;
use Dskripchenko\PhpPdf\Build\TableBuilder;

DocumentBuilder::new()
    ->heading(1, 'Quarterly report')
    ->paragraph('Q1 revenue exceeded the forecast by 12%.')
    ->table(function (TableBuilder $t) {
        $t->headerRow(fn (RowBuilder $r) => $r->cells(['Quarter', 'Revenue']));
        $t->row(fn (RowBuilder $r) => $r->cells(['Q1', '$330,000']));
        $t->row(fn (RowBuilder $r) => $r->cells(['Q2', '$310,000']));
    })
    ->toFile('report.pdf');
```

### Низкоуровневая эмиссия

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Style\PaperSize;

$doc  = Document::new(PaperSize::A4);
$page = $doc->addPage();
$page->showText('Hello, world!', 72, 720, StandardFont::TimesRoman, 12);
file_put_contents('hello.pdf', $doc->toBytes());
```

---

## Ключевые возможности

### Вход
- HTML5-парсер (*subset*) через `Document::fromHtml()`.
- Блочные теги `<p>`, `<h1>`–`<h6>`, `<ul>`/`<ol>`/`<li>`, таблицы (с
  `<thead>`/`<tbody>`/`<tfoot>` и `<caption>`), `<blockquote>`, `<hr>`,
  `<pre>`, `<dl>`/`<dt>`/`<dd>`.
- Строчные теги `<b>`/`<strong>`, `<i>`/`<em>`, `<u>`, `<s>`/`<del>`,
  `<sup>`/`<sub>`, `<br>`, `<a>`, `<span>`, а также `<code>`, `<kbd>`,
  `<samp>`, `<tt>`, `<var>`, `<mark>`, `<small>`, `<big>`, `<ins>`,
  `<cite>`, `<dfn>`, `<q>`, `<abbr>`.
- Семантические блоки HTML5 (`<header>`, `<footer>`, `<article>`,
  `<nav>`, `<section>` и т.д.) и устаревшие `<center>` / `<font>`.
- Инлайн-CSS: color, background, font-свойства, text-align,
  text-decoration, text-transform, сокращения margin/padding, границы,
  line-height, text-indent.

### Раскладка и типографика
- Алгоритм переносов Кнута–Пласса (box–glue–penalty) с адаптивным
  штрафом.
- Многоколоночная раскладка (`ColumnSet`) с column-first потоком.
- Таблицы с rowspan, colspan, border collapse, двойными границами,
  border radius, внутренними отступами ячеек.
- Колонтитулы, водяные знаки (текст и изображение), разрывы секций.
- Сноски, прижатые к низу страницы.

### Шрифты
- 14 базовых шрифтов Adobe base-14 (WinAnsi).
- Встраивание TTF с сабсеттингом по требованию (CFF и TrueType).
- Кернинг, базовые GSUB-лигатуры, ToUnicode CMap.
- Экземпляры variable-шрифтов (fvar, gvar, MVAR, HVAR, avar).
- Bidi (UAX#9), арабская формовка, базовая индийская формовка.

### Штрихкоды
- Линейные: Code 128 (A/B/C auto, GS1-128), Code 39, Code 93, Code 11,
  Codabar, ITF/ITF-14, MSI Plessey, Pharmacode, EAN-13/EAN-8 с add-on
  на 2/5 цифр, UPC-A, UPC-E.
- 2D: QR V1–V10 (Numeric, Alphanumeric, Byte, Kanji, ECI, Structured
  Append, FNC1), Data Matrix ECC 200 (все размеры, в т.ч. 144×144,
  прямоугольные, 6 режимов), PDF417 (Byte/Text/Numeric, Macro, GS1,
  ECI), Aztec Compact 1–4L + Full 5–32L (Structured Append, FLG/ECI).
- Удобные фабрики QR: vCard 3.0, WiFi Joinware, mailto.

### Графики
- BarChart, LineChart, PieChart, AreaChart, DonutChart, GroupedBarChart,
  StackedBarChart, MultiLineChart, ScatterChart.

### Интерактив
- Виджеты AcroForm: text (однострочный / многострочный / пароль),
  checkbox, radio, combo, list, push / submit / reset, signature.
- JavaScript-действия на уровне поля (keystroke, validate, calculate,
  format).
- Markup-аннотации: Text, Highlight, Underline, StrikeOut, FreeText,
  Square, Circle, Line, Stamp, Ink, Polygon, PolyLine.

### Безопасность и соответствие стандартам
- Шифрование: RC4-128, AES-128, AES-256 (V5 R5 + R6 / PDF 2.0).
- Подпись PKCS#7 detached с меткой времени, причиной, местом и именем
  подписанта.
- PDF/A-1a, PDF/A-1b, PDF/A-2u со встроенным sRGB ICC.
- PDF/X-1a, PDF/X-3, PDF/X-4 с /OutputIntent /S /GTS_PDFX.
- Tagged PDF / дерево структуры PDF/UA-ready.

### Чтение и объединение
- Чтение готовых PDF (`ReaderDocument`) — классический и потоковый xref,
  object-потоки, восстановление битого xref, расшифровка (RC4 / AES-128 / AES-256).
- Объединение (`PdfMerger`) — склейка и переупорядочивание всех или выбранных
  страниц из нескольких файлов; аннотации и закладки переносятся с ремапом
  внутренних ссылок и именованных назначений.
- Наложения (водяные знаки, бланки) и импорт страницы в стиле FPDI в
  сгенерированный документ (`PageImporter::intoDocument()`). См.
  [руководство по чтению и объединению](MERGE.md).

Полное руководство по использованию — в [docs/en/USAGE.md](USAGE.md).

---

## Документация

- 📖 [Руководство по использованию](USAGE.md) — абзацы, таблицы,
  графики, штрихкоды, формы, шифрование, подпись, PDF/A.
- 🔗 [Чтение и объединение PDF](MERGE.md) — чтение готовых файлов,
  склейка/переупорядочивание страниц, наложения, импорт в стиле FPDI.
- ⚖️ [Сравнение с mpdf / tcpdf / dompdf / FPDF](COMPARISON.md) —
  матрица возможностей, когда что выбирать.
- 📊 [Бенчмарки](BENCHMARKS.md) — воспроизводимые замеры
  wall-time, памяти и размера выхода.
- 🔀 [Миграция с mpdf](MIGRATION-FROM-MPDF.md) — compat-фасад и полная
  таблица соответствий.
- 🔀 [Миграция с FPDI](MIGRATION-FROM-FPDI.md) — импорт/штамповка,
  чтение xref-stream и зашифрованных источников.
---

## Производительность

Медиана из 5 изолированных запусков в подпроцессах (окружение и версии
всех библиотек — в отчёте). Полная методология и репродьюсер — в
[docs/en/BENCHMARKS.md](BENCHMARKS.md).

<!-- bench:table:start — generated by scripts/bench/run.php, do not edit -->
| Scenario | dskripchenko/php-pdf | mpdf | tcpdf | dompdf | FPDF |
|---|---:|---:|---:|---:|---:|
| HTML → PDF article (~5 pages) | **12.0 ms** | 64.1 ms | 37.4 ms | 51.7 ms | _n/a_ |
| 100-page invoice (50 rows/page) | **576 ms** | 2640 ms | 1479 ms | 9586 ms | 27.3 ms |
| Image grid (20 pages × 4 JPEGs) | **6.7 ms** | 36.3 ms | 14.5 ms | 32.8 ms | 1.0 ms |
| Hello world (single page, one paragraph) | **4.3 ms** | 28.4 ms | 12.9 ms | 11.5 ms | 0.9 ms |
<!-- bench:table:end -->

FPDF выигрывает в простейших сценариях (нет HTML, нет переносов, нет
табличного потока), но не умеет HTML→PDF и не поддерживает UTF-8,
графики, штрихкоды, формы, шифрование, подпись.

---

## Требования

- PHP **8.2** или новее.
- Обязательно: `ext-mbstring`, `ext-zlib`, `ext-dom`.
- Опционально: `ext-openssl` (AES-шифрование и подпись PKCS#7).
- Никаких внешних бинарей — только чистый PHP.

---

## Тестирование

```bash
composer install
vendor/bin/phpunit
```

2 000+ тестов, ~119k утверждений, все проходят на PHP 8.2 / 8.3 / 8.4.

---

## Лицензия

MIT — см. [LICENSE](LICENSE).

Copyright © 2026 Denis Skripchenko.
