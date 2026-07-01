# External PDF corpus

Real-world PDFs from **third-party producers**, used to validate the reader and
merge subsystem against files php-pdf did not generate itself (round-tripping
against our own output can hide spec deviations).

| File | Producer | Pages | Notes |
|------|----------|------:|-------|
| `w3c-dummy.pdf` | (W3C sample) | 1 | classic minimal PDF |
| `pypdf-minimal.pdf` | pdfTeX 1.40.23 | 1 | trivial document |
| `pypdf-libreoffice.pdf` | LibreOffice Writer | 1 | trivial |
| `pdflatex-image.pdf` | pdfTeX 1.40.23 | 1 | embedded image |
| `pdflatex-4pages.pdf` | pdfTeX 1.40.23 | 4 | multi-page |
| `pdflatex-outline.pdf` | pdfTeX 1.40.23 | 4 | outlines/bookmarks |
| `imagemagick-lzw.pdf` | ImageMagick | 1 | **LZWDecode** image (4×4, 9-bit) |
| `imagemagick-lzw-large.pdf` | ImageMagick | 1 | **LZWDecode** 48×48 noise — exercises code-width widening past 9-bit |
| `imagemagick-ccitt.pdf` | ImageMagick | 1 | CCITTFax image (passthrough) |
| `google-doc.pdf` | Skia/PDF (Google Docs) | 1 | Chrome/Skia renderer |
| `pdfkit.pdf` | Qt 5.12 | 1 | Qt print pipeline |
| `crazyones-pdfa.pdf` | Ghostscript 10 | 1 | PDF/A |
| `cropped-rotated-scaled.pdf` | pypdf | 4 | rotated / cropped pages |
| `annotated.pdf` | FPDF2 | 1 | annotations |
| `libreoffice-password.pdf` | LibreOffice Writer | 1 | **encrypted (RC4-128)**, user password `openpassword` |

## Sources

- `w3c-dummy.pdf` — W3C WAI test resources (`w3.org/WAI/ER/tests/.../dummy.pdf`).
- All `pypdf-*`, `pdflatex-*`, `imagemagick-*`, `google-doc`, `pdfkit`,
  `crazyones-pdfa`, `cropped-rotated-scaled`, `annotated`,
  `libreoffice-password` — from the
  [`py-pdf/sample-files`](https://github.com/py-pdf/sample-files) corpus,
  a curated public collection of sample PDFs for testing PDF tooling.

These files are used solely as read-only test fixtures.
