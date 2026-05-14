<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

/**
 * Минимальный PDF-эмиттер на уровне формата (без layout/font logic).
 *
 * Pattern:
 *   $w = new Writer(version: '1.7');
 *   $catalogId = $w->reserveObject();
 *   $pagesId = $w->reserveObject();
 *   $pageId = $w->reserveObject();
 *   ...
 *   $w->setObject($catalogId, "<< /Type /Catalog /Pages $pagesId 0 R >>");
 *   ...
 *   $w->setRoot($catalogId);
 *   echo $w->toBytes();
 *
 * Структура итогового PDF:
 *   %PDF-1.7
 *   %ÂÅÒÓ          ← high-byte comment (рекомендация ISO 32000-1 §7.5.2:
 *                    помогает определять binary mode при transmission)
 *   1 0 obj
 *     <<...>>
 *   endobj
 *   ...
 *   xref
 *     0 N
 *     0000000000 65535 f
 *     0000000018 00000 n
 *     ...
 *   trailer
 *     << /Size N /Root 1 0 R >>
 *   startxref
 *     <offset>
 *   %%EOF
 *
 * Indirect object IDs выдаются монотонно начиная с 1. Generation number
 * у всех new-объектов = 0 (incremental updates не поддерживаются).
 */
final class Writer
{
    private const string LINE_ENDING = "\n";

    /** @var array<int, ?string> objectId → serialised body (без `N 0 obj`-обёртки) */
    private array $objects = [];

    private int $nextId = 1;

    private ?int $rootId = null;

    private ?int $infoId = null;

    /** Phase 41: encryption config (null = no encryption). */
    private ?Encryption $encryption = null;

    private ?int $encryptId = null;

    /** Phase 108: PKCS#7 signing config (null = no signature). */
    private ?SignatureConfig $signature = null;

    private ?int $signatureDictId = null;

    public function __construct(
        private readonly string $version = '1.7',
    ) {}

    /**
     * Phase 41: Enable encryption — encrypt streams через RC4-128 V2 R3.
     */
    public function setEncryption(Encryption $encryption, int $encryptObjectId): void
    {
        $this->encryption = $encryption;
        $this->encryptId = $encryptObjectId;
    }

    /**
     * Phase 108: enable PKCS#7 detached signing — patches /ByteRange и
     * /Contents placeholders в the signature dictionary post-emission.
     */
    public function setSignature(SignatureConfig $sig, int $sigDictId): void
    {
        $this->signature = $sig;
        $this->signatureDictId = $sigDictId;
    }

    /**
     * Резервирует object ID. Используется когда нужно создать
     * cross-references раньше, чем тело объекта (например, Catalog ссылается
     * на Pages, который ещё не написан).
     */
    public function reserveObject(): int
    {
        $id = $this->nextId++;
        $this->objects[$id] = null;

        return $id;
    }

    /**
     * Записывает тело уже зарезервированного объекта.
     */
    public function setObject(int $id, string $body): void
    {
        if (! array_key_exists($id, $this->objects)) {
            throw new \LogicException("Object id $id was not reserved before setObject().");
        }
        $this->objects[$id] = $body;
    }

    /**
     * Append-shortcut: сразу резервирует и заполняет.
     */
    public function addObject(string $body): int
    {
        $id = $this->reserveObject();
        $this->setObject($id, $body);

        return $id;
    }

    public function setRoot(int $catalogId): void
    {
        $this->rootId = $catalogId;
    }

    /**
     * Sets /Info dictionary reference (PDF metadata). Trailer добавит
     * `/Info N 0 R` если задан.
     */
    public function setInfo(int $infoId): void
    {
        $this->infoId = $infoId;
    }

    /**
     * Сериализует весь PDF в string. Бросает исключение если есть unfilled-объекты.
     *
     * Internally calls {@see toStream()} via php://memory.
     */
    public function toBytes(): string
    {
        $mem = fopen('php://memory', 'r+b');
        if ($mem === false) {
            throw new \RuntimeException('Failed to open php://memory stream');
        }
        try {
            $this->toStream($mem);
            rewind($mem);
            $bytes = stream_get_contents($mem);
            if ($bytes === false) {
                throw new \RuntimeException('Failed to read memory stream');
            }

            return $bytes;
        } finally {
            fclose($mem);
        }
    }

    /**
     * Phase 129: streaming output. Writes PDF incrementally к $stream resource,
     * avoiding accumulating final document в string memory.
     *
     * Use case: writing large PDFs к file/HTTP response без full-document
     * memory overhead.
     *
     * @param  resource  $stream  any writable stream resource
     * @return int  total bytes written
     */
    public function toStream($stream): int
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('toStream requires a stream resource');
        }
        if ($this->rootId === null) {
            throw new \LogicException('Catalog root not set; call setRoot() before toStream().');
        }
        foreach ($this->objects as $id => $body) {
            if ($body === null) {
                throw new \LogicException("Object $id was reserved but never filled.");
            }
        }

        // PKCS#7 signing requires post-emit byte patching (locate
        // /ByteRange + /Contents placeholders, sign hash, splice in
        // signature).
        //
        // Phase 191: streaming optimization. Если stream is seekable (file
        // handle), emit incrementally + seek back для patch. Avoids full
        // document buffer. Non-seekable streams (pipe, socket) — fall back
        // к buffered approach.
        if ($this->signature !== null) {
            if (self::streamIsSeekable($stream)) {
                return $this->toSeekableStreamWithSignature($stream);
            }
            $bytes = $this->buildBytes();
            $bytes = $this->applyPkcs7Signature($bytes);

            return self::writeAll($stream, $bytes);
        }

        $written = 0;
        $written += self::writeAll($stream, '%PDF-' . $this->version . self::LINE_ENDING);
        $written += self::writeAll($stream, "%\xE2\xE3\xCF\xD3" . self::LINE_ENDING);

        // Emit objects incrementally; record offsets for xref.
        $offsets = [];
        foreach ($this->objects as $id => $body) {
            if ($this->encryption !== null && $id !== $this->encryptId) {
                $body = $this->encryptStreamsInBody($body, $id);
            }
            $offsets[$id] = $written;
            $written += self::writeAll($stream, $id . ' 0 obj' . self::LINE_ENDING);
            $written += self::writeAll($stream, $body . self::LINE_ENDING);
            $written += self::writeAll($stream, 'endobj' . self::LINE_ENDING);
        }

        // xref table.
        $xrefOffset = $written;
        $count = $this->nextId;
        $written += self::writeAll($stream, 'xref' . self::LINE_ENDING);
        $written += self::writeAll($stream, '0 ' . $count . self::LINE_ENDING);
        $written += self::writeAll($stream, '0000000000 65535 f ' . self::LINE_ENDING);
        for ($id = 1; $id < $count; $id++) {
            $written += self::writeAll($stream, sprintf("%010d 00000 n \n", $offsets[$id]));
        }

        // Trailer.
        $written += self::writeAll($stream, 'trailer' . self::LINE_ENDING);
        $infoPart = $this->infoId !== null ? ' /Info ' . $this->infoId . ' 0 R' : '';
        $encryptPart = '';
        $idPart = '';
        if ($this->encryption !== null && $this->encryptId !== null) {
            $encryptPart = ' /Encrypt ' . $this->encryptId . ' 0 R';
            $idHex = bin2hex($this->encryption->fileId);
            $idPart = ' /ID [<' . $idHex . '> <' . $idHex . '>]';
        }
        $written += self::writeAll($stream, '<< /Size ' . $count . ' /Root ' . $this->rootId . ' 0 R' . $infoPart . $encryptPart . $idPart . ' >>' . self::LINE_ENDING);
        $written += self::writeAll($stream, 'startxref' . self::LINE_ENDING);
        $written += self::writeAll($stream, $xrefOffset . self::LINE_ENDING);
        $written += self::writeAll($stream, '%%EOF' . self::LINE_ENDING);

        return $written;
    }

    /**
     * Internal: build PDF в memory string без signing. Used by signing
     * path which needs full bytes для post-emit patching.
     */
    private function buildBytes(): string
    {
        $mem = fopen('php://memory', 'r+b');
        if ($mem === false) {
            throw new \RuntimeException('Failed to open memory stream');
        }
        try {
            $hadSig = $this->signature;
            $this->signature = null; // temporarily disable to avoid recursion
            $this->toStream($mem);
            $this->signature = $hadSig;
            rewind($mem);

            return (string) stream_get_contents($mem);
        } finally {
            fclose($mem);
        }
    }

    /**
     * @param  resource  $stream
     */
    /**
     * Phase 191: detect если stream supports random-access seek (fseek).
     * File handles seekable; pipes/sockets/php://output обычно нет.
     *
     * @param  resource  $stream
     */
    private static function streamIsSeekable($stream): bool
    {
        $meta = stream_get_meta_data($stream);

        return ($meta['seekable'] ?? false) === true;
    }

    /**
     * Phase 191: streaming PKCS#7 signing для seekable streams.
     * Emits объекты incrementally, после полного emit seek'ает back и
     * patches /ByteRange + /Contents placeholders с actual signature.
     *
     * @param  resource  $stream
     */
    private function toSeekableStreamWithSignature($stream): int
    {
        // Emit identical к non-signed path, then patch in-place в stream.
        // Strategy: write everything, remember start offset, seek back, patch.
        $startOffset = ftell($stream);
        $written = 0;
        $written += self::writeAll($stream, '%PDF-' . $this->version . self::LINE_ENDING);
        $written += self::writeAll($stream, "%\xE2\xE3\xCF\xD3" . self::LINE_ENDING);

        $offsets = [];
        foreach ($this->objects as $id => $body) {
            if ($body === null) {
                throw new \LogicException("Object $id was reserved but never filled.");
            }
            if ($this->encryption !== null && $id !== $this->encryptId) {
                $body = $this->encryptStreamsInBody($body, $id);
            }
            $offsets[$id] = $written;
            $written += self::writeAll($stream, $id . ' 0 obj' . self::LINE_ENDING);
            $written += self::writeAll($stream, $body . self::LINE_ENDING);
            $written += self::writeAll($stream, 'endobj' . self::LINE_ENDING);
        }

        $xrefOffset = $written;
        $count = $this->nextId;
        $written += self::writeAll($stream, 'xref' . self::LINE_ENDING);
        $written += self::writeAll($stream, '0 ' . $count . self::LINE_ENDING);
        $written += self::writeAll($stream, '0000000000 65535 f ' . self::LINE_ENDING);
        for ($id = 1; $id < $count; $id++) {
            $off = $offsets[$id] ?? 0;
            $written += self::writeAll($stream, sprintf('%010d 00000 n %s', $off, self::LINE_ENDING));
        }
        // Trailer.
        $trailer = '<< /Size ' . $count . ' /Root 1 0 R';
        if ($this->encryption !== null && $this->encryptId !== null) {
            $trailer .= ' /Encrypt ' . $this->encryptId . ' 0 R';
        }
        if ($this->fileId !== null) {
            $fid = strtoupper(bin2hex($this->fileId));
            $trailer .= ' /ID [<' . $fid . '> <' . $fid . '>]';
        }
        $trailer .= ' >>';
        $written += self::writeAll($stream, 'trailer' . self::LINE_ENDING);
        $written += self::writeAll($stream, $trailer . self::LINE_ENDING);
        $written += self::writeAll($stream, 'startxref' . self::LINE_ENDING);
        $written += self::writeAll($stream, $xrefOffset . self::LINE_ENDING);
        $written += self::writeAll($stream, '%%EOF' . self::LINE_ENDING);

        // Now patch signature: seek к start, read full content, apply
        // applyPkcs7Signature, seek back, rewrite content.
        // Note: для very large documents эта оптимизация partial — final
        // hashing still needs full read. Но avoiding intermediate string
        // concatenation savings memory bandwidth.
        fseek($stream, $startOffset);
        $content = '';
        while (! feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            $content .= $chunk;
        }
        $signed = $this->applyPkcs7Signature($content);
        // Rewrite signed content.
        fseek($stream, $startOffset);
        $writtenFinal = self::writeAll($stream, $signed);
        // Truncate если signed content shorter than original.
        $endOffset = $startOffset + $writtenFinal;
        if (function_exists('ftruncate')) {
            ftruncate($stream, $endOffset);
        }

        return $writtenFinal;
    }

    private static function writeAll($stream, string $data): int
    {
        $total = strlen($data);
        $written = 0;
        while ($written < $total) {
            $w = fwrite($stream, substr($data, $written));
            if ($w === false || $w === 0) {
                throw new \RuntimeException('Stream write failed at offset ' . $written);
            }
            $written += $w;
        }

        return $total;
    }

    /**
     * Phase 108: locate placeholders in assembled bytes, compute byte
     * range, sign data outside /Contents, patch dictionary в-place.
     */
    private function applyPkcs7Signature(string $bytes): string
    {
        // Locate /ByteRange [<digits> <digits> <digits> <digits>] placeholder.
        // The placeholder values are all zeros, padded с trailing spaces к
        // 10 digits each для room под actual integers up to ~10 GB files.
        if (! preg_match(
            '@/ByteRange \[(\d+ +\d+ +\d+ +\d+) +\]@',
            $bytes,
            $brMatch,
            PREG_OFFSET_CAPTURE,
        )) {
            throw new \RuntimeException('Phase 108: /ByteRange placeholder not found in PDF stream');
        }
        $brStart = (int) $brMatch[0][1];
        $brLen = strlen((string) $brMatch[0][0]);

        // Locate /Contents <000...000> placeholder.
        if (! preg_match(
            '@/Contents <0+>@',
            $bytes,
            $cMatch,
            PREG_OFFSET_CAPTURE,
        )) {
            throw new \RuntimeException('Phase 108: /Contents placeholder not found');
        }
        $cStart = (int) $cMatch[0][1];
        $cLen = strlen((string) $cMatch[0][0]);

        // Byte ranges: [0, gapStart] и [gapEnd, EOF].
        // gapStart = '<' position; gapEnd = position right after '>'.
        $gapStart = $cStart + strlen('/Contents ');
        $gapEnd = $cStart + $cLen;
        $a = 0;
        $b = $gapStart;
        $c = $gapEnd;
        $d = strlen($bytes) - $gapEnd;

        // Compute ByteRange string padded к original length.
        $brContent = sprintf('/ByteRange [%d %d %d %d]', $a, $b, $c, $d);
        if (strlen($brContent) > $brLen) {
            throw new \RuntimeException('Phase 108: /ByteRange replacement exceeds placeholder');
        }
        $brContent = str_pad($brContent, $brLen, ' ', STR_PAD_RIGHT);
        $bytes = substr_replace($bytes, $brContent, $brStart, $brLen);

        // Build hashable data: bytes[0..b] + bytes[c..].
        $signedData = substr($bytes, 0, $b) . substr($bytes, $c);

        // Sign through openssl_pkcs7_sign — requires input file.
        $infile = tempnam(sys_get_temp_dir(), 'phppdf-sig-in-');
        $outfile = tempnam(sys_get_temp_dir(), 'phppdf-sig-out-');
        if ($infile === false || $outfile === false) {
            throw new \RuntimeException('Phase 108: failed to create temp files for signing');
        }
        file_put_contents($infile, $signedData);

        $signKey = $this->signature->privateKeyPassphrase !== null
            ? [$this->signature->privateKeyPem, $this->signature->privateKeyPassphrase]
            : $this->signature->privateKeyPem;

        $ok = openssl_pkcs7_sign(
            $infile,
            $outfile,
            $this->signature->certPem,
            $signKey,
            [],
            PKCS7_BINARY | PKCS7_DETACHED,
        );
        $smime = $ok ? (string) file_get_contents($outfile) : '';
        @unlink($infile);
        @unlink($outfile);
        if (! $ok) {
            throw new \RuntimeException('Phase 108: openssl_pkcs7_sign failed: ' . openssl_error_string());
        }

        // Extract DER PKCS#7 bytes из S/MIME envelope.
        $der = self::extractPkcs7FromSmime($smime);
        $sigHex = strtoupper(bin2hex($der));

        $placeholderHexLen = $cLen - strlen('/Contents <') - 1; // minus '>'
        if (strlen($sigHex) > $placeholderHexLen) {
            throw new \RuntimeException(sprintf(
                'Phase 108: PKCS#7 signature %d hex chars exceeds placeholder %d',
                strlen($sigHex), $placeholderHexLen,
            ));
        }
        $sigHex = str_pad($sigHex, $placeholderHexLen, '0', STR_PAD_RIGHT);

        // Patch /Contents <...>.
        $hexStart = $cStart + strlen('/Contents <');

        return substr_replace($bytes, $sigHex, $hexStart, $placeholderHexLen);
    }

    /**
     * Parse openssl_pkcs7_sign output (S/MIME multipart/signed) → raw DER.
     *
     * Structure (simplified):
     *   MIME headers...
     *   --boundary
     *   <signed message body>
     *   --boundary
     *   Content-Type: application/x-pkcs7-signature; ...
     *   Content-Transfer-Encoding: base64
     *   Content-Disposition: attachment; filename="smime.p7s"
     *   <empty>
     *   <base64-encoded PKCS#7>
     *   --boundary--
     */
    private static function extractPkcs7FromSmime(string $smime): string
    {
        if (! preg_match(
            '@Content-Disposition:[^\r\n]+(?:\r?\n[^\r\n]*)*?\r?\n\r?\n([A-Za-z0-9+/=\s]+?)\r?\n--@s',
            $smime,
            $m,
        )) {
            throw new \RuntimeException('Phase 108: PKCS#7 base64 portion not found в S/MIME');
        }
        $b64 = (string) preg_replace('@\s+@', '', $m[1]);
        $der = base64_decode($b64, true);
        if ($der === false || $der === '') {
            throw new \RuntimeException('Phase 108: base64_decode failed for PKCS#7 envelope');
        }

        return $der;
    }

    /**
     * Phase 41+77: encrypt streams + strings в object body.
     * Streams: `stream\n<bytes>\nendstream` → RC4/AES per-object.
     * Strings: literal `(...)` (Phase 77) → encrypted, then re-encoded.
     */
    private function encryptStreamsInBody(string $body, int $objId): string
    {
        if ($this->encryption === null) {
            return $body;
        }
        $enc = $this->encryption;

        // Phase 77: encrypt literal strings первый (before streams чтобы
        // не повредить regex с binary).
        $body = $this->encryptLiteralStrings($body, $objId);

        // Encrypt stream content: `stream\n<bytes>\nendstream`.
        return preg_replace_callback(
            '@stream\n(.*?)\nendstream@s',
            function (array $m) use ($enc, $objId): string {
                $encrypted = $enc->encryptObject($m[1], $objId);

                return 'stream'."\n".$encrypted."\n".'endstream';
            },
            $body,
        ) ?? $body;
    }

    /**
     * Phase 77: Encrypt literal PDF strings (...) → output as hex form
     * <ENCRYPTED_HEX>. Walks body manually для correct paren-balance
     * (literal strings allow nested () pairs balanced).
     */
    private function encryptLiteralStrings(string $body, int $objId): string
    {
        if ($this->encryption === null) {
            return $body;
        }
        $enc = $this->encryption;
        $out = '';
        $len = strlen($body);
        $i = 0;
        while ($i < $len) {
            $ch = $body[$i];
            if ($ch === '(') {
                // Find matching closing paren respecting nested + escaped.
                $depth = 1;
                $j = $i + 1;
                $strStart = $j;
                while ($j < $len && $depth > 0) {
                    $cj = $body[$j];
                    if ($cj === '\\' && $j + 1 < $len) {
                        $j += 2;

                        continue;
                    }
                    if ($cj === '(') {
                        $depth++;
                    } elseif ($cj === ')') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                    $j++;
                }
                if ($depth !== 0) {
                    // Malformed — keep as-is.
                    $out .= substr($body, $i, $j - $i + 1);
                    $i = $j + 1;

                    continue;
                }
                $raw = substr($body, $strStart, $j - $strStart);
                // Unescape PDF literal string escapes: \\, \(, \).
                $decoded = strtr($raw, ['\\\\' => '\\', '\\(' => '(', '\\)' => ')']);
                $encrypted = $enc->encryptObject($decoded, $objId);
                $out .= '<'.bin2hex($encrypted).'>';
                $i = $j + 1; // skip closing )
            } else {
                $out .= $ch;
                $i++;
            }
        }

        return $out;
    }
}
