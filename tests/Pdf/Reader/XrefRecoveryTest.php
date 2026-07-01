<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Reader;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase P5: cross-reference recovery by scanning `N G obj` headers.
 */
final class XrefRecoveryTest extends TestCase
{
    private function makePdf(int $pageCount, bool $objStm): string
    {
        $pdf = new PdfDocument();
        if ($objStm) {
            $pdf->useObjectStreams(true);
        }
        for ($i = 0; $i < $pageCount; $i++) {
            $pdf->addPage();
        }
        return $pdf->toBytes();
    }

    /** Point startxref at a bogus offset so the normal reader fails. */
    private function breakStartxref(string $pdf): string
    {
        $pos = strrpos($pdf, 'startxref');
        self::assertNotFalse($pos);
        // Replace the offset that follows 'startxref' with 0 (→ '%PDF', not 'xref').
        return preg_replace(
            '/startxref\s+\d+/',
            "startxref\n0",
            $pdf,
            1,
        ) ?? $pdf;
    }

    #[Test]
    public function recovers_page_count_from_classic_output(): void
    {
        foreach ([1, 3, 5] as $n) {
            $broken = $this->breakStartxref($this->makePdf($n, objStm: false));
            $doc = ReaderDocument::fromBytes($broken);
            self::assertSame($n, $doc->pageCount(), "recovered classic, {$n} pages");
        }
    }

    #[Test]
    public function recovers_from_object_stream_output(): void
    {
        // Catalog lives inside an /ObjStm here; recovery must index it.
        foreach ([1, 4] as $n) {
            $broken = $this->breakStartxref($this->makePdf($n, objStm: true));
            $doc = ReaderDocument::fromBytes($broken);
            self::assertSame($n, $doc->pageCount(), "recovered objstm, {$n} pages");
        }
    }

    #[Test]
    public function recovers_when_startxref_marker_is_absent(): void
    {
        $pdf = $this->makePdf(2, objStm: false);
        $stripped = str_replace('startxref', 'xxxxxxxxx', $pdf);
        $doc = ReaderDocument::fromBytes($stripped);
        self::assertSame(2, $doc->pageCount());
    }

    #[Test]
    public function recovered_document_matches_intact_document(): void
    {
        $pdf = $this->makePdf(3, objStm: false);
        $intact = ReaderDocument::fromBytes($pdf);
        $broken = ReaderDocument::fromBytes($this->breakStartxref($pdf));
        self::assertSame($intact->pageCount(), $broken->pageCount());
    }

    #[Test]
    public function recovers_and_decrypts_an_encrypted_document(): void
    {
        // Recovery must still set up decryption from the reconstructed trailer.
        $pdf = new \Dskripchenko\PhpPdf\Pdf\Document();
        $pdf->metadata(title: 'RECOVERED-SECRET');
        $pdf->addPage();
        $pdf->encrypt('', algorithm: \Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm::Rc4_128);

        $doc = ReaderDocument::fromBytes($this->breakStartxref($pdf->toBytes()));
        self::assertSame(1, $doc->pageCount());

        $info = $doc->deref($doc->trailer()->get('Info'));
        $title = $doc->deref($info->get('Title'));
        self::assertStringContainsString('RECOVERED-SECRET', $title->bytes);
    }
}
