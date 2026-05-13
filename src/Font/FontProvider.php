<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Font;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;

/**
 * Abstraction для font-resolution: caller просит шрифт по имени, реализация
 * возвращает TtfFile (или null если не знает этого имени).
 *
 * Использование:
 *
 *   $provider = new ChainedFontProvider(
 *       new LiberationFontProvider(),     // из php-pdf-fonts-liberation
 *       new CustomFontProvider('/path/to/my/fonts'),
 *   );
 *
 *   $ttf = $provider->resolve('Arial');
 *   // → LiberationSans (metric-compatible aliasing в LiberationFontProvider)
 *
 *   $ttf = $provider->resolve('Helvetica');
 *   // → null (Helvetica = PDF base-14, embedding не нужен)
 *
 * Phase 2 API minimum:
 *   - resolve(name): ?TtfFile
 *
 * Future expansions (Phase 5+):
 *   - resolveBold/Italic variants
 *   - FontProvider implementations для CSS-family stacks
 *     ('Arial, Helvetica, sans-serif')
 *   - FontProvider'ы для system-installed font discovery
 */
interface FontProvider
{
    /**
     * Резолвит font name → TtfFile или null если provider не знает имя.
     *
     * Имена case-sensitive — provider'ы могут нормализовать сами.
     * PostScript names — наиболее устойчивый вариант (LiberationSans
     * vs «Liberation Sans» или «liberation-sans»).
     */
    public function resolve(string $fontName): ?TtfFile;
}
