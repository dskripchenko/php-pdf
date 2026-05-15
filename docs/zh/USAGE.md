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
  - [页面设置与分节符](#页面设置与分节符)
- [HTML / CSS 输入](#html--css-输入)
- [自定义字体](#自定义字体)
- [条形码](#条形码)
- [图表](#图表)
- [数学公式](#数学公式)
- [SVG](#svg)
- [超链接与书签](#超链接与书签)
- [表单（AcroForm）](#表单acroform)
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

`ListFormat` 覆盖 Decimal、UpperRoman、LowerRoman、UpperAlpha、
LowerAlpha。

### 图片

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;

DocumentBuilder::new()
    ->image('/path/to/logo.png', widthPt: 120)
    ->paragraph('Above is our logo.')
    ->toFile('with-image.pdf');
```

支持的格式：JPEG、PNG（8 位真彩色、8 位调色板、通过 SMask 支持
alpha 通道）。同一张图片在文档中被使用 N 次时，仅以单个 XObject
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
            $p->text('Page ')->pageNumber()->text(' of ')->pageCount());
    })
    ->watermark('DRAFT')
    ->paragraph('Document body.')
    ->toFile('with-chrome.pdf');
```

`->watermarkImage($image)` 接受一个 `PdfImage`。两种水印都可以通过
`watermarkTextOpacity()` / `watermarkImageOpacity()` 控制不透明度。

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
        margins: new PageMargins(top: 36, right: 36, bottom: 36, left: 36),
    ))
    ->heading(1, 'Wide content')
    ->toFile('landscape.pdf');
```

纸张尺寸：A0–A6、B0–B6、Letter、Legal、Executive、Tabloid，以及
通过 `defaultCustomDimensionsPt` 自定义。

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
  `<caption>`、`<blockquote>`、`<pre>`、`<dl>` / `<dt>` / `<dd>`。
- 行内：`<b>` / `<strong>`、`<i>` / `<em>`、`<u>`、`<s>` / `<del>`、
  `<sup>` / `<sub>`、`<br>`、`<img>`、`<a>`、`<span>`、`<code>`、
  `<kbd>`、`<samp>`、`<tt>`、`<var>`、`<mark>`、`<small>`、`<big>`、
  `<ins>`、`<cite>`、`<dfn>`、`<q>`、`<abbr>`。
- 历史遗留：`<center>`、`<font color face size>`。

**支持的行内 CSS（`style` 属性）：**

- `color`、`background-color` —— hex `#rrggbb` / `#rgb`、`rgb()`、
  21 个具名颜色。
- `font-size`（pt、px、em、mm、cm、in）、`font-family`（取逗号分隔
  列表中的第一个值）、`font-weight`（`bold`、`bolder`、700+）、
  `font-style: italic`。
- `text-decoration`（`underline`、`line-through`）、`text-transform`
  （`uppercase`、`lowercase`、`capitalize`）、`text-align`、
  `text-indent`。
- `margin`、`padding` 简写（1/2/3/4 个值）、`border` 简写
  （`solid`、`double`、`dashed`、`dotted`、`none` + 宽度 + 颜色）。
- `line-height`（倍数或百分比）。

**不支持：** 外部 CSS（`<link rel="stylesheet">`）、`<style>`
块、复杂选择器、`@media`、JavaScript、浮动、
`position: absolute / fixed`、Flexbox。

如需 `<style>` / class 支持，请通过外部内联工具
（例如 `pelago/emogrifier`）预处理 HTML。

---

## 自定义字体

```php
use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Font\DirectoryFontProvider;
use Dskripchenko\PhpPdf\Layout\Engine;

$fonts = new DirectoryFontProvider(__DIR__ . '/fonts');

$bytes = DocumentBuilder::new()
    ->paragraph('Здравствуй, мир — 你好世界 — مرحبا')
    ->toBytes(new Engine(fontProvider: $fonts));
```

`DirectoryFontProvider` 扫描指定目录中的 `.ttf` / `.otf` 文件，
按字体族名暴露它们。`ChainedFontProvider` 允许您组合多个 provider，
让引擎可以回退到系统字体以处理缺失的字形。

内嵌字体按需子集化 —— 只有您实际使用过的字形才会进入最终文件。
字距调整、基础连字与 ToUnicode CMap 会自动发射。

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

$vcard = QrEncoder::vCard(
    fullName: 'Jane Doe',
    org: 'Acme Corp',
    email: 'jane@example.com',
    phone: '+1-555-0100',
);

$wifi = QrEncoder::wifi(ssid: 'guest', password: 'welcome', hidden: false);

$mailto = QrEncoder::mailto('hello@example.com', subject: 'Hi');
```

支持的全部 16 种格式列表请参阅 [COMPARISON.md](COMPARISON.md#条形码-1)。

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

行内 SVG 在 HTML 中同样有效 —— `<svg>` 标签会直接交给 SVG
渲染器处理。

---

## 超链接与书签

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

可发射的超链接类型：`URI`、`Dest`（命名目的地）、
JavaScript、Launch、命名页面目的地。大纲面板（书签）支持多级 ——
传 `level: 2` 表示子小节，传 `level: 3` 表示更深一层，以此类推。

标题自动锚点：`<h1 id="intro">` 会成为一个命名目的地，因此
`<a href="#intro">jump</a>` 在 HTML 输入中也能工作。

---

## 表单（AcroForm）

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

字段类型：`text`、`text-multiline`、`password`、`checkbox`、
`radio-group`、`combo`、`list`、`push`、`submit`、`reset`、`signature`。

字段级 JavaScript 钩子：`keystrokeScript`、`validateScript`、
`calculateScript`、`formatScript`、`clickScript`。文档级事件：
`WC`（WillClose）、`WS`（WillSave）、`DS`（DidSave）、`WP`
（WillPrint）、`DP`（DidPrint）。

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
    x1: 72, y1: 720, x2: 200, y2: 735,
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

PDF 先以占位的 `/ByteRange` 与 `/Contents` 发射，然后在 PKCS#7
分离签名完成后就地修补。

---

## PDF/A 与 Tagged PDF

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
    variant: PdfXConfig::VARIANT_X4,
    outputConditionIdentifier: 'FOGRA39',
    outputCondition: 'Coated FOGRA39',
    registryName: 'http://www.color.org',
    title: 'Print master',
    trapped: 'False',
));
$doc->toFile('print.pdf');
```

变体：`VARIANT_X1A`、`VARIANT_X3`、`VARIANT_X4`。调用方负责
内容级的合规性（例如 X-1a 需要 CMYK 转换，X-1a / X-3 不允许透明度）。

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

`Pdf\Document` 没有布局引擎 —— 坐标以 PDF 点（1/72 英寸）为单位，
原点在左下角。适用于票据风格或叠加风格的 PDF，定位完全由调用方
计算。

`Pdf\Document` 上的配置：

- `setMetadata(...)` —— Title、Author、Subject、Keywords、Creator、
  Producer、CreationDate。
- `useXrefStream(true)` —— 以流对象形式发射交叉引用（PDF 1.5+），
  元数据体积减小约 50%。
- `useObjectStreams(true)` —— 将非流字典打包进 Object Streams，
  对元数据密集的文档可减小约 15–30% 的文件体积。
- `setViewerPreferences(['hideToolbar' => true, ...])`。
- `setPageLabels([['startPage' => 0, 'style' => 'lower-roman'], ...])`。
- `setOpenAction('fit-page', pageIndex: 3)`。
- `attachFile($name, $bytes, mimeType: 'application/json')`。

完整接口请参阅 `src/Pdf/Document.php`。
