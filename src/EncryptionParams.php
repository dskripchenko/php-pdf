<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf;

use Dskripchenko\PhpPdf\Pdf\Encryption;
use Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm;

/**
 * Phase 217: Document-level encryption parameters VO.
 *
 * Pass к `Document::__construct(encryption: new EncryptionParams(...))` для
 * declarative encryption setup без drop'ing к low-level Pdf\Document API.
 *
 * Mirrors `Pdf\Document::encrypt()` signature — параметры forwarded as-is.
 *
 * Default algorithm: RC4-128 V2 R3 (PDF 1.4-compat). Recommend AES-128
 * (V4 R4, PDF 1.6) или AES-256 R6 (V5 R6, PDF 2.0) для new documents.
 */
final readonly class EncryptionParams
{
    public function __construct(
        public string $userPassword,
        public ?string $ownerPassword = null,
        public int $permissions = Encryption::PERM_PRINT | Encryption::PERM_COPY | Encryption::PERM_PRINT_HIGH,
        public EncryptionAlgorithm $algorithm = EncryptionAlgorithm::Rc4_128,
    ) {}
}
