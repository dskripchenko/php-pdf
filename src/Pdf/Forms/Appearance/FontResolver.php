<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms\Appearance;

use Dskripchenko\PhpPdf\Font\FontProvider;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\Forms\FormGraph;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;

/**
 * Picks the font a field value is drawn with, in order of preference:
 *
 *  1. the `/DA` font from the form's `/DR`, when it is a fully embedded (or
 *     base-14) WinAnsi font with known widths that can show the text;
 *  2. the base-14 font closest to it (Helvetica / Times / Courier), when the
 *     text is WinAnsi — e.g. the DR font is a subset, or `/DR` is missing;
 *  3. an embedded TrueType font (from {@see useFont()} or a font provider),
 *     for anything else — Cyrillic, CJK, …
 *
 * @internal
 */
final class FontResolver
{
    private const ALIASES = [
        'Helv' => 'Helvetica', 'HeBo' => 'Helvetica-Bold', 'HeOb' => 'Helvetica-Oblique',
        'HeBO' => 'Helvetica-BoldOblique', 'TiRo' => 'Times-Roman', 'TiBo' => 'Times-Bold',
        'TiIt' => 'Times-Italic', 'TiBI' => 'Times-BoldItalic', 'Cour' => 'Courier',
        'CoBo' => 'Courier-Bold', 'CoOb' => 'Courier-Oblique', 'CoBO' => 'Courier-BoldOblique',
    ];

    /** @var array<string,SimpleFont> */
    private array $standard = [];

    /** @var array<string,UnicodeFont> */
    private array $unicode = [];

    public function __construct(
        private readonly FormGraph $graph,
        private readonly ?TtfFile $font = null,
        private readonly ?FontProvider $provider = null,
    ) {
    }

    public function resolve(?string $daFontName, ?PdfDictionary $dr, string $text, string $fieldName): AppearanceFont
    {
        $fontRaw = $daFontName !== null ? $this->graph->dict($dr?->get('Font'))?->get($daFontName) : null;
        $fontDict = $this->graph->dict($fontRaw);
        $baseFont = $fontDict?->get('BaseFont') instanceof PdfName ? $fontDict->get('BaseFont')->value : null;
        $style = $this->style($baseFont ?? ($daFontName !== null ? (self::ALIASES[$daFontName] ?? $daFontName) : 'Helvetica'));

        if ($fontDict !== null) {
            $own = $this->simpleFromDict($fontRaw, $fontDict, $baseFont);
            if ($own !== null && $own->canShow($text)) {
                return $own;
            }
        }

        $standard = $this->standardFont($style);
        if ($standard->canShow($text)) {
            return $standard;
        }

        $unicode = $this->unicodeFont($style);
        if ($unicode === null) {
            throw new \RuntimeException(sprintf(
                'Field "%s": the value needs a Unicode font the form does not provide. '
                .'Pass one to ExistingFormFiller::useFont(), or install dskripchenko/php-pdf-fonts-liberation.',
                $fieldName,
            ));
        }
        if (!$unicode->canShow($text)) {
            throw new \RuntimeException(sprintf('Field "%s": the configured font has no glyphs for part of the value.', $fieldName));
        }

        return $unicode;
    }

    /** A ZapfDingbats font for generated check-box and radio marks. */
    public function dingbats(): PdfReference
    {
        return $this->standardFont('ZapfDingbats')->reference();
    }

    /** Embed every TrueType subset used so far. */
    public function embed(): void
    {
        foreach ($this->unicode as $font) {
            $font->embed();
        }
    }

    private function simpleFromDict(mixed $raw, PdfDictionary $dict, ?string $baseFont): ?SimpleFont
    {
        $subtype = $dict->get('Subtype') instanceof PdfName ? $dict->get('Subtype')->value : null;
        if (!in_array($subtype, ['Type1', 'TrueType', 'MMType1'], true) || $baseFont === null) {
            return null;
        }
        // A subset only carries the glyphs of the text it was made for.
        if (preg_match('/^[A-Z]{6}\+/', $baseFont) === 1) {
            return null;
        }

        $encoding = $this->graph->resolve($dict->get('Encoding'));
        if ($encoding instanceof PdfDictionary) {
            if ($encoding->has('Differences')) {
                return null;
            }
            $encoding = $encoding->get('BaseEncoding');
        }
        $encodingName = $encoding instanceof PdfName ? $encoding->value : null;
        if ($encodingName !== null && $encodingName !== 'WinAnsiEncoding') {
            return null;
        }

        $reference = $raw instanceof PdfReference ? $raw : $this->graph->allocate($dict);
        $widths = $this->graph->array($dict->get('Widths'));
        $firstChar = $this->graph->resolve($dict->get('FirstChar'));
        if ($widths !== null && is_int($firstChar)) {
            $map = [];
            foreach ($widths as $i => $w) {
                $w = $this->graph->resolve($w);
                if (is_int($w) || is_float($w)) {
                    $map[$firstChar + $i] = (float) $w;
                }
            }

            return new SimpleFont($reference, $baseFont, $map, asciiOnly: $encodingName === null);
        }
        if (StandardFontMetrics::has($baseFont)) {
            return new SimpleFont($reference, $baseFont, asciiOnly: $encodingName === null);
        }

        return null;
    }

    private function standardFont(string $baseFont): SimpleFont
    {
        if (isset($this->standard[$baseFont])) {
            return $this->standard[$baseFont];
        }
        $items = [
            'Type' => new PdfName('Font'),
            'Subtype' => new PdfName('Type1'),
            'BaseFont' => new PdfName($baseFont),
        ];
        if ($baseFont !== 'ZapfDingbats') {
            $items['Encoding'] = new PdfName('WinAnsiEncoding');
        }

        return $this->standard[$baseFont] = new SimpleFont($this->graph->allocate(new PdfDictionary($items)), $baseFont);
    }

    private function unicodeFont(string $style): ?UnicodeFont
    {
        $ttf = $this->font;
        if ($ttf === null) {
            $family = match (true) {
                str_starts_with($style, 'Times') => 'LiberationSerif',
                str_starts_with($style, 'Courier') => 'LiberationMono',
                default => 'LiberationSans',
            };
            $variant = match (true) {
                str_ends_with($style, 'BoldOblique'), str_ends_with($style, 'BoldItalic') => 'BoldItalic',
                str_ends_with($style, 'Bold') => 'Bold',
                str_ends_with($style, 'Oblique'), str_ends_with($style, 'Italic') => 'Italic',
                default => 'Regular',
            };
            $provider = $this->provider ?? $this->defaultProvider();
            $ttf = $provider?->resolve("{$family}-{$variant}") ?? $provider?->resolve("{$family}-Regular");
        }
        if ($ttf === null) {
            return null;
        }
        $key = spl_object_hash($ttf);

        return $this->unicode[$key] ??= new UnicodeFont($this->graph, $ttf);
    }

    private function defaultProvider(): ?FontProvider
    {
        $class = 'Dskripchenko\\PhpPdfFontsLiberation\\LiberationFontProvider';
        if (!class_exists($class)) {
            return null;
        }
        $provider = new $class();

        return $provider instanceof FontProvider ? $provider : null;
    }

    /** The base-14 font matching a font name's family and weight. */
    private function style(string $name): string
    {
        $name = (string) preg_replace('/^[A-Z]{6}\+/', '', $name);
        if (isset(self::ALIASES[$name])) {
            return self::ALIASES[$name];
        }
        $family = match (true) {
            preg_match('/times|serif|roman|georgia|garamond|cambria|libertine|book/i', $name) === 1
                && preg_match('/sans/i', $name) !== 1 => 'Times',
            preg_match('/courier|mono|consol/i', $name) === 1 => 'Courier',
            default => 'Helvetica',
        };
        $bold = preg_match('/bold|black|heavy|semibold|,?bd\b/i', $name) === 1;
        $italic = preg_match('/italic|oblique/i', $name) === 1;

        return match ($family) {
            'Times' => 'Times-'.match (true) {
                $bold && $italic => 'BoldItalic',
                $bold => 'Bold',
                $italic => 'Italic',
                default => 'Roman',
            },
            default => $family.match (true) {
                $bold && $italic => '-BoldOblique',
                $bold => '-Bold',
                $italic => '-Oblique',
                default => '',
            },
        };
    }
}
