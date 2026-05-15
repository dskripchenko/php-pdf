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
