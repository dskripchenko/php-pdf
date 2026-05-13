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

final class PdfUaRoleMapTest extends TestCase
{
    #[Test]
    public function role_map_emitted_в_struct_tree_root(): void
    {
        $ast = new AstDocument(
            new Section([new Paragraph([new Run('Test')])]),
            tagged: true,
        );
        $engine = new Engine(compressStreams: false);
        $pdf = $engine->render($ast);
        $pdf->setStructRoleMap([
            'CustomHeading' => 'H1',
            'CustomTable' => 'Table',
        ]);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/RoleMap', $bytes);
        self::assertStringContainsString('/CustomHeading /H1', $bytes);
        self::assertStringContainsString('/CustomTable /Table', $bytes);
    }

    #[Test]
    public function empty_role_map_no_entry(): void
    {
        $ast = new AstDocument(
            new Section([new Paragraph([new Run('Test')])]),
            tagged: true,
        );
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/RoleMap', $bytes);
    }

    #[Test]
    public function role_map_in_untagged_no_struct_tree(): void
    {
        $ast = new AstDocument(new Section([new Paragraph([new Run('Plain')])]));
        $engine = new Engine(compressStreams: false);
        $pdf = $engine->render($ast);
        $pdf->setStructRoleMap(['X' => 'P']);
        $bytes = $pdf->toBytes();

        self::assertStringNotContainsString('/RoleMap', $bytes);
        self::assertStringNotContainsString('/StructTreeRoot', $bytes);
    }
}
