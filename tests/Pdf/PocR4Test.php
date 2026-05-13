<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PocR4Test extends TestCase
{
    protected function setUp(): void
    {
        if (! is_readable(__DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf')) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
    }

    #[Test]
    public function emits_pdf_with_three_columns_content(): void
    {
        if (! $this->commandExists('pdftotext')) {
            self::markTestSkipped('pdftotext not installed.');
        }
        $pdfBytes = (string) shell_exec('php '.escapeshellarg(__DIR__.'/../../pocs/r4a/run.php'));
        $tmp = tempnam(sys_get_temp_dir(), 'r4-');
        file_put_contents($tmp, $pdfBytes);
        try {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
            // Все 3 columns content присутствует.
            self::assertStringContainsString('Acme Insurance', $text);
            self::assertStringContainsString('Group Ltd.', $text);
            self::assertStringContainsString('ПОЛИС СТРАХОВАНИЯ', $text);
            self::assertStringContainsString('ВЗР × СЕМЬЯ', $text);
            self::assertStringContainsString('№ 12345', $text);
            self::assertStringContainsString('13.05.2026', $text);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function content_stream_has_fill_and_stroke_operators(): void
    {
        $pdfBytes = (string) shell_exec('php '.escapeshellarg(__DIR__.'/../../pocs/r4a/run.php'));

        // 3 stroke rectangles (cell borders): 3 × `RG` setColor calls.
        $strokeColors = substr_count($pdfBytes, ' RG');
        self::assertGreaterThanOrEqual(3, $strokeColors);

        // Fills: 3 cell backgrounds + text fills.
        $fillColors = substr_count($pdfBytes, ' rg');
        self::assertGreaterThanOrEqual(3, $fillColors);

        // re-операторов (rectangle) — total 6 (3 fill + 3 stroke).
        $rectangles = substr_count($pdfBytes, ' re');
        self::assertGreaterThanOrEqual(6, $rectangles);
    }

    private function commandExists(string $cmd): bool
    {
        $out = shell_exec('which '.escapeshellarg($cmd).' 2>/dev/null');

        return is_string($out) && trim($out) !== '';
    }
}
