# 从 FPDI 迁移

`setasign/fpdi` 有一个大家总在最糟糕的时刻才发现的陷阱：免费版无法读取
使用压缩交叉引用流（xref streams）的 PDF —— 而这是大多数现代 PDF
生成器的**默认输出格式**（PDF 1.5+）。客户一旦上传这样的文件，就需要
购买商业版 FPDI PDF-Parser 附加组件。`dskripchenko/php-pdf`（MIT）
开箱即读免费 FPDI 读不了的内容：

- 经典 xref **与 xref streams**、object streams、混合文件；
- **加密输入** —— RC4、AES-128、AES-256（R5/R6），用户与所有者口令；
- 通过扫描对象头**恢复损坏的 xref**；
- 经第三方语料验证：pdfTeX、LibreOffice、Google Docs/Skia、Qt/pdfkit、
  Ghostscript、ImageMagick、FPDF2、pypdf。

## 路线 1 —— 兼容门面

经典 FPDI 流程一对一映射：

```php
// 之前                                      // 之后
use setasign\Fpdi\Fpdi;                      use Dskripchenko\PhpPdf\Compat\Fpdi;

$pdf = new Fpdi();                           $pdf = new Fpdi();
$count = $pdf->setSourceFile('in.pdf');      $count = $pdf->setSourceFile('in.pdf');
$tpl = $pdf->importPage(1);                  $tpl = $pdf->importPage(1);
$pdf->AddPage('', $pdf->getTemplateSize($tpl));
$pdf->useTemplate($tpl, x: 0, y: 0);         // 相同调用
$pdf->Output('F', 'out.pdf');                // 相同调用（两种参数顺序均可）
```

坐标沿用 FPDF 约定：原点在页面左上角，y 向下，用户单位（默认毫米；
构造函数支持 `pt`、`cm`、`in`）。`useTemplate()` 只传 `width` 或只传
`height` 时按比例缩放，与 FPDI 一致。

门面不模拟 FPDF 的绘图 API（`Cell()`/`SetFont()`），而是暴露底层对象：
`$pdf->page()` 返回原生 `Pdf\Page`（文本、图片、表单字段），
`$pdf->document()` 返回原生 `Pdf\Document`（加密、签名、元数据）：

```php
$pdf->page()->showText('COPY - not an original', 40, 40,
    \Dskripchenko\PhpPdf\Pdf\StandardFont::Helvetica, 9);
```

## 路线 2 —— 原生 API

| FPDI | php-pdf |
|---|---|
| `$pdf->setSourceFile($f)` | `$src = ReaderDocument::fromBytes(file_get_contents($f))`（可选口令参数） |
| *（页数 —— 返回值）* | `count($src->pages())` |
| `$tpl = $pdf->importPage($n)` | `$form = PageImporter::intoDocument($doc, $src, $n - 1)` —— 0 起始索引 |
| `$pdf->useTemplate($tpl, $x, $y, $w, $h)` | `$page->useFormXObject($form, $x, $y, $w, $h)` —— PDF 坐标：左下原点、点 |
| `$pdf->getTemplateSize($tpl)` | `$form->bboxWidth()` / `$form->bboxHeight()` |
| 整文件拼接循环 | `PdfMerger::create()->append(PdfSource::fromFile($a))->append(...)->toBytes()` |
| 盖印/水印循环 | `PdfMerger::create()->append($src)->stamp(PdfSource::fromFile($stamp), placement: Placement::fit())` |
| 加密源 *（无附加组件不可用）* | `PdfSource::fromFile($f, password: '...')` / `ReaderDocument::fromBytes($bytes, '...')` |
| xref-stream 源 *（需商业解析器）* | 开箱即用 |

**原生工具的选择：**

- `PdfMerger` —— 追加/重排整个文档。注释、书签与命名目标会被带入
  （FPDI 会丢弃它们）。
- `PageImporter` —— FPDI 风格：把导入页作为 XObject 放进新生成的文档，
  可在其上/下继续绘制。

## 注意事项

- **坐标**：门面保留 FPDF 的左上/毫米约定；原生 API 是 PDF 原生的 ——
  左下原点、点。换算：`y_pdf = 页高 − y_毫米 × 72/25.4 − 高度_pt`。
- **页码**：FPDI 的 `importPage()` 从 1 起；原生
  `PageImporter::intoDocument()` 从 0 起。门面保持从 1 起。
- **注释**：与 FPDI 一样，`importPage`/`PageImporter` 只导入页面
  *内容*。需要保留链接/书签时请用 `PdfMerger`。
- **`adjustPageSize`**：用 `AddPage('', $pdf->getTemplateSize($tpl))`
  显式传入模板尺寸即可，等价于 FPDI 的该标志。

以上映射均由 `tests/Compat/FpdiCompatTest.php` 覆盖。

---

语言：[English](../en/MIGRATION-FROM-FPDI.md) · [Русский](../ru/MIGRATION-FROM-FPDI.md) · [中文](MIGRATION-FROM-FPDI.md) · [Deutsch](../de/MIGRATION-FROM-FPDI.md)
