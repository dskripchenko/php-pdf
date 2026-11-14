# dskripchenko/php-pdf

> **Один MIT-пакет вместо mpdf + FPDI — без GPL-трений и
> [быстрее](BENCHMARKS.md).** PDF-тулкит на чистом PHP: **генерация,
> чтение и объединение** PDF. GPL-free замена mpdf для HTML→PDF и бесплатная
> альтернатива FPDI для импорта/штамповки — включая xref-stream и
> зашифрованные исходники. Миграция механическая:
> [с mpdf](MIGRATION-FROM-MPDF.md) · [с FPDI](MIGRATION-FROM-FPDI.md).

[![Tests](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-pdf/tests.yml?branch=main&label=tests&logo=github)](https://github.com/dskripchenko/php-pdf/actions/workflows/tests.yml)
[![Conformance](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-pdf/conformance.yml?branch=main&label=PDF%2FA%20%C2%B7%20PDF%2FX%20%C2%B7%20visual&logo=github)](../en/CONFORMANCE.md)
[![Latest Version](https://img.shields.io/packagist/v/dskripchenko/php-pdf?logo=packagist&logoColor=white)](https://packagist.org/packages/dskripchenko/php-pdf)
[![Total Downloads](https://img.shields.io/packagist/dt/dskripchenko/php-pdf)](https://packagist.org/packages/dskripchenko/php-pdf)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](../../LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://www.php.net)

**Языки:** [English](../en/README.md) · [Русский](README.md) · [中文](../zh/README.md) · [Deutsch](../de/README.md)

---

## Содержание

- [Зачем эта библиотека](#зачем-эта-библиотека)
- [Установка](#установка)
- [Быстрый старт](#быстрый-старт)
- [Ключевые возможности](#ключевые-возможности)
- [Документация](#документация)
- [Семейство php-pdf](#семейство-php-pdf)
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
- **Подпись PAdES** (B-B, B-T с меткой времени RFC 3161) — RSA, ECDSA и
  ГОСТ Р 34.10-2012, ключ в PEM / PKCS#12 / свой `Signer` (токен, HSM) или
  **двухфазная подпись вне сервера** (КриптоПро plug-in, HSM, сервис подписи) —
  с видимым **штампом подписи** с данными сертификата; **подпись готовых PDF**
  дописыванием (несколько подписантов, сертификация, блокировка полей) и
  **долгосрочная проверка** (B-LT — DSS, B-LTA — метка времени документа);
  **проверка подписей** с защитой от известных атак на подписанные PDF;
  **откреплённые подписи CAdES** (`.p7s`) любых байтов — подпись и проверка.
  Готовые подписанты для токенов и HSM по PKCS#11
  ([php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11)), облачных
  KMS — AWS, Google, Azure
  ([php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms)) —
  и КриптоПро CSP на сервере
  ([php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro)).
- **Соответствие PDF/A-1a, 1b, 2b, 2u и 3b** (проверяется veraPDF в CI) и
  **PDF/X-1a / 3 / 4** со встроенным ICC-профилем и XMP-метаданными.
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
Добавьте `openssl` для AES-шифрования или электронной подписи.

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
- CSS из блоков `<style>` и атрибутов `style` с каскадом: селекторы
  по типу, классу, id и атрибуту, `:first-child`, `:last-child`,
  `:nth-child()`, `:not()`, комбинаторы потомка / `>` / `+` / `~`,
  специфичность, `!important` — CSS-инлайнер не нужен.
- `@media print` применяется, `@media screen` пропускается; размер и поля
  страницы из `@page`.
- Наследование как в браузере: color, font, text-align, line-height и
  подобные свойства, заданные на `body`, `div` или `td`, доходят до текста
  внутри.
- Контейнеры `div` с margin, padding, фоном и рамкой; `display: flex`
  (ряды, `flex-wrap`, `flex-direction: column`, `gap`), `display: grid`
  (`grid-template-columns` с длинами, `%`, `fr`, `repeat()`, `minmax()`;
  `grid-column: span n`; `gap`), `display: none`.
- `float: left / right` с `clear`; `position: absolute` и `fixed`
  (fixed-элемент повторяется на каждой странице); `break-inside: avoid`
  удерживает блок на одной странице.
- `::before` / `::after` с `content` (строки, `attr()`, кавычки);
  `<link rel="stylesheet">` на локальные файлы и `data:`-URL;
  списки `font-family` (берётся первое доступное семейство).
- Стили таблиц: `width`, `table-layout: auto`, `border-collapse` /
  `border-spacing`, `padding` и `vertical-align` ячеек, ширины `<col>`;
  строки `<thead>` повторяются на каждой странице.
- `page-break-*` / `break-*`, `white-space: pre` / `pre-wrap` /
  `pre-line`; сплошные, пунктирные, точечные и двойные рамки.
- Приближения: текст рядом с float не перетекает под него;
  `position: relative` только служит точкой отсчёта для absolute-потомков
  (собственные смещения не применяются); удалённые таблицы стилей не
  загружаются. Не поддерживаются: `grid-template-areas` и явное
  размещение по строкам, `@import`, `:hover` и другие динамические
  псевдоклассы.

### Раскладка и типографика
- Алгоритм переносов Кнута–Пласса (box–glue–penalty) с адаптивным
  штрафом.
- Многоколоночная раскладка (`ColumnSet`) с column-first потоком.
- Таблицы с rowspan, colspan, border collapse, двойными, пунктирными и
  точечными границами, border radius, внутренними отступами ячеек.
- Колонтитулы, водяные знаки (текст и изображение), разрывы секций.
- Сноски, прижатые к низу страницы.

### Шрифты
- 14 базовых шрифтов Adobe base-14 (WinAnsi).
- Шрифты с TrueType-контурами (`.ttf`, `.otf` с TrueType-контурами) с
  сабсеттингом по требованию; OpenType на основе CFF (`.otf`) встраивается
  целиком, без сабсеттинга; CFF2 (вариативный CFF) не поддерживается.
- Кернинг, базовые GSUB-лигатуры, ToUnicode CMap.
- Экземпляры variable-шрифтов (fvar, gvar, MVAR, HVAR, avar).
- Bidi (UAX#9), арабская формовка, базовая индийская формовка.
- Готовый бандл Liberation (метрически совместим с Arial / Times New
  Roman / Courier New): [dskripchenko/php-pdf-fonts-liberation](https://github.com/dskripchenko/php-pdf-fonts-liberation).

### Штрихкоды
- Линейные: Code 128 (A/B/C auto, GS1-128), Code 39, Code 93, Code 11,
  Codabar, ITF/ITF-14, MSI Plessey, Pharmacode, EAN-13/EAN-8 с add-on
  на 2/5 цифр, UPC-A, UPC-E.
- 2D: QR V1–V10 (Numeric, Alphanumeric, Byte, Kanji, ECI, Structured
  Append, FNC1), Data Matrix ECC 200 (все размеры, в т.ч. 144×144,
  прямоугольные, 6 режимов), PDF417 (Byte/Text/Numeric, Macro, GS1,
  ECI), Aztec Compact 1–4L + Full 5–32L (Structured Append, FLG/ECI).
- Удобные фабрики QR: vCard 3.0, WiFi (Joinware), URL, SMS,
  email (`mailto:`), геолокация.

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
- Подпись PAdES (`ETSI.CAdES.detached`) или PKCS#7 detached с причиной,
  местом, именем подписанта, цепочкой сертификатов и метками времени RFC 3161; штампы подписи (текст, картинки, QR) с плейсхолдерами
  сертификата, несколько полей подписи, невидимая подпись.
- Готовые подписанты: токены и HSM по PKCS#11
  ([php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11)), AWS
  KMS, Google Cloud KMS и Azure Key Vault
  ([php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms)),
  КриптоПро CSP на сервере
  ([php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro)).
- PDF/A-1a, PDF/A-1b, PDF/A-2b, PDF/A-2u, PDF/A-3b со встроенным sRGB ICC,
  проверяется veraPDF в CI.
- PDF/X-1a, PDF/X-3, PDF/X-4 с /OutputIntent /S /GTS_PDFX.
- Tagged PDF / дерево структуры PDF/UA-ready.

### Чтение и объединение
- Чтение готовых PDF (`ReaderDocument`) — классический и потоковый xref,
  object-потоки, восстановление битого xref, расшифровка (RC4 / AES-128 / AES-256).
- Извлечение текста (`ReaderDocument::text()`) — карты ToUnicode, кодировки
  простых шрифтов с `/Differences`, составные шрифты, form XObject; строки и
  пробелы — по положению глифов.
- Объединение (`PdfMerger`) — склейка и переупорядочивание всех или выбранных
  страниц из нескольких файлов; аннотации страниц и закладки переносятся с
  ремапом внутренних ссылок и именованных назначений.
- Наложения (водяные знаки, бланки) и импорт страницы в стиле FPDI в только
  что сгенерированный документ через `PageImporter::intoDocument()`.
- См. [руководство по чтению и объединению](MERGE.md).

Полное руководство по использованию — в [USAGE.md](USAGE.md).

---

## Документация

- 📖 [Руководство по использованию](USAGE.md) — абзацы, таблицы,
  графики, штрихкоды, формы, шифрование, подпись, PDF/A.
- ✍️ [Электронная подпись](SIGNING.md) — подпись PAdES и ГОСТ, штампы,
  подпись вне сервера (КриптоПро, HSM), подпись готовых PDF и несколько
  подписантов, сертификация, долгосрочная проверка, проверка подписей,
  откреплённые подписи `.p7s`.
- 🔗 [Чтение и объединение PDF](MERGE.md) — чтение готовых файлов,
  склейка/переупорядочивание страниц, наложения, работа с зашифрованными
  исходниками.
- ⚖️ [Сравнение с mpdf / tcpdf / dompdf / FPDF](COMPARISON.md) —
  матрица возможностей, когда что выбирать.
- 📊 [Бенчмарки](BENCHMARKS.md) — воспроизводимые замеры
  wall-time, памяти и размера выхода.
- ✅ [Соответствие стандартам](../en/CONFORMANCE.md) — PDF/A проверяется
  veraPDF, PDF/X — Ghostscript, рендер сверяется с эталонами, подписи
  проверяются OpenSSL и pyHanko — на каждый push.
- 🔀 [Миграция с mpdf](MIGRATION-FROM-MPDF.md) — compat-фасад
  (`WriteHTML`/`Output` продолжают работать) и полная таблица соответствий.
- 🔀 [Миграция с FPDI](MIGRATION-FROM-FPDI.md) — фасад
  `setSourceFile`/`importPage`/`useTemplate`; читает xref-stream и
  зашифрованные источники без коммерческого парсера.

### Примеры

Готовые к запуску скрипты в [`examples/`](../../examples/), результаты
закоммичены в [`samples/`](../../samples/) — каждый проверяется рендером в CI:

| Запуск | Результат | Что показывает |
|---|---|---|
| `php examples/01-html-to-pdf.php` | [PDF](../../samples/01-html-to-pdf.pdf) | подмножество HTML/CSS → PDF |
| `php examples/02-builder.php` | [PDF](../../samples/02-builder.pdf) | fluent-билдер, таблицы |
| `php examples/03-barcodes.php` | [PDF](../../samples/03-barcodes.pdf) | QR, Code 128, EAN-13, DataMatrix |
| `php examples/04-charts.php` | [PDF](../../samples/04-charts.pdf) | векторные круговые/столбчатые/линейные графики |
| `php examples/05-forms.php` | [PDF](../../samples/05-forms.pdf) | заполняемые поля AcroForm |
| `php examples/06-signature.php` | [PDF](../../samples/06-signature.pdf) | электронная подпись PAdES с видимым штампом |
| `php examples/07-pdfa.php` | [PDF](../../samples/07-pdfa.pdf) | PDF/A-2u (чисто по veraPDF); нужен `scripts/fetch-fonts.sh` |
| `php examples/08-merge.php` | [PDF](../../samples/08-merge.pdf) | склейка и переупорядочивание готовых PDF |
| `php examples/09-stamp.php` | [PDF](../../samples/09-stamp.pdf) | наложение водяного знака/бланка |

[Torture-набор](../../examples/torture/) прогоняет сложные пути (арабский,
CJK, прямоугольный DataMatrix, …) — см. [VIEWER-MATRIX](../en/VIEWER-MATRIX.md).

---

## Семейство php-pdf

| Пакет | Что добавляет |
|---|---|
| **php-pdf** (этот пакет) | Ядро: HTML с CSS или билдер в PDF, чтение, объединение, заполнение форм, подпись и проверка. |
| [laravel-php-pdf](https://github.com/dskripchenko/laravel-php-pdf) | Мост для Laravel: Blade-представление сразу в PDF, `response()->pdf()`, подпись из конфига. |
| [php-pdf-fonts-liberation](https://github.com/dskripchenko/php-pdf-fonts-liberation) | Liberation Sans, Serif и Mono, метрически совместимые с Arial, Times New Roman и Courier New (OFL, вынесены из MIT-ядра). |
| [php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro) | Подпись ключом в КриптоПро CSP на сервере: ГОСТ Р 34.10-2012, российская квалифицированная подпись. |
| [php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11) | Подпись ключом на HSM, смарт-карте или USB-токене через PKCS#11. |
| [php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms) | Подпись ключом в AWS KMS, Google Cloud KMS или Azure Key Vault. |

---

## Производительность

Медиана из 5 изолированных запусков в подпроцессах (окружение и версии
всех библиотек — в отчёте). Полная методология и репродьюсер — в
[BENCHMARKS.md](BENCHMARKS.md).

<!-- bench:table:start — generated by scripts/bench/run.php, do not edit -->
| Scenario | dskripchenko/php-pdf | mpdf | tcpdf | dompdf | FPDF |
|---|---:|---:|---:|---:|---:|
| HTML → PDF article (~5 pages) | **15.2 ms** | 61.9 ms | 38.0 ms | 48.1 ms | _n/a_ |
| 100-page invoice (50 rows/page) | **632 ms** | 2300 ms | 1342 ms | 9156 ms | 26.4 ms |
| Image grid (20 pages × 4 JPEGs) | **7.7 ms** | 36.5 ms | 16.9 ms | 31.8 ms | 1.0 ms |
| Hello world (single page, one paragraph) | **5.0 ms** | 29.4 ms | 15.0 ms | 11.7 ms | 0.9 ms |
<!-- bench:table:end -->

FPDF выигрывает в простейших сценариях (нет HTML, нет переносов, нет
табличного потока), но не умеет HTML→PDF и не поддерживает UTF-8,
графики, штрихкоды, формы, шифрование, подпись.

---

## Требования

- PHP **8.2** или новее.
- Обязательно: `ext-mbstring`, `ext-zlib`, `ext-dom`.
- Опционально: `ext-openssl` (AES-шифрование и электронная подпись).
- Никаких внешних бинарей — только чистый PHP.

---

## Тестирование

```bash
composer install
vendor/bin/phpunit
```

2 480+ тестов, ~120k утверждений, прогоняются на PHP 8.2 / 8.3 / 8.4 / 8.5 в CI (см. [workflow тестов](https://github.com/dskripchenko/php-pdf/actions/workflows/tests.yml)).

---

## Лицензия

MIT — см. [LICENSE](../../LICENSE).

Copyright © 2026 Denis Skripchenko.
