<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font;

use Dskripchenko\PhpPdf\Font\DirectoryFontProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DirectoryFontProviderTest extends TestCase
{
    private string $fontsDir;

    protected function setUp(): void
    {
        $this->fontsDir = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5';
        if (! is_dir($this->fontsDir)) {
            self::markTestSkipped('Liberation fonts directory not cached.');
        }
    }

    #[Test]
    public function indexes_ttf_files_by_postscript_name(): void
    {
        $provider = new DirectoryFontProvider($this->fontsDir);
        $known = $provider->knownFonts();
        // Должно быть 12 шрифтов (Sans/Serif/Mono × Regular/Bold/Italic/BoldItalic).
        self::assertCount(12, $known);
        self::assertArrayHasKey('LiberationSans', $known);
        self::assertArrayHasKey('LiberationSerif-Bold', $known);
        self::assertArrayHasKey('LiberationMono-Italic', $known);
    }

    #[Test]
    public function resolve_returns_ttf_file(): void
    {
        $provider = new DirectoryFontProvider($this->fontsDir);
        $ttf = $provider->resolve('LiberationSans');
        self::assertNotNull($ttf);
        self::assertSame('LiberationSans', $ttf->postScriptName());
    }

    #[Test]
    public function unknown_font_returns_null(): void
    {
        $provider = new DirectoryFontProvider($this->fontsDir);
        self::assertNull($provider->resolve('NonExistentFont'));
    }

    #[Test]
    public function path_for_returns_absolute_path(): void
    {
        $provider = new DirectoryFontProvider($this->fontsDir);
        $path = $provider->pathFor('LiberationSans');
        self::assertNotNull($path);
        self::assertStringEndsWith('LiberationSans-Regular.ttf', $path);
    }

    #[Test]
    public function nonexistent_directory_yields_empty_index(): void
    {
        $provider = new DirectoryFontProvider('/nonexistent/path');
        self::assertSame([], $provider->knownFonts());
        self::assertNull($provider->resolve('Anything'));
    }

    #[Test]
    public function resolve_caches_ttf_instance(): void
    {
        $provider = new DirectoryFontProvider($this->fontsDir);
        $first = $provider->resolve('LiberationSans');
        $second = $provider->resolve('LiberationSans');
        // Идемпотентность: same TtfFile instance возвращается.
        self::assertSame($first, $second);
    }
}
