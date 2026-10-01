<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderPage;

/**
 * Places a raster image (PNG/JPEG, with alpha preserved via `/SMask`) onto a
 * page — a standalone primitive independent of any AcroForm field, so a
 * caller can drive it with whatever placement convention it likes (a
 * signature, a photo, a watermark, …).
 *
 * Coordinates are in points from the top-left corner of the page *as a
 * viewer shows it*: the visible CropBox, after `/Rotate`. The image is drawn
 * upright in that view.
 *
 * @internal
 */
final class ImageStamper
{
    public function __construct(
        private readonly FormGraph $graph,
        private readonly PageEditor $pages,
    ) {
    }

    public function stamp(ReaderPage $page, PdfImage $image, float $x, float $y, float $width, float $height): void
    {
        $name = $this->pages->addResource($page, 'XObject', 'Stamp', $this->register($image));

        // Unit square (u right, v up) → display box (y down) → user space.
        [$a, $b, $c, $d, $e, $f] = PageEditor::displayToUser($page);
        $point = static fn (float $dx, float $dy): array => [$a * $dx + $c * $dy + $e, $b * $dx + $d * $dy + $f];
        [$ox, $oy] = $point($x, $y + $height);
        [$ux, $uy] = $point($x + $width, $y + $height);
        [$vx, $vy] = $point($x, $y);

        $this->pages->appendContent($page, sprintf(
            "q %s %s %s %s %s %s cm /%s Do Q",
            FormGraph::number($ux - $ox),
            FormGraph::number($uy - $oy),
            FormGraph::number($vx - $ox),
            FormGraph::number($vy - $oy),
            FormGraph::number($ox),
            FormGraph::number($oy),
            $name,
        ));
    }

    private function register(PdfImage $image): PdfReference
    {
        $items = [
            'Type' => new PdfName('XObject'),
            'Subtype' => new PdfName('Image'),
            'Width' => $image->widthPx,
            'Height' => $image->heightPx,
            'ColorSpace' => new PdfName(ltrim($image->colorSpace, '/')),
            'BitsPerComponent' => $image->bitsPerComponent,
            'Filter' => new PdfName(ltrim($image->filter, '/')),
        ];

        if ($image->alphaData !== null && $image->alphaData !== '') {
            $items['SMask'] = $this->graph->allocate(new PdfStream(new PdfDictionary([
                'Type' => new PdfName('XObject'),
                'Subtype' => new PdfName('Image'),
                'Width' => $image->widthPx,
                'Height' => $image->heightPx,
                'ColorSpace' => new PdfName('DeviceGray'),
                'BitsPerComponent' => 8,
                'Filter' => new PdfName('FlateDecode'),
            ]), $image->alphaData));
        }

        return $this->graph->allocate(new PdfStream(new PdfDictionary($items), $image->imageData));
    }
}
