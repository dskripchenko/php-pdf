<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end signature validation with the OpenSSL CLI — an implementation
 * independent of ours. The CMS blob from /Contents must verify against
 * exactly the bytes selected by /ByteRange; a single flipped bit inside the
 * signed range must break verification. In-process structural tests
 * (SignatureTest) can't catch a signature computed over the wrong bytes.
 */
final class SignatureOpenSslTest extends TestCase
{
    private string $certPem;

    private string $keyPem;

    private string $workDir;

    protected function setUp(): void
    {
        if (! function_exists('openssl_pkcs7_sign')) {
            self::markTestSkipped('openssl extension not available');
        }
        exec('openssl version 2>/dev/null', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('openssl CLI not available');
        }

        $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($pkey === false) {
            self::markTestSkipped('openssl_pkey_new unavailable');
        }
        $dn = ['commonName' => 'PhpPdf OpenSSL CLI Signer', 'countryName' => 'US'];
        $csr = openssl_csr_new($dn, $pkey, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $pkey, 30, ['digest_alg' => 'sha256']);
        $certPem = '';
        $keyPem = '';
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($pkey, $keyPem);
        $this->certPem = $certPem;
        $this->keyPem = $keyPem;

        $dir = sys_get_temp_dir().'/phppdf-sig-'.bin2hex(random_bytes(6));
        if (! mkdir($dir, 0700, true)) {
            self::markTestSkipped("cannot create $dir");
        }
        $this->workDir = $dir;
    }

    protected function tearDown(): void
    {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            foreach ((array) glob($this->workDir.'/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($this->workDir);
        }
    }

    private function signedPdf(): string
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->addFormField('signature', 'Signature1', 50, 50, 200, 50);
        $pdf->sign(new SignatureConfig($this->certPem, $this->keyPem));

        return $pdf->toBytes();
    }

    /**
     * @return array{signedContent: string, der: string}
     */
    private function extractSignature(string $bytes): array
    {
        self::assertSame(
            1,
            preg_match('@/ByteRange \[(\d+) (\d+) (\d+) (\d+)\s*\]@', $bytes, $br),
            'No /ByteRange found',
        );
        [, $a, $b, $c, $d] = array_map(intval(...), $br);
        self::assertSame(strlen($bytes), $c + $d, '/ByteRange must span to EOF');

        self::assertSame(
            1,
            preg_match('@/Contents <([0-9A-Fa-f]+)>@', $bytes, $ct),
            'No /Contents found',
        );
        $blob = (string) hex2bin($ct[1]);

        // The placeholder is zero-padded past the DER; cut at the encoded
        // length (30 82 LL LL — constructed SEQUENCE, long-form length).
        self::assertSame("\x30\x82", substr($blob, 0, 2), 'CMS must start with a long-form SEQUENCE');
        $derLen = 4 + (ord($blob[2]) << 8) + ord($blob[3]);
        self::assertLessThanOrEqual(strlen($blob), $derLen);

        return [
            'signedContent' => substr($bytes, $a, $b).substr($bytes, $c, $d),
            'der' => substr($blob, 0, $derLen),
        ];
    }

    private function opensslVerify(string $signedContent, string $der): int
    {
        $contentFile = $this->workDir.'/content.bin';
        $derFile = $this->workDir.'/sig.der';
        file_put_contents($contentFile, $signedContent);
        file_put_contents($derFile, $der);

        // -noverify skips chain validation (self-signed) but still checks
        // the signature against the content.
        exec(sprintf(
            'openssl cms -verify -binary -inform DER -in %s -content %s -noverify -out /dev/null 2>/dev/null',
            escapeshellarg($derFile),
            escapeshellarg($contentFile),
        ), $out, $code);

        return $code;
    }

    #[Test]
    public function openssl_cli_verifies_signature_over_byte_range(): void
    {
        $sig = $this->extractSignature($this->signedPdf());

        self::assertSame(
            0,
            $this->opensslVerify($sig['signedContent'], $sig['der']),
            'openssl cms -verify rejected the signature',
        );
    }

    #[Test]
    public function tampering_inside_byte_range_breaks_verification(): void
    {
        $bytes = $this->signedPdf();
        $sig = $this->extractSignature($bytes);

        $tampered = $sig['signedContent'];
        $pos = intdiv(strlen($tampered), 2);
        $tampered[$pos] = $tampered[$pos] === 'A' ? 'B' : 'A';

        self::assertNotSame(
            0,
            $this->opensslVerify($tampered, $sig['der']),
            'openssl accepted a signature over tampered content',
        );
    }
}
