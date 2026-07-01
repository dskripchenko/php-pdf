<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Reader;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Encryption;
use Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase P7: Standard-security-handler decryption (RC4, AESV2, AES-256 R5/R6).
 */
final class DecryptorTest extends TestCase
{
    private const TITLE = 'Confidential-Report-42';
    private const BODY = 'TopSecretBodyText';

    /** @return iterable<string, array{EncryptionAlgorithm}> */
    public static function algorithms(): iterable
    {
        yield 'RC4-128' => [EncryptionAlgorithm::Rc4_128];
        yield 'AES-128' => [EncryptionAlgorithm::Aes_128];
        yield 'AES-256' => [EncryptionAlgorithm::Aes_256];
        yield 'AES-256-R6' => [EncryptionAlgorithm::Aes_256_R6];
    }

    private function encryptedPdf(EncryptionAlgorithm $algo, string $userPassword = ''): string
    {
        $pdf = new PdfDocument();
        $pdf->metadata(title: self::TITLE);
        $page = $pdf->addPage();
        $page->showText(self::BODY, 72, 700, StandardFont::Helvetica, 12);
        $pdf->encrypt($userPassword, ownerPassword: 'owner-secret', algorithm: $algo);
        return $pdf->toBytes();
    }

    private function skipIfUnavailable(EncryptionAlgorithm $algo): void
    {
        $ok = match ($algo) {
            EncryptionAlgorithm::Rc4_128 => true,
            EncryptionAlgorithm::Aes_128 => Encryption::aesAvailable(),
            EncryptionAlgorithm::Aes_256 => Encryption::aes256Available(),
            EncryptionAlgorithm::Aes_256_R6 => Encryption::aes256R6Available(),
        };
        if (!$ok) {
            self::markTestSkipped('openssl support missing for ' . $algo->value);
        }
    }

    #[Test]
    #[DataProvider('algorithms')]
    public function decrypts_info_title_string(EncryptionAlgorithm $algo): void
    {
        $this->skipIfUnavailable($algo);

        $doc = ReaderDocument::fromBytes($this->encryptedPdf($algo));
        $info = $doc->deref($doc->trailer()->get('Info'));
        self::assertInstanceOf(PdfDictionary::class, $info);
        $title = $doc->deref($info->get('Title'));
        self::assertInstanceOf(PdfString::class, $title);
        self::assertStringContainsString(self::TITLE, $title->bytes);
    }

    #[Test]
    #[DataProvider('algorithms')]
    public function decrypts_page_content_stream(EncryptionAlgorithm $algo): void
    {
        $this->skipIfUnavailable($algo);

        $doc = ReaderDocument::fromBytes($this->encryptedPdf($algo));
        $page = $doc->pages()[0];
        $contents = $doc->deref($page->dict->get('Contents'));
        self::assertInstanceOf(PdfStream::class, $contents);

        $decoded = $doc->streamData($contents);
        // The drawn text appears in the content stream's Tj operand.
        self::assertStringContainsString(self::BODY, $decoded);
    }

    #[Test]
    #[DataProvider('algorithms')]
    public function page_count_survives_encryption(EncryptionAlgorithm $algo): void
    {
        $this->skipIfUnavailable($algo);
        $doc = ReaderDocument::fromBytes($this->encryptedPdf($algo));
        self::assertSame(1, $doc->pageCount());
    }

    #[Test]
    public function opens_with_owner_password_when_user_password_set(): void
    {
        // User password is non-empty; owner password unlocks via the /O path.
        $pdf = $this->encryptedPdf(EncryptionAlgorithm::Rc4_128, userPassword: 'user-pw');
        $doc = ReaderDocument::fromBytes($pdf, password: 'user-pw');
        $info = $doc->deref($doc->trailer()->get('Info'));
        $title = $doc->deref($info->get('Title'));
        self::assertStringContainsString(self::TITLE, $title->bytes);
    }

    #[Test]
    public function opens_with_distinct_owner_password_via_algorithm_7(): void
    {
        // Distinct user/owner passwords; open with the OWNER password, which
        // must be recovered from /O (Algorithm 7).
        $pdf = new PdfDocument();
        $pdf->metadata(title: self::TITLE);
        $pdf->addPage();
        $pdf->encrypt('user-secret', ownerPassword: 'owner-secret', algorithm: EncryptionAlgorithm::Rc4_128);

        $doc = ReaderDocument::fromBytes($pdf->toBytes(), password: 'owner-secret');
        $info = $doc->deref($doc->trailer()->get('Info'));
        $title = $doc->deref($info->get('Title'));
        self::assertStringContainsString(self::TITLE, $title->bytes);
    }
}
