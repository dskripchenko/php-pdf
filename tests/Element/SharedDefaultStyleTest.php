<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Element;

use Dskripchenko\PhpPdf\Build\CellBuilder;
use Dskripchenko\PhpPdf\Build\ParagraphBuilder;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Field;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Style\CellStyle;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\RunStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Elements made without a style share one immutable default instead of
 * each holding its own copy — a 100-page table used to spend over half its
 * memory on identical style objects.
 */
final class SharedDefaultStyleTest extends TestCase
{
    #[Test]
    public function elements_without_a_style_share_the_default(): void
    {
        self::assertSame(RunStyle::default(), (new Run('a'))->style);
        self::assertSame(RunStyle::default(), (new Field(Field::PAGE))->style);
        self::assertSame(RunStyle::default(), Field::page()->style);
        self::assertSame(ParagraphStyle::default(), (new Paragraph)->style);
        self::assertSame(RunStyle::default(), (new Paragraph)->defaultRunStyle);
        self::assertSame(CellStyle::default(), (new Cell)->style);
    }

    #[Test]
    public function the_shared_default_equals_a_fresh_style(): void
    {
        self::assertEquals(new RunStyle, RunStyle::default());
        self::assertEquals(new ParagraphStyle, ParagraphStyle::default());
        self::assertEquals(new CellStyle, CellStyle::default());
    }

    #[Test]
    public function a_given_style_is_kept(): void
    {
        $bold = (new RunStyle)->withBold();
        self::assertSame($bold, (new Run('a', $bold))->style);
        self::assertTrue((new ParagraphBuilder)->bold('a')->build()->children[0]->style->bold);
    }

    #[Test]
    public function builders_use_the_shared_defaults(): void
    {
        $cell = (new CellBuilder)->text('a')->build();
        self::assertSame(CellStyle::default(), $cell->style);

        $paragraph = $cell->children[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSame(ParagraphStyle::default(), $paragraph->style);
        self::assertSame(RunStyle::default(), $paragraph->children[0]->style);
    }

    #[Test]
    public function a_large_table_of_plain_cells_stays_small(): void
    {
        $before = memory_get_usage();
        $cells = [];
        for ($i = 0; $i < 5000; $i++) {
            $cells[] = (new CellBuilder)->text("Item $i")->build();
        }
        $perCell = (memory_get_usage() - $before) / count($cells);

        // ~800 bytes with shared styles, ~1900 with a copy per element.
        self::assertLessThan(1200, $perCell);
    }
}
