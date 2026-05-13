<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Font\Ttf;

/**
 * Big-endian binary reader для разбора TrueType / OpenType binary tables.
 *
 * TTF использует big-endian для всех multi-byte значений (ISO/IEC 14496-22
 * §4.1). PHP `unpack` поддерживает big-endian через формат-кодеки
 * `n` (uint16) / `N` (uint32) / `J` (uint64).
 *
 * Знаковые значения (int16/int32) парсятся отдельно через bit-twiddling,
 * т.к. unpack возвращает unsigned.
 *
 * Класс stateful: хранит позицию-курсор, методы автоматически продвигают
 * её. seek/tell позволяют jump'ать к любому offset'у.
 */
final class BinaryReader
{
    private int $pos = 0;

    public function __construct(
        private readonly string $bytes,
    ) {}

    public function size(): int
    {
        return strlen($this->bytes);
    }

    public function tell(): int
    {
        return $this->pos;
    }

    public function seek(int $offset): void
    {
        if ($offset < 0 || $offset > strlen($this->bytes)) {
            throw new \OutOfBoundsException("seek($offset) out of range (size=".strlen($this->bytes).')');
        }
        $this->pos = $offset;
    }

    public function skip(int $n): void
    {
        $this->seek($this->pos + $n);
    }

    public function readUInt8(): int
    {
        $byte = ord($this->bytes[$this->pos]);
        $this->pos++;

        return $byte;
    }

    public function readUInt16(): int
    {
        $arr = unpack('n', substr($this->bytes, $this->pos, 2));
        $this->pos += 2;

        return $arr[1];
    }

    public function readUInt32(): int
    {
        $arr = unpack('N', substr($this->bytes, $this->pos, 4));
        $this->pos += 4;

        return $arr[1];
    }

    public function readInt16(): int
    {
        $u = $this->readUInt16();
        // Sign extend если top bit установлен.
        return $u >= 0x8000 ? $u - 0x10000 : $u;
    }

    public function readInt32(): int
    {
        $u = $this->readUInt32();

        return $u >= 0x80000000 ? $u - 0x100000000 : $u;
    }

    /**
     * Tag — четыре ASCII-байта (например, "cmap", "head"). Используется
     * как ключ в table directory.
     */
    public function readTag(): string
    {
        $tag = substr($this->bytes, $this->pos, 4);
        $this->pos += 4;

        return $tag;
    }

    /**
     * Read N raw bytes (для embedding'а subtables в свой output).
     */
    public function readBytes(int $n): string
    {
        $b = substr($this->bytes, $this->pos, $n);
        $this->pos += $n;

        return $b;
    }

    /**
     * Random-access read без изменения курсора.
     */
    public function peekUInt16(int $offset): int
    {
        $arr = unpack('n', substr($this->bytes, $offset, 2));

        return $arr[1];
    }

    public function peekUInt32(int $offset): int
    {
        $arr = unpack('N', substr($this->bytes, $offset, 4));

        return $arr[1];
    }
}
