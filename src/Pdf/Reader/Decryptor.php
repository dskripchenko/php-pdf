<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Reader;

use Dskripchenko\PhpPdf\Pdf\Encryption;

/**
 * Standard-security-handler decryption (ISO 32000-1 §7.6.3, ISO 32000-2 §7.6).
 *
 * Supports V1/V2 (RC4), V4 (RC4 or AESV2 via crypt filters), and V5 R5/R6
 * (AES-256). The file encryption key is derived from the user password (empty
 * by default) — Algorithm 2 for V≤4, Algorithm 2.A for V5 — with a fallback to
 * the owner password. Crypto primitives are reused from {@see Encryption}.
 *
 * Objects stored inside object streams are *not* decrypted here: the object
 * stream itself is decrypted as a top-level stream, after which its members are
 * already in cleartext. Cross-reference streams are never encrypted.
 */
final class Decryptor
{
    private const PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        . "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private const METHOD_IDENTITY = 'identity';
    private const METHOD_RC4 = 'rc4';
    private const METHOD_AESV2 = 'aesv2';
    private const METHOD_AESV3 = 'aesv3';

    private function __construct(
        private readonly string $fileKey,
        private readonly string $streamMethod,
        private readonly string $stringMethod,
    ) {
    }

    /**
     * Build a decryptor from the trailer's /Encrypt dictionary.
     */
    public static function create(PdfDictionary $enc, string $fileIdFirst, string $password = ''): self
    {
        $filter = $enc->get('Filter');
        if ($filter instanceof PdfName && $filter->value !== 'Standard') {
            throw new PdfParseException("Unsupported security handler: {$filter->value}");
        }

        $v = self::int($enc->get('V'), 0);
        $r = self::int($enc->get('R'), 0);

        if ($v >= 5) {
            $fileKey = self::deriveKeyV5($enc, $password, $r);
            return new self($fileKey, self::METHOD_AESV3, self::METHOD_AESV3);
        }

        $keyLen = $v === 1 ? 5 : intdiv(self::int($enc->get('Length'), 40), 8);
        $encryptMetadata = !($enc->get('EncryptMetadata') === false);
        $fileKey = self::deriveKeyV2V4($enc, $password, $fileIdFirst, $keyLen, $r, $v, $encryptMetadata);

        [$streamMethod, $stringMethod] = self::resolveMethods($enc, $v);

        return new self($fileKey, $streamMethod, $stringMethod);
    }

    public function decryptStream(string $data, int $objNum, int $gen): string
    {
        return $this->apply($this->streamMethod, $data, $objNum, $gen);
    }

    public function decryptString(string $data, int $objNum, int $gen): string
    {
        return $this->apply($this->stringMethod, $data, $objNum, $gen);
    }

    private function apply(string $method, string $data, int $objNum, int $gen): string
    {
        return match ($method) {
            self::METHOD_IDENTITY => $data,
            self::METHOD_RC4 => Encryption::rc4($this->objectKey($objNum, $gen, aes: false), $data),
            self::METHOD_AESV2 => $this->aesCbc($data, $this->objectKey($objNum, $gen, aes: true), 128),
            self::METHOD_AESV3 => $this->aesCbc($data, $this->fileKey, 256),
            default => $data,
        };
    }

    /** Per-object key for RC4/AESV2 (Algorithm 1, §7.6.2). */
    private function objectKey(int $objNum, int $gen, bool $aes): string
    {
        $base = $this->fileKey
            . chr($objNum & 0xFF) . chr(($objNum >> 8) & 0xFF) . chr(($objNum >> 16) & 0xFF)
            . chr($gen & 0xFF) . chr(($gen >> 8) & 0xFF)
            . ($aes ? 'sAlT' : '');

        return substr(md5($base, true), 0, min(16, strlen($this->fileKey) + 5));
    }

    private function aesCbc(string $data, string $key, int $bits): string
    {
        if (strlen($data) < 16) {
            return '';
        }
        $iv = substr($data, 0, 16);
        $cipher = substr($data, 16);
        if ($cipher === '' || strlen($cipher) % 16 !== 0) {
            return '';
        }
        $plain = openssl_decrypt(
            $cipher,
            "aes-{$bits}-cbc",
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv,
        );
        if ($plain === false || $plain === '') {
            return '';
        }
        // Strip PKCS#7 padding.
        $pad = ord($plain[strlen($plain) - 1]);
        if ($pad >= 1 && $pad <= 16 && $pad <= strlen($plain)) {
            $plain = substr($plain, 0, -$pad);
        }
        return $plain;
    }

    // --- key derivation ----------------------------------------------------

    private static function deriveKeyV2V4(
        PdfDictionary $enc,
        string $password,
        string $fileIdFirst,
        int $keyLen,
        int $revision,
        int $v,
        bool $encryptMetadata,
    ): string {
        $userPadded = self::padPassword($password);
        $o = self::stringBytes($enc->get('O'));
        $p = self::int($enc->get('P'), 0);
        $pUnsigned = $p < 0 ? $p + 0x100000000 : $p;

        $data = $userPadded
            . $o
            . chr($pUnsigned & 0xFF) . chr(($pUnsigned >> 8) & 0xFF)
            . chr(($pUnsigned >> 16) & 0xFF) . chr(($pUnsigned >> 24) & 0xFF)
            . $fileIdFirst;

        if ($v >= 4 && !$encryptMetadata) {
            $data .= "\xFF\xFF\xFF\xFF";
        }

        $hash = md5($data, true);
        if ($revision >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $hash = md5(substr($hash, 0, $keyLen), true);
            }
        }

        return substr($hash, 0, $keyLen);
    }

    /** Algorithm 2.A — derive the V5 file key from user, then owner, password. */
    private static function deriveKeyV5(PdfDictionary $enc, string $password, int $revision): string
    {
        $pw = substr($password, 0, 127);
        $u = self::stringBytes($enc->get('U'));
        $ue = self::stringBytes($enc->get('UE'));

        if (strlen($u) >= 48) {
            $valSalt = substr($u, 32, 8);
            $keySalt = substr($u, 40, 8);
            if (self::v5Hash($pw, $valSalt, '', $revision) === substr($u, 0, 32)) {
                return self::v5FileKey(self::v5Hash($pw, $keySalt, '', $revision), $ue);
            }
        }

        // Owner-password path: hashes mix in the 48-byte /U value.
        $o = self::stringBytes($enc->get('O'));
        $oe = self::stringBytes($enc->get('OE'));
        if (strlen($o) >= 48 && strlen($u) >= 48) {
            $valSalt = substr($o, 32, 8);
            $keySalt = substr($o, 40, 8);
            if (self::v5Hash($pw, $valSalt, $u, $revision) === substr($o, 0, 32)) {
                return self::v5FileKey(self::v5Hash($pw, $keySalt, $u, $revision), $oe);
            }
        }

        throw new PdfParseException('Cannot decrypt: wrong password (V5)');
    }

    private static function v5Hash(string $pw, string $salt, string $udata, int $revision): string
    {
        if ($revision >= 6) {
            return Encryption::computeR6Hash($pw, $salt, $udata);
        }
        return hash('sha256', $pw . $salt . $udata, true);
    }

    private static function v5FileKey(string $intermediateKey, string $encryptedKey): string
    {
        $key = openssl_decrypt(
            $encryptedKey,
            'aes-256-cbc',
            $intermediateKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            str_repeat("\x00", 16),
        );
        if ($key === false || strlen($key) < 32) {
            throw new PdfParseException('V5 file-key decryption failed');
        }
        return substr($key, 0, 32);
    }

    /**
     * Determine the stream and string ciphers for V1/V2/V4.
     *
     * @return array{string, string} [streamMethod, stringMethod]
     */
    private static function resolveMethods(PdfDictionary $enc, int $v): array
    {
        if ($v <= 2) {
            return [self::METHOD_RC4, self::METHOD_RC4];
        }

        $cf = $enc->get('CF');
        $cf = $cf instanceof PdfDictionary ? $cf : new PdfDictionary([]);

        $resolve = static function (mixed $name) use ($cf): string {
            if (!$name instanceof PdfName || $name->value === 'Identity') {
                return self::METHOD_IDENTITY;
            }
            $filter = $cf->get($name->value);
            if (!$filter instanceof PdfDictionary) {
                return self::METHOD_IDENTITY;
            }
            $cfm = $filter->get('CFM');
            $cfmName = $cfm instanceof PdfName ? $cfm->value : '';
            return match ($cfmName) {
                'V2' => self::METHOD_RC4,
                'AESV2' => self::METHOD_AESV2,
                'AESV3' => self::METHOD_AESV3,
                default => self::METHOD_IDENTITY,
            };
        };

        return [$resolve($enc->get('StmF')), $resolve($enc->get('StrF'))];
    }

    // --- small helpers -----------------------------------------------------

    private static function padPassword(string $password): string
    {
        $bytes = substr($password, 0, 32);
        if (strlen($bytes) < 32) {
            $bytes .= substr(self::PADDING, 0, 32 - strlen($bytes));
        }
        return $bytes;
    }

    private static function stringBytes(mixed $value): string
    {
        return $value instanceof PdfString ? $value->bytes : '';
    }

    private static function int(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }
}
