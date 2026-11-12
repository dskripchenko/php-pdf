<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Struct elements can carry an attribute object (/A, ISO 32000-1 §14.8.5).
 * PDF/UA needs one on table header cells: a TH without /Scope (or
 * /Headers and IDs) fails ISO 14289-1 §7.5.
 */
final class StructAttributesTest extends TestCase
{
    #[Test]
    public function a_leaf_writes_its_attribute_object(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->enableTagged();
        $page = $pdf->addPage();
        $pdf->beginStructContainer('Table', $page);
        $pdf->beginStructContainer('TR', $page);
        $mcid = $page->nextMcid();
        $pdf->addStructElement('TH', $mcid, $page, attributes: ['O' => 'Table', 'Scope' => 'Column']);
        $pdf->endStructContainer();
        $pdf->endStructContainer();

        self::assertMatchesRegularExpression('@/S /TH .*?/A << /O /Table /Scope /Column >> >>@', $pdf->toBytes());
    }

    #[Test]
    public function a_container_writes_its_attribute_object_with_numbers(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->enableTagged();
        $page = $pdf->addPage();
        $pdf->beginStructContainer('TD', $page, ['O' => 'Table', 'ColSpan' => 2, 'RowSpan' => 1]);
        $pdf->addStructElement('P', $page->nextMcid(), $page);
        $pdf->endStructContainer();

        self::assertStringContainsString('/A << /O /Table /ColSpan 2 /RowSpan 1 >>', $pdf->toBytes());
    }

    #[Test]
    public function no_attributes_write_no_attribute_object(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->enableTagged();
        $page = $pdf->addPage();
        $pdf->addStructElement('P', $page->nextMcid(), $page);

        self::assertStringNotContainsString('/A <<', $pdf->toBytes());
    }

    #[Test]
    public function an_attribute_object_needs_an_owner(): void
    {
        $pdf = PdfDocument::new();
        $page = $pdf->addPage();

        $this->expectException(\InvalidArgumentException::class);
        $pdf->addStructElement('TH', 0, $page, attributes: ['Scope' => 'Column']);
    }

    #[Test]
    public function a_value_that_is_not_a_name_is_rejected(): void
    {
        $pdf = PdfDocument::new();
        $page = $pdf->addPage();

        $this->expectException(\InvalidArgumentException::class);
        $pdf->addStructElement('TH', 0, $page, attributes: ['O' => 'Table', 'Scope' => 'Column >> /K 99']);
    }
}
