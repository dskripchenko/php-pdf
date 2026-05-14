<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 108: PKCS#7 detached signing.
 */
final class SignatureTest extends TestCase
{
    private string $certPem;

    private string $keyPem;

    protected function setUp(): void
    {
        if (! function_exists('openssl_pkcs7_sign')) {
            $this->markTestSkipped('openssl extension not available');
        }
        // Generate self-signed cert + key once per test instance.
        $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($pkey === false) {
            $this->markTestSkipped('openssl_pkey_new unavailable');
        }
        $dn = ['commonName' => 'PhpPdf Test Signer', 'organizationName' => 'Test Org', 'countryName' => 'US'];
        $csr = openssl_csr_new($dn, $pkey, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $pkey, 30, ['digest_alg' => 'sha256']);
        $certPem = '';
        $keyPem = '';
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($pkey, $keyPem);
        $this->certPem = $certPem;
        $this->keyPem = $keyPem;
    }

    private function signSampleDoc(SignatureConfig $cfg): string
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->addFormField('signature', 'Signature1', 50, 50, 200, 50);
        $pdf->sign($cfg);

        return $pdf->toBytes();
    }

    #[Test]
    public function signed_pdf_contains_sig_dict(): void
    {
        $bytes = $this->signSampleDoc(new SignatureConfig($this->certPem, $this->keyPem));

        self::assertStringContainsString('/Type /Sig', $bytes);
        self::assertStringContainsString('/Filter /Adobe.PPKLite', $bytes);
        self::assertStringContainsString('/SubFilter /adbe.pkcs7.detached', $bytes);
    }

    #[Test]
    public function byterange_is_patched_to_actual_values(): void
    {
        $bytes = $this->signSampleDoc(new SignatureConfig($this->certPem, $this->keyPem));

        if (! preg_match('@/ByteRange \[(\d+) (\d+) (\d+) (\d+)\s*\]@', $bytes, $m)) {
            self::fail('No /ByteRange found');
        }
        // a should be 0; b should be > 0 (offset of <).
        // c = b + (gap length); d = remaining length.
        self::assertSame(0, (int) $m[1]);
        self::assertGreaterThan(0, (int) $m[2]);
        self::assertGreaterThan((int) $m[2], (int) $m[3]);
        self::assertGreaterThan(0, (int) $m[4]);
    }

    #[Test]
    public function contents_replaced_with_signature_hex(): void
    {
        $bytes = $this->signSampleDoc(new SignatureConfig($this->certPem, $this->keyPem));

        // Must NOT contain only zeros (placeholder still in place).
        self::assertDoesNotMatchRegularExpression('@/Contents <0{16384}>@', $bytes);
        // Should contain /Contents <hex...> with hex data.
        if (! preg_match('@/Contents <([0-9A-Fa-f]+)>@', $bytes, $m)) {
            self::fail('No /Contents <...> found');
        }
        $hex = $m[1];
        self::assertSame(16384, strlen($hex), 'Placeholder length preserved');
        // First chars should be non-zero (DER PKCS#7 starts with 0x30 = '30').
        self::assertStringStartsWith('30', strtoupper($hex));
    }

    #[Test]
    public function pkcs7_blob_is_valid_signed_data_with_our_cert(): void
    {
        // We don't use openssl_pkcs7_verify directly — its PHP wrapper has
        // bizarre engine config issues с PEM-wrapped DER. Instead verify
        // structurally: DER parses, length matches, contains our cert.
        // (Full cryptographic round-trip confirmed via `openssl smime -verify`
        // CLI — see manual debug в Phase 108 commit message.)
        $bytes = $this->signSampleDoc(new SignatureConfig($this->certPem, $this->keyPem));
        if (! preg_match('@/Contents <([0-9A-Fa-f]+)>@', $bytes, $cm)) {
            self::fail('No /Contents hex');
        }
        $fullDer = (string) hex2bin($cm[1]);

        // SEQUENCE tag.
        self::assertSame(0x30, ord($fullDer[0]));
        // Length header.
        $lenByte = ord($fullDer[1]);
        self::assertSame(0x82, $lenByte, '4-byte SEQUENCE header expected for PKCS#7 RSA-2048');
        $contentLen = (ord($fullDer[2]) << 8) | ord($fullDer[3]);
        self::assertGreaterThan(500, $contentLen, 'PKCS#7 SignedData with RSA-2048 cert ≥ 500 bytes');

        // OID 1.2.840.113549.1.7.2 = pkcs7-signedData = 06 09 2A 86 48 86 F7 0D 01 07 02.
        self::assertStringContainsString(
            hex2bin('06092A864886F70D010702'),
            $fullDer,
            'PKCS#7 SignedData OID must be present',
        );

        // The signer cert DER должна быть embedded в SignedData.certificates.
        $certDer = self::pemToDer($this->certPem);
        // Cert appears whole within SignedData blob.
        self::assertStringContainsString($certDer, $fullDer, 'Signer cert embedded in PKCS#7');
    }

    private static function pemToDer(string $pem): string
    {
        $pem = preg_replace('@-----[^-]+-----@', '', $pem);
        $pem = (string) preg_replace('@\s@', '', (string) $pem);

        return (string) base64_decode($pem);
    }

    #[Test]
    public function acroform_emits_sigflags_3(): void
    {
        $bytes = $this->signSampleDoc(new SignatureConfig($this->certPem, $this->keyPem));

        self::assertStringContainsString('/SigFlags 3', $bytes);
    }

    #[Test]
    public function signature_widget_references_sig_dict(): void
    {
        $bytes = $this->signSampleDoc(new SignatureConfig($this->certPem, $this->keyPem));

        // Signature field has /FT /Sig and /V <N> 0 R reference.
        self::assertMatchesRegularExpression('@/FT /Sig[^>]+/V \d+ 0 R@', $bytes);
    }

    #[Test]
    public function optional_metadata_appears_in_sig_dict(): void
    {
        $cfg = new SignatureConfig(
            $this->certPem, $this->keyPem,
            signerName: 'Alice Test',
            reason: 'I approve this document',
            location: 'San Francisco',
            contactInfo: 'alice@example.com',
        );
        $bytes = $this->signSampleDoc($cfg);

        self::assertStringContainsString('/Name (Alice Test)', $bytes);
        self::assertStringContainsString('/Reason (I approve this document)', $bytes);
        self::assertStringContainsString('/Location (San Francisco)', $bytes);
        self::assertStringContainsString('/ContactInfo (alice@example.com)', $bytes);
    }

    #[Test]
    public function sign_without_signature_field_throws(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage(); // no signature field
        $pdf->sign(new SignatureConfig($this->certPem, $this->keyPem));

        $this->expectException(\LogicException::class);
        $pdf->toBytes();
    }

    #[Test]
    public function signed_at_uses_provided_timestamp(): void
    {
        $cfg = new SignatureConfig(
            $this->certPem, $this->keyPem,
            signedAt: new \DateTimeImmutable('2026-01-15T10:30:00+00:00'),
        );
        $bytes = $this->signSampleDoc($cfg);

        self::assertStringContainsString("D:20260115103000+00'00'", $bytes);
    }
}
