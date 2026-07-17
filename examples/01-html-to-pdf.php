<?php

declare(strict_types=1);

// HTML → PDF: the one-liner path. Parses an HTML5/inline-CSS subset —
// headings, paragraphs, tables, lists, inline styles.

require __DIR__.'/bootstrap.php';

use Dskripchenko\PhpPdf\Document;

$doc = Document::fromHtml(<<<'HTML'
    <h1>Invoice #1234</h1>
    <p>Customer: <strong>Acme Corp</strong> — due <u>2026-08-01</u></p>
    <table>
      <thead><tr><th>Item</th><th>Qty</th><th>Price</th></tr></thead>
      <tbody>
        <tr><td>Widget</td><td>2</td><td>$10.00</td></tr>
        <tr><td>Gadget</td><td>1</td><td>$25.00</td></tr>
      </tbody>
    </table>
    <p style="text-align: right"><strong>Total: $45.00</strong></p>
    HTML, metadata: ['Title' => 'Invoice #1234']);

save_sample('01-html-to-pdf.pdf', $doc->toBytes());
