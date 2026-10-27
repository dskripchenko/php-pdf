# 使用指南

本指南将带您逐步了解 `dskripchenko/php-pdf`，从最简单的
HTML 转 PDF 一直深入到最底层的页面发射。每个章节都是自包含的 ——
您可以从头读到尾，也可以直接跳到您需要的特性。

## 目录

- [三个入口](#三个入口)
- [构建文档](#构建文档)
  - [段落与标题](#段落与标题)
  - [Run 样式](#run-样式)
  - [表格](#表格)
  - [列表](#列表)
  - [图片](#图片)
  - [页眉、页脚、水印](#页眉页脚水印)
  - [脚注](#脚注)
  - [页面设置与分节符](#页面设置与分节符)
- [HTML / CSS 输入](#html--css-输入)
- [自定义字体](#自定义字体)
  - [按请求运行的进程中的字体](#按请求运行的进程中的字体)
- [条形码](#条形码)
- [图表](#图表)
- [数学公式](#数学公式)
- [SVG](#svg)
- [超链接与书签](#超链接与书签)
- [表单（AcroForm）](#表单acroform)
- [填写已有表单（AcroForm）](#填写已有表单acroform)
- [标注](#标注)
- [加密](#加密)
- [数字签名](#数字签名)
- [PDF/A 与 Tagged PDF](#pdfa-与-tagged-pdf)
- [PDF/X 印刷一致性](#pdfx-印刷一致性)
- [流式输出](#流式输出)
- [可选内容组（图层）](#可选内容组图层)
- [底层发射](#底层发射)

---

## 三个入口

本库提供三个层次，每一层都可独立使用。

1. **`Document::fromHtml($html)`** —— 最简单。解析 HTML、完成排版，
   返回一个可直接写入的 `Document`。适合发票、报表，以及任何已经
   以 HTML 形式存在的内容。
2. **`Build\DocumentBuilder`** —— 流式调用。链式方法构建段落、
   表格、列表、图表、条形码。最终编译到与 HTML 入口相同的 AST。
   适合内容是动态计算的场景。
3. **`Pdf\Document`** —— 底层。添加页面、按绝对坐标绘制文字与图形。
   没有布局引擎、没有流式排布。适合票据类需要精确定位的场景。

您可以混合使用它们。`DocumentBuilder` 生成 AST；布局引擎发射底层
`Pdf\Document`；`toBytes()` 与 `toFile()` 在每一层都可用。

---

## 构建文档

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;

$bytes = DocumentBuilder::new()
    ->heading(1, 'Annual Report 2026')
    ->paragraph('Revenue is up 12% year-over-year.')
    ->toBytes();
```

`toBytes()` 将 PDF 作为字符串返回。`toFile($path)` 直接写入磁盘
并返回字节数。`build()` 返回 AST `Document`，便于您检查或后处理。

### 段落与标题

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

### Run 样式

`Run` 是带样式的文本片段。`RunStyle` 控制所有影响字形渲染的属性。

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

块级布局通过 `ParagraphStyle` 控制：

```php
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;

$style = new ParagraphStyle(
    alignment: Alignment::Both,   // 两端对齐
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

通过 `ParagraphBuilder::style($style)` 应用它。内边距按边设置：
`paddingTopPt`、`paddingRightPt`、`paddingBottomPt`、`paddingLeftPt`。

### 表格

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

行跨度、列跨度、对齐、边框以及单元格级样式均受支持。完整 API
请参阅 `src/Build/CellBuilder.php`。

### 列表

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

`ListFormat` 覆盖 `Bullet`、`Decimal`、`LowerLetter`、`UpperLetter`、
`LowerRoman`、`UpperRoman`。

### 图片

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;

DocumentBuilder::new()
    ->image('/path/to/logo.png', widthPt: 120)
    ->paragraph('Above is our logo.')
    ->toFile('with-image.pdf');
```

支持的格式：JPEG（灰度、RGB、CMYK）与 PNG —— 灰度
（1–16 位）、灰度 + alpha、RGB 与 RGBA（8/16 位，alpha 通过 SMask）以及调色板
（1–8 位，tRNS 透明度通过 SMask），隔行扫描（Adam7）与否均可。同一张图片在文档中被使用 N 次时，仅以单个 XObject
嵌入（通过内容哈希去重）。

### 页眉、页脚、水印

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

`->watermarkImage($image)` 接受一个 `PdfImage`。两种水印都可以通过
`watermarkTextOpacity()` / `watermarkImageOpacity()` 控制不透明度。

### 脚注

段落中的 `new Footnote('文本')` 会在行内放置编号标记，并把文本放到该页底部。
页底部的位置由分节决定：

```php
use Dskripchenko\PhpPdf\Section;

new Section($body, footnotesAtPageFoot: true);   // 页面脚注需要多少空间就占多少
new Section($body, footnoteBottomReservedPt: 60); // 每页固定区域
new Section($body);                               // 分节结束后的尾注
```

使用 `footnotesAtPageFoot` 时，没有脚注的页面不会损失空间；而当某行的脚注
在本页放不下时，该行会连同脚注一起移到下一页——与文字处理软件的排版方式
相同。`new Footnote('文本', new RunStyle(sizePt: 8))` 为脚注设置自己的文字样式。

### 页面设置与分节符

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

纸张尺寸（`PaperSize`）：`A3`、`A4`、`A5`、`A6`、`Letter`、`Legal`、
`Tabloid`、`Executive`。其他尺寸以点为单位给出：
`new PageSetup(customDimensionsPt: [252, 144])`（3.5 × 2 英寸的卡片）；在
`Pdf\Document` 上则是 `addPage(customDimensionsPt: [...])` 或构造函数的
`defaultCustomDimensionsPt`。

---

## HTML / CSS 输入

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

**支持的 HTML5：**

- 块级：`<p>`、`<div>`、`<section>`、`<article>`、`<h1>`–`<h6>`、
  `<header>`、`<footer>`、`<nav>`、`<aside>`、`<main>`、`<figure>`、
  `<figcaption>`、`<hr>`、`<ul>` / `<ol>` / `<li>`、`<table>` /
  `<thead>` / `<tbody>` / `<tfoot>` / `<tr>` / `<td>` / `<th>` /
  `<caption>` / `<colgroup>` / `<col>`、`<blockquote>`、`<pre>`、
  `<dl>` / `<dt>` / `<dd>`；也可以传入完整文档（`<html>`、`<head>`、`<body>`）。
- 行内：`<b>` / `<strong>`、`<i>` / `<em>`、`<u>`、`<s>` / `<del>`、
  `<sup>` / `<sub>`、`<br>`、`<img>`、`<a>`、`<span>`、`<code>`、
  `<kbd>`、`<samp>`、`<tt>`、`<var>`、`<mark>`、`<small>`、`<big>`、
  `<ins>`、`<cite>`、`<dfn>`、`<q>`、`<abbr>`。
- 旧式：`<center>`、`<font color face size>`，表格属性 `border`、
  `cellpadding`、`cellspacing`、`width`、`align`、`bgcolor`、`valign`。

**CSS**——来自 `<style>` 块和 `style` 属性，支持层叠：

- 选择器：类型、`.class`、`#id`、`*`、`[attr]` / `[attr=value]`、
  `:root`、`:first-child`、`:last-child`、`:only-child`、`:nth-child()`、`:not()`，
  后代、`>`、`+`、`~`；优先级、`!important`。`@media print` 和 `all`
  生效，`@media screen` 跳过。
- 自定义属性：`--name` 声明会被继承，`var(--name, 备用值)` 可在任何值
  （包括简写属性）中替换它们；变量缺失且没有备用值的属性会被丢弃，与浏览器
  一致。
- 与浏览器一样继承：`color`、`font-*`、`text-decoration`、
  `text-transform`、`letter-spacing`、`text-align`、`line-height`、
  `text-indent`、`white-space`——设置在 `body`、`div` 或 `td` 上，
  会作用到其中的文字。
- 文字：`color`、`background-color`（绘制在文字下方）、`font-size`
  （pt、px、mm、cm、in、em、rem、%、关键字）、`font-family`、
  `font-weight`（`normal` 取消加粗）、`font-style`、`text-decoration`、
  `text-transform`、`letter-spacing`、`vertical-align: super / sub`、
  `white-space`（`pre`、`pre-wrap`、`pre-line`、`nowrap`）。`&nbsp;` 会保留。
- 块：`margin`、`padding`、`border`（简写、按边、`-width`、`-style`、
  `-color`）、`background-color`、`text-align`、`line-height`（倍数、%、
  长度）、`text-indent`、`display: none / block / flex / grid`。
- 容器：包含块元素的 `div`（`section` 等）保留其中的块；其 margin 和
  padding 形成间距与缩进，背景或边框会在外面画出框。`display: flex`
  让子元素并排（`gap`、`width` / `flex-basis`、`align-items`）；配合
  `flex-wrap: wrap` 时按子元素宽度换成所需的多行，`flex-direction: column`
  让它们纵向堆叠。`display: grid` 将子元素逐行排入
  `grid-template-columns` 定义的列（长度、`%`、`fr`、`auto`、`repeat()`、
  `minmax()`），`grid-column: span n`（或 `a / b`）跨越多列，
  `gap` / `row-gap` / `column-gap` 设置间距。
- 浮动与定位：`float: left / right` 让元素与其后的内容并排，直到带
  `clear` 的元素为止；浮动提前结束时，文字不会流到其下方。
  `position: absolute` 按 `top` / `right` / `bottom` / `left` 在页面区域
  内放置元素——或相对于已定位（`relative` 等）祖先的起点——且不占据
  空间；`position: fixed` 从该处起在每一页上做同样的放置。
  `position: relative` 自身的偏移不生效。
- 任意块上的 `break-inside: avoid`（`page-break-inside`）使其保持在同一页；
  高于一页的块仍会被拆分。
- 生成内容：`::before` / `::after`（也支持 `:before` / `:after`）配合
  `content`——带 CSS 转义的字符串、`attr(name)`、`open-quote` /
  `close-quote`，以及页码计数器 `counter(page)` / `counter(pages)`
  （页码、总页数）——由其自身的规则设置样式；不支持其他计数器。
- 样式表：`<style>` 与 `<link rel="stylesheet">` 按文档顺序生效；链接可
  指向本地文件（除非设置 `localFiles: false`）或 `data:text/css` URL——不获取远程样式表（解析器不发起
  网络请求）。
- 表格：`width`、`table-layout`（`auto` 像浏览器一样按内容确定列宽）、
  `border-collapse`、`border-spacing`、`border`、`margin`（`auto` 居中）；
  `<col>` 宽度；单元格：`border`、`background-color`、`padding`、
  `vertical-align`、`width`、`height`、`colspan`、`rowspan`；行：
  `height`、`break-inside: avoid`。`<thead>` 行在每一页重复。
- 列表：`<ol start>`、`type` 与 `list-style-type`（decimal、alpha、roman、
  `none`）、`<li value>`；带字符串、`counter(list-item)` 和
  `counters(list-item, ".")` 的 `::marker { content }` 为列表项加标签——在第 3 项
  下显示 `3.1.`，与 Word 多级编号的写法一致；`list-style-type: none` 且自身无
  内容的列表项只承载其嵌套列表。列表的 `padding-left` / `margin-left` 与列表项的
  `margin-left` 决定列表项文字的起点；标签悬挂在其前方，标签后的制表位把文字
  放到缩进处，与 Word 一致。列表的 `margin-top` / `margin-bottom` 与列表项的外边距
  决定其周围的间距。
- 图片：`width` / `height`（`%` 相对于行宽），`display: block` 配合
  `margin: auto` 居中。文字行中的 `<svg>` 作为独立的块绘制：段落在其周围
  拆分，它沿用文字的对齐方式。
- 分页：`page-break-before` / `-after`（`break-before` / `-after`）；
  未传入 `$sectionTemplate` 时，`@page { size; margin }` 决定
  `Document::fromHtml()` 的页面。

`em` 和 `%` 相对于当前字号；若没有任何元素设置字号，则相对于
`Document::fromHtml()` 的 `$baseFontSizePt`（默认 12）——请把引擎的
`defaultFontSizePt` 传给它。

**不支持：** 远程样式表与 `@import`、`grid-template-areas` 与显式 grid
行定位、`position: relative` 偏移、文字流到浮动元素下方、`:hover` 等动态
伪类、表单、JavaScript。

### 扩展解析器

应用可以在解析器之前决定一个元素变成什么——签名域、脚注、条形码、
自定义标签——以及图片从哪里获取：

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

处理器按顺序对每个元素进行询问——块级元素在块之间询问，其余元素在文字
流中询问（`$context->inline`）；第一个非 null 的结果生效。
`$context->style` 是元素处的文字样式，`$context->inlines($element)` /
`$context->blocks($element)` 按解析器的方式读取其内容，处理器可以将其
包装。`imageSource` 最先收到每个 `src`；返回 null 时回退到 `data:` URL
和可读的本地文件。

处理器为文字行中的元素返回的块——例如为 `<p>` 内的 `<img>` 返回签名域——
会在该处拆分段落：其前后的文字各自成为段落，第一个保留上方间距，最后一个
保留下方间距。`$context->alignment` 是当前生效的文字对齐方式，用于放置这样
的块。

对于来自不受信任作者的 HTML，请传入 `localFiles: false`：此时
`<img src>` 与 `<link href>` 不会从磁盘读取任何内容——只读取 `data:` URL
和 `imageSource`。

页码无需处理器：在 `::before` / `::after` 中使用 `content: counter(page)`
和 `counter(pages)` 即可输出页码和总页数——
`.page-number::after { content: "第 " counter(page) " 页，共 " counter(pages) " 页" }`。

---

## 自定义字体

没有内嵌字体时，文字使用 base-14 字体排版（WinAnsi：西欧拉丁字符，其他字符显示为 `?`）：
Helvetica（或 `fallbackStandard` 的字族），按文本段的粗细与倾斜选择字形；衬线
`fontFamily` 用 Times，等宽字族用 Courier；宽度按其真实字宽计算。
正文字体是引擎的 `defaultFont`，另外可选 `boldFont`、`italicFont` 和
`boldItalicFont`：

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;

$engine = new Engine(
    defaultFont: new PdfFont(TtfFile::fromFile(__DIR__.'/fonts/DejaVuSans.ttf')),
    boldFont: new PdfFont(TtfFile::fromFile(__DIR__.'/fonts/DejaVuSans-Bold.ttf')),
    // 主字体没有字形的单词（此处为 CJK）改用回退字体排版。
    fallbackFonts: [new PdfFont(TtfFile::fromFile(__DIR__.'/fonts/DroidSansFallback.ttf'))],
);

DocumentBuilder::new()
    ->paragraph('Здравствуй, мир — Γειά σου κόσμε — 你好世界')
    ->toFile('fonts.pdf', $engine);
```

`fallbackFonts` 按单词逐一尝试：主字体无法绘制的单词，会使用第一个
包含其全部字形的回退字体排版。

`FontProvider` 为指定了字体族的文本提供字体 —— 即 run 的
`RunStyle(fontFamily: ...)` 或 CSS `font-family`（列表中 provider 拥有的
第一个字体族；没有内嵌字体时，通用名称选择 base-14 字体——`serif` 用
Times，`monospace` 用 Courier）。
它不会改变默认字体：

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

`DirectoryFontProvider` 扫描目录中的 `.ttf` / `.otf` 文件，并按 PostScript
名称（`DejaVuSans`、`DejaVuSans-Bold`）解析它们。对于字体族 `X`，引擎会根据
run 的字重与样式依次请求 `X-BoldItalic`、`X-Bold`、`X-Italic`、`X-Regular`，
最后是 `X`。`ChainedFontProvider` 按顺序询问其 provider，取第一个找到的字体 ——
它解析的是名称，而不是缺失的字形；按字形回退由 `fallbackFonts` 负责。
[dskripchenko/php-pdf-fonts-liberation](https://github.com/dskripchenko/php-pdf-fonts-liberation)
提供 `LiberationFontProvider`：Liberation Sans、Serif 和 Mono，也可通过
`Arial`、`Times New Roman` 和 `Courier New` 这些名称获取。

TrueType 轮廓字体（`.ttf`、TrueType 风格的 `.otf`）按需子集化 —— 只有您实际使用过的字形才会进入最终文件。
基于 CFF 的 OpenType 字体（带 `CFF ` 表的 `.otf`）整体内嵌、不做子集化，
以 CIDFontType0 加 `/FontFile3 /CIDFontType0C` 字体程序的形式写入（PDF/A-1 同样允许）；
输出中会包含完整的 CFF 大小（含拉丁与西里尔字母的字体约 100–200 KB）。
CFF2（可变 CFF）字体会被拒绝。
字距调整、基础连字与 ToUnicode CMap 会自动发射。

### 按请求运行的进程中的字体

`TtfFile::fromFile()` 会在整个进程生命周期内保留已解析的字体（最多
`TtfFile::$cacheLimit` 个），因此队列 worker 或 Octane 对每个字体只解析一次。
在 PHP-FPM 下，每个请求都从零开始并重新解析字体——拉丁字体约需一毫秒，
CJK 字体需要数毫秒。`exportState()` 将已解析的字体保存为纯数据（整数、
字符串、布尔值和数组），`TtfFile::fromState()` 则根据这些数据和同一个
字体文件恢复字体，无需解析：

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
            // 其他字体文件或状态已损坏：在下面重新解析
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

用 `var_export()` 写出、再用 `require` 读回的状态会驻留在 OPcache 的共享
内存中，恢复只需解析耗时的一小部分。也可以使用 JSON；若使用
`serialize()`，请用 `unserialize($s, ['allowed_classes' => false])` 读回——
状态中从不包含对象。

状态仅供应用自己的缓存使用：`fromState()` 只检查其结构，不检查其完整性，
因此切勿恢复来自外部的状态。对于 `TtfFile::STATE_FORMAT` 不同、属于其他
字体文件或已损坏的状态，它会抛出 `InvalidArgumentException` 拒绝；此时
按上例重新解析即可。这对方法属于实验性 API：改变格式的版本会提高
`STATE_FORMAT`，缓存未命中一次后重新填充，不会造成其他破坏。像上例那样
把 `STATE_FORMAT` 放进缓存键，不同版本的状态就不会混用。

嵌入的字体数据以 zlib 6 级压缩。在按请求生成文档的场景中，
`PdfFont::$compressionLevel = 1;` 压缩子集的速度快两到五倍，数据流只大
几个百分点。-1..9 以外的取值会以 `InvalidArgumentException` 拒绝。

---

## 条形码

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;

DocumentBuilder::new()
    ->barcode('ACME-1234', BarcodeFormat::Code128, heightPt: 40)
    ->barcode('https://example.com', BarcodeFormat::Qr, widthPt: 120, heightPt: 120, showText: false)
    ->toFile('barcodes.pdf');
```

QR 便捷工厂方法：

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

`vCard()`（3.0）接受 `name`、`org`、`title`、`tel`、`email`、`url` 和
`address`。其他工厂方法有 `url()`、`sms($phone, $message)` 与
`geo($latitude, $longitude, zoom: ...)`。

支持的全部 16 种格式列表请参阅 [COMPARISON.md](COMPARISON.md#条形码)。

---

## 图表

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

可用的图表类型：`BarChart`、`LineChart`、`PieChart`、`AreaChart`、
`DonutChart`、`GroupedBarChart`、`StackedBarChart`、`MultiLineChart`、
`ScatterChart`。每个图表都支持轴标题、标签旋转、网格线、
图例、颜色，以及在适用场景下的平滑曲线。

---

## 数学公式

通过 `MathExpression` 渲染 LaTeX 子集到 PDF：

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\MathExpression;

DocumentBuilder::new()
    ->block(new MathExpression('\frac{a^2 + b^2}{c^2} = 1'))
    ->block(new MathExpression('\sum_{i=1}^{n} i = \frac{n(n+1)}{2}'))
    ->block(new MathExpression('\begin{pmatrix} a & b \\ c & d \end{pmatrix}'))
    ->toFile('math.pdf');
```

支持：分式、开方、上下标、大型运算符（求和、求积、积分）、
矩阵（`pmatrix`、`bmatrix`、`vmatrix`）、多行环境
（`align`、`gather`、`cases`）。

---

## SVG

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Element\SvgElement;

DocumentBuilder::new()
    ->block(new SvgElement(file_get_contents('logo.svg'), widthPt: 200))
    ->toFile('svg.pdf');
```

支持的 SVG：path（完整路径语法，包括圆弧与贝塞尔曲线）、
基本图形（`<rect>`、`<circle>`、`<ellipse>`、`<line>`、`<polyline>`、
`<polygon>`）、渐变（线性、径向、多色标）、变换
（`translate`、`scale`、`rotate`、`skewX`、`skewY`、`matrix`）、
`<use>` / `<defs>`、CSS 类样式。

`fill-rule="evenodd"`（写在元素上、其 `style` 中、样式表中或分组上）按奇偶规则
填充 path 与多边形：与外轮廓同向绘制的孔（圆环、字母 O）保持镂空。
渐变可填充任意图形，支持两种 `gradientUnits`；`fill`、`stroke` 及其不透明度
从分组继承，嵌套的变换会依次叠加。

`<text>` 使用文档的字体排版 —— 与段落相同的字体族链、后备字体以及粗体/斜体
字形 —— 因此西里尔字母及其他非拉丁文字的显示与正文一致；没有嵌入字体时，
会退回到只含拉丁字形的 base-14 字体。`font-family`、`font-weight`、
`font-style`、`font-size`、`fill` 与 `text-anchor` 从元素本身、其 `style`
或最近的祖先元素读取；组的 `transform` 会让字形随图形一起旋转和缩放。
不支持：`<tspan>` 定位、`<textPath>`、`dx`/`dy`。

行内 SVG 在 HTML 中同样有效 —— `<svg>` 标签会直接交给 SVG
渲染器处理。

---

## 超链接与书签

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;

DocumentBuilder::new()
    ->heading(1, 'Contents')
    ->paragraph(function (ParagraphBuilder $p) {
        $p->text('Visit ')->link('https://example.com', 'our site')
          ->text(', or jump to ')->internalLink('terms', 'the terms');
    })
    ->heading(1, 'Chapter 1')          // h1–h6 构成大纲
    ->heading(2, 'Section 1.1')
    ->paragraph(fn (ParagraphBuilder $p) => $p->bookmark('terms')->text('Terms apply.'))
    ->toFile('linked.pdf');
```

大纲面板（书签）由标题构建：`heading(1, …)` 是顶级条目，
`heading(2, …)` 嵌套在其下，以此类推，最深到第 6 级。
`ParagraphBuilder::bookmark($name)` 标记一个命名目的地；
`internalLink($name, …)` 跳转到它，`link($url, …)` 打开 URI。在
`Pdf\Page` 上还有 `addExternalLink()`、`addInternalLink()`、
`addNamedActionLink()`、`addJavaScriptLink()` 和 `addLaunchLink()`，
用于绝对定位的链接区域。

标题自动锚点：`<h1 id="intro">` 会成为一个命名目的地，因此
`<a href="#intro">jump</a>` 在 HTML 输入中也能工作。

---

## 表单（AcroForm）

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

`FormField` 是流中的一个块：它接受 `name`、`type`（默认为文本）、
`defaultValue`、`widthPt` / `heightPt`（默认 200 × 20）、`tooltip`、
`required`、`readOnly`、`options`，以及下文介绍的脚本与按钮参数。
若要把字段放在绝对坐标处，请将其添加到 `Pdf\Page`：

```php
use Dskripchenko\PhpPdf\Pdf\Document;

$doc = Document::new();
$page = $doc->addPage();
$page->addFormField('text', 'full_name', x: 100, y: 700, width: 200, height: 24);
$page->addFormField('checkbox', 'agree', x: 100, y: 660, width: 14, height: 14, defaultValue: 'on');
$doc->toFile('form-absolute.pdf');
```

字段类型：`text`、`text-multiline`、`password`、`checkbox`、
`radio-group`、`combo`、`list`、`push`、`submit`、`reset`、`signature`。

字段级 JavaScript 钩子：`keystrokeScript`、`validateScript`、
`calculateScript`、`formatScript`、`clickScript`。文档级事件：
`WC`（WillClose）、`WS`（WillSave）、`DS`（DidSave）、`WP`
（WillPrint）、`DP`（DidPrint）。

---

## 填写已有表单（AcroForm）

上文介绍的是从零创建表单。若要打开他人生成的 PDF（上传的模板、政府表单），并按名称填写其中已定义字段的值，请改用 `ExistingFormFiller`：

```php
use Dskripchenko\PhpPdf\Pdf\Forms\ExistingFormFiller;

ExistingFormFiller::fromFile('template.pdf')
    ->useFont('fonts/LiberationSans-Regular.ttf') // 用于非拉丁文字的值
    ->setValues([
        'full_name' => 'Jane Roe',
        'agree' => true,
        'employer.name' => 'Acme Corp', // 通过 /Parent 继承解析
        'country' => 'Germany',         // 组合框选项（导出值或显示文本）
    ])
    ->stampImage(0, 'signature.png', x: 100, y: 600, width: 120, height: 40)
    ->flatten()
    ->toFile('filled.pdf');
```

源文档永远不会被修改——每次调用都作用于*整个*对象图的深拷贝，因此未触及的内容（书签、元数据、其他注释）会原样保留到输出中。`fields()` 返回每个字段以点分隔的完整名称、类型、当前值、可接受的选项、`/MaxLen` 以及 `/TU` 提示，便于在填写前了解模板结构：

```php
foreach (ExistingFormFiller::fromFile('template.pdf')->fields() as $name => $field) {
    echo "{$name}: {$field->type} = ".json_encode($field->value)."\n";
}
```

各字段类型接受的值：

| 类型 | 可接受的值 |
|---|---|
| `text`、`text-multiline` | 字符串；会校验 `/MaxLen` |
| `checkbox` | `true`/`false`、`on`/`yes`/`true`/`1`、`off`/`no`/`false`/`0`，或其导出值 |
| `radio` | `options` 之一（不区分大小写），或 `Off` |
| `combo`、`list` | 选项的导出值或显示文本；多选列表框可传入数组 |
| `signature`、`push` | 不可设置——抛出 `LogicException` |

未知选项会抛出 `InvalidArgumentException`，而不会被静默忽略。

填写后的文本字段和选择字段会获得新生成的外观流——使用 `/DA` 中的字体、字号（含自动字号）、颜色和对齐方式，`/MK` 中的背景和边框，以及梳状单元格和多行换行——因此值在任何阅读器中都能显示，而不仅限于支持 `/NeedAppearances` 的阅读器。当表单自带字体无法显示某个值（西里尔文、希腊文、中日韩文字等）时，会改用嵌入的 TrueType 字体绘制：可通过 `useFont()` 传入字体（或通过 `useFontProvider()` 传入 `FontProvider`），也可以安装 `dskripchenko/php-pdf-fonts-liberation`，届时会自动使用。若没有可用字体，此类值会抛出带有字段名的 `RuntimeException`。

`flatten(?array $fieldNames = null)` 以 Acrobat 的方式把字段变为静态页面内容：将每个控件自身的外观（勾选标记、单选圆点、背景、边框）绘制到页面上，并移除该控件。传入名称列表可只扁平化部分字段、保留其余字段可交互；省略参数则扁平化所有控件并删除 `/AcroForm`。

`stampImage()`/`stampImageBytes()` 可在任意字段之外放置 PNG（含透明通道）或 JPEG——适用于本身不是表单字段的签名或照片。`x`/`y` 为图像左上角，以点为单位，从*阅读器所显示页面*（CropBox，已应用 `/Rotate`）的左上角起算；`pageSize($index)` 返回该尺寸。

输出是对文档的完整重写，且不加密。已有的数字签名将不再有效；一旦有任何改动，XFA 表单定义会被移除（否则支持 XFA 的阅读器会显示 XFA 层而不是已填写的值）。

`toBytes(incremental: true)` 则只在原文件之后追加改动：签名保持有效（参见[填写已签名的表单](SIGNING.md#填写已签名的表单)）。

---

## 标注

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

标注类型：`Text`、`Highlight`、`Underline`、`StrikeOut`、
`FreeText`、`Square`、`Circle`、`Line`、`Stamp`、`Ink`、`Polygon`、
`PolyLine`。

---

## 加密

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

算法：

| 算法           | V/R  | 加密方式      | PDF 版本    |
|---------------|------|--------------|-------------|
| `Rc4_128`     | V2 R3 | RC4-128      | 1.4         |
| `Aes_128`     | V4 R4 | AES-128-CBC (AESV2) | 1.6 |
| `Aes_256`     | V5 R5 | AES-256-CBC (AESV3) | 1.7 |
| `Aes_256_R6`  | V5 R6 | AES-256 + Algorithm 2.B iterative hash | 2.0 |

权限位：`PERM_PRINT`、`PERM_MODIFY`、`PERM_COPY`、
`PERM_ANNOTATE`、`PERM_FILL_FORMS`、`PERM_ACCESSIBILITY`、
`PERM_ASSEMBLE`、`PERM_PRINT_HIGH`。

AES 需要 `ext-openssl`。

---

## 数字签名

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;

$doc = Document::new();
$page = $doc->addPage();

// 签名字段，以填入证书信息的签章显示。
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

PDF 先以占位的 `/ByteRange` 与 `/Contents` 发射，然后就地写入分离的 CMS 签名（默认 PAdES）。版式流中的签名字段、签章设计、占位符、字体以及多个签署人，详见[签名指南](SIGNING.md)。对于永远不离开设备或服务的密钥，有现成的签名器：
[php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11)（HSM、
智能卡、USB 令牌）、
[php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms)
（AWS KMS、Google Cloud KMS、Azure Key Vault）以及
[php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro)
（服务器上的 CryptoPro CSP）—— 参见 [SIGNING.md](SIGNING.md#密钥和证书)。

---

## PDF/A 与 Tagged PDF

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

输出意图（output intent）需要 sRGB ICC 配置文件；本包在
`resources/icc/sRGB2014.icc` 中自带一份。PDF/A-1 接受一致性级别 A 或 B；
PDF/A-2 与 PDF/A-3 还接受 U。PDF/A 还要求所有字体都被内嵌 —— base-14
字体无法通过验证，因此请用 TTF 排版文字（`PdfFont`，或对排版文档使用带
`defaultFont` 的 `Engine`）。

`enablePdfA()` 与 `encrypt()` 和 `enablePdfX()` 互斥 ——
冲突时库会抛出异常。

不启用 PDF/A 而仅启用 Tagged PDF：

```php
$doc->enableTagged();
$doc->setLang('en-US');
```

Tagged 发射会生成包含 `H1`–`H6`、`P`、`Table`、`TR`、`TD`、
`L`、`LI`、`Link` 的 `/StructTreeRoot`，外加可选的自定义
`/RoleMap`（`setStructRoleMap(['MyHeading' => 'H1', ...])`）。

---

## PDF/X 印刷一致性

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

变体：`VARIANT_X1A`、`VARIANT_X3`、`VARIANT_X4`。自带的 sRGB
配置文件适用于 X-3 与 X-4；针对特定印刷条件，请传入其 CMYK 配置文件
（X-1a 必须使用 CMYK 配置文件 —— 本包未自带），并配合
`iccProfileName`、`outputConditionIdentifier`（例如 `FOGRA39`）与
`outputCondition`。调用方负责内容级的合规性（例如 X-1a 需要 CMYK 转换，X-1a / X-3 不允许透明度）。

---

## 流式输出

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

流式输出将字节装配直接刷新到流中，而不是在内存中缓存一份完整
的文档字符串。

---

## 可选内容组（图层）

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

图层会出现在 Acrobat 的图层面板中；阅读器可以自由切换显示与隐藏。

---

## 底层发射

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

`Pdf\Page` 上的其他绘图方法：`fillRoundedRect()`、
`strokeRoundedRect()`、`strokeDashedLine()`、`fillPolygon()`、
`strokePolyline()`、`drawImage()`。

`Pdf\Document` 没有布局引擎 —— 坐标以 PDF 点（1/72 英寸）为单位，
原点在左下角。适用于票据风格或叠加风格的 PDF，定位完全由调用方
计算。

`Pdf\Document` 上的配置：

- `metadata(title: ..., author: ..., subject: ..., keywords: ...,
  creator: ..., producer: ..., creationDate: ...)` —— `/Info`
  字典。
- `useXrefStream(true)` —— 以流对象形式发射交叉引用（PDF 1.5+），
  元数据体积减小约 50%。
- `useObjectStreams(true)` —— 将非流字典打包进 Object Streams，
  对元数据密集的文档可减小约 15–30% 的文件体积。
- `setViewerPreferences(['hideToolbar' => true, ...])`。
- `setPageLabels([['startPage' => 0, 'style' => 'lower-roman'], ...])`。
- `setOpenAction('fit-page', pageIndex: 3)`。
- `attachFile($name, $bytes, mimeType: 'application/json')`。

完整接口请参阅 `src/Pdf/Document.php`。
