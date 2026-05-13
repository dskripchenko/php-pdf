<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Font;

use Dskripchenko\PhpPdf\Pdf\PdfFont;

/**
 * Wraps {@see FontProvider} (которое resolve'ит по PostScript-имени)
 * и предоставляет API «family + bold + italic → PdfFont» с naming
 * convention fallback chain'ом.
 *
 * Convention: для family «LiberationSans» variant'ы ищутся как
 *   LiberationSans-BoldItalic
 *   LiberationSans-Bold
 *   LiberationSans-Italic
 *   LiberationSans-Regular
 *   LiberationSans
 *
 * Fallback chain (если конкретный variant не найден):
 *   BoldItalic → Bold → Italic → Regular
 *
 * Кеширует resolved PdfFont instances по (family, bold, italic).
 */
final class PdfFontResolver
{
    /** @var array<string, ?PdfFont>  cache key = family|bold|italic */
    private array $cache = [];

    public function __construct(
        private readonly FontProvider $provider,
    ) {}

    public function resolve(string $family, bool $bold = false, bool $italic = false): ?PdfFont
    {
        $key = $family.'|'.($bold ? '1' : '0').'|'.($italic ? '1' : '0');
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        // Try the most specific variant first, then degrade.
        $candidates = $this->variantCandidates($family, $bold, $italic);
        foreach ($candidates as $name) {
            $ttf = $this->provider->resolve($name);
            if ($ttf !== null) {
                return $this->cache[$key] = new PdfFont($ttf);
            }
        }

        return $this->cache[$key] = null;
    }

    /**
     * @return list<string>  Список наименований для последовательной try-resolve.
     */
    private function variantCandidates(string $family, bool $bold, bool $italic): array
    {
        $variants = [];
        if ($bold && $italic) {
            $variants[] = $family.'-BoldItalic';
        }
        if ($bold) {
            $variants[] = $family.'-Bold';
        }
        if ($italic) {
            $variants[] = $family.'-Italic';
        }
        $variants[] = $family.'-Regular';
        $variants[] = $family;

        return array_values(array_unique($variants));
    }
}
