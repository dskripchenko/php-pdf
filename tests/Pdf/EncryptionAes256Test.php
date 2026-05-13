<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Encryption;
use Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EncryptionAes256Test extends TestCase
{
    #[Test]
    public function aes256_emits_v5_r5_encrypt_dict(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->encrypt('pw', algorithm: EncryptionAlgorithm::Aes_256);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/V 5', $bytes);
        self::assertStringContainsString('/R 5', $bytes);
        self::assertStringContainsString('/Length 256', $bytes);
        self::assertStringContainsString('/CFM /AESV3', $bytes);
        // OE/UE/Perms entries.
        self::assertMatchesRegularExpression('@/OE <[a-f0-9]{64}>@', $bytes);
        self::assertMatchesRegularExpression('@/UE <[a-f0-9]{64}>@', $bytes);
        self::assertMatchesRegularExpression('@/Perms <[a-f0-9]{32}>@', $bytes);
    }

    #[Test]
    public function aes256_pdf_version_at_least_1_7(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->encrypt('pw', algorithm: EncryptionAlgorithm::Aes_256);
        $bytes = $pdf->toBytes();

        self::assertStringStartsWith('%PDF-1.7', $bytes);
    }

    #[Test]
    public function aes256_o_u_values_48_bytes(): void
    {
        // V5 R5 /O and /U = 48 bytes (hash 32 + 8 valSalt + 8 keySalt).
        $enc = new Encryption('pw', algorithm: EncryptionAlgorithm::Aes_256);
        self::assertSame(48, strlen($enc->oValue));
        self::assertSame(48, strlen($enc->uValue));
        // OE / UE = 32 bytes (AES-256-CBC encrypted 32-byte fileKey).
        self::assertSame(32, strlen($enc->oeValue));
        self::assertSame(32, strlen($enc->ueValue));
        // Perms = 16 bytes (AES-256-ECB encrypted 16-byte permissions block).
        self::assertSame(16, strlen($enc->permsValue));
    }

    #[Test]
    public function aes256_file_key_32_bytes(): void
    {
        $enc = new Encryption('pw', algorithm: EncryptionAlgorithm::Aes_256);
        self::assertSame(32, strlen($enc->fileKey));
    }

    #[Test]
    public function aes256_user_hash_validates_password(): void
    {
        // U[0..32] = SHA-256(pw + U[32..40]) — validation hash.
        $enc = new Encryption('mypassword', algorithm: EncryptionAlgorithm::Aes_256);
        $userHash = substr($enc->uValue, 0, 32);
        $userValSalt = substr($enc->uValue, 32, 8);
        $expected = hash('sha256', 'mypassword' . $userValSalt, true);
        self::assertSame(bin2hex($expected), bin2hex($userHash));
    }

    #[Test]
    public function aes256_owner_hash_includes_u_value(): void
    {
        $enc = new Encryption('user', ownerPassword: 'owner', algorithm: EncryptionAlgorithm::Aes_256);
        $ownerHash = substr($enc->oValue, 0, 32);
        $ownerValSalt = substr($enc->oValue, 32, 8);
        // O hash uses owner pw + ownerValSalt + 48-byte U value.
        $expected = hash('sha256', 'owner' . $ownerValSalt . $enc->uValue, true);
        self::assertSame(bin2hex($expected), bin2hex($ownerHash));
    }

    #[Test]
    public function aes256_stream_content_encrypted(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showText('Top-secret content', 72, 720, StandardFont::Helvetica, 12);
        $pdf->encrypt('pw', algorithm: EncryptionAlgorithm::Aes_256);
        $bytes = $pdf->toBytes();

        self::assertStringNotContainsString('Top-secret content', $bytes);
        self::assertStringContainsString('AESV3', $bytes);
    }

    #[Test]
    public function aes256_different_passwords_yield_different_keys(): void
    {
        $a = new Encryption('foo', algorithm: EncryptionAlgorithm::Aes_256);
        $b = new Encryption('bar', algorithm: EncryptionAlgorithm::Aes_256);

        self::assertNotSame($a->fileKey, $b->fileKey);
        self::assertNotSame($a->uValue, $b->uValue);
        self::assertNotSame($a->oValue, $b->oValue);
    }

    #[Test]
    public function aes256_algorithm_enum_helpers(): void
    {
        self::assertSame(5, EncryptionAlgorithm::Aes_256->pdfVersion());
        self::assertSame(5, EncryptionAlgorithm::Aes_256->revision());
    }
}
