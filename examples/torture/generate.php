<?php

declare(strict_types=1);

/**
 * Torture set — documents that exercise the hardest rendering paths in one
 * place: complex tables, multilingual text (Cyrillic, Greek, Arabic, CJK),
 * barcodes, charts, SVG, AcroForm fields, digital signatures, PDF/A and
 * read/merge. Used three ways:
 *
 *  1. CI smoke: every document must render cleanly with poppler
 *     (scripts/conformance/torture-smoke.sh);
 *  2. the manual cross-viewer checklist in docs/en/VIEWER-MATRIX.md;
 *  3. a gallery of what the library can do.
 *
 * Usage: php examples/torture/generate.php [output-dir]
 *   (default output: examples/torture/out/)
 *
 * Requires fonts fetched by scripts/fetch-fonts.sh (Liberation + Amiri +
 * DroidSansFallback).
 */

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\AreaChart;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Element\BarChart;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\FormField;
use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\LineChart;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\PieChart;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Font\FontProvider;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfMerger;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfSource;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\RunStyle;

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$fontDir = $root.'/.cache/fonts';
foreach (['liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf', 'Amiri-Regular.ttf', 'DroidSansFallback.ttf'] as $needed) {
    if (! is_readable("$fontDir/$needed")) {
        fwrite(STDERR, "Missing $fontDir/$needed — run scripts/fetch-fonts.sh first.\n");
        exit(1);
    }
}

$outDir = $argv[1] ?? __DIR__.'/out';
if (! is_dir($outDir) && ! mkdir($outDir, 0777, true)) {
    fwrite(STDERR, "Cannot create $outDir\n");
    exit(1);
}

$lib = fn (string $file) => new PdfFont(TtfFile::fromFile("$fontDir/liberation-fonts-ttf-2.1.5/$file"));

$provider = new class($fontDir) implements FontProvider {
    public function __construct(private string $dir) {}

    public function resolve(string $name): ?TtfFile
    {
        return match (strtolower($name)) {
            'arabic' => TtfFile::fromFile($this->dir.'/Amiri-Regular.ttf'),
            'cjk' => TtfFile::fromFile($this->dir.'/DroidSansFallback.ttf'),
            default => null,
        };
    }
};

$engine = new Engine(
    defaultFont: $lib('LiberationSans-Regular.ttf'),
    boldFont: $lib('LiberationSans-Bold.ttf'),
    italicFont: $lib('LiberationSans-Italic.ttf'),
    boldItalicFont: $lib('LiberationSans-BoldItalic.ttf'),
    fontProvider: $provider,
);

$bold = new RunStyle(bold: true);
$p = fn (string $text, ?RunStyle $style = null) => new Paragraph([new Run($text, $style ?? new RunStyle)]);
$cell = fn (string $text, ?RunStyle $style = null) => new Cell([new Paragraph([new Run($text, $style ?? new RunStyle)])]);

$docs = [];

// t01 — tables: header, column spans, row spans, numeric alignment fodder.
$docs['t01-tables'] = new Document(new Section([
    new Heading(1, [new Run('T01 — Tables')]),
    $p('Column spans, row spans, long wrapped content and tight columns.'),
    new Table([
        new Row([
            new Cell([new Paragraph([new Run('Quarterly summary', $bold)])], columnSpan: 3),
            $cell('Total', $bold),
        ]),
        new Row([$cell('Region', $bold), $cell('Q1', $bold), $cell('Q2', $bold), $cell('H1', $bold)]),
        new Row([$cell('EMEA — including a deliberately long region description that must wrap inside the cell'), $cell('1 204'), $cell('1 310'), $cell('2 514')]),
        new Row([
            new Cell([new Paragraph([new Run('APAC (spans two rows)')])], rowSpan: 2),
            $cell('890'), $cell('944'), $cell('1 834'),
        ]),
        new Row([$cell('— restated'), $cell('901'), $cell('1 791')]),
    ]),
]));

// t02 — Latin, Cyrillic, Greek, ligatures, combining diacritics.
$docs['t02-multilingual'] = new Document(new Section([
    new Heading(1, [new Run('T02 — Latin · Кириллица · Ελληνικά')]),
    $p('Ligatures: office floor difficult affluent. Diacritics: naïve façade Zoë coöperate.'),
    $p('Съешь же ещё этих мягких французских булок, да выпей чаю — классическая панграмма.'),
    $p('Θάλασσα, θάλασσα! Αλφάβητο: αβγδεζηθικλμνξοπρστυφχψω.'),
    $p('Mixed emphasis: ', $bold),
    new Paragraph([
        new Run('bold ', $bold),
        new Run('italic ', new RunStyle(italic: true)),
        new Run('bold-italic ', new RunStyle(bold: true, italic: true)),
        new Run('underline', new RunStyle(underline: true)),
    ]),
]));

// t03 — Arabic shaping + bidi (Amiri).
$arabic = new RunStyle(fontFamily: 'arabic');
$docs['t03-arabic-bidi'] = new Document(new Section([
    new Heading(1, [new Run('T03 — Arabic shaping and bidi')]),
    new Paragraph([new Run('مرحبا بالعالم — التشكيل العربي مع الأرقام ١٢٣ والاتجاهين', $arabic)]),
    new Paragraph([
        new Run('Latin lead-in, then ')
        , new Run('نص عربي في المنتصف', $arabic),
        new Run(', then Latin tail.'),
    ]),
]));

// t04 — CJK via font provider.
$cjk = new RunStyle(fontFamily: 'cjk');
$docs['t04-cjk'] = new Document(new Section([
    new Heading(1, [new Run('T04 — CJK')]),
    new Paragraph([new Run('简体中文：你好世界，可移植文档格式生成测试。', $cjk)]),
    new Paragraph([new Run('日本語：こんにちは世界。組版のテストです。', $cjk)]),
    new Paragraph([new Run('한국어 없음 — DroidSansFallback covers CJK ideographs and kana.', new RunStyle)]),
]));

// t05 — barcodes, linear + 2D.
$docs['t05-barcodes'] = new Document(new Section([
    new Heading(1, [new Run('T05 — Barcodes')]),
    $p('Code128:'),
    new Barcode('PHP-PDF-1234', BarcodeFormat::Code128),
    $p('EAN-13:'),
    new Barcode('4006381333931', BarcodeFormat::Ean13),
    $p('QR:'),
    new Barcode('https://github.com/dskripchenko/php-pdf', BarcodeFormat::Qr, heightPt: 80),
    $p('DataMatrix:'),
    new Barcode('php-pdf torture', BarcodeFormat::DataMatrix, heightPt: 60),
    $p('PDF417:'),
    new Barcode('php-pdf PDF417 sample', BarcodeFormat::Pdf417, heightPt: 50),
]));

// t06 — charts.
$docs['t06-charts'] = new Document(new Section([
    new Heading(1, [new Run('T06 — Charts')]),
    new PieChart([
        ['label' => 'MIT', 'value' => 60],
        ['label' => 'GPL', 'value' => 25],
        ['label' => 'Proprietary', 'value' => 15],
    ], title: 'License share'),
    new BarChart(bars: [
        ['label' => 'php-pdf', 'value' => 10.8],
        ['label' => 'mpdf', 'value' => 61.1],
        ['label' => 'tcpdf', 'value' => 36.1],
    ], title: 'HTML render, ms'),
    new LineChart(points: [
        ['label' => 'Jan', 'value' => 3],
        ['label' => 'Feb', 'value' => 7],
        ['label' => 'Mar', 'value' => 4],
        ['label' => 'Apr', 'value' => 11],
    ], title: 'Trend'),
    new AreaChart(
        xLabels: ['Q1', 'Q2', 'Q3'],
        series: [
            ['name' => 'Generate', 'values' => [4, 9, 6]],
            ['name' => 'Merge', 'values' => [1, 3, 5]],
        ],
        title: 'Area',
    ),
]));

// t07 — SVG.
$docs['t07-svg'] = new Document(new Section([
    new Heading(1, [new Run('T07 — SVG')]),
    new SvgElement(<<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 120">
          <defs>
            <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stop-color="#4a90d9"/>
              <stop offset="1" stop-color="#153a5f"/>
            </linearGradient>
          </defs>
          <rect x="10" y="10" width="180" height="100" rx="12" fill="url(#g)"/>
          <circle cx="60" cy="60" r="30" fill="#ffffff" fill-opacity="0.85"/>
          <path d="M 110 40 q 30 -25 60 0 t -20 50 z" fill="#e8b23a" stroke="#7a5b12" stroke-width="2"/>
          <text x="100" y="112" text-anchor="middle" font-size="10" fill="#333333">gradient + path + text</text>
        </svg>
        SVG, widthPt: 300, heightPt: 180),
]));

// t08 — AcroForm fields.
$docs['t08-forms'] = new Document(new Section([
    new Heading(1, [new Run('T08 — AcroForm')]),
    $p('Name:'),
    new FormField('name', FormField::TYPE_TEXT, defaultValue: 'Jane Roe'),
    $p('Subscribe:'),
    new FormField('subscribe', FormField::TYPE_CHECKBOX),
    $p('Comment:'),
    new FormField('comment', FormField::TYPE_TEXT_MULTILINE, heightPt: 60),
]));

$emitted = [];
$failures = 0;
foreach ($docs as $name => $document) {
    try {
        $bytes = $document->toBytes($engine);
        file_put_contents("$outDir/$name.pdf", $bytes);
        $emitted[$name] = $bytes;
        echo "generated $name.pdf\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAILED $name: {$e->getMessage()}\n");
        $failures++;
    }
}

// t09 — PDF/A-2u with real content (same engine, archival profile).
try {
    $iccPath = $root.'/resources/icc/sRGB2014.icc';
    $pdfa = new Document(
        new Section([
            new Heading(1, [new Run('T09 — PDF/A-2u')]),
            $p('Archival document with embedded fonts, Cyrillic — привет — and a table.'),
            new Table([
                new Row([$cell('Standard', $bold), $cell('ISO 19005-2')]),
                new Row([$cell('Conformance'), $cell('U (Unicode)')]),
            ]),
        ]),
        lang: 'en',
        pdfA: new PdfAConfig(
            $iccPath,
            iccProfileName: 'sRGB2014',
            title: 'T09 — PDF/A-2u torture',
            author: 'dskripchenko/php-pdf',
            part: PdfAConfig::PART_2,
            conformance: PdfAConfig::CONFORMANCE_U,
        ),
    );
    $bytes = $pdfa->toBytes($engine);
    file_put_contents("$outDir/t09-pdfa-2u.pdf", $bytes);
    $emitted['t09-pdfa-2u'] = $bytes;
    echo "generated t09-pdfa-2u.pdf\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "FAILED t09-pdfa-2u: {$e->getMessage()}\n");
    $failures++;
}

// t10 — signed document (self-signed certificate, generated on the fly).
try {
    $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'php-pdf torture signer'], $pkey, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $pkey, 365, ['digest_alg' => 'sha256']);
    $certPem = '';
    $keyPem = '';
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($pkey, $keyPem);

    $pdf = PdfDocument::new();
    $page = $pdf->addPage();
    $page->showText('T10 - digitally signed (self-signed demo certificate)', 60, 760, \Dskripchenko\PhpPdf\Pdf\StandardFont::Helvetica, 14);
    $page->addFormField('signature', 'Signature1', 60, 680, 240, 60);
    $pdf->sign(new SignatureConfig($certPem, $keyPem));
    $bytes = $pdf->toBytes();
    file_put_contents("$outDir/t10-signed.pdf", $bytes);
    $emitted['t10-signed'] = $bytes;
    echo "generated t10-signed.pdf\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "FAILED t10-signed: {$e->getMessage()}\n");
    $failures++;
}

// t11 — merge + stamp: combine two torture docs, stamp a generated overlay.
try {
    if (! isset($emitted['t01-tables'], $emitted['t05-barcodes'])) {
        throw new \RuntimeException('prerequisite documents failed');
    }
    $stampSrc = new Document(new Section([
        new Paragraph([new Run('MERGED BY PHP-PDF', new RunStyle(bold: true, color: '#c0392b'))]),
    ]));
    $merged = PdfMerger::create()
        ->append(PdfSource::fromBytes($emitted['t01-tables']))
        ->append(PdfSource::fromBytes($emitted['t05-barcodes']))
        ->stamp(PdfSource::fromBytes($stampSrc->toBytes($engine)))
        ->toBytes();
    file_put_contents("$outDir/t11-merged.pdf", $merged);
    echo "generated t11-merged.pdf\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "FAILED t11-merged: {$e->getMessage()}\n");
    $failures++;
}

exit($failures > 0 ? 1 : 0);
