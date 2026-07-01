# 读取与合并现有 PDF

除了从零生成 PDF，`dskripchenko/php-pdf` 还能**读取现有 PDF 文件**并**组合**它们：
将一个文档追加到另一个之后、挑选单独的页面，以及把一个文档的某页盖印到另一个
文档的页面上。

全部为纯 PHP、MIT 许可 —— 无需 FPDI，无外部二进制程序。

## 目录

- [读取 PDF](#读取-pdf)
- [追加文档](#追加文档)
- [选择与重排页面](#选择与重排页面)
- [嵌入（盖印）页面](#嵌入盖印页面)
- [注释与书签](#注释与书签)
- [导入到生成的文档（FPDI 风格）](#导入到生成的文档fpdi-风格)
- [定位](#定位)
- [加密输入](#加密输入)
- [Reader 支持的内容](#reader-支持的内容)
- [限制（v1）](#限制v1)

## 读取 PDF

```php
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;

$doc = ReaderDocument::fromBytes(file_get_contents('report.pdf'));

echo $doc->pageCount();               // 页数
foreach ($doc->pages() as $page) {
    printf("%.0f × %.0f pt\n", $page->width(), $page->height());
}
```

`ReaderDocument` 惰性解析对象，沿交叉引用链（经典表、XRef 流和对象流）遍历，
并在交叉引用损坏时回退到整文件扫描。

## 追加文档

`PdfMerger` 将一个或多个来源的页面连接成一个新文档（pdftk 风格）：

```php
use Dskripchenko\PhpPdf\Pdf\Merge\{PdfMerger, PdfSource};

$bytes = PdfMerger::create()
    ->append(PdfSource::fromFile('cover.pdf'))
    ->append(PdfSource::fromFile('body.pdf'))
    ->append(PdfSource::fromFile('appendix.pdf'))
    ->toBytes();

file_put_contents('combined.pdf', $bytes);
// 或：PdfMerger::create()->append(...)->toFile('combined.pdf');
```

`PdfSource` 接受文件路径或原始字节：

```php
PdfSource::fromFile('/path/to.pdf');
PdfSource::fromBytes($binaryString);
```

## 选择与重排页面

传入从 1 开始的页码，按你希望的输出顺序：

```php
$bytes = PdfMerger::create()
    ->append(PdfSource::fromFile('a.pdf'), pages: [1, 3, 5])   // 子集
    ->append(PdfSource::fromFile('b.pdf'), pages: [2, 1])      // 重排
    ->toBytes();
```

省略 `pages` 则按阅读顺序取全部页面。每个输出页面保留其来源几何信息
（MediaBox、CropBox、旋转）。

## 嵌入（盖印）页面

`stamp()` 把一个文档的某页画在已追加页面之上 —— 来源页会成为可复用的 Form
XObject。适用于水印、信头、背景，或将某页作为插图放置。

```php
$bytes = PdfMerger::create()
    ->append(PdfSource::fromFile('invoices.pdf'))          // 基础页面
    ->stamp(
        PdfSource::fromFile('watermark.pdf'),
        page: 1,                                            // 哪一个来源页
        onPages: null,                                      // null = 每个输出页面
        placement: Placement::fit(),
    )
    ->toBytes();
```

用 `onPages`（从 1 开始）指定特定输出页面：

```php
->stamp(PdfSource::fromFile('logo.pdf'), page: 1, onPages: [1], placement: Placement::at(40, 40, 0.5))
```

基础页面自身的内容会被保留并先绘制；叠加层从干净的图形状态在其上绘制。

## 注释与书签

页面注释和文档大纲（书签）**默认**会被带入合并后的输出。内部链接和书签目标 ——
包括命名目标 —— 会被重新映射到新页面。目标页面不在输出中的链接或书签会被丢弃；
外部 `URI` 链接始终保留。

```php
PdfMerger::create()
    ->append(PdfSource::fromFile('a.pdf'))
    ->withoutAnnotations()   // 关闭注释带入
    ->withoutOutlines()      // 关闭书签带入
    ->toBytes();
```

表单字段控件（AcroForm）和弹出（popup）注释不会被带入。

## 导入到生成的文档（FPDI 风格）

`stamp()` 组合现有 PDF。若要把导入的页面放进你用 php-pdf 构建的文档中 —— 在其
上方或下方绘制你自己的文本、水印或图形 —— 使用 `PageImporter::intoDocument()`。
导入的页面会成为一个 Form XObject，你用 `Page::useFormXObject()` 定位它：

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Pdf\Merge\PageImporter;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;

$src  = ReaderDocument::fromBytes(file_get_contents('contract.pdf'));
[$w, $h] = PageImporter::pageSize($src, 0);

$doc  = new Document();
$page = $doc->addPage(customDimensionsPt: [$w, $h]);

$form = PageImporter::intoDocument($doc, $src, pageIndex: 0);
$page->useFormXObject($form, 0, 0, $w, $h);              // 导入页面作为背景
$page->showText('DRAFT', 200, 400, StandardFont::Helvetica, 48); // 你的内容在其上

$doc->toFile('stamped-contract.pdf');
```

页面的旋转与 CropBox 会自动处理。导入页面的字体和图像会原样复制到输出中。

## 定位

`Placement` 控制被嵌入页面（以点为单位）如何映射到目标页面：

| 工厂方法 | 行为 |
|---|---|
| `Placement::fit()` | 缩放适配，保持宽高比，居中。 |
| `Placement::stretch()` | 精确填满目标页面（忽略宽高比）。 |
| `Placement::at($x, $y, $scale = 1.0)` | 左下角位于 `($x, $y)`，按 `$scale` 缩放。 |

旋转的来源页（`/Rotate` 90/180/270）通过 Form 的 `/Matrix` 被正立烘焙，因此定位
在直观的正立坐标系中进行。

## 加密输入

加密来源在读取时被透明解密，并在合并输出中以**未加密**方式重新写出：

```php
PdfSource::fromFile('protected.pdf', password: 'secret');
```

支持：RC4（40/128 位）、AES-128（AESV2）和 AES-256（V5 R5/R6）。会同时尝试用户
密码和所有者密码。空密码（常见的“仅所有者限制”场景）无需传参即可工作。带预测器
（predictor）编码的流可在任意位深下解码（8 位与 16 位，PNG 预测器还支持亚字节）。

公钥安全处理器（`/Adobe.PubSec`，基于证书）**不受支持** —— 此类文件会抛出清晰的
“Unsupported security handler”错误，而不是产生乱码。

## Reader 支持的内容

- 经典 `xref` 表、XRef 流（PDF 1.5+）、混合 `/XRefStm` 以及增量更新（`/Prev`）。
- 对象流（压缩对象）。
- 过滤器：Flate（含 PNG/TIFF 预测器）、LZW、ASCII85、ASCIIHex、RunLength。
  图像过滤器（DCT/JPX/CCITT/JBIG2）原样透传。
- 通过扫描对象头恢复损坏/缺失的交叉引用。
- 页面树扁平化，继承 MediaBox/CropBox/Rotate/Resources。

## 限制（v1）

合并会从每页的内容和资源重建该页。以下内容**尚未**带入输出：

- 交互式表单字段（AcroForm）以及控件/弹出注释 —— 丢弃。
- 结构标签（Tagged PDF / 结果的 PDF-A 一致性）。
- 图像和字体流原样复制，从不重新编码。

带内部/命名目标的注释和大纲（书签）**会**被带入并重新映射 ——
见[注释与书签](#注释与书签)。

记录这些是为了避免把“支持合并”误解为“保留一切”。完整范围和路线图见
`docs/design/pdf-merge.md`。
