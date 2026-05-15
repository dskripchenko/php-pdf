# Руководство по использованию

Это руководство проводит через `dskripchenko/php-pdf` — от простейшей
конвертации HTML в PDF до самой низкоуровневой эмиссии страниц. Каждый
раздел самодостаточен — читайте сверху вниз для обзора либо переходите
сразу к нужной возможности.

## Содержание

- [Три точки входа](#три-точки-входа)
- [Сборка документов](#сборка-документов)
  - [Абзацы и заголовки](#абзацы-и-заголовки)
  - [Стилизация *run*](#стилизация-run)
  - [Таблицы](#таблицы)
  - [Списки](#списки)
  - [Изображения](#изображения)
  - [Колонтитулы и водяные знаки](#колонтитулы-и-водяные-знаки)
  - [Настройка страницы и разрывы секций](#настройка-страницы-и-разрывы-секций)
- [Вход HTML / CSS](#вход-html--css)
- [Пользовательские шрифты](#пользовательские-шрифты)
- [Штрихкоды](#штрихкоды)
- [Графики](#графики)
- [Математические выражения](#математические-выражения)
- [SVG](#svg)
- [Гиперссылки и закладки](#гиперссылки-и-закладки)
- [Формы (AcroForm)](#формы-acroform)
- [Аннотации](#аннотации)
- [Шифрование](#шифрование)
- [Цифровая подпись](#цифровая-подпись)
- [PDF/A и Tagged PDF](#pdfa-и-tagged-pdf)
- [Соответствие PDF/X для печати](#соответствие-pdfx-для-печати)
- [Потоковый вывод](#потоковый-вывод)
- [Группы опционального контента (слои)](#группы-опционального-контента-слои)
- [Низкоуровневая эмиссия](#низкоуровневая-эмиссия)

---

## Три точки входа

Библиотека предоставляет три слоя, каждый полностью пригоден к
использованию сам по себе.

1. **`Document::fromHtml($html)`** — проще всего. Разбирает HTML,
   раскладывает его и возвращает готовый к записи `Document`. Подходит
   для счетов, отчётов и любого содержимого, уже существующего как
   HTML.
2. **`Build\DocumentBuilder`** — *fluent*. Цепочка методов для
   абзацев, таблиц, списков, графиков, штрихкодов. Компилируется в тот
   же AST, что и HTML-точка входа. Подходит, когда содержимое
   вычисляется программно.
3. **`Pdf\Document`** — низкий уровень. Добавляйте страницы, рисуйте
   текст и фигуры в абсолютных координатах. Никакого движка раскладки,
   никакого потока. Подходит для точного позиционирования в стиле
   билета.

Их можно сочетать. `DocumentBuilder` производит AST; движок раскладки
эмитит лежащий в основе `Pdf\Document`; `toBytes()` и `toFile()`
работают на каждом слое.

---

## Сборка документов

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;

$bytes = DocumentBuilder::new()
    ->heading(1, 'Annual Report 2026')
    ->paragraph('Revenue is up 12% year-over-year.')
    ->toBytes();
```

`toBytes()` возвращает PDF в виде строки. `toFile($path)` пишет
непосредственно на диск и возвращает количество байт. `build()`
возвращает AST-`Document`, если хотите его проинспектировать или
обработать дальше.

### Абзацы и заголовки

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;
use Dskripchenko\PhpPdf\Style\RunStyle;

DocumentBuilder::new()
    ->heading(1, 'Quarterly review')
    ->paragraph('First sentence.')
    ->paragraph(function (ParagraphBuilder $p) {
        $p->text('Second paragraph with ')
          ->text('bold', new RunStyle(bold: true))
          ->text(' and ')
          ->text('italic', new RunStyle(italic: true))
          ->text(' inline.');
    })
    ->emptyLine()
    ->horizontalRule()
    ->paragraph('Below the rule.')
    ->toFile('out.pdf');
```

### Стилизация *run*

`Run` — это стилизованный фрагмент текста. `RunStyle` управляет всем,
что влияет на отрисовку глифов.

```php
use Dskripchenko\PhpPdf\Style\RunStyle;

$style = new RunStyle(
    sizePt: 12.0,
    color: 'ff0000',           // hex without '#'
    backgroundColor: 'ffff99',
    fontFamily: 'Helvetica',
    bold: true,
    italic: false,
    underline: false,
    strikethrough: false,
    superscript: false,
    subscript: false,
    letterSpacingPt: 0.5,
);
```

Блочная раскладка живёт в `ParagraphStyle`:

```php
use Dskripchenko\PhpPdf\Element\Alignment;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;

new ParagraphStyle(
    alignment: Alignment::Justify,
    spaceBeforePt: 6.0,
    spaceAfterPt: 6.0,
    indentLeftPt: 36.0,
    indentFirstLinePt: 18.0,
    lineHeightMult: 1.5,
    paddingPt: 8.0,
    backgroundColor: 'f0f0f0',
);
```

### Таблицы

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\RowBuilder;
use Dskripchenko\PhpPdf\Build\TableBuilder;

DocumentBuilder::new()
    ->heading(2, 'Quarterly revenue')
    ->table(function (TableBuilder $t) {
        $t->columnWidths([100, 200])
          ->headerRow(fn (RowBuilder $r) =>
              $r->cells(['Quarter', 'Revenue']))
          ->row(fn (RowBuilder $r) => $r->cells(['Q1', '$300,000']))
          ->row(fn (RowBuilder $r) => $r->cells(['Q2', '$310,000']))
          ->row(fn (RowBuilder $r) => $r->cells(['Q3', '$280,000']))
          ->row(fn (RowBuilder $r) => $r->cells(['Q4', '$310,000']));
    })
    ->toFile('report.pdf');
```

Объединения строк, объединения столбцов, выравнивание, границы и
стилизация по ячейке — всё поддерживается. Полный API см. в
`src/Build/CellBuilder.php`.

### Списки

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\ListBuilder;
use Dskripchenko\PhpPdf\Style\ListFormat;

DocumentBuilder::new()
    ->bulletList(function (ListBuilder $l) {
        $l->item('Alpha');
        $l->item('Beta');
        $l->item('Gamma');
    })
    ->orderedList(function (ListBuilder $l) {
        $l->item('Step one');
        $l->item('Step two');
    }, ListFormat::Decimal)
    ->toFile('lists.pdf');
```

`ListFormat` покрывает Decimal, UpperRoman, LowerRoman, UpperAlpha,
LowerAlpha.

### Изображения

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;

DocumentBuilder::new()
    ->image('/path/to/logo.png', widthPt: 120)
    ->paragraph('Above is our logo.')
    ->toFile('with-image.pdf');
```

Поддерживаемые форматы: JPEG, PNG (8-bit truecolor, 8-bit palette, с
альфой через SMask). Одно и то же изображение, использованное N раз в
документе, встраивается как единственный XObject (дедупликация по
content-hash).

### Колонтитулы и водяные знаки

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\HeaderFooterBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;

DocumentBuilder::new()
    ->header(function (HeaderFooterBuilder $h) {
        $h->paragraph(fn (ParagraphBuilder $p) =>
            $p->text('Acme Corp · Confidential'));
    })
    ->footer(function (HeaderFooterBuilder $f) {
        $f->paragraph(fn (ParagraphBuilder $p) =>
            $p->text('Page ')->pageNumber()->text(' of ')->pageCount());
    })
    ->watermark('DRAFT')
    ->paragraph('Document body.')
    ->toFile('with-chrome.pdf');
```

`->watermarkImage($image)` принимает `PdfImage`. У обоих водяных
знаков есть управление прозрачностью через `watermarkTextOpacity()` /
`watermarkImageOpacity()`.

### Настройка страницы и разрывы секций

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Style\Orientation;
use Dskripchenko\PhpPdf\Style\PageMargins;
use Dskripchenko\PhpPdf\Style\PageSetup;
use Dskripchenko\PhpPdf\Style\PaperSize;

DocumentBuilder::new()
    ->pageSetup(new PageSetup(
        paperSize: PaperSize::A4,
        orientation: Orientation::Landscape,
        margins: new PageMargins(top: 36, right: 36, bottom: 36, left: 36),
    ))
    ->heading(1, 'Wide content')
    ->toFile('landscape.pdf');
```

Форматы бумаги: A0–A6, B0–B6, Letter, Legal, Executive, Tabloid, плюс
произвольный через `defaultCustomDimensionsPt`.

---

## Вход HTML / CSS

```php
use Dskripchenko\PhpPdf\Document;

$doc = Document::fromHtml(<<<'HTML'
<h1 style="color: navy">Annual Report 2026</h1>
<p>
  <strong>Revenue</strong>:
  <span style="color: green">$1.2M</span>
  (<span style="color: red">-12%</span> YoY)
</p>
<table>
  <thead><tr><th>Quarter</th><th>Revenue</th></tr></thead>
  <tbody>
    <tr><td>Q1</td><td>$300K</td></tr>
    <tr><td>Q2</td><td>$310K</td></tr>
  </tbody>
</table>
HTML);

$doc->toFile('report.pdf');
```

**Поддерживаемый HTML5:**

- Блочные: `<p>`, `<div>`, `<section>`, `<article>`, `<h1>`–`<h6>`,
  `<header>`, `<footer>`, `<nav>`, `<aside>`, `<main>`, `<figure>`,
  `<figcaption>`, `<hr>`, `<ul>` / `<ol>` / `<li>`, `<table>` /
  `<thead>` / `<tbody>` / `<tfoot>` / `<tr>` / `<td>` / `<th>` /
  `<caption>`, `<blockquote>`, `<pre>`, `<dl>` / `<dt>` / `<dd>`.
- Строчные: `<b>` / `<strong>`, `<i>` / `<em>`, `<u>`, `<s>` / `<del>`,
  `<sup>` / `<sub>`, `<br>`, `<img>`, `<a>`, `<span>`, `<code>`,
  `<kbd>`, `<samp>`, `<tt>`, `<var>`, `<mark>`, `<small>`, `<big>`,
  `<ins>`, `<cite>`, `<dfn>`, `<q>`, `<abbr>`.
- Устаревшие: `<center>`, `<font color face size>`.

**Поддерживаемый инлайн-CSS (атрибут `style`):**

- `color`, `background-color` — hex `#rrggbb` / `#rgb`, `rgb()`, 21
  именованный цвет.
- `font-size` (pt, px, em, mm, cm, in), `font-family` (первое значение
  из списка через запятую), `font-weight` (`bold`, `bolder`, 700+),
  `font-style: italic`.
- `text-decoration` (`underline`, `line-through`), `text-transform`
  (`uppercase`, `lowercase`, `capitalize`), `text-align`,
  `text-indent`.
- Сокращения `margin`, `padding` (1/2/3/4 значения), сокращение
  `border` (`solid`, `double`, `dashed`, `dotted`, `none` + ширина +
  цвет).
- `line-height` (множитель или процент).

**Не поддерживается:** внешний CSS (`<link rel="stylesheet">`), блоки
`<style>`, сложные селекторы, `@media`, JavaScript, обтекание,
`position: absolute / fixed`, Flexbox.

Для поддержки `<style>` / классов прогоните HTML через внешний
*inliner* (например, `pelago/emogrifier`).

---

## Пользовательские шрифты

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Font\DirectoryFontProvider;
use Dskripchenko\PhpPdf\Layout\Engine;

$fonts = new DirectoryFontProvider(__DIR__ . '/fonts');

$bytes = DocumentBuilder::new()
    ->paragraph('Здравствуй, мир — 你好世界 — مرحبا')
    ->toBytes(new Engine(fontProvider: $fonts));
```

`DirectoryFontProvider` сканирует каталог на файлы `.ttf` / `.otf` и
предоставляет их по имени семейства. `ChainedFontProvider` позволяет
композировать провайдеры, чтобы движок мог откатиться на системные
шрифты для отсутствующих глифов.

Встраиваемые шрифты сабсетятся по требованию — в файл попадают только
использованные глифы. Кернинг, базовые лигатуры и ToUnicode CMaps
эмитятся автоматически.

---

## Штрихкоды

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;

DocumentBuilder::new()
    ->barcode('ACME-1234', BarcodeFormat::Code128, heightPt: 40)
    ->barcode('https://example.com', BarcodeFormat::Qr, widthPt: 120, heightPt: 120, showText: false)
    ->toFile('barcodes.pdf');
```

Удобные фабрики QR:

```php
use Dskripchenko\PhpPdf\Barcode\QrEncoder;

$vcard = QrEncoder::vCard(
    fullName: 'Jane Doe',
    org: 'Acme Corp',
    email: 'jane@example.com',
    phone: '+1-555-0100',
);

$wifi = QrEncoder::wifi(ssid: 'guest', password: 'welcome', hidden: false);

$mailto = QrEncoder::mailto('hello@example.com', subject: 'Hi');
```

Полный список из 16 поддерживаемых форматов см. в
[COMPARISON.md](COMPARISON.md#штрихкоды).

---

## Графики

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\BarChart;

DocumentBuilder::new()
    ->block(new BarChart(
        bars: [
            ['label' => 'Q1', 'value' => 300],
            ['label' => 'Q2', 'value' => 310],
            ['label' => 'Q3', 'value' => 280],
            ['label' => 'Q4', 'value' => 310],
        ],
        title: 'Quarterly revenue',
        widthPt: 400,
        heightPt: 220,
    ))
    ->toFile('chart.pdf');
```

Доступные типы графиков: `BarChart`, `LineChart`, `PieChart`,
`AreaChart`, `DonutChart`, `GroupedBarChart`, `StackedBarChart`,
`MultiLineChart`, `ScatterChart`. Каждый принимает заголовки осей,
поворот меток, линии сетки, легенду, цвета и сглаживание там, где
применимо.

---

## Математические выражения

Подмножество LaTeX рендерится в PDF через `MathExpression`:

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\MathExpression;

DocumentBuilder::new()
    ->block(new MathExpression('\frac{a^2 + b^2}{c^2} = 1'))
    ->block(new MathExpression('\sum_{i=1}^{n} i = \frac{n(n+1)}{2}'))
    ->block(new MathExpression('\begin{pmatrix} a & b \\ c & d \end{pmatrix}'))
    ->toFile('math.pdf');
```

Поддерживаются: дроби, sqrt, верх/нижние индексы, большие операторы
(sum, product, integral), матрицы (`pmatrix`, `bmatrix`, `vmatrix`),
многострочные окружения (`align`, `gather`, `cases`).

---

## SVG

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\SvgElement;

DocumentBuilder::new()
    ->block(new SvgElement(file_get_contents('logo.svg'), widthPt: 200))
    ->toFile('svg.pdf');
```

Поддерживаемый SVG: пути (полный синтаксис, включая дуги и кривые
Безье), фигуры (`<rect>`, `<circle>`, `<ellipse>`, `<line>`,
`<polyline>`, `<polygon>`), градиенты (линейные, радиальные,
многоступенчатые), преобразования (`translate`, `scale`, `rotate`,
`skewX`, `skewY`, `matrix`), `<use>` / `<defs>`, стилизация через
CSS-классы.

Инлайн-SVG работает и внутри HTML — теги `<svg>` пробрасываются в
SVG-рендерер.

---

## Гиперссылки и закладки

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;

DocumentBuilder::new()
    ->heading(1, 'Top')
    ->paragraph(function (ParagraphBuilder $p) {
        $p->text('Visit ')->link('https://example.com', 'our site');
    })
    ->bookmark('Chapter 1', level: 1)
    ->heading(1, 'Chapter 1')
    ->paragraph('Body...')
    ->toFile('linked.pdf');
```

Эмитируемые виды гиперссылок: `URI`, `Dest` (именованное назначение),
JavaScript, Launch и назначения на именованные страницы. Панель
оглавления (закладки) — многоуровневая: передавайте `level: 2` для
подраздела, `level: 3` для под-подраздела и так далее.

Авто-якоря заголовков: `<h1 id="intro">` становится именованным
назначением, поэтому `<a href="#intro">jump</a>` работает внутри
HTML-входа.

---

## Формы (AcroForm)

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\FormField;

DocumentBuilder::new()
    ->heading(1, 'Application form')
    ->block(new FormField(
        type: 'text',
        name: 'full_name',
        x: 100, y: 700, w: 200, h: 24,
    ))
    ->block(new FormField(
        type: 'checkbox',
        name: 'agree',
        x: 100, y: 660, w: 14, h: 14,
        defaultValue: 'on',
    ))
    ->block(new FormField(
        type: 'combo',
        name: 'country',
        x: 100, y: 620, w: 200, h: 24,
        options: ['US', 'UK', 'DE', 'RU', 'CN'],
        defaultValue: 'US',
    ))
    ->block(new FormField(
        type: 'submit',
        name: 'send',
        x: 100, y: 580, w: 80, h: 28,
        buttonCaption: 'Submit',
        submitUrl: 'https://example.com/submit',
    ))
    ->toFile('form.pdf');
```

Типы полей: `text`, `text-multiline`, `password`, `checkbox`,
`radio-group`, `combo`, `list`, `push`, `submit`, `reset`, `signature`.

JavaScript-хуки на уровне поля: `keystrokeScript`, `validateScript`,
`calculateScript`, `formatScript`, `clickScript`. Документные
события: `WC` (WillClose), `WS` (WillSave), `DS` (DidSave), `WP`
(WillPrint), `DP` (DidPrint).

---

## Аннотации

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Style\PaperSize;

$doc  = Document::new(PaperSize::A4);
$page = $doc->addPage();
$page->showText('Highlighted text', 72, 720, StandardFont::Helvetica, 12);
$page->addHighlightAnnotation(
    x1: 72, y1: 720, x2: 200, y2: 735,
    contents: 'Reviewer note: confirm this number.',
    color: [1.0, 1.0, 0.4],
);
file_put_contents('annotated.pdf', $doc->toBytes());
```

Виды аннотаций: `Text`, `Highlight`, `Underline`, `StrikeOut`,
`FreeText`, `Square`, `Circle`, `Line`, `Stamp`, `Ink`, `Polygon`,
`PolyLine`.

---

## Шифрование

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Encryption;
use Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm;

$doc = Document::new();
$doc->encrypt(
    userPassword: 'secret',
    ownerPassword: 'owner',
    permissions: Encryption::PERM_PRINT | Encryption::PERM_COPY,
    algorithm: EncryptionAlgorithm::Aes_256_R6,
);
$doc->toFile('encrypted.pdf');
```

Алгоритмы:

| Алгоритм      | V/R  | Шифр         | Версия PDF  |
|---------------|------|--------------|-------------|
| `Rc4_128`     | V2 R3 | RC4-128      | 1.4         |
| `Aes_128`     | V4 R4 | AES-128-CBC (AESV2) | 1.6 |
| `Aes_256`     | V5 R5 | AES-256-CBC (AESV3) | 1.7 |
| `Aes_256_R6`  | V5 R6 | AES-256 + Algorithm 2.B итеративный хэш | 2.0 |

Биты разрешений: `PERM_PRINT`, `PERM_MODIFY`, `PERM_COPY`,
`PERM_ANNOTATE`, `PERM_FILL_FORMS`, `PERM_ACCESSIBILITY`,
`PERM_ASSEMBLE`, `PERM_PRINT_HIGH`.

Для AES требуется `ext-openssl`.

---

## Цифровая подпись

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Element\FormField;

$doc = Document::new();
$page = $doc->addPage();

// At least one signature widget must exist.
$page->addFormField(new FormField(
    type: 'signature',
    name: 'sig1',
    x: 100, y: 100, w: 200, h: 60,
));

$doc->sign(new SignatureConfig(
    certificatePem: file_get_contents('cert.pem'),
    privateKeyPem: file_get_contents('key.pem'),
    privateKeyPassphrase: 'optional',
    signerName: 'Jane Doe',
    reason: 'Document approval',
    location: 'Berlin',
    contactInfo: 'jane@example.com',
));

$doc->toFile('signed.pdf');
```

PDF эмитируется с плейсхолдерами `/ByteRange` и `/Contents`, которые
патчатся на месте после подписи PKCS#7 detached.

---

## PDF/A и Tagged PDF

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;

$doc = Document::new();
$doc->enablePdfA(new PdfAConfig(
    conformance: PdfAConfig::CONFORMANCE_B,   // or CONFORMANCE_A, CONFORMANCE_U
    title: 'Archive copy',
    author: 'Acme Corp',
    lang: 'en-US',
));
// Conformance 'A' auto-enables Tagged PDF.
$doc->toFile('pdfa.pdf');
```

`enablePdfA()` несовместим с `encrypt()` и `enablePdfX()` —
библиотека бросает исключение при конфликте.

Tagged PDF без PDF/A:

```php
$doc->enableTagged();
$doc->setLang('en-US');
```

Tagged-эмиссия выдаёт `/StructTreeRoot` с `H1`–`H6`, `P`,
`Table`, `TR`, `TD`, `L`, `LI`, `Link`, плюс опциональную
пользовательскую `/RoleMap` (`setStructRoleMap(['MyHeading' => 'H1', ...])`).

---

## Соответствие PDF/X для печати

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\PdfXConfig;

$doc = Document::new();
$doc->enablePdfX(new PdfXConfig(
    variant: PdfXConfig::VARIANT_X4,
    outputConditionIdentifier: 'FOGRA39',
    outputCondition: 'Coated FOGRA39',
    registryName: 'http://www.color.org',
    title: 'Print master',
    trapped: 'False',
));
$doc->toFile('print.pdf');
```

Варианты: `VARIANT_X1A`, `VARIANT_X3`, `VARIANT_X4`. Вызывающий код
отвечает за соответствие на уровне содержимого (например, конвертацию
в CMYK для X-1a, отсутствие прозрачности для X-1a / X-3).

---

## Потоковый вывод

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;

$builder = DocumentBuilder::new();
// ... add thousands of pages ...

// Direct to a file without buffering the whole document.
$builder->toFile('/tmp/big.pdf');

// Or to any stream resource — HTTP response, php://stdout, etc.
$fp = fopen('php://output', 'wb');
$builder->build()->toStream($fp);
```

Потоковая запись сразу сбрасывает сборку байтов в поток вместо
буферизации полной документной строки в памяти.

---

## Группы опционального контента (слои)

```php
use Dskripchenko\PhpPdf\Pdf\Document;

$doc = Document::new();
$base = $doc->addLayer('Base map', defaultVisible: true);
$annotations = $doc->addLayer('Annotations', defaultVisible: false);

$page = $doc->addPage();
$page->beginLayer($base);
// ... draw the base map ...
$page->endLayer();
$page->beginLayer($annotations);
// ... draw annotations ...
$page->endLayer();
```

Слои появляются в панели «Слои» в Acrobat; читатели могут включать и
выключать их.

---

## Низкоуровневая эмиссия

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Style\PaperSize;

$doc  = Document::new(PaperSize::A4);
$page = $doc->addPage();

$page->showText('Lower left', 30, 30, StandardFont::Helvetica, 10);
$page->showText('Hello, world!', 200, 400, StandardFont::TimesRoman, 24);

// Filled rectangle.
$page->saveState();
$page->setNonStrokingColor(0.9, 0.1, 0.1);
$page->fillRectangle(100, 100, 200, 50);
$page->restoreState();

// Line.
$page->saveState();
$page->setStrokingColor(0, 0, 0);
$page->setLineWidth(2.0);
$page->moveTo(50, 200);
$page->lineTo(550, 200);
$page->stroke();
$page->restoreState();

file_put_contents('low-level.pdf', $doc->toBytes());
```

У `Pdf\Document` нет движка раскладки — координаты в PDF-пунктах
(1/72 дюйма), начало координат в левом нижнем углу. Используйте его
для PDF в стиле билетов или оверлеев, где позиционирование полностью
рассчитывает вызывающий код.

Конфигурация `Pdf\Document`:

- `setMetadata(...)` — Title, Author, Subject, Keywords, Creator,
  Producer, CreationDate.
- `useXrefStream(true)` — эмитировать таблицу перекрёстных ссылок как
  потоковый объект (PDF 1.5+), ~50% компактнее метаданных.
- `useObjectStreams(true)` — упаковать непотоковые словари в Object
  Streams, ~15–30% меньше для метаданно-насыщенных документов.
- `setViewerPreferences(['hideToolbar' => true, ...])`.
- `setPageLabels([['startPage' => 0, 'style' => 'lower-roman'], ...])`.
- `setOpenAction('fit-page', pageIndex: 3)`.
- `attachFile($name, $bytes, mimeType: 'application/json')`.

Полную поверхность API см. в `src/Pdf/Document.php`.
