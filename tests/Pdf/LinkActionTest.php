<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 113: Named/JavaScript/Launch link action types.
 */
final class LinkActionTest extends TestCase
{
    private function emit(callable $configure): string
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $configure($page);

        return $pdf->toBytes();
    }

    #[Test]
    public function named_action_next_page(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addNamedActionLink(50, 50, 100, 30, 'NextPage'));

        self::assertStringContainsString('/A << /Type /Action /S /Named /N /NextPage >>', $bytes);
    }

    #[Test]
    public function named_action_print(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addNamedActionLink(0, 0, 10, 10, 'Print'));
        self::assertStringContainsString('/N /Print', $bytes);
    }

    #[Test]
    public function invalid_named_action_throws(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $this->expectException(\InvalidArgumentException::class);
        $page->addNamedActionLink(0, 0, 10, 10, 'Bogus');
    }

    #[Test]
    public function javascript_link_emits_js_action(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addJavaScriptLink(0, 0, 50, 20, "app.alert('hi')"));

        self::assertStringContainsString('/Type /Action /S /JavaScript /JS', $bytes);
        self::assertStringContainsString("(app.alert\\('hi'\\))", $bytes);
    }

    #[Test]
    public function launch_link_emits_launch_action(): void
    {
        $bytes = $this->emit(fn ($p) => $p->addLaunchLink(0, 0, 50, 20, 'companion.docx'));

        self::assertStringContainsString('/Type /Action /S /Launch /F (companion.docx)', $bytes);
    }

    #[Test]
    public function all_link_kinds_emit_subtype_link(): void
    {
        $bytes = $this->emit(function ($p) {
            $p->addExternalLink(0, 0, 10, 10, 'https://x');
            $p->addNamedActionLink(20, 0, 10, 10, 'Find');
            $p->addJavaScriptLink(40, 0, 10, 10, 'noop');
            $p->addLaunchLink(60, 0, 10, 10, 'a.pdf');
        });

        self::assertSame(4, substr_count($bytes, '/Subtype /Link'));
    }
}
