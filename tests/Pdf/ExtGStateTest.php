<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\PdfExtGState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExtGStateTest extends TestCase
{
    #[Test]
    public function dict_body_contains_type_and_fill_opacity(): void
    {
        $gs = new PdfExtGState(fillOpacity: 0.3);
        $body = $gs->toDictBody();

        self::assertStringContainsString('/Type /ExtGState', $body);
        self::assertStringContainsString('/ca 0.3', $body);
        self::assertStringNotContainsString('/CA', $body);
    }

    #[Test]
    public function dict_body_supports_stroke_opacity(): void
    {
        $gs = new PdfExtGState(strokeOpacity: 0.5);
        $body = $gs->toDictBody();

        self::assertStringContainsString('/CA 0.5', $body);
        self::assertStringNotContainsString('/ca ', $body);
    }

    #[Test]
    public function dict_body_supports_both_opacities(): void
    {
        $gs = new PdfExtGState(fillOpacity: 0.4, strokeOpacity: 0.6);
        $body = $gs->toDictBody();

        self::assertStringContainsString('/ca 0.4', $body);
        self::assertStringContainsString('/CA 0.6', $body);
    }

    #[Test]
    public function key_disambiguates_distinct_states(): void
    {
        $a = new PdfExtGState(fillOpacity: 0.3);
        $b = new PdfExtGState(fillOpacity: 0.5);
        $c = new PdfExtGState(fillOpacity: 0.3);

        self::assertNotSame($a->key(), $b->key());
        self::assertSame($a->key(), $c->key());
    }

    #[Test]
    public function integer_opacity_formatted_without_decimal(): void
    {
        $gs = new PdfExtGState(fillOpacity: 1.0);
        $body = $gs->toDictBody();

        self::assertStringContainsString('/ca 1', $body);
        self::assertStringNotContainsString('/ca 1.', $body);
    }
}
