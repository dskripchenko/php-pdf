<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Font;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;

/**
 * Композит из нескольких FontProvider'ов — пробует каждый по порядку,
 * возвращает первый non-null result.
 *
 * Используется когда у caller'а несколько источников шрифтов:
 *  - Bundled (LiberationFontProvider из php-pdf-fonts-liberation)
 *  - User-provided (DirectoryFontProvider с /var/fonts)
 *  - Fallback на dummy/null
 *
 * Provider'ы хранятся в insertion order — earliest wins.
 */
final class ChainedFontProvider implements FontProvider
{
    /** @var list<FontProvider> */
    private array $providers;

    public function __construct(FontProvider ...$providers)
    {
        $this->providers = array_values($providers);
    }

    /**
     * Прокидывает provider в конец цепочки (lowest priority).
     */
    public function append(FontProvider $provider): self
    {
        $this->providers[] = $provider;

        return $this;
    }

    /**
     * Добавляет provider в начало (highest priority).
     */
    public function prepend(FontProvider $provider): self
    {
        array_unshift($this->providers, $provider);

        return $this;
    }

    public function resolve(string $fontName): ?TtfFile
    {
        foreach ($this->providers as $provider) {
            $ttf = $provider->resolve($fontName);
            if ($ttf !== null) {
                return $ttf;
            }
        }

        return null;
    }

    /**
     * @return list<FontProvider>
     */
    public function providers(): array
    {
        return $this->providers;
    }
}
