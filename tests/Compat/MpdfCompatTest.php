<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Compat;

use Dskripchenko\PhpPdf\Compat\Mpdf;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MpdfCompatTest extends TestCase
{
    private function extractText(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phppdf-mpdf-');
        file_put_contents($tmp, $bytes);
        $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>/dev/null');
        unlink($tmp);

        return $text;
    }

    #[Test]
    public function five_line_migration_example_produces_a_pdf(): void
    {
        $mpdf = new Mpdf;
        $mpdf->WriteHTML('<h1>Invoice #77</h1><p>Total: <strong>$41.00</strong></p>');
        $bytes = $mpdf->Output('', 'S');

        self::assertIsString($bytes);
        self::assertStringStartsWith('%PDF-', $bytes);
        $text = $this->extractText($bytes);
        self::assertStringContainsString('Invoice #77', $text);
        self::assertStringContainsString('$41.00', $text);
    }

    #[Test]
    public function repeated_write_html_appends(): void
    {
        $mpdf = new Mpdf;
        $mpdf->WriteHTML('<p>First fragment.</p>');
        $mpdf->WriteHTML('<p>Second fragment.</p>');
        $text = $this->extractText((string) $mpdf->Output('', 'S'));

        self::assertStringContainsString('First fragment.', $text);
        self::assertStringContainsString('Second fragment.', $text);
    }

    #[Test]
    public function add_page_starts_a_new_page(): void
    {
        $mpdf = new Mpdf;
        $mpdf->WriteHTML('<p>Page one.</p>');
        $mpdf->AddPage();
        $mpdf->WriteHTML('<p>Page two.</p>');
        $bytes = (string) $mpdf->Output('', 'S');

        self::assertSame(2, preg_match_all('@/Type /Page(?![s\w])@', $bytes));
        // pdftotext separates pages with \f.
        self::assertMatchesRegularExpression('@Page one\..*\f.*Page two\.@s', $this->extractText($bytes));
    }

    #[Test]
    public function output_f_writes_the_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phppdf-mpdf-out-');
        $mpdf = new Mpdf;
        $mpdf->WriteHTML('<p>To file.</p>');
        $result = $mpdf->Output($path, 'F');

        self::assertNull($result);
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($path));
        unlink($path);
    }

    #[Test]
    public function legacy_output_name_without_dest_means_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phppdf-mpdf-legacy-');
        $mpdf = new Mpdf;
        $mpdf->WriteHTML('<p>x</p>');
        $mpdf->Output($path);

        self::assertStringStartsWith('%PDF-', (string) file_get_contents($path));
        unlink($path);
    }

    #[Test]
    public function metadata_setters_reach_the_info_dictionary(): void
    {
        $mpdf = new Mpdf;
        $mpdf->SetTitle('Compat Title');
        $mpdf->SetAuthor('Compat Author');
        $mpdf->WriteHTML('<p>x</p>');
        $bytes = (string) $mpdf->Output('', 'S');

        self::assertStringContainsString('(Compat Title)', $bytes);
        self::assertStringContainsString('(Compat Author)', $bytes);
    }

    #[Test]
    public function format_and_margins_config_is_honoured(): void
    {
        $mpdf = new Mpdf(['format' => 'Letter', 'margin_left' => 10]);
        $mpdf->WriteHTML('<p>x</p>');
        $bytes = (string) $mpdf->Output('', 'S');

        // Letter = 612 pt wide.
        self::assertStringContainsString('/MediaBox [0 0 612 792]', $bytes);
    }

    #[Test]
    public function landscape_format_suffix_is_honoured(): void
    {
        $mpdf = new Mpdf(['format' => 'A4-L']);
        $mpdf->WriteHTML('<p>x</p>');
        $bytes = (string) $mpdf->Output('', 'S');

        self::assertStringContainsString('/MediaBox [0 0 841.89 595.28]', $bytes);
    }
}
