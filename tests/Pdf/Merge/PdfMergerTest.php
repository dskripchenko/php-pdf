<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Merge;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfMerger;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfSource;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase P9: PdfMerger::append() — the end-to-end page-concatenation goal.
 */
final class PdfMergerTest extends TestCase
{
    /** @param list<string> $labels one label per page */
    private function pdf(array $labels, bool $objStm = false, ?string $password = null): string
    {
        $pdf = new PdfDocument();
        if ($objStm) {
            $pdf->useObjectStreams(true);
        }
        foreach ($labels as $i => $label) {
            $page = $pdf->addPage(customDimensionsPt: [200.0 + $i, 300.0]);
            $page->showText($label, 20, 250, StandardFont::Helvetica, 14);
        }
        if ($password !== null) {
            $pdf->encrypt($password, algorithm: EncryptionAlgorithm::Rc4_128);
        }
        return $pdf->toBytes();
    }

    private function pageText(ReaderDocument $doc, int $index): string
    {
        $contents = $doc->deref($doc->pages()[$index]->dict->get('Contents'));
        return $doc->streamData($contents);
    }

    #[Test]
    public function appends_two_documents_in_order(): void
    {
        $a = PdfSource::fromBytes($this->pdf(['A1', 'A2']));
        $b = PdfSource::fromBytes($this->pdf(['B1']));

        $bytes = PdfMerger::create()->append($a)->append($b)->toBytes();
        $out = ReaderDocument::fromBytes($bytes);

        self::assertSame(3, $out->pageCount());
        self::assertStringContainsString('A1', $this->pageText($out, 0));
        self::assertStringContainsString('A2', $this->pageText($out, 1));
        self::assertStringContainsString('B1', $this->pageText($out, 2));
    }

    #[Test]
    public function appends_a_page_subset_in_custom_order(): void
    {
        $src = PdfSource::fromBytes($this->pdf(['P1', 'P2', 'P3', 'P4']));

        $bytes = PdfMerger::create()->append($src, pages: [3, 1])->toBytes();
        $out = ReaderDocument::fromBytes($bytes);

        self::assertSame(2, $out->pageCount());
        self::assertStringContainsString('P3', $this->pageText($out, 0));
        self::assertStringContainsString('P1', $this->pageText($out, 1));
    }

    #[Test]
    public function preserves_per_page_geometry(): void
    {
        $a = PdfSource::fromBytes($this->pdf(['A1', 'A2', 'A3']));
        $bytes = PdfMerger::create()->append($a, pages: [2])->toBytes();
        $out = ReaderDocument::fromBytes($bytes);
        // Page 2 was created with width 201.
        self::assertSame(201.0, $out->pages()[0]->width());
        self::assertSame(300.0, $out->pages()[0]->height());
    }

    #[Test]
    public function merges_object_stream_source(): void
    {
        $a = PdfSource::fromBytes($this->pdf(['A1'], objStm: true));
        $b = PdfSource::fromBytes($this->pdf(['B1', 'B2'], objStm: true));
        $bytes = PdfMerger::create()->append($a)->append($b)->toBytes();
        self::assertSame(3, ReaderDocument::fromBytes($bytes)->pageCount());
    }

    #[Test]
    public function merges_encrypted_source(): void
    {
        // Encrypted input is decrypted on read and re-emitted unencrypted.
        $a = PdfSource::fromBytes($this->pdf(['SECRET1', 'SECRET2'], password: ''));
        $bytes = PdfMerger::create()->append($a, pages: [2])->toBytes();
        $out = ReaderDocument::fromBytes($bytes);
        self::assertSame(1, $out->pageCount());
        self::assertStringContainsString('SECRET2', $this->pageText($out, 0));
    }

    #[Test]
    public function rejects_out_of_range_page(): void
    {
        $src = PdfSource::fromBytes($this->pdf(['only']));
        $this->expectException(\OutOfRangeException::class);
        PdfMerger::create()->append($src, pages: [2])->toBytes();
    }
}
