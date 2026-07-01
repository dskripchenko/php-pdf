<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Merge;

use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderPage;

/**
 * Imports a source page as a reusable Form XObject (ISO 32000-1 §8.10): the
 * page's content streams are decoded and concatenated into the form body, its
 * resource closure is imported, `/BBox` is the crop box, and `/Matrix` bakes in
 * the page's `/Rotate` so the embedded page appears upright.
 */
final class PageXObjectBuilder
{
    public function __construct(private readonly ObjectImporter $importer)
    {
    }

    /**
     * @return array{\Dskripchenko\PhpPdf\Pdf\Reader\PdfReference, float, float}
     *         [reference, upright-width, upright-height]
     */
    public function build(ReaderDocument $doc, ReaderPage $page): array
    {
        $this->importer->useSource($doc);

        $body = $this->contentBytes($doc, $page);
        [$matrix, $w, $h] = $this->rotation($page);
        $resources = $this->importer->importValue($page->resources ?? new PdfDictionary([]));

        $dict = new PdfDictionary([
            'Type' => new PdfName('XObject'),
            'Subtype' => new PdfName('Form'),
            'FormType' => 1,
            'BBox' => array_map('floatval', $page->cropBox),
            'Matrix' => $matrix,
            'Resources' => $resources,
            'Filter' => new PdfName('FlateDecode'),
        ]);

        $ref = new \Dskripchenko\PhpPdf\Pdf\Reader\PdfReference(
            $this->importer->allocate(new PdfStream($dict, (string) gzcompress($body))),
            0,
        );

        return [$ref, $w, $h];
    }

    /** Decode and concatenate all of the page's content streams. */
    private function contentBytes(ReaderDocument $doc, ReaderPage $page): string
    {
        $contents = $doc->deref($page->dict->get('Contents'));
        $streams = is_array($contents) ? $contents : [$contents];

        $parts = [];
        foreach ($streams as $entry) {
            $stream = $doc->deref($entry);
            if ($stream instanceof PdfStream) {
                $parts[] = $doc->streamData($stream);
            }
        }
        return implode("\n", $parts);
    }

    /**
     * Rotation matrix mapping the crop box to an upright [0,0,W,H] box.
     *
     * @return array{array{float,float,float,float,float,float}, float, float}
     */
    private function rotation(ReaderPage $page): array
    {
        [$cx0, $cy0, $cx1, $cy1] = $page->cropBox;
        $w = $cx1 - $cx0;
        $h = $cy1 - $cy0;

        return match ($page->rotate) {
            90 => [[0.0, -1.0, 1.0, 0.0, -$cy0, $cx1], $h, $w],
            180 => [[-1.0, 0.0, 0.0, -1.0, $cx1, $cy1], $w, $h],
            270 => [[0.0, 1.0, -1.0, 0.0, $cy1, -$cx0], $h, $w],
            default => [[1.0, 0.0, 0.0, 1.0, -$cx0, -$cy0], $w, $h],
        };
    }
}
