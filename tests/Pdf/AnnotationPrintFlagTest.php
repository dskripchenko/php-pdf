<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Links and form widgets carry /F 4 (Print), so they print with the page and
 * meet PDF/A (ISO 19005-1 §6.5.3, -2 §6.3.2), which requires the flag.
 */
final class AnnotationPrintFlagTest extends TestCase
{
    #[Test]
    public function every_kind_of_link_is_printable(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->addExternalLink(10, 10, 50, 10, 'https://example.com');
        $page->addInternalLink(10, 30, 50, 10, 'target');
        $page->addNamedActionLink(10, 50, 50, 10, 'NextPage');
        $page->addJavaScriptLink(10, 70, 50, 10, 'app.alert(1)');
        $page->addLaunchLink(10, 90, 50, 10, 'other.pdf');
        $pdf->registerDestination('target', $page, 0, 0);
        $links = self::annotations($pdf->toBytes(), 'Link');

        self::assertCount(5, $links);
        foreach ($links as $link) {
            self::assertStringContainsString('/F 4', $link);
        }
    }

    #[Test]
    public function every_kind_of_form_widget_is_printable(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->addFormField('text', 'name', 10, 10, 100, 20);
        $page->addFormField('checkbox', 'agree', 10, 40, 20, 20);
        $page->addFormField('combo', 'colour', 10, 70, 100, 20, options: ['Red', 'Blue']);
        $page->addFormField('radio-group', 'size', 10, 100, 20, 20, options: ['S', 'M'], radioWidgets: [['x' => 10, 'y' => 100, 'w' => 14, 'h' => 14], ['x' => 30, 'y' => 100, 'w' => 14, 'h' => 14]]);
        $page->addFormField('push', 'go', 10, 130, 60, 20);
        $widgets = self::annotations($pdf->toBytes(), 'Widget');

        self::assertCount(6, $widgets);
        foreach ($widgets as $widget) {
            self::assertStringContainsString('/F 4', $widget);
        }
    }

    /** @return list<string> */
    private static function annotations(string $bytes, string $subtype): array
    {
        preg_match_all('@<< /Type /Annot /Subtype /'.$subtype.'\b.*?>>\s*endobj@s', $bytes, $m);

        return $m[0];
    }
}
