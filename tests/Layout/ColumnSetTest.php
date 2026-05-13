<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\ColumnSet;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ColumnSetTest extends TestCase
{
    #[Test]
    public function single_column_degenerates_to_inline_render(): void
    {
        $cs = new ColumnSet(
            body: [new Paragraph([new Run('Inline')])],
            columnCount: 1,
        );
        $doc = new Document(new Section([$cs]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(Inline) Tj', $bytes);
        self::assertSame(1, substr_count($bytes, '/Type /Page '));
    }

    #[Test]
    public function two_column_layout_emits_text_in_narrower_strip(): void
    {
        $cs = new ColumnSet(
            body: [new Paragraph([new Run('AAA')])],
            columnCount: 2,
            columnGapPt: 12,
        );
        $doc = new Document(new Section([$cs]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Smoke: AAA рендерится.
        self::assertStringContainsString('(AAA) Tj', $bytes);
    }

    #[Test]
    public function column_overflow_advances_to_next_column(): void
    {
        // Заполняем column 0 множеством параграфов, чтобы потребовался
        // column break.
        $blocks = [];
        for ($i = 0; $i < 80; $i++) {
            $blocks[] = new Paragraph([new Run("Line$i")]);
        }
        $cs = new ColumnSet(body: $blocks, columnCount: 2);
        $doc = new Document(new Section([$cs]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Должна остаться одна page (overflow → column 1, не page break).
        // Если каждый Line = ~13pt, 80 lines = ~1040pt. A4 height ~842pt;
        // 2 columns × ~700pt content = ~1400pt total. Должно влезть на 1 page.
        self::assertSame(1, substr_count($bytes, '/Type /Page '));
        // Все строки выведены.
        self::assertStringContainsString('(Line0) Tj', $bytes);
        self::assertStringContainsString('(Line79) Tj', $bytes);
    }

    #[Test]
    public function column_overflow_then_page_break(): void
    {
        // Заполняем достаточно чтобы исчерпать обе columns и нужен page break.
        $blocks = [];
        for ($i = 0; $i < 200; $i++) {
            $blocks[] = new Paragraph([new Run("L$i")]);
        }
        $cs = new ColumnSet(body: $blocks, columnCount: 2);
        $doc = new Document(new Section([$cs]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // 200 lines × 13pt = 2600pt total; 2 columns × ~700pt = 1400pt per page →
        // нужно как минимум 2 pages.
        self::assertGreaterThanOrEqual(2, substr_count($bytes, '/Type /Page '));
        self::assertStringContainsString('(L0) Tj', $bytes);
        self::assertStringContainsString('(L199) Tj', $bytes);
    }

    #[Test]
    public function three_column_layout(): void
    {
        $blocks = [];
        for ($i = 0; $i < 30; $i++) {
            $blocks[] = new Paragraph([new Run("X$i")]);
        }
        $cs = new ColumnSet(body: $blocks, columnCount: 3, columnGapPt: 8);
        $doc = new Document(new Section([$cs]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Smoke: 3 columns layout renders без exception.
        self::assertStringContainsString('(X0) Tj', $bytes);
        self::assertStringContainsString('(X29) Tj', $bytes);
    }

    #[Test]
    public function columns_followed_by_regular_paragraph(): void
    {
        // После ColumnSet — обычный single-column параграф. Layout state
        // должен restore'нуться.
        $cs = new ColumnSet(
            body: [new Paragraph([new Run('ColText')])],
            columnCount: 2,
        );
        $doc = new Document(new Section([
            $cs,
            new Paragraph([new Run('AfterCol')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('(ColText) Tj', $bytes);
        self::assertStringContainsString('(AfterCol) Tj', $bytes);
    }

    #[Test]
    public function zero_column_count_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColumnSet(body: [], columnCount: 0);
    }
}
