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
     * Сериализует весь PDF. Бросает исключение если есть unfilled-объекты.
     */
    public function toBytes(): string
    {
        if ($this->rootId === null) {
            throw new \LogicException('Catalog root not set; call setRoot() before toBytes().');
        }
        foreach ($this->objects as $id => $body) {
            if ($body === null) {
                throw new \LogicException("Object $id was reserved but never filled.");
            }
        }

        $out = '%PDF-'.$this->version.self::LINE_ENDING;
        // Comment-line с high-byte symbols — рекомендация ISO 32000-1 §7.5.2.
        $out .= "%\xE2\xE3\xCF\xD3".self::LINE_ENDING;

        // Object table. Записываем offsets для xref.
        $offsets = [];
        foreach ($this->objects as $id => $body) {
            // Phase 41: encrypt streams (НЕ применяется к Encrypt object'у
            // самому — он содержит ключи в clear).
            if ($this->encryption !== null && $id !== $this->encryptId) {
                $body = $this->encryptStreamsInBody($body, $id);
            }
            $offsets[$id] = strlen($out);
            $out .= $id.' 0 obj'.self::LINE_ENDING;
            $out .= $body.self::LINE_ENDING;
            $out .= 'endobj'.self::LINE_ENDING;
        }

        // xref-таблица.
        $xrefOffset = strlen($out);
        $count = $this->nextId; // объектов: 1..N; xref включает 0-й (head of free list)
        $out .= 'xref'.self::LINE_ENDING;
        $out .= '0 '.$count.self::LINE_ENDING;
        // Entry 0: free, generation 65535. Стандартный head.
        $out .= '0000000000 65535 f '.self::LINE_ENDING;
        for ($id = 1; $id < $count; $id++) {
            $offset = $offsets[$id];
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        // Trailer.
        $out .= 'trailer'.self::LINE_ENDING;
        $infoPart = $this->infoId !== null ? ' /Info '.$this->infoId.' 0 R' : '';
        $encryptPart = '';
        $idPart = '';
        if ($this->encryption !== null && $this->encryptId !== null) {
            $encryptPart = ' /Encrypt '.$this->encryptId.' 0 R';
            $idHex = bin2hex($this->encryption->fileId);
            // ID array — 16 bytes твой fileId; repeat для permanent + current.
            $idPart = ' /ID [<'.$idHex.'> <'.$idHex.'>]';
        }
        $out .= '<< /Size '.$count.' /Root '.$this->rootId.' 0 R'.$infoPart.$encryptPart.$idPart.' >>'.self::LINE_ENDING;
        $out .= 'startxref'.self::LINE_ENDING;
        $out .= $xrefOffset.self::LINE_ENDING;
        $out .= '%%EOF'.self::LINE_ENDING;

        return $out;
    }

    /**
     * Phase 41: Find stream blocks в object body и encrypt их contents
     * через per-object RC4 key.
     */
    private function encryptStreamsInBody(string $body, int $objId): string
    {
        if ($this->encryption === null) {
            return $body;
        }
        $enc = $this->encryption;
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
}
