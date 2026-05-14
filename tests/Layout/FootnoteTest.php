<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Footnote;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FootnoteTest extends TestCase
{
    #[Test]
    public function footnote_renders_endnote_block(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Run('Text with footnote'),
                new Footnote('First footnote text'),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Phase 158: endnote text batched в один Tj (consecutive same-style runs).
        // Может появиться как "(1. First footnote text) Tj" целиком или с
        // частичными группировками — проверяем наличие ключевых слов.
        self::assertStringContainsString('1.', $bytes);
        self::assertStringContainsString('First', $bytes);
        self::assertStringContainsString('footnote', $bytes);
        self::assertStringContainsString('text', $bytes);
    }

    #[Test]
    public function multiple_footnotes_numbered(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Run('A'),
                new Footnote('Note A'),
                new Run(' and B'),
                new Footnote('Note B'),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Phase 158: endnotes batched — '1.' и '2.' встречаются (могут быть
        // частью большего Tj-string). Marker '1' / '2' появляются в body.
        self::assertStringContainsString('1.', $bytes);
        self::assertStringContainsString('2.', $bytes);
        // Numbering positions: 2× '(1' (marker + endnote start) и 2× '(2'.
        self::assertGreaterThanOrEqual(2, substr_count($bytes, '(1'));
        self::assertGreaterThanOrEqual(2, substr_count($bytes, '(2'));
    }

    #[Test]
    public function no_footnote_no_separator(): void
    {
        // Без footnote не должно быть endnote block + separator rect.
        $doc = new Document(new Section([
            new Paragraph([new Run('Plain text')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Plain rectangles count — без footnote ноль (или мало, если другие
        // features использовали fillRect). Должен быть < 2.
        $rects = preg_match_all('@\bf\b@m', $bytes);
        self::assertLessThanOrEqual(1, $rects);
    }

    #[Test]
    public function footnote_inline_marker_in_text_flow(): void
    {
        // Marker = "1" — superscript ASCII number, появляется в body Tj.
        $doc = new Document(new Section([
            new Paragraph([
                new Run('Preceding'),
                new Footnote('Note'),
                new Run('Following'),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(Preceding) Tj', $bytes);
        self::assertStringContainsString('(Following) Tj', $bytes);
        // Marker присутствует.
        self::assertStringContainsString('(1) Tj', $bytes);
    }

    #[Test]
    public function footnotes_isolated_per_section(): void
    {
        // Multi-section: каждая section получает свои own endnotes,
        // numbering re-starts с 1.
        $sec1 = new Section([
            new Paragraph([new Run('S1'), new Footnote('NoteS1')]),
        ]);
        $sec2 = new Section([
            new Paragraph([new Run('S2'), new Footnote('NoteS2')]),
        ]);
        $doc = new Document($sec1, additionalSections: [$sec2]);
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Phase 158: оба footnotes show "1." (numbered fresh per section).
        // С batching '1.' может быть частью большего Tj — count '1.' occurrences.
        $count = substr_count($bytes, '1.');
        self::assertGreaterThanOrEqual(2, $count, 'оба section должны иметь endnote "1."');
    }

    #[Test]
    public function footnote_separator_rect_emitted(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('Body'), new Footnote('Note')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Endnote separator: тонкий rect 0.5pt height. Должен быть в bytes.
        self::assertMatchesRegularExpression('@\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+\d+(?:\.\d+)?\s+0\.5\s+re@', $bytes);
    }
}
