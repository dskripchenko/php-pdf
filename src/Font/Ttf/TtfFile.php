<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Font\Ttf;

/**
 * TrueType Font file parser (минимальный для POC-R2.a).
 *
 * Парсит только то, что нужно для PDF font embedding:
 *  - Table directory (поиск offset'ов всех таблиц)
 *  - `head`  → unitsPerEm, xMin/yMin/xMax/yMax (для FontBBox)
 *  - `hhea`  → ascent/descent, numberOfHMetrics
 *  - `maxp` → numGlyphs
 *  - `hmtx` → advance widths массив
 *  - `cmap` → character-to-glyph-id mapping (формат 4 + формат 12)
 *  - `name` → PostScript name (для /BaseFont)
 *  - `OS/2` → CapHeight (опционально), italic angle hints
 *  - `post` → italicAngle, isFixedPitch
 *
 * НЕ парсит: glyf/loca/cvt/fpgm/prep (содержат glyph outlines + hinting,
 * embed'ятся в PDF как opaque FontFile2 stream).
 *
 * Reference: ISO/IEC 14496-22 + Apple TrueType Reference Manual.
 */
final class TtfFile
{
    /**
     * Scaler type magic. 0x00010000 = TTF (Windows/Adobe).
     * 'true' = Apple TTF (old). 'OTTO' = OTF with CFF (мы НЕ поддерживаем).
     */
    private const int SCALER_TTF = 0x00010000;

    private const string SCALER_APPLE = 'true';

    private const string SCALER_OTF_CFF = 'OTTO';

    private readonly BinaryReader $reader;

    /** @var array<string, array{offset: int, length: int}>  tag → metadata */
    private array $tables = [];

    private int $unitsPerEm;

    private int $numGlyphs;

    /** @var list<int>  bbox: [xMin, yMin, xMax, yMax] в FUnits */
    private array $bbox;

    private int $ascent;

    private int $descent;

    /** @var list<int>  index = glyphId; value = advance width в FUnits */
    private array $advanceWidths;

    /** @var array<int, int>  unicodeCodepoint → glyphId */
    private array $cmap;

    private string $postScriptName;

    private int $italicAngle;

    private bool $isFixedPitch;

    public function __construct(
        private readonly string $bytes,
    ) {
        $this->reader = new BinaryReader($bytes);
        $this->parseTableDirectory();
        $this->parseHead();
        $this->parseMaxp();
        $this->parseHhea();
        $this->parseHmtx();
        $this->parseCmap();
        $this->parseName();
        $this->parsePost();
    }

    public static function fromFile(string $path): self
    {
        if (! is_readable($path)) {
            throw new \InvalidArgumentException("Cannot read TTF: $path");
        }

        return new self((string) file_get_contents($path));
    }

    public function rawBytes(): string
    {
        return $this->bytes;
    }

    public function unitsPerEm(): int
    {
        return $this->unitsPerEm;
    }

    public function numGlyphs(): int
    {
        return $this->numGlyphs;
    }

    /** @return list<int> [xMin, yMin, xMax, yMax] */
    public function bbox(): array
    {
        return $this->bbox;
    }

    public function ascent(): int
    {
        return $this->ascent;
    }

    public function descent(): int
    {
        return $this->descent;
    }

    public function postScriptName(): string
    {
        return $this->postScriptName;
    }

    public function italicAngle(): int
    {
        return $this->italicAngle;
    }

    public function isFixedPitch(): bool
    {
        return $this->isFixedPitch;
    }

    /**
     * Lazy-parsed kerning table из GPOS (cached). Null если GPOS отсутствует
     * или не содержит pair-adjustment lookup'ов.
     */
    private ?KerningTable $kerningTable = null;

    private bool $kerningParsed = false;

    public function kerningTable(): ?KerningTable
    {
        if ($this->kerningParsed) {
            return $this->kerningTable;
        }
        $this->kerningParsed = true;
        $gposInfo = $this->tableInfo('GPOS');
        if ($gposInfo === null) {
            return null;
        }
        $table = (new GposReader)->read($this->bytes, $gposInfo);
        if (! $table->isEmpty()) {
            $this->kerningTable = $table;
        }

        return $this->kerningTable;
    }

    /**
     * Lazy-parsed ligature substitutions (cached) из GSUB. Null если
     * GSUB отсутствует или не содержит 'liga' feature lookup'ов.
     */
    private ?LigatureSubstitutions $ligatures = null;

    private bool $ligaturesParsed = false;

    public function ligatures(): ?LigatureSubstitutions
    {
        if ($this->ligaturesParsed) {
            return $this->ligatures;
        }
        $this->ligaturesParsed = true;
        $gsubInfo = $this->tableInfo('GSUB');
        if ($gsubInfo === null) {
            return null;
        }
        $subs = (new GsubReader)->read($this->bytes, $gsubInfo);
        if (! $subs->isEmpty()) {
            $this->ligatures = $subs;
        }

        return $this->ligatures;
    }

    /**
     * Резолв Unicode codepoint → glyph ID. Возвращает 0 (.notdef) если char
     * не покрывается font'ом.
     */
    public function glyphIdForChar(int $codepoint): int
    {
        return $this->cmap[$codepoint] ?? 0;
    }

    public function advanceWidth(int $glyphId): int
    {
        return $this->advanceWidths[$glyphId] ?? 0;
    }

    /**
     * Доступ к raw-offset таблицы (для подкласс'ов / debug).
     *
     * @return array{offset: int, length: int}|null
     */
    public function tableInfo(string $tag): ?array
    {
        return $this->tables[$tag] ?? null;
    }

    /**
     * Read header + table directory.
     * Header: scaler type (4) + numTables (2) + 3 × uint16 = 12 bytes.
     * Каждый entry — tag (4) + checksum (4) + offset (4) + length (4) = 16.
     */
    private function parseTableDirectory(): void
    {
        $this->reader->seek(0);
        $scaler = $this->reader->readUInt32();

        // 0x00010000 для standard TTF, 'OTTO' для CFF-based OTF.
        if ($scaler !== self::SCALER_TTF
            && substr($this->bytes, 0, 4) !== self::SCALER_APPLE) {
            if (substr($this->bytes, 0, 4) === self::SCALER_OTF_CFF) {
                throw new \RuntimeException('CFF-based OTF fonts not supported in POC; need TTF outline-based.');
            }
            throw new \RuntimeException(sprintf('Unknown font scaler 0x%08X', $scaler));
        }

        $numTables = $this->reader->readUInt16();
        $this->reader->skip(6); // searchRange + entrySelector + rangeShift — нам не нужны

        for ($i = 0; $i < $numTables; $i++) {
            $tag = $this->reader->readTag();
            $this->reader->skip(4); // checksum
            $offset = $this->reader->readUInt32();
            $length = $this->reader->readUInt32();
            $this->tables[$tag] = ['offset' => $offset, 'length' => $length];
        }
    }

    /**
     * `head` table — base font metrics.
     * Offsets (от start таблицы):
     *   0..3   majorVersion, minorVersion (Fixed)
     *   4..7   fontRevision (Fixed)
     *   8..11  checkSumAdjustment
     *   12..15 magicNumber (должен быть 0x5F0F3CF5)
     *   16..17 flags
     *   18..19 unitsPerEm
     *   20..35 created/modified (Date64, 16 bytes total)
     *   36..37 xMin
     *   38..39 yMin
     *   40..41 xMax
     *   42..43 yMax
     *   ...etc
     */
    private function parseHead(): void
    {
        $offset = $this->requireTable('head');
        $this->reader->seek($offset + 18);
        $this->unitsPerEm = $this->reader->readUInt16();
        $this->reader->seek($offset + 36);
        $this->bbox = [
            $this->reader->readInt16(),
            $this->reader->readInt16(),
            $this->reader->readInt16(),
            $this->reader->readInt16(),
        ];
    }

    /**
     * `maxp` — содержит numGlyphs (offset 4, uint16).
     */
    private function parseMaxp(): void
    {
        $offset = $this->requireTable('maxp');
        $this->reader->seek($offset + 4);
        $this->numGlyphs = $this->reader->readUInt16();
    }

    /**
     * `hhea`:
     *   4..5   ascender (int16)
     *   6..7   descender (int16)
     *   34..35 numberOfHMetrics (uint16)
     */
    private int $numHMetrics;

    private function parseHhea(): void
    {
        $offset = $this->requireTable('hhea');
        $this->reader->seek($offset + 4);
        $this->ascent = $this->reader->readInt16();
        $this->descent = $this->reader->readInt16();
        $this->reader->seek($offset + 34);
        $this->numHMetrics = $this->reader->readUInt16();
    }

    /**
     * `hmtx` — массив longHorMetric (advance width + lsb) первых
     * numHMetrics + опциональный массив только-lsb для остальных.
     * Glyph'ы с index >= numHMetrics используют last-known advance width.
     */
    private function parseHmtx(): void
    {
        $offset = $this->requireTable('hmtx');
        $this->reader->seek($offset);
        $this->advanceWidths = [];

        $lastWidth = 0;
        for ($i = 0; $i < $this->numHMetrics; $i++) {
            $lastWidth = $this->reader->readUInt16();
            $this->reader->skip(2); // lsb (signed, не используем)
            $this->advanceWidths[$i] = $lastWidth;
        }
        // Остальные glyph'ы — lsb only, ширина = lastWidth.
        for ($i = $this->numHMetrics; $i < $this->numGlyphs; $i++) {
            $this->advanceWidths[$i] = $lastWidth;
        }
    }

    /**
     * `cmap` — character-to-glyph mapping. Содержит несколько subtables;
     * мы ищем сначала format 12 (full Unicode), потом format 4 (BMP).
     *
     * cmap header:
     *   0..1   version (uint16)
     *   2..3   numTables (uint16)
     *   далее numTables entries по 8 байт:
     *     0..1 platformID
     *     2..3 encodingID
     *     4..7 offset (от начала cmap-таблицы)
     *
     * Предпочтения:
     *   - Windows / Unicode full (platform 3, encoding 10) — format 12
     *   - Windows / BMP (platform 3, encoding 1) — format 4
     *   - Unicode / BMP (platform 0, encoding 3) — format 4
     */
    private function parseCmap(): void
    {
        $cmapStart = $this->requireTable('cmap');
        $this->reader->seek($cmapStart);
        $this->reader->skip(2); // version
        $numSubtables = $this->reader->readUInt16();

        $subtables = []; // priority → offset
        for ($i = 0; $i < $numSubtables; $i++) {
            $platformId = $this->reader->readUInt16();
            $encodingId = $this->reader->readUInt16();
            $subtableOffset = $cmapStart + $this->reader->readUInt32();
            $priority = $this->cmapSubtablePriority($platformId, $encodingId);
            if ($priority !== null) {
                $subtables[$priority] = $subtableOffset;
            }
        }

        if ($subtables === []) {
            throw new \RuntimeException('No usable cmap subtable found in TTF.');
        }
        ksort($subtables); // lowest = highest priority
        $chosenOffset = reset($subtables);

        $this->reader->seek($chosenOffset);
        $format = $this->reader->readUInt16();
        $this->cmap = match ($format) {
            4 => $this->parseCmapFormat4($chosenOffset),
            12 => $this->parseCmapFormat12($chosenOffset),
            default => throw new \RuntimeException("Unsupported cmap format $format"),
        };
    }

    private function cmapSubtablePriority(int $platformId, int $encodingId): ?int
    {
        // Lower = higher priority.
        if ($platformId === 3 && $encodingId === 10) {
            return 1; // Windows / Unicode full (UCS-4)
        }
        if ($platformId === 0 && $encodingId === 4) {
            return 2; // Unicode / full
        }
        if ($platformId === 3 && $encodingId === 1) {
            return 3; // Windows / Unicode BMP
        }
        if ($platformId === 0 && ($encodingId === 3 || $encodingId === 6)) {
            return 4; // Unicode / BMP
        }

        return null; // не используем (Macintosh, Symbol, etc.)
    }

    /**
     * cmap format 4 — segment mapping для BMP (U+0000..U+FFFF).
     *
     * Schema:
     *   0..1   format (= 4)
     *   2..3   length
     *   4..5   language
     *   6..7   segCountX2 (2 × segCount)
     *   8..9   searchRange / entrySelector / rangeShift (skip 6 bytes)
     *   14..   endCode array (segCount × uint16)
     *   ...    reservedPad (uint16)
     *   ...    startCode array (segCount × uint16)
     *   ...    idDelta array (segCount × int16)
     *   ...    idRangeOffset array (segCount × uint16)
     *   ...    glyphIdArray
     *
     * Алгоритм mapping:
     *   for each char c:
     *     найти segment i где startCode[i] <= c <= endCode[i]
     *     if idRangeOffset[i] == 0:
     *       glyphId = (c + idDelta[i]) mod 65536
     *     else:
     *       glyphId = glyphIdArray[idRangeOffset[i]/2 + (c - startCode[i]) + i - segCount]
     *
     * @return array<int, int>
     */
    private function parseCmapFormat4(int $subtableOffset): array
    {
        $this->reader->seek($subtableOffset + 6);
        $segCountX2 = $this->reader->readUInt16();
        $segCount = $segCountX2 / 2;
        $this->reader->skip(6); // searchRange + entrySelector + rangeShift

        $endCode = [];
        for ($i = 0; $i < $segCount; $i++) {
            $endCode[$i] = $this->reader->readUInt16();
        }
        $this->reader->skip(2); // reservedPad

        $startCode = [];
        for ($i = 0; $i < $segCount; $i++) {
            $startCode[$i] = $this->reader->readUInt16();
        }

        $idDelta = [];
        for ($i = 0; $i < $segCount; $i++) {
            $idDelta[$i] = $this->reader->readInt16();
        }

        $idRangeOffsetStart = $this->reader->tell();
        $idRangeOffset = [];
        for ($i = 0; $i < $segCount; $i++) {
            $idRangeOffset[$i] = $this->reader->readUInt16();
        }

        $cmap = [];
        for ($i = 0; $i < $segCount; $i++) {
            $start = $startCode[$i];
            $end = $endCode[$i];
            // Last segment всегда 0xFFFF..0xFFFF mapping to 0. Skip.
            if ($start === 0xFFFF && $end === 0xFFFF) {
                continue;
            }
            for ($c = $start; $c <= $end; $c++) {
                if ($idRangeOffset[$i] === 0) {
                    $glyphId = ($c + $idDelta[$i]) & 0xFFFF;
                } else {
                    $glyphIndexPos = $idRangeOffsetStart + $i * 2
                        + $idRangeOffset[$i]
                        + ($c - $start) * 2;
                    $glyphId = $this->reader->peekUInt16($glyphIndexPos);
                    if ($glyphId !== 0) {
                        $glyphId = ($glyphId + $idDelta[$i]) & 0xFFFF;
                    }
                }
                if ($glyphId !== 0) {
                    $cmap[$c] = $glyphId;
                }
            }
        }

        return $cmap;
    }

    /**
     * cmap format 12 — segmented mapping for full UCS-4 (с supplementary
     * planes outside BMP).
     *
     * Schema:
     *   0..1   format (= 12)
     *   2..3   reserved
     *   4..7   length (uint32)
     *   8..11  language (uint32)
     *   12..15 numGroups (uint32)
     *   then numGroups × {startCharCode: uint32, endCharCode: uint32,
     *                     startGlyphID: uint32}
     *
     * @return array<int, int>
     */
    private function parseCmapFormat12(int $subtableOffset): array
    {
        $this->reader->seek($subtableOffset + 12);
        $numGroups = $this->reader->readUInt32();

        $cmap = [];
        for ($g = 0; $g < $numGroups; $g++) {
            $start = $this->reader->readUInt32();
            $end = $this->reader->readUInt32();
            $startGlyph = $this->reader->readUInt32();
            for ($c = $start, $glyph = $startGlyph; $c <= $end; $c++, $glyph++) {
                $cmap[$c] = $glyph;
            }
        }

        return $cmap;
    }

    /**
     * `name` table — содержит metadata strings (font family, postscript name).
     *
     * Header:
     *   0..1  format (обычно 0)
     *   2..3  count
     *   4..5  stringOffset (от начала name-таблицы)
     *   далее `count` записей по 12 байт:
     *     0..1 platformID
     *     2..3 encodingID
     *     4..5 languageID
     *     6..7 nameID
     *     8..9 length
     *     10..11 offset
     *
     * Мы ищем nameID 6 (PostScript name). Предпочтение — Windows
     * (platformID 3) с Unicode (UTF-16BE).
     */
    private function parseName(): void
    {
        $info = $this->tableInfo('name');
        if ($info === null) {
            $this->postScriptName = 'UnknownFont';

            return;
        }
        $nameStart = $info['offset'];
        $this->reader->seek($nameStart + 2);
        $count = $this->reader->readUInt16();
        $stringOffset = $this->reader->readUInt16();

        $best = null;
        for ($i = 0; $i < $count; $i++) {
            $platformId = $this->reader->readUInt16();
            $encodingId = $this->reader->readUInt16();
            $this->reader->skip(2); // languageID
            $nameId = $this->reader->readUInt16();
            $length = $this->reader->readUInt16();
            $offset = $this->reader->readUInt16();
            if ($nameId !== 6) {
                continue; // не PostScript name
            }
            $absOffset = $nameStart + $stringOffset + $offset;
            $rawString = substr($this->bytes, $absOffset, $length);
            $value = match ($platformId) {
                3 => mb_convert_encoding($rawString, 'UTF-8', 'UTF-16BE'),
                1 => $rawString, // Macintosh, MacRoman; для PostScript name
                                 // это ASCII-only de facto
                default => $rawString,
            };
            if ($value === '' || $value === false) {
                continue;
            }
            // Prefer Windows (platform 3).
            if ($platformId === 3) {
                $best = $value;
                break;
            }
            $best ??= $value;
        }

        $this->postScriptName = $best ?? 'UnknownFont';
    }

    /**
     * `post` — italicAngle (Fixed 16.16, offset 4) и isFixedPitch
     * (uint32, offset 12, non-zero = monospace).
     */
    private function parsePost(): void
    {
        $info = $this->tableInfo('post');
        if ($info === null) {
            $this->italicAngle = 0;
            $this->isFixedPitch = false;

            return;
        }
        $offset = $info['offset'];
        $this->reader->seek($offset + 4);
        // italicAngle: Fixed 16.16. Высокие 16 бит — integer часть.
        $italicRaw = $this->reader->readInt32();
        $this->italicAngle = $italicRaw >> 16;

        $this->reader->seek($offset + 12);
        $this->isFixedPitch = $this->reader->readUInt32() !== 0;
    }

    private function requireTable(string $tag): int
    {
        $info = $this->tableInfo($tag);
        if ($info === null) {
            throw new \RuntimeException("Required TTF table '$tag' not found.");
        }

        return $info['offset'];
    }
}
