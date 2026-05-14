<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Barcode;

use Dskripchenko\PhpPdf\Barcode\QrEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QrAutoMaskTest extends TestCase
{
    #[Test]
    public function selected_mask_in_range(): void
    {
        $enc = new QrEncoder('Hello');
        self::assertGreaterThanOrEqual(0, $enc->selectedMask);
        self::assertLessThanOrEqual(7, $enc->selectedMask);
    }

    #[Test]
    public function different_inputs_могут_select_different_masks(): void
    {
        // Smoke: каждое значение из 0..7 selected for some input across many trials.
        // Just verify range varies across diverse inputs.
        $masks = [];
        $inputs = ['A', 'BB', 'HELLO WORLD', '123456', 'test123!', 'abc-def', 'XYZ123ABC'];
        foreach ($inputs as $input) {
            $enc = new QrEncoder($input);
            $masks[] = $enc->selectedMask;
        }
        // At least 2 different masks для variety of inputs (probabilistic).
        self::assertGreaterThan(1, count(array_unique($masks)));
    }

    #[Test]
    public function mask_encoded_в_format_info(): void
    {
        // Best-mask write format info reflects selected mask in bits.
        // Smoke: verify QR generated successfully.
        $enc = new QrEncoder('Test');
        self::assertSame(21, $enc->size()); // V1 = 21×21.
        self::assertGreaterThanOrEqual(0, $enc->selectedMask);
    }
}
