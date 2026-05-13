<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StandardFontTest extends TestCase
{
    #[Test]
    public function all_14_fonts_present(): void
    {
        // Adobe base-14 (PDF спека ISO 32000-1 §9.6.2.2).
        self::assertCount(14, StandardFont::cases());
    }

    #[Test]
    public function postscript_name_matches_value(): void
    {
        self::assertSame('Times-Roman', StandardFont::TimesRoman->postScriptName());
        self::assertSame('Helvetica-BoldOblique', StandardFont::HelveticaBoldOblique->postScriptName());
        self::assertSame('Symbol', StandardFont::Symbol->postScriptName());
    }

    #[Test]
    public function pdf_dictionary_uses_type1_with_winansi(): void
    {
        $dict = StandardFont::TimesRoman->pdfDictionary();
        self::assertStringContainsString('/Type /Font', $dict);
        self::assertStringContainsString('/Subtype /Type1', $dict);
        self::assertStringContainsString('/BaseFont /Times-Roman', $dict);
        self::assertStringContainsString('/Encoding /WinAnsiEncoding', $dict);
    }

    #[Test]
    public function symbol_and_zapfdingbats_have_no_explicit_encoding(): void
    {
        // Symbol и ZapfDingbats имеют built-in encoding, не WinAnsi.
        self::assertStringNotContainsString('WinAnsiEncoding', StandardFont::Symbol->pdfDictionary());
        self::assertStringNotContainsString('WinAnsiEncoding', StandardFont::ZapfDingbats->pdfDictionary());
    }

    #[Test]
    public function classification_predicates(): void
    {
        self::assertTrue(StandardFont::TimesRoman->isSerif());
        self::assertFalse(StandardFont::Helvetica->isSerif());

        self::assertTrue(StandardFont::Courier->isMonospaced());
        self::assertFalse(StandardFont::TimesRoman->isMonospaced());

        self::assertTrue(StandardFont::TimesBold->isBold());
        self::assertFalse(StandardFont::TimesRoman->isBold());

        self::assertTrue(StandardFont::TimesItalic->isItalic());
        self::assertTrue(StandardFont::HelveticaOblique->isItalic());
        self::assertFalse(StandardFont::TimesRoman->isItalic());
    }
}
