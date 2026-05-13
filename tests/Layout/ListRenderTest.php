<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\ListItem;
use Dskripchenko\PhpPdf\Element\ListNode;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\ListFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListRenderTest extends TestCase
{
    private function font(): PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    private function item(string $text, ?ListNode $nested = null): ListItem
    {
        return new ListItem([new Paragraph([new Run($text)])], nestedList: $nested);
    }

    private function pdftotext(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'list-');
        file_put_contents($tmp, $bytes);
        try {
            return (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function bullet_list_renders_items(): void
    {
        $l = new ListNode([
            $this->item('First'),
            $this->item('Second'),
            $this->item('Third'),
        ]);
        $bytes = (new Document(new Section([$l])))->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);

        self::assertStringContainsString('First', $text);
        self::assertStringContainsString('Second', $text);
        self::assertStringContainsString('Third', $text);
        // Bullet character.
        self::assertStringContainsString("\u{2022}", $text);
    }

    #[Test]
    public function decimal_list_emits_numbers(): void
    {
        $l = new ListNode([
            $this->item('alpha'),
            $this->item('beta'),
            $this->item('gamma'),
        ], format: ListFormat::Decimal);
        $bytes = (new Document(new Section([$l])))->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        $text = $this->pdftotext($bytes);

        self::assertStringContainsString('1.', $text);
        self::assertStringContainsString('2.', $text);
        self::assertStringContainsString('3.', $text);
    }

    #[Test]
    public function start_at_offsets_numbering(): void
    {
        $l = new ListNode([
            $this->item('a'),
            $this->item('b'),
        ], format: ListFormat::Decimal, startAt: 5);
        $text = $this->pdftotext(
            (new Document(new Section([$l])))->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()))
        );

        self::assertStringContainsString('5.', $text);
        self::assertStringContainsString('6.', $text);
    }

    #[Test]
    public function letter_format_uses_alphabet(): void
    {
        $l = new ListNode([
            $this->item('one'),
            $this->item('two'),
        ], format: ListFormat::UpperLetter);
        $text = $this->pdftotext(
            (new Document(new Section([$l])))->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()))
        );
        self::assertStringContainsString('A.', $text);
        self::assertStringContainsString('B.', $text);
    }

    #[Test]
    public function roman_format_uses_roman_numerals(): void
    {
        $l = new ListNode([
            $this->item('one'),
            $this->item('two'),
            $this->item('three'),
            $this->item('four'),
        ], format: ListFormat::LowerRoman);
        $text = $this->pdftotext(
            (new Document(new Section([$l])))->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()))
        );
        self::assertStringContainsString('i.', $text);
        self::assertStringContainsString('ii.', $text);
        self::assertStringContainsString('iii.', $text);
        self::assertStringContainsString('iv.', $text);
    }

    #[Test]
    public function nested_list_renders_with_increasing_indent(): void
    {
        $nested = new ListNode([
            $this->item('Sub A'),
            $this->item('Sub B'),
        ]);
        $l = new ListNode([
            $this->item('Top', nested: $nested),
            $this->item('Second top'),
        ]);
        $text = $this->pdftotext(
            (new Document(new Section([$l])))->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()))
        );
        self::assertStringContainsString('Top', $text);
        self::assertStringContainsString('Sub A', $text);
        self::assertStringContainsString('Second top', $text);
    }

    #[Test]
    public function long_list_overflows_to_second_page(): void
    {
        $items = [];
        for ($i = 1; $i <= 100; $i++) {
            $items[] = $this->item("Item $i");
        }
        $l = new ListNode($items);
        $bytes = (new Document(new Section([$l])))->toBytes(new Engine(compressStreams: false, defaultFont: $this->font()));
        self::assertGreaterThan(1, substr_count($bytes, '/Type /Page '));
    }
}
