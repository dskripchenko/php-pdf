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
    /**
     * glyphId → list of source codepoints (для ToUnicode CMap построения).
     * Для обычных glyph'ов — single-element list. Для ligature-glyph'ов —
     * список codepoint'ов исходной sequence (e.g., glyph "fi" → ['f', 'i']).
     *
     * @var array<int, list<int>>
     */
    private array $usedGlyphs = [];

    private ?int $fontObjectId = null;

    /**
     * @var bool  Применять ligature substitutions из GSUB ('liga' feature).
     *            False — disable (для debug или fonts с проблемными liga).
     */
    private bool $ligaturesEnabled = true;

    /**
     * @param  bool  $subset  Если true — embed только used glyph'ы через
     *                        TtfSubsetter (от ~411KB до ~5-50KB обычно).
     *                        Если false — full TTF (backward-compat для
     *                        корнер-кейсов где subset причинит проблем).
     */
    public function __construct(
        private readonly TtfFile $ttf,
        private readonly bool $subset = true,
    ) {}

    /**
     * Регистрирует font в Writer'е. Создаёт все необходимые объекты,
     * возвращает Type0 font dict object ID — этот ID используется
     * в page Resources /Font dict.
     */
    public function registerWith(Writer $writer, bool $compressStreams = true): int
    {
        if ($this->fontObjectId !== null) {
            return $this->fontObjectId;
        }

        // 1. Embed TTF binary как FontFile2 stream object. С FlateDecode
        //    font subsets ~50-70% меньше (typical 30-70KB → 15-35KB).
        $fontBytes = $this->subset
            ? (new \Dskripchenko\PhpPdf\Font\Ttf\TtfSubsetter)->subset($this->ttf, array_keys($this->usedGlyphs))
            : $this->ttf->rawBytes();
        if ($compressStreams) {
            $compressed = (string) gzcompress($fontBytes, 6);
            $fontFileObjId = $writer->addObject(sprintf(
                "<< /Length %d /Length1 %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
                strlen($compressed),
                strlen($fontBytes),  // Length1 = un-compressed original size (PDF spec)
                $compressed,
            ));
        } else {
            $fontFileObjId = $writer->addObject(sprintf(
                "<< /Length %d /Length1 %d >>\nstream\n%s\nendstream",
                strlen($fontBytes),
                strlen($fontBytes),
                $fontBytes,
            ));
        }

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
     * Внутренний raw-access на TtfFile (для TextMeasurer + LineBreaker,
     * которые сами не должны парсить TTF).
     */
    public function ttf(): \Dskripchenko\PhpPdf\Font\Ttf\TtfFile
    {
        return $this->ttf;
    }

    /**
     * Decode UTF-8 в list (codepoint, glyphId) — низкоуровневый decoder.
     * НЕ применяет ligature substitution. Side-effect: НЕ накапливает в
     * usedGlyphs (это делает shapedGlyphs/encodeText).
     *
     * @return list<array{cp: int, gid: int}>
     */
    public function decodeUtf8(string $utf8): array
    {
        $out = [];
        $i = 0;
        $len = strlen($utf8);
        while ($i < $len) {
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
                $cp = (($b1 & 0x07) << 18)
                    | ((ord($utf8[$i + 1]) & 0x3F) << 12)
                    | ((ord($utf8[$i + 2]) & 0x3F) << 6)
                    | (ord($utf8[$i + 3]) & 0x3F);
                $i += 4;
            }
            $out[] = ['cp' => $cp, 'gid' => $this->ttf->glyphIdForChar($cp)];
        }

        return $out;
    }

    /**
     * High-level: UTF-8 → list of «shaped» glyph entries после GSUB liga
     * substitution. Каждый entry = {gid, sourceCps} — sourceCps это
     * codepoint'ы которые этот gid представляет (1 для regular glyph'ов,
     * 2+ для ligature glyph'ов).
     *
     * Side-effect: записывает в usedGlyphs со всеми source codepoint'ами
     * (для построения ToUnicode CMap с правильным copy-paste).
     *
     * @return list<array{gid: int, sourceCps: list<int>}>
     */
    public function shapedGlyphs(string $utf8): array
    {
        $decoded = $this->decodeUtf8($utf8);
        $glyphIds = array_map(fn ($e) => $e['gid'], $decoded);
        $codepoints = array_map(fn ($e) => $e['cp'], $decoded);

        $ligatures = $this->ligaturesEnabled ? $this->ttf->ligatures() : null;

        if ($ligatures === null) {
            // No GSUB liga — straight mapping.
            $out = [];
            foreach ($decoded as $i => $entry) {
                $this->usedGlyphs[$entry['gid']] = [$entry['cp']];
                $out[] = ['gid' => $entry['gid'], 'sourceCps' => [$entry['cp']]];
            }

            return $out;
        }

        $result = $ligatures->apply($glyphIds);
        // Mapping: source-glyph-index ranges → result glyph + sources.
        // ligatures->apply() возвращает {glyphs, sourceMap (ligatureGid → component-gids)}
        // Мы нужно знать какие исходные CODEPOINTS соответствуют каждому
        // ligature gid'у. Это требует matching от LigatureSubstitutions.

        // Простой подход: re-run apply on (cp, gid) pairs sequentially,
        // отслеживая source-cp positions.
        $out = [];
        $shaped = $result['glyphs'];
        $sourceMap = $result['sourceMap']; // ligatureGid → list<componentGid>
        $sourceIdx = 0;
        foreach ($shaped as $shapedGid) {
            if (isset($sourceMap[$shapedGid])) {
                $componentCount = count($sourceMap[$shapedGid]);
                $sourceCps = array_slice($codepoints, $sourceIdx, $componentCount);
                $sourceIdx += $componentCount;
            } else {
                $sourceCps = [$codepoints[$sourceIdx]];
                $sourceIdx++;
            }
            $this->usedGlyphs[$shapedGid] = $sourceCps;
            $out[] = ['gid' => $shapedGid, 'sourceCps' => $sourceCps];
        }

        return $out;
    }

    /**
     * Backward-compat: iterate как было раньше. Использует decodeUtf8 без
     * shaping. Side-effect: pollutes usedGlyphs single-cp entries.
     *
     * Используется только для legacy кода; новый код должен использовать
     * shapedGlyphs().
     *
     * @return iterable<array{cp: int, gid: int}>
     */
    public function utf8ToGlyphs(string $utf8): iterable
    {
        foreach ($this->decodeUtf8($utf8) as $entry) {
            $this->usedGlyphs[$entry['gid']] = [$entry['cp']];
            yield $entry;
        }
    }

    /**
     * Отключить ligature substitution. Полезно когда font имеет проблемные
     * 'liga' rules или нужен exact-glyph layout.
     */
    public function disableLigatures(): self
    {
        $this->ligaturesEnabled = false;

        return $this;
    }

    /**
     * Kerning adjustment между (left, right) glyph'ами в PDF text-space units
     * (1000/em), уже sign-flipped для готового использования в TJ operator
     * и width-measurement subtraction.
     *
     * Convention:
     *  - Возвращает POSITIVE для tighter pairs (less space, AV например)
     *  - Возвращает NEGATIVE для loose pairs (more space — редко)
     *  - 0 если pair не имеет kerning'а
     *
     * Для измерения width: total_pdf_units -= kerningPdfUnits(prev, cur).
     * Для TJ operator: между runs insert int value = +kerningPdfUnits(prev, cur).
     */
    public function kerningPdfUnits(int $leftGid, int $rightGid): int
    {
        $kt = $this->ttf->kerningTable();
        if ($kt === null) {
            return 0;
        }
        $xAdvanceFu = $kt->lookup($leftGid, $rightGid);
        if ($xAdvanceFu === 0) {
            return 0;
        }
        // GPOS xAdvance negative → reduce advance → tighter pair.
        // PDF TJ value positive → subtract from position → next glyph left.
        // Convert: PDF_value = -GPOS_value * 1000/em.
        return (int) round(-$xAdvanceFu * 1000 / $this->ttf->unitsPerEm());
    }

    /**
     * Кодирует UTF-8 текст в hex glyph-ID string для simple Tj operator
     * при Identity-H encoding. Применяет ligature substitution через
     * shapedGlyphs(). БЕЗ kerning'а — для kerning-aware версии см.
     * encodeTextTjArray().
     *
     * Side-effect: usedGlyphs накапливается для ToUnicode CMap.
     */
    public function encodeText(string $utf8): string
    {
        $hex = '';
        foreach ($this->shapedGlyphs($utf8) as $shaped) {
            $hex .= sprintf('%04X', $shaped['gid']);
        }

        return '<'.$hex.'>';
    }

    /**
     * Kerning-aware encoding для TJ operator. Возвращает array состоящий
     * из чередующихся hex-string'ов и int adjustment'ов.
     *
     * Example «AVA»:
     *   ['<0036>', 74, '<00570036>']
     *   ──────  ──  ──────────
     *   A run   AV  V+A run
     *           kern
     *
     * Если у font'а нет kerning table'а ИЛИ ни одной из pair-ов нет
     * kerning'а — array содержит один single hex-string (можно тогда
     * использовать обычный Tj вместо TJ — but caller сам решает).
     *
     * Side-effect: usedGlyphs накапливается.
     *
     * @return list<string|int>
     */
    public function encodeTextTjArray(string $utf8): array
    {
        $result = [];
        $currentRun = '';
        $prevGid = null;
        foreach ($this->shapedGlyphs($utf8) as $shaped) {
            $gid = $shaped['gid'];
            if ($prevGid !== null) {
                $kern = $this->kerningPdfUnits($prevGid, $gid);
                if ($kern !== 0) {
                    $result[] = '<'.$currentRun.'>';
                    $result[] = $kern;
                    $currentRun = '';
                }
            }
            $currentRun .= sprintf('%04X', $gid);
            $prevGid = $gid;
        }
        if ($currentRun !== '') {
            $result[] = '<'.$currentRun.'>';
        }

        return $result;
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
     * Width одного glyph'а в PDF text-space units (1000/em).
     * Аналог widthOfCharPdfUnits, но принимает уже-разрешённый glyph ID.
     */
    public function widthOfGlyphPdfUnits(int $gid): int
    {
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
            foreach ($this->usedGlyphs as $gid => $cps) {
                // cps — list<int>: один codepoint для regular glyph,
                // несколько для ligature glyph (e.g., fi → ['f','i']).
                // PDF ToUnicode bfchar: <gid> <utf16-hex> где utf16-hex
                // может быть multi-character для ligatures.
                $utf16 = '';
                foreach ($cps as $cp) {
                    $utf16 .= $this->codepointToUtf16BeHex($cp);
                }
                $body .= sprintf("<%04X> <%s>\n", $gid, $utf16);
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
