<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Style\RunStyle;

/**
 * Field — dynamic content placeholder, рассчитываемый Layout engine'ом.
 *
 * Common kinds:
 *  - PAGE      — current page number
 *  - NUMPAGES  — total pages count
 *  - DATE      — current date (формат через `:format`)
 *  - TIME      — current time
 *  - MERGEFIELD — placeholder для mail-merge replacement
 *
 * Format: `KIND` или `KIND:format-spec`. E.g.:
 *   - 'PAGE'
 *   - 'NUMPAGES'
 *   - 'DATE:dd.MM.yyyy'
 *   - 'MERGEFIELD:CustomerName'
 *
 * Layout engine resolves PAGE/NUMPAGES в numbers, DATE/TIME в текущий
 * date/time в указанном формате, MERGEFIELD оставляет placeholder text.
 */
final readonly class Field implements InlineElement
{
    public const string PAGE = 'PAGE';

    public const string NUMPAGES = 'NUMPAGES';

    public const string DATE = 'DATE';

    public const string TIME = 'TIME';

    public const string MERGEFIELD = 'MERGEFIELD';

    public function __construct(
        public string $instruction,
        public RunStyle $style = new RunStyle,
    ) {}

    public static function page(RunStyle $style = new RunStyle): self
    {
        return new self(self::PAGE, $style);
    }

    public static function totalPages(RunStyle $style = new RunStyle): self
    {
        return new self(self::NUMPAGES, $style);
    }

    public static function date(string $format = 'dd.MM.yyyy', RunStyle $style = new RunStyle): self
    {
        return new self(self::DATE.':'.$format, $style);
    }

    public static function time(string $format = 'HH:mm', RunStyle $style = new RunStyle): self
    {
        return new self(self::TIME.':'.$format, $style);
    }

    public static function mergeField(string $name, RunStyle $style = new RunStyle): self
    {
        return new self(self::MERGEFIELD.':'.$name, $style);
    }

    /**
     * Field type без формата (PAGE, DATE и т.д.).
     */
    public function kind(): string
    {
        $colon = strpos($this->instruction, ':');

        return $colon === false ? $this->instruction : substr($this->instruction, 0, $colon);
    }

    /**
     * Format-параметр (после двоеточия), или пустая строка.
     */
    public function format(): string
    {
        $colon = strpos($this->instruction, ':');

        return $colon === false ? '' : substr($this->instruction, $colon + 1);
    }
}
