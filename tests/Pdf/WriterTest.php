<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WriterTest extends TestCase
{
    #[Test]
    public function minimal_pdf_has_header_xref_trailer_eof(): void
    {
        $w = new Writer;
        $catalog = $w->addObject('<< /Type /Catalog /Pages 2 0 R >>');
        $pages = $w->addObject('<< /Type /Pages /Kids [] /Count 0 >>');
        $w->setRoot($catalog);

        $pdf = $w->toBytes();

        self::assertStringStartsWith('%PDF-1.7', $pdf);
        self::assertStringContainsString('xref', $pdf);
        self::assertStringContainsString('trailer', $pdf);
        self::assertStringContainsString('startxref', $pdf);
        self::assertStringEndsWith("%%EOF\n", $pdf);
    }

    #[Test]
    public function object_ids_are_monotonic_starting_from_1(): void
    {
        $w = new Writer;
        $a = $w->addObject('<< /Type /A >>');
        $b = $w->addObject('<< /Type /B >>');
        $c = $w->addObject('<< /Type /C >>');

        self::assertSame(1, $a);
        self::assertSame(2, $b);
        self::assertSame(3, $c);
    }

    #[Test]
    public function reserve_then_set_allows_forward_references(): void
    {
        // Catalog (ID 1) ссылается на Pages (ID 2) ДО того, как Pages
        // имеет body. Это типичная PDF-конструкция.
        $w = new Writer;
        $catalogId = $w->reserveObject();
        $pagesId = $w->reserveObject();

        // Set in reverse order — это OK.
        $w->setObject($catalogId, sprintf('<< /Type /Catalog /Pages %d 0 R >>', $pagesId));
        $w->setObject($pagesId, '<< /Type /Pages /Kids [] /Count 0 >>');
        $w->setRoot($catalogId);

        $pdf = $w->toBytes();
        self::assertStringContainsString('/Pages 2 0 R', $pdf);
    }

    #[Test]
    public function unfilled_reserved_object_throws(): void
    {
        $w = new Writer;
        $catalogId = $w->addObject('<< /Type /Catalog /Pages 2 0 R >>');
        $w->reserveObject();
        $w->setRoot($catalogId);

        $this->expectException(\LogicException::class);
        $w->toBytes();
    }

    #[Test]
    public function missing_root_throws(): void
    {
        $w = new Writer;
        $w->addObject('<< >>');

        $this->expectException(\LogicException::class);
        $w->toBytes();
    }

    #[Test]
    public function xref_offsets_match_actual_object_positions(): void
    {
        $w = new Writer;
        $c = $w->addObject('<< /Type /Catalog /Pages 2 0 R >>');
        $w->addObject('<< /Type /Pages /Kids [] /Count 0 >>');
        $w->setRoot($c);

        $pdf = $w->toBytes();

        // Parse xref table — линии вида "0000000018 00000 n ".
        preg_match('/xref\n0 (\d+)\n(.*?)trailer/s', $pdf, $m);
        self::assertNotEmpty($m, 'xref must be present');

        $entries = preg_split('/\n/', trim($m[2]));
        // entries[0] — head (0 65535 f). entries[1..] — object offsets.
        self::assertCount(3, $entries); // 0-head + 2 objects

        // Проверяем offset object 1: должен указывать на "1 0 obj".
        preg_match('/^(\d{10}) 00000 n/', $entries[1], $m1);
        $offset1 = (int) $m1[1];
        self::assertSame('1 0 obj', substr($pdf, $offset1, 7));
    }

    #[Test]
    public function trailer_includes_correct_size_and_root(): void
    {
        $w = new Writer;
        $catalogId = $w->addObject('<< /Type /Catalog /Pages 2 0 R >>');
        $w->addObject('<< /Type /Pages /Kids [] /Count 0 >>');
        $w->setRoot($catalogId);

        $pdf = $w->toBytes();
        // Trailer Size = N+1 (одну строку под 0-head + N объектов).
        self::assertMatchesRegularExpression('/trailer\n<< \/Size 3 \/Root 1 0 R >>/', $pdf);
    }
}
