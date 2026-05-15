## Benchmark results

Methodology: each (library, scenario) ran 5 times in an isolated
PHP subprocess (after 1 warmup); median wall time and peak memory
are reported. Output size is the produced PDF byte count.

### Hello world (single page, one paragraph)

| Library | Wall time (ms) | Peak memory | Output size |
|---|---:|---:|---:|
| fpdf | 0.9 | 4.0 MB | 1 KB |
| phppdf | 4.6 | 6.0 MB | 0 KB |
| dompdf | 12.0 | 8.0 MB | 1 KB |
| tcpdf | 14.8 | 16.0 MB | 6 KB |
| mpdf | 29.8 | 20.0 MB | 14 KB |

### 100-page invoice (50 rows/page)

| Library | Wall time (ms) | Peak memory | Output size |
|---|---:|---:|---:|
| fpdf | 26.0 | 6.0 MB | 198 KB |
| phppdf | 518.2 | 50.0 MB | 192 KB |
| tcpdf | 1348.9 | 26.0 MB | 462 KB |
| mpdf | 2366.9 | 30.0 MB | 811 KB |
| dompdf | 8890.6 | 350.0 MB | 2316 KB |

### Image grid (20 pages × 4 JPEGs)

| Library | Wall time (ms) | Peak memory | Output size |
|---|---:|---:|---:|
| fpdf | 1.0 | 4.0 MB | 6 KB |
| phppdf | 6.4 | 6.0 MB | 8 KB |
| tcpdf | 15.3 | 16.0 MB | 33 KB |
| dompdf | 30.4 | 10.0 MB | 4 KB |
| mpdf | 35.9 | 20.0 MB | 24 KB |

### HTML → PDF article (~5 pages)

| Library | Wall time (ms) | Peak memory | Output size |
|---|---:|---:|---:|
| phppdf | 10.8 | 8.0 MB | 8 KB |
| tcpdf | 36.1 | 16.0 MB | 49 KB |
| dompdf | 46.9 | 12.0 MB | 19 KB |
| mpdf | 61.1 | 20.0 MB | 55 KB |
| fpdf | _skipped_ (not implemented) | — | — |

