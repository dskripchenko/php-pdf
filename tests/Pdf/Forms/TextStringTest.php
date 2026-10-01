<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Forms\Appearance\DefaultAppearance;
use Dskripchenko\PhpPdf\Pdf\Forms\TextString;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextStringTest extends TestCase
{
    #[Test]
    public function ascii_stays_plain(): void
    {
        self::assertSame('Jane Roe', TextString::encode('Jane Roe')->bytes);
    }

    #[Test]
    public function anything_else_becomes_utf16_with_a_bom(): void
    {
        self::assertSame("\xFE\xFF\x04\x18\x04\x32", TextString::encode('Ив')->bytes);
        self::assertSame("\xFE\xFF\x00\x5A\x00\x6F\x00\xEB", TextString::encode('Zoë')->bytes);
    }

    #[Test]
    public function decodes_every_text_string_form(): void
    {
        self::assertSame('Иван', TextString::decode(TextString::encode('Иван')));
        self::assertSame('Zoë', TextString::decode(new PdfString("Zo\xEB")), 'PDFDocEncoding');
        self::assertSame('€ • —', TextString::decode(new PdfString("\xA0 \x80 \x84")), 'PDFDocEncoding high codes');
        self::assertSame('ok', TextString::decode(new PdfString("\xEF\xBB\xBFok")), 'UTF-8 with BOM (PDF 2.0)');
        self::assertSame('Yes', TextString::decode(new PdfName('Yes')));
        self::assertNull(TextString::decode(42));
    }

    #[Test]
    public function parses_default_appearance_strings(): void
    {
        $da = DefaultAppearance::parse('/Helv 0 Tf 0 0 1 rg');
        self::assertSame('Helv', $da->fontName);
        self::assertSame(0.0, $da->fontSize);
        self::assertSame('0 0 1 rg', $da->color);

        $da = DefaultAppearance::parse('0.2 g /TiRo 9.5 Tf');
        self::assertSame('TiRo', $da->fontName);
        self::assertSame(9.5, $da->fontSize);
        self::assertSame('0.2 g', $da->color);

        $da = DefaultAppearance::parse(null);
        self::assertNull($da->fontName);
        self::assertSame('0 g', $da->color);
    }
}
