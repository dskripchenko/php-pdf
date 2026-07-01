<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Merge;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Merge\MergeSerializer;
use Dskripchenko\PhpPdf\Pdf\Merge\ObjectImporter;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase P8: object import (closure copy + renumber + cycle break) and the
 * verbatim serializer, exercised as a full-document clone.
 */
final class ObjectImporterTest extends TestCase
{
    private function sourcePdf(int $pages, bool $objStm = false): string
    {
        $pdf = new PdfDocument();
        if ($objStm) {
            $pdf->useObjectStreams(true);
        }
        for ($i = 0; $i < $pages; $i++) {
            $page = $pdf->addPage(customDimensionsPt: [200.0 + $i, 300.0 + $i]);
            $page->showText("Page {$i}", 20, 250, StandardFont::Helvetica, 12);
        }
        return $pdf->toBytes();
    }

    /** Clone by importing the whole catalog closure, then re-serialize. */
    private function clone(string $pdf): ReaderDocument
    {
        $src = ReaderDocument::fromBytes($pdf);
        $importer = new ObjectImporter();
        $importer->useSource($src);

        $rootRef = $src->trailer()->get('Root');
        self::assertInstanceOf(PdfReference::class, $rootRef);
        $newRoot = $importer->importObject($rootRef->number);

        $bytes = (new MergeSerializer())->serialize($importer->objects(), $newRoot->number);
        return ReaderDocument::fromBytes($bytes);
    }

    #[Test]
    public function clone_preserves_page_count(): void
    {
        foreach ([1, 3, 5] as $n) {
            self::assertSame($n, $this->clone($this->sourcePdf($n))->pageCount());
        }
    }

    #[Test]
    public function clone_preserves_page_geometry(): void
    {
        $cloned = $this->clone($this->sourcePdf(3));
        $pages = $cloned->pages();
        self::assertSame([0.0, 0.0, 200.0, 300.0], $pages[0]->mediaBox);
        self::assertSame([0.0, 0.0, 202.0, 302.0], $pages[2]->mediaBox);
    }

    #[Test]
    public function clone_preserves_content_stream(): void
    {
        $cloned = $this->clone($this->sourcePdf(1));
        $contents = $cloned->deref($cloned->pages()[0]->dict->get('Contents'));
        $decoded = $cloned->streamData($contents);
        self::assertStringContainsString('Page 0', $decoded);
    }

    #[Test]
    public function clone_works_from_object_stream_source(): void
    {
        // Source uses object streams; importer resolves compressed objects.
        $cloned = $this->clone($this->sourcePdf(2, objStm: true));
        self::assertSame(2, $cloned->pageCount());
    }

    #[Test]
    public function importer_dedups_shared_objects(): void
    {
        // The page-tree root is referenced by every page's /Parent; importing
        // the catalog must not duplicate it.
        $src = ReaderDocument::fromBytes($this->sourcePdf(3));
        $importer = new ObjectImporter();
        $importer->useSource($src);
        $importer->importObject($src->trailer()->get('Root')->number);

        // 3 pages sharing one parent: object count stays well below a
        // no-dedup explosion.
        self::assertLessThan(30, $importer->count());
        self::assertGreaterThanOrEqual(5, $importer->count());
    }
}
