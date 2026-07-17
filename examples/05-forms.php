<?php

declare(strict_types=1);

// Fillable AcroForm fields: text, checkbox, multiline.

require __DIR__.'/bootstrap.php';

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\FormField;
use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Section;

$doc = new Document(new Section([
    new Heading(1, [new Run('Registration form')]),
    new Paragraph([new Run('Full name:')]),
    new FormField('name', FormField::TYPE_TEXT, defaultValue: 'Jane Roe'),
    new Paragraph([new Run('Subscribe to the newsletter:')]),
    new FormField('subscribe', FormField::TYPE_CHECKBOX),
    new Paragraph([new Run('Notes:')]),
    new FormField('notes', FormField::TYPE_TEXT_MULTILINE, heightPt: 70),
]));

save_sample('05-forms.pdf', $doc->toBytes());
