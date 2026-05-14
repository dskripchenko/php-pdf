<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

/**
 * Phase 108: PKCS#7 detached signature configuration.
 *
 * ISO 32000-1 §12.8.1 — signature dictionary with /SubFilter
 * /adbe.pkcs7.detached. The signed bytes cover the whole PDF except
 * the /Contents hex string (replaced post-emission).
 *
 * Cert/key могут быть:
 *  - PEM strings (преимущественный вариант, no file I/O).
 *  - "file://<path>" PEM file references.
 *
 * Optional dict fields (PDF spec §12.8.1):
 *  - /M    (signing time, D:YYYYMMDDHHMMSS+HH'MM').
 *  - /Reason
 *  - /Location
 *  - /ContactInfo
 *  - /Name (signer name displayed в виде overlay).
 */
final readonly class SignatureConfig
{
    public function __construct(
        public string $certPem,
        public string $privateKeyPem,
        public ?string $privateKeyPassphrase = null,
        public ?string $signerName = null,
        public ?string $reason = null,
        public ?string $location = null,
        public ?string $contactInfo = null,
        public ?\DateTimeImmutable $signedAt = null,
    ) {
        if (! function_exists('openssl_pkcs7_sign')) {
            throw new \RuntimeException('PKCS#7 signing requires openssl extension');
        }
    }

    public function effectiveSignedAt(): \DateTimeImmutable
    {
        return $this->signedAt ?? new \DateTimeImmutable;
    }

    /**
     * Format signing time per PDF spec: D:YYYYMMDDHHMMSS+HH'MM'.
     */
    public function pdfSignedAt(): string
    {
        $dt = $this->effectiveSignedAt();
        $tz = $dt->format('P');           // +03:00
        $tzPdf = str_replace(':', "'", $tz) . "'"; // +03'00'

        return 'D:' . $dt->format('YmdHis') . $tzPdf;
    }
}
