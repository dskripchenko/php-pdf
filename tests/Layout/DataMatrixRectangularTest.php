<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Barcode\DataMatrixEncoder;
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DataMatrix ECC 200 has rectangular symbol sizes (e.g. 12×26). The shared
 * 2D matrix renderer assumed a square symbol and walked rows 0..cols-1,
 * reading past the end of the module matrix (an undefined-index warning
 * per missing cell) and drawing a distorted symbol.
 */
final class DataMatrixRectangularTest extends TestCase
{
    #[Test]
    public function fixture_produces_a_rectangular_symbol(): void
    {
        $enc = new DataMatrixEncoder('php-pdf torture');

        self::assertNotSame(
            count($enc->modules()),
            $enc->symbolWidth(),
            'Fixture must encode to a rectangular DataMatrix symbol',
        );
    }

    #[Test]
    public function rectangular_symbol_renders_without_matrix_overruns(): void
    {
        set_error_handler(static function (int $errno, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $errno, $file, $line);
        });
        try {
            $doc = new Document(new Section([
                new Barcode('php-pdf torture', BarcodeFormat::DataMatrix, heightPt: 60),
            ]));
            $bytes = $doc->toBytes(new Engine(compressStreams: false));
        } finally {
            restore_error_handler();
        }

        // Module runs are emitted as `re` + `f` fill operators.
        self::assertMatchesRegularExpression('@re\nf\n@', $bytes);
    }
}
