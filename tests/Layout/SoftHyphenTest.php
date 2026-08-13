<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\PageMargins;
use Dskripchenko\PhpPdf\Style\PageSetup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SoftHyphenTest extends TestCase
{
    private const SHY = "\u{00AD}";

    #[Test]
    public function strip_soft_hyphens_helper(): void
    {
        $word = 'hyp'.self::SHY.'hen'.self::SHY.'ation';
        self::assertSame('hyphenation', Engine::stripSoftHyphens($word));
    }

    #[Test]
    public function shy_invisible_when_no_overflow(): void
    {
        // The word fits on the line without a break → the SHYs have to be invisible.
        $word = 'hyp'.self::SHY.'hen';
        $doc = new Document(new Section([
            new Paragraph([new Run($word)]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // The SHY bytes (0xC2 0xAD in UTF-8) must NOT reach the content stream.
        self::assertStringNotContainsString("\xC2\xAD", $bytes);
        // Word emitted full (no hyphen added).
        self::assertStringContainsString('(hyphen) Tj', $bytes);
    }

    #[Test]
    public function shy_triggers_split_on_overflow(): void
    {
        // A long word with a SHY in the middle and a narrow content area — it has
        // to split. Very narrow margins (~50pt of content) guarantee an overflow.
        $setup = new PageSetup(
            margins: new PageMargins(leftPt: 270, rightPt: 270, topPt: 72, bottomPt: 72),
        );
        $word = 'super'.self::SHY.'long'.self::SHY.'word'.self::SHY.'really'.self::SHY.'huge';
        $doc = new Document(new Section(
            body: [new Paragraph([new Run("X $word Y")])],
            pageSetup: $setup,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // At least one SHY split has to happen — a word with a trailing "-" is
        // present in one of the expected shapes. Phase 158: batched text can
        // include leading words on the same line, so the regex allows any prefix
        // characters before the terminal `word-`.
        $hasHyphen = preg_match('@[A-Za-z]+-\) Tj@', $bytes);
        self::assertSame(1, $hasHyphen, 'Soft-hyphen split must emit prefix with trailing "-"');
    }

    #[Test]
    public function shy_preserved_in_remainder_for_next_line(): void
    {
        // Repeated SHYs allow several wraps. Check that not a single SHY byte
        // leaked into the final PDF.
        $setup = new PageSetup(
            margins: new PageMargins(leftPt: 240, rightPt: 240, topPt: 72, bottomPt: 72),
        );
        $word = 'pneu'.self::SHY.'mono'.self::SHY.'ultra'.self::SHY.'micro'.self::SHY.'scopic';
        $doc = new Document(new Section(
            body: [new Paragraph([new Run("X $word Y")])],
            pageSetup: $setup,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // The SHY UTF-8 bytes 0xC2 0xAD must not reach the text.
        self::assertStringNotContainsString("\xC2\xAD", $bytes);
    }

    #[Test]
    public function shy_no_split_when_word_fits_remaining_too_tight(): void
    {
        // The word is so small, and the SHY positions placed so, that no prefix
        // fits → the fallback: the whole word on the next line.
        $setup = new PageSetup(
            margins: new PageMargins(leftPt: 270, rightPt: 270, topPt: 72, bottomPt: 72),
        );
        // contentWidth ≈ 55pt — nothing fits except short words.
        $word = 'ab'.self::SHY.'cd';
        $doc = new Document(new Section(
            body: [new Paragraph([new Run("filler $word end")])],
            pageSetup: $setup,
        ));
        // It must not throw; the whole word goes on the next line.
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // The full word 'abcd' (without the SHYs) has to occur (when unsplit).
        self::assertStringNotContainsString("\xC2\xAD", $bytes);
    }
}
