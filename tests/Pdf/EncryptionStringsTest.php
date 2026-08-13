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

        // The title metadata string has to be encrypted, not plaintext.
        self::assertStringNotContainsString('Confidential Title', $bytes);
        // The literal string parens (...) in the objects are replaced with the
        // hex form <...>. The /O /U of the Encrypt dict are still hex (which is
        // right), but the other strings are now encrypted hex.
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
        // The /Encrypt object is excluded — its /O /U values stay hex as before.
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
