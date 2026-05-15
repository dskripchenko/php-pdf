<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\QrEccLevel;
use Dskripchenko\PhpPdf\Barcode\QrEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 227: QR convenience factories — vCard, WiFi, URL, SMS, email, geo.
 */
final class QrConvenienceFactoriesTest extends TestCase
{
    // ---- vCard ----

    #[Test]
    public function vcard_minimal(): void
    {
        $qr = QrEncoder::vCard(['name' => 'John Doe']);
        self::assertStringContainsString('BEGIN:VCARD', $qr->data);
        self::assertStringContainsString('VERSION:3.0', $qr->data);
        self::assertStringContainsString('FN:John Doe', $qr->data);
        self::assertStringContainsString('END:VCARD', $qr->data);
    }

    #[Test]
    public function vcard_full_fields(): void
    {
        $qr = QrEncoder::vCard([
            'name' => 'Jane Smith',
            'org' => 'Acme Corp',
            'title' => 'CEO',
            'tel' => '+1-555-1234',
            'email' => 'jane@acme.com',
            'url' => 'https://acme.com',
        ]);
        self::assertStringContainsString('FN:Jane Smith', $qr->data);
        self::assertStringContainsString('ORG:Acme Corp', $qr->data);
        self::assertStringContainsString('TITLE:CEO', $qr->data);
        self::assertStringContainsString('TEL:+1-555-1234', $qr->data);
        self::assertStringContainsString('EMAIL:jane@acme.com', $qr->data);
        self::assertStringContainsString('URL:https://acme.com', $qr->data);
    }

    #[Test]
    public function vcard_escapes_special_chars(): void
    {
        // vCard 3.0 spec: escape commas, semicolons, backslashes (но не spaces).
        $qr = QrEncoder::vCard(['name' => 'Smith, John; CEO']);
        self::assertStringContainsString('FN:Smith\, John\; CEO', $qr->data);
    }

    #[Test]
    public function vcard_skips_empty_fields(): void
    {
        $qr = QrEncoder::vCard(['name' => 'Test', 'email' => '']);
        self::assertStringContainsString('FN:Test', $qr->data);
        self::assertStringNotContainsString('EMAIL:', $qr->data);
    }

    // ---- WiFi ----

    #[Test]
    public function wifi_basic_wpa(): void
    {
        $qr = QrEncoder::wifi('MyNetwork', 'secret123');
        self::assertStringContainsString('WIFI:T:WPA', $qr->data);
        self::assertStringContainsString('S:MyNetwork', $qr->data);
        self::assertStringContainsString('P:secret123', $qr->data);
    }

    #[Test]
    public function wifi_open_network(): void
    {
        $qr = QrEncoder::wifi('Guest', auth: 'nopass');
        self::assertStringContainsString('T:nopass', $qr->data);
    }

    #[Test]
    public function wifi_hidden_flag(): void
    {
        $qr = QrEncoder::wifi('Hidden', 'pass', hidden: true);
        self::assertStringContainsString('H:true', $qr->data);
    }

    #[Test]
    public function wifi_escapes_special_chars(): void
    {
        $qr = QrEncoder::wifi('Net;Name', 'p:ass\\word');
        self::assertStringContainsString('S:Net\\;Name', $qr->data);
        self::assertStringContainsString('P:p\\:ass\\\\word', $qr->data);
    }

    #[Test]
    public function wifi_rejects_invalid_auth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrEncoder::wifi('Net', 'pass', auth: 'WPA-PSK-256');
    }

    // ---- URL ----

    #[Test]
    public function url_factory_works_as_constructor(): void
    {
        $qr = QrEncoder::url('https://example.com');
        self::assertSame('https://example.com', $qr->data);
    }

    // ---- SMS ----

    #[Test]
    public function sms_with_message(): void
    {
        $qr = QrEncoder::sms('+15551234', 'Hello world');
        self::assertSame('SMSTO:+15551234:Hello world', $qr->data);
    }

    #[Test]
    public function sms_phone_only(): void
    {
        $qr = QrEncoder::sms('+15551234');
        self::assertSame('SMSTO:+15551234', $qr->data);
    }

    // ---- Email ----

    #[Test]
    public function email_address_only(): void
    {
        $qr = QrEncoder::email('test@example.com');
        self::assertSame('mailto:test@example.com', $qr->data);
    }

    #[Test]
    public function email_with_subject(): void
    {
        $qr = QrEncoder::email('a@b.com', subject: 'Hello');
        self::assertStringContainsString('mailto:a@b.com', $qr->data);
        self::assertStringContainsString('subject=Hello', $qr->data);
    }

    #[Test]
    public function email_with_subject_and_body(): void
    {
        $qr = QrEncoder::email('a@b.com', subject: 'Hi', body: 'Greetings');
        self::assertStringContainsString('subject=Hi', $qr->data);
        self::assertStringContainsString('body=Greetings', $qr->data);
    }

    // ---- Geo ----

    #[Test]
    public function geo_coordinates(): void
    {
        $qr = QrEncoder::geo(55.7558, 37.6173);
        self::assertSame('geo:55.7558,37.6173', $qr->data);
    }

    #[Test]
    public function geo_with_zoom(): void
    {
        $qr = QrEncoder::geo(55.75, 37.61, zoom: 15);
        self::assertSame('geo:55.75,37.61?z=15', $qr->data);
    }

    // ---- ECC level passthrough ----

    #[Test]
    public function ecc_level_passed_к_constructor(): void
    {
        $qr = QrEncoder::vCard(['name' => 'Test'], QrEccLevel::H);
        self::assertSame(QrEccLevel::H, $qr->eccLevel);
    }
}
