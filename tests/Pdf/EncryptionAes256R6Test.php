<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Encryption;
use Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 106: AES-256 V5 R6 (PDF 2.0 / ISO 32000-2) — iterative hash 2.B.
 */
final class EncryptionAes256R6Test extends TestCase
{
    #[Test]
    public function r6_emits_pdf_2_0_header(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->encrypt('pw', algorithm: EncryptionAlgorithm::Aes_256_R6);
        $bytes = $pdf->toBytes();

        self::assertStringStartsWith('%PDF-2.0', $bytes);
    }

    #[Test]
    public function r6_emits_v5_r6_encrypt_dict(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->encrypt('pw', algorithm: EncryptionAlgorithm::Aes_256_R6);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/V 5', $bytes);
        self::assertStringContainsString('/R 6', $bytes);
        self::assertStringContainsString('/Length 256', $bytes);
        self::assertStringContainsString('/CFM /AESV3', $bytes);
        self::assertMatchesRegularExpression('@/OE <[a-f0-9]{64}>@', $bytes);
        self::assertMatchesRegularExpression('@/UE <[a-f0-9]{64}>@', $bytes);
        self::assertMatchesRegularExpression('@/Perms <[a-f0-9]{32}>@', $bytes);
    }

    #[Test]
    public function r6_o_u_oe_ue_perms_sizes(): void
    {
        $enc = new Encryption('pw', algorithm: EncryptionAlgorithm::Aes_256_R6);
        self::assertSame(48, strlen($enc->oValue));
        self::assertSame(48, strlen($enc->uValue));
        self::assertSame(32, strlen($enc->oeValue));
        self::assertSame(32, strlen($enc->ueValue));
        self::assertSame(16, strlen($enc->permsValue));
        self::assertSame(32, strlen($enc->fileKey));
    }

    #[Test]
    public function r6_user_hash_uses_iterative_algorithm(): void
    {
        $enc = new Encryption('mypassword', algorithm: EncryptionAlgorithm::Aes_256_R6);
        $userHash = substr($enc->uValue, 0, 32);
        $userValSalt = substr($enc->uValue, 32, 8);

        // R6 hash = Algorithm 2.B (iterative), не single SHA-256.
        $expected = Encryption::computeR6Hash('mypassword', $userValSalt);
        self::assertSame(bin2hex($expected), bin2hex($userHash));

        // Sanity: it is NOT the single SHA-256 that R5 would produce.
        $sha256 = hash('sha256', 'mypassword' . $userValSalt, true);
        self::assertNotSame(bin2hex($sha256), bin2hex($userHash));
    }

    #[Test]
    public function r6_owner_hash_includes_u_value(): void
    {
        $enc = new Encryption('user', ownerPassword: 'owner', algorithm: EncryptionAlgorithm::Aes_256_R6);
        $ownerHash = substr($enc->oValue, 0, 32);
        $ownerValSalt = substr($enc->oValue, 32, 8);

        $expected = Encryption::computeR6Hash('owner', $ownerValSalt, $enc->uValue);
        self::assertSame(bin2hex($expected), bin2hex($ownerHash));
    }

    #[Test]
    public function r6_hash_deterministic(): void
    {
        // Same inputs → same hash (algorithm fully deterministic).
        $a = Encryption::computeR6Hash('pw', "\x01\x02\x03\x04\x05\x06\x07\x08");
        $b = Encryption::computeR6Hash('pw', "\x01\x02\x03\x04\x05\x06\x07\x08");
        self::assertSame(bin2hex($a), bin2hex($b));
        self::assertSame(32, strlen($a));
    }

    #[Test]
    public function r6_hash_differs_by_password(): void
    {
        $salt = "\x10\x11\x12\x13\x14\x15\x16\x17";
        $a = Encryption::computeR6Hash('alice', $salt);
        $b = Encryption::computeR6Hash('bob', $salt);
        self::assertNotSame(bin2hex($a), bin2hex($b));
    }

    #[Test]
    public function r6_stream_content_encrypted(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showText('Highly classified', 72, 720, StandardFont::Helvetica, 12);
        $pdf->encrypt('pw', algorithm: EncryptionAlgorithm::Aes_256_R6);
        $bytes = $pdf->toBytes();

        self::assertStringNotContainsString('Highly classified', $bytes);
        self::assertStringContainsString('AESV3', $bytes);
    }

    #[Test]
    public function r6_algorithm_enum_helpers(): void
    {
        self::assertSame(5, EncryptionAlgorithm::Aes_256_R6->pdfVersion());
        self::assertSame(6, EncryptionAlgorithm::Aes_256_R6->revision());
    }
}
