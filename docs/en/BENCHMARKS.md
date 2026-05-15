# Benchmarks

A reproducible comparison of `dskripchenko/php-pdf` against the four
most-used PHP PDF libraries on Packagist: `mpdf/mpdf`, `tecnickcom/tcpdf`,
`dompdf/dompdf`, and `setasign/fpdf`.

## Methodology

Each (library, scenario) pair runs **5 times** inside its own PHP
subprocess after **1 warmup** run. Median wall-time and peak memory are
reported. Output size is the byte count of the produced PDF.

Subprocess isolation matters: a single long-running PHP process
accumulates loaded code and OPcache pressure, which inflates the memory
peak of whatever scenario happens to run last. Spawning a fresh process
per run makes the memory column meaningful.

Wall-time is measured with `hrtime(true)`; memory with
`memory_get_peak_usage(true)`.

**Test environment:**
- macOS 25.4 (Darwin), Apple Silicon.
- PHP 8.4 CLI (Zend OPcache enabled, JIT default).
- Library versions (Packagist):
  - dskripchenko/php-pdf 1.0.0
  - mpdf/mpdf ^8.2
  - tecnickcom/tcpdf ^6.7
  - dompdf/dompdf ^3.0
  - setasign/fpdf ^1.8

## Scenarios

| Key       | Description                                            |
|-----------|--------------------------------------------------------|
| `hello`   | Single A4 page, one `Hello, world!` paragraph.        |
| `invoice` | 100 pages × 50 rows; each page has an `<h1>` heading and a 4-column table. |
| `images`  | 20 pages × 4 JPEG thumbnails (250 × 180 pt each).     |
| `html`    | One `<h1>`, two `<h2>` sections, 24 paragraphs of Lorem with bold/italic/link plus bullet lists. ~5 pages. |

FPDF has no HTML support and is therefore skipped on the `html`
scenario.

## Results

### Hello world (single page, one paragraph)

| Library | Wall time (ms) | Peak memory | Output size |
|---|---:|---:|---:|
| fpdf | 0.9 | 4.0 MB | 1 KB |
| **phppdf** | **4.6** | **6.0 MB** | 0.8 KB |
| dompdf | 12.0 | 8.0 MB | 1 KB |
| tcpdf | 14.8 | 16.0 MB | 6 KB |
| mpdf | 29.8 | 20.0 MB | 14 KB |

### 100-page invoice (50 rows/page)

| Library | Wall time (ms) | Peak memory | Output size |
|---|---:|---:|---:|
| fpdf | 26.0 | 6.0 MB | 198 KB |
| **phppdf** | **518.2** | 50.0 MB | **192 KB** |
| tcpdf | 1348.9 | 26.0 MB | 462 KB |
| mpdf | 2366.9 | 30.0 MB | 811 KB |
| dompdf | 8890.6 | 350.0 MB | 2316 KB |

`phppdf` is **~2.6× faster than tcpdf**, **~4.6× faster than mpdf**, and
**~17× faster than dompdf** on a 5000-row table workload, while producing
the smallest output among the HTML-capable libraries.

### Image grid (20 pages × 4 JPEGs)

| Library | Wall time (ms) | Peak memory | Output size |
|---|---:|---:|---:|
| fpdf | 1.0 | 4.0 MB | 6 KB |
| **phppdf** | **6.4** | 6.0 MB | 8 KB |
| tcpdf | 15.3 | 16.0 MB | 33 KB |
| dompdf | 30.4 | 10.0 MB | 4 KB |
| mpdf | 35.9 | 20.0 MB | 24 KB |

Identity-dedup by content hash means the same JPEG used 80 times across
20 pages is embedded as a single XObject.

### HTML → PDF article (~5 pages)

| Library | Wall time (ms) | Peak memory | Output size |
|---|---:|---:|---:|
| **phppdf** | **10.8** | 8.0 MB | 8 KB |
| tcpdf | 36.1 | 16.0 MB | 49 KB |
| dompdf | 46.9 | 12.0 MB | 19 KB |
| mpdf | 61.1 | 20.0 MB | 55 KB |
| fpdf | _skipped_ (no HTML) | — | — |

## How to reproduce

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

Memory limits inside `run.php` / `one.php` are pinned to 1 GB to give
dompdf room on the invoice scenario.

## Notes and caveats

- **FPDF advantages.** FPDF wins on `hello` and `images` because it is
  a minimal imperative API — no HTML parsing, no text wrapping, no
  table flow, no UTF-8. It is a fair benchmark only against equally
  minimal scenarios; comparable feature coverage (HTML input, charts,
  barcodes, encryption, signing, PDF/A, AcroForms) is not available in
  FPDF.

- **dompdf invoice memory.** dompdf builds the full DOM and CSS box
  tree in memory before laying out, which scales with total HTML size
  rather than per-page. The 350 MB peak on 100-page invoice reflects
  that architectural choice.

- **Output size.** Smaller is not always better — some libraries embed
  more aggressive font subsets or use uncompressed streams. All
  scenarios above use the libraries' default font/compression settings.

- **HTML coverage.** The HTML scenario uses elements (`<h1>`, `<h2>`,
  `<p>`, `<strong>`, `<em>`, `<a>`, `<ul>`, `<li>`) that all libraries
  support. Complex CSS (Flexbox, multi-column, floats) is not measured.

- **First-run overhead.** Wall times include subprocess startup and
  autoloader bootstrap, which is realistic for serverless / per-request
  workloads. Long-running workers will see proportionally smaller
  per-render overhead for all libraries.
