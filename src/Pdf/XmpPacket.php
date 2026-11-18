<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

/**
 * An XMP metadata packet built from the document's /Info values, plus the
 * identification schemas of the standards it claims (pdfaid, pdfuaid).
 *
 * PDF/A and PDF/UA both require the /Info entries and their XMP
 * properties to be equivalent, so the packet takes the /Info values as
 * they are written: Title, Author, Subject, Keywords, Creator, Producer,
 * CreationDate and ModDate (dates in PDF form). Empty values are left out.
 * Without a CreationDate the current time is used.
 *
 * @internal
 */
final class XmpPacket
{
    public const NS_PDFAID = 'http://www.aiim.org/pdfa/ns/id/';

    public const NS_PDFUAID = 'http://www.aiim.org/pdfua/ns/id/';

    private const PDFUAID_EXTENSION = <<<'XMP'

    <rdf:Description rdf:about=""
        xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/"
        xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#"
        xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">
      <pdfaExtension:schemas>
        <rdf:Bag>
          <rdf:li rdf:parseType="Resource">
            <pdfaSchema:schema>PDF/UA Universal Accessibility Schema</pdfaSchema:schema>
            <pdfaSchema:namespaceURI>http://www.aiim.org/pdfua/ns/id/</pdfaSchema:namespaceURI>
            <pdfaSchema:prefix>pdfuaid</pdfaSchema:prefix>
            <pdfaSchema:property>
              <rdf:Seq>
                <rdf:li rdf:parseType="Resource">
                  <pdfaProperty:name>part</pdfaProperty:name>
                  <pdfaProperty:valueType>Integer</pdfaProperty:valueType>
                  <pdfaProperty:category>internal</pdfaProperty:category>
                  <pdfaProperty:description>Part of ISO 14289 the document conforms to</pdfaProperty:description>
                </rdf:li>
              </rdf:Seq>
            </pdfaSchema:property>
          </rdf:li>
        </rdf:Bag>
      </pdfaExtension:schemas>
    </rdf:Description>
XMP;

    /**
     * @param  array<string, string>  $info  /Info values
     * @param  array<string, string>  $fallback  Title or Author to use when $info has none
     * @param  array<string, array<string, string>>  $identification  prefix → [property → value], e.g. ['pdfuaid' => ['part' => '1']]
     */
    public static function build(array $info, string $producer, array $fallback = [], array $identification = []): string
    {
        $value = static fn (string $key): string => self::xmlEscape($info[$key] ?? $fallback[$key] ?? '');
        $created = isset($info['CreationDate'])
            ? self::xmpDate($info['CreationDate'])
            : (new \DateTimeImmutable)->format('Y-m-d\TH:i:sP');
        $modified = isset($info['ModDate']) ? self::xmpDate($info['ModDate']) : $created;

        $properties = [];
        if (($title = $value('Title')) !== '') {
            $properties[] = "<dc:title><rdf:Alt><rdf:li xml:lang=\"x-default\">$title</rdf:li></rdf:Alt></dc:title>";
        }
        if (($author = $value('Author')) !== '') {
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
        $properties[] = '<pdf:Producer>'.self::xmlEscape($info['Producer'] ?? $producer).'</pdf:Producer>';
        $properties[] = "<xmp:CreateDate>$created</xmp:CreateDate>";
        $properties[] = "<xmp:ModifyDate>$modified</xmp:ModifyDate>";

        $namespaces = '';
        foreach ($identification as $prefix => $values) {
            $uri = match ($prefix) {
                'pdfaid' => self::NS_PDFAID,
                'pdfuaid' => self::NS_PDFUAID,
                default => throw new \InvalidArgumentException("Unknown XMP identification schema: $prefix"),
            };
            $namespaces .= "\n        xmlns:$prefix=\"$uri\"";
            foreach ($values as $property => $v) {
                $properties[] = "<$prefix:$property>".self::xmlEscape($v)."</$prefix:$property>";
            }
        }
        $body = implode("\n      ", $properties);
        // PDF/A accepts only predefined XMP schemas unless an extension
        // schema describes the others (ISO 19005-2 §6.6.2.3.2); pdfuaid
        // isn't predefined, so a PDF/A + PDF/UA packet declares it.
        $extension = isset($identification['pdfaid'], $identification['pdfuaid']) ? self::PDFUAID_EXTENSION : '';

        return <<<XMP
<?xpacket begin="\u{FEFF}" id="W5M0MpCehiHzreSzNTczkc9d"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description rdf:about=""
        xmlns:dc="http://purl.org/dc/elements/1.1/"
        xmlns:pdf="http://ns.adobe.com/pdf/1.3/"
        xmlns:xmp="http://ns.adobe.com/xap/1.0/"$namespaces>
      $body
    </rdf:Description>$extension
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
