# Миграция с FPDI

У `setasign/fpdi` есть подвох, который все обнаруживают в худший момент:
бесплатная версия не читает PDF со сжатыми таблицами перекрёстных ссылок
(xref streams) — а это **формат по умолчанию** у большинства современных
генераторов PDF (1.5+). Как только клиент загружает такой файл, нужен
коммерческий парсер-аддон FPDI. `dskripchenko/php-pdf` под MIT читает то,
что бесплатному FPDI недоступно:

- классический xref **и xref streams**, object streams, гибридные файлы;
- **зашифрованный вход** — RC4, AES-128, AES-256 (R5/R6), пользовательский
  и владельческий пароли;
- **восстановление битого xref** сканированием заголовков объектов;
- проверено на стороннем корпусе: pdfTeX, LibreOffice, Google Docs/Skia,
  Qt/pdfkit, Ghostscript, ImageMagick, FPDF2, pypdf.

## Маршрут 1 — compat-фасад

Классический FPDI-флоу маппится один-в-один:

```php
// было                                      // стало
use setasign\Fpdi\Fpdi;                      use Dskripchenko\PhpPdf\Compat\Fpdi;

$pdf = new Fpdi();                           $pdf = new Fpdi();
$count = $pdf->setSourceFile('in.pdf');      $count = $pdf->setSourceFile('in.pdf');
$tpl = $pdf->importPage(1);                  $tpl = $pdf->importPage(1);
$pdf->AddPage('', $pdf->getTemplateSize($tpl));
$pdf->useTemplate($tpl, x: 0, y: 0);         // тот же вызов
$pdf->Output('F', 'out.pdf');                // тот же вызов (оба порядка аргументов)
```

Координаты — конвенции FPDF: начало в верхнем левом углу, y вниз,
пользовательские единицы (мм по умолчанию; `pt`, `cm`, `in` в
конструкторе). `useTemplate()` масштабирует пропорционально при передаче
только `width` или только `height` — как FPDI.

Вместо рисовального API FPDF (`Cell()`/`SetFont()`) фасад отдаёт нативные
объекты: `$pdf->page()` — `Pdf\Page` (текст, картинки, поля),
`$pdf->document()` — `Pdf\Document` (шифрование, подпись, метаданные):

```php
$pdf->page()->showText('Копия — не оригинал', 40, 40,
    \Dskripchenko\PhpPdf\Pdf\StandardFont::Helvetica, 9);
```

## Маршрут 2 — нативный API

| FPDI | php-pdf |
|---|---|
| `$pdf->setSourceFile($f)` | `$src = ReaderDocument::fromBytes(file_get_contents($f))` (опциональный пароль) |
| *(число страниц — возврат)* | `count($src->pages())` |
| `$tpl = $pdf->importPage($n)` | `$form = PageImporter::intoDocument($doc, $src, $n - 1)` — индекс 0-based |
| `$pdf->useTemplate($tpl, $x, $y, $w, $h)` | `$page->useFormXObject($form, $x, $y, $w, $h)` — PDF-координаты: нижний левый угол, пункты |
| `$pdf->getTemplateSize($tpl)` | `$form->bboxWidth()` / `$form->bboxHeight()` |
| цикл склейки файлов | `PdfMerger::create()->append(PdfSource::fromFile($a))->append(...)->toBytes()` |
| цикл штамповки/водяных знаков | `PdfMerger::create()->append($src)->stamp(PdfSource::fromFile($stamp), placement: Placement::fit())` |
| зашифрованный источник *(без аддона недоступен)* | `PdfSource::fromFile($f, password: '...')` / `ReaderDocument::fromBytes($bytes, '...')` |
| источники с xref-stream *(коммерческий парсер)* | поддерживаются из коробки |

**Какой нативный инструмент когда:**

- `PdfMerger` — склейка/переупорядочивание целых документов. Переносит
  аннотации, закладки и именованные назначения (FPDI их отбрасывает).
- `PageImporter` — FPDI-стиль: импортированная страница как XObject внутри
  свежесгенерированного документа, можно рисовать поверх и под ней.

## Гочи

- **Координаты**: фасад сохраняет верхний-левый/мм конвенции FPDF;
  нативный API — PDF-нативный (нижний-левый, пункты). Пересчёт:
  `y_pdf = высотаСтраницы − y_мм × 72/25.4 − высота_pt`.
- **Индексация страниц**: `importPage()` FPDI — 1-based; нативный
  `PageImporter::intoDocument()` — 0-based. Фасад остаётся 1-based.
- **Аннотации**: как и FPDI, `importPage`/`PageImporter` переносит только
  *контент* страницы. Нужны ссылки/закладки — используйте `PdfMerger`.
- **`adjustPageSize`**: вместо флага FPDI передайте размер шаблона в
  `AddPage('', $pdf->getTemplateSize($tpl))` — явно и эквивалентно.

Маппинги выше покрыты `tests/Compat/FpdiCompatTest.php`.

---

Язык: [English](../en/MIGRATION-FROM-FPDI.md) · [Русский](MIGRATION-FROM-FPDI.md) · [中文](../zh/MIGRATION-FROM-FPDI.md) · [Deutsch](../de/MIGRATION-FROM-FPDI.md)
