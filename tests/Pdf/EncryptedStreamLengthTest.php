<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Encryption;
use Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * /Length must describe the bytes actually written, not the plaintext they
 * were made from.
 *
 * Encryption grows a stream — AES prepends a 16-byte IV and pads to the block
 * size — and the /Length written for the plaintext used to survive that
 * unchanged. A reader that believes /Length (§7.3.8.2 entitles it to) then
 * reads a truncated stream; a reader that falls back to scanning for
 * `endstream` has to guess where the preceding EOL begins, and guesses wrong
 * whenever the last ciphertext byte happens to be CR — about one file in 256,
 * which is exactly how this surfaced: an intermittently failing decryption
 * test.
 */
final class EncryptedStreamLengthTest extends TestCase
{
    /** @return iterable<string, array{EncryptionAlgorithm}> */
    public static function algorithms(): iterable
    {
        yield 'RC4-128' => [EncryptionAlgorithm::Rc4_128];
        yield 'AES-128' => [EncryptionAlgorithm::Aes_128];
        yield 'AES-256' => [EncryptionAlgorithm::Aes_256];
        yield 'AES-256-R6' => [EncryptionAlgorithm::Aes_256_R6];
    }

    private function available(EncryptionAlgorithm $algo): bool
    {
        return match ($algo) {
            EncryptionAlgorithm::Rc4_128 => true,
            EncryptionAlgorithm::Aes_128 => Encryption::aesAvailable(),
            EncryptionAlgorithm::Aes_256 => Encryption::aes256Available(),
            EncryptionAlgorithm::Aes_256_R6 => Encryption::aes256R6Available(),
        };
    }

    #[Test]
    #[DataProvider('algorithms')]
    public function declared_length_matches_the_bytes_on_disk(EncryptionAlgorithm $algo): void
    {
        if (! $this->available($algo)) {
            self::markTestSkipped('openssl support missing for '.$algo->value);
        }

        $pdf = new PdfDocument;
        $page = $pdf->addPage();
        $page->showText('TopSecretBodyText', 72, 700, StandardFont::Helvetica, 12);
        $pdf->encrypt('', ownerPassword: 'owner-secret', algorithm: $algo);
        $bytes = $pdf->toBytes();

        $streams = 0;
        $offset = 0;
        while (($pos = strpos($bytes, "stream\n", $offset)) !== false) {
            $offset = $pos + 7;
            if (substr($bytes, $pos - 3, 3) === 'end') {
                continue;   // this is the `endstream` of the previous object
            }

            $end = strpos($bytes, "\nendstream", $pos);
            self::assertNotFalse($end, 'unterminated stream');

            $actual = $end - ($pos + 7);
            $declared = $this->declaredLengthBefore($bytes, $pos);
            self::assertNotNull($declared, 'stream without /Length');
            self::assertSame(
                $actual,
                $declared,
                '/Length must count the encrypted bytes, not the plaintext',
            );
            $streams++;
        }

        self::assertGreaterThan(0, $streams, 'nothing was written to check');
    }

    /** The /Length of the dictionary that immediately precedes a stream. */
    private function declaredLengthBefore(string $bytes, int $streamPos): ?int
    {
        $head = substr($bytes, 0, $streamPos);
        if (preg_match_all('@/Length (\d+)@', $head, $m) === 0) {
            return null;
        }

        return (int) end($m[1]);
    }
}
