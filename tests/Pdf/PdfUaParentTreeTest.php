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

final class PdfUaParentTreeTest extends TestCase
{
    #[Test]
    public function tagged_pdf_emits_struct_parents_on_pages(): void
    {
        $ast = new AstDocument(
            new Section([new Paragraph([new Run('Hello')])]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // Each Page object has /StructParents 0 entry.
        self::assertMatchesRegularExpression('@/StructParents 0@', $bytes);
    }

    #[Test]
    public function struct_tree_root_includes_parent_tree(): void
    {
        $ast = new AstDocument(
            new Section([new Paragraph([new Run('Test')])]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // /ParentTree reference в StructTreeRoot.
        self::assertMatchesRegularExpression('@/ParentTree\s+\d+\s+0\s+R@', $bytes);
        // /ParentTreeNextKey соответствует pages count.
        self::assertMatchesRegularExpression('@/ParentTreeNextKey\s+\d+@', $bytes);
    }

    #[Test]
    public function parent_tree_maps_pages_to_struct_elements(): void
    {
        $ast = new AstDocument(
            new Section([new Paragraph([new Run('Test')])]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // /Nums entry в parent tree: page index 0 → array of struct refs.
        self::assertMatchesRegularExpression('@/Nums \[0 \[\d+\s+0\s+R\]@', $bytes);
    }

    #[Test]
    public function untagged_no_struct_parents(): void
    {
        $doc = new AstDocument(new Section([new Paragraph([new Run('Plain')])]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/StructParents', $bytes);
        self::assertStringNotContainsString('/ParentTree', $bytes);
    }
}
