<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document as AstDocument;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaggedPdfTest extends TestCase
{
    #[Test]
    public function untagged_document_no_markinfo(): void
    {
        $doc = new AstDocument(new Section([
            new Paragraph([new Run('Untagged')]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/MarkInfo', $bytes);
        self::assertStringNotContainsString('/StructTreeRoot', $bytes);
        self::assertStringNotContainsString('BDC', $bytes);
    }

    #[Test]
    public function tagged_mode_emits_markinfo_structroot(): void
    {
        $ast = new AstDocument(
            new Section([new Paragraph([new Run('Hello')])]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/MarkInfo << /Marked true >>', $bytes);
        self::assertMatchesRegularExpression('@/StructTreeRoot\s+\d+\s+0\s+R@', $bytes);
        self::assertStringContainsString('/Type /StructTreeRoot', $bytes);
    }

    #[Test]
    public function each_paragraph_has_bdc_emc_with_mcid(): void
    {
        $ast = new AstDocument(
            new Section([
                new Paragraph([new Run('First')]),
                new Paragraph([new Run('Second')]),
                new Paragraph([new Run('Third')]),
            ]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // BDC ops + EMC ops счёт.
        $bdcCount = substr_count($bytes, 'BDC');
        $emcCount = substr_count($bytes, 'EMC');
        self::assertSame(3, $bdcCount);
        self::assertSame(3, $emcCount);
        // MCID values 0, 1, 2.
        self::assertStringContainsString('/MCID 0', $bytes);
        self::assertStringContainsString('/MCID 1', $bytes);
        self::assertStringContainsString('/MCID 2', $bytes);
    }

    #[Test]
    public function struct_elements_emit_as_structelem_objects(): void
    {
        $ast = new AstDocument(
            new Section([
                new Paragraph([new Run('A')]),
                new Paragraph([new Run('B')]),
            ]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        $count = substr_count($bytes, '/Type /StructElem');
        self::assertSame(2, $count);
        self::assertGreaterThanOrEqual(2, substr_count($bytes, '/S /P'));
    }

    #[Test]
    public function struct_root_has_kids_array(): void
    {
        $ast = new AstDocument(
            new Section([
                new Paragraph([new Run('A')]),
                new Paragraph([new Run('B')]),
            ]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression(
            '@/StructTreeRoot /K \[\d+\s+0\s+R\s+\d+\s+0\s+R\]@',
            $bytes,
        );
    }
}
