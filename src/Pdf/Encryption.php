<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

/**
 * Phase 41-42: PDF Standard Security Handler V2 R3 (RC4-128) и V4 R4 (AES-128).
 *
 * ISO 32000-1 §7.6, Algorithms 2-7.
 *
 * Supported:
 *  - RC4_128: V=2, R=3, Length=128 (RC4-128 stream cipher).
 *  - AES_128: V=4, R=4, Length=128 + CFM AESV2 (AES-128-CBC).
 *  - User password (для opening); owner = user если не задан.
 *  - Permissions bits (printing, copying, modification, ...).
 *
 * Не реализовано:
 *  - V5 (AES-256, PDF 1.7 Extension Level 8 / PDF 2.0).
 *  - Public-key encryption (/Filter /PubSec).
 *  - String encryption (currently /Identity для strings).
 */
final class Encryption
{
    /** Standard PDF password padding (ISO 32000-1 §7.6.3.3 Algorithm 2 step a). */
    private const PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    /** Default permissions: print + copy + modify + annotations + assemble. */
    public const PERM_PRINT = 4;

    public const PERM_MODIFY = 8;

    public const PERM_COPY = 16;

    public const PERM_ANNOTATE = 32;

    public const PERM_FILL_FORMS = 256;

    public const PERM_ACCESSIBILITY = 512;

    public const PERM_ASSEMBLE = 1024;

    public const PERM_PRINT_HIGH = 2048;

    /** Reserved bits set per spec (bits 7,8,13..32) — set to 1 always. */
    private const RESERVED_BITS = 0xFFFFF0C0;

    public readonly string $oValue;

    public readonly string $uValue;

    /** 16-byte file encryption key. */
    public readonly string $fileKey;

    public readonly int $permissions;

    public readonly string $fileId;

    public readonly EncryptionAlgorithm $algorithm;

    public function __construct(
        string $userPassword,
        ?string $ownerPassword = null,
        int $permissions = self::PERM_PRINT | self::PERM_COPY | self::PERM_PRINT_HIGH,
        EncryptionAlgorithm $algorithm = EncryptionAlgorithm::Rc4_128,
    ) {
        $this->algorithm = $algorithm;
        if ($algorithm === EncryptionAlgorithm::Aes_128 && ! self::aesAvailable()) {
            throw new \RuntimeException('AES-128 encryption requires openssl extension with aes-128-cbc support');
        }
        $owner = $ownerPassword ?? $userPassword;
        $userPadded = self::padPassword($userPassword);
        $ownerPadded = self::padPassword($owner);

        // Permissions: combine с reserved bits; signed 32-bit.
        $p = ($permissions | self::RESERVED_BITS) & 0xFFFFFFFF;
        // Convert to signed for PDF /P entry.
        if ($p >= 0x80000000) {
            $p -= 0x100000000;
        }
        $this->permissions = $p;

        // File ID — 16 random bytes.
        $this->fileId = random_bytes(16);

        // Algorithm 3: compute /O value.
        $this->oValue = self::computeOValue($ownerPadded, $userPadded);

        // Algorithm 2: compute file encryption key.
        $this->fileKey = self::computeFileKey($userPadded, $this->oValue, $this->permissions, $this->fileId);

        // Algorithm 5: compute /U value.
        $this->uValue = self::computeUValue($this->fileKey, $this->fileId);
    }

    /**
     * Encrypts data для PDF object N gen G (typically G=0).
     *
     * RC4_128: Algorithm 1 — RC4 stream cipher с per-object key.
     * AES_128: Algorithm 1.b — AES-128-CBC с per-object key + random IV,
     *          IV prepended к ciphertext.
     */
    public function encryptObject(string $data, int $objNum, int $genNum = 0): string
    {
        // Build per-object key base: fileKey + obj_num (3 bytes LE) + gen_num (2 bytes LE).
        $base = $this->fileKey
            . chr($objNum & 0xFF)
            . chr(($objNum >> 8) & 0xFF)
            . chr(($objNum >> 16) & 0xFF)
            . chr($genNum & 0xFF)
            . chr(($genNum >> 8) & 0xFF);

        if ($this->algorithm === EncryptionAlgorithm::Aes_128) {
            // AES per-object key: salt "sAlT" appended перед MD5.
            $key = substr(md5($base . "sAlT", true), 0, min(16, strlen($this->fileKey) + 5));
            $iv = random_bytes(16);
            $cipher = (string) openssl_encrypt(
                $data,
                'aes-128-cbc',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
            );

            return $iv . $cipher;
        }

        $objKey = substr(md5($base, true), 0, min(16, strlen($this->fileKey) + 5));

        return self::rc4($objKey, $data);
    }

    public static function aesAvailable(): bool
    {
        return function_exists('openssl_encrypt')
            && in_array('aes-128-cbc', openssl_get_cipher_methods(), true);
    }

    /**
     * Algorithm 5 step (a): pad password to 32 bytes.
     */
    private static function padPassword(string $password): string
    {
        $bytes = substr($password, 0, 32);
        if (strlen($bytes) < 32) {
            $bytes .= substr(self::PADDING, 0, 32 - strlen($bytes));
        }

        return $bytes;
    }

    /**
     * Algorithm 3: compute /O entry.
     */
    private static function computeOValue(string $ownerPadded, string $userPadded): string
    {
        // a. MD5 of owner_padded.
        $hash = md5($ownerPadded, true);
        // b. Iterate MD5 50 times (V≥2, R≥3).
        for ($i = 0; $i < 50; $i++) {
            $hash = md5($hash, true);
        }
        // c. Take first 16 bytes as RC4 key.
        $rc4Key = substr($hash, 0, 16);
        // d. Encrypt user_padded с RC4.
        $encrypted = self::rc4($rc4Key, $userPadded);
        // e. Iterate XOR cycling key bytes 19 times.
        for ($i = 1; $i <= 19; $i++) {
            $alt = '';
            for ($j = 0; $j < 16; $j++) {
                $alt .= chr(ord($rc4Key[$j]) ^ $i);
            }
            $encrypted = self::rc4($alt, $encrypted);
        }

        return $encrypted;
    }

    /**
     * Algorithm 2: compute file encryption key.
     */
    private static function computeFileKey(string $userPadded, string $oValue, int $permissions, string $fileId): string
    {
        $data = $userPadded;
        $data .= $oValue;
        // Permissions как 4-byte LE.
        $p = $permissions < 0 ? $permissions + 0x100000000 : $permissions;
        $data .= chr($p & 0xFF) . chr(($p >> 8) & 0xFF)
            . chr(($p >> 16) & 0xFF) . chr(($p >> 24) & 0xFF);
        $data .= $fileId;
        // Step (f): metadata flag — for V4+ only. Skip для V2.

        $hash = md5($data, true);
        // V≥2, R≥3: iterate MD5 50 more times truncated to 16 bytes.
        for ($i = 0; $i < 50; $i++) {
            $hash = md5(substr($hash, 0, 16), true);
        }

        return substr($hash, 0, 16);
    }

    /**
     * Algorithm 5: compute /U entry (R≥3).
     */
    private static function computeUValue(string $fileKey, string $fileId): string
    {
        // MD5 of padding + fileID.
        $hash = md5(self::PADDING . $fileId, true);
        // RC4 encrypt the 16-byte hash с fileKey.
        $encrypted = self::rc4($fileKey, $hash);
        // Iterate 19 rounds с XOR'd key.
        for ($i = 1; $i <= 19; $i++) {
            $alt = '';
            for ($j = 0; $j < 16; $j++) {
                $alt .= chr(ord($fileKey[$j]) ^ $i);
            }
            $encrypted = self::rc4($alt, $encrypted);
        }
        // Pad encrypted (16 bytes) к 32 bytes appending arbitrary 16 bytes
        // (стандарт: append arbitrary; many encoders append zeros, others
        // duplicate first 16). Duplicate первые 16 (commonly seen).
        return $encrypted . substr($encrypted, 0, 16);
    }

    /**
     * RC4 stream cipher. Не используем openssl_encrypt — RC4 удалён в
     * OpenSSL 3.0 default provider. Self-contained ~30 строк.
     */
    public static function rc4(string $key, string $data): string
    {
        $s = range(0, 255);
        $keyBytes = array_values(unpack('C*', $key) ?: []);
        $keyLen = count($keyBytes);
        $j = 0;
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + $keyBytes[$i % $keyLen]) % 256;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $result = '';
        $i = 0;
        $j = 0;
        $len = strlen($data);
        for ($k = 0; $k < $len; $k++) {
            $i = ($i + 1) % 256;
            $j = ($j + $s[$i]) % 256;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $kt = $s[($s[$i] + $s[$j]) % 256];
            $result .= chr(ord($data[$k]) ^ $kt);
        }

        return $result;
    }

    /**
     * Encode bytes как PDF literal string with hex form: <abcdef...>.
     */
    public static function asHexString(string $bytes): string
    {
        return '<' . bin2hex($bytes) . '>';
    }
}
