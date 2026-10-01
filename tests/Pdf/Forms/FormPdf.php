<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Writer;

/**
 * Hand-built one-page AcroForm PDFs, for shapes real forms have but the
 * library's own FormField authoring never produces (inherited resources,
 * inline /DR fonts, auto-size /DA, /Opt, deep hierarchies, …).
 */
final class FormPdf
{
    public readonly int $page;

    public readonly int $pages;

    public readonly int $catalog;

    public readonly int $acroForm;

    public string $pageAttributes = '/MediaBox [0 0 612 792] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Times-Roman >> >> >>';

    public string $pagesAttributes = '';

    public string $acroFormAttributes = '/DR << /Font << /Helv << /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >> >> >>';

    public string $catalogAttributes = '';

    public string $content = 'BT /F1 12 Tf 72 760 Td (Original page text) Tj ET';

    public ?string $info = null;

    private readonly Writer $writer;

    /** @var list<int> */
    private array $fields = [];

    /** @var list<int> */
    private array $annots = [];

    public function __construct()
    {
        $this->writer = new Writer();
        $this->page = $this->writer->reserveObject();
        $this->pages = $this->writer->reserveObject();
        $this->catalog = $this->writer->reserveObject();
        $this->acroForm = $this->writer->reserveObject();
    }

    public function reserve(): int
    {
        return $this->writer->reserveObject();
    }

    public function set(int $id, string $body): int
    {
        $this->writer->setObject($id, $body);

        return $id;
    }

    public function add(string $body): int
    {
        return $this->writer->addObject($body);
    }

    /** Register a top-level field (listed in /AcroForm /Fields). */
    public function field(int $id): self
    {
        $this->fields[] = $id;

        return $this;
    }

    /** Register a widget annotation on the page. */
    public function widget(int $id): self
    {
        $this->annots[] = $id;

        return $this;
    }

    /** A merged single-widget text field on the page. */
    public function textField(string $name, string $rect = '100 700 300 720', string $extra = '/DA (/Helv 10 Tf 0 g)'): int
    {
        $id = $this->add(sprintf(
            '<< /Type /Annot /Subtype /Widget /FT /Tx /T (%s) /Rect [%s] /P %d 0 R /F 4 %s >>',
            $name,
            $rect,
            $this->page,
            $extra,
        ));
        $this->field($id)->widget($id);

        return $id;
    }

    public function toBytes(): string
    {
        $contents = $this->add(sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($this->content), $this->content));
        $ids = static fn (array $list): string => implode(' ', array_map(static fn (int $id) => "{$id} 0 R", $list));

        $this->set($this->page, sprintf(
            '<< /Type /Page /Parent %d 0 R /Contents %d 0 R /Annots [%s] %s >>',
            $this->pages,
            $contents,
            $ids($this->annots),
            $this->pageAttributes,
        ));
        $this->set($this->pages, sprintf('<< /Type /Pages /Kids [%d 0 R] /Count 1 %s >>', $this->page, $this->pagesAttributes));
        $this->set($this->acroForm, sprintf('<< /Fields [%s] %s >>', $ids($this->fields), $this->acroFormAttributes));
        $this->set($this->catalog, sprintf(
            '<< /Type /Catalog /Pages %d 0 R /AcroForm %d 0 R %s >>',
            $this->pages,
            $this->acroForm,
            $this->catalogAttributes,
        ));
        $this->writer->setRoot($this->catalog);
        if ($this->info !== null) {
            $this->writer->setInfo($this->add($this->info));
        }

        return $this->writer->toBytes();
    }

    /**
     * Every content stream drawn on a page — its own and, recursively, those
     * of the Form XObjects it paints — decoded.
     */
    public static function drawn(ReaderDocument $doc, int $pageIndex = 0): string
    {
        $page = $doc->pages()[$pageIndex];
        $contents = $doc->deref($page->dict->get('Contents'));
        $out = [];
        foreach (is_array($contents) ? $contents : [$page->dict->get('Contents')] as $ref) {
            $stream = $doc->deref($ref);
            if ($stream instanceof PdfStream) {
                $out[] = $doc->streamData($stream);
            }
        }
        $resources = $doc->deref($page->dict->get('Resources'));
        $xobjects = $resources instanceof PdfDictionary ? $doc->deref($resources->get('XObject')) : null;
        foreach ($xobjects instanceof PdfDictionary ? $xobjects->all() : [] as $name => $ref) {
            $stream = $doc->deref($ref);
            if ($stream instanceof PdfStream && ($stream->dict->get('Subtype') instanceof PdfName) && $stream->dict->get('Subtype')->value === 'Form') {
                $out[] = "% XObject /{$name}\n".$doc->streamData($stream);
            }
        }

        return implode("\n", $out);
    }

    public static function liberationSans(): ?string
    {
        $path = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';

        return is_readable($path) ? $path : null;
    }
}
