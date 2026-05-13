<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\DirectoryFontProvider;
use Dskripchenko\PhpPdf\Font\FontProvider;
use Dskripchenko\PhpPdf\Font\PdfFontResolver;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\RunStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FontProviderTest extends TestCase
{
    private function fontsDir(): string
    {
        return __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5';
    }

    private function provider(): FontProvider
    {
        if (! is_dir($this->fontsDir())) {
            self::markTestSkipped('Liberation fonts not cached.');
        }

        return new DirectoryFontProvider($this->fontsDir());
    }

    #[Test]
    public function resolver_returns_regular_variant_when_no_marks(): void
    {
        $resolver = new PdfFontResolver($this->provider());
        $font = $resolver->resolve('LiberationSans');
        self::assertInstanceOf(PdfFont::class, $font);
    }

    #[Test]
    public function resolver_returns_bold_variant(): void
    {
        $resolver = new PdfFontResolver($this->provider());
        $bold = $resolver->resolve('LiberationSans', bold: true);
        $regular = $resolver->resolve('LiberationSans');
        self::assertNotNull($bold);
        self::assertNotNull($regular);
        // Different fonts because different TTF subsets.
        self::assertNotSame($bold, $regular);
    }

    #[Test]
    public function resolver_returns_italic_variant(): void
    {
        $resolver = new PdfFontResolver($this->provider());
        $italic = $resolver->resolve('LiberationSans', italic: true);
        self::assertNotNull($italic);
    }

    #[Test]
    public function resolver_returns_bold_italic_variant(): void
    {
        $resolver = new PdfFontResolver($this->provider());
        $bi = $resolver->resolve('LiberationSans', bold: true, italic: true);
        self::assertNotNull($bi);
    }

    #[Test]
    public function resolver_falls_back_to_regular_when_variant_missing(): void
    {
        // Mock a provider that knows only Regular.
        $only = new class implements FontProvider
        {
            public function resolve(string $fontName): ?TtfFile
            {
                if ($fontName === 'OnlyRegular-Regular' || $fontName === 'OnlyRegular') {
                    // Use Liberation Regular as the underlying TTF for test data.
                    $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';

                    return is_readable($path) ? TtfFile::fromFile($path) : null;
                }

                return null;
            }
        };
        $resolver = new PdfFontResolver($only);
        $bold = $resolver->resolve('OnlyRegular', bold: true);
        self::assertNotNull($bold, 'Should fallback to Regular');
    }

    #[Test]
    public function resolver_returns_null_for_unknown_family(): void
    {
        $resolver = new PdfFontResolver($this->provider());
        self::assertNull($resolver->resolve('NonExistentFont12345'));
    }

    #[Test]
    public function resolver_caches_results(): void
    {
        $resolver = new PdfFontResolver($this->provider());
        $f1 = $resolver->resolve('LiberationSans', bold: true);
        $f2 = $resolver->resolve('LiberationSans', bold: true);
        self::assertSame($f1, $f2, 'Should return same instance on repeat call');
    }

    #[Test]
    public function engine_uses_provider_for_run_style_font_family(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Run('Sans serif', (new RunStyle)->withFontFamily('LiberationSans')),
                new Run(' Serif', (new RunStyle)->withFontFamily('LiberationSerif')),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(fontProvider: $this->provider()));

        // Two distinct Type0 subsets embedded — Sans + Serif.
        $count = substr_count($bytes, '/Subtype /Type0');
        self::assertGreaterThanOrEqual(2, $count,
            'Both Sans and Serif families should be embedded as separate fonts');
    }

    #[Test]
    public function engine_routes_bold_italic_through_provider(): void
    {
        $doc = new Document(new Section([
            new Paragraph([
                new Run('regular ', (new RunStyle)->withFontFamily('LiberationSans')),
                new Run('bold ', (new RunStyle)->withFontFamily('LiberationSans')->withBold()),
                new Run('italic ', (new RunStyle)->withFontFamily('LiberationSans')->withItalic()),
                new Run('both', (new RunStyle)->withFontFamily('LiberationSans')->withBold()->withItalic()),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(fontProvider: $this->provider()));

        // 4 разных variant'а LiberationSans should embedded.
        $count = substr_count($bytes, '/Subtype /Type0');
        self::assertGreaterThanOrEqual(4, $count);
    }

    #[Test]
    public function engine_falls_back_to_default_when_family_unknown(): void
    {
        // Provider returns null → engine использует defaultFont.
        $regular = new PdfFont(TtfFile::fromFile(
            $this->fontsDir().'/LiberationSans-Regular.ttf'
        ));
        $doc = new Document(new Section([
            new Paragraph([
                new Run('unknown family', (new RunStyle)->withFontFamily('NoSuchFont')),
            ]),
        ]));
        $bytes = $doc->toBytes(new Engine(
            defaultFont: $regular,
            fontProvider: $this->provider(),
        ));

        // Only 1 Type0 — fallback to defaultFont.
        self::assertSame(1, substr_count($bytes, '/Subtype /Type0'));
    }
}
