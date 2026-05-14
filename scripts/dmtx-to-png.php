<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

if ($argc < 3) {
    fwrite(STDERR, "usage: php {$argv[0]} <data> <out.png> [moduleSize=12] [quietZone=2]\n");
    exit(1);
}
[, $data, $outPath] = $argv;
$moduleSize = (int) ($argv[3] ?? 12);
$quiet = (int) ($argv[4] ?? 2);

$enc = new Dskripchenko\PhpPdf\Barcode\DataMatrixEncoder($data);
$m = $enc->modules();
$cols = $enc->symbolWidth();
$rows = $enc->symbolHeight();
$totalW = ($cols + 2 * $quiet) * $moduleSize;
$totalH = ($rows + 2 * $quiet) * $moduleSize;

$img = imagecreatetruecolor($totalW, $totalH);
$white = imagecolorallocate($img, 255, 255, 255);
$black = imagecolorallocate($img, 0, 0, 0);
imagefilledrectangle($img, 0, 0, $totalW, $totalH, $white);
foreach ($m as $y => $row) {
    foreach ($row as $x => $b) {
        if ($b) {
            $px = ($x + $quiet) * $moduleSize;
            $py = ($y + $quiet) * $moduleSize;
            imagefilledrectangle($img, $px, $py, $px+$moduleSize-1, $py+$moduleSize-1, $black);
        }
    }
}
imagepng($img, $outPath);
$shape = $enc->rectangular ? "rect" : "square";
printf("DataMatrix %s %d×%d, data=%d ecc=%d → %s (%dx%d px)\n",
    $shape, $cols, $rows, $enc->dataCw, $enc->eccCw, $outPath, $totalW, $totalH);
