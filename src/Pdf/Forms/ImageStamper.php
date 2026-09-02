<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms;

use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\Merge\ObjectImporter;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;

/**
 * Places a raster image (PNG/JPEG, with alpha preserved via `/SMask`) onto a
 * page of an already-imported object graph, as its own `/XObject` — a
 * standalone primitive independent of any AcroForm field, so a caller can
 * drive it with whatever placement convention it likes (a signature, a
 * photo, a watermark, …).
 *
 * Decoding reuses {@see PdfImage} (already used by the authoring side); only
 * XObject registration differs, since here objects are `allocate()`d on a
 * shared {@see ObjectImporter} instead of written via the authoring `Writer`
 * — mirrors the pattern in {@see \Dskripchenko\PhpPdf\Pdf\Merge\PageXObjectBuilder}.
 *
 * Input coordinates are top-left origin (matching how callers already
 * measure a page for placement), converted here to PDF's bottom-left origin.
 */
final class ImageStamper
{
    public function stamp(
        ObjectImporter $importer,
        int $pageId,
        PdfImage $image,
        float $x,
        float $y,
        float $width,
        float $height,
        float $pageHeight,
    ): void {
        $imageId = $this->registerImage($importer, $image);
        $name = 'Stamp' . $imageId;

        $pdfY = $pageHeight - $y - $height;
        $cm = sprintf(
            '%s 0 0 %s %s %s cm',
            $this->fmt($width), $this->fmt($height), $this->fmt($x), $this->fmt($pdfY),
        );
        $content = "q\n{$cm}\n/{$name} Do\nQ";

        $this->appendToPage($importer, $pageId, $content, $name, new PdfReference($imageId, 0));
    }

    private function registerImage(ObjectImporter $importer, PdfImage $image): int
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
            $maskDict = new PdfDictionary([
                'Type' => new PdfName('XObject'),
                'Subtype' => new PdfName('Image'),
                'Width' => $image->widthPx,
                'Height' => $image->heightPx,
                'ColorSpace' => new PdfName('DeviceGray'),
                'BitsPerComponent' => 8,
                'Filter' => new PdfName('FlateDecode'),
            ]);
            $maskId = $importer->allocate(new PdfStream($maskDict, $image->alphaData));
            $items['SMask'] = new PdfReference($maskId, 0);
        }

        return $importer->allocate(new PdfStream(new PdfDictionary($items), $image->imageData));
    }

    private function appendToPage(ObjectImporter $importer, int $pageId, string $content, string $name, PdfReference $imageRef): void
    {
        $dict = $importer->get($pageId);
        if (!$dict instanceof PdfDictionary) {
            throw new \InvalidArgumentException("Page object {$pageId} was not imported");
        }
        $items = $dict->all();

        $contents = $items['Contents'] ?? null;
        $list = match (true) {
            is_array($contents) => array_values($contents),
            $contents !== null => [$contents],
            default => [],
        };
        $list[] = new PdfReference($importer->allocate(new PdfStream(new PdfDictionary([]), $content)), 0);
        $items['Contents'] = $list;

        $resources = $this->resolveDict($importer, $items['Resources'] ?? null) ?? new PdfDictionary([]);
        $xobjects = $this->resolveDict($importer, $resources->get('XObject')) ?? new PdfDictionary([]);
        $xobjItems = $xobjects->all();
        $xobjItems[$name] = $imageRef;
        $resItems = $resources->all();
        $resItems['XObject'] = new PdfDictionary($xobjItems);
        $items['Resources'] = new PdfDictionary($resItems);

        $importer->set($pageId, new PdfDictionary($items));
    }

    /**
     * A dictionary value already read off an imported object may itself be an
     * indirect reference (ISO 32000-1 allows any value to be indirect) rather
     * than inlined — resolve it against the importer's own object map before
     * treating an absent match as "no such dictionary".
     */
    private function resolveDict(ObjectImporter $importer, mixed $value): ?PdfDictionary
    {
        if ($value instanceof PdfReference) {
            $value = $importer->get($value->number);
        }

        return $value instanceof PdfDictionary ? $value : null;
    }

    private function fmt(float $v): string
    {
        if ($v === floor($v) && abs($v) < 1e9) {
            return (string) (int) $v;
        }

        return rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
    }
}
