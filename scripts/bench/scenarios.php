<?php

declare(strict_types=1);

use Dskripchenko\PhpPdf\Build\DocumentBuilder;
use Dskripchenko\PhpPdf\Build\RowBuilder;
use Dskripchenko\PhpPdf\Build\TableBuilder;
use Dskripchenko\PhpPdf\Document as PpDocument;

// ---------- dskripchenko/php-pdf ----------

function bench_phppdf_hello(): string
{
    return DocumentBuilder::new()
        ->paragraph('Hello, world!')
        ->toBytes();
}

function bench_phppdf_invoice(): string
{
    $b = DocumentBuilder::new();
    for ($p = 0; $p < 100; $p++) {
        $b->heading(1, 'Invoice page ' . ($p + 1));
        $b->table(function (TableBuilder $t) {
            $t->headerRow(function (RowBuilder $r) {
                $r->cells(['Description', 'Unit price', 'Qty', 'Total']);
            });
            for ($i = 0; $i < 50; $i++) {
                $price = sprintf('%.2f', mt_rand(100, 9999) / 100);
                $t->row(function (RowBuilder $r) use ($i, $price) {
                    $r->cells(['Item ' . $i, $price, '1', $price]);
                });
            }
        });
        if ($p < 99) {
            $b->pageBreak();
        }
    }
    return $b->toBytes();
}

function bench_phppdf_images(): string
{
    $imgPath = __DIR__ . '/fixtures/photo.jpg';
    $b = DocumentBuilder::new();
    for ($p = 0; $p < 20; $p++) {
        $b->image($imgPath, widthPt: 250, heightPt: 180);
        $b->image($imgPath, widthPt: 250, heightPt: 180);
        $b->image($imgPath, widthPt: 250, heightPt: 180);
        $b->image($imgPath, widthPt: 250, heightPt: 180);
        if ($p < 19) {
            $b->pageBreak();
        }
    }
    return $b->toBytes();
}

function bench_phppdf_html(): string
{
    return PpDocument::fromHtml(bench_article_html())->toBytes();
}

// ---------- mpdf ----------

function bench_mpdf_hello(): string
{
    $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4']);
    $pdf->WriteHTML('<p>Hello, world!</p>');
    return $pdf->Output('', 'S');
}

function bench_mpdf_invoice(): string
{
    $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4']);
    for ($p = 0; $p < 100; $p++) {
        $html = '<h1>Invoice page ' . ($p + 1) . '</h1><table border="1" cellpadding="4"><tr><th>Description</th><th>Unit price</th><th>Qty</th><th>Total</th></tr>';
        for ($r = 0; $r < 50; $r++) {
            $price = sprintf('%.2f', mt_rand(100, 9999) / 100);
            $html .= '<tr><td>Item ' . $r . '</td><td>' . $price . '</td><td>1</td><td>' . $price . '</td></tr>';
        }
        $html .= '</table>';
        $pdf->WriteHTML($html);
        if ($p < 99) $pdf->AddPage();
    }
    return $pdf->Output('', 'S');
}

function bench_mpdf_images(): string
{
    $imgPath = __DIR__ . '/fixtures/photo.jpg';
    $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4']);
    for ($p = 0; $p < 20; $p++) {
        $html = '';
        for ($i = 0; $i < 4; $i++) {
            $html .= '<img src="' . $imgPath . '" width="250" height="180"/> ';
        }
        $pdf->WriteHTML($html);
        if ($p < 19) $pdf->AddPage();
    }
    return $pdf->Output('', 'S');
}

function bench_mpdf_html(): string
{
    $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4']);
    $pdf->WriteHTML(bench_article_html());
    return $pdf->Output('', 'S');
}

// ---------- tcpdf ----------

function bench_tcpdf_hello(): string
{
    $pdf = new \TCPDF('P', 'pt', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Write(0, 'Hello, world!');
    return $pdf->Output('', 'S');
}

function bench_tcpdf_invoice(): string
{
    $pdf = new \TCPDF('P', 'pt', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    for ($p = 0; $p < 100; $p++) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Write(0, 'Invoice page ' . ($p + 1));
        $pdf->Ln(20);
        $pdf->SetFont('helvetica', '', 9);
        $html = '<table border="1" cellpadding="3"><tr><th>Description</th><th>Unit price</th><th>Qty</th><th>Total</th></tr>';
        for ($r = 0; $r < 50; $r++) {
            $price = sprintf('%.2f', mt_rand(100, 9999) / 100);
            $html .= '<tr><td>Item ' . $r . '</td><td>' . $price . '</td><td>1</td><td>' . $price . '</td></tr>';
        }
        $html .= '</table>';
        $pdf->writeHTML($html, true, false, false, false, '');
    }
    return $pdf->Output('', 'S');
}

function bench_tcpdf_images(): string
{
    $imgPath = __DIR__ . '/fixtures/photo.jpg';
    $pdf = new \TCPDF('P', 'pt', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    for ($p = 0; $p < 20; $p++) {
        $pdf->AddPage();
        $y = 50;
        for ($i = 0; $i < 4; $i++) {
            $pdf->Image($imgPath, 50, $y, 250, 180, 'JPG');
            $y += 190;
        }
    }
    return $pdf->Output('', 'S');
}

function bench_tcpdf_html(): string
{
    $pdf = new \TCPDF('P', 'pt', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->writeHTML(bench_article_html(), true, false, false, false, '');
    return $pdf->Output('', 'S');
}

// ---------- dompdf ----------

function bench_dompdf_hello(): string
{
    $pdf = new \Dompdf\Dompdf();
    $pdf->loadHtml('<p>Hello, world!</p>');
    $pdf->render();
    return $pdf->output();
}

function bench_dompdf_invoice(): string
{
    $pdf = new \Dompdf\Dompdf();
    $html = '';
    for ($p = 0; $p < 100; $p++) {
        $html .= '<h1>Invoice page ' . ($p + 1) . '</h1>';
        $html .= '<table border="1" cellpadding="4" style="width:100%"><tr><th>Description</th><th>Unit price</th><th>Qty</th><th>Total</th></tr>';
        for ($r = 0; $r < 50; $r++) {
            $price = sprintf('%.2f', mt_rand(100, 9999) / 100);
            $html .= '<tr><td>Item ' . $r . '</td><td>' . $price . '</td><td>1</td><td>' . $price . '</td></tr>';
        }
        $html .= '</table><div style="page-break-after: always;"></div>';
    }
    $pdf->loadHtml($html);
    $pdf->render();
    return $pdf->output();
}

function bench_dompdf_images(): string
{
    $imgPath = __DIR__ . '/fixtures/photo.jpg';
    $pdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
    $html = '';
    for ($p = 0; $p < 20; $p++) {
        for ($i = 0; $i < 4; $i++) {
            $html .= '<img src="' . $imgPath . '" width="250" height="180"/>';
        }
        $html .= '<div style="page-break-after: always;"></div>';
    }
    $pdf->loadHtml($html);
    $pdf->render();
    return $pdf->output();
}

function bench_dompdf_html(): string
{
    $pdf = new \Dompdf\Dompdf();
    $pdf->loadHtml(bench_article_html());
    $pdf->render();
    return $pdf->output();
}

// ---------- FPDF ----------

function bench_fpdf_hello(): string
{
    $pdf = new \FPDF('P', 'pt', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->Cell(0, 14, 'Hello, world!');
    return $pdf->Output('S');
}

function bench_fpdf_invoice(): string
{
    $pdf = new \FPDF('P', 'pt', 'A4');
    for ($p = 0; $p < 100; $p++) {
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(0, 20, 'Invoice page ' . ($p + 1));
        $pdf->Ln(24);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(220, 12, 'Description', 1);
        $pdf->Cell(80, 12, 'Unit price', 1);
        $pdf->Cell(50, 12, 'Qty', 1);
        $pdf->Cell(80, 12, 'Total', 1);
        $pdf->Ln(12);
        $pdf->SetFont('Helvetica', '', 9);
        for ($r = 0; $r < 50; $r++) {
            $price = sprintf('%.2f', mt_rand(100, 9999) / 100);
            $pdf->Cell(220, 11, 'Item ' . $r, 1);
            $pdf->Cell(80, 11, $price, 1);
            $pdf->Cell(50, 11, '1', 1);
            $pdf->Cell(80, 11, $price, 1);
            $pdf->Ln(11);
        }
    }
    return $pdf->Output('S');
}

function bench_fpdf_images(): string
{
    $imgPath = __DIR__ . '/fixtures/photo.jpg';
    $pdf = new \FPDF('P', 'pt', 'A4');
    for ($p = 0; $p < 20; $p++) {
        $pdf->AddPage();
        $y = 50;
        for ($i = 0; $i < 4; $i++) {
            $pdf->Image($imgPath, 50, $y, 250, 180, 'JPG');
            $y += 190;
        }
    }
    return $pdf->Output('S');
}

// FPDF has no HTML; intentionally skipped.

// ---------- shared HTML article ----------

function bench_article_html(): string
{
    $body = str_repeat(
        '<p>Lorem ipsum dolor sit amet, <strong>consectetur</strong> adipiscing elit. '
        . 'Sed do <em>eiusmod tempor</em> incididunt ut labore et dolore magna aliqua. '
        . 'Ut enim ad minim veniam, quis <a href="https://example.com">nostrud exercitation</a> '
        . 'ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>'
        . '<ul><li>Item alpha</li><li>Item beta</li><li>Item gamma</li></ul>',
        12,
    );
    return '<!doctype html><html><body>'
        . '<h1>Lorem Ipsum Article</h1>'
        . '<h2>Section A</h2>' . $body
        . '<h2>Section B</h2>' . $body
        . '</body></html>';
}

// ---------- SVG dashboards ----------
//
// Ten metrics dashboards, one per page: a 4 × 3 grid of panels with
// gridlines and tick labels, holding two-series line charts (300 points
// each), 48-bar charts, 250-point translucent scatters and filled area
// paths — about 1,200 shapes per page. Each page is a different file, so a
// library that caches images by name still draws all ten. Every library
// reads the files inside the timed run. FPDF has no SVG.

const BENCH_SVG_PAGES = 10;
const BENCH_SVG_WIDTH_PT = 523.0;
const BENCH_SVG_HEIGHT_PT = 523.0 * 800 / 1200;

function bench_prepare_svg(): void
{
    $dir = bench_svg_dir();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    foreach (bench_svg_files() as $i => $file) {
        if (! is_file($file)) {
            file_put_contents($file, bench_svg_dashboard($i + 1));
        }
    }
}

function bench_svg_dir(): string
{
    // The version keeps files of an older generator from being reused.
    return sys_get_temp_dir().'/php-pdf-bench-svg-v1';
}

/** @return list<string> */
function bench_svg_files(): array
{
    $files = [];
    for ($p = 1; $p <= BENCH_SVG_PAGES; $p++) {
        $files[] = bench_svg_dir()."/dashboard-$p.svg";
    }

    return $files;
}

function bench_svg_dashboard(int $seed): string
{
    mt_srand($seed);
    $W = 1200; $H = 800; $cols = 4; $rows = 3; $pad = 12;
    $pw = ($W - $pad * ($cols + 1)) / $cols;
    $ph = ($H - $pad * ($rows + 1)) / $rows;
    $f = fn (float $v): string => rtrim(rtrim(sprintf('%.2f', $v), '0'), '.');
    $o = [sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">', $W, $H, $W, $H)];
    $o[] = sprintf('<rect x="0" y="0" width="%d" height="%d" fill="#f4f5f7"/>', $W, $H);
    $kinds = ['line', 'bar', 'scatter', 'area'];
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            $kind = $kinds[($r * $cols + $c) % 4];
            $o[] = sprintf('<g transform="translate(%s,%s)">', $f($pad + $c * ($pw + $pad)), $f($pad + $r * ($ph + $pad)));
            $o[] = sprintf('<rect x="0" y="0" width="%s" height="%s" rx="4" fill="#ffffff" stroke="#d0d4da" stroke-width="1"/>', $f($pw), $f($ph));
            $o[] = sprintf('<text x="10" y="18" font-family="Helvetica" font-size="11" fill="#222222">%s metric %d</text>', ucfirst($kind), $r * $cols + $c + 1);
            $cx0 = 34; $cy0 = 28; $cw = $pw - 44; $ch = $ph - 48;
            for ($g = 0; $g <= 5; $g++) {
                $gy = $cy0 + $ch - $g * $ch / 5;
                $o[] = sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="#e5e7eb" stroke-width="0.5"/>', $cx0, $f($gy), $f($cx0 + $cw), $f($gy));
                $o[] = sprintf('<text x="%s" y="%s" font-family="Helvetica" font-size="7" fill="#6b7280" text-anchor="end">%d</text>', $cx0 - 4, $f($gy + 2.5), $g * 20);
            }
            for ($t = 0; $t <= 6; $t++) {
                $o[] = sprintf('<text x="%s" y="%s" font-family="Helvetica" font-size="7" fill="#6b7280" text-anchor="middle">%02d:00</text>', $f($cx0 + $t * $cw / 6), $f($cy0 + $ch + 11), $t * 4);
            }
            $sy = fn (float $v): float => $cy0 + $ch - $v * $ch;
            switch ($kind) {
                case 'line':
                    foreach (['#2563eb', '#dc2626'] as $color) {
                        $v = mt_rand(30, 70) / 100;
                        $pts = [];
                        for ($i = 0; $i < 300; $i++) {
                            $v = max(0.02, min(0.98, $v + mt_rand(-100, 100) / 2500));
                            $pts[] = $f($cx0 + $i * $cw / 299).','.$f($sy($v));
                        }
                        $o[] = sprintf('<polyline fill="none" stroke="%s" stroke-width="1" points="%s"/>', $color, implode(' ', $pts));
                    }
                    break;
                case 'bar':
                    $bw = $cw / 48;
                    for ($i = 0; $i < 48; $i++) {
                        $v = mt_rand(5, 95) / 100;
                        $o[] = sprintf('<rect x="%s" y="%s" width="%s" height="%s" fill="#10b981"/>', $f($cx0 + $i * $bw + 0.5), $f($sy($v)), $f($bw - 1), $f($v * $ch));
                    }
                    break;
                case 'scatter':
                    for ($i = 0; $i < 250; $i++) {
                        $a = mt_rand(0, 1000) / 1000;
                        $b = max(0.02, min(0.98, $a * 0.8 + mt_rand(-150, 150) / 1000 + 0.1));
                        $o[] = sprintf('<circle cx="%s" cy="%s" r="1.8" fill="#7c3aed" fill-opacity="0.6"/>', $f($cx0 + $a * $cw), $f($sy($b)));
                    }
                    break;
                case 'area':
                    $v = 0.4;
                    $area = sprintf('M%s,%s', $cx0, $f($cy0 + $ch));
                    $line = '';
                    for ($i = 0; $i < 200; $i++) {
                        $v = max(0.05, min(0.95, $v + mt_rand(-100, 100) / 2000));
                        $pt = $f($cx0 + $i * $cw / 199).','.$f($sy($v));
                        $area .= ' L'.$pt;
                        $line .= ($i === 0 ? 'M' : ' L').$pt;
                    }
                    $area .= sprintf(' L%s,%s Z', $f($cx0 + $cw), $f($cy0 + $ch));
                    $o[] = sprintf('<path d="%s" fill="#f59e0b" fill-opacity="0.35" stroke="none"/>', $area);
                    $o[] = sprintf('<path d="%s" fill="none" stroke="#d97706" stroke-width="1"/>', $line);
                    break;
            }
            $o[] = '</g>';
        }
    }
    $o[] = '</svg>';

    return implode("\n", $o)."\n";
}

function bench_phppdf_svg(): string
{
    $b = DocumentBuilder::new();
    foreach (bench_svg_files() as $i => $file) {
        if ($i > 0) {
            $b->pageBreak();
        }
        $b->block(new \Dskripchenko\PhpPdf\Element\SvgElement(
            (string) file_get_contents($file), BENCH_SVG_WIDTH_PT, BENCH_SVG_HEIGHT_PT,
        ));
    }

    return $b->toBytes();
}

function bench_mpdf_svg(): string
{
    $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4']);
    foreach (bench_svg_files() as $i => $file) {
        if ($i > 0) {
            $pdf->AddPage();
        }
        $pdf->WriteHTML(sprintf('<img src="%s" style="width:%spt;height:%spt"/>', $file, BENCH_SVG_WIDTH_PT, BENCH_SVG_HEIGHT_PT));
    }

    return $pdf->Output('', 'S');
}

function bench_tcpdf_svg(): string
{
    $pdf = new \TCPDF('P', 'pt', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    foreach (bench_svg_files() as $file) {
        $pdf->AddPage();
        $pdf->ImageSVG('@'.file_get_contents($file), 36, 36, BENCH_SVG_WIDTH_PT, BENCH_SVG_HEIGHT_PT);
    }

    return $pdf->Output('', 'S');
}

function bench_dompdf_svg(): string
{
    $pdf = new \Dompdf\Dompdf(['chroot' => bench_svg_dir(), 'isRemoteEnabled' => false]);
    $html = '<html><body>';
    foreach (bench_svg_files() as $i => $file) {
        if ($i > 0) {
            $html .= '<div style="page-break-after: always;"></div>';
        }
        $html .= sprintf('<img src="%s" style="width:%spt;height:%spt"/>', $file, BENCH_SVG_WIDTH_PT, BENCH_SVG_HEIGHT_PT);
    }
    $pdf->loadHtml($html.'</body></html>');
    $pdf->render();

    return $pdf->output();
}
