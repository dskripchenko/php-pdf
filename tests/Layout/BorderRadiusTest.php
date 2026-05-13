<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Border;
use Dskripchenko\PhpPdf\Style\BorderSet;
use Dskripchenko\PhpPdf\Style\BorderStyle;
use Dskripchenko\PhpPdf\Style\CellStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BorderRadiusTest extends TestCase
{
    private function cellWithRadius(float $radius, ?Border $border = null): Cell
    {
        return new Cell(
            children: [new Paragraph([new Run('rounded')])],
            style: new CellStyle(
                borders: BorderSet::all($border ?? new Border(BorderStyle::Single, 8, '000000')),
                cornerRadiusPt: $radius,
            ),
        );
    }

    #[Test]
    public function rounded_border_emits_cubic_bezier_ops(): void
    {
        $doc = new Document(new Section([
            new Table([new Row([$this->cellWithRadius(10)])]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // Rounded path uses 'c' (cubic bezier) op. Square borders use 're'.
        self::assertStringContainsString(" c\n", $bytes);
    }

    #[Test]
    public function zero_radius_falls_back_to_square(): void
    {
        $doc = new Document(new Section([
            new Table([new Row([$this->cellWithRadius(0)])]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // No 'c' op (no curves) — square corners use re only.
        self::assertStringNotContainsString(" c\n", $bytes);
    }

    #[Test]
    public function rounded_background_fill_uses_curves(): void
    {
        $doc = new Document(new Section([
            new Table([new Row([new Cell(
                children: [new Paragraph([new Run('bg')])],
                style: new CellStyle(
                    backgroundColor: 'ccffcc',
                    cornerRadiusPt: 8,
                ),
            )])]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // Background also uses rounded path.
        self::assertStringContainsString(" c\n", $bytes);
    }

    #[Test]
    public function non_uniform_borders_fallback_to_square_corners(): void
    {
        // Different border per-side → no rounding.
        $borders = new BorderSet(
            top: new Border(BorderStyle::Single, 8, '000000'),
            left: new Border(BorderStyle::Single, 16, 'cc0000'),
            bottom: new Border(BorderStyle::Single, 8, '000000'),
            right: new Border(BorderStyle::Single, 8, '000000'),
        );
        $doc = new Document(new Section([
            new Table([new Row([new Cell(
                children: [new Paragraph([new Run('mixed')])],
                style: new CellStyle(borders: $borders, cornerRadiusPt: 8),
            )])]),
        ]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));
        // Square fallback (no 'c' ops для borders).
        // Note: bg can still produce curves если backgroundColor set, но
        // здесь нет bg, only borders.
        self::assertStringNotContainsString(" c\n", $bytes);
    }
}
