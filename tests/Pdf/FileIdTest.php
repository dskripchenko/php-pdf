<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\Writer;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 212: /ID trailer entry always emitted (PDF spec compliance).
 */
final class FileIdTest extends TestCase
{
    #[Test]
    public function id_always_emitted_for_non_encrypted_doc(): void
    {
        $doc = new Document(new Section([new Paragraph([new Run('Test')])]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // /ID present в trailer.
        self::assertMatchesRegularExpression('@/ID \[<[0-9a-f]{32}> <[0-9a-f]{32}>\]@', $bytes);
    }

    #[Test]
    public function id_stable_across_repeated_emissions(): void
    {
        // Same document content → same /ID across calls (deterministic hash).
        $section = new Section([new Paragraph([new Run('Deterministic content')])]);
        $doc1 = new Document($section);
        $doc2 = new Document($section);
        $bytes1 = $doc1->toBytes(new Engine(compressStreams: false));
        $bytes2 = $doc2->toBytes(new Engine(compressStreams: false));

        preg_match('@/ID \[<([0-9a-f]{32})>@', $bytes1, $m1);
        preg_match('@/ID \[<([0-9a-f]{32})>@', $bytes2, $m2);
        self::assertSame($m1[1], $m2[1], 'Same content → same /ID hash');
    }

    #[Test]
    public function id_differs_for_different_content(): void
    {
        $doc1 = new Document(new Section([new Paragraph([new Run('Content A')])]));
        $doc2 = new Document(new Section([new Paragraph([new Run('Content B — different')])]));
        $bytes1 = $doc1->toBytes(new Engine(compressStreams: false));
        $bytes2 = $doc2->toBytes(new Engine(compressStreams: false));

        preg_match('@/ID \[<([0-9a-f]{32})>@', $bytes1, $m1);
        preg_match('@/ID \[<([0-9a-f]{32})>@', $bytes2, $m2);
        self::assertNotSame($m1[1], $m2[1], 'Different content → different /ID');
    }

    #[Test]
    public function id_format_is_two_identical_strings(): void
    {
        // PDF spec: /ID = [<original_id> <update_id>]. Original creation —
        // both equal (no incremental updates).
        $doc = new Document(new Section([new Paragraph([new Run('test')])]));
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        preg_match('@/ID \[<([0-9a-f]{32})> <([0-9a-f]{32})>\]@', $bytes, $m);
        self::assertSame($m[1], $m[2], 'Original = Update /ID для new documents');
    }

    #[Test]
    public function id_present_with_xref_stream(): void
    {
        $doc = new Document(
            new Section([new Paragraph([new Run('test')])]),
            useXrefStream: true,
        );
        $bytes = $doc->toBytes(new Engine(compressStreams: false));

        // XRef stream dict должен contain /ID.
        self::assertMatchesRegularExpression('@/Type /XRef.*?/ID \[<[0-9a-f]{32}>@s', $bytes);
    }

    #[Test]
    public function low_level_writer_emits_id(): void
    {
        $w = new Writer;
        $catId = $w->addObject('<< /Type /Catalog /Pages 2 0 R >>');
        $w->addObject('<< /Type /Pages /Kids [] /Count 0 >>');
        $w->setRoot($catId);
        $bytes = $w->toBytes();

        self::assertMatchesRegularExpression('@/ID \[<[0-9a-f]{32}> <[0-9a-f]{32}>\]@', $bytes);
    }

    #[Test]
    public function id_is_md5_length_16_bytes(): void
    {
        // 32 hex chars = 16 bytes (MD5 length).
        $w = new Writer;
        $catId = $w->addObject('<< /Type /Catalog /Pages 2 0 R >>');
        $w->addObject('<< /Type /Pages /Kids [] /Count 0 >>');
        $w->setRoot($catId);
        $bytes = $w->toBytes();

        preg_match('@/ID \[<([0-9a-f]+)>@', $bytes, $m);
        self::assertSame(32, strlen($m[1]));
    }
}
