<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Reader;

use Dskripchenko\PhpPdf\Pdf\Reader\Lexer;
use Dskripchenko\PhpPdf\Pdf\Reader\ObjectParser;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfNull;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfParseException;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase P1: object-syntax lexer + parser.
 */
final class ObjectParserTest extends TestCase
{
    private function parse(string $src): mixed
    {
        return (new ObjectParser(new Lexer($src)))->parseValue();
    }

    #[Test]
    public function parses_integer_and_real(): void
    {
        self::assertSame(42, $this->parse('42'));
        self::assertSame(-7, $this->parse('-7'));
        self::assertSame(3.14, $this->parse('3.14'));
        self::assertSame(0.5, $this->parse('.5'));
        self::assertSame(4.0, $this->parse('4.'));
    }

    #[Test]
    public function parses_booleans_and_null(): void
    {
        self::assertTrue($this->parse('true'));
        self::assertFalse($this->parse('false'));
        self::assertSame(PdfNull::instance(), $this->parse('null'));
    }

    #[Test]
    public function parses_name_with_hex_escape(): void
    {
        $v = $this->parse('/Name#20With#2FSlash');
        self::assertInstanceOf(PdfName::class, $v);
        self::assertSame('Name With/Slash', $v->value);
    }

    #[Test]
    public function parses_literal_string_with_escapes_and_nesting(): void
    {
        $v = $this->parse('(a\\(b\\)c\\n\\101 (nested))');
        self::assertInstanceOf(PdfString::class, $v);
        self::assertSame("a(b)c\nA (nested)", $v->bytes);
    }

    #[Test]
    public function parses_hex_string_with_odd_padding(): void
    {
        $v = $this->parse('<48656C6C6F>');
        self::assertInstanceOf(PdfString::class, $v);
        self::assertSame('Hello', $v->bytes);

        $odd = $this->parse('<41 4>'); // -> 41 40 = "A@"
        self::assertInstanceOf(PdfString::class, $odd);
        self::assertSame("A@", $odd->bytes);
    }

    #[Test]
    public function parses_indirect_reference(): void
    {
        $v = $this->parse('12 0 R');
        self::assertInstanceOf(PdfReference::class, $v);
        self::assertSame(12, $v->number);
        self::assertSame(0, $v->generation);
        self::assertSame('12 0', $v->key());
    }

    #[Test]
    public function bare_number_is_not_a_reference(): void
    {
        self::assertSame(12, $this->parse('12 0'));
    }

    #[Test]
    public function parses_array_of_mixed_values(): void
    {
        $v = $this->parse('[1 2.5 /Foo (bar) 3 0 R true]');
        self::assertIsArray($v);
        self::assertSame(1, $v[0]);
        self::assertSame(2.5, $v[1]);
        self::assertInstanceOf(PdfName::class, $v[2]);
        self::assertInstanceOf(PdfString::class, $v[3]);
        self::assertInstanceOf(PdfReference::class, $v[4]);
        self::assertTrue($v[5]);
        self::assertCount(6, $v);
    }

    #[Test]
    public function parses_nested_dictionary(): void
    {
        $v = $this->parse('<< /Type /Page /Count 3 /Kids [4 0 R 5 0 R] /Sub << /A 1 >> >>');
        self::assertInstanceOf(PdfDictionary::class, $v);
        self::assertInstanceOf(PdfName::class, $v->get('Type'));
        self::assertSame('Page', $v->get('Type')->value);
        self::assertSame(3, $v->get('Count'));
        self::assertIsArray($v->get('Kids'));
        self::assertCount(2, $v->get('Kids'));
        self::assertInstanceOf(PdfDictionary::class, $v->get('Sub'));
        self::assertSame(1, $v->get('Sub')->get('A'));
    }

    #[Test]
    public function parses_stream_with_direct_length(): void
    {
        $body = "BT /F1 12 Tf ET";
        $src = "<< /Length " . strlen($body) . " >>\nstream\n{$body}\nendstream";
        $v = $this->parse($src);
        self::assertInstanceOf(PdfStream::class, $v);
        self::assertSame($body, $v->raw);
        self::assertSame(strlen($body), $v->dict->get('Length'));
    }

    #[Test]
    public function parses_stream_by_scanning_when_length_indirect(): void
    {
        $body = "some binary-ish payload";
        $src = "<< /Length 9 0 R >>\nstream\n{$body}\nendstream";
        $v = $this->parse($src);
        self::assertInstanceOf(PdfStream::class, $v);
        self::assertSame($body, $v->raw);
    }

    #[Test]
    public function parses_indirect_object_wrapper(): void
    {
        $parser = new ObjectParser(new Lexer("7 0 obj\n<< /A 1 >>\nendobj"));
        $obj = $parser->parseIndirectObject();
        self::assertSame(7, $obj['number']);
        self::assertSame(0, $obj['generation']);
        self::assertInstanceOf(PdfDictionary::class, $obj['value']);
        self::assertSame(1, $obj['value']->get('A'));
    }

    #[Test]
    public function skips_comments_and_whitespace(): void
    {
        $v = $this->parse("% a comment\n  42 % trailing\n");
        self::assertSame(42, $v);
    }

    #[Test]
    public function throws_on_unterminated_dictionary(): void
    {
        $this->expectException(PdfParseException::class);
        $this->parse('<< /A 1');
    }
}
