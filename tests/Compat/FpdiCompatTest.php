<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Compat;

use Dskripchenko\PhpPdf\Compat\Fpdi;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FpdiCompatTest extends TestCase
{
    /**
     * Two-page source: "SOURCE ALPHA" on page 1, "SOURCE BETA" on page 2.
     */
    private function sourcePdf(): string
    {
        $doc = PdfDocument::new();
        $doc->addPage()->showText('SOURCE ALPHA', 72, 720, StandardFont::Helvetica, 24);
        $doc->addPage()->showText('SOURCE BETA', 72, 720, StandardFont::Helvetica, 24);

        return $doc->toBytes();
    }

    /**
     * Raw bytes with every FlateDecode stream replaced by its inflation,
     * so content-stream operators are searchable.
     */
    private function inflateStreams(string $bytes): string
    {
        return (string) preg_replace_callback(
            '@stream\r?\n(.*?)\r?\nendstream@s',
            function (array $m): string {
                $inflated = @gzuncompress($m[1]);

                return $inflated === false ? $m[0] : "stream\n".$inflated."\nendstream";
            },
            $bytes,
        );
    }

    private function extractText(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phppdf-fpdi-');
        file_put_contents($tmp, $bytes);
        $text = (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>/dev/null');
        unlink($tmp);

        return $text;
    }

    #[Test]
    public function set_source_file_returns_page_count(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phppdf-fpdi-src-');
        file_put_contents($path, $this->sourcePdf());

        $pdf = new Fpdi;
        self::assertSame(2, $pdf->setSourceFile($path));
        unlink($path);
    }

    #[Test]
    public function import_and_use_template_carries_page_content(): void
    {
        $pdf = new Fpdi;
        $pdf->setSourceBytes($this->sourcePdf());
        $tpl = $pdf->importPage(2);
        $pdf->AddPage('', $pdf->getTemplateSize($tpl));
        $pdf->useTemplate($tpl);
        $bytes = (string) $pdf->Output('S');

        $text = $this->extractText($bytes);
        self::assertStringContainsString('SOURCE BETA', $text);
        self::assertStringNotContainsString('SOURCE ALPHA', $text);
    }

    #[Test]
    public function proportional_scaling_from_width(): void
    {
        $pdf = new Fpdi; // mm units, A4 template source (595.28×841.89 pt)
        $pdf->setSourceBytes($this->sourcePdf());
        $tpl = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($tpl, width: 100.0);

        self::assertEqualsWithDelta(100.0, $size['width'], 0.01);
        self::assertEqualsWithDelta(100.0 * 841.89 / 595.28, $size['height'], 0.01);
    }

    #[Test]
    public function use_template_places_at_fpdf_coordinates(): void
    {
        $pdf = new Fpdi; // mm
        $pdf->setSourceBytes($this->sourcePdf());
        $tpl = $pdf->importPage(1);
        $pdf->AddPage();
        $placed = $pdf->useTemplate($tpl, x: 10, y: 20, width: 100);
        $bytes = (string) $pdf->Output('S');

        self::assertEqualsWithDelta(100.0, $placed['width'], 0.01);
        // The form placement matrix must appear in the (deflated) content
        // stream: x = 10mm = 28.35pt; y = pageH - 20mm - height.
        $k = 72 / 25.4;
        $hPt = $placed['height'] * $k;
        $expectedX = 10 * $k;
        $expectedY = 841.89 - 20 * $k - $hPt;
        $inflated = $this->inflateStreams($bytes);
        self::assertSame(1, preg_match('@/Fm\d+ Do@', $inflated), 'Form XObject must be drawn');
        preg_match_all('@([\d.]+) 0 0 ([\d.]+) ([\d.]+) ([\d.]+) cm@', $inflated, $all, PREG_SET_ORDER);
        $found = false;
        foreach ($all as $m) {
            if (abs((float) $m[3] - $expectedX) < 0.01 && abs((float) $m[4] - $expectedY) < 0.01) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, sprintf(
            'No placement matrix with tx=%.2f ty=%.2f among %d cm matrices',
            $expectedX, $expectedY, count($all),
        ));
    }

    #[Test]
    public function two_imports_on_one_page_both_render(): void
    {
        $pdf = new Fpdi;
        $pdf->setSourceBytes($this->sourcePdf());
        $a = $pdf->importPage(1);
        $b = $pdf->importPage(2);
        $pdf->AddPage();
        $pdf->useTemplate($a, x: 0, y: 0, width: 100);
        $pdf->useTemplate($b, x: 0, y: 150, width: 100);
        $text = $this->extractText((string) $pdf->Output('S'));

        self::assertStringContainsString('SOURCE ALPHA', $text);
        self::assertStringContainsString('SOURCE BETA', $text);
    }

    #[Test]
    public function legacy_output_argument_order_writes_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phppdf-fpdi-out-');
        $pdf = new Fpdi;
        $pdf->setSourceBytes($this->sourcePdf());
        $tpl = $pdf->importPage(1);
        $pdf->AddPage();
        $pdf->useTemplate($tpl);
        $pdf->Output($path, 'F'); // legacy (name, dest) order

        self::assertStringStartsWith('%PDF-', (string) file_get_contents($path));
        unlink($path);
    }

    #[Test]
    public function import_page_requires_a_source(): void
    {
        $this->expectException(\LogicException::class);
        (new Fpdi)->importPage(1);
    }

    #[Test]
    public function use_template_requires_a_page(): void
    {
        $pdf = new Fpdi;
        $pdf->setSourceBytes($this->sourcePdf());
        $tpl = $pdf->importPage(1);
        $this->expectException(\LogicException::class);
        $pdf->useTemplate($tpl);
    }
}
