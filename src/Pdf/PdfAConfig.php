<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

/**
 * Phase 47: PDF/A-1b compliance configuration.
 *
 * ISO 19005-1:2005 Level B (basic) — preserves visual appearance for
 * long-term archival.
 *
 * Requirements applied при emission:
 *  - PDF version downgraded к 1.4.
 *  - /Metadata stream (XMP RDF) added к Catalog.
 *  - /OutputIntents array с embedded ICC profile.
 *  - /Lang entry в Catalog.
 *  - Encryption disabled (throws if encrypt() called).
 *  - File ID array (already emit'ится unconditionally).
 *
 * Caller must provide path к valid sRGB ICC profile. Common locations:
 *  - macOS: /System/Library/ColorSync/Profiles/sRGB Profile.icc
 *  - Linux: /usr/share/color/icc/sRGB.icc
 *  - or download sRGB v2 IEC61966-2.1 profile (~3KB) from W3C / ICC.org.
 *
 * Not enforced (compliance violation if violated):
 *  - Embedded fonts mandatory (standard 14 fonts могут fall validation).
 *  - No transparency (no ExtGState /ca < 1.0).
 *  - No JavaScript / external links к non-standard URIs.
 *  - No /EmbeddedFiles.
 */
final readonly class PdfAConfig
{
    public const PART_1 = 1;

    public const PART_2 = 2;

    public const PART_3 = 3;

    public const CONFORMANCE_A = 'A';   // accessible (tagged)

    public const CONFORMANCE_B = 'B';   // basic

    public const CONFORMANCE_U = 'U';   // unicode (PDF/A-2 и 3)

    public function __construct(
        public string $iccProfilePath,
        public string $iccProfileName = 'sRGB IEC61966-2.1',
        public string $lang = 'en',
        public string $title = '',
        public string $author = '',
        // Phase 103: PDF/A part и conformance level.
        public int $part = self::PART_1,
        public string $conformance = self::CONFORMANCE_B,
    ) {
        if (! is_readable($iccProfilePath)) {
            throw new \InvalidArgumentException("ICC profile not readable: $iccProfilePath");
        }
        if (! in_array($part, [self::PART_1, self::PART_2, self::PART_3], true)) {
            throw new \InvalidArgumentException('PDF/A part must be 1, 2, или 3');
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
     * Renders XMP metadata stream (XML с PDF/A + Dublin Core namespaces).
     *
     * Часть body required для PDF/A-1b — pdfaid:part=1, conformance=B.
     */
    public function xmpMetadata(string $producer = 'dskripchenko/php-pdf'): string
    {
        $created = (new \DateTimeImmutable)->format('Y-m-d\TH:i:sP');
        $title = self::xmlEscape($this->title);
        $author = self::xmlEscape($this->author);
        $producer = self::xmlEscape($producer);

        return <<<XMP
<?xpacket begin="\u{FEFF}" id="W5M0MpCehiHzreSzNTczkc9d"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description rdf:about=""
        xmlns:dc="http://purl.org/dc/elements/1.1/"
        xmlns:pdf="http://ns.adobe.com/pdf/1.3/"
        xmlns:xmp="http://ns.adobe.com/xap/1.0/"
        xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/">
      <dc:title><rdf:Alt><rdf:li xml:lang="x-default">$title</rdf:li></rdf:Alt></dc:title>
      <dc:creator><rdf:Seq><rdf:li>$author</rdf:li></rdf:Seq></dc:creator>
      <pdf:Producer>$producer</pdf:Producer>
      <xmp:CreateDate>$created</xmp:CreateDate>
      <xmp:ModifyDate>$created</xmp:ModifyDate>
      <pdfaid:part>{$this->part}</pdfaid:part>
      <pdfaid:conformance>{$this->conformance}</pdfaid:conformance>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
<?xpacket end="w"?>
XMP;
    }

    private static function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
