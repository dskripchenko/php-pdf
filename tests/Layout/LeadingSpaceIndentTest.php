<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A paragraph that opens with spaces is positioned by them.
 *
 * Word processors place a heading in the middle of a line with a run of
 * spaces rather than an alignment, and documents built that way are
 * common. Words are split on whitespace, so the run was discarded and such
 * a heading printed hard against the left margin.
 *
 * Only the opening whitespace counts. Whitespace between words is a
 * separator, and giving it width would change how every line breaks.
 */
final class LeadingSpaceIndentTest extends TestCase
{
    #[Test]
    public function leadingSpacesPushTheFirstLineRight(): void
    {
        $plain = $this->firstTextX('Statement');
        $indented = $this->firstTextX(str_repeat(' ', 20).'Statement');

        self::assertGreaterThan(
            $plain + 20,
            $indented,
            'ведущие пробелы не сдвинули строку',
        );
    }

    #[Test]
    public function widthGrowsWithTheNumberOfSpaces(): void
    {
        $ten = $this->firstTextX(str_repeat(' ', 10).'X');
        $twenty = $this->firstTextX(str_repeat(' ', 20).'X');

        // Twice as many spaces give roughly twice the indent.
        $left = $this->firstTextX('X');
        self::assertEqualsWithDelta(($ten - $left) * 2, $twenty - $left, 1.0);
    }

    #[Test]
    public function spacesBetweenWordsStayASeparator(): void
    {
        // Otherwise the breaking of every line in the document would change.
        $single = $this->firstTextX('X       Y');
        $plain = $this->firstTextX('X Y');

        self::assertEqualsWithDelta($plain, $single, 0.01);
    }

    /** The X of the first text position on the page. */
    private function firstTextX(string $text): float
    {
        $pdf = (new Document(new Section(body: [new Paragraph([new Run($text)])])))->toBytes();

        foreach (preg_split("/stream\r?\n/", $pdf) ?: [] as $part) {
            $end = strpos($part, 'endstream');
            $body = @gzuncompress($end !== false ? substr($part, 0, $end) : $part);
            if (is_string($body) && preg_match('/([\d.]+) [\d.]+ Td/', $body, $m) === 1) {
                return (float) $m[1];
            }
        }

        self::fail('в потоке нет позиции текста');
    }
}
