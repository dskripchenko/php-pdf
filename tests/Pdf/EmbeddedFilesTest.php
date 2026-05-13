<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmbeddedFilesTest extends TestCase
{
    #[Test]
    public function single_attachment_emits_filespec_and_stream(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showText('See attachment', 72, 720, StandardFont::Helvetica, 12);
        $pdf->attachFile('data.csv', "id,name\n1,Alice\n2,Bob\n", 'text/csv');
        $bytes = $pdf->toBytes();

        // EmbeddedFile stream emitted.
        self::assertStringContainsString('/Type /EmbeddedFile', $bytes);
        // CSV bytes присутствуют в stream.
        self::assertStringContainsString("id,name\n1,Alice", $bytes);
        // Filespec dict.
        self::assertStringContainsString('/Type /Filespec', $bytes);
        self::assertStringContainsString('(data.csv)', $bytes);
        // Names tree → EmbeddedFiles.
        self::assertMatchesRegularExpression('@/EmbeddedFiles\s+\d+\s+0\s+R@', $bytes);
    }

    #[Test]
    public function multiple_attachments_all_referenced(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->attachFile('a.txt', 'Hello A');
        $pdf->attachFile('b.txt', 'Hello B');
        $pdf->attachFile('c.txt', 'Hello C');
        $bytes = $pdf->toBytes();

        self::assertSame(3, substr_count($bytes, '/Type /Filespec'));
        self::assertSame(3, substr_count($bytes, '/Type /EmbeddedFile'));
        self::assertStringContainsString('Hello A', $bytes);
        self::assertStringContainsString('Hello B', $bytes);
        self::assertStringContainsString('Hello C', $bytes);
    }

    #[Test]
    public function description_emitted_when_set(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->attachFile('contract.txt', 'contract bytes',
            description: 'Original signed contract');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Desc (Original signed contract)', $bytes);
    }

    #[Test]
    public function mime_type_emitted_with_hash_escapes(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->attachFile('img.png', 'fake-png-bytes', mimeType: 'image/png');
        $bytes = $pdf->toBytes();

        // 'image/png' → image#2Fpng в PDF name object.
        self::assertStringContainsString('/Subtype /image#2Fpng', $bytes);
    }

    #[Test]
    public function attachments_sorted_alphabetically(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        // Add в reverse order.
        $pdf->attachFile('zebra.txt', 'z');
        $pdf->attachFile('alpha.txt', 'a');
        $pdf->attachFile('mango.txt', 'm');
        $bytes = $pdf->toBytes();

        // Names array sorted alphabetically.
        // Find /EmbeddedFiles dict Names array.
        preg_match('@/EmbeddedFiles\s+(\d+)\s+0\s+R@', $bytes, $m);
        self::assertNotEmpty($m);

        // Verify alpha precedes mango precedes zebra в Names array.
        preg_match('@/Names \[([^\]]+)\]@s', $bytes, $namesMatch);
        // Could match multiple — find the one с alpha/mango/zebra.
        preg_match_all('@/Names \[([^\]]+)\]@s', $bytes, $allMatches);
        $found = false;
        foreach ($allMatches[1] as $arrayContent) {
            if (str_contains($arrayContent, '(alpha.txt)') && str_contains($arrayContent, '(zebra.txt)')) {
                $alphaPos = strpos($arrayContent, '(alpha.txt)');
                $zebraPos = strpos($arrayContent, '(zebra.txt)');
                self::assertLessThan($zebraPos, $alphaPos, 'alpha должен быть перед zebra');
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'EmbeddedFiles Names array должен содержать оба filename');
    }

    #[Test]
    public function no_attachments_no_embedded_files_entry(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $bytes = $pdf->toBytes();

        self::assertStringNotContainsString('/EmbeddedFiles', $bytes);
        self::assertStringNotContainsString('/Type /Filespec', $bytes);
    }

    #[Test]
    public function binary_attachment_bytes_preserved(): void
    {
        $binary = "\x00\x01\x02\x03\xFF\xFE\xFD test\x00binary";
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->attachFile('blob.bin', $binary);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString($binary, $bytes);
        // Length record matches.
        $expectedLen = strlen($binary);
        self::assertMatchesRegularExpression(
            '@/Type /EmbeddedFile /Length\s+'.$expectedLen.'@',
            $bytes,
        );
    }

    #[Test]
    public function attachment_with_named_destinations_coexist(): void
    {
        // Names tree должен содержать оба /Dests и /EmbeddedFiles.
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $pdf->registerDestination('chapter1', $page, 0, 700);
        $pdf->attachFile('appendix.pdf', 'PDF bytes here');
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/Dests', $bytes);
        self::assertStringContainsString('/EmbeddedFiles', $bytes);
    }
}
