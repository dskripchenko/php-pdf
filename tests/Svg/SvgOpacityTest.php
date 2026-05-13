<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SvgOpacityTest extends TestCase
{
    #[Test]
    public function fill_opacity_emits_extgstate(): void
    {
        $svg = '<svg width="100" height="100"><rect x="0" y="0" width="50" height="50" fill="#f00" fill-opacity="0.5"/></svg>';
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/Type /ExtGState', $bytes);
        self::assertStringContainsString('/ca 0.5', $bytes);
    }

    #[Test]
    public function stroke_opacity_emits_extgstate(): void
    {
        $svg = '<svg width="100" height="100"><rect x="0" y="0" width="50" height="50" fill="none" stroke="#000" stroke-opacity="0.3"/></svg>';
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/CA 0.3', $bytes);
    }

    #[Test]
    public function global_opacity_applies_to_both_fill_stroke(): void
    {
        $svg = '<svg width="100" height="100"><rect x="0" y="0" width="50" height="50" fill="#f00" stroke="#000" opacity="0.4"/></svg>';
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/ca 0.4', $bytes);
        self::assertStringContainsString('/CA 0.4', $bytes);
    }

    #[Test]
    public function opacity_multiplied_by_fill_opacity(): void
    {
        // opacity="0.5" + fill-opacity="0.5" → effective 0.25.
        $svg = '<svg width="100" height="100"><rect x="0" y="0" width="50" height="50" fill="#f00" opacity="0.5" fill-opacity="0.5"/></svg>';
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/ca 0.25', $bytes);
    }

    #[Test]
    public function fully_opaque_no_extgstate(): void
    {
        $svg = '<svg width="100" height="100"><rect x="0" y="0" width="50" height="50" fill="#f00" fill-opacity="1.0"/></svg>';
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/Type /ExtGState', $bytes);
    }

    #[Test]
    public function circle_opacity_applies(): void
    {
        $svg = '<svg width="100" height="100"><circle cx="50" cy="50" r="20" fill="#0f0" fill-opacity="0.6"/></svg>';
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/ca 0.6', $bytes);
    }

    #[Test]
    public function polygon_opacity_applies(): void
    {
        $svg = '<svg width="100" height="100"><polygon points="10,10 90,10 50,90" fill="#00f" fill-opacity="0.7"/></svg>';
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('/ca 0.7', $bytes);
    }

    #[Test]
    public function out_of_range_opacity_clamped(): void
    {
        // opacity > 1 clamped к 1 (no /ca emitted).
        $svg = '<svg width="100" height="100"><rect x="0" y="0" width="50" height="50" fill="#f00" fill-opacity="1.5"/></svg>';
        $bytes = (new Document(new Section([new SvgElement($svg)])))
            ->toBytes(new Engine(compressStreams: false));

        self::assertStringNotContainsString('/Type /ExtGState', $bytes);
    }
}
