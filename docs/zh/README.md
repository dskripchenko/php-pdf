# dskripchenko/php-pdf

> 纯 PHP 实现、采用 **MIT 许可** 的 PDF 生成器。可作为
> `mpdf/mpdf`（GPL-2.0）的直接替代品 —— 用于 OEM、本地部署安装包
> 或专有软件捆绑时不存在任何许可摩擦。

[![Packagist](https://img.shields.io/packagist/v/dskripchenko/php-pdf.svg)](https://packagist.org/packages/dskripchenko/php-pdf)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%5E8.2-blue.svg)](composer.json)
[![Tests](https://img.shields.io/badge/tests-1977%20passing-success.svg)](#testing)

**语言：** [English](../en/README.md) · [Русский](../ru/README.md) · [中文](README.md) · [Deutsch](../de/README.md)

---

## 目录

- [为什么选择这个库](#为什么选择这个库)
- [安装](#安装)
- [快速开始](#快速开始)
- [核心特性](#核心特性)
- [文档](#文档)
- [性能](#性能)
- [运行要求](#运行要求)
- [测试](#测试)
- [许可证](#许可证)

---

## 为什么选择这个库

**许可证。** MIT 是 PHP 生态中最宽松的许可证 —— 您可以在任何场景下使用代码，
包括闭源产品。与主流 PHP PDF 技术栈对比：

| 库                       | 许可证          | OEM／专有软件捆绑 |
|--------------------------|----------------|--------------------------|
| **dskripchenko/php-pdf** | **MIT**        | ✅ 无任何摩擦 |
| mpdf/mpdf                | GPL-2.0-only   | ❌ 需要随附 GPL 或购买商业许可 |
| tecnickcom/tcpdf         | LGPL-2.1+      | ⚠️ 静态链接存在一些细节问题 |
| dompdf/dompdf            | LGPL-2.1       | ⚠️ 同 tcpdf |
| setasign/fpdf            | 可重新授权       | ✅ 但扩展组件是专有的 |

**工程实现。**

- **现代 PHP 8.2+** —— readonly 类、枚举、命名参数、严格类型。
  整洁、类型安全的 API。
- **双层架构** —— `Pdf\Document` 负责底层发射，`Build\*` 流式构建器
  负责高层文档构建，`Document::fromHtml()` 用于 HTML/CSS 输入。
- **强大的排版** —— Knuth–Plass 断行、带字距调整的 TTF 子集化、
  GSUB 连字、ToUnicode CMap、可变字体实例、Bidi（UAX#9）、
  阿拉伯语字形整形、基础印度语系字形整形、竖排书写。
- **最广泛的条形码覆盖** —— 12 种线性条形码 + 4 种二维码格式，
  包括罕见的 Pharmacode、MSI Plessey、ITF-14、EAN-2/5 附加码。
- **生产级加密** —— RC4-128、AES-128、AES-256（V5 R5 和 R6，
  遵循 ISO 32000-2 / PDF 2.0）。
- **PKCS#7 分离签名**，自动对占位 /ByteRange 进行原位修补。
- **PDF/A-1a / 1b / 2u 与 PDF/X-1a / 3 / 4** 一致性支持，
  内嵌 sRGB ICC 配置文件与 XMP 元数据。
- **Tagged PDF / PDF/UA 就绪** 的结构树，支持 H1–H6、Table/TR/TD、
  L/LI、自定义 RoleMap、ParentTree number tree。
- **流式输出** 到流资源，适合大文档。
- **XRef 流**（PDF 1.5+）与 Object Streams，输出更紧凑。

---

## 安装

```bash
composer require dskripchenko/php-pdf
```

要求 PHP 8.2 或更高版本。必需扩展：`mbstring`、`zlib`、`dom`。
如需 AES 加密或 PKCS#7 签名，请额外启用 `openssl`。

---

## 快速开始

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

### 程序化构建器

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

### 底层发射

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

## 核心特性

### 输入
- 通过 `Document::fromHtml()` 解析 HTML5 子集。
- 块级标签 `<p>`、`<h1>`–`<h6>`、`<ul>`/`<ol>`/`<li>`、表格
  （含 `<thead>`/`<tbody>`/`<tfoot>` 和 `<caption>`）、`<blockquote>`、
  `<hr>`、`<pre>`、`<dl>`/`<dt>`/`<dd>`。
- 行内标签 `<b>`/`<strong>`、`<i>`/`<em>`、`<u>`、`<s>`/`<del>`、
  `<sup>`/`<sub>`、`<br>`、`<a>`、`<span>`，以及 `<code>`、`<kbd>`、
  `<samp>`、`<tt>`、`<var>`、`<mark>`、`<small>`、`<big>`、`<ins>`、
  `<cite>`、`<dfn>`、`<q>`、`<abbr>`。
- HTML5 语义块（`<header>`、`<footer>`、`<article>`、`<nav>`、
  `<section>` 等）以及历史遗留的 `<center>` / `<font>`。
- 行内 CSS：color、background、字体属性、text-align、
  text-decoration、text-transform、margin/padding 简写、边框、
  line-height、text-indent。

### 布局与排版
- Knuth–Plass box–glue–penalty 断行算法，带自适应惩罚。
- 多栏布局（`ColumnSet`），支持 column-first 流向。
- 表格支持 rowspan、colspan、边框合并、双线边框、圆角边框、
  单元格内边距。
- 页眉、页脚、水印（文字和图片）、分节符。
- 脚注，定位在页面底部。

### 字体
- 14 个 Adobe base-14 字体（WinAnsi）。
- TTF 嵌入与按需子集化（CFF 和 TrueType）。
- 字距调整、基础 GSUB 连字、ToUnicode CMap。
- 可变字体实例（fvar、gvar、MVAR、HVAR、avar）。
- Bidi（UAX#9）、阿拉伯语字形整形、基础印度语系字形整形。

### 条形码
- 线性：Code 128（A/B/C 自动、GS1-128）、Code 39、Code 93、Code 11、
  Codabar、ITF/ITF-14、MSI Plessey、Pharmacode、EAN-13/EAN-8
  （带 2/5 位附加码）、UPC-A、UPC-E。
- 二维：QR V1–V10（Numeric、Alphanumeric、Byte、Kanji、ECI、
  Structured Append、FNC1）、Data Matrix ECC 200（全尺寸含
  144×144、矩形、6 种模式）、PDF417（Byte/Text/Numeric、Macro、GS1、ECI）、
  Aztec Compact 1–4L + Full 5–32L（Structured Append、FLG/ECI）。
- QR 便捷工厂方法：vCard 3.0、WiFi Joinware、mailto。

### 图表
- BarChart、LineChart、PieChart、AreaChart、DonutChart、GroupedBarChart、
  StackedBarChart、MultiLineChart、ScatterChart。

### 交互
- AcroForm 控件：文本（单行 / 多行 / 密码）、复选框、单选按钮、
  下拉框、列表、push / submit / reset 按钮、签名。
- 字段级 JavaScript 动作（keystroke、validate、calculate、format）。
- 标注：Text、Highlight、Underline、StrikeOut、FreeText、
  Square、Circle、Line、Stamp、Ink、Polygon、PolyLine。

### 安全与一致性
- 加密：RC4-128、AES-128、AES-256（V5 R5 + R6 / PDF 2.0）。
- PKCS#7 分离签名，支持时间戳、原因、位置、签署人名称。
- PDF/A-1a、PDF/A-1b、PDF/A-2u，内嵌 sRGB ICC。
- PDF/X-1a、PDF/X-3、PDF/X-4，配 /OutputIntent /S /GTS_PDFX。
- Tagged PDF / PDF/UA 就绪的结构树。

完整的使用演练请参阅 [使用指南](USAGE.md)。

---

## 文档

- 📖 [使用指南](USAGE.md) —— 段落、表格、图表、
  条形码、表单、加密、签名、PDF/A。
- ⚖️ [与 mpdf / tcpdf / dompdf / FPDF 的对比](COMPARISON.md) ——
  特性矩阵，以及如何选型。
- 📊 [基准测试](BENCHMARKS.md) —— 可复现的墙钟时间、内存
  与输出大小测量。

---

## 性能

5 次独立子进程运行的中位数，环境为 macOS 25 / PHP 8.4。完整
方法论与复现脚本见 [基准测试](BENCHMARKS.md)。

| 场景                       | dskripchenko/php-pdf | mpdf      | tcpdf     | dompdf     | FPDF      |
|---------------------------|---------------------:|----------:|----------:|-----------:|----------:|
| HTML → PDF 文章（约 5 页） | **10.8 ms**     | 61.1 ms   | 36.1 ms   | 46.9 ms    | _n/a_     |
| 100 页发票（每页 50 行）    | **518 ms**    | 2367 ms   | 1349 ms   | 8891 ms    | 26 ms     |
| 图片网格（20 页 × 4）       | **6.4 ms**          | 35.9 ms   | 15.3 ms   | 30.4 ms    | 1.0 ms    |
| Hello world（单页）         | 4.6 ms              | 29.8 ms   | 14.8 ms   | 12.0 ms    | 0.9 ms    |

FPDF 在最简单的场景中胜出（没有 HTML、没有换行、没有表格流），
但它无法生成 HTML→PDF，也不支持 UTF-8、图表、条形码、
表单、加密、签名。

---

## 运行要求

- PHP **8.2** 或更高版本。
- 必需：`ext-mbstring`、`ext-zlib`、`ext-dom`。
- 可选：`ext-openssl`（AES 加密与 PKCS#7 签名）。
- 无外部二进制依赖 —— 纯 PHP 实现。

---

## 测试

```bash
composer install
vendor/bin/phpunit
```

1977 个测试，约 119k 个断言，在 PHP 8.2 / 8.3 / 8.4 上全部通过。

---

## 许可证

MIT —— 见 [LICENSE](LICENSE)。

版权所有 © 2026 Denis Skripchenko。
