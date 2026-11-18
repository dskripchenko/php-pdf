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
     * $pdfUaPart adds the PDF/UA identification (pdfuaid:part) for a
     * document that is both PDF/A and PDF/UA.
     *
     * @param  array<string, string>  $info
     */
    public function xmpMetadata(string $producer = 'dskripchenko/php-pdf', array $info = [], ?int $pdfUaPart = null): string
    {
        $identification = ['pdfaid' => ['part' => (string) $this->part, 'conformance' => $this->conformance]];
        if ($pdfUaPart !== null) {
            $identification['pdfuaid'] = ['part' => (string) $pdfUaPart];
        }

        return XmpPacket::build(
            $info,
            $producer,
            ['Title' => $this->title, 'Author' => $this->author],
            $identification,
        );
    }
}
