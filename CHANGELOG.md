# Changelog

All notable changes to `dskripchenko/php-pdf` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- Embedded TrueType subsets were named after the whole font. Their
  `/BaseFont` and `/FontName` now start with a six-letter tag
  (ISO 32000-1 §9.6.4), taken from a hash of the subset's bytes, so two
  subsets of one font no longer share a name and the output stays
  reproducible. The names in the output change: `/BaseFont /LiberationSans`
  becomes `/ABCDEF+LiberationSans`. Fonts embedded whole and CFF fonts keep
  the plain name.
- PostScript names are written as PDF names (§7.3.5): delimiters, `#`,
  whitespace and characters outside printable ASCII are escaped as `#xx`.
- A TrueType subset carries a `/CIDSet` in its font descriptor, which
  PDF/A-1 requires for a subset font (ISO 19005-1 §6.3.5). It lists every
  glyph of the subset, as PDF/A-2 and 3 require of a CIDSet.

## [1.25.0] — 2026-10-19

### Fixed
- SVG `<text>` was always set in base-14 Helvetica, so Cyrillic and any
  other non-Latin text printed as `?` — a stamp "ОПЛАЧЕНО" came out as
  "????????". It is set in the document's fonts now: the family chain, the
  fallbacks and the bold/italic faces a paragraph uses.
- SVG `<text>` ignored `text-anchor` (centred labels started at their
  anchor), a group's `transform` (rotated stamps printed level) and the font
  properties of its group or `style`. `font-family`, `font-weight`,
  `font-style`, `font-size`, `fill` and `text-anchor` are inherited as SVG
  defines, and the glyphs are placed by a text matrix that follows the
  group's rotation, scale and skew.

### Added
- `Page::showTextWithMatrix()` — text placed by a full text matrix, in a
  base-14 or an embedded font; `SvgRenderer::render()` takes an optional
  font resolver.

## [1.24.1] — 2026-10-19

### Fixed
- An absolutely placed block inside a table cell was drawn from where the
  cell's vertical alignment put its content: a logo anchored in a
  bottom-aligned cell landed at the cell's bottom, over the next row. It is
  placed from the cell's own top now, as in CSS.

## [1.24.0] — 2026-10-19

### Added
- HTML lists: `<ol start>`, `type` and `list-style-type` (decimal, alpha,
  roman, `none`), `<li value>`, and `::marker { content }` labels with
  strings, `counter(list-item)` and `counters(list-item, ".")` — Word's
  multilevel numbering (`3.1.`, `4.1.`) as an import writes it. An item with
  no label and no content draws only its nested list. `ListItem::$marker`
  and `ListItem::$value`; `ListFormat::number()`.
- HTML lists are placed by CSS: a list's `padding-left` / `margin-left`
  (`ListNode::$indentPt`) and an item's `margin-left` (`ListItem::$indentPt`)
  instead of the fixed indent per level — Word places a level by its
  numbering's indent, not by its depth.
- HTML lists keep the vertical space their CSS gives them: a list's
  `margin-top` / `margin-bottom` (the 6 pt gap after a list only when the
  list says nothing), an item's own margins on its paragraphs.
- `Footnote::$style`: the text style a note is printed in at the foot of
  the page or among the endnotes — notes are usually smaller than the body.
- A list in a table cell splits between its items (and inside a nested
  list) when its row is split across pages; the rest numbers on. Before, a
  long list carried its whole row to the next page.
- A hanging indent is a tab stop on a paragraph's first line, as in Word,
  and a list label is followed by a tab: the item's text starts on the
  indent whatever the label's width (`1.` or `3.12.`).

### Fixed
- HTML: `<ol start>` and `type` were ignored — every list counted from 1
  in decimal.
- HTML: a list item holding only a nested list printed an empty line
  before it.

## [1.23.0] — 2026-10-18

### Added
- `Section::$footnotesAtPageFoot` — footnotes at the foot of the page that
  references them, with as much room as they take: a page without notes
  loses nothing, and a line (or a table row) whose notes do not fit moves on
  with them. The fixed `footnoteBottomReservedPt` area cost a seven-page form
  three extra pages.
- HTML: CSS custom properties — `--name` declarations are inherited and
  `var(--name, fallback)` is substituted in any value, shorthands included;
  an unresolved one drops the property, as in a browser. `:root` selector.
- HTML: a block an `ElementHandler` returns for an element in a line of text
  (a signature field for an `<img>` in `<p>`) splits the paragraph there
  instead of being dropped; `HtmlContext::$alignment` gives the text-align in
  effect to place it.
- HTML: `localFiles: false` on `HtmlParser` / `Document::fromHtml()` — for
  HTML from untrusted authors `<img src>` and `<link href>` read nothing from
  the disk, only `data:` URLs and the image source.

### Fixed
- HTML: an `<svg>` inside a paragraph was kept as a child of the paragraph,
  where the engine does not draw it — a QR code in `<p>` printed as nothing.
  It is now a block of its own with the paragraph's alignment, and CSS
  `width` / `height` on it win over its attributes.
- `Document::fromHtml()` with a `$sectionTemplate` dropped the template's
  footnote area (`footnoteBottomReservedPt`); every field but the body is
  kept now.

## [1.22.0] — 2026-10-18

### Added
- `Html\ElementHandler` — an application decides what an HTML element
  becomes before the parser does (a signature field, a footnote, a custom
  tag); `Html\HtmlContext` gives it the element's style and reads the
  element's content as the parser would. `Document::fromHtml(...,
  elementHandlers: [...])`, `new HtmlParser(elementHandlers: [...])`.
- `imageSource` — a closure that returns an image's bytes by its `src`
  (storage, a database, an allow-listed host), tried before `data:` URLs and
  local files.
- `counter(page)` and `counter(pages)` in `::before` / `::after` `content` —
  the page number and the page count, as in CSS paged media.

## [1.21.2] — 2026-10-18

### Fixed
- A table row carried over to a new page because nothing of it fitted the
  rest of the old one was drawn whole there, even when taller than the
  page: everything past the bottom was lost (a long clause of a contract
  lost 343 words). It now splits on the new page like any other row.
- A paragraph was measured by a simplified copy of the line breaking: tabs,
  inline images, the first-line indent of leading spaces, long-word and
  soft-hyphen breaks were left out, so a cell could be measured shorter
  than it laid out. Layout and measurement now share one line breaker and
  one line-height rule — rows are as tall as their content, and nothing
  overlaps the row below.
- A word split into runs ("настояще" + "му", as Word cuts text at
  formatting changes) could break between its pieces at the end of a line;
  it now moves to the next line whole.

## [1.21.1] — 2026-10-18

### Fixed
- A table with a cell merged down (`rowSpan`) failed with "max(): Argument
  #1 must contain at least one element" from the second row after the
  merge had run out — since 1.19.0. Found by printable's reference
  documents.

## [1.21.0] — 2026-10-18

### Added
- HTML: `display: grid` — `grid-template-columns` (lengths, `%`, `fr`,
  `auto`, `repeat()`, `minmax()`), `grid-column: span n` / `a / b`, `gap`.
- HTML: `display: flex` wraps into lines with `flex-wrap: wrap` (items keep
  their widths, a short line keeps its empty room) and stacks with
  `flex-direction: column`.
- HTML: `float: left / right` with `clear` — the float and the content
  after it side by side (text does not run on under a float that ends).
- HTML: `position: absolute` and `fixed` through a new out-of-flow
  `Element\Positioned` block — offsets from the page area, or from the
  padding edge of a positioned ancestor; a fixed element repeats on every
  page of the section.
- HTML: `break-inside: avoid` on any block; one taller than a page still
  splits.
- HTML: `::before` / `::after` with `content` — strings with CSS escapes,
  `attr()`, `open-quote` / `close-quote`.
- HTML: `<link rel="stylesheet">` to local files and `data:` URLs, applied
  in document order with `<style>`; remote sheets are not fetched.
- HTML: `font-family` lists — the first family the font provider has;
  generic names choose the base-14 face.
- OpenType fonts with CFF outlines (`.otf` with a `CFF ` table), embedded
  whole as `/FontFile3 /CIDFontType0C` (PDF/A-1 compatible); CID-keyed
  fonts map CIDs through the CFF charset. GPOS extension lookups are
  followed, so such fonts keep their kerning. CFF2 is refused clearly.
- PNG: palette images (1–8 bit, with tRNS transparency), 16-bit images
  (with 16-bit alpha) and interlaced (Adam7) images.
- `scripts/viewer-matrix/run.sh` — renders the torture set with pdf.js,
  PDFium (Chrome's engine) and Quartz; a torture document for HTML/CSS.

### Changed
- Text without an embedded font is set in the base-14 face that matches
  the run — bold, italic, Times for serif, Courier for monospace — and
  measured with the fonts' real widths (it was always `fallbackStandard`,
  at half an em per letter), so centring, right alignment, justification,
  wrapping and chart labels are right.
- PNG images without alpha are embedded with their compressed data as it
  is (they were decoded and recompressed): a 640×480 RGB image takes
  225 KB instead of 699 KB and 0.3 ms instead of 98 ms.
- `<th>` is centred unless styled otherwise, as in a browser.

### Fixed
- A flex or grid item's background and border were drawn twice (on the
  cell and on the text inside), with the gap inside the border.
- Duplicate-image detection now compares palettes and masks, and
  `ImageStamper` handles palette images.

## [1.20.0] — 2026-10-18

### Added
- `DetachedSignature::sign()` takes a `CmsSigner` too — CryptoPro CSP on
  the server, a signing service. Its CMS is checked as for a PDF (it covers
  exactly the content, it was made with the signer's certificate, it
  verifies) and returned detached, with the chain and the timestamp.

### Fixed
- Text in the base-14 fonts (no embedded font) came out garbled outside
  ASCII: the fonts are declared with `/WinAnsiEncoding`, but the UTF-8
  bytes went into the content stream as they were, so "Café · 10 €" read
  "CafÃ© Â· 10 â‚¬". Text is now converted to WinAnsi codes in
  `Page::showText()`, watermarks, laid-out documents and form-field
  appearances; a character WinAnsi has no code for (Cyrillic, CJK…) is
  shown as `?` — embed a font for those. Symbol and ZapfDingbats keep
  their own codes, and text that is not valid UTF-8 passes through as
  WinAnsi bytes.

## [1.19.1] — 2026-10-16

### Fixed
- Dashed and dotted borders (`BorderStyle::Dashed`, `BorderStyle::Dotted`,
  CSS `dashed` / `dotted`) were drawn as solid lines. Paragraph, cell and
  table borders now draw dashes three line widths long and round dots one
  width across, with the gaps stretched so each side starts and ends on a
  dash or a dot and the corners stay closed.
- Text is placed in its line the way CSS places it: the extra room a line
  height adds is split between the top and the bottom of the line
  (half-leading). The baseline used to sit 0.8 em below the line top at
  any line height, so text rose ~1pt above where a browser draws it and
  touched a cell's or box's top border. Inline images stay inside their
  line instead of rising above it.

## [1.19.0] — 2026-10-16

### Added
- HTML input reads CSS as a browser does. `<style>` blocks with the
  cascade (type, class, id, attribute selectors, `:first-child`,
  `:last-child`, `:nth-child()`, `:not()`, descendant / `>` / `+` / `~`,
  specificity, `!important`), `@media print` applied and `@media screen`
  skipped, `@page` size and margins for `Document::fromHtml()`. No CSS
  inliner is needed any more.
- Inheritance: `color`, `font-*`, `text-decoration`, `text-transform`,
  `letter-spacing`, `text-align`, `line-height`, `text-indent`,
  `white-space` set on `body`, a `div` or a `td` reach the text inside.
  `em` and `%` refer to the current font size (`$baseFontSizePt` of
  `Document::fromHtml()` / `HtmlParser` where none is set).
- HTML tables: `style` on `<td>`/`<th>` (border, background, padding,
  vertical-align, width, height), on `<tr>` (height, `break-inside:
  avoid`) and on `<table>` (width, `table-layout`, border-collapse,
  border-spacing, border, margin, `auto` centring); `<col>` widths; the
  `border`, `cellpadding`, `cellspacing`, `width`, `align`, `bgcolor`,
  `valign` attributes; `<thead>` rows repeat on every page.
- Containers: a `div` (or `section`, …) holding blocks keeps them as
  blocks — its margin and padding space and indent them, a background or
  border draws a box. `display: flex` lays children out side by side;
  `display: none` hides.
- `page-break-before` / `-after` (and `break-*`) in HTML; `white-space:
  pre / pre-wrap / pre-line`; `line-height` as a length; `font-weight:
  normal`, `font-style: normal`, `text-decoration: none` switch inherited
  values off; `vertical-align: super / sub` on spans; images sized and
  centred from CSS (`width: 100%`, `display: block; margin: auto`).
- Layout: `rowSpan` — a cell merged down holds its columns in the rows
  below, spans their height (a taller one grows the last row), and the
  rows it binds move to the next page together.
- Layout: `CellStyle::$widthPt` / `$widthPercent` set the column width
  when the table has no `columnWidthsPt`; `TableStyle::$autoLayout` sizes
  columns by their content; `TableStyle::$indentLeftPt` / `$indentRightPt`;
  `TableStyle::$borders` is drawn around the table (on each page it
  spans); `Row::$minHeightPt`, `Row::$cantSplit`, `CellStyle::$minHeightPt`;
  `Image::$widthPercent`.
- Layout: `RunStyle::$backgroundColor` and `$highlight` are painted under
  the text.

### Fixed
- A paragraph's padding was left out of its measured height, so in a
  table cell the next row was drawn over the paragraph's background.
  Padding and border now count in the measured height.
- `ParagraphStyle::$borders` were parsed from HTML but never drawn. The
  paragraph's background and border now stay inside its left/right
  indents (an HTML container's padding), and so does a block image
  (`Image::$indentLeftPt` / `$indentRightPt`): a `width: 100%` header in
  a padded section was as wide as the page.
- A heading inside a table cell measured zero height; a heading's runs
  lost their own font size to the level's default.
- HTML: the cells of the row after a `rowspan` slid under the merged
  cell; `&nbsp;` was collapsed into a plain space; a link dropped the
  size, font and weight around it; whitespace at the start of a block was
  shown as an indent.

### Changed
- HTML tables have no 6pt space after them unless the CSS sets a
  `margin-bottom`, and size their columns by content (`table-layout:
  auto`) unless `table-layout: fixed` is set — as browsers do. Tables
  built in PHP keep equal columns and the 6pt default.
- `<pre>` keeps its spaces and line breaks.

## [1.18.0] — 2026-10-16

### Added
- `CmsSigner` — a key behind software that returns a whole CMS rather than a
  bare signature value: CryptoPro CSP on the server (`CadesSignedData`,
  `cryptcp`), a signing service, an HSM front end. Passed as
  `SignatureConfig::$signer`, it signs in one call — `Pdf\Document::sign()`,
  a laid-out document's `signature:`, `PdfSigner::sign()` — with no
  `prepare()`/`complete()` by hand. Its CMS (DER or BER, detached or
  attached) is checked the way `PreparedSignature::complete()` checks one:
  it covers this document, it was made with the signer's certificate, it
  verifies; missing certificates are added and the config's timestamp
  authority timestamps it.

## [1.17.0] — 2026-10-14

### Added
- `SignatureReport::$untrustedTimestampTime` — the time stated by a signature
  timestamp that verifies but whose TSA does not chain to a trusted root.
  `timestampTime` stays reserved for a vouched-for time; before, such a
  timestamp left no trace but a warning, and a report could only say "no
  timestamp" for a signature that has one (freetsa.org's, unless its root is
  trusted).

## [1.16.1] — 2026-10-14

### Fixed
- An unreachable OCSP responder (a timeout, a refused connection) no longer
  stops `addValidationData()`: the transport's failure is an `LtvException`
  like an HTTP error, so the CRL is used instead. Seen with freetsa.org, whose
  OCSP responder does not answer while its CRL does.
- Transport failures are typed elsewhere too: an unreachable CRL is an
  `LtvException`, an unreachable timestamp authority a `TsaException`; an
  unreachable issuer URL moves on to the next one.

## [1.16.0] — 2026-10-13

### Added
- **Detached signatures** — `Signature\Cms\DetachedSignature`: `sign()`
  writes a CAdES-BES `.p7s` over any bytes (`signing-time`,
  `signing-certificate-v2`, chain embedded), CAdES-T with a timestamp
  authority; `verify()` checks one — digest, signature, certificate binding,
  timestamp, chain, revocation — into a `SignatureReport`, accepting DER,
  BER, base64 and PEM, attached signatures of the same content and
  signatures without signed attributes (with a warning);
  `addTimestamp()` timestamps a signature made elsewhere.

### Changed
- The CMS checks of `SignatureValidator` (digest, signature,
  `signing-certificate-v2`, signature timestamp, chain, revocation) moved to
  an internal class shared with `DetachedSignature`; results are unchanged.

## [1.15.1] — 2026-10-13

### Documentation
- Package description and keywords cover the signing features (PAdES, GOST,
  stamps, timestamps, LTV, validation).
- `docs/en/README.md` brought in line with the root README (signing
  features, signing guide link).
- Comparison tables (all languages): the single "PKCS#7 signing" row split
  into PKCS#7, PAdES, timestamps, visible stamps, certification, signing
  existing PDFs, signing outside the server, long-term validation and
  signature validation.
- Usage guides, viewer matrix, mpdf migration guide: signing wording and
  links updated; broken links fixed (merge design document path, two
  anchors in the German and Chinese usage guides).

## [1.15.0] — 2026-10-13

### Added
- **Signature validation** — `Signature\Validation\SignatureValidator`
  checks every signature and document timestamp: the byte range and its gap
  (against signature wrapping and universal forgery), the digest, the CMS
  signature and `signing-certificate-v2`, the signature timestamp, the chain
  to a `TrustStore` (each link verified, judged at the timestamped time),
  revocation from the DSS's OCSP responses and CRLs (their signatures
  verified) or a `RevocationSource`, and every change appended after it
  (against incremental-saving and shadow attacks), with DocMDP and FieldMDP
  enforced. `ValidationReport` / `SignatureReport` give the verdict and the
  reasons; `signedRevision()` returns the document as signed.
- **Filling signed forms** — `ExistingFormFiller::toBytes(incremental: true)`
  appends the changes instead of rewriting the file: signatures stay valid,
  a form-filling certification stays intact; refused under "no changes" and,
  for page changes, under any certification.
- **Signing and filling encrypted PDFs** — `PdfSigner`,
  `ExistingFormFiller` (incremental) and `SignatureValidator` take the
  password; the update is encrypted with the file's own key (RC4, AES-128,
  AES-256), the signature value left plain as the format requires.

### Fixed
- **Encrypted documents with embedded fonts were corrupt** — string
  encryption scanned the stream data too and replaced bytes of compressed
  fonts and images; non-ASCII text strings (hex: Cyrillic titles, bookmarks,
  field names) were not encrypted at all and read back as garbage.
- The reader no longer "decrypts" a signature dictionary's /Contents, which
  is never encrypted.

## [1.14.0] — 2026-10-13

### Added
- **Signing existing PDFs** — `Signature\PdfSigner` signs a PDF made
  elsewhere, or one already signed, as an incremental update: the original
  bytes stay untouched, so earlier signatures remain valid and signers can
  follow one another. Signs into a named or the first empty signature field,
  a field added with `addField()` (with a stamp), or a new invisible one;
  `withAppearance()` redraws the stamp of an existing field;
  `addEmptyField()` lays out fields for later signers; `signatureFields()`
  lists them; `prepare()` signs outside the server.
- **Certification (DocMDP) and field locks (FieldMDP)** —
  `SignatureConfig::$certification` (`DocMdpPermission::NoChanges`,
  `FormFilling`, `FormFillingAndAnnotations`) and `$lockFields`
  (`FieldLock::all()`, `only()`, `allExcept()`), for generated and existing
  documents. Certified documents accept signatures only in the empty fields
  they already have; "no changes" refuses further signatures.
- **Long-term validation (PAdES B-LT, B-LTA)** —
  `PdfSigner::addValidationData()` writes the document security store (DSS):
  every certificate of the document's signatures and timestamp tokens,
  missing issuers downloaded from AIA caIssuers, OCSP responses (CRL
  fallback). `PdfSigner::timestamp()` adds a document timestamp
  (`ETSI.RFC3161`). `Ltv\OcspClient`, `CrlFetcher`, `CertificateFetcher`,
  `RevocationSource` / `OnlineRevocationSource`, `ValidationData`.
- `Incremental\IncrementalUpdate` — appends changes to an existing PDF,
  following its cross-reference kind (table or stream).
- `Transport\HttpGetTransport` (implemented by `StreamHttpTransport`) for
  CRL and certificate downloads.
- Tests: all third-party fixtures signed incrementally; several signers;
  certification checked by pyHanko's modification analysis; the test PKI now
  has OCSP/CRL/caIssuers endpoints served offline (`openssl ocsp -index`);
  PAdES-LT and LTA validated by pyHanko offline in CI.

### Fixed
- **A `PdfImage` reused in a second document pointed at the wrong object**
  — its object number was cached on the instance, so a logo cached between
  requests vanished from the next document (or showed up as a font).
- **A `PdfFont` kept every finished document in memory** — it held a strong
  reference to each writer (~20 KB per document in a long-running worker).
  Both now cache per writer in a `WeakMap`.
- `/Reason`, `/Location`, `/Name` and `/ContactInfo` of a signature are
  written as text strings (UTF-16 for non-ASCII) — Cyrillic showed as
  mojibake in the signature panel.

## [1.13.0] — 2026-10-13

### Added
- **Signing outside the server (two-phase).** `Pdf\Document::prepareSignature()`
  (and `Document::prepareSignature()` for laid-out documents) emits the
  document with an empty signature and returns a `PreparedSignature`; the
  signer — CryptoPro in the browser, a token, an HSM, a signing service —
  works elsewhere, and `PreparedSignature::complete()` embeds the result.
  - `ExternalMode::Cms`: the signer gets the document digest
    (`documentDigest()` / `documentDigestHex()`) or the signed bytes
    (`signedContent()`) and returns a detached CMS.
  - `ExternalMode::RawSignature`: the library builds the signed attributes
    (`dataToSign()` / `hashToSign()`), the signer returns the raw value.
  - `complete()` checks the result first — the CMS covers this document, was
    made with the prepared certificate, verifies, fits the reserved space —
    adds missing certificates, and can add an RFC 3161 timestamp.
    `completeBase64()` takes base64 or PEM. GOST raw values in CryptoAPI's
    little-endian order are recognised. BER with indefinite lengths (what
    CryptoPro writes) is read and embedded as definite lengths; an attached
    CMS whose content is this document is made detached.
  - `toJson()` / `fromJson()` keep the state between requests.
  - `ExternalSigner` stands in for the absent key, with or without the
    certificate (the stamp shows it when given).
- Signing guide: signing outside the server, with a CryptoPro Browser
  plug-in sketch.

### Changed
- Signature placeholders are handled by one internal class shared by
  in-process and two-phase signing.

## [1.12.0] — 2026-10-13

### Added
- **PAdES signatures.** The CMS signature is now built by the library
  itself instead of `openssl_pkcs7_sign`: a detached `SignedData` with the
  `signing-certificate-v2` attribute binding the certificate, the signer's
  certificate chain embedded, and `/SubFilter /ETSI.CAdES.detached`
  (PAdES B-B). The pre-PAdES format stays available as
  `subFilter: SubFilter::Pkcs7Detached`.
- **Timestamps (PAdES B-T)** — `SignatureConfig::$timestampAuthority` takes
  a `Tsa\TsaClient`: an RFC 3161 client that timestamps the signature value
  and checks the answer (status, imprint, nonce). Basic auth, extra headers,
  policy OID, timeout; HTTP through `Transport\HttpTransport`, with a
  dependency-free `StreamHttpTransport` by default.
- **Keys** — `Signer` interface for keys held anywhere (token, HSM, signing
  service): the library hands it the signed attributes and takes back the
  signature value. `OpenSslSigner` signs with a PEM key or a PKCS#12
  container (`fromPkcs12()`), with its chain.
- **Algorithms** — RSA (PKCS#1 v1.5), ECDSA (P-256/384/521), and GOST
  R 34.10-2012 256/512 with Streebog when OpenSSL's gost engine is loaded;
  the digest follows the key. `docker/gost/` builds a PHP image with the
  engine; CI runs the GOST tests in it.
- `SignatureConfig::$certificateChainPem` — intermediate certificates for a
  PEM key.
- `SignatureConfig::$contentsReserveBytes` — the space for the signature is
  now estimated from the certificates, key and TSA, and can be overridden; an
  overflow error says how much is needed.
- Internal DER encoder/parser (`Signature\Asn1`) — no new dependencies.
- Tests: a committed test PKI (root → intermediate → RSA and ECDSA signers,
  TSA, PKCS#12; `tests/fixtures/pki/generate.sh`), a test TSA on
  `openssl ts -reply`, verification with `openssl cms -verify` up to the
  root and `openssl ts -verify`, and pyHanko in CI.

### Changed
- **The default signature format is PAdES (`ETSI.CAdES.detached`)** instead
  of `adbe.pkcs7.detached`.
- `SignatureConfig` takes the key as `signer:` or as PEM strings; `certPem`
  and `privateKeyPem` became optional (positional use is unchanged). The key
  is now read — and checked against the certificate — when the config is
  built, not when the document is written.
- The signing time in the CMS (`adbe.pkcs7.detached`) equals `signedAt`;
  before, `openssl_pkcs7_sign` wrote the time of writing, so a validator
  could show a different time than `/M` and the stamp.

## [1.11.0] — 2026-10-13

### Added
- **Signature stamps** — a signature field can show a stamp drawn from
  `SignatureAppearance`: a frame (border, rounded corners, background,
  padding) holding `TextBlock`, `ImageBlock` and `QrBlock` layers placed by
  fractional `Box`es. Text wraps and shrinks to fit; images keep their
  proportions and transparency; QR codes sit on a white ground with a
  quiet zone.
  - Placeholders filled when the document is signed: certificate subject
    (`{cn}`, `{org}`, `{email}`), `{serial}`, `{validFrom}`, `{validTo}`,
    `{issuer}`, the Russian qualified-certificate identifiers (`{inn}`,
    `{innle}`, `{ogrn}`, `{ogrnip}`, `{snils}`), `{signer}`, `{signedAt}`,
    `{reason}`, `{location}`, `{contact}`, and custom `variables`. A line
    whose placeholders all come out empty is dropped.
  - Presets: `electronicSignatureStamp()` (the customary electronic-signature
    mark, Russian or English captions, optional emblem), `simple()`,
    `imageOnly()`.
  - `CertificateInfo` reads what the stamp shows from an X.509 certificate.
- `Element\SignatureField` — a signature field in the layout flow, aligned
  like an image; its stamp text falls back to the document's default and bold
  fonts.
- `Page::addSignatureField()` — a signature field with an appearance; a
  zero-size field is an invisible signature.
- `repeatOnOtherPages` draws the stamp, as non-interactive artifact content,
  at the same position on every other page.
- `SignatureConfig::$fieldName` picks the field to sign among several; the
  others stay empty for later signers.
- Docs: signing guide (`docs/*/SIGNING.md`).
- Conformance: a signed PDF/A-2b document with a stamp is validated with
  veraPDF, and a signature-stamp page joins the visual regression set.

### Changed
- The signature widget carries `/F 4` (Print), so the stamp is printed.
- `NeedAppearances` is no longer written for signed or PDF/A documents —
  every widget has its own appearance stream, PDF/A forbids the flag, and a
  viewer redrawing appearances would disturb a signed file.
- The signing time is fixed once per write, so `/M` and the stamp agree.

### Fixed
- `toFile()` of a signed document failed with "/ByteRange placeholder not
  found": the file was opened write-only while signing reads the emitted
  bytes back. Files are opened read-write, and write-only streams take the
  buffered signing path.
- The signing example in the usage guide used a constructor argument and a
  form-field call that do not exist.

## [1.10.0] — 2026-10-01

### Added
- **`ExistingFormFiller` — fill, flatten and stamp a PDF form produced
  elsewhere** (contributed by Christiaan Baartse, #1). Reads the AcroForm's
  fields by fully-qualified name (`fields()`), sets text, check box, radio,
  combo and list box values (`setValue()`), bakes fields into the page
  (`flatten()`), and places an image on a page (`stampImage()`).
  - Filled fields get a generated appearance stream — the `/DA` font, size
    (auto size included), colour and alignment, the `/MK` background and
    border, comb cells, multi-line wrapping — so values show in every viewer.
  - Values the form's fonts cannot show are drawn with an embedded TrueType
    font: `useFont()`, `useFontProvider()`, or Liberation picked up
    automatically when `dskripchenko/php-pdf-fonts-liberation` is installed.
  - `flatten()` draws each widget's own appearance, as Acrobat does, so check
    marks, backgrounds and borders survive.
  - Values are validated: `/MaxLen`, radio and choice options; signature and
    push-button fields refuse a value.
  - Inherited page resources, the page's graphics state, `/Info`, `/ID`, the
    CropBox and `/Rotate` are respected; an XFA definition is dropped once
    values change.
- `ObjectImporter::withSource()` — import objects from a second document into
  a graph without losing the current source's deduplication.

## [1.9.4] — 2026-08-17

### Changed
- The code's comments are in English, and the documentation follows the shared
  layout: README and CHANGELOG at the root, the translations under `docs/`.
  Nothing the library does has changed — the tag is here so that "the tag is
  the state of the repository" keeps holding.

## [1.9.3] — 2026-08-06

### Fixed
- **Rendering the footnote zone could recurse until memory ran out.** The
  zone is drawn with ordinary paragraphs, and those may break the page —
  which asks for the page's footnotes again, with the same list still
  pending. A page is now marked as flushed before its notes are drawn.
  Shipped broken in 1.9.2: the package's own suite passed and the fault
  surfaced only in an application's, on a document whose zone overflowed.

## [1.9.2] — 2026-08-06

### Fixed
- **A footnote inside a table cell never reached the foot of the page.**
  Form-like documents keep their whole body in a table, so for them footnotes
  did not work at all. A cell is laid out more than once — measured, and
  re-laid when a row is split across pages — and the 1.7.2 attempt collected
  notes as they were met: the list grew on every pass, numbering drifted
  upward and a seven-page form ate half a gigabyte (reverted in 1.7.3).

  Numbers are now handed out in a single walk of the tree, in document order,
  and are keyed by the footnote element itself, so visiting a cell again
  changes nothing. Which notes belong to a page is decided by the markers
  actually painted on it rather than by a range in a running list — a range
  is what lost the cell's notes along with the cell's own layout context.

  Measured on the seven-page form that caused the revert: 0.7 s, peak memory
  87 MB.

## [1.9.1] — 2026-08-06

### Fixed
- **A paragraph opening with spaces ignored them.** Word processors place a
  heading in the middle of a line with a run of spaces rather than an
  alignment, and documents built that way are common — an insurance form in
  the corpus positions every one of its headings this way. Words are split on
  whitespace, so the run was discarded and the heading printed hard against
  the left margin. The opening whitespace is now measured and added to the
  first-line indent, the mechanism it stands for. Whitespace *between* words
  stays a separator: giving it width would change how every line breaks.

  Measured on the form: indentation now agrees with the reference PDF on 21
  of 33 matched lines, against 18 before.

## [1.9.0] — 2026-08-06

### Added
- **Custom `/Info` entries.** The metadata dictionary accepted only the six
  well-known fields, so an application's own document identifier had nowhere
  to live inside the file. ISO 32000-1 §14.3.3 allows extra keys and readers
  display them alongside the rest; any key beyond the standard set now goes
  through. Keys are validated against the PDF name grammar — a space or a
  delimiter would produce a dictionary readers cannot parse, and the damage
  would surface far from the call that caused it. Values follow the same
  encoding rule as the standard fields: non-ASCII is written as UTF-16BE.

## [1.8.6] — 2026-08-06

### Fixed
- **Non-ASCII text strings reached readers as mojibake.** Metadata, outline
  titles, form-field tooltips and alternate descriptions were written as
  literal strings, which a reader decodes as PDFDocEncoding — so a Cyrillic
  `/Info` Author came out as `ÐžÐžÐž` in Acrobat, Preview and `pdfinfo` alike.
  Text strings outside ASCII are now emitted as UTF-16BE with a byte order
  mark, the form ISO 32000-1 §7.9.2.2 defines for the purpose. URIs, file
  specifications and ICC profile names keep the literal form: they are ASCII
  strings by definition and UTF-16 would break them.

## [1.8.5] — 2026-08-05

### Fixed
- **A word too long for the line overflowed the column.** Greedy line breaking
  only ever broke *between* words, so a single token wider than the available
  width — a URL, a hash, a long number — was placed as it was and ran past the
  column: in a narrow table cell it printed over its neighbour. Such a word is
  now broken by characters, after a soft-hyphen point if the word offers one.
  Breaking a long string is better than losing the column boundary — which is
  what Word and browsers do too.

## [1.8.4] — 2026-08-05

### Fixed
- **A vector block took no room when measured.** `SvgElement` was missing from
  height measurement, so a table row holding a logo measured as zero-height and
  the drawing spilled out of it onto the row border. It now takes the height it
  declares.

## [1.8.3] — 2026-08-05

### Fixed
- **A table header row could be left alone at the foot of a page.** It
  explains columns that are no longer on that page, and repeats itself at the
  top of the next one. The header now moves to the next page together with the
  first row of data.

## [1.8.2] — 2026-08-05

### Fixed
- **A table row was torn apart instead of being moved.** When a row did not
  fit in what was left of the page, it was split — but the split is only a
  continuation if some cell has content on both sides. With a row like
  "directive on the left, its result on the right" the text fitted and the
  image did not, so the page ended with a row whose right cell was empty and
  the next page opened with a row whose left cell was empty: two halves of one
  statement, separated. A row that nobody continues is now moved whole.

## [1.8.1] — 2026-08-05

### Fixed
- **Encrypted streams declared the length of their plaintext.** Encryption
  grows a stream — AES prepends a 16-byte IV and pads to the block size — but
  the `/Length` written before encryption was left untouched. A reader that
  believes `/Length`, and §7.3.8.2 entitles it to, reads a truncated stream; a
  reader that falls back to scanning for `endstream` has to guess where the
  preceding EOL begins, and guesses wrong whenever the last ciphertext byte is
  a CR. That is roughly one encrypted file in 256, which is how it surfaced:
  an intermittently failing decryption test. `/Length` now counts the bytes
  actually written, and a test asserts that for every algorithm.

## [1.8.0] — 2026-08-05

### Fixed
- **A missing character was extracted as a different one.** Every character
  absent from the font is drawn with the single `.notdef` glyph, and a
  `ToUnicode` entry for it meant "this glyph reads as the last character that
  went missing". A form containing an empty ballot box ☐ and a check mark ✔
  extracted as two check marks — an unticked checkbox read as ticked changes
  what the document says. The glyph is no longer written into the extraction
  map: a missing character stays missing.

  Drawing is unchanged — a character the font lacks still comes out as
  `.notdef`. Substituting it from a fallback font per character is a separate
  matter: the chain currently picks one font per word.

## [1.7.3] — 2026-08-05

### Notes
- Reverted the 1.7.2 attempt to carry footnotes out of a table cell. A cell is
  rendered more than once (measurement, splitting a row across pages), and the
  list grew on every pass: a seven-page form ate half a gigabyte and brought
  the render down. Footnotes inside tables still do not reach the foot of the
  page; the note is left in the code so the next attempt starts from what is
  already known.

## [1.7.2] — 2026-08-05

### Fixed
- **A footnote inside a table cell was discarded.** A cell renders in its own
  layout context, and the footnotes collected there went away with it. They
  belong to the page, not to the cell, so they are now carried back out. In
  form-like documents, where the whole body lives inside a table, nothing
  reached the foot of the page at all. (Reverted in 1.7.3 — see the note.)

## [1.7.1] — 2026-08-05

### Changed
- `keepWithNext` now also applies when the heading is a list item: the
  requirement is then carried by the list, and the whole list moves with the
  block that follows it.

### Notes
- Tried and rejected: "a row that fits on an empty page moves there whole
  instead of being split" — that is what Word did in the case under study, but
  across the corpus it cost three pages of agreement and pushed two more
  documents apart. Word's rule here is more than a single condition; the note
  is left in the code so the attempt is not repeated.

## [1.7.0] — 2026-08-05

### Added
- **`ParagraphStyle::$keepWithNext` — "keep with next".** A section heading
  must not be left as the last line of a page: when nothing fits after it,
  both it and the following block move to the next page. The check looks at
  the first line of the next block rather than the whole of it — demanding the
  entire block would throw away half a page for the sake of a long table.

## [1.6.0] — 2026-08-05

### Added
- **Floating objects** — `Image::$outOfFlow`. Word anchors stamps and
  signatures to a paragraph and offsets them from the anchor point so they lie
  over finished text. Such an object takes no room in the flow: while it stood
  in the flow as a line of its own, the document was pushed apart by its
  height, which cost an insurance policy an extra page. A floating image is
  drawn where its offset puts it, the cursor returns to where it stood, and
  the surrounding text does not move. Ordinary images are unaffected.

## [1.5.3] — 2026-08-05

### Fixed
- **A forced page break inside a table cell is ignored, as Word ignores it.**
  Word cannot start a page in the middle of a cell, so such a break survives
  in the file as a leftover of editing and renders as nothing. Acting on it
  tore a real application form apart: the row carrying the break went to a
  page of its own and everything after it began on the following page. Two
  such leftovers turned a seven-page document into nine. Breaks outside tables
  work exactly as before.

## [1.5.2] — 2026-08-05

### Fixed
- **Fill colour leaked into all the text that followed.** `rg` is graphics
  state: it holds until the next `rg`, yet "no colour given" was treated as
  "emit nothing". Everything after a coloured heading was drawn in that
  colour — an insurance application came out entirely blue, tables included.
  Absence of a colour now means black rather than "keep whatever is set".

  The state comparison was fixed along the way: `0 === 0.0` is false in PHP,
  so black never matched the current state and `rg` was re-emitted on every
  line.

## [1.5.1] — 2026-08-05

### Fixed
- **Letter spacing from one run spread across the whole document.** `Tc` is a
  text-state parameter: it lives until the next `Tc`, not until the nearest
  `ET`. The operator was emitted only for non-zero spacing, so everything
  after a tracked heading inherited it. On a real contract the result was
  unreadable: lines grew wider than their column, overlapped each other and
  ran off the page — text extraction returned two-letter fragments.

  The value is now tracked and reset to zero explicitly. Documents without
  letter spacing are unchanged: they still carry no `Tc` at all (the initial
  value per the specification is zero).

- **Footers took more from the body than they occupied.** The decision to
  raise the body's bottom edge compared "footer height + 8pt gap" against the
  page's bottom margin, ignoring the distance from the page edge at which the
  footer is declared. That gap has no business in the comparison: the body was
  shrinking for footers that fit into the margin perfectly well. On A4 with a
  single-line footer this cost ~15pt of height on every page — roughly six
  lines over five pages, enough to spill onto an extra page.

## [1.5.0] — 2026-08-05

### Fixed
- **Image transparency was lost — backgrounds printed black.** The alpha
  channel of a PNG (colour types 6 and 4) was simply dropped on insertion, so
  transparent pixels came out in whatever colour sat underneath: a signature
  and a stamp from an imported document ended up as black rectangles. PDF
  keeps transparency in a separate object, so alpha is now extracted into an
  8-bit `DeviceGray` mask and attached through `/SMask`. A fully opaque image
  gets no mask — an extra object in the file buys nothing.

### Added
- `PdfImage::$alphaData` — the alpha channel as a separate stream.

## [1.4.0] — 2026-08-04

### Added
- **Header and footer distances** — `PageSetup::$headerDistancePt` /
  `$footerDistancePt`. Word declares them separately from the page margin
  (`w:header`, `w:footer`), while the engine pinned the header to the very
  edge of the sheet: an imported document drifted two centimetres up relative
  to the original. The default is 4pt — the previous behaviour. The header
  zone now accounts for both the distance and its own height, so a large
  `w:header` no longer lets body text run into the header.

## [1.3.1] — 2026-08-04

### Fixed
- Types in the `renderRow()` docblocks — 1.3.0 was tagged with static analysis
  failing (three `missingType.iterableValue`). A stale baseline entry was
  dropped along the way: it described the earlier, less precise type of a
  by-reference parameter.

## [1.3.0] — 2026-08-04

### Added
- **A tall table row is split across pages.** Previously a row that did not
  fit into the remainder of a page was carried over whole — leaving a
  half-empty sheet behind. In an insurance policy under study this produced a
  page with 40 words instead of seven hundred: a long block of terms lived
  inside a table. Cell content is now split at the page boundary, the table
  header repeats on the continuation, and the printed document matched the
  original in page count.

  The split goes by blocks: a cell is a list of paragraphs and the boundary
  runs between them. We do not descend into a paragraph. Header rows are not
  split (they are repeated anyway), nor are rows with a vertical merge
  (`rowSpan`) — the break would drift apart from the neighbouring cells. If
  not a single block fits into the remainder, the row is carried over whole as
  before: otherwise it would loop forever.

## [1.2.7] — 2026-08-04

### Fixed
- **Adjacent runs with no space between them drifted apart.** Words inside a
  run are cut into tokens and the line is reassembled with spaces — so a space
  that does not exist in the text appeared at the seam between two runs. Word
  splits a line at every change of formatting, which is why "(" and
  "залогодатель" arrive as separate runs, and the imported document printed as
  "СТРАХОВАТЕЛЬ ( залогодатель )". A seam where neither run ended or started
  with a space is now glued. Found by comparing print output against a
  reference document in printable: text agreement with the original rose from
  99.9% to 100%.

## [1.2.6] — 2026-07-31

### Performance
- **The subset's `post` table no longer carries glyph names** (format 3.0
  instead of 2.0). Names of every glyph in the source font survived into the
  subset in full and were its largest table: 26 KB out of 61 KB on Liberation
  Sans (43%), while the outlines took 6 KB.

  A PDF has no use for glyph names: rendering goes by identifier and text
  extraction by the `ToUnicode` CMap, which the emitter writes separately.

  **Consumer-side documents: 54.6 → 30.0 KB (an invoice), 73.6 → 49.0 KB (a
  nine-page contract).** Rendering is unchanged — verified pixel by pixel
  through poppler on every page: zero differences. Goldens need no
  regeneration.

  The table header (slant, underline, monospace flags) is preserved — format
  3.0 differs only in dropping the names.

## [1.2.5] — 2026-07-31

### Performance
- **Compressed font bodies are remembered.** Level-6 `gzcompress` over a font
  body is the most expensive part of embedding: a measurement in the consuming
  application showed 2.2 ms per document with two 62 KB fonts, while the whole
  render took 12.6 ms. The body is identical from document to document.

  **12.6 ms → 9.6 ms per document (−24%).** The document itself does not
  change: embedded font bodies are byte-for-byte the same.

  The key is a content hash (`xxh3`) rather than a file name: it stays correct
  for a subset, which differs per document. Hashing 62 KB costs microseconds
  against a millisecond of compression.

  The cache is bounded by `PdfFont::$compressedCacheLimit` (24 by default);
  `PdfFont::forgetCompressedCache()` clears it. As with the parsed-font cache
  from 1.2.4, the gain goes to long-lived processes — a queue worker and
  Octane.

## [1.2.4] — 2026-07-30

### Performance
- **Parsed fonts are remembered.** `TtfFile::fromFile()` read the whole file
  and parsed `cmap`/`hmtx`/`name`/`post` (plus `GPOS` on demand) every time,
  even though the result for a given file is always the same and the object is
  immutable.

  Measured in a consuming application that builds the renderer anew for each
  document: **14.6 → 8.4 ms on a light document (−43%)**, −4.7 ms on a heavy
  one. The gain goes to long-lived processes (queue worker, Octane); under
  process-per-request the state is discarded between requests anyway.

  The cache is bounded by `TtfFile::$cacheLimit` (24 fonts by default) — a
  process serving many clients with their own font sets would otherwise
  accumulate all of them. `TtfFile::forgetCache()` clears it.

  The key includes the file's modification time and size: a replaced font is
  parsed again.

## [1.2.3] — 2026-07-28

### Changed
- CI: PHP **8.5** added to the test matrix — the supported range
  (8.2–8.5) is now verified on every push.
- Docs: the Fonts highlights link to the Liberation bundle; the
  `fallbackFonts` entry moved to the release section it shipped in; the
  mpdf migration guide points its download row at the Laravel bridge.

## [1.2.2] — 2026-07-18

### Fixed
- **Aztec: letter case inverted after a shifted uppercase character.**
  From Lower mode, code 28 is U/S — an upper shift valid for one
  character — but the encoder latched its mode variable, desynchronizing
  from the decoder ("Aztec-Test" scanned as "Aztec-TEst"). Found while
  reviewing findings frozen in the PHPStan baseline; verified with ZXing.

### Changed
- PHPStan baseline halved (129 → 65 entries): every missing iterable
  value type got a precise annotation, dangling docblocks re-attached,
  and dead code removed (legacy watermark renderers, superseded bidi
  helpers, unused mode-switch constants). Remaining entries are reviewed
  false positives, documented as such.

## [1.2.1] — 2026-07-18

### Fixed
- **QR codes were unreadable by real scanners.** The 15-bit format
  information (ECC level + mask) was written LSB-first into both matrix
  copies — a mirrored, BCH-invalid codeword. Viewers happily rendered the
  symbol; every decoder (zbar, ZXing, phone cameras) rejected it. Both
  copies now follow ISO/IEC 18004 bit order; decodability is covered by a
  pure-PHP BCH round-trip test plus an end-to-end zbar test.
- **SVG gradients, `<style>` and `<use>` were silently ignored for
  real-world files.** SimpleXML's xpath() cannot address the default
  namespace, so any SVG declaring `xmlns="http://www.w3.org/2000/svg"`
  (i.e. all of them) lost its `//linearGradient`, `//style` and `//use`
  lookups — a gradient-filled rect rendered opaque black. The renderer
  now strips the default-namespace declaration before parsing.

- **Tagged PDF: marked-content sequences no longer nest.** Table/TR/L
  used to open their own MCID sequences around their children's — invalid
  per ISO 32000 and flagged by veraPDF. Grouping elements (Table, TR, L)
  now live purely in the structure tree with their children in /K; only
  leaves (P, TD, LI, Hn, Figure) own marked content. The ParentTree is
  now correctly indexed by MCID.

### Added
- **PDF/X-1a conformance reference** with a CMYK output intent
  (CGATS21_CRPC1 from the ICC registry, fetched on demand); the `/N` key
  of embedded output-intent profiles now derives from the profile's
  colour space instead of a hardcoded RGB. The X-1a checker enforces
  `/N 4` and bans device-RGB operators in content streams.
- `<rect rx="...">` rounded corners in the SVG renderer (solid fills and
  strokes; pattern fills stay square for now).
- Viewer matrix: automated pdf.js (Firefox engine) and Quartz (macOS
  Preview engine) columns; barcode decodability verified with zbar,
  ZXing and libdmtx against rendered output.

## [1.2.0] — 2026-07-17

### Fixed
- **`Engine(fallbackFonts: [...])` never actually switched fonts.** The
  draw-call batcher merged equal-styled words before font resolution, so
  mixed-script paragraphs rendered `.notdef` boxes for every character the
  main font lacked. Fonts are now resolved per word and participate in
  batch compatibility — Latin words draw with the main font, CJK/Arabic
  words with the first covering fallback, even inside a single `Run`.
  Latin-only output is byte-identical to before.

### Added
- **Migration compat facades** for the two libraries php-pdf replaces:
  `Compat\Mpdf` (`WriteHTML`/`AddPage`/`Output` F·S·D·I, metadata
  setters, `format`/`orientation`/margin config keys) and `Compat\Fpdi`
  (`setSourceFile`/`importPage`/`getTemplateSize`/`AddPage`/
  `useTemplate`/`Output` with FPDF top-left/mm coordinate conventions,
  both `Output` argument orders, escape hatches to the native
  `Pdf\Page`/`Pdf\Document`). Migration guides with full mapping tables:
  `docs/en/MIGRATION-FROM-MPDF.md`, `docs/en/MIGRATION-FROM-FPDI.md`.

### Changed
- Benchmark harness is now a first-class reproducible artifact:
  `composer bench` from a clean clone installs the pinned competitors
  (`scripts/bench/composer.lock`, committed), runs isolated-subprocess
  measurements and writes `results.{json,csv,md}` with the full
  environment (PHP, OS, exact library versions). The tables in
  `docs/en/BENCHMARKS.md` and all README performance sections are
  generated by the harness (`--docs`), never edited by hand.

## [1.1.3] — 2026-07-17

### Fixed
- **PDF/X output was missing Info keys and page boxes required by
  ISO 15930.** `/GTS_PDFXVersion` and `/ModDate` are now written to `/Info`,
  and pages default to a `/TrimBox` equal to the MediaBox when neither
  TrimBox nor ArtBox was set.
- **Rectangular DataMatrix symbols (ECC 200, e.g. 12×26) rendered
  distorted.** The 2D matrix renderer assumed square symbols and read past
  the module matrix; it now honours the symbol's true width × height.
- **TrueType `cmap` subtables are now merged instead of picking one.**
  A format 12 subtable is not necessarily a superset of format 4 (e.g.
  DroidSansFallback maps CJK only in format 12); previously half the font's
  coverage could be lost.

### Added
- Torture set (`examples/torture/`) — 11 worst-case documents (complex
  tables, Cyrillic/Greek, Arabic bidi, CJK, 5 barcode symbologies, charts,
  SVG, AcroForm, signature, PDF/A-2u, merge+stamp), smoke-rendered by
  poppler and Ghostscript in CI; manual cross-viewer checklist in
  `docs/en/VIEWER-MATRIX.md`.
- PDF/X structural validation in CI (Ghostscript + byte-level checks) and
  visual regression against golden renders; conformance checks moved to a
  dedicated `conformance` workflow with its own badge and
  `docs/en/CONFORMANCE.md`.
- `scripts/fetch-fonts.sh` now also fetches Amiri (Arabic) and
  DroidSansFallback (CJK) for the torture set.
- OpenSSL CLI verification of PKCS#7 signatures in the test suite.

## [1.1.2] — 2026-07-17

### Fixed
- **Stream `/Length` counted the EOL delimiter before `endstream` as data.**
  ToUnicode CMap streams (emitted for every embedded TrueType font) and
  uncompressed content streams declared a `/Length` one byte too long,
  violating PDF/A clause 6.1.7 (ISO 19005). Viewers tolerate it; validators
  don't. All PDF/A flavours (1b, 1a, 2b, 2u, 3b) now validate as compliant
  with veraPDF.

### Added
- PDF/A conformance validation in CI: reference documents with embedded
  fonts, Cyrillic text and tables are generated per flavour and checked with
  veraPDF on every push (`scripts/conformance/`, job `conformance-pdfa`);
  reports are published as workflow artifacts.
- Vendored sRGB2014 ICC profile (`resources/icc/sRGB2014.icc`) usable as an
  `/OutputIntents` profile for `PdfAConfig` / `PdfXConfig`.

## [1.1.1] — 2026-07-06

### Fixed
- **Merged pages rendered blank in real PDF viewers.** Imported streams
  (page `/Contents`, image XObjects, embedded fonts) were written as a
  reference-to-a-reference (`N 0 obj  M 0 R  endobj`). Lenient readers
  dereferenced the chain, but poppler / Ghostscript / Acrobat rejected
  `/Contents` with "weird page contents" and drew blank pages. Fixed; merge
  output is now verified against a real renderer (pdftotext) in the test suite.

## [1.1.0] — 2026-07-01

### PDF reading & merging (new)
- `ReaderDocument` — parse existing PDFs: classic `xref`, XRef streams,
  object streams, hybrid `/XRefStm`, incremental updates, and corrupt-xref
  recovery by scanning object headers.
- Stream filters: Flate (with PNG/TIFF predictors at any bit depth — 8- and
  16-bit, plus sub-byte for PNG), LZW, ASCII85, ASCIIHex, RunLength; image
  filters (DCT/JPX/CCITT/JBIG2) passed through verbatim.
- Standard-security-handler decryption: RC4 (40/128), AES-128 (AESV2),
  AES-256 (V5 R5/R6), with user- and owner-password support. Wrong/missing
  passwords fail fast with a clear error (validated against /U) instead of
  producing corrupt output.
- Validated against a third-party corpus (pdfTeX, LibreOffice, Google
  Docs/Skia, Qt/pdfkit, Ghostscript, ImageMagick, FPDF2, pypdf).
- Page-tree flattening with inherited MediaBox/CropBox/Rotate/Resources.
- `PdfMerger` — append/reorder whole or selected pages from multiple sources
  (`PdfSource::fromFile()` / `fromBytes()`, optional password).
- `PdfMerger::stamp()` — embed a source page onto output pages as a Form
  XObject with `Placement::fit()/stretch()/at()`; rotation baked via `/Matrix`.
- `PageImporter::intoDocument()` (FPDI-style) — import an existing PDF page
  into a freshly generated `Pdf\Document` as a Form XObject, so new php-pdf
  content can be drawn over/under it. `Document::registerImportedForm()`
  injects the foreign resource closure into the writer with references
  renumbered; `PdfValueSerializer` re-emits value trees with a ref map.
- `PdfMerger` carries page annotations and the document outline (bookmarks)
  by default, remapping internal links and named destinations to the new
  pages (dangling ones dropped, external URI links kept). Opt out with
  `withoutAnnotations()` / `withoutOutlines()`. Widget/popup annotations are
  not carried. `ReaderDocument::namedDestinations()` resolves the /Dests
  dictionary and /Names /Dests name tree.
- See [docs/en/MERGE.md](docs/en/MERGE.md). v1 does not carry over AcroForm
  fields or structure tags (annotations and outlines *are* carried).

### Fixed
- **PHP 8.2 support restored** — several classes used typed class constants
  (`const string …`), a PHP 8.3+ feature, so the package failed to parse on
  PHP 8.2 despite declaring `"php": "^8.2"`. The constant types were removed
  (behaviour unchanged).
- LZWDecode code-width widening was off by one (EarlyChange), corrupting LZW
  streams that grow past 9-bit codes (large images); now validated against a
  real ImageMagick/libtiff stream.

### Tooling
- GitHub Actions CI (PHP 8.2 / 8.3 / 8.4) running the test suite, plus a
  PHPStan (level 6) gate.

## [1.0.0] — 2026-05-15

Initial public release.

### PDF emission
- ISO 32000-1 (PDF 1.7) and ISO 32000-2 (PDF 2.0) output.
- Cross-reference: classic `xref…trailer` and XRef streams (PDF 1.5+).
- Object Streams for compact metadata-heavy documents.
- Balanced Page Tree for large documents.
- Page boxes (CropBox, BleedBox, TrimBox, ArtBox), rotation, tab order.
- Named destinations, multi-level outline (bookmarks panel).
- Page transitions and auto-advance.
- Optional Content Groups (layers) with default-visible toggling.
- Streaming output to a stream resource for large documents.

### HTML and CSS input
- `Document::fromHtml()` HTML5 parser entry point.
- Block tags: `<p>`, `<h1>`–`<h6>`, `<ul>`, `<ol>`, `<li>`, `<table>`,
  `<thead>`, `<tbody>`, `<tr>`, `<td>`, `<th>`, `<blockquote>`, `<hr>`,
  `<pre>`, `<dl>`, `<dt>`, `<dd>`.
- Inline tags: `<b>`/`<strong>`, `<i>`/`<em>`, `<u>`, `<s>`/`<del>`,
  `<sup>`, `<sub>`, `<br>`, `<a>`, `<span>`, `<code>`, `<kbd>`, `<samp>`,
  `<tt>`, `<var>`, `<mark>`, `<small>`, `<big>`, `<ins>`, `<cite>`,
  `<dfn>`, `<q>`, `<abbr>`.
- HTML5 semantic blocks: `<header>`, `<footer>`, `<nav>`, `<aside>`,
  `<main>`, `<article>`, `<section>`, `<figure>`, `<figcaption>`.
- Legacy tags: `<center>`, `<font>` (color, face, size 1–7).
- Inline CSS: color, background-color, font-family, font-size,
  font-weight, font-style, text-decoration, text-transform,
  text-align, margin, padding, border, line-height, text-indent.
- Table caption support, heading auto-anchors for internal hyperlinks.

### Layout
- Knuth–Plass box–glue–penalty line breaker with adaptive penalty for
  ragged-right output.
- Hyphenation hooks and soft-hyphen handling.
- Multi-column layout (`ColumnSet`) with column-first flow.
- Tables with rowspan/colspan, border collapse, double borders, border
  radius, border-spacing, cell padding.
- Headers, footers, watermarks (text and image, with opacity).
- Page setup: paper sizes (A0–A6, B0–B6, Letter, Legal, Executive,
  Tabloid, plus custom), portrait/landscape, margins, gutter.
- Section breaks with per-section page setup.
- Footnotes with page-bottom positioning.

### Text and fonts
- 14 Adobe base-14 standard fonts (WinAnsi encoding).
- TTF embedding with on-demand subsetting (CFF and TrueType outlines).
- Kerning, basic GSUB ligatures and single substitutions.
- ToUnicode CMap for searchable / copy-pasteable Cyrillic, Greek, CJK.
- Variable font instances (fvar, gvar, MVAR, HVAR, avar).
- Font fallback chain via `ChainedFontProvider`.
- Bidi text (UAX#9) with Arabic shaping and basic Indic shaping
  (Devanagari, Bengali, Gujarati).
- Vertical text writing mode.

### Barcodes
- Linear: Code 128 (A/B/C auto-switch + GS1-128), Code 39, Code 93,
  Code 11, Codabar, ITF/ITF-14, MSI Plessey, Pharmacode (Laetus),
  EAN-13/EAN-8 (with 2/5-digit add-ons), UPC-A, UPC-E.
- 2D: QR Code V1–V10 (Numeric, Alphanumeric, Byte, Kanji, ECI,
  Structured Append, FNC1 GS1/AIM), Data Matrix ECC 200 (all
  standard sizes incl. 144×144, rectangular variants, 6 modes,
  Macro 05/06, GS1, ECI), PDF417 (Byte/Text/Numeric, Macro, GS1, ECI),
  Aztec Compact 1–4L + Full 5–32L (Structured Append, FLG/ECI).
- QR convenience factories: vCard 3.0, WiFi Joinware, RFC 6068 mailto.

### Charts
- BarChart, LineChart, PieChart, AreaChart (stacked or independent),
  DonutChart, GroupedBarChart, StackedBarChart, MultiLineChart,
  ScatterChart.
- Configurable axis titles, label rotation, grid lines, legends,
  smoothing.

### Forms and interactivity
- AcroForm widgets: text (single/multiline/password), checkbox, radio
  group, combo box, list box, push/submit/reset buttons, signature.
- AcroForm appearance streams (NeedAppearances + per-widget /AP).
- Per-field JavaScript actions: keystroke, validate, calculate, format,
  click. Document-level WillClose/WillSave/DidSave/WillPrint/DidPrint
  actions; page open/close actions.
- Markup annotations: Text, Highlight, Underline, StrikeOut, FreeText,
  Square, Circle, Line, Stamp, Ink, Polygon, PolyLine.
- Hyperlink kinds: URI, named destination, JavaScript, Launch, Dest.

### Security
- Encryption: RC4-128 (V2 R3), AES-128 (V4 R4 / CFM AESV2),
  AES-256 (V5 R5 Adobe Supplement / CFM AESV3), AES-256 R6 (ISO
  32000-2 / PDF 2.0 Algorithm 2.B iterative hash).
- Permission bits (printing, copying, modifying, annotating,
  filling forms, accessibility, assembly, high-quality printing).
- Encrypted strings + encrypted streams + encrypted Catalog.

### Digital signing
- PKCS#7 detached signing with `openssl_pkcs7_sign`.
- Signed-at timestamp, signer name, reason, location, contact info.
- /ByteRange auto-patching with placeholder /Contents (16384 hex zeros).
- SigFlags 3 (SignaturesExist | AppendOnly).

### Print and accessibility
- PDF/A-1b, PDF/A-1a, PDF/A-2u conformance with embedded sRGB ICC.
- PDF/X-1a, PDF/X-3, PDF/X-4 with /OutputIntent /S /GTS_PDFX.
- Tagged PDF with StructTreeRoot, MCID marking, custom RoleMap,
  /StructParent annotation linking, /ParentTree number tree.
- /Lang attribute, /MarkInfo, /ViewerPreferences (HideToolbar,
  HideMenubar, FitWindow, CenterWindow, DisplayDocTitle, Direction,
  PrintScaling, Duplex).
- Page labels (decimal, Roman upper/lower, alpha upper/lower) with
  per-range prefixes and starting numbers.

### Image and graphics
- JPEG, PNG (8-bit truecolor + 8-bit palette + alpha via SMask).
- Identity-dedup by content hash (same image used N times = one XObject).
- Color spaces: DeviceRGB, DeviceCMYK, DeviceGray.
- ExtGState: opacity, blend mode, line styles.
- Patterns: tiling (Type 1) and shading (Type 2 axial / Type 3 radial,
  stitching functions for multi-stop gradients).
- Form XObjects (reusable content streams referenced by `/Do`).
- Clipping paths, transforms (q…Q with cm), text rendering modes.

### Math and SVG
- LaTeX subset rendering: fractions, sqrt, super/subscript, big
  operators (sum, product, integral), matrices (pmatrix, bmatrix,
  vmatrix), multi-line environments (align, gather, cases).
- Inline SVG embedding via `<svg>` in HTML or `SvgElement`. Paths,
  shapes, gradients, transforms, `<use>`/`<defs>`, CSS class styling.

### Embedded files
- File attachments via `Document::attachFile()` — visible in the
  reader's attachments panel.

### Quality
- 1977 tests, ~119k assertions, all passing.
- Pure PHP — no shell-outs, no native extensions beyond `ext-mbstring`,
  `ext-zlib`, `ext-dom`, plus `ext-openssl` when AES or PKCS#7 is used.
- PHP 8.2+, strict types throughout.

[1.1.1]: https://github.com/dskripchenko/php-pdf/releases/tag/v1.1.1
[1.1.0]: https://github.com/dskripchenko/php-pdf/releases/tag/v1.1.0
[1.0.0]: https://github.com/dskripchenko/php-pdf/releases/tag/v1.0.0
