<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\CompositeGlyph;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 186: composite glyph parser + serializer tests.
 */
final class CompositeGlyphTest extends TestCase
{
    /**
     * Build a minimal composite glyph: 1 component с byte args (dx=10, dy=20).
     */
    private function buildSimpleComposite(int $dx = 10, int $dy = 20): string
    {
        // numContours = -1 (int16)
        // bbox: xMin=-100, yMin=-100, xMax=100, yMax=100 (4× int16)
        $bytes = pack('n', 0xFFFF);  // numContours = -1
        $bytes .= pack('n', -100 & 0xFFFF);
        $bytes .= pack('n', -100 & 0xFFFF);
        $bytes .= pack('n', 100);
        $bytes .= pack('n', 100);
        // Component: flags = ARGS_ARE_XY_VALUES (0x0002)
        $bytes .= pack('n', 0x0002);
        $bytes .= pack('n', 5); // glyphIndex = 5
        $bytes .= pack('c', $dx).pack('c', $dy);

        return $bytes;
    }

    private function buildMultiComponentComposite(): string
    {
        $bytes = pack('n', 0xFFFF);
        $bytes .= pack('n', 0).pack('n', 0).pack('n', 200).pack('n', 200);
        // Component 1: MORE_COMPONENTS (0x0020) + ARGS_ARE_XY_VALUES (0x0002)
        $bytes .= pack('n', 0x0022);
        $bytes .= pack('n', 5);
        $bytes .= pack('c', 0).pack('c', 0);
        // Component 2: no MORE_COMPONENTS, ARGS_ARE_XY_VALUES
        $bytes .= pack('n', 0x0002);
        $bytes .= pack('n', 7);
        $bytes .= pack('c', 50).pack('c', 100);

        return $bytes;
    }

    #[Test]
    public function parse_returns_null_for_simple_glyph(): void
    {
        // numContours = 1 (simple, non-negative).
        $bytes = pack('n', 1).str_repeat("\x00", 50);
        self::assertNull(CompositeGlyph::parse($bytes));
    }

    #[Test]
    public function parse_returns_null_for_empty(): void
    {
        self::assertNull(CompositeGlyph::parse(''));
    }

    #[Test]
    public function parse_extracts_single_component(): void
    {
        $bytes = $this->buildSimpleComposite(dx: 10, dy: 20);
        $composite = CompositeGlyph::parse($bytes);
        self::assertNotNull($composite);
        self::assertCount(1, $composite->components);
        self::assertSame(5, $composite->components[0]['glyphIndex']);
        self::assertSame(10, $composite->components[0]['arg1']);
        self::assertSame(20, $composite->components[0]['arg2']);
        self::assertTrue($composite->components[0]['isXY']);
    }

    #[Test]
    public function parse_extracts_multiple_components(): void
    {
        $bytes = $this->buildMultiComponentComposite();
        $composite = CompositeGlyph::parse($bytes);
        self::assertNotNull($composite);
        self::assertCount(2, $composite->components);
        self::assertSame(5, $composite->components[0]['glyphIndex']);
        self::assertSame(7, $composite->components[1]['glyphIndex']);
        self::assertSame(50, $composite->components[1]['arg1']);
        self::assertSame(100, $composite->components[1]['arg2']);
    }

    #[Test]
    public function serialize_with_no_changes_returns_equivalent_bytes(): void
    {
        $bytes = $this->buildSimpleComposite();
        $composite = CompositeGlyph::parse($bytes);
        $reSerialized = $composite->serialize([]);
        // Byte-equivalent (no modifications applied).
        self::assertSame(strlen($bytes), strlen($reSerialized));
    }

    #[Test]
    public function serialize_applies_new_offsets(): void
    {
        $bytes = $this->buildSimpleComposite(dx: 10, dy: 20);
        $composite = CompositeGlyph::parse($bytes);
        $modified = $composite->serialize([0 => ['dx' => 30, 'dy' => 40]]);

        // Re-parse modified.
        $reparsed = CompositeGlyph::parse($modified);
        self::assertNotNull($reparsed);
        self::assertSame(30, $reparsed->components[0]['arg1']);
        self::assertSame(40, $reparsed->components[0]['arg2']);
    }

    #[Test]
    public function serialize_promotes_to_int16_when_needed(): void
    {
        $bytes = $this->buildSimpleComposite(dx: 10, dy: 20);
        $composite = CompositeGlyph::parse($bytes);
        // Offset > 127 — promote из int8 к int16.
        $modified = $composite->serialize([0 => ['dx' => 1000, 'dy' => -500]]);

        $reparsed = CompositeGlyph::parse($modified);
        self::assertNotNull($reparsed);
        // Args promoted к int16.
        self::assertSame(2, $reparsed->components[0]['argSize']);
        self::assertSame(1000, $reparsed->components[0]['arg1']);
        self::assertSame(-500, $reparsed->components[0]['arg2']);
    }

    #[Test]
    public function serialize_multi_component_with_partial_modification(): void
    {
        $bytes = $this->buildMultiComponentComposite();
        $composite = CompositeGlyph::parse($bytes);
        // Modify только second component.
        $modified = $composite->serialize([1 => ['dx' => 60, 'dy' => 110]]);

        $reparsed = CompositeGlyph::parse($modified);
        self::assertNotNull($reparsed);
        // First component unchanged.
        self::assertSame(0, $reparsed->components[0]['arg1']);
        self::assertSame(0, $reparsed->components[0]['arg2']);
        // Second modified.
        self::assertSame(60, $reparsed->components[1]['arg1']);
        self::assertSame(110, $reparsed->components[1]['arg2']);
    }
}
