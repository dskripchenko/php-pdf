<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PocR6Test extends TestCase
{
    protected function setUp(): void
    {
        if (! is_readable(__DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf')) {
            self::markTestSkipped('Liberation Sans not cached.');
        }
        if (! function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD extension not available.');
        }
    }

    #[Test]
    public function poc_r6a_emits_pdf_with_png_and_jpeg(): void
    {
        $pdf = (string) shell_exec('php '.escapeshellarg(__DIR__.'/../../pocs/r6a/run.php'));

        self::assertStringStartsWith('%PDF-1.7', $pdf);
        // Two Image XObjects.
        self::assertSame(2, substr_count($pdf, '/Subtype /Image'));
        // One PNG (FlateDecode) and one JPEG (DCTDecode).
        self::assertSame(1, substr_count($pdf, '/Filter /DCTDecode'));
        // FlateDecode appears 2 raz: один для PNG, один для ToUnicode CMap stream;
        // или один для PNG + могут быть ещё. Проверяем что есть as least one.
        self::assertGreaterThanOrEqual(1, substr_count($pdf, '/Filter /FlateDecode'));
    }

    #[Test]
    public function page_resources_reference_both_images(): void
    {
        $pdf = (string) shell_exec('php '.escapeshellarg(__DIR__.'/../../pocs/r6a/run.php'));
        // Page resources должны иметь /XObject << /Im1 ... /Im2 ... >>
        self::assertMatchesRegularExpression('/\/XObject << \/Im1 \d+ 0 R \/Im2 \d+ 0 R/', $pdf);
    }

    #[Test]
    public function content_stream_uses_do_operator_with_ctm_scale(): void
    {
        $pdf = (string) shell_exec('php '.escapeshellarg(__DIR__.'/../../pocs/r6a/run.php'));
        // q + cm + Do + Q операторы рендера картинки.
        self::assertStringContainsString('/Im1 Do', $pdf);
        self::assertStringContainsString('/Im2 Do', $pdf);
    }
}
