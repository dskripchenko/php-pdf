<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Encryption;
use Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EncryptionAesTest extends TestCase
{
    #[Test]
    public function aes_emits_v4_r4_encrypt_dict(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showText('Secret', 72, 720, StandardFont::Helvetica, 12);
        $pdf->encrypt('password', algorithm: EncryptionAlgorithm::Aes_128);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/V 4', $bytes);
        self::assertStringContainsString('/R 4', $bytes);
        self::assertStringContainsString('/CFM /AESV2', $bytes);
        self::assertStringContainsString('/StmF /StdCF', $bytes);
        self::assertStringContainsString('/StrF /StdCF', $bytes);
    }

    #[Test]
    public function aes_requires_pdf_1_6_or_higher(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->encrypt('pw', algorithm: EncryptionAlgorithm::Aes_128);
        $bytes = $pdf->toBytes();

        // Default = 1.7; AES требует ≥1.6. Просто validate version header корректный.
        self::assertMatchesRegularExpression('@^%PDF-1\.[67]@', $bytes);
    }

    #[Test]
    public function aes_encrypts_stream_content(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showText('Cleartext-marker', 72, 720, StandardFont::Helvetica, 12);
        $pdf->encrypt('password', algorithm: EncryptionAlgorithm::Aes_128);
        $bytes = $pdf->toBytes();

        // Plain text should not leak.
        self::assertStringNotContainsString('Cleartext-marker', $bytes);
    }

    #[Test]
    public function aes_per_object_includes_random_iv(): void
    {
        // Encrypt same data dvazhdy — IV должен быть разный, ciphertext тоже.
        $enc1 = new Encryption('pw', algorithm: EncryptionAlgorithm::Aes_128);
        $enc2 = new Encryption('pw', algorithm: EncryptionAlgorithm::Aes_128);

        $data = str_repeat('A', 32);
        $c1 = $enc1->encryptObject($data, 5);
        $c2 = $enc2->encryptObject($data, 5);

        // Different fileIds → разные keys → разные ciphertexts.
        self::assertNotSame($c1, $c2);
        // Length = 16 byte IV + ciphertext (padded to 16-byte multiple).
        self::assertSame(0, strlen($c1) % 16);
    }

    #[Test]
    public function aes_output_length_includes_iv_plus_pkcs7_padding(): void
    {
        $enc = new Encryption('pw', algorithm: EncryptionAlgorithm::Aes_128);
        // 10 bytes plaintext → IV (16) + ciphertext aligned к 16 = 32 total.
        $cipher = $enc->encryptObject(str_repeat('x', 10), 1);
        self::assertSame(32, strlen($cipher));
        // 16 bytes plaintext → IV + 32 (one full block + pad block) = 48.
        $cipher2 = $enc->encryptObject(str_repeat('x', 16), 1);
        self::assertSame(48, strlen($cipher2));
    }

    #[Test]
    public function rc4_vs_aes_different_algorithm_marker(): void
    {
        $pdf1 = PdfDocument::new(compressStreams: false);
        $pdf1->addPage();
        $pdf1->encrypt('pw', algorithm: EncryptionAlgorithm::Rc4_128);
        $rc4Bytes = $pdf1->toBytes();

        $pdf2 = PdfDocument::new(compressStreams: false);
        $pdf2->addPage();
        $pdf2->encrypt('pw', algorithm: EncryptionAlgorithm::Aes_128);
        $aesBytes = $pdf2->toBytes();

        self::assertStringContainsString('/V 2', $rc4Bytes);
        self::assertStringNotContainsString('AESV2', $rc4Bytes);
        self::assertStringContainsString('/V 4', $aesBytes);
        self::assertStringContainsString('AESV2', $aesBytes);
    }

    #[Test]
    public function algorithm_enum_helpers(): void
    {
        self::assertSame(2, EncryptionAlgorithm::Rc4_128->pdfVersion());
        self::assertSame(3, EncryptionAlgorithm::Rc4_128->revision());
        self::assertSame(4, EncryptionAlgorithm::Aes_128->pdfVersion());
        self::assertSame(4, EncryptionAlgorithm::Aes_128->revision());
    }
}
