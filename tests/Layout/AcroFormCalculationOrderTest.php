<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\FormField;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcroFormCalculationOrderTest extends TestCase
{
    #[Test]
    public function single_calculate_field_emits_co_array(): void
    {
        $doc = new Document(new Section([
            new FormField('total', FormField::TYPE_TEXT,
                calculateScript: 'event.value = 1+1;'),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertMatchesRegularExpression('@/CO \[\d+\s+0\s+R\]@', $bytes);
    }

    #[Test]
    public function multiple_calculated_fields_in_order(): void
    {
        $doc = new Document(new Section([
            new FormField('a', FormField::TYPE_TEXT, calculateScript: '...'),
            new FormField('b', FormField::TYPE_TEXT, calculateScript: '...'),
            new FormField('c', FormField::TYPE_TEXT, calculateScript: '...'),
            new FormField('plain', FormField::TYPE_TEXT), // no calc.
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // /CO array should contain 3 refs (a, b, c — not plain).
        preg_match('@/CO \[([^\]]+)\]@', $bytes, $m);
        self::assertNotEmpty($m);
        $refs = preg_match_all('@\d+\s+0\s+R@', $m[1]);
        self::assertSame(3, $refs);
    }

    #[Test]
    public function no_calculate_fields_no_co_entry(): void
    {
        $doc = new Document(new Section([
            new FormField('plain', FormField::TYPE_TEXT),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/CO', $bytes);
    }

    #[Test]
    public function only_validate_no_calc_no_co(): void
    {
        $doc = new Document(new Section([
            new FormField('email', FormField::TYPE_TEXT,
                validateScript: 'event.rc = true;'),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // /AA emitted, no /CO.
        self::assertStringContainsString('/AA', $bytes);
        self::assertStringNotContainsString('/CO', $bytes);
    }
}
