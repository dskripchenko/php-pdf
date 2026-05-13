<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font;

use Dskripchenko\PhpPdf\Font\ChainedFontProvider;
use Dskripchenko\PhpPdf\Font\FontProvider;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChainedFontProviderTest extends TestCase
{
    #[Test]
    public function empty_chain_returns_null(): void
    {
        $chain = new ChainedFontProvider;
        self::assertNull($chain->resolve('AnyFont'));
    }

    #[Test]
    public function first_provider_wins(): void
    {
        $ttf1 = $this->stubTtf('A');
        $ttf2 = $this->stubTtf('B');

        $a = $this->staticProvider(['Roboto' => $ttf1]);
        $b = $this->staticProvider(['Roboto' => $ttf2]);

        $chain = new ChainedFontProvider($a, $b);
        self::assertSame($ttf1, $chain->resolve('Roboto'));
    }

    #[Test]
    public function fallback_when_first_returns_null(): void
    {
        $ttf = $this->stubTtf('A');
        $a = $this->staticProvider([]); // empty
        $b = $this->staticProvider(['Roboto' => $ttf]);

        $chain = new ChainedFontProvider($a, $b);
        self::assertSame($ttf, $chain->resolve('Roboto'));
    }

    #[Test]
    public function none_resolves_returns_null(): void
    {
        $a = $this->staticProvider([]);
        $b = $this->staticProvider([]);

        $chain = new ChainedFontProvider($a, $b);
        self::assertNull($chain->resolve('Unknown'));
    }

    #[Test]
    public function append_adds_to_lowest_priority(): void
    {
        $ttf1 = $this->stubTtf('A');
        $ttf2 = $this->stubTtf('B');

        $chain = new ChainedFontProvider($this->staticProvider(['Roboto' => $ttf1]));
        $chain->append($this->staticProvider(['Roboto' => $ttf2]));

        // First provider wins.
        self::assertSame($ttf1, $chain->resolve('Roboto'));
    }

    #[Test]
    public function prepend_adds_to_highest_priority(): void
    {
        $ttf1 = $this->stubTtf('A');
        $ttf2 = $this->stubTtf('B');

        $chain = new ChainedFontProvider($this->staticProvider(['Roboto' => $ttf1]));
        $chain->prepend($this->staticProvider(['Roboto' => $ttf2]));

        // Prepended wins.
        self::assertSame($ttf2, $chain->resolve('Roboto'));
    }

    /**
     * @param  array<string, TtfFile>  $map
     */
    private function staticProvider(array $map): FontProvider
    {
        return new class($map) implements FontProvider
        {
            /** @param array<string, TtfFile> $map */
            public function __construct(private readonly array $map) {}

            public function resolve(string $fontName): ?TtfFile
            {
                return $this->map[$fontName] ?? null;
            }
        };
    }

    private function stubTtf(string $tag): TtfFile
    {
        // Use real Liberation TTF — заглушку парсить лень.
        // Но мы переиспользуем разные FILES.
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/Liberation'
            .match ($tag) {
                'A' => 'Sans-Regular.ttf',
                'B' => 'Serif-Regular.ttf',
                default => 'Sans-Regular.ttf',
            };
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation fonts not cached.');
        }

        return TtfFile::fromFile($path);
    }
}
