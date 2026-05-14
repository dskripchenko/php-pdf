<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Font\Ttf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 131: Variable fonts — fvar table parsing.
 */
final class FvarReaderTest extends TestCase
{
    private function loadSystemVariableFont(): ?TtfFile
    {
        $path = '/System/Library/Fonts/NewYork.ttf';
        if (! is_readable($path)) {
            return null;
        }

        return TtfFile::fromFile($path);
    }

    #[Test]
    public function liberation_sans_is_not_variable(): void
    {
        $path = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached');
        }
        $ttf = TtfFile::fromFile($path);
        self::assertFalse($ttf->isVariable());
        self::assertSame([], $ttf->variationAxes());
        self::assertSame([], $ttf->namedInstances());
    }

    #[Test]
    public function newyork_variable_font_has_axes(): void
    {
        $ttf = $this->loadSystemVariableFont();
        if ($ttf === null) {
            self::markTestSkipped('System variable font /System/Library/Fonts/NewYork.ttf not available');
        }
        self::assertTrue($ttf->isVariable());
        $axes = $ttf->variationAxes();
        self::assertNotEmpty($axes);

        // Expected axes для NewYork: opsz, wght, GRAD.
        $tags = array_column($axes, 'tag');
        self::assertContains('wght', $tags, 'Should have Weight axis');
        self::assertContains('opsz', $tags, 'Should have Optical Size axis');
    }

    #[Test]
    public function variable_font_axes_have_ranges(): void
    {
        $ttf = $this->loadSystemVariableFont();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        foreach ($ttf->variationAxes() as $axis) {
            // min ≤ default ≤ max
            self::assertLessThanOrEqual($axis['max'] + 1e-6, $axis['default']);
            self::assertGreaterThanOrEqual($axis['min'] - 1e-6, $axis['default']);
            self::assertSame(4, strlen($axis['tag']), 'Axis tag is 4-char OpenType tag');
        }
    }

    #[Test]
    public function named_instances_have_coordinates(): void
    {
        $ttf = $this->loadSystemVariableFont();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        $instances = $ttf->namedInstances();
        self::assertNotEmpty($instances);

        $axisTags = array_column($ttf->variationAxes(), 'tag');
        foreach ($instances as $inst) {
            self::assertSame(count($axisTags), count($inst['coordinates']),
                'Instance должен иметь по 1 coordinate per axis');
            foreach ($axisTags as $tag) {
                self::assertArrayHasKey($tag, $inst['coordinates']);
            }
        }
    }

    #[Test]
    public function name_lookup_for_axis_and_instance_ids(): void
    {
        $ttf = $this->loadSystemVariableFont();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        // First axis should have a readable name (e.g., "Weight", "Optical Size").
        $axes = $ttf->variationAxes();
        $axisName = $ttf->nameById($axes[0]['nameId']);
        self::assertNotNull($axisName);
        self::assertNotSame('', $axisName);

        // First instance имеет subfamily name.
        $instances = $ttf->namedInstances();
        $instName = $ttf->nameById($instances[0]['nameId']);
        self::assertNotNull($instName);
    }

    #[Test]
    public function newyork_has_weight_axis_with_expected_range(): void
    {
        $ttf = $this->loadSystemVariableFont();
        if ($ttf === null) {
            self::markTestSkipped('System variable font not available');
        }
        $weightAxis = null;
        foreach ($ttf->variationAxes() as $a) {
            if ($a['tag'] === 'wght') {
                $weightAxis = $a;
                break;
            }
        }
        self::assertNotNull($weightAxis, 'NewYork should have wght axis');
        // Weight axis spec range typically 100..900 or wider.
        self::assertGreaterThanOrEqual(100.0, $weightAxis['min']);
        self::assertLessThanOrEqual(1000.0, $weightAxis['max']);
        self::assertGreaterThanOrEqual($weightAxis['min'], $weightAxis['default']);
        self::assertLessThanOrEqual($weightAxis['max'], $weightAxis['default']);
    }
}
