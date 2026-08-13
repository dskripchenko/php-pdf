<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A vector block takes up as much room as it declared.
 *
 * While the measurement did not know about it, a table row holding a mark
 * measured as zero height: the drawing spilled out of the row and ran into its
 * border.
 */
final class SvgHeightTest extends TestCase
{
    private const MARK = '<svg width="24" height="24" viewBox="0 0 24 24">'
        .'<rect x="2" y="2" width="20" height="20" fill="#14b8a6"/></svg>';

    private function pdfOf(Section $section): string
    {
        return (new Document($section))->toBytes(new Engine(compressStreams: false));
    }

    #[Test]
    public function a_row_is_as_tall_as_the_mark_inside_it(): void
    {
        // Compare two marks of different heights: the minimum row height then
        // drops out of the comparison, leaving exactly the drawing's
        // contribution.
        $row = static fn (float $h): Table => new Table([new Row([
            new Cell([new SvgElement(self::MARK, widthPt: 40, heightPt: $h)]),
        ])]);

        // The position of the paragraph below the table IS the row height: the
        // measurement is checked the same way a reader sees it.
        $textY = function (Table $t): float {
            $path = tempnam(sys_get_temp_dir(), 'svgh-').'.pdf';
            file_put_contents($path, $this->pdfOf(new Section([$t, new Paragraph([new Run('после')])])));
            try {
                $xml = (string) shell_exec('pdftotext -bbox '.escapeshellarg($path).' - 2>/dev/null');
            } finally {
                @unlink($path);
            }
            preg_match('/<word[^>]*yMin="([\d.]+)"/', $xml, $m);

            return (float) ($m[1] ?? 0);
        };

        // The extraction coordinates grow downwards: a mark twice as tall pushes
        // the paragraph down by exactly the difference in height.
        self::assertEqualsWithDelta(60.0, $textY($row(100)) - $textY($row(40)), 1.0);
    }
}
