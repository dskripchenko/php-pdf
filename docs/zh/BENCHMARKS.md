# 基准测试

将 `dskripchenko/php-pdf` 与 Packagist 上使用最广泛的四个 PHP PDF
库进行可复现的对比：`mpdf/mpdf`、`tecnickcom/tcpdf`、
`dompdf/dompdf` 与 `setasign/fpdf`。

## 方法论

每一对（库，场景）在各自独立的 PHP 子进程中运行 **5 次**，
并先做 **1 次热身**。报告墙钟时间与峰值内存的中位数。
输出大小为所生成 PDF 的字节数。

子进程隔离很重要：单一长时间运行的 PHP 进程会累积已加载代码与
OPcache 压力，从而拉高最后运行的那个场景的内存峰值。为每次运行
新建进程，能让内存这一列变得有意义。

墙钟时间用 `hrtime(true)` 测量；内存用
`memory_get_peak_usage(true)` 测量。

**测试环境：**
- macOS 25.4（Darwin），Apple Silicon。
- PHP 8.4 CLI（Zend OPcache 启用，JIT 默认设置）。
- 库版本（Packagist）：
  - dskripchenko/php-pdf 1.0.0
  - mpdf/mpdf ^8.2
  - tecnickcom/tcpdf ^6.7
  - dompdf/dompdf ^3.0
  - setasign/fpdf ^1.8

## 场景

| 键名      | 描述                                                  |
|-----------|--------------------------------------------------------|
| `hello`   | 单页 A4，一段 `Hello, world!`。                        |
| `invoice` | 100 页 × 50 行；每页有一个 `<h1>` 标题和一个 4 列表格。 |
| `images`  | 20 页 × 4 张 JPEG 缩略图（每张 250 × 180 pt）。         |
| `html`    | 一个 `<h1>`、两个 `<h2>` 小节、24 段带粗体/斜体/链接的 Lorem 文本，外加项目符号列表。约 5 页。 |

FPDF 不支持 HTML，因此在 `html` 场景中跳过。

## 结果

### Hello world（单页，一段文字）

| 库 | 墙钟时间 (ms) | 峰值内存 | 输出大小 |
|---|---:|---:|---:|
| fpdf | 0.9 | 4.0 MB | 1 KB |
| **phppdf** | **4.6** | **6.0 MB** | 0.8 KB |
| dompdf | 12.0 | 8.0 MB | 1 KB |
| tcpdf | 14.8 | 16.0 MB | 6 KB |
| mpdf | 29.8 | 20.0 MB | 14 KB |

### 100 页发票（每页 50 行）

| 库 | 墙钟时间 (ms) | 峰值内存 | 输出大小 |
|---|---:|---:|---:|
| fpdf | 26.0 | 6.0 MB | 198 KB |
| **phppdf** | **518.2** | 50.0 MB | **192 KB** |
| tcpdf | 1348.9 | 26.0 MB | 462 KB |
| mpdf | 2366.9 | 30.0 MB | 811 KB |
| dompdf | 8890.6 | 350.0 MB | 2316 KB |

在 5000 行表格的工作负载上，`phppdf` **比 tcpdf 快约 2.6 倍**、
**比 mpdf 快约 4.6 倍**、**比 dompdf 快约 17 倍**，同时在所有
支持 HTML 的库中产生最小的输出体积。

### 图片网格（20 页 × 4 张 JPEG）

| 库 | 墙钟时间 (ms) | 峰值内存 | 输出大小 |
|---|---:|---:|---:|
| fpdf | 1.0 | 4.0 MB | 6 KB |
| **phppdf** | **6.4** | 6.0 MB | 8 KB |
| tcpdf | 15.3 | 16.0 MB | 33 KB |
| dompdf | 30.4 | 10.0 MB | 4 KB |
| mpdf | 35.9 | 20.0 MB | 24 KB |

基于内容哈希的同图去重意味着：在 20 个页面上用 80 次的同一张
JPEG，只会被嵌入为一个 XObject。

### HTML → PDF 文章（约 5 页）

| 库 | 墙钟时间 (ms) | 峰值内存 | 输出大小 |
|---|---:|---:|---:|
| **phppdf** | **10.8** | 8.0 MB | 8 KB |
| tcpdf | 36.1 | 16.0 MB | 49 KB |
| dompdf | 46.9 | 12.0 MB | 19 KB |
| mpdf | 61.1 | 20.0 MB | 55 KB |
| fpdf | _跳过_（不支持 HTML） | — | — |

## 如何复现

```bash
git clone https://github.com/dskripchenko/php-pdf.git
cd php-pdf

# Install the library under test.
composer install

# Install the comparison harness with mpdf, tcpdf, dompdf, FPDF as
# isolated dev dependencies (does not pollute the library's composer.json).
cd scripts/bench
composer install

# Run all scenarios; ~30 seconds total wall time.
php run.php             # JSON to stdout
php run.php --md        # Markdown table form
```

`run.php` / `one.php` 内部将内存上限锁定在 1 GB，以便 dompdf
在发票场景中有足够空间。

## 说明与注意事项

- **FPDF 的优势。** FPDF 在 `hello` 和 `images` 上胜出，是因为它
  是一个极简的命令式 API —— 没有 HTML 解析、没有文本换行、
  没有表格流、没有 UTF-8。仅与同样极简的场景比较才公平；
  在覆盖面相当的特性（HTML 输入、图表、条形码、加密、签名、
  PDF/A、AcroForm）上，FPDF 并不具备这些能力。

- **dompdf 的发票内存。** dompdf 在排版前会在内存中构建完整的
  DOM 与 CSS 盒模型树，其规模与整个 HTML 总量挂钩，而非按页计算。
  100 页发票上 350 MB 的峰值正反映了这种架构选择。

- **输出大小。** 更小不一定更好 —— 有些库会更积极地对字体做子集化，
  或使用未压缩的流。上述所有场景都使用各库默认的字体／压缩设置。

- **HTML 覆盖度。** HTML 场景使用的元素（`<h1>`、`<h2>`、
  `<p>`、`<strong>`、`<em>`、`<a>`、`<ul>`、`<li>`）所有库都支持。
  复杂 CSS（Flexbox、多栏、浮动）不在本次测量范围内。

- **首次运行开销。** 墙钟时间包含子进程启动和 autoloader 引导，
  这对 serverless / 每请求 的工作负载来说是真实的开销。长时间运行
  的 worker 在所有库上看到的每次渲染开销都会按比例减小。
