# 从 mpdf 迁移

`mpdf/mpdf` 采用 GPL-2.0-only 许可：将其捆绑进专有或 OEM 产品，要么整体
以 GPL 发布，要么购买商业许可。`dskripchenko/php-pdf` 是 MIT 许可 ——
而且在我们测量的所有 HTML→PDF 场景中都[更快](BENCHMARKS.md)。

迁移有两条路线，下面分别说明。

## 路线 1 —— 兼容门面（最快）

对于 mpdf 最常见的用法 —— `WriteHTML()` + `Output()` —— 只需替换导入，
调用处保持不变：

```php
// 之前
$mpdf = new \Mpdf\Mpdf(['format' => 'A4', 'margin_left' => 15]);
$mpdf->WriteHTML($html);
$mpdf->Output('invoice.pdf', 'F');

// 之后
$mpdf = new \Dskripchenko\PhpPdf\Compat\Mpdf(['format' => 'A4', 'margin_left' => 15]);
$mpdf->WriteHTML($html);
$mpdf->Output('invoice.pdf', 'F');
```

门面覆盖：多次调用 `WriteHTML()`（追加）、`AddPage()`、`Output()` 的全部
四种目标（`F` 文件 / `S` 字符串 / `D` 下载 / `I` 内联）、`SetTitle` /
`SetAuthor` / `SetCreator` / `SetSubject` / `SetKeywords`，以及配置键
`format`（含 `A4-L` 后缀）、`orientation`、`margin_left/right/top/bottom`
（毫米，与 mpdf 一致）。

刻意**不**覆盖：mpdf 专有的 HTML 扩展（`<pagebreak>`、`<barcode>`、
`<watermarktext>` 等）、`WriteHTML()` 的模式参数、`SetHeader`/`SetFooter`
短代码以及字体配置数组 —— 这些场景下原生 API（路线 2）严格更好。
当门面不够用时，`toDocument()` 会交出组装好的原生 `Document`。

**非拉丁文本：** mpdf 自带 DejaVu 并自动选用。门面的默认引擎只使用 PDF
base-14 字体（WinAnsi，仅拉丁字符）。中文/西里尔/阿拉伯文请传入内嵌
TTF 的引擎：

```php
use Dskripchenko\PhpPdf\Compat\Mpdf;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;

$engine = new Engine(defaultFont: new PdfFont(TtfFile::fromFile('/path/NotoSansSC.ttf')));
$mpdf = new Mpdf([], $engine);
```

## 路线 2 —— 原生 API

| mpdf | php-pdf |
|---|---|
| `new \Mpdf\Mpdf()` | *（HTML 输入无需配置对象）* |
| `$mpdf->WriteHTML($html)` | `$doc = Document::fromHtml($html)` |
| `$mpdf->Output($f, 'F')` | `$doc->toFile($f)` |
| `$mpdf->Output('', 'S')` | `$doc->toBytes()` |
| `$mpdf->Output('x.pdf', 'D')` | 发送响应头 + `echo $doc->toBytes()`（Laravel：dskripchenko/laravel-php-pdf 的 `response()->pdf(...)`） |
| `$mpdf->Output('', 'I')` | 发送响应头 + `echo $doc->toBytes()` |
| `['format' => 'A4-L', 'margin_left' => 15]` | `new Section($blocks, pageSetup: new PageSetup(paperSize: PaperSize::A4, orientation: Orientation::Landscape, margins: new PageMargins(leftPt: 15 * 72 / 25.4)))` |
| `$mpdf->SetTitle('T')` / `SetAuthor` | `new Document($section, metadata: ['Title' => 'T', 'Author' => ...])` 或 `Document::fromHtml($html, metadata: [...])` |
| `$mpdf->AddPage()` | 在块之间插入 `new PageBreak` 元素 |
| `SetHeader('text')` / `SetFooter` | `Section(headerBlocks: [...], footerBlocks: [...])` —— 完整块元素，而非短代码 |
| `SetWatermarkText('DRAFT')` | `Section(watermarkText: 'DRAFT')` |
| 自定义 TTF（`fontdata` 配置） | `new Engine(defaultFont: new PdfFont(TtfFile::fromFile($path)), boldFont: ..., fontProvider: ...)` 传给 `toBytes()/toFile()` |
| `SetProtection([...], $user, $owner)` | `new Document($section, encryption: new EncryptionParams($user, $owner, ...))` |
| 配置里的 `PDFA` 标志 | `new Document($section, pdfA: new PdfAConfig($iccPath, ...))` —— [CI 中经 veraPDF 验证](../en/CONFORMANCE.md) |
| 数字签名（借助外部工具） | 内置：`new Document($section, signature: new SignatureConfig($certPem, $keyPem))` |
| `<barcode code="..." type="QR">` | `new Barcode('...', BarcodeFormat::Qr)` 元素（12 种一维 + 4 种二维格式） |

## 查找/替换速查表

| 查找 | 替换为 |
|---|---|
| `use Mpdf\Mpdf;` | `use Dskripchenko\PhpPdf\Compat\Mpdf;` |
| `new Mpdf(` | `new Mpdf(` *（使用门面导入后无需修改）* |
| `\Mpdf\Output\Destination::FILE` | `'F'` |
| `\Mpdf\Output\Destination::STRING_RETURN` | `'S'` |
| `\Mpdf\Output\Destination::DOWNLOAD` | `'D'` |
| `\Mpdf\Output\Destination::INLINE` | `'I'` |
| `\Mpdf\MpdfException` | `\Throwable`（php-pdf 抛出 SPL 异常） |

## 注意事项

- **字体**：不会自动内嵌任何字体。base-14 覆盖拉丁字符（WinAnsi）；
  其余文字需要通过 `Engine` 提供 TTF（见上）。与 mpdf 不同，TTF 默认
  做子集化 —— 输出保持精简。
- **CSS 覆盖范围**不同：php-pdf 解析 HTML5/内联 CSS 子集（见
  [使用指南](USAGE.md)）；mpdf 专有标签和 `@page` 规则不被识别，复杂的
  `@page` 配置请改用 `Section`/`PageSetup`。
- **单位**：mpdf 配置用毫米；原生 API 用点（`1 毫米 = 72 / 25.4 pt`）。
  兼容门面会自动换算。
- **临时目录**：php-pdf 不写磁盘 —— 没有 `tempDir` 配置，也没有需要
  清理的 `ttfontdata` 缓存。
- **异常**：没有 `MpdfException`；失败抛出 SPL 异常
  （`InvalidArgumentException`、`LogicException`、`RuntimeException`）。

两条路线均由测试套件覆盖（`tests/Compat/MpdfCompatTest.php`）——
上面的示例全部来自测试，而非愿景。

---

语言：[English](../en/MIGRATION-FROM-MPDF.md) · [Русский](../ru/MIGRATION-FROM-MPDF.md) · [中文](MIGRATION-FROM-MPDF.md) · [Deutsch](../de/MIGRATION-FROM-MPDF.md)
