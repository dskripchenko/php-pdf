<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Border;
use Dskripchenko\PhpPdf\Style\BorderStyle;
use Dskripchenko\PhpPdf\Style\TableStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BorderSpacingTest extends TestCase
{
    private function table(float $spacing, bool $collapse = false): Table
    {
        $cell = fn(string $t) => new Cell([new Paragraph([new Run($t)])]);

        return new Table(
            rows: [
                new Row([$cell('A'), $cell('B')]),
                new Row([$cell('C'), $cell('D')]),
            ],
            style: new TableStyle(
                defaultCellBorder: new Border(BorderStyle::Single, 8, '000000'),
                borderCollapse: $collapse,
                borderSpacingPt: $spacing,
            ),
        );
    }

    #[Test]
    public function border_spacing_zero_no_change(): void
    {
        $doc = new Document(new Section([$this->table(spacing: 0)]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringStartsWith('%PDF', $bytes);
    }

    #[Test]
    public function border_spacing_separate_mode_produces_different_output(): void
    {
        $doc0 = new Document(new Section([$this->table(0)]));
        $doc8 = new Document(new Section([$this->table(8)]));
        $b0 = $doc0->toBytes(new Engine(compressStreams: false));
        $b8 = $doc8->toBytes(new Engine(compressStreams: false));
        self::assertNotSame($b0, $b8);
    }

    #[Test]
    public function border_spacing_ignored_in_collapse_mode(): void
    {
        $doc0 = new Document(new Section([$this->table(0, collapse: true)]));
        $doc8 = new Document(new Section([$this->table(8, collapse: true)]));
        $b0 = $doc0->toBytes(new Engine(compressStreams: false));
        $b8 = $doc8->toBytes(new Engine(compressStreams: false));
        // В collapse mode spacing не должен влиять.
        self::assertSame($b0, $b8);
    }
}
