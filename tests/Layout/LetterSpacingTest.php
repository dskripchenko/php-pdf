<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\RunStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LetterSpacingTest extends TestCase
{
    private function font(): PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    #[Test]
    public function letter_spacing_emits_Tc_operator(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Run('spaced', (new RunStyle)->withLetterSpacingPt(2)),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        // Tc operator: '2 Tc' (character spacing 2pt)
        self::assertStringContainsString("2 Tc\n", $bytes);
    }

    #[Test]
    public function zero_letter_spacing_no_Tc_emitted(): void
    {
        $doc = new Document(new Section([
            new Paragraph([new Run('plain')]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        self::assertStringNotContainsString(' Tc', $bytes);
    }

    #[Test]
    public function inherited_letter_spacing_from_default_run_style(): void
    {
        $doc = new Document(new Section([
            new Paragraph(
                children: [new Run('inherit')],
                defaultRunStyle: (new RunStyle)->withLetterSpacingPt(1.5),
            ),
        ]));
        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        self::assertStringContainsString('1.5 Tc', $bytes);
    }

    #[Test]
    public function line_height_multiplier_applied(): void
    {
        $doc1 = new Document(new Section([
            new Paragraph(
                children: [str_repeat('Word ', 30) ? new Run(str_repeat('Word ', 30)) : new Run('')],
                style: new ParagraphStyle(lineHeightMult: 1.0),
            ),
        ]));
        $doc2 = new Document(new Section([
            new Paragraph(
                children: [new Run(str_repeat('Word ', 30))],
                style: new ParagraphStyle(lineHeightMult: 2.0),
            ),
        ]));

        $b1 = $doc1->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        $b2 = $doc2->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));
        // Different line-heights → different cursorY positions → different output.
        self::assertNotSame($b1, $b2);
    }

    #[Test]
    public function letter_spacing_does_not_leak_into_following_text(): void
    {
        // `Tc` — параметр состояния текста: он живёт до следующего `Tc`, а не
        // до ближайшего `ET`. Пока значение писали только когда оно ненулевое,
        // разрядка одного заголовка расползалась на весь документ: строки
        // выходили шире колонки, налезали друг на друга и уходили за край
        // страницы. Настоящий договор так печатался целиком нечитаемым.
        $doc = new Document(new Section([
            new Paragraph([
                new Run('ЗАГОЛОВОК', (new RunStyle)->withLetterSpacingPt(3.1)),
            ]),
            new Paragraph([
                new Run('обычный текст без разрядки'),
            ]),
        ]));

        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));

        // После разряженного фрагмента обязан идти явный возврат к нулю.
        self::assertStringContainsString('3.1 Tc', $bytes);
        self::assertStringContainsString('0 Tc', $bytes);

        $spaced = strpos($bytes, '3.1 Tc');
        $reset = strpos($bytes, '0 Tc', (int) $spaced);
        self::assertIsInt($reset, 'разрядка не сброшена — она достанется следующему тексту');
    }

    #[Test]
    public function repeated_zero_spacing_is_written_once(): void
    {
        // Сброс не должен превращаться в шум: `Tc` пишется только на смене
        // значения, иначе поток пухнет на каждой строке документа.
        $doc = new Document(new Section([
            new Paragraph([new Run('первая строка')]),
            new Paragraph([new Run('вторая строка')]),
            new Paragraph([new Run('третья строка')]),
        ]));

        $bytes = $doc->toBytes(new Engine(
            compressStreams: false,
            defaultFont: $this->font(),
        ));

        self::assertSame(0, substr_count($bytes, ' Tc'), 'без разрядки оператор Tc не нужен вовсе');
    }
}
