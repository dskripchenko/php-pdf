<?php

declare(strict_types=1);

/**
 * Helper: рендерит PDF417 или Aztec barcode как PNG для decoder verification.
 *
 * Usage:
 *   php scripts/barcode-to-png.php pdf417 "Hello PDF417 World" /tmp/pdf417.png
 *   php scripts/barcode-to-png.php aztec "HELLO AZTEC" /tmp/aztec.png
 *
 * Options:
 *   3-й arg: output PNG path (required)
 *   4-й arg: module size в pixels (default 6)
 *   5-й arg: quiet zone в modules (default 4)
 */
require __DIR__.'/../vendor/autoload.php';

if ($argc < 4) {
    fwrite(STDERR, "usage: php {$argv[0]} <pdf417|aztec> <data> <out.png> [moduleSize=6] [quietZone=4]\n");
    exit(1);
}

[, $format, $data, $outPath] = $argv;
$moduleSize = (int) ($argv[4] ?? 6);
$quietZone = (int) ($argv[5] ?? 4);

switch (strtolower($format)) {
    case 'pdf417':
        $enc = new Dskripchenko\PhpPdf\Barcode\Pdf417Encoder($data);
        $matrix = $enc->modules();
        // PDF417 logical rows expand vertically rowHeight=3 modules each.
        $rowHeight = 3;
        $expanded = [];
        foreach ($matrix as $row) {
            for ($i = 0; $i < $rowHeight; $i++) {
                $expanded[] = $row;
            }
        }
        $matrix = $expanded;
        $cols = count($matrix[0]);
        $rows = count($matrix);
        printf("PDF417: %d logical rows × %d data cols → %d modules tall × %d wide; ECC L%d\n",
            $enc->rows, $enc->cols, $rows, $cols, $enc->eccLevel);
        break;

    case 'aztec':
        $enc = new Dskripchenko\PhpPdf\Barcode\AztecEncoder($data);
        $matrix = $enc->modules();
        $cols = $rows = $enc->matrixSize();
        printf("Aztec Compact: %d layers, %d×%d modules; %d data + %d ECC codewords\n",
            $enc->layers, $rows, $cols, $enc->dataCodewords, $enc->eccCodewords);
        break;

    default:
        fwrite(STDERR, "unsupported format: $format (use pdf417 or aztec)\n");
        exit(1);
}

$totalW = ($cols + 2 * $quietZone) * $moduleSize;
$totalH = ($rows + 2 * $quietZone) * $moduleSize;

$img = imagecreatetruecolor($totalW, $totalH);
$white = imagecolorallocate($img, 255, 255, 255);
$black = imagecolorallocate($img, 0, 0, 0);
imagefilledrectangle($img, 0, 0, $totalW, $totalH, $white);

foreach ($matrix as $y => $row) {
    foreach ($row as $x => $isBlack) {
        if ($isBlack) {
            $px = ($x + $quietZone) * $moduleSize;
            $py = ($y + $quietZone) * $moduleSize;
            imagefilledrectangle($img, $px, $py, $px + $moduleSize - 1, $py + $moduleSize - 1, $black);
        }
    }
}

imagepng($img, $outPath);
imagedestroy($img);

printf("wrote %s (%dx%d px, %d-pixel modules, %d-module quiet zone)\n",
    $outPath, $totalW, $totalH, $moduleSize, $quietZone);
