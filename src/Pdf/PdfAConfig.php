<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

/**
 * PDF/A archival conformance configuration (ISO 19005).
 *
 * Variants:
 *  - PDF/A-1 (parts A and B) — long-term archival baseline
 *  - PDF/A-2, PDF/A-3 — adds Unicode (U) plus newer PDF features
 *
 * Conformance levels:
 *  - B (basic) — preserves visual appearance
 *  - A (accessible) — requires Tagged PDF (auto-enabled)
 *  - U (Unicode, parts 2-3 only) — full Unicode mapping
 *
 * Emission applies:
 *  - PDF version 1.4
 *  - /Metadata stream (XMP RDF) in Catalog
 *  - /OutputIntents array with embedded ICC profile
 *  - /Lang entry in Catalog
 *  - Disables encryption (throws on `encrypt()` after `enablePdfA()`)
 *
 * Caller must supply a path to a valid sRGB ICC profile. Common sources:
 *  - macOS: /System/Library/ColorSync/Profiles/sRGB Profile.icc
 *  - Linux: /usr/share/color/icc/sRGB.icc
 *  - W3C / ICC.org distribute the ~3KB sRGB v2 IEC61966-2.1 profile
 *
 * Not enforced (violations break PDF/A but emission still succeeds):
 *  - Embedded fonts mandatory (the standard 14 fonts fail validation)
 *  - No transparency (no ExtGState /ca < 1.0)
 *  - No JavaScript or external links to non-standard URIs
 *  - No /EmbeddedFiles
 */
final readonly class PdfAConfig
{
    public const PART_1 = 1;

    public const PART_2 = 2;

    public const PART_3 = 3;

    public const CONFORMANCE_A = 'A';   // accessible (tagged)

    public const CONFORMANCE_B = 'B';   // basic

    public const CONFORMANCE_U = 'U';   // unicode (parts 2-3)

    public function __construct(
        public string $iccProfilePath,
        public string $iccProfileName = 'sRGB IEC61966-2.1',
        public string $lang = 'en',
        public string $title = '',
        public string $author = '',
        public int $part = self::PART_1,
        public string $conformance = self::CONFORMANCE_B,
    ) {
        if (! is_readable($iccProfilePath)) {
            throw new \InvalidArgumentException("ICC profile not readable: $iccProfilePath");
        }
        if (! in_array($part, [self::PART_1, self::PART_2, self::PART_3], true)) {
            throw new \InvalidArgumentException('PDF/A part must be 1, 2, or 3');
        }
        $validConformance = match ($part) {
            self::PART_1 => [self::CONFORMANCE_A, self::CONFORMANCE_B],
            self::PART_2, self::PART_3 => [self::CONFORMANCE_A, self::CONFORMANCE_B, self::CONFORMANCE_U],
        };
        if (! in_array($conformance, $validConformance, true)) {
            throw new \InvalidArgumentException("PDF/A-$part doesn't support conformance $conformance");
        }
    }

    public function iccProfileBytes(): string
    {
        return (string) file_get_contents($this->iccProfilePath);
    }

    /**
     * Render XMP metadata stream (XML with PDF/A + Dublin Core namespaces).
     *
     * $info holds the document's /Info values (Title, Author, Subject,
     * Keywords, Creator, Producer, CreationDate, ModDate, dates in PDF form):
     * PDF/A requires each to match its XMP property, so they take precedence
     * over this config's title, author and $producer. Empty values are left
     * out. Without a CreationDate the current time is used.
     *
     * @param  array<string, string>  $info
     */
    public function xmpMetadata(string $producer = 'dskripchenko/php-pdf', array $info = []): string
    {
        $value = static fn (string $key, string $fallback = ''): string => self::xmlEscape($info[$key] ?? $fallback);
        $created = isset($info['CreationDate'])
            ? self::xmpDate($info['CreationDate'])
            : (new \DateTimeImmutable)->format('Y-m-d\TH:i:sP');
        $modified = isset($info['ModDate']) ? self::xmpDate($info['ModDate']) : $created;

        $properties = [];
        if (($title = $value('Title', $this->title)) !== '') {
            $properties[] = "<dc:title><rdf:Alt><rdf:li xml:lang=\"x-default\">$title</rdf:li></rdf:Alt></dc:title>";
        }
        if (($author = $value('Author', $this->author)) !== '') {
            $properties[] = "<dc:creator><rdf:Seq><rdf:li>$author</rdf:li></rdf:Seq></dc:creator>";
        }
        if (($subject = $value('Subject')) !== '') {
            $properties[] = "<dc:description><rdf:Alt><rdf:li xml:lang=\"x-default\">$subject</rdf:li></rdf:Alt></dc:description>";
        }
        if (($keywords = $value('Keywords')) !== '') {
            $properties[] = "<pdf:Keywords>$keywords</pdf:Keywords>";
        }
        if (($creator = $value('Creator')) !== '') {
            $properties[] = "<xmp:CreatorTool>$creator</xmp:CreatorTool>";
        }
        $properties[] = '<pdf:Producer>'.$value('Producer', $producer).'</pdf:Producer>';
        $properties[] = "<xmp:CreateDate>$created</xmp:CreateDate>";
        $properties[] = "<xmp:ModifyDate>$modified</xmp:ModifyDate>";
        $properties[] = "<pdfaid:part>{$this->part}</pdfaid:part>";
        $properties[] = "<pdfaid:conformance>{$this->conformance}</pdfaid:conformance>";
        $body = implode("\n      ", $properties);

        return <<<XMP
<?xpacket begin="\u{FEFF}" id="W5M0MpCehiHzreSzNTczkc9d"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description rdf:about=""
        xmlns:dc="http://purl.org/dc/elements/1.1/"
        xmlns:pdf="http://ns.adobe.com/pdf/1.3/"
        xmlns:xmp="http://ns.adobe.com/xap/1.0/"
        xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/">
      $body
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
<?xpacket end="w"?>
XMP;
    }

    /**
     * A PDF date (D:YYYYMMDDHHmmSS with an optional Z or +HH'mm' offset,
     * later fields optional) as an XMP date, the same instant.
     */
    private static function xmpDate(string $pdfDate): string
    {
        if (preg_match("/^D:(\\d{4})(\\d{2})?(\\d{2})?(\\d{2})?(\\d{2})?(\\d{2})?(?:(Z)|([+-])(\\d{2})'?(\\d{2})?'?)?\$/", $pdfDate, $m) !== 1) {
            return $pdfDate;
        }
        $offset = match (true) {
            ($m[8] ?? '') !== '' => $m[8].$m[9].':'.(($m[10] ?? '') !== '' ? $m[10] : '00'),
            default => '+00:00',
        };

        return sprintf(
            '%s-%s-%sT%s:%s:%s%s',
            $m[1],
            ($m[2] ?? '') !== '' ? $m[2] : '01',
            ($m[3] ?? '') !== '' ? $m[3] : '01',
            ($m[4] ?? '') !== '' ? $m[4] : '00',
            ($m[5] ?? '') !== '' ? $m[5] : '00',
            ($m[6] ?? '') !== '' ? $m[6] : '00',
            $offset,
        );
    }

    private static function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
