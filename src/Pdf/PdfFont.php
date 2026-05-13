<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;

/**
 * Builds a Type0 composite font в PDF из TtfFile.
 *
 * Структура PDF font objects (ISO 32000-1 §9.7):
 *
 *   Font (Type0)
 *     /BaseFont /<PostScriptName>
 *     /Encoding /Identity-H        ← 2-byte glyph IDs as character codes
 *     /DescendantFonts [<<CIDFontType2>>]
 *     /ToUnicode <<CMap stream>>   ← для copy-paste correctness
 *
 *   CIDFontType2 (descendant)
 *     /BaseFont /<PostScriptName>
 *     /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>
 *     /CIDToGIDMap /Identity        ← CID == glyph ID напрямую
 *     /FontDescriptor <<...>>
 *     /W [<gid> [<width>]]
 *
 *   FontDescriptor
 *     /FontName /<PostScriptName>
 *     /Flags <integer>              ← bit-flags: serif, fixed-pitch, italic, etc.
 *     /FontBBox [xMin yMin xMax yMax]
 *     /Ascent / /Descent / /CapHeight / /ItalicAngle / /StemV
 *     /FontFile2 <<embedded TTF stream>>
 *
 *   FontFile2 stream
 *     /Length <compressed length>
 *     /Length1 <original TTF length>
 *
 * Encoding text: 2-byte big-endian glyph IDs в hex string
 *   `<00480065006C006C006F>` для "Hello"
 *
 * Coords для widths: PDF convention — 1000/em. TTF FUnit → PDF unit
 * пересчёт: `PDF_width = TTF_width * 1000 / unitsPerEm`.
 */
final class PdfFont
{
    /** @var array<int, int>  glyphId → unicodeCodepoint (для ToUnicode CMap) */
    private array $usedGlyphs = [];

    private ?int $fontObjectId = null;

    public function __construct(
        private readonly TtfFile $ttf,
    ) {}

    /**
     * Регистрирует font в Writer'е. Создаёт все необходимые объекты,
     * возвращает Type0 font dict object ID — этот ID используется
     * в page Resources /Font dict.
     */
    public function registerWith(Writer $writer): int
    {
        if ($this->fontObjectId !== null) {
            return $this->fontObjectId;
        }

        // 1. Embed TTF binary как FontFile2 stream object.
        $fontBytes = $this->ttf->rawBytes();
        $fontFileObjId = $writer->addObject(sprintf(
            "<< /Length %d /Length1 %d >>\nstream\n%s\nendstream",
            strlen($fontBytes),
            strlen($fontBytes),
            $fontBytes,
        ));

        // 2. FontDescriptor.
        $descriptorBody = $this->buildFontDescriptor($fontFileObjId);
        $descriptorId = $writer->addObject($descriptorBody);

        // 3. CIDFontType2 descendant.
        $cidFontBody = $this->buildCIDFont($descriptorId);
        $cidFontId = $writer->addObject($cidFontBody);

        // 4. ToUnicode CMap (placeholder — filled later когда usedGlyphs known).
        //    Но для POC мы регистрируем все glyph'ы используемые в content
        //    stream'ах ДО registerWith(). См. addUsedChar().
        $toUnicodeId = $writer->addObject($this->buildToUnicodeCMap());

        // 5. Top-level Type0 font dict.
        $type0Body = sprintf(
            '<< /Type /Font /Subtype /Type0 /BaseFont /%s '
            .'/Encoding /Identity-H /DescendantFonts [%d 0 R] '
            .'/ToUnicode %d 0 R >>',
            $this->ttf->postScriptName(),
            $cidFontId,
            $toUnicodeId,
        );
        $this->fontObjectId = $writer->addObject($type0Body);

        return $this->fontObjectId;
    }

    /**
     * Кодирует UTF-8 текст в hex glyph-ID string для использования с
     * Tj/TJ операторами при Identity-H encoding.
     *
     * Также накапливает usedGlyphs для построения ToUnicode CMap.
     * Поэтому encodeText() должен вызываться ДО registerWith() — иначе
     * ToUnicode CMap получится пустой.
     */
    public function encodeText(string $utf8): string
    {
        $hex = '';
        $i = 0;
        $len = strlen($utf8);
        while ($i < $len) {
            // Декод UTF-8 codepoint вручную (без mb_str_split — поддерживаем
            // emoji/surrogate edge-cases на минимуме).
            $b1 = ord($utf8[$i]);
            if ($b1 < 0x80) {
                $cp = $b1;
                $i++;
            } elseif (($b1 & 0xE0) === 0xC0) {
                $cp = (($b1 & 0x1F) << 6) | (ord($utf8[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($b1 & 0xF0) === 0xE0) {
                $cp = (($b1 & 0x0F) << 12)
                    | ((ord($utf8[$i + 1]) & 0x3F) << 6)
                    | (ord($utf8[$i + 2]) & 0x3F);
                $i += 3;
            } else {
                // 4-byte UTF-8 (supplementary plane); для POC просто кодируем
                // как-есть с предположением что font не покрывает.
                $cp = (($b1 & 0x07) << 18)
                    | ((ord($utf8[$i + 1]) & 0x3F) << 12)
                    | ((ord($utf8[$i + 2]) & 0x3F) << 6)
                    | (ord($utf8[$i + 3]) & 0x3F);
                $i += 4;
            }

            $gid = $this->ttf->glyphIdForChar($cp);
            $this->usedGlyphs[$gid] = $cp;
            $hex .= sprintf('%04X', $gid);
        }

        return '<'.$hex.'>';
    }

    /**
     * Width одного UTF-8 character'а в PDF text-space units (1000/em).
     * Полезно для measure / layout — Phase 3 будет переиспользовать.
     */
    public function widthOfCharPdfUnits(int $unicodeCodepoint): int
    {
        $gid = $this->ttf->glyphIdForChar($unicodeCodepoint);
        $fontUnits = $this->ttf->advanceWidth($gid);

        return (int) round($fontUnits * 1000 / $this->ttf->unitsPerEm());
    }

    /**
     * FontDescriptor — содержит metrics + reference на embedded font file.
     */
    private function buildFontDescriptor(int $fontFileObjId): string
    {
        $upem = $this->ttf->unitsPerEm();
        $toPdf = static fn (int $fu): int => (int) round($fu * 1000 / $upem);

        $bbox = $this->ttf->bbox();
        $bboxStr = sprintf('[%d %d %d %d]',
            $toPdf($bbox[0]),
            $toPdf($bbox[1]),
            $toPdf($bbox[2]),
            $toPdf($bbox[3]),
        );

        // PDF Flags (ISO 32000-1 Table 123). Bit-positions начинаются с 1.
        //   bit 1: FixedPitch
        //   bit 2: Serif
        //   bit 3: Symbolic (любой non-Adobe-Latin charset)
        //   bit 4: Script
        //   bit 6: Nonsymbolic
        //   bit 7: Italic
        //   bit 17: AllCap
        //   bit 18: SmallCap
        //   bit 19: ForceBold
        $flags = 0;
        if ($this->ttf->isFixedPitch()) {
            $flags |= 1; // bit 1
        }
        $flags |= 32; // bit 6 — Nonsymbolic (наш default; работает с Latin + Cyrillic)
        if ($this->ttf->italicAngle() !== 0) {
            $flags |= 64; // bit 7
        }

        return sprintf(
            '<< /Type /FontDescriptor /FontName /%s /Flags %d '
            .'/FontBBox %s /ItalicAngle %d '
            .'/Ascent %d /Descent %d /CapHeight %d /StemV 80 '
            .'/FontFile2 %d 0 R >>',
            $this->ttf->postScriptName(),
            $flags,
            $bboxStr,
            $this->ttf->italicAngle(),
            $toPdf($this->ttf->ascent()),
            $toPdf($this->ttf->descent()),
            $toPdf($this->ttf->ascent()), // CapHeight ≈ ascent для POC; уточним позже
            $fontFileObjId,
        );
    }

    /**
     * CIDFontType2 descendant — содержит glyph widths и связь с descriptor'ом.
     */
    private function buildCIDFont(int $descriptorId): string
    {
        return sprintf(
            '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s '
            .'/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> '
            .'/CIDToGIDMap /Identity /FontDescriptor %d 0 R '
            .'/W %s >>',
            $this->ttf->postScriptName(),
            $descriptorId,
            $this->buildWidthsArray(),
        );
    }

    /**
     * /W array — widths для каждого использованного glyph'а. Формат:
     *   [<gid> [<width1> <width2> ...]  ...]
     *
     * Для POC эмитим individual entries (по одному glyph'у на запись).
     * Phase 2 будет оптимизировать в diapason'ы.
     */
    private function buildWidthsArray(): string
    {
        if ($this->usedGlyphs === []) {
            return '[]';
        }
        $upem = $this->ttf->unitsPerEm();
        $parts = [];
        // Sort by glyphId для readability и (возможно) future-optimization.
        ksort($this->usedGlyphs);
        foreach (array_keys($this->usedGlyphs) as $gid) {
            $pdfWidth = (int) round($this->ttf->advanceWidth($gid) * 1000 / $upem);
            $parts[] = "$gid [$pdfWidth]";
        }

        return '['.implode(' ', $parts).']';
    }

    /**
     * ToUnicode CMap stream — позволяет PDF reader'у конвертировать
     * 2-byte glyph IDs обратно в Unicode для copy-paste, text-search,
     * accessibility.
     *
     * Минимальный CMap:
     *   /CIDInit /ProcSet findresource begin
     *   12 dict begin
     *   begincmap
     *   /CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def
     *   /CMapName /Adobe-Identity-UCS def
     *   /CMapType 2 def
     *   1 begincodespacerange
     *   <0000> <FFFF>
     *   endcodespacerange
     *   N beginbfchar
     *   <GID-hex> <UTF-16-hex>
     *   endbfchar
     *   endcmap
     *   CMapName currentdict /CMap defineresource pop
     *   end end
     */
    private function buildToUnicodeCMap(): string
    {
        $body = "/CIDInit /ProcSet findresource begin\n";
        $body .= "12 dict begin\nbegincmap\n";
        $body .= "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n";
        $body .= "/CMapName /Adobe-Identity-UCS def\n";
        $body .= "/CMapType 2 def\n";
        $body .= "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";

        $count = count($this->usedGlyphs);
        if ($count > 0) {
            $body .= "$count beginbfchar\n";
            foreach ($this->usedGlyphs as $gid => $cp) {
                $body .= sprintf("<%04X> <%s>\n",
                    $gid,
                    $this->codepointToUtf16BeHex($cp),
                );
            }
            $body .= "endbfchar\n";
        }

        $body .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";

        return sprintf("<< /Length %d >>\nstream\n%sendstream", strlen($body), $body);
    }

    /**
     * Codepoint → UTF-16BE hex для CMap entries. BMP — 4 hex chars.
     * Supplementary plane — 8 hex chars (surrogate pair).
     */
    private function codepointToUtf16BeHex(int $cp): string
    {
        if ($cp <= 0xFFFF) {
            return sprintf('%04X', $cp);
        }
        // Surrogate pair: высокий 0xD800..0xDBFF, низкий 0xDC00..0xDFFF.
        $cp -= 0x10000;
        $high = 0xD800 + ($cp >> 10);
        $low = 0xDC00 + ($cp & 0x3FF);

        return sprintf('%04X%04X', $high, $low);
    }
}
