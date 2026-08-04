<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\PageSetup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Расстояние до колонтитула.
 *
 * Word задаёт его отдельно от поля страницы (`w:header`), и раньше движок
 * прижимал шапку к самому краю листа: документ ехал вверх относительно
 * оригинала. Найдено сравнением с эталоном в printable.
 */
final class HeaderDistanceTest extends TestCase
{
    private function headerTopY(float $distancePt): float
    {
        $section = new Section(
            body: [new Paragraph([new Run('body')])],
            pageSetup: new PageSetup(headerDistancePt: $distancePt),
            headerBlocks: [new Paragraph([new Run('HEADERMARK')])],
        );

        $pdf = (new Document($section))->toBytes(new Engine(compressStreams: false));
        $path = tempnam(sys_get_temp_dir(), 'hdr-').'.pdf';
        file_put_contents($path, $pdf);

        try {
            $bbox = (string) shell_exec('pdftotext -bbox '.escapeshellarg($path).' - 2>/dev/null');
            preg_match('/yMin="([0-9.]+)"[^>]*>HEADERMARK/', $bbox, $m);

            return isset($m[1]) ? (float) $m[1] : -1.0;
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function header_starts_at_the_declared_distance(): void
    {
        $near = $this->headerTopY(4.0);
        $far = $this->headerTopY(40.0);

        // Шапка обязана опуститься ровно на разницу расстояний.
        self::assertEqualsWithDelta(36.0, $far - $near, 1.0);
    }
}
