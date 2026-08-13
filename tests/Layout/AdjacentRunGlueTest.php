<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Adjacent runs with no space between them do not drift apart.
 *
 * The words inside a run are cut into tokens, and the line is assembled back
 * through a space — at the seam of two runs that produced a space which is not
 * in the text. Word cuts a line at any change of typeface, so «(» and
 * «залогодатель» arrive as separate runs, and the imported document printed as
 * «СТРАХОВАТЕЛЬ ( залогодатель )». Found by comparing against the reference in
 * printable.
 */
final class AdjacentRunGlueTest extends TestCase
{
    /**
     * The right edge of the paragraph's last word.
     *
     * The comparison is of geometry rather than extracted text: `pdftotext`
     * decides on its own whether to put a space between glyphs by the size of
     * the gap, and with an embedded font that heuristic fires where there is no
     * space. The width of a line has no such freedom.
     */
    private function rightEdgeOf(Paragraph $paragraph): float
    {
        $pdf = (new Document(new Section([$paragraph])))->toBytes(new Engine(compressStreams: false));
        $path = tempnam(sys_get_temp_dir(), 'glue-').'.pdf';
        file_put_contents($path, $pdf);

        try {
            $bbox = (string) shell_exec('pdftotext -bbox '.escapeshellarg($path).' - 2>/dev/null');
            preg_match_all('/xMax="([0-9.]+)"/', $bbox, $m);

            return $m[1] === [] ? 0.0 : max(array_map('floatval', $m[1]));
        } finally {
            @unlink($path);
        }
    }

    private function textOf(Paragraph $paragraph): string
    {
        $pdf = (new Document(new Section([$paragraph])))->toBytes(new Engine(compressStreams: false));
        $path = tempnam(sys_get_temp_dir(), 'glue-').'.pdf';
        file_put_contents($path, $pdf);

        try {
            // pdftotext lays the positioned words out into lines as it sees fit —
            // compare the stream without the breaks.
            $text = (string) shell_exec('pdftotext '.escapeshellarg($path).' - 2>/dev/null');

            return trim((string) preg_replace('/\n+/', ' ', $text));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function adjacent_runs_without_whitespace_are_not_separated(): void
    {
        // Latin on purpose: the font embedded in a document without a provider
        // is WinAnsi, and Cyrillic turns into junk on extraction. The gluing
        // logic does not depend on the language.
        $paragraph = new Paragraph([
            // Every run has the same typeface: what is compared is the effect of
            // the SPLIT INTO RUNS, not a difference between fonts.
            new Run('POLICYHOLDER '),
            new Run('('),
            new Run('pledgor'),
            new Run(')'),
        ]);

        // A line of four runs has to occupy exactly as much as the same text in
        // a single run: an extra space at a seam lengthens it immediately.
        $split = $this->rightEdgeOf($paragraph);
        $whole = $this->rightEdgeOf(new Paragraph([new Run('POLICYHOLDER (pledgor)')]));

        self::assertEqualsWithDelta($whole, $split, 0.5);
    }

    #[Test]
    public function whitespace_at_a_run_boundary_still_separates(): void
    {
        // A space at the end of a run is the only separator the text actually
        // has: gluing across it is not allowed, or the words run together.
        $paragraph = new Paragraph([
            new Run('first '),
            new Run('second'),
        ]);

        $split = $this->rightEdgeOf($paragraph);
        $whole = $this->rightEdgeOf(new Paragraph([new Run('first second')]));

        self::assertEqualsWithDelta($whole, $split, 0.5);
    }
}
