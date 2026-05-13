<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document as AstDocument;
use Dskripchenko\PhpPdf\Element\ListItem;
use Dskripchenko\PhpPdf\Element\ListNode;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\ListFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaggedListTest extends TestCase
{
    private function makeItem(string $text): ListItem
    {
        return new ListItem([new Paragraph([new Run($text)])]);
    }

    #[Test]
    public function tagged_list_emits_l_li_structs(): void
    {
        $list = new ListNode(
            items: [$this->makeItem('First'), $this->makeItem('Second'), $this->makeItem('Third')],
            format: ListFormat::Bullet,
        );
        $ast = new AstDocument(new Section([$list]), tagged: true);
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/S /L', $bytes);
        // 3 items → 3 /LI.
        self::assertSame(3, substr_count($bytes, '/S /LI'));
    }

    #[Test]
    public function tagged_list_content_streams_have_bdc(): void
    {
        $list = new ListNode(
            items: [$this->makeItem('X')],
            format: ListFormat::Bullet,
        );
        $ast = new AstDocument(new Section([$list]), tagged: true);
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/L << /MCID', $bytes);
        self::assertStringContainsString('/LI << /MCID', $bytes);
    }

    #[Test]
    public function untagged_list_no_struct(): void
    {
        $list = new ListNode(
            items: [$this->makeItem('X')],
            format: ListFormat::Bullet,
        );
        $doc = new AstDocument(new Section([$list]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/S /L', $bytes);
        self::assertStringNotContainsString('BDC', $bytes);
    }

    #[Test]
    public function ordered_list_also_tagged(): void
    {
        $list = new ListNode(
            items: [$this->makeItem('A'), $this->makeItem('B')],
            format: ListFormat::Decimal,
        );
        $ast = new AstDocument(new Section([$list]), tagged: true);
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/S /L', $bytes);
        self::assertSame(2, substr_count($bytes, '/S /LI'));
    }

    #[Test]
    public function item_paragraph_no_separate_p_tag(): void
    {
        $list = new ListNode(
            items: [$this->makeItem('X')],
            format: ListFormat::Bullet,
        );
        $ast = new AstDocument(new Section([$list]), tagged: true);
        $bytes = $ast->toBytes(new Engine(compressStreams: false));

        // BDC: /L + /LI = 2. No nested /P.
        $bdcCount = substr_count($bytes, 'BDC');
        self::assertSame(2, $bdcCount);
    }
}
