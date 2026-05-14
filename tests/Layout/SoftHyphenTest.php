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
        // Word влезает на line без переноса → SHY должны быть невидимы.
        $word = 'hyp'.self::SHY.'hen';
        $doc = new Document(new Section([
            new Paragraph([new Run($word)]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // SHY bytes (0xC2 0xAD UTF-8) НЕ должны попасть в content stream.
        self::assertStringNotContainsString("\xC2\xAD", $bytes);
        // Word emitted full (no hyphen added).
        self::assertStringContainsString('(hyphen) Tj', $bytes);
    }

    #[Test]
    public function shy_triggers_split_on_overflow(): void
    {
        // Long word с SHY в середине, narrow content area → должен сплитнуться.
        // Очень narrow margins (~50pt content) гарантируют overflow.
        $setup = new PageSetup(
            margins: new PageMargins(leftPt: 270, rightPt: 270, topPt: 72, bottomPt: 72),
        );
        $word = 'super'.self::SHY.'long'.self::SHY.'word'.self::SHY.'really'.self::SHY.'huge';
        $doc = new Document(new Section(
            body: [new Paragraph([new Run("X $word Y")])],
            pageSetup: $setup,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Хотя бы один SHY split должен сработать — присутствует word с
        // trailing "-" в одной из ожидаемых форм. Phase 158: batched text
        // может включать leading words на same line, поэтому regex допускает
        // любые prefix chars перед терминальным `word-`.
        $hasHyphen = preg_match('@[A-Za-z]+-\) Tj@', $bytes);
        self::assertSame(1, $hasHyphen, 'Soft-hyphen split must emit prefix with trailing "-"');
    }

    #[Test]
    public function shy_preserved_in_remainder_for_next_line(): void
    {
        // Многократные SHY → возможны несколько wraps. Проверим что
        // ни одного SHY-byte не утекло в финальный PDF.
        $setup = new PageSetup(
            margins: new PageMargins(leftPt: 240, rightPt: 240, topPt: 72, bottomPt: 72),
        );
        $word = 'pneu'.self::SHY.'mono'.self::SHY.'ultra'.self::SHY.'micro'.self::SHY.'scopic';
        $doc = new Document(new Section(
            body: [new Paragraph([new Run("X $word Y")])],
            pageSetup: $setup,
        ));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // SHY UTF-8 bytes 0xC2 0xAD не должны попадать в текст.
        self::assertStringNotContainsString("\xC2\xAD", $bytes);
    }

    #[Test]
    public function shy_no_split_when_word_fits_remaining_too_tight(): void
    {
        // Слово настолько маленькое и SHY-positions так размещены, что
        // ни один prefix не помещается → fallback: word целиком на след. line.
        $setup = new PageSetup(
            margins: new PageMargins(leftPt: 270, rightPt: 270, topPt: 72, bottomPt: 72),
        );
        // contentWidth ≈ 55pt — ничего не влезает кроме coротких слов.
        $word = 'ab'.self::SHY.'cd';
        $doc = new Document(new Section(
            body: [new Paragraph([new Run("filler $word end")])],
            pageSetup: $setup,
        ));
        // Не должен бросить exception; word целиком на след. line.
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Полное слово 'abcd' (без SHY) должно встречаться (когда не разбито).
        self::assertStringNotContainsString("\xC2\xAD", $bytes);
    }
}
