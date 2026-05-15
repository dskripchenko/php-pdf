<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 234: text-transform CSS (uppercase/lowercase/capitalize).
 */
final class HtmlTextTransformTest extends TestCase
{
    private function firstRunText(string $html): string
    {
        $blocks = (new HtmlParser)->parse($html);
        $run = $blocks[0]->children[0];
        self::assertInstanceOf(Run::class, $run);
        return $run->text;
    }

    // ---- block-level ----

    #[Test]
    public function p_uppercase(): void
    {
        $text = $this->firstRunText('<p style="text-transform: uppercase">hello world</p>');
        self::assertSame('HELLO WORLD', $text);
    }

    #[Test]
    public function p_lowercase(): void
    {
        $text = $this->firstRunText('<p style="text-transform: lowercase">HELLO WORLD</p>');
        self::assertSame('hello world', $text);
    }

    #[Test]
    public function p_capitalize(): void
    {
        $text = $this->firstRunText('<p style="text-transform: capitalize">hello world foo</p>');
        self::assertSame('Hello World Foo', $text);
    }

    #[Test]
    public function p_none_no_change(): void
    {
        $text = $this->firstRunText('<p style="text-transform: none">Mixed CASE</p>');
        self::assertSame('Mixed CASE', $text);
    }

    #[Test]
    public function p_default_no_transform(): void
    {
        $text = $this->firstRunText('<p>Original CASE</p>');
        self::assertSame('Original CASE', $text);
    }

    // ---- inline-level ----

    #[Test]
    public function span_uppercase_within_normal(): void
    {
        $blocks = (new HtmlParser)->parse(
            '<p>before <span style="text-transform: uppercase">upper</span> after</p>'
        );
        $runs = $blocks[0]->children;
        // Concatenate all run texts.
        $allText = '';
        foreach ($runs as $r) {
            if ($r instanceof Run) {
                $allText .= $r->text;
            }
        }
        self::assertStringContainsString('UPPER', $allText);
        self::assertStringContainsString('before', $allText);
        self::assertStringContainsString('after', $allText);
    }

    #[Test]
    public function nested_transform_override(): void
    {
        // Outer uppercase, inner lowercase.
        $blocks = (new HtmlParser)->parse(
            '<p style="text-transform: uppercase">'
            .'outer '
            .'<span style="text-transform: lowercase">INNER</span>'
            .' tail</p>'
        );
        $runs = $blocks[0]->children;
        $texts = [];
        foreach ($runs as $r) {
            if ($r instanceof Run) {
                $texts[] = $r->text;
            }
        }
        // Outer parts uppercased, inner span lowercased.
        $combined = implode('', $texts);
        self::assertStringContainsString('OUTER', $combined);
        self::assertStringContainsString('inner', $combined);
        self::assertStringContainsString('TAIL', $combined);
    }

    #[Test]
    public function unicode_uppercase(): void
    {
        $text = $this->firstRunText('<p style="text-transform: uppercase">привет</p>');
        self::assertSame('ПРИВЕТ', $text);
    }

    #[Test]
    public function unicode_lowercase(): void
    {
        $text = $this->firstRunText('<p style="text-transform: lowercase">ПРИВЕТ</p>');
        self::assertSame('привет', $text);
    }

    #[Test]
    public function capitalize_unicode(): void
    {
        $text = $this->firstRunText('<p style="text-transform: capitalize">привет мир</p>');
        self::assertSame('Привет Мир', $text);
    }

    #[Test]
    public function transform_with_other_styling(): void
    {
        $text = $this->firstRunText(
            '<p style="text-transform: uppercase; color: red">tag</p>'
        );
        self::assertSame('TAG', $text);
    }

    #[Test]
    public function heading_text_transform(): void
    {
        $blocks = (new HtmlParser)->parse(
            '<h1 style="text-transform: uppercase">title</h1>'
        );
        $run = $blocks[0]->children[0];
        self::assertSame('TITLE', $run->text);
    }
}
