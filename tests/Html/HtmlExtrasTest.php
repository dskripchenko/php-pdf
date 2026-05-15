<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 235: HTML extras — <address>, <details>/<summary>, <wbr>, <picture>.
 */
final class HtmlExtrasTest extends TestCase
{
    private function parse(string $html): array
    {
        return (new HtmlParser)->parse($html);
    }

    // ---- <address> ----

    #[Test]
    public function address_italicized(): void
    {
        $blocks = $this->parse('<address>123 Main St, Anytown</address>');
        self::assertCount(1, $blocks);
        $p = $blocks[0];
        self::assertInstanceOf(Paragraph::class, $p);
        $run = $p->children[0];
        self::assertInstanceOf(Run::class, $run);
        self::assertTrue($run->style->italic);
    }

    // ---- <details> + <summary> ----

    #[Test]
    public function details_with_summary_bold(): void
    {
        $blocks = $this->parse(
            '<details>
                <summary>Click to expand</summary>
                <p>Hidden content</p>
            </details>'
        );
        // Summary first (bold), then content paragraph (indented).
        self::assertGreaterThanOrEqual(2, count($blocks));
        $summary = $blocks[0];
        self::assertInstanceOf(Paragraph::class, $summary);
        $summaryRun = $summary->children[0];
        self::assertInstanceOf(Run::class, $summaryRun);
        self::assertTrue($summaryRun->style->bold);
    }

    #[Test]
    public function details_content_indented(): void
    {
        $blocks = $this->parse(
            '<details><summary>S</summary><p>Body</p></details>'
        );
        // Last block = indented paragraph.
        $body = end($blocks);
        self::assertInstanceOf(Paragraph::class, $body);
        self::assertGreaterThan(0, $body->style->indentLeftPt);
    }

    #[Test]
    public function details_no_summary_uses_fallback(): void
    {
        $blocks = $this->parse('<details><p>Just content</p></details>');
        // First block = "Details" fallback (bold), then indented content.
        $first = $blocks[0];
        self::assertInstanceOf(Paragraph::class, $first);
        $run = $first->children[0];
        self::assertInstanceOf(Run::class, $run);
        self::assertSame('Details', $run->text);
        self::assertTrue($run->style->bold);
    }

    // ---- <wbr> ----

    #[Test]
    public function wbr_emits_soft_hyphen_marker(): void
    {
        $blocks = $this->parse('<p>looking<wbr>glass</p>');
        // Soft hyphen U+00AD inserted between texts.
        $combined = '';
        foreach ($blocks[0]->children as $r) {
            if ($r instanceof Run) {
                $combined .= $r->text;
            }
        }
        self::assertStringContainsString("\u{00AD}", $combined);
    }

    // ---- <picture> ----

    #[Test]
    public function picture_uses_first_img_fallback(): void
    {
        // Use real PNG fixture (1x1 transparent).
        $fixtureDir = __DIR__.'/../fixtures';
        $imgFile = $fixtureDir.'/1x1.png';
        if (! is_readable($imgFile)) {
            // Create tiny fixture on-the-fly если не exists.
            @mkdir($fixtureDir, 0755, true);
            $pngBytes = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
                true,
            );
            file_put_contents($imgFile, $pngBytes);
        }

        $blocks = $this->parse(
            '<p><picture>'
            .'<source srcset="foo.webp" type="image/webp">'
            ."<img src=\"{$imgFile}\" alt=\"fallback\">"
            .'</picture></p>'
        );
        $hasImage = false;
        foreach ($blocks[0]->children as $c) {
            if ($c instanceof \Dskripchenko\PhpPdf\Element\Image) {
                $hasImage = true;
                break;
            }
        }
        self::assertTrue($hasImage);
    }

    #[Test]
    public function picture_no_img_returns_nothing(): void
    {
        $blocks = $this->parse('<p>before <picture></picture> after</p>');
        // No image, just text runs.
        $images = array_filter(
            $blocks[0]->children,
            fn ($c) => $c instanceof \Dskripchenko\PhpPdf\Element\Image
        );
        self::assertCount(0, $images);
    }

    // ---- <address> и <details> in HTML5 context ----

    #[Test]
    public function address_in_footer(): void
    {
        $blocks = $this->parse(
            '<footer>
                <p>Copyright 2026</p>
                <address>contact@example.com</address>
            </footer>'
        );
        // footer transparent → 2 blocks (p + address).
        self::assertCount(2, $blocks);
        // Last = address (italic).
        $address = end($blocks);
        $run = $address->children[0];
        self::assertTrue($run->style->italic);
    }
}
