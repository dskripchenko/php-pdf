# dskripchenko/php-pdf

> **一个 MIT 包同时替代 mpdf 与 FPDI —— 没有 GPL 摩擦，而且[更快](../en/BENCHMARKS.md)。**
> 纯 PHP 实现的 PDF 工具包：**生成、读取与合并** PDF。HTML→PDF 场景下的
> GPL-free mpdf 替代品，导入/盖印场景下的免费 FPDI 替代品 —— 包括
> xref-stream 与加密源文件。迁移是机械化的：
> [从 mpdf](../en/MIGRATION-FROM-MPDF.md) · [从 FPDI](../en/MIGRATION-FROM-FPDI.md)。

[![Tests](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-pdf/tests.yml?branch=main&label=tests&logo=github)](https://github.com/dskripchenko/php-pdf/actions/workflows/tests.yml)
[![Conformance](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-pdf/conformance.yml?branch=main&label=PDF%2FA%20%C2%B7%20PDF%2FX%20%C2%B7%20visual&logo=github)](../en/CONFORMANCE.md)
[![Latest Version](https://img.shields.io/packagist/v/dskripchenko/php-pdf?logo=packagist&logoColor=white)](https://packagist.org/packages/dskripchenko/php-pdf)
[![Total Downloads](https://img.shields.io/packagist/dt/dskripchenko/php-pdf)](https://packagist.org/packages/dskripchenko/php-pdf)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](../../LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://www.php.net)

**语言：** [English](../en/README.md) · [Русский](../ru/README.md) · [中文](README.md) · [Deutsch](../de/README.md)

---

## 目录

- [为什么选择这个库](#为什么选择这个库)
- [安装](#安装)
- [快速开始](#快速开始)
- [核心特性](#核心特性)
- [文档](#文档)
- [php-pdf 系列](#php-pdf-系列)
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
- **PAdES 签名**（B-B；配合 RFC 3161 时间戳为 B-T）——支持 RSA、ECDSA 与
  GOST R 34.10-2012，密钥可为 PEM / PKCS#12 / 自定义 `Signer`（令牌、HSM），或在服务器之外**两阶段签名**（CryptoPro 插件、HSM、签名服务），并可显示填入证书信息的**签章**；可通过增量更新**签署已有 PDF**（多个签署人、认证、锁定字段），并支持**长期验证**（B-LT 的 DSS、B-LTA 的文档时间戳）以及可防御已知 PDF 签名攻击的**签名验证**；还支持对任意字节生成并验证**分离式 CAdES 签名**（`.p7s`）。
  提供现成的签名器：PKCS#11 令牌与 HSM
  （[php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11)）、云端
  KMS —— AWS、Google、Azure
  （[php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms)）——
  以及服务器上的 CryptoPro CSP
  （[php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro)）。
- **PDF/A-1a、1b、2b、2u 与 3b**（在 CI 中经 veraPDF 验证）以及
  **PDF/X-1a / 3 / 4** 一致性支持，内嵌 ICC 配置文件与 XMP
  元数据。
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
如需 AES 加密或数字签名，请额外启用 `openssl`。

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
- 来自 `<style>` 块和 `style` 属性的 CSS，支持层叠：
  类型、class、id 与属性选择器，`:first-child`、`:last-child`、
  `:nth-child()`、`:not()`，后代 / `>` / `+` / `~` 组合器，
  优先级（specificity）、`!important` —— 无需 CSS 内联工具。
- 应用 `@media print`，跳过 `@media screen`；支持 `@page` 尺寸与
  页边距。
- 与浏览器一致的继承：在 `body`、`div` 或 `td` 上设置的 color、字体、
  text-align、line-height 等属性会作用到其内部文本。
- `div` 容器支持 margin、padding、background 与 border；
  `display: flex`（多行、`flex-wrap`、`flex-direction: column`、`gap`）、
  `display: grid`（`grid-template-columns` 支持长度、`%`、`fr`、
  `repeat()`、`minmax()`；`grid-column: span n`；`gap`）、`display: none`。
- `float: left / right` 与 `clear`；`position: absolute` 和 `fixed`
  （fixed 元素在每一页重复）；`break-inside: avoid` 使块保持在同一页。
- `::before` / `::after` 与 `content`（字符串、`attr()`、引号）；
  指向本地文件和 `data:` URL 的 `<link rel="stylesheet">`；
  `font-family` 列表（取第一个可用的字体族）。
- 表格样式：`width`、`table-layout: auto`、`border-collapse` /
  `border-spacing`、单元格 `padding` 与 `vertical-align`、`<col>` 宽度；
  `<thead>` 行在每一页重复。
- `page-break-*` / `break-*`、`white-space: pre` / `pre-wrap` /
  `pre-line`；实线、虚线、点线与双线边框。
- 近似实现：浮动元素旁的文字不会流到其下方；`position: relative`
  仅作为其 absolute 子元素的定位基准（自身偏移不生效）；不获取远程
  样式表。不支持：`grid-template-areas` 与显式行定位、`@import`、
  `:hover` 等动态伪类。

### 布局与排版
- Knuth–Plass box–glue–penalty 断行算法，带自适应惩罚。
- 多栏布局（`ColumnSet`），支持 column-first 流向。
- 表格支持 rowspan、colspan、边框合并、双线、虚线与点线边框、
  圆角边框、单元格内边距。
- 页眉、页脚、水印（文字和图片）、分节符。
- 脚注，定位在页面底部。

### 字体
- 14 个 Adobe base-14 字体（WinAnsi）。
- TrueType 轮廓字体（`.ttf`、TrueType 风格的 `.otf`），支持
  按需子集化；基于 CFF 的 OpenType（`.otf`）整体内嵌，不做子集化；
  不支持 CFF2（可变 CFF）。
- 字距调整、基础 GSUB 连字、ToUnicode CMap。
- 可变字体实例（fvar、gvar、MVAR、HVAR、avar）。
- Bidi（UAX#9）、阿拉伯语字形整形、基础印度语系字形整形。
- 现成的 Liberation 字体包（与 Arial / Times New Roman / Courier New
  度量兼容）：[dskripchenko/php-pdf-fonts-liberation](https://github.com/dskripchenko/php-pdf-fonts-liberation)。

### 条形码
- 线性：Code 128（A/B/C 自动、GS1-128）、Code 39、Code 93、Code 11、
  Codabar、ITF/ITF-14、MSI Plessey、Pharmacode、EAN-13/EAN-8
  （带 2/5 位附加码）、UPC-A、UPC-E。
- 二维：QR V1–V10（Numeric、Alphanumeric、Byte、Kanji、ECI、
  Structured Append、FNC1）、Data Matrix ECC 200（全尺寸含
  144×144、矩形、6 种模式）、PDF417（Byte/Text/Numeric、Macro、GS1、ECI）、
  Aztec Compact 1–4L + Full 5–32L（Structured Append、FLG/ECI）。
- QR 便捷工厂方法：vCard 3.0、WiFi（Joinware）、URL、SMS、
  电子邮件（`mailto:`）、地理位置。

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
- PAdES（`ETSI.CAdES.detached`）或 PKCS#7 分离签名，支持原因、位置、签署人名称、证书链与 RFC 3161 时间戳；签章（文本、图片、QR）
  支持证书占位符，可有多个签名字段，支持不可见签名。
- 现成的签名器：PKCS#11 令牌与 HSM
  （[php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11)）、AWS
  KMS、Google Cloud KMS 与 Azure Key Vault
  （[php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms)）、
  服务器上的 CryptoPro CSP
  （[php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro)）。
- PDF/A-1a、PDF/A-1b、PDF/A-2b、PDF/A-2u、PDF/A-3b，内嵌 sRGB ICC，
  在 CI 中经 veraPDF 验证。
- PDF/X-1a、PDF/X-3、PDF/X-4，配 /OutputIntent /S /GTS_PDFX。
- Tagged PDF / PDF/UA 就绪的结构树。

### 读取与合并
- 读取现有 PDF（`ReaderDocument`）—— 经典与流式 xref、对象流、损坏 xref 恢复、
  解密（RC4 / AES-128 / AES-256）。
- 提取纯文本（`ReaderDocument::text()`）—— ToUnicode 映射、带 `/Differences` 的简单字体编码、复合字体、表单 XObject；按字形位置划分行与词间空格。
- 合并（`PdfMerger`）—— 追加并重排来自多个文件的全部或选定页面；页面注释与书签
  会被带入，内部链接和命名目标随之重新映射。
- 盖印叠加（水印、信头）与 FPDI 风格的页面导入，通过
  `PageImporter::intoDocument()` 导入到新生成的文档。
- 参见[读取与合并指南](MERGE.md)。

完整的使用演练请参阅 [使用指南](USAGE.md)。

---

## 文档

- 📖 [使用指南](USAGE.md) —— 段落、表格、图表、
  条形码、表单、加密、签名、PDF/A。
- ✍️ [数字签名](SIGNING.md) —— PAdES 与 GOST 签名、签章、在服务器之外签名
  （CryptoPro、HSM）、签署已有 PDF 与多个签署人、认证、长期验证、签名验证、
  分离式 `.p7s` 签名。
- 🔗 [读取与合并 PDF](MERGE.md) —— 读取现有文件、追加/重排页面、
  盖印叠加、处理加密输入。
- ⚖️ [与 mpdf / tcpdf / dompdf / FPDF 的对比](COMPARISON.md) ——
  特性矩阵，以及如何选型。
- 📊 [基准测试](BENCHMARKS.md) —— 可复现的墙钟时间、内存
  与输出大小测量。
- ✅ [一致性](../en/CONFORMANCE.md) —— PDF/A 经 veraPDF 验证，
  PDF/X 经 Ghostscript 检查，渲染结果与基准图（golden）比对，
  签名经 OpenSSL 与 pyHanko 验证 —— 每次推送都会执行。
- 🔀 [从 mpdf 迁移](MIGRATION-FROM-MPDF.md) —— 兼容
  门面（`WriteHTML`/`Output` 照常可用）+ 完整映射表。
- 🔀 [从 FPDI 迁移](MIGRATION-FROM-FPDI.md) ——
  `setSourceFile`/`importPage`/`useTemplate` 门面；无需商业解析器即可读取
  xref-stream 与加密源。

### 示例

可运行的脚本位于 [`examples/`](../../examples/)，输出已提交到
[`samples/`](../../samples/) —— 每一个都在 CI 中进行渲染检查：

| 运行 | 输出 | 展示内容 |
|---|---|---|
| `php examples/01-html-to-pdf.php` | [PDF](../../samples/01-html-to-pdf.pdf) | HTML/CSS 子集 → PDF |
| `php examples/02-builder.php` | [PDF](../../samples/02-builder.pdf) | 流式构建器、表格 |
| `php examples/03-barcodes.php` | [PDF](../../samples/03-barcodes.pdf) | QR、Code 128、EAN-13、DataMatrix |
| `php examples/04-charts.php` | [PDF](../../samples/04-charts.pdf) | 矢量饼图/柱状图/折线图 |
| `php examples/05-forms.php` | [PDF](../../samples/05-forms.pdf) | 可填写的 AcroForm 字段 |
| `php examples/06-signature.php` | [PDF](../../samples/06-signature.pdf) | 带可见签章的 PAdES 数字签名 |
| `php examples/07-pdfa.php` | [PDF](../../samples/07-pdfa.pdf) | PDF/A-2u（通过 veraPDF 验证）；需要先运行 `scripts/fetch-fonts.sh` |
| `php examples/08-merge.php` | [PDF](../../samples/08-merge.pdf) | 追加并重排现有 PDF |
| `php examples/09-stamp.php` | [PDF](../../samples/09-stamp.pdf) | 水印/信头盖印 |

[压力测试集](../../examples/torture/)覆盖了疑难路径（阿拉伯语、
CJK、矩形 DataMatrix 等）—— 参见 [VIEWER-MATRIX](../en/VIEWER-MATRIX.md)。

---

## php-pdf 系列

| 包 | 提供的功能 |
|---|---|
| **php-pdf**（本包） | 核心：用 HTML 加 CSS 或构建器生成 PDF，读取、合并、填写表单、签名和验证。 |
| [laravel-php-pdf](https://github.com/dskripchenko/laravel-php-pdf) | Laravel 桥接：Blade 视图直接生成 PDF、`response()->pdf()`、按配置签名。 |
| [php-pdf-fonts-liberation](https://github.com/dskripchenko/php-pdf-fonts-liberation) | Liberation Sans、Serif 和 Mono，与 Arial、Times New Roman 和 Courier New 度量兼容（OFL 许可，独立于 MIT 核心之外）。 |
| [php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro) | 使用服务器上 CryptoPro CSP 中的密钥签名：GOST R 34.10-2012，俄罗斯合格电子签名。 |
| [php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11) | 通过 PKCS#11 使用 HSM、智能卡或 USB 令牌上的密钥签名。 |
| [php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms) | 使用 AWS KMS、Google Cloud KMS 或 Azure Key Vault 中的密钥签名。 |

---

## 性能

5 次独立子进程运行的中位数（环境与版本详见报告）。完整
方法论与复现脚本见 [基准测试](BENCHMARKS.md)。

<!-- bench:table:start — generated by scripts/bench/run.php, do not edit -->
| Scenario | dskripchenko/php-pdf | mpdf | tcpdf | dompdf | FPDF |
|---|---:|---:|---:|---:|---:|
| HTML → PDF article (~5 pages) | **15.2 ms** | 61.9 ms | 38.0 ms | 48.1 ms | _n/a_ |
| 100-page invoice (50 rows/page) | **632 ms** | 2300 ms | 1342 ms | 9156 ms | 26.4 ms |
| Image grid (20 pages × 4 JPEGs) | **7.7 ms** | 36.5 ms | 16.9 ms | 31.8 ms | 1.0 ms |
| Hello world (single page, one paragraph) | **5.0 ms** | 29.4 ms | 15.0 ms | 11.7 ms | 0.9 ms |
<!-- bench:table:end -->

FPDF 在最简单的场景中胜出（没有 HTML、没有换行、没有表格流），
但它无法生成 HTML→PDF，也不支持 UTF-8、图表、条形码、
表单、加密、签名。

---

## 运行要求

- PHP **8.2** 或更高版本。
- 必需：`ext-mbstring`、`ext-zlib`、`ext-dom`。
- 可选：`ext-openssl`（AES 加密与数字签名）。
- 无外部二进制依赖 —— 纯 PHP 实现。

---

## 测试

```bash
composer install
vendor/bin/phpunit
```

2,480+ 个测试，约 120k 个断言，在 CI 中于 PHP 8.2 / 8.3 / 8.4 / 8.5 上运行（见 [tests workflow](https://github.com/dskripchenko/php-pdf/actions/workflows/tests.yml)）。

---

## 许可证

MIT —— 见 [LICENSE](../../LICENSE)。

版权所有 © 2026 Denis Skripchenko。
