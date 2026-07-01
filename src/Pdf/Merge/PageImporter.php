<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Merge;

use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\PdfFormXObject;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderPage;

/**
 * FPDI-style import: brings a page from an existing PDF into a freshly built
 * {@see Document} as a Form XObject, so new php-pdf content (text, graphics,
 * watermarks) can be drawn over or under the imported page.
 *
 * ```php
 * $doc  = new Document();
 * $page = $doc->addPage();
 * $form = PageImporter::intoDocument($doc, $src, pageIndex: 0);
 * $page->useFormXObject($form, 0, 0, 595, 842);   // place imported page
 * $page->showText('DRAFT', 220, 400, StandardFont::Helvetica, 48);
 * ```
 *
 * The page's rotation and crop-box origin are baked into a leading `cm` inside
 * the form's content and the /BBox is the upright box `[0 0 W H]`, so
 * {@see \Dskripchenko\PhpPdf\Pdf\Page::useFormXObject()} places it correctly
 * for any rotation.
 */
final class PageImporter
{
    /**
     * Import page $pageIndex (0-based) of $src into $doc and return the form.
     */
    public static function intoDocument(Document $doc, ReaderDocument $src, int $pageIndex): PdfFormXObject
    {
        $pages = $src->pages();
        if ($pageIndex < 0 || $pageIndex >= count($pages)) {
            throw new \OutOfRangeException("Page {$pageIndex} does not exist (document has " . count($pages) . ' pages)');
        }
        $page = $pages[$pageIndex];

        $importer = new ObjectImporter();
        $importer->useSource($src);
        $resources = $importer->importValue($page->resources ?? new PdfDictionary([]));

        [$matrix, $w, $h] = self::rotation($page);
        $content = 'q ' . self::formatMatrix($matrix) . " cm\n" . self::contentBytes($src, $page) . "\nQ";

        $form = new PdfFormXObject($content, 0.0, 0.0, $w, $h);
        $doc->registerImportedForm(
            $form,
            $importer->objects(),
            $resources instanceof PdfDictionary ? $resources : new PdfDictionary([]),
        );

        return $form;
    }

    /** Upright width/height of a source page (after its /Rotate). */
    public static function pageSize(ReaderDocument $src, int $pageIndex): array
    {
        [, $w, $h] = self::rotation($src->pages()[$pageIndex]);
        return [$w, $h];
    }

    private static function contentBytes(ReaderDocument $doc, ReaderPage $page): string
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
     * Matrix mapping the crop box to the upright box [0,0,W,H], applied as a
     * leading `cm` in the form content.
     *
     * @return array{array{float,float,float,float,float,float}, float, float}
     */
    private static function rotation(ReaderPage $page): array
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

    /**
     * @param array{float,float,float,float,float,float} $m
     */
    private static function formatMatrix(array $m): string
    {
        return implode(' ', array_map(static function (float $v): string {
            if ($v === floor($v) && abs($v) < 1e9) {
                return (string) (int) $v;
            }
            return rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
        }, $m));
    }
}
