<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Forms\Appearance;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\TextMeasurer;
use Dskripchenko\PhpPdf\Pdf\Forms\FormGraph;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfNull;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Writer;

/**
 * An embedded TrueType font (Type0, Identity-H), for text the form's own fonts
 * cannot show. The subset can only be built once every string is known, so the
 * font object is reserved up front and filled in by {@see embed()}.
 *
 * @internal
 */
final class UnicodeFont implements AppearanceFont
{
    private readonly PdfFont $font;

    private ?PdfReference $reference = null;

    public function __construct(private readonly FormGraph $graph, private readonly TtfFile $ttf)
    {
        $this->font = new PdfFont($ttf);
        $this->font->disableLigatures();
    }

    public function reference(): PdfReference
    {
        return $this->reference ??= $this->graph->allocate(PdfNull::instance());
    }

    public function canShow(string $utf8): bool
    {
        foreach (mb_str_split($utf8, 1, 'UTF-8') as $char) {
            $cp = mb_ord($char, 'UTF-8');
            if ($cp >= 0x20 && $this->ttf->glyphIdForChar($cp) === 0) {
                return false;
            }
        }

        return true;
    }

    public function encode(string $utf8): string
    {
        return $this->font->encodeText($utf8);
    }

    public function width(string $utf8, float $size): float
    {
        return (new TextMeasurer($this->font, $size, useKerning: false))->widthPt($utf8);
    }

    public function capHeight(): float
    {
        return $this->ascent() * 0.72;
    }

    public function ascent(): float
    {
        return $this->ttf->ascent() * 1000 / $this->ttf->unitsPerEm();
    }

    /** Embed the subset of every glyph encoded so far into the graph. */
    public function embed(): void
    {
        if ($this->reference === null) {
            return;
        }
        $writer = new Writer();
        $fontId = $this->font->registerWith($writer);
        $writer->setRoot($writer->addObject('<< /Type /Catalog >>'));
        $source = ReaderDocument::fromBytes($writer->toBytes());

        $imported = $this->graph->importer->withSource(
            $source,
            static fn ($importer) => $importer->importObject($fontId),
        );
        $this->graph->set($this->reference->number, $this->graph->get($imported->number));
    }
}
