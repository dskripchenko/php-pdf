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
  - [Сноски](#сноски)
  - [Настройка страницы и разрывы секций](#настройка-страницы-и-разрывы-секций)
- [Вход HTML / CSS](#вход-html--css)
- [Пользовательские шрифты](#пользовательские-шрифты)
  - [Шрифты в процессах «на запрос»](#шрифты-в-процессах-на-запрос)
- [Штрихкоды](#штрихкоды)
- [Графики](#графики)
- [Математические выражения](#математические-выражения)
- [SVG](#svg)
- [Гиперссылки и закладки](#гиперссылки-и-закладки)
- [Формы (AcroForm)](#формы-acroform)
- [Заполнение существующей формы (AcroForm)](#заполнение-существующей-формы-acroform)
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
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;

$style = new ParagraphStyle(
    alignment: Alignment::Both,   // justified
    spaceBeforePt: 6.0,
    spaceAfterPt: 6.0,
    indentLeftPt: 36.0,
    indentFirstLinePt: 18.0,
    lineHeightMult: 1.5,
    paddingTopPt: 8.0,
    paddingBottomPt: 8.0,
    backgroundColor: 'f0f0f0',
);
```

Применяется через `ParagraphBuilder::style($style)`. Внутренние отступы
задаются по сторонам: `paddingTopPt`, `paddingRightPt`, `paddingBottomPt`,
`paddingLeftPt`.

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

`ListFormat` покрывает `Bullet`, `Decimal`, `LowerLetter`, `UpperLetter`,
`LowerRoman`, `UpperRoman`.

### Изображения

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;

DocumentBuilder::new()
    ->image('/path/to/logo.png', widthPt: 120)
    ->paragraph('Above is our logo.')
    ->toFile('with-image.pdf');
```

Поддерживаемые форматы: JPEG (оттенки серого, RGB, CMYK) и PNG — оттенки
серого (1–16 бит), серый + альфа, RGB и RGBA (8/16 бит, альфа через SMask) и
палитровые (1–8 бит, прозрачность tRNS через SMask), чересстрочные (Adam7) и обычные. Одно и то же изображение, использованное N раз в
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
            $p->text('Page ')->pageNumber()->text(' of ')->totalPages());
    })
    ->watermark('DRAFT')
    ->paragraph('Document body.')
    ->toFile('with-chrome.pdf');
```

`->watermarkImage($image)` принимает `PdfImage`. У обоих водяных
знаков есть управление прозрачностью через `watermarkTextOpacity()` /
`watermarkImageOpacity()`.

### Сноски

`new Footnote('текст')` в абзаце ставит в строке номер, а текст — внизу
её страницы. Где этот низ, решает секция:

```php
use Dskripchenko\PhpPdf\Section;

new Section($body, footnotesAtPageFoot: true);   // столько места, сколько займут сноски страницы
new Section($body, footnoteBottomReservedPt: 60); // фиксированная зона на каждой странице
new Section($body);                               // концевые сноски после секции
```

С `footnotesAtPageFoot` страница без сносок ничего не теряет, а строка,
чьи сноски не помещаются на её странице, переходит дальше вместе с ними —
так, как размещает их текстовый редактор.
`new Footnote('текст', new RunStyle(sizePt: 8))` задаёт собственный стиль
текста сноски.

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
        margins: new PageMargins(topPt: 36, rightPt: 36, bottomPt: 36, leftPt: 36),
    ))
    ->heading(1, 'Wide content')
    ->toFile('landscape.pdf');
```

Форматы бумаги (`PaperSize`): `A3`, `A4`, `A5`, `A6`, `Letter`, `Legal`,
`Tabloid`, `Executive`. Любой другой размер задаётся в пунктах:
`new PageSetup(customDimensionsPt: [252, 144])` (карточка 3,5 × 2 дюйма); в
`Pdf\Document` это `addPage(customDimensionsPt: [...])` или
`defaultCustomDimensionsPt` конструктора.

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
  `<caption>` / `<colgroup>` / `<col>`, `<blockquote>`, `<pre>`,
  `<dl>` / `<dt>` / `<dd>`; можно передать весь документ (`<html>`,
  `<head>`, `<body>`).
- Строчные: `<b>` / `<strong>`, `<i>` / `<em>`, `<u>`, `<s>` / `<del>`,
  `<sup>` / `<sub>`, `<br>`, `<img>`, `<a>`, `<span>`, `<code>`,
  `<kbd>`, `<samp>`, `<tt>`, `<var>`, `<mark>`, `<small>`, `<big>`,
  `<ins>`, `<cite>`, `<dfn>`, `<q>`, `<abbr>`.
- Устаревшие: `<center>`, `<font color face size>`, атрибуты таблиц
  `border`, `cellpadding`, `cellspacing`, `width`, `align`, `bgcolor`,
  `valign`.

**CSS** — из блоков `<style>` и атрибутов `style`, с каскадом:

- Селекторы: тег, `.class`, `#id`, `*`, `[attr]` / `[attr=value]`,
  `:root`, `:first-child`, `:last-child`, `:only-child`, `:nth-child()`, `:not()`,
  потомок, `>`, `+`, `~`; специфичность, `!important`. `@media print` и
  `all` применяются, `@media screen` пропускается.
- Пользовательские свойства: объявления `--name` наследуются, а
  `var(--name, запасное)` подставляет их в любое значение, включая
  сокращённые свойства; свойство, чья переменная не найдена и не имеет
  запасного значения, отбрасывается, как в браузере.
- Наследуются, как в браузере: `color`, `font-*`, `text-decoration`,
  `text-transform`, `letter-spacing`, `text-align`, `line-height`,
  `text-indent`, `white-space` — заданные на `body`, `div` или `td`,
  они доходят до текста внутри.
- Текст: `color`, `background-color` (подложка под текстом),
  `font-size` (pt, px, mm, cm, in, em, rem, %, ключевые слова),
  `font-family`, `font-weight` (`normal` снимает жирность),
  `font-style`, `text-decoration`, `text-transform`, `letter-spacing`,
  `vertical-align: super / sub`, `white-space` (`pre`, `pre-wrap`,
  `pre-line`, `nowrap`). `&nbsp;` сохраняется.
- Блоки: `margin`, `padding`, `border` (сокращение, по сторонам,
  `-width`, `-style`, `-color`), `background-color`, `text-align`,
  `line-height` (множитель, %, длина), `text-indent`,
  `display: none / block / flex / grid`.
- Контейнеры: `div` (`section`, …) с блоками внутри оставляет их
  блоками; его margin и padding дают отступы, фон или рамка — коробку
  вокруг. `display: flex` ставит детей в ряд (`gap`, `width` /
  `flex-basis`, `align-items`); с `flex-wrap: wrap` они переносятся на
  столько строк, сколько требуют их ширины, `flex-direction: column`
  ставит их друг под другом. `display: grid` раскладывает детей ряд за
  рядом по колонкам `grid-template-columns` (длины, `%`, `fr`, `auto`,
  `repeat()`, `minmax()`), `grid-column: span n` (или `a / b`) растягивает
  на несколько колонок, `gap` / `row-gap` / `column-gap` задают зазоры.
- Обтекание и позиционирование: `float: left / right` ставит элемент и
  следующее за ним содержимое рядом — до элемента с `clear`; под
  float, который закончился раньше, текст не перетекает.
  `position: absolute` размещает элемент по `top` / `right` / `bottom` /
  `left` в области страницы — или от начала позиционированного
  (`relative`, …) предка — не занимая места; `position: fixed` делает то
  же на каждой странице начиная с текущей. Смещения самого
  `position: relative` не применяются.
- `break-inside: avoid` (`page-break-inside`) на любом блоке удерживает
  его на одной странице; блок выше страницы всё равно разбивается.
- Генерируемое содержимое: `::before` / `::after` (также `:before` /
  `:after`) с `content` — строки с CSS-экранированием, `attr(name)`,
  `open-quote` / `close-quote`, счётчики страниц `counter(page)` / `counter(pages)`
  (номер страницы, число страниц) — со своими собственными правилами
  стилей; другие счётчики не поддерживаются.
- Таблицы стилей: `<style>` и `<link rel="stylesheet">` в порядке
  документа; ссылка может вести на локальный файл (если не задано
  `localFiles: false`) или на
  `data:text/css`-URL — удалённые таблицы стилей не загружаются (парсер не
  делает сетевых запросов).
- Таблицы: `width`, `table-layout` (`auto` подбирает колонки по
  содержимому, как браузер), `border-collapse`, `border-spacing`,
  `border`, `margin` (`auto` центрирует); ширины `<col>`; ячейки:
  `border`, `background-color`, `padding`, `vertical-align`, `width`,
  `height`, `colspan`, `rowspan`; строки: `height`,
  `break-inside: avoid`. Строки `<thead>` повторяются на каждой
  странице.
- Списки: `<ol start>`, `type` и `list-style-type` (decimal, alpha,
  roman, `none`), `<li value>`; `::marker { content }` со строками,
  `counter(list-item)` и `counters(list-item, ".")` подписывает пункты —
  `3.1.` под пунктом 3, как записывается многоуровневая нумерация Word;
  пункт с `list-style-type: none` без собственного содержимого только несёт
  вложенный список. `padding-left` / `margin-left` списка и `margin-left`
  пункта задают, где начинается текст пунктов; метка висит перед ним, а
  табуляция после неё ставит текст на отступ, как в Word. `margin-top` /
  `margin-bottom` списка и поля пункта задают отступы вокруг них.
- Картинки: `width` / `height` (`%` от строки), `display: block` с
  `margin: auto` центрирует. `<svg>` в строке текста рисуется отдельным
  блоком: абзац делится вокруг него, а он берёт выравнивание текста.
- Страницы: `page-break-before` / `-after` (`break-before` / `-after`);
  `@page { size; margin }` задаёт страницу `Document::fromHtml()`, если
  не передан `$sectionTemplate`.

`em` и `%` считаются от текущего размера шрифта; там, где его никто не
задал, — от `$baseFontSizePt` у `Document::fromHtml()` (по умолчанию 12):
передайте туда `defaultFontSizePt` движка.

**Не поддерживается:** удалённые таблицы стилей и `@import`,
`grid-template-areas` и явное размещение по строкам grid, смещения
`position: relative`, перетекание текста под float, `:hover` и другие
динамические псевдоклассы, формы, JavaScript.

### Расширение разбора

Приложение может само решить, чем станет элемент, раньше парсера, —
полем подписи, сноской, штрихкодом, своим тегом, — и откуда берутся
картинки:

```php
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Footnote;
use Dskripchenko\PhpPdf\Element\SignatureField;
use Dskripchenko\PhpPdf\Html\ElementHandler;
use Dskripchenko\PhpPdf\Html\HtmlContext;

final class TemplateElements implements ElementHandler
{
    public function handle(\DOMElement $element, HtmlContext $context): ?array
    {
        return match (true) {
            $element->hasAttribute('data-signature') => [new SignatureField($element->getAttribute('data-signature'))],
            $context->inline && str_contains($element->getAttribute('class'), 'footnote') => [new Footnote(trim($element->textContent))],
            default => null, // the parser's own reading
        };
    }
}

$doc = Document::fromHtml($html,
    elementHandlers: [new TemplateElements],
    imageSource: fn (string $src): ?string => str_starts_with($src, 'storage://') ? $storage->get(substr($src, 10)) : null,
);
```

Обработчики опрашиваются по порядку о каждом элементе — блочных между
блоками, остальных там, где течёт текст (`$context->inline`); побеждает
первый ответ не null. `$context->style` — стиль текста в точке элемента,
`$context->inlines($element)` / `$context->blocks($element)` читают его
содержимое так, как прочитал бы парсер, — обработчик может его обернуть.
`imageSource` получает каждый `src` первым; null — обычный путь через
`data:`-URL и локальные файлы.

Блок, возвращённый для элемента в строке текста, — поле подписи для
`<img>` внутри `<p>` — делит абзац в этом месте: текст до и после него
становится отдельными абзацами, первый сохраняет отступ сверху, последний
— снизу. `$context->alignment` — действующее выравнивание текста, чтобы
поставить такой блок.

Для HTML от недоверенных авторов передайте `localFiles: false`: тогда
`<img src>` и `<link href>` ничего не читают с диска — только `data:`-URL
и `imageSource`.

Номерам страниц обработчик не нужен: `content: counter(page)` и
`counter(pages)` в `::before` / `::after` печатают номер страницы и
число страниц —
`.page-number::after { content: "Стр. " counter(page) " из " counter(pages) }`.

---

## Пользовательские шрифты

Без встроенного шрифта текст набирается шрифтами base-14 (WinAnsi:
западноевропейская латиница, прочие символы выводятся как `?`): Helvetica —
или семейство `fallbackStandard` — с жирностью и наклоном фрагмента, Times
для `fontFamily` с засечками, Courier для моноширинного; ширина считается по
их реальным метрикам. Шрифт основного текста — `defaultFont` движка, плюс
необязательные `boldFont`, `italicFont` и `boldItalicFont`:

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;

$engine = new Engine(
    defaultFont: new PdfFont(TtfFile::fromFile(__DIR__.'/fonts/DejaVuSans.ttf')),
    boldFont: new PdfFont(TtfFile::fromFile(__DIR__.'/fonts/DejaVuSans-Bold.ttf')),
    // Words the main font has no glyphs for (CJK here) are set in a fallback.
    fallbackFonts: [new PdfFont(TtfFile::fromFile(__DIR__.'/fonts/DroidSansFallback.ttf'))],
);

DocumentBuilder::new()
    ->paragraph('Здравствуй, мир — Γειά σου κόσμε — 你好世界')
    ->toFile('fonts.pdf', $engine);
```

`fallbackFonts` перебираются пословно: слово, которое основной шрифт не
может нарисовать, набирается первым запасным шрифтом, в котором есть все
его глифы.

`FontProvider` обслуживает текст, в котором названо семейство шрифта, —
`RunStyle(fontFamily: ...)` у run или CSS `font-family` (первое семейство
из списка, которое есть у провайдера; без встроенного шрифта общие имена
выбирают начертание base-14 — `serif` Times, `monospace` Courier). Шрифт
по умолчанию он не меняет:

```php
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Font\ChainedFontProvider;
use Dskripchenko\PhpPdf\Font\DirectoryFontProvider;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdfFontsLiberation\LiberationFontProvider;

$fonts = new ChainedFontProvider(
    new DirectoryFontProvider(__DIR__.'/fonts'),  // DejaVuSans.ttf, DejaVuSans-Bold.ttf, …
    new LiberationFontProvider,                  // dskripchenko/php-pdf-fonts-liberation
);

$engine = new Engine(
    defaultFont: new PdfFont($fonts->resolve('LiberationSerif-Regular')),
    boldFont: new PdfFont($fonts->resolve('LiberationSerif-Bold')),
    fontProvider: $fonts,
);

Document::fromHtml(<<<'HTML'
<p>Body text in Liberation Serif.</p>
<p style="font-family: DejaVuSans">DejaVu Sans, <b>bold</b>.</p>
<p style="font-family: Arial">Arial → Liberation Sans.</p>
HTML)->toFile('fonts-provider.pdf', $engine);
```

`DirectoryFontProvider` сканирует каталог на файлы `.ttf` / `.otf` и
находит их по PostScript-имени (`DejaVuSans`, `DejaVuSans-Bold`). Для
семейства `X` движок запрашивает `X-BoldItalic`, `X-Bold`, `X-Italic`,
`X-Regular`, затем `X` — в зависимости от жирности и начертания run.
`ChainedFontProvider` опрашивает свои провайдеры по порядку и берёт
первый найденный шрифт — он разрешает имена, а не недостающие глифы;
откат по глифам — это `fallbackFonts`.
[dskripchenko/php-pdf-fonts-liberation](https://github.com/dskripchenko/php-pdf-fonts-liberation)
поставляет `LiberationFontProvider`: Liberation Sans, Serif и Mono, в том
числе под именами `Arial`, `Times New Roman` и `Courier New`.

Шрифты с TrueType-контурами (`.ttf`, `.otf` с TrueType-контурами)
сабсетятся по требованию — в файл попадают только использованные глифы.
OpenType-шрифты на основе CFF (`.otf` с таблицей `CFF `) встраиваются
целиком, без сабсеттинга, как CIDFontType0 с программой
`/FontFile3 /CIDFontType0C` (допустимо и в PDF/A-1); в файл уходит полный
размер CFF (100–200 КБ для начертания с латиницей и кириллицей). Шрифты
с CFF2 (вариативный CFF) отклоняются. Кернинг, базовые лигатуры и ToUnicode CMaps
эмитятся автоматически.

### Шрифты в процессах «на запрос»

`TtfFile::fromFile()` держит разобранные шрифты всё время жизни процесса
(не больше `TtfFile::$cacheLimit`), поэтому воркер очереди или Octane
разбирает каждый шрифт один раз. Под PHP-FPM каждый запрос начинает с нуля
и разбирает шрифты заново — около миллисекунды на латинскую гарнитуру и
несколько на CJK. `exportState()` сохраняет разобранный шрифт как простые
данные (целые, строки, булевы значения и массивы), а `TtfFile::fromState()`
восстанавливает его из этих данных и того же файла шрифта без разбора:

```php
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\PdfFont;

function cachedFont(string $path, string $cacheDir): TtfFile
{
    $bytes = (string) file_get_contents($path);
    $file = $cacheDir.'/'.basename($path).'.v'.TtfFile::STATE_FORMAT.'.php';

    if (is_file($file)) {
        try {
            return TtfFile::fromState(require $file, $bytes);
        } catch (\InvalidArgumentException) {
            // другой файл шрифта или повреждённое состояние: разбираем заново ниже
        }
    }

    $font = new TtfFile($bytes);
    $tmp = $file.'.'.getmypid();
    file_put_contents($tmp, '<?php return '.var_export($font->exportState(), true).';');
    rename($tmp, $file);

    return $font;
}

$regular = new PdfFont(cachedFont(__DIR__.'/fonts/DejaVuSans.ttf', __DIR__.'/var/fonts'));
```

Состояние, записанное через `var_export()` и прочитанное `require`, лежит в
общей памяти OPcache, и восстановление стоит долю разбора. Подойдёт и JSON;
при `serialize()` читайте обратно через
`unserialize($s, ['allowed_classes' => false])` — объектов в состоянии
не бывает.

Состояние предназначено для собственного кэша приложения: `fromState()`
проверяет его форму, но не целостность, поэтому не восстанавливайте
состояние, пришедшее извне. Состояние другого `TtfFile::STATE_FORMAT`, от
другого файла шрифта или повреждённое отклоняется с
`InvalidArgumentException` — тогда шрифт разбирается заново, как в примере.
Пара методов экспериментальная: релиз, меняющий формат, повышает
`STATE_FORMAT`, кэш один раз промахивается и заполняется снова, больше
ничего не ломается. Если `STATE_FORMAT` входит в ключ кэша, как в примере,
состояния разных версий не смешиваются.

Тела встроенных шрифтов сжимаются zlib на уровне 6. Там, где документы
строятся на каждый запрос, `PdfFont::$compressionLevel = 1;` сжимает
подмножество в два–пять раз быстрее, а поток выходит больше на несколько
процентов. Значения вне -1..9 отклоняются с `InvalidArgumentException`.

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

$vcard = QrEncoder::vCard([
    'name' => 'Jane Doe',
    'org' => 'Acme Corp',
    'email' => 'jane@example.com',
    'tel' => '+1-555-0100',
]);

$wifi = QrEncoder::wifi(ssid: 'guest', password: 'welcome', hidden: false);

$email = QrEncoder::email('hello@example.com', subject: 'Hi');
```

`vCard()` (3.0) принимает `name`, `org`, `title`, `tel`, `email`, `url` и
`address`. Остальные фабрики — `url()`, `sms($phone, $message)` и
`geo($latitude, $longitude, zoom: ...)`.

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

`<text>` набирается шрифтами документа — той же цепочкой семейств,
запасными шрифтами и начертаниями, что и абзац, — поэтому кириллица и другие
нелатинские письменности печатаются как в тексте; без встроенного шрифта
текст уходит в шрифт base-14, в котором есть только латиница. `font-family`,
`font-weight`, `font-style`, `font-size`, `fill` и `text-anchor` берутся у
элемента, из его `style` или у ближайшего предка; `transform` группы
поворачивает и масштабирует глифы вместе с фигурами. Не поддерживаются:
позиционирование `<tspan>`, `<textPath>`, `dx`/`dy`.

Инлайн-SVG работает и внутри HTML — теги `<svg>` пробрасываются в
SVG-рендерер.

---

## Гиперссылки и закладки

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;

DocumentBuilder::new()
    ->heading(1, 'Contents')
    ->paragraph(function (ParagraphBuilder $p) {
        $p->text('Visit ')->link('https://example.com', 'our site')
          ->text(', or jump to ')->internalLink('terms', 'the terms');
    })
    ->heading(1, 'Chapter 1')          // h1–h6 build the outline
    ->heading(2, 'Section 1.1')
    ->paragraph(fn (ParagraphBuilder $p) => $p->bookmark('terms')->text('Terms apply.'))
    ->toFile('linked.pdf');
```

Панель оглавления (закладки) строится из заголовков: `heading(1, …)` —
запись верхнего уровня, `heading(2, …)` вкладывается в неё и так далее до
уровня 6. `ParagraphBuilder::bookmark($name)` ставит именованное
назначение; `internalLink($name, …)` переходит к нему, `link($url, …)`
открывает URI. У `Pdf\Page` есть также `addExternalLink()`,
`addInternalLink()`, `addNamedActionLink()`, `addJavaScriptLink()` и
`addLaunchLink()` для абсолютно позиционированных областей ссылок.

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
    ->block(new FormField('full_name', tooltip: 'Full name'))
    ->block(new FormField('agree', FormField::TYPE_CHECKBOX, widthPt: 14, heightPt: 14))
    ->block(new FormField('country', FormField::TYPE_COMBO,
        defaultValue: 'US', options: ['US', 'UK', 'DE', 'RU', 'CN']))
    ->block(new FormField('send', FormField::TYPE_SUBMIT_BUTTON, widthPt: 80, heightPt: 28,
        buttonCaption: 'Submit', submitUrl: 'https://example.com/submit'))
    ->toFile('form.pdf');
```

`FormField` — блок в потоке: принимает `name`, `type` (по умолчанию
text), `defaultValue`, `widthPt` / `heightPt` (по умолчанию 200 × 20),
`tooltip`, `required`, `readOnly`, `options` и аргументы скриптов и кнопок,
описанные ниже. Чтобы поставить поле по абсолютным координатам, добавьте
его на `Pdf\Page`:

```php
use Dskripchenko\PhpPdf\Pdf\Document;

$doc = Document::new();
$page = $doc->addPage();
$page->addFormField('text', 'full_name', x: 100, y: 700, width: 200, height: 24);
$page->addFormField('checkbox', 'agree', x: 100, y: 660, width: 14, height: 14, defaultValue: 'on');
$doc->toFile('form-absolute.pdf');
```

Типы полей: `text`, `text-multiline`, `password`, `checkbox`,
`radio-group`, `combo`, `list`, `push`, `submit`, `reset`, `signature`.

JavaScript-хуки на уровне поля: `keystrokeScript`, `validateScript`,
`calculateScript`, `formatScript`, `clickScript`. Документные
события: `WC` (WillClose), `WS` (WillSave), `DS` (DidSave), `WP`
(WillPrint), `DP` (DidPrint).

---

## Заполнение существующей формы (AcroForm)

Выше описано создание новой формы. Чтобы открыть PDF, созданный кем-то
другим — загруженный шаблон, государственную форму — и заполнить значения
уже определённых в нём полей по имени, используйте вместо этого
`ExistingFormFiller`:

```php
use Dskripchenko\PhpPdf\Pdf\Forms\ExistingFormFiller;

ExistingFormFiller::fromFile('template.pdf')
    ->useFont('fonts/LiberationSans-Regular.ttf') // для кириллицы и прочего не-Latin
    ->setValues([
        'full_name' => 'Jane Roe',
        'agree' => true,
        'employer.name' => 'Acme Corp', // разрешается через наследование /Parent
        'country' => 'Germany',         // вариант combo box (экспортное значение или подпись)
    ])
    ->stampImage(0, 'signature.png', x: 100, y: 600, width: 120, height: 40)
    ->flatten()
    ->toFile('filled.pdf');
```

Исходный документ никогда не изменяется — каждый вызов работает с глубокой
копией *всего* графа объектов, поэтому всё, что не затронуто (оглавление,
метаданные, прочие аннотации), переходит в результат без изменений.
`fields()` возвращает для каждого поля полное имя через точку, тип, текущее
значение, допустимые варианты, `/MaxLen` и подсказку `/TU` — так можно
изучить устройство шаблона до заполнения:

```php
foreach (ExistingFormFiller::fromFile('template.pdf')->fields() as $name => $field) {
    echo "{$name}: {$field->type} = ".json_encode($field->value)."\n";
}
```

Значения по типам полей:

| Тип | Что принимает |
|---|---|
| `text`, `text-multiline` | строку; `/MaxLen` соблюдается |
| `checkbox` | `true`/`false`, `on`/`yes`/`true`/`1`, `off`/`no`/`false`/`0` или своё экспортное значение |
| `radio` | один из `options` (без учёта регистра) или `Off` |
| `combo`, `list` | экспортное значение варианта или его подпись; для list box с множественным выбором — список |
| `signature`, `push` | задать нельзя — `LogicException` |

Неизвестный вариант отклоняется с `InvalidArgumentException`, а не
игнорируется молча.

Заполненные текстовые поля и поля выбора получают заново сгенерированный
внешний вид — шрифт, размер (включая авторазмер), цвет и выравнивание из
`/DA`, фон и рамку из `/MK`, comb-ячейки, перенос строк — поэтому значение
видно в любом просмотрщике, а не только в тех, что учитывают
`/NeedAppearances`. Если собственный шрифт формы не может показать значение
(кириллица, греческий, CJK…), оно рисуется встроенным TrueType-шрифтом:
передайте его в `useFont()` (или `FontProvider` в `useFontProvider()`), либо
установите `dskripchenko/php-pdf-fonts-liberation` — тогда он подхватится
автоматически. Без шрифта такое значение приводит к `RuntimeException` с
именем поля.

`flatten(?array $fieldNames = null)` превращает поля в статичное содержимое
страницы так же, как это делает Acrobat: собственный внешний вид каждого
виджета — галочки, точки радиокнопок, фон, рамки — рисуется на странице, а
виджет удаляется. Передайте список имён, чтобы «запечь» только часть полей и
оставить остальные интерактивными, или не передавайте ничего — тогда
обрабатываются все виджеты и `/AcroForm` удаляется.

`stampImage()`/`stampImageBytes()` ставят PNG (с альфа-каналом) или JPEG
независимо от полей — например, подпись или фото, которые сами полем формы
не являются. `x`/`y` — левый верхний угол картинки в пунктах от левого
верхнего угла страницы *в том виде, как её показывает просмотрщик* (CropBox,
с учётом `/Rotate`); этот размер возвращает `pageSize($index)`.

Результат — полностью пересобранный документ без шифрования. Существующие
цифровые подписи перестают проходить проверку, а при любом изменении
удаляется XFA-описание формы (иначе просмотрщики с поддержкой XFA показали бы
XFA-слой вместо заполненных значений).

`toBytes(incremental: true)` вместо этого дописывает только изменения после
исходного файла: подписи остаются действительными (см. [заполнение подписанной формы](SIGNING.md#заполнение-подписанной-формы)).

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
    x: 72, y: 716, width: 100, height: 16,
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
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;

$doc = Document::new();
$page = $doc->addPage();

// Поле подписи, показанное штампом с данными сертификата.
$page->addSignatureField('approval', x: 72, y: 600, width: 250, height: 75,
    appearance: SignatureAppearance::simple());

$doc->sign(new SignatureConfig(
    certPem: file_get_contents('signer.crt'),
    privateKeyPem: file_get_contents('signer.key'),
    privateKeyPassphrase: 'optional',
    signerName: 'Jane Doe',
    reason: 'Document approval',
    location: 'Berlin',
));

$doc->toFile('signed.pdf');
```

PDF эмитируется с плейсхолдерами `/ByteRange` и `/Contents`, которые
патчатся на месте отсоединённой CMS-подписью (по умолчанию PAdES). Поле подписи в потоке
документа, оформление штампа, плейсхолдеры, шрифты и несколько подписантов —
в [руководстве по подписи](SIGNING.md). Для ключей, которые никогда не
покидают устройство или сервис, есть готовые подписанты:
[php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11) (HSM,
смарт-карты, USB-токены),
[php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms)
(AWS KMS, Google Cloud KMS, Azure Key Vault) и
[php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro)
(КриптоПро CSP на сервере) — см. [SIGNING.md](SIGNING.md#ключи-и-сертификаты).

---

## PDF/A и Tagged PDF

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;

$doc = Document::new();
$doc->enablePdfA(new PdfAConfig(
    iccProfilePath: 'vendor/dskripchenko/php-pdf/resources/icc/sRGB2014.icc',
    part: PdfAConfig::PART_2,                 // PART_1, PART_2 or PART_3
    conformance: PdfAConfig::CONFORMANCE_U,   // or CONFORMANCE_A, CONFORMANCE_B
    title: 'Archive copy',
    author: 'Acme Corp',
    lang: 'en-US',
));
// Conformance 'A' auto-enables Tagged PDF.
$doc->addPage();
$doc->toFile('pdfa.pdf');
```

Для output intent нужен ICC-профиль sRGB; пакет поставляет его в
`resources/icc/sRGB2014.icc`. PDF/A-1 допускает уровень соответствия A или
B; PDF/A-2 и PDF/A-3 — ещё и U. PDF/A также требует, чтобы все шрифты были
встроены — base-14 шрифты валидацию не проходят, поэтому набирайте текст
TTF-шрифтом (`PdfFont` или `Engine` с `defaultFont` для свёрстанных
документов).

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
    iccProfilePath: 'vendor/dskripchenko/php-pdf/resources/icc/sRGB2014.icc',
    variant: PdfXConfig::VARIANT_X4,
    title: 'Print master',
    trapped: PdfXConfig::TRAPPED_FALSE,
));
$doc->addPage();
$doc->toFile('print.pdf');
```

Варианты: `VARIANT_X1A`, `VARIANT_X3`, `VARIANT_X4`. Встроенный профиль
sRGB подходит для X-3 и X-4; для условий печати передайте их CMYK-профиль
(для X-1a CMYK-профиль обязателен — в пакете его нет) вместе с
`iccProfileName`, `outputConditionIdentifier` (например, `FOGRA39`) и
`outputCondition`. Вызывающий код отвечает за соответствие на уровне содержимого (например, конвертацию
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

// Filled rectangle (x, y, width, height, RGB 0..1).
$page->fillRect(100, 100, 200, 50, r: 0.9, g: 0.1, b: 0.1);

// Outlined rectangle and a line, 2 pt wide.
$page->strokeRect(100, 300, 200, 50, lineWidthPt: 2.0);
$page->strokeLine(50, 200, 550, 200, lineWidthPt: 2.0);

$doc->metadata(title: 'Ticket', author: 'Acme Corp');
file_put_contents('low-level.pdf', $doc->toBytes());
```

Другие методы рисования у `Pdf\Page`: `fillRoundedRect()`,
`strokeRoundedRect()`, `strokeDashedLine()`, `fillPolygon()`,
`strokePolyline()`, `drawImage()`.

У `Pdf\Document` нет движка раскладки — координаты в PDF-пунктах
(1/72 дюйма), начало координат в левом нижнем углу. Используйте его
для PDF в стиле билетов или оверлеев, где позиционирование полностью
рассчитывает вызывающий код.

Конфигурация `Pdf\Document`:

- `metadata(title: ..., author: ..., subject: ..., keywords: ...,
  creator: ..., producer: ..., creationDate: ...)` — словарь `/Info`.
- `useXrefStream(true)` — эмитировать таблицу перекрёстных ссылок как
  потоковый объект (PDF 1.5+), ~50% компактнее метаданных.
- `useObjectStreams(true)` — упаковать непотоковые словари в Object
  Streams, ~15–30% меньше для метаданно-насыщенных документов.
- `setViewerPreferences(['hideToolbar' => true, ...])`.
- `setPageLabels([['startPage' => 0, 'style' => 'lower-roman'], ...])`.
- `setOpenAction('fit-page', pageIndex: 3)`.
- `attachFile($name, $bytes, mimeType: 'application/json')`.

Полную поверхность API см. в `src/Pdf/Document.php`.
