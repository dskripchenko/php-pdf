<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Reader\Text;

use Dskripchenko\PhpPdf\Pdf\Reader\Lexer;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderPage;
use Dskripchenko\PhpPdf\Pdf\Reader\Token;
use Dskripchenko\PhpPdf\Pdf\Reader\TokenType;
use Throwable;

/**
 * Plain text of a PDF (§9.4, §9.10): a page's content streams and the form
 * XObjects they draw are interpreted for their text operators; each glyph's
 * text comes from its font (FontDecoder), its place from the text and
 * graphics matrices. Text is written in content order; a move to another
 * line starts a new line, a gap wider than a fraction of the font size
 * becomes a space. Images, paths and colour are ignored. A broken stream
 * gives the text read up to the break.
 */
final class TextExtractor
{
    /** Typographic ligatures, written out: text is for reading and search, not for setting. */
    private const LIGATURES = ["\u{FB00}" => 'ff', "\u{FB01}" => 'fi', "\u{FB02}" => 'fl', "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl', "\u{FB05}" => 'st', "\u{FB06}" => 'st'];

    /** Form XObjects inside form XObjects deeper than this are not read. */
    private const MAX_DEPTH = 8;

    /** @var array<string,FontDecoder> */
    private array $fonts = [];

    private string $out = '';

    private ?float $lastY = null;

    private ?float $lastEndX = null;

    private float $lastHeight = 0.0;

    public function __construct(private readonly ReaderDocument $doc)
    {
    }

    /** The text of every page, pages separated by a blank line. */
    public function document(): string
    {
        $pages = [];
        foreach ($this->doc->pages() as $page) {
            $pages[] = $this->page($page);
        }

        return trim(implode("\n\n", array_filter($pages, static fn (string $t): bool => $t !== '')));
    }

    public function page(ReaderPage $page): string
    {
        $this->out = '';
        $this->lastY = null;
        $this->lastEndX = null;
        $contents = $this->doc->deref($page->dict->get('Contents'));
        $streams = $contents instanceof PdfStream ? [$contents] : (is_array($contents) ? $contents : []);
        $data = '';
        foreach ($streams as $stream) {
            $stream = $this->doc->deref($stream);
            if ($stream instanceof PdfStream) {
                try {
                    $data .= $this->doc->streamData($stream)."\n";
                } catch (Throwable) {
                    continue;
                }
            }
        }
        $this->run($data, $page->resources, [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], 0);

        return self::tidy($this->out);
    }

    /**
     * @param array{float,float,float,float,float,float} $ctm
     */
    private function run(string $data, ?PdfDictionary $resources, array $ctm, int $depth): void
    {
        $lexer = new Lexer($data);
        $state = ['ctm' => $ctm, 'font' => null, 'size' => 0.0, 'tc' => 0.0, 'tw' => 0.0, 'th' => 1.0, 'tl' => 0.0, 'rise' => 0.0];
        $stack = [];
        $tm = $tlm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $operands = [];
        try {
            while (($token = $lexer->nextToken())->type !== TokenType::Eof) {
                if ($token->type !== TokenType::Keyword || in_array($token->value, ['true', 'false', 'null'], true)) {
                    $operands[] = $this->operand($lexer, $token);
                    continue;
                }
                $op = (string) $token->value;
                switch ($op) {
                    case 'q':
                        $stack[] = $state;
                        break;
                    case 'Q':
                        $state = array_pop($stack) ?? $state;
                        break;
                    case 'cm':
                        if (count($operands) >= 6) {
                            $state['ctm'] = self::multiply(self::matrix(array_slice($operands, -6)), $state['ctm']);
                        }
                        break;
                    case 'BT':
                        $tm = $tlm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
                        break;
                    case 'Tf':
                        $name = $operands[count($operands) - 2] ?? null;
                        $state['font'] = $name instanceof PdfName ? $this->font($resources, $name->value) : null;
                        $state['size'] = self::num($operands[count($operands) - 1] ?? 0);
                        break;
                    case 'Tc':
                        $state['tc'] = self::num(end($operands));
                        break;
                    case 'Tw':
                        $state['tw'] = self::num(end($operands));
                        break;
                    case 'Tz':
                        $state['th'] = self::num(end($operands)) / 100.0;
                        break;
                    case 'TL':
                        $state['tl'] = self::num(end($operands));
                        break;
                    case 'Ts':
                        $state['rise'] = self::num(end($operands));
                        break;
                    case 'Td':
                    case 'TD':
                        $tx = self::num($operands[count($operands) - 2] ?? 0);
                        $ty = self::num($operands[count($operands) - 1] ?? 0);
                        if ($op === 'TD') {
                            $state['tl'] = -$ty;
                        }
                        $tm = $tlm = self::multiply([1.0, 0.0, 0.0, 1.0, $tx, $ty], $tlm);
                        break;
                    case 'Tm':
                        if (count($operands) >= 6) {
                            $tm = $tlm = self::matrix(array_slice($operands, -6));
                        }
                        break;
                    case 'T*':
                        $tm = $tlm = self::multiply([1.0, 0.0, 0.0, 1.0, 0.0, -$state['tl']], $tlm);
                        break;
                    case 'Tj':
                        $tm = $this->show(end($operands), $state, $tm);
                        break;
                    case "'":
                        $tm = $tlm = self::multiply([1.0, 0.0, 0.0, 1.0, 0.0, -$state['tl']], $tlm);
                        $tm = $this->show(end($operands), $state, $tm);
                        break;
                    case '"':
                        $state['tw'] = self::num($operands[count($operands) - 3] ?? 0);
                        $state['tc'] = self::num($operands[count($operands) - 2] ?? 0);
                        $tm = $tlm = self::multiply([1.0, 0.0, 0.0, 1.0, 0.0, -$state['tl']], $tlm);
                        $tm = $this->show(end($operands), $state, $tm);
                        break;
                    case 'TJ':
                        $items = end($operands);
                        foreach (is_array($items) ? $items : [] as $item) {
                            if (is_int($item) || is_float($item)) {
                                $shift = -$item / 1000.0 * $state['size'] * $state['th'];
                                $tm = self::multiply([1.0, 0.0, 0.0, 1.0, $shift, 0.0], $tm);
                            } else {
                                $tm = $this->show($item, $state, $tm);
                            }
                        }
                        break;
                    case 'Do':
                        $name = end($operands);
                        if ($name instanceof PdfName && $depth < self::MAX_DEPTH) {
                            $this->form($resources, $name->value, $state['ctm'], $depth);
                        }
                        break;
                    case 'BI':
                        self::skipInlineImage($lexer);
                        break;
                }
                $operands = [];
            }
        } catch (Throwable) {
            // A broken stream: the text read so far stands.
        }
    }

    /**
     * @param array<string,mixed> $state
     * @param array{float,float,float,float,float,float} $tm
     * @return array{float,float,float,float,float,float}
     */
    private function show(mixed $string, array $state, array $tm): array
    {
        $font = $state['font'];
        if (!is_string($string) || !$font instanceof FontDecoder) {
            return $tm;
        }
        $size = (float) $state['size'];
        foreach ($font->decode($string) as [$text, $width, $isSpace]) {
            $trm = self::multiply(self::multiply([$size * $state['th'], 0.0, 0.0, $size, 0.0, $state['rise']], $tm), $state['ctm']);
            $advance = ($width / 1000.0 * $size + $state['tc'] + ($isSpace ? $state['tw'] : 0.0)) * $state['th'];
            $next = self::multiply([1.0, 0.0, 0.0, 1.0, $advance, 0.0], $tm);
            $end = self::multiply(self::multiply([1.0, 0.0, 0.0, 1.0, 0.0, $state['rise']], $next), $state['ctm']);
            $this->emit($text, $trm[4], $trm[5], $end[4], hypot($trm[2], $trm[3]));
            $tm = $next;
        }

        return $tm;
    }

    private function emit(string $text, float $x, float $y, float $endX, float $height): void
    {
        if ($text === '') {
            $this->lastEndX = $endX;

            return;
        }
        $height = $height > 0.0 ? $height : 10.0;
        if ($this->lastY !== null && $this->lastEndX !== null) {
            $lineGap = abs($y - $this->lastY);
            $reference = max($height, $this->lastHeight);
            if ($lineGap > 0.5 * $reference) {
                $this->out = rtrim($this->out, ' ').($lineGap > 2.0 * $reference ? "\n\n" : "\n");
            } elseif ($x - $this->lastEndX > 0.2 * $reference && !str_ends_with($this->out, ' ') && !str_starts_with($text, ' ')) {
                $this->out .= ' ';
            }
        }
        $this->out .= $text;
        $this->lastY = $y;
        $this->lastEndX = $endX;
        $this->lastHeight = $height;
    }

    private function font(?PdfDictionary $resources, string $name): ?FontDecoder
    {
        $fonts = $resources === null ? null : $this->doc->deref($resources->get('Font'));
        if (!$fonts instanceof PdfDictionary) {
            return null;
        }
        $raw = $fonts->get($name);
        $key = $raw instanceof PdfReference ? 'r'.$raw->number : 'o'.spl_object_id($fonts).'/'.$name;
        if (isset($this->fonts[$key])) {
            return $this->fonts[$key];
        }
        $dict = $this->doc->deref($raw);
        if (!$dict instanceof PdfDictionary) {
            return null;
        }

        return $this->fonts[$key] = FontDecoder::fromDictionary($this->doc, $dict);
    }

    /**
     * @param array{float,float,float,float,float,float} $ctm
     */
    private function form(?PdfDictionary $resources, string $name, array $ctm, int $depth): void
    {
        $xobjects = $resources === null ? null : $this->doc->deref($resources->get('XObject'));
        $form = $xobjects instanceof PdfDictionary ? $this->doc->deref($xobjects->get($name)) : null;
        if (!$form instanceof PdfStream) {
            return;
        }
        $subtype = $this->doc->deref($form->dict->get('Subtype'));
        if (!$subtype instanceof PdfName || $subtype->value !== 'Form') {
            return;
        }
        $matrix = $this->doc->deref($form->dict->get('Matrix'));
        $own = $this->doc->deref($form->dict->get('Resources'));
        try {
            $data = $this->doc->streamData($form);
        } catch (Throwable) {
            return;
        }
        $m = is_array($matrix) && count($matrix) === 6 ? self::matrix(array_map(fn (mixed $v): mixed => $this->doc->deref($v), array_values($matrix))) : [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $this->run($data, $own instanceof PdfDictionary ? $own : $resources, self::multiply($m, $ctm), $depth + 1);
    }

    /** An operand: a number, a name, a string's bytes, an array of them; a dictionary is skipped. */
    private function operand(Lexer $lexer, Token $token): mixed
    {
        return match ($token->type) {
            TokenType::Number => $token->value,
            TokenType::Name => new PdfName((string) $token->value),
            TokenType::Str, TokenType::HexStr => (string) $token->value,
            TokenType::ArrayOpen => $this->array($lexer),
            TokenType::DictOpen => self::skipDictionary($lexer),
            default => null,
        };
    }

    /** @return list<mixed> */
    private function array(Lexer $lexer): array
    {
        $items = [];
        while (($token = $lexer->nextToken())->type !== TokenType::ArrayClose) {
            if ($token->type === TokenType::Eof) {
                break;
            }
            $items[] = $this->operand($lexer, $token);
        }

        return $items;
    }

    private static function skipDictionary(Lexer $lexer): null
    {
        $depth = 1;
        while ($depth > 0 && ($token = $lexer->nextToken())->type !== TokenType::Eof) {
            $depth += match ($token->type) {
                TokenType::DictOpen => 1,
                TokenType::DictClose => -1,
                default => 0,
            };
        }

        return null;
    }

    /** `BI … ID <bytes> EI`: past the image data, which is not PDF syntax. */
    private static function skipInlineImage(Lexer $lexer): void
    {
        while (($token = $lexer->nextToken())->type !== TokenType::Eof) {
            if ($token->isKeyword('ID')) {
                break;
            }
        }
        $data = $lexer->data();
        if (preg_match('/\sEI(?=\s|$)/', $data, $m, PREG_OFFSET_CAPTURE, $lexer->position()) === 1) {
            $lexer->seek($m[0][1] + strlen($m[0][0]));
        } else {
            $lexer->seek(strlen($data));
        }
    }

    private static function tidy(string $text): string
    {
        $lines = array_map(static fn (string $l): string => rtrim($l), explode("\n", $text));

        $text = strtr(implode("\n", $lines), self::LIGATURES);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private static function num(mixed $value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }

    /**
     * @param list<mixed> $values
     * @return array{float,float,float,float,float,float}
     */
    private static function matrix(array $values): array
    {
        $v = array_map(self::num(...), array_values($values));

        return [$v[0] ?? 1.0, $v[1] ?? 0.0, $v[2] ?? 0.0, $v[3] ?? 1.0, $v[4] ?? 0.0, $v[5] ?? 0.0];
    }

    /**
     * @param array{float,float,float,float,float,float} $m1
     * @param array{float,float,float,float,float,float} $m2
     * @return array{float,float,float,float,float,float}
     */
    private static function multiply(array $m1, array $m2): array
    {
        return [
            $m1[0] * $m2[0] + $m1[1] * $m2[2],
            $m1[0] * $m2[1] + $m1[1] * $m2[3],
            $m1[2] * $m2[0] + $m1[3] * $m2[2],
            $m1[2] * $m2[1] + $m1[3] * $m2[3],
            $m1[4] * $m2[0] + $m1[5] * $m2[2] + $m2[4],
            $m1[4] * $m2[1] + $m1[5] * $m2[3] + $m2[5],
        ];
    }
}
