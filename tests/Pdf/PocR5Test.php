<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PocR5Test extends TestCase
{
    protected function setUp(): void
    {
        if (! is_readable(__DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf')) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
    }

    #[Test]
    public function multipage_pdf_has_three_pages_with_distinct_text(): void
    {
        $pdf = (string) shell_exec('php '.escapeshellarg(__DIR__.'/../../pocs/r5a/run.php'));

        self::assertStringStartsWith('%PDF-1.7', $pdf);
        // Pages tree должен указывать /Count 3.
        self::assertMatchesRegularExpression('/\/Count 3/', $pdf);

        // Все 3 Page objects.
        $pageMatches = preg_match_all('/\/Type \/Page /', $pdf);
        self::assertSame(3, $pageMatches);
    }

    #[Test]
    public function pdftotext_extracts_text_from_all_pages(): void
    {
        if (! $this->commandExists('pdftotext')) {
            self::markTestSkipped('pdftotext not installed.');
        }
        $pdf = (string) shell_exec('php '.escapeshellarg(__DIR__.'/../../pocs/r5a/run.php'));
        $tmp = tempnam(sys_get_temp_dir(), 'r5-');
        file_put_contents($tmp, $pdf);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            self::assertStringContainsString('Page 1 of 3', $text);
            self::assertStringContainsString('Page 2 of 3', $text);
            self::assertStringContainsString('Page 3 of 3', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function pages_share_same_font_resource(): void
    {
        $pdf = (string) shell_exec('php '.escapeshellarg(__DIR__.'/../../pocs/r5a/run.php'));

        // Все 3 pages должны ссылаться на тот же font resource ID.
        // Если каждая page имела бы свой font, мы бы видели несколько
        // /FontFile2 streams. Проверяем что только один TTF embedded.
        $fontFile2Count = substr_count($pdf, '/FontFile2');
        self::assertSame(1, $fontFile2Count, 'shared font resource between pages');
    }

    private function commandExists(string $cmd): bool
    {
        $out = shell_exec('which '.escapeshellarg($cmd).' 2>/dev/null');

        return is_string($out) && trim($out) !== '';
    }
}
