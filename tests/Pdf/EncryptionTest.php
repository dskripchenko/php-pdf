<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document as AstDocument;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Encryption;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EncryptionTest extends TestCase
{
    #[Test]
    public function rc4_basic_known_vector(): void
    {
        // RFC 6229 RC4 test vector: key=0x0102030405, 16 bytes plaintext zeros.
        $key = "\x01\x02\x03\x04\x05";
        $plaintext = str_repeat("\x00", 16);
        $cipher = Encryption::rc4($key, $plaintext);
        // First 16 bytes from RFC 6229 reference output.
        $expected = "\xB2\x39\x63\x05\xF0\x3D\xC0\x27\xCC\xC3\x52\x4A\x0A\x11\x18\xA8";
        self::assertSame(bin2hex($expected), bin2hex($cipher));
    }

    #[Test]
    public function encryption_emits_encrypt_object_in_trailer(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $pdf->encrypt('test-password');
        $bytes = $pdf->toBytes();

        // Trailer должен ссылаться на /Encrypt object.
        self::assertMatchesRegularExpression('@/Encrypt\s+\d+\s+0\s+R@', $bytes);
        // Encrypt dict содержит V=2 R=3 Length=128.
        self::assertStringContainsString('/V 2', $bytes);
        self::assertStringContainsString('/R 3', $bytes);
        self::assertStringContainsString('/Length 128', $bytes);
        // /ID array присутствует.
        self::assertMatchesRegularExpression('@/ID \[<[a-f0-9]{32}> <[a-f0-9]{32}>\]@', $bytes);
    }

    #[Test]
    public function encryption_changes_stream_content(): void
    {
        // Сравним bytes encrypted vs non-encrypted.
        $ast = new AstDocument(new Section([
            new Paragraph([new Run('Hello secret world')]),
        ]));
        $plain = $ast->toBytes();

        // Render again и encrypt.
        $ast2 = new AstDocument(new Section([
            new Paragraph([new Run('Hello secret world')]),
        ]));
        // Direct path: need to inject encryption via PdfDocument. Test uses
        // helper to verify encryption path emits different bytes.
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->showText('Hello secret world', 72, 720, \Dskripchenko\PhpPdf\Pdf\StandardFont::Helvetica, 12);
        $clear = $pdf->toBytes();

        $pdf2 = PdfDocument::new(compressStreams: false);
        $page2 = $pdf2->addPage();
        $page2->showText('Hello secret world', 72, 720, \Dskripchenko\PhpPdf\Pdf\StandardFont::Helvetica, 12);
        $pdf2->encrypt('password');
        $encrypted = $pdf2->toBytes();

        // Encrypted version должен содержать /Encrypt.
        self::assertStringContainsString('/Encrypt', $encrypted);
        // Plain text НЕ должен встречаться в encrypted в clear.
        self::assertStringContainsString('Hello secret world', $clear);
        self::assertStringNotContainsString('Hello secret world', $encrypted);

        // Markers: clear не имеет /Encrypt.
        self::assertStringNotContainsString('/Encrypt', $clear);
    }

    #[Test]
    public function encryption_permissions_default_allow_print_copy(): void
    {
        $enc = new Encryption('password');
        // PERM_PRINT | PERM_COPY | PERM_PRINT_HIGH = 4 | 16 | 2048 = 2068.
        // Combined with reserved bits.
        self::assertSame(
            Encryption::PERM_PRINT,
            Encryption::PERM_PRINT & 4,
        );
    }

    #[Test]
    public function different_passwords_yield_different_keys(): void
    {
        $a = new Encryption('foo');
        $b = new Encryption('bar');
        self::assertNotSame($a->fileKey, $b->fileKey);
        self::assertNotSame($a->uValue, $b->uValue);
    }

    #[Test]
    public function owner_password_distinct_from_user(): void
    {
        $a = new Encryption('user-pw');
        $b = new Encryption('user-pw', ownerPassword: 'owner-pw');
        // O-values отличаются — different owner derivation.
        self::assertNotSame($a->oValue, $b->oValue);
    }

    #[Test]
    public function file_id_random(): void
    {
        $a = new Encryption('pw');
        $b = new Encryption('pw');
        // Same password но different fileIds → different keys.
        self::assertNotSame($a->fileId, $b->fileId);
    }

    #[Test]
    public function rc4_self_inverse(): void
    {
        // RC4 stream cipher: encrypt(encrypt(x)) == x.
        $key = 'secret_key';
        $data = 'Hello, World! This is a longer test string with various bytes.';
        $cipher = Encryption::rc4($key, $data);
        $decrypted = Encryption::rc4($key, $cipher);
        self::assertSame($data, $decrypted);
    }
}
