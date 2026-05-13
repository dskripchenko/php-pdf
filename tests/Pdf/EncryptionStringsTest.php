<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EncryptionStringsTest extends TestCase
{
    #[Test]
    public function strings_encrypted_into_hex_form_aes128(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showText('SecretText', 72, 720, StandardFont::Helvetica, 12);
        $pdf->metadata(title: 'Confidential Title');
        $pdf->encrypt('password', algorithm: EncryptionAlgorithm::Aes_128);
        $bytes = $pdf->toBytes();

        // Title metadata string должен быть зашифрован — не plaintext.
        self::assertStringNotContainsString('Confidential Title', $bytes);
        // Literal string parens (...) в objects replaced на hex form <...>.
        // Encrypt dict /O /U still hex (correct), но other strings now encrypted hex.
        self::assertStringContainsString('AESV2', $bytes);
    }

    #[Test]
    public function strings_encrypted_aes256(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->metadata(author: 'SecretAuthor');
        $pdf->encrypt('password', algorithm: EncryptionAlgorithm::Aes_256);
        $bytes = $pdf->toBytes();

        self::assertStringNotContainsString('SecretAuthor', $bytes);
    }

    #[Test]
    public function strings_encrypted_rc4(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->metadata(subject: 'SecretSubject');
        $pdf->encrypt('pw', algorithm: EncryptionAlgorithm::Rc4_128);
        $bytes = $pdf->toBytes();

        self::assertStringNotContainsString('SecretSubject', $bytes);
    }

    #[Test]
    public function encrypt_dict_strings_remain_plain(): void
    {
        // /Encrypt object excluded — /O /U values остаются hex как было.
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->encrypt('pw', algorithm: EncryptionAlgorithm::Aes_128);
        $bytes = $pdf->toBytes();

        // Encrypt dict still readable (V/R/Length/O/U/P entries).
        self::assertStringContainsString('/V 4', $bytes);
        self::assertStringContainsString('/R 4', $bytes);
        self::assertStringContainsString('/Length 128', $bytes);
    }
}
