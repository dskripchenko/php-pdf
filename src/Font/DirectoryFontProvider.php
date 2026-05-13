<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Font;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;

/**
 * FontProvider, который сканирует директорию для TTF файлов и резолвит
 * по PostScript-имени (из TTF name table).
 *
 * Lazy: TTF не парсится при construct'е — только когда первый resolve()
 * вызовется. Cached после первого парсинга.
 *
 * Usage:
 *   $provider = new DirectoryFontProvider('/path/to/fonts');
 *   $ttf = $provider->resolve('MyCustomFont-Regular');
 *
 * Recursive search опционально (default off).
 */
final class DirectoryFontProvider implements FontProvider
{
    /** @var array<string, string>|null  postscriptName → absolute path; lazily populated */
    private ?array $indexed = null;

    /** @var array<string, TtfFile>  cache parsed TtfFile instances */
    private array $cache = [];

    public function __construct(
        private readonly string $directoryPath,
        private readonly bool $recursive = false,
    ) {}

    public function resolve(string $fontName): ?TtfFile
    {
        if (isset($this->cache[$fontName])) {
            return $this->cache[$fontName];
        }
        $this->ensureIndexed();
        if (! isset($this->indexed[$fontName])) {
            return null;
        }

        return $this->cache[$fontName] = TtfFile::fromFile($this->indexed[$fontName]);
    }

    /**
     * Path к найденному TTF без парсинга (для debug / introspection).
     */
    public function pathFor(string $fontName): ?string
    {
        $this->ensureIndexed();

        return $this->indexed[$fontName] ?? null;
    }

    /**
     * Все известные шрифты в директории (после index'ирования).
     *
     * @return array<string, string>  postscriptName → path
     */
    public function knownFonts(): array
    {
        $this->ensureIndexed();

        return $this->indexed;
    }

    private function ensureIndexed(): void
    {
        if ($this->indexed !== null) {
            return;
        }
        $this->indexed = [];
        if (! is_dir($this->directoryPath)) {
            return;
        }
        $this->scanDirectory($this->directoryPath);
    }

    private function scanDirectory(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                if ($this->recursive) {
                    $this->scanDirectory($path);
                }

                continue;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext !== 'ttf' && $ext !== 'otf') {
                continue;
            }
            // Parse name из TTF.
            try {
                $ttf = TtfFile::fromFile($path);
                $name = $ttf->postScriptName();
                $this->indexed[$name] = $path;
                // Caching: индекс знает path, не TtfFile. Это позволяет
                // освобождать memory если TtfFile.bytes не нужен сразу.
                // resolve() параметрически load'ит TtfFile впервые.
            } catch (\Throwable) {
                // Skip unreadable / corrupt files.
            }
        }
    }
}
