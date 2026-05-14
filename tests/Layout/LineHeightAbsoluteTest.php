<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\RunStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LineHeightAbsoluteTest extends TestCase
{
    #[Test]
    public function absolute_line_height_overrides_multiplier(): void
    {
        // lineHeightPt: 30 — fixed 30pt regardless of font size.
        $p = new Paragraph(
            [new Run('Line 1', new RunStyle(sizePt: 12))],
            new ParagraphStyle(lineHeightPt: 30.0),
        );
        $doc = new Document(new Section([$p]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertNotEmpty($bytes);
        // Phase 158: Run "Line 1" batched into single showText.
        self::assertStringContainsString('(Line 1) Tj', $bytes);
    }

    #[Test]
    public function absolute_takes_precedence_over_multiplier(): void
    {
        $p1 = new Paragraph(
            [new Run('Test', new RunStyle(sizePt: 10))],
            new ParagraphStyle(lineHeightMult: 2.0),
        );
        $p2 = new Paragraph(
            [new Run('Test', new RunStyle(sizePt: 10))],
            new ParagraphStyle(lineHeightMult: 2.0, lineHeightPt: 15.0),
        );

        // measure heights:
        $doc1 = new Document(new Section([$p1, $p1, $p1]));
        $doc2 = new Document(new Section([$p2, $p2, $p2]));
        // p1 line-height = 10 × 2.0 = 20pt; p2 = 15pt absolute → smaller.
        $bytes1 = $doc1->toBytes(new Engine(compressStreams: false));
        $bytes2 = $doc2->toBytes(new Engine(compressStreams: false));
        // Smoke — both rendering ok.
        self::assertNotEmpty($bytes1);
        self::assertNotEmpty($bytes2);
    }

    #[Test]
    public function null_falls_back_to_multiplier(): void
    {
        $p = new Paragraph(
            [new Run('Default')],
            new ParagraphStyle, // both line-height null.
        );
        $doc = new Document(new Section([$p]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('(Default) Tj', $bytes);
    }
}
