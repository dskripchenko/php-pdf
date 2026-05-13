<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end проверка POC-R2.a (Latin TTF embedding) и
 * POC-R2.b (Cyrillic + mixed scripts).
 *
 * Запускает реальные POC scripts, проверяет:
 *   - PDF валиден (правильный header/EOF)
 *   - pdftotext извлекает Latin/Cyrillic текст КОРРЕКТНО (т.е. через
 *     ToUnicode CMap, а не как glyph-ID hex). Это самая важная
 *     валидация для R2.c.
 */
final class PocR2Test extends TestCase
{
    protected function setUp(): void
    {
        $fontPath = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($fontPath)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
    }

    #[Test]
    public function poc_r2a_latin_hello_world_extracts_correctly(): void
    {
        if (! $this->commandExists('pdftotext')) {
            self::markTestSkipped('pdftotext not installed.');
        }

        $pdfBytes = $this->runScript(__DIR__.'/../../pocs/r2a/run.php');

        self::assertStringStartsWith('%PDF-1.7', $pdfBytes);
        self::assertStringContainsString('/FontFile2', $pdfBytes);
        self::assertStringContainsString('LiberationSans', $pdfBytes);

        // Embed весь Liberation Sans Regular (~410 KB).
        self::assertGreaterThan(400_000, strlen($pdfBytes));

        // Самая важная проверка: pdftotext (poppler — independent
        // implementation) корректно извлекает Latin text через ToUnicode.
        $extracted = $this->pdftotext($pdfBytes);
        self::assertStringContainsString('Hello, world!', $extracted);
    }

    #[Test]
    public function poc_r2b_cyrillic_extracts_correctly(): void
    {
        if (! $this->commandExists('pdftotext')) {
            self::markTestSkipped('pdftotext not installed.');
        }

        $pdfBytes = $this->runScript(__DIR__.'/../../pocs/r2b/run.php');

        $extracted = $this->pdftotext($pdfBytes);
        // Cyrillic Привет, мир!
        self::assertStringContainsString('Привет, мир!', $extracted);
        // Mixed scripts + em-dash через ToUnicode (surrogate-pair logic
        // не нужна для em-dash U+2014, но проверяем что non-BMP-similar
        // chars работают).
        self::assertStringContainsString('Mixed', $extracted);
        self::assertStringContainsString('Hello', $extracted);
        self::assertStringContainsString('—', $extracted); // em-dash
    }

    private function runScript(string $path): string
    {
        $output = shell_exec('php '.escapeshellarg($path).' 2>&1');
        if ($output === null || $output === false) {
            self::fail("Script returned no output: $path");
        }

        return $output;
    }

    private function pdftotext(string $pdfBytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pdf-r2-');
        file_put_contents($tmp, $pdfBytes);
        try {
            return (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>&1');
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
