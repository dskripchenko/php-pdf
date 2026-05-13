<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PocR8Test extends TestCase
{
    protected function setUp(): void
    {
        if (! is_readable(__DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf')) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
    }

    #[Test]
    public function emits_two_link_annotations_with_correct_subtypes(): void
    {
        $pdf = (string) shell_exec('php '.escapeshellarg(__DIR__.'/../../pocs/r8a/run.php'));

        // 2 link-аннотации.
        $linkCount = substr_count($pdf, '/Subtype /Link');
        self::assertSame(2, $linkCount);
    }

    #[Test]
    public function external_link_has_uri_action(): void
    {
        $pdf = (string) shell_exec('php '.escapeshellarg(__DIR__.'/../../pocs/r8a/run.php'));

        self::assertStringContainsString('/A << /Type /Action /S /URI', $pdf);
        self::assertStringContainsString('(https://example.com)', $pdf);
    }

    #[Test]
    public function internal_link_has_dest_array(): void
    {
        $pdf = (string) shell_exec('php '.escapeshellarg(__DIR__.'/../../pocs/r8a/run.php'));

        // Внутренний link → /Dest [pageRef /XYZ x y zoom].
        self::assertMatchesRegularExpression('/\/Dest \[\d+ 0 R \/XYZ /', $pdf);
    }

    #[Test]
    public function page_1_annots_array_references_both_annotations(): void
    {
        $pdf = (string) shell_exec('php '.escapeshellarg(__DIR__.'/../../pocs/r8a/run.php'));

        // Page object с /Annots [N 0 R M 0 R].
        self::assertMatchesRegularExpression('/\/Annots \[\d+ 0 R \d+ 0 R\]/', $pdf);
    }

    #[Test]
    public function pdftotext_extracts_link_visible_text(): void
    {
        if (! $this->commandExists('pdftotext')) {
            self::markTestSkipped('pdftotext not installed.');
        }
        $pdf = (string) shell_exec('php '.escapeshellarg(__DIR__.'/../../pocs/r8a/run.php'));
        $tmp = tempnam(sys_get_temp_dir(), 'r8-');
        file_put_contents($tmp, $pdf);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Visit example.com', $text);
            self::assertStringContainsString('Jump to page 2', $text);
            self::assertStringContainsString('Hello from page 2', $text);
        } finally {
            @unlink($tmp);
        }
    }

    private function commandExists(string $cmd): bool
    {
        $out = shell_exec('which '.escapeshellarg($cmd).' 2>/dev/null');

        return is_string($out) && trim($out) !== '';
    }
}
