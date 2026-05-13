<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration test для POC-R9.a — Hello World PDF.
 *
 * Запускает реальный pocs/r9a/run.php script и проверяет получаемый PDF:
 *  - Имеет валидный %PDF-X.Y magic header
 *  - Заканчивается %%EOF
 *  - pdftotext извлекает ожидаемый "Hello, world!" текст
 *
 * Skip'ится если pdftotext не установлен.
 */
final class PocR9aTest extends TestCase
{
    private const string SCRIPT_PATH = __DIR__.'/../../pocs/r9a/run.php';

    #[Test]
    public function script_produces_valid_pdf_with_expected_text(): void
    {
        $pdf = $this->runScript();

        self::assertStringStartsWith('%PDF-1.7', $pdf);
        self::assertStringEndsWith("%%EOF\n", $pdf);
        self::assertStringContainsString('/Type /Catalog', $pdf);
        self::assertStringContainsString('/Type /Pages', $pdf);
        self::assertStringContainsString('/Type /Page ', $pdf);
        self::assertStringContainsString('/Times-Roman', $pdf);
        self::assertStringContainsString('Hello, world!', $pdf);
        self::assertStringContainsString('xref', $pdf);
        self::assertStringContainsString('startxref', $pdf);
    }

    #[Test]
    public function pdftotext_extracts_hello_world(): void
    {
        if (! $this->commandExists('pdftotext')) {
            self::markTestSkipped('pdftotext (poppler) not installed; skipping text-extraction validation.');
        }

        $pdf = $this->runScript();
        $tmpPdf = tempnam(sys_get_temp_dir(), 'pdf-pocr9a-');
        file_put_contents($tmpPdf, $pdf);

        try {
            $extracted = (string) shell_exec('pdftotext '.escapeshellarg($tmpPdf).' - 2>&1');
            self::assertStringContainsString('Hello, world!', $extracted);
        } finally {
            @unlink($tmpPdf);
        }
    }

    #[Test]
    public function output_file_size_is_reasonable_under_1kb(): void
    {
        // Hello World PDF без font embedding должен быть очень компактным
        // (~600 байт). Если уйдёт за 1KB — проверить нет ли regression
        // в Writer (лишние объекты, дубли, etc.).
        $pdf = $this->runScript();
        self::assertLessThan(1024, strlen($pdf));
        self::assertGreaterThan(400, strlen($pdf));
    }

    private function runScript(): string
    {
        $output = shell_exec('php '.escapeshellarg(self::SCRIPT_PATH).' 2>&1');
        if ($output === null || $output === false) {
            self::fail('POC script returned no output');
        }

        return $output;
    }

    private function commandExists(string $cmd): bool
    {
        $out = shell_exec('which '.escapeshellarg($cmd).' 2>/dev/null');

        return is_string($out) && trim($out) !== '';
    }
}
