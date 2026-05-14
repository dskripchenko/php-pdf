<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\EncryptionParams;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\Encryption;
use Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 217: top-level Document encryption/signing/PDF-A integration.
 */
final class DocumentTopLevelEncryptionTest extends TestCase
{
    private function buildBaseDoc(): array
    {
        return [new Paragraph([new Run('Secret content.')])];
    }

    #[Test]
    public function default_no_encryption(): void
    {
        $doc = new Document(new Section($this->buildBaseDoc()));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/Encrypt', $bytes);
        self::assertStringContainsString('Secret content', $bytes);
    }

    #[Test]
    public function rc4_encryption_applied(): void
    {
        $doc = new Document(
            new Section($this->buildBaseDoc()),
            encryption: new EncryptionParams('user-password'),
        );
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/Encrypt', $bytes);
        // Plain text не должен быть visible (encrypted streams).
        self::assertStringNotContainsString('Secret content', $bytes);
    }

    #[Test]
    public function aes_128_encryption_applied(): void
    {
        $doc = new Document(
            new Section($this->buildBaseDoc()),
            encryption: new EncryptionParams(
                userPassword: 'user',
                ownerPassword: 'owner',
                algorithm: EncryptionAlgorithm::Aes_128,
            ),
        );
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/V 4', $bytes);
        self::assertStringContainsString('/R 4', $bytes);
        self::assertStringContainsString('/CFM /AESV2', $bytes);
    }

    #[Test]
    public function aes_256_r6_encryption_pdf_2_0(): void
    {
        $doc = new Document(
            new Section($this->buildBaseDoc()),
            encryption: new EncryptionParams(
                userPassword: 'user',
                algorithm: EncryptionAlgorithm::Aes_256_R6,
            ),
        );
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // R6 bumps PDF version к 2.0.
        self::assertStringStartsWith('%PDF-2.0', $bytes);
        self::assertStringContainsString('/V 5', $bytes);
        self::assertStringContainsString('/R 6', $bytes);
    }

    #[Test]
    public function pdf_a_1b_applied(): void
    {
        $iccPath = $this->findIccProfile();
        if ($iccPath === null) {
            self::markTestSkipped('No ICC profile available.');
        }

        $doc = new Document(
            new Section($this->buildBaseDoc()),
            pdfA: new PdfAConfig(
                iccProfilePath: $iccPath,
                conformance: PdfAConfig::CONFORMANCE_B,
            ),
        );
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/Type /OutputIntent', $bytes);
        self::assertStringContainsString('/S /GTS_PDFA1', $bytes);
        self::assertStringContainsString('/Type /Metadata', $bytes);
    }

    #[Test]
    public function pdf_a_1a_auto_enables_tagged(): void
    {
        $iccPath = $this->findIccProfile();
        if ($iccPath === null) {
            self::markTestSkipped('No ICC profile available.');
        }

        $doc = new Document(
            new Section($this->buildBaseDoc()),
            pdfA: new PdfAConfig(
                iccProfilePath: $iccPath,
                conformance: PdfAConfig::CONFORMANCE_A,
            ),
        );
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // /MarkInfo /Marked true — Tagged PDF marker.
        self::assertStringContainsString('/MarkInfo', $bytes);
        self::assertStringContainsString('/Marked true', $bytes);
    }

    #[Test]
    public function pdf_a_and_encryption_throws(): void
    {
        $iccPath = $this->findIccProfile();
        if ($iccPath === null) {
            self::markTestSkipped('No ICC profile available.');
        }

        $doc = new Document(
            new Section($this->buildBaseDoc()),
            encryption: new EncryptionParams('user'),
            pdfA: new PdfAConfig(iccProfilePath: $iccPath),
        );

        $this->expectException(\LogicException::class);
        $doc->toBytes(new Engine(compressStreams: false));
    }

    #[Test]
    public function encryption_permissions_passed_through(): void
    {
        $doc = new Document(
            new Section($this->buildBaseDoc()),
            encryption: new EncryptionParams(
                userPassword: 'user',
                permissions: Encryption::PERM_PRINT, // только print, no copy
            ),
        );
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/Encrypt', $bytes);
        self::assertStringContainsString('/P', $bytes); // permissions field
    }

    private function findIccProfile(): ?string
    {
        // Use bundled dummy ICC от existing PdfATest.
        $path = __DIR__.'/fixtures/dummy.icc';
        if (is_readable($path)) {
            return $path;
        }

        return null;
    }
}
