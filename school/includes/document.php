<?php
declare(strict_types=1);

use School\Core\SchoolDatabase;

/**
 * Shared HTML document shell for server-rendered PDFs.
 *
 * The markup is intentionally self-contained: all CSS is inlined and images
 * are embedded as data URIs so headless Chrome and WeasyPrint can render the
 * same document without network access.
 */

function school_document_brand(): array
{
    static $brand = null;
    if ($brand !== null) {
        return $brand;
    }
    try {
        $row = SchoolDatabase::connection()
            ->query('SELECT name,motto,logo_path,address,phone,email,website,currency FROM school_profile ORDER BY id LIMIT 1')
            ->fetch();
    } catch (Throwable $exception) {
        $row = null;
    }
    $brand = $row ?: [
        'name' => 'School Management', 'motto' => null, 'logo_path' => null,
        'address' => null, 'phone' => null, 'email' => null, 'website' => null, 'currency' => 'TZS',
    ];
    return $brand;
}

function school_logo_data_uri(?string $path): ?string
{
    if (!$path) {
        return null;
    }
    $root = dirname(__DIR__, 2);
    $candidates = [$path, $root . '/' . ltrim($path, '/'), $root . '/public/' . ltrim($path, '/')];
    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $mime = match (strtolower(pathinfo($candidate, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => null,
        };
        if ($mime === null) {
            continue;
        }
        $bytes = @file_get_contents($candidate);
        if ($bytes !== false && $bytes !== '') {
            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }
    }
    return null;
}

function school_document_styles(): string
{
    return <<<'CSS'
@page { size: A4; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
  font-family: "DejaVu Sans", Arial, Helvetica, sans-serif;
  color: #1f2a27; font-size: 11.5px; line-height: 1.45;
  background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.sheet {
  width: 210mm; min-height: 297mm; margin: 0 auto; padding: 14mm 14mm 16mm;
  position: relative; background: #fff; overflow: hidden;
}
.sheet.landscape { width: 297mm; min-height: 210mm; }
.top-rule { position: absolute; top: 0; left: 0; right: 0; height: 7px;
  background: linear-gradient(90deg, #075c47 0 76%, #c9a447 76%); }
.doc-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px;
  border-bottom: 1.5px solid #dce5e1; padding-bottom: 12px; }
.brand { display: flex; gap: 12px; align-items: flex-start; }
.brand img { width: 52px; height: 52px; object-fit: contain; }
.brand h1 { margin: 0; color: #063328; font-size: 20px; letter-spacing: .2px; }
.brand .motto { color: #64716d; font-style: italic; font-size: 10.5px; margin-top: 2px; }
.contact { text-align: right; color: #64716d; font-size: 9.5px; line-height: 1.5; white-space: pre-line; max-width: 80mm; }
.doc-title-row { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; margin: 16px 0 12px; }
.doc-title-row h2 { margin: 0; color: #075c47; font-size: 15px; letter-spacing: 1.4px; text-transform: uppercase; }
.doc-title-row .subtitle { color: #64716d; font-size: 10px; margin-top: 3px; }
.doc-number { text-align: right; color: #64716d; font-size: 9.5px; }
.doc-number strong { display: block; color: #1f2a27; font-size: 11px; }
.meta-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0;
  border: 1px solid #dce5e1; border-radius: 7px; overflow: hidden; background: #f7faf8; margin-bottom: 14px; }
.meta-grid .meta { padding: 7px 10px; border-right: 1px solid #dce5e1; border-bottom: 1px solid #dce5e1; }
.meta-grid .meta:nth-child(3n) { border-right: 0; }
.meta-grid .meta:nth-last-child(-n+3) { border-bottom: 0; }
.meta .label { display: block; color: #64716d; font-size: 8px; letter-spacing: .7px; text-transform: uppercase; margin-bottom: 2px; }
.meta .value { font-weight: 600; font-size: 10.5px; }
h3.section { color: #063328; font-size: 10.5px; text-transform: uppercase; letter-spacing: 1px; margin: 14px 0 6px; }
table.data { width: 100%; border-collapse: collapse; font-size: 10px; }
table.data th { background: #075c47; color: #fff; padding: 7px 8px; text-align: left; font-size: 8.5px;
  text-transform: uppercase; letter-spacing: .5px; font-weight: 700; }
table.data td { padding: 6px 8px; border-bottom: 1px solid #e4ebe7; vertical-align: top; }
table.data tbody tr:nth-child(even) { background: #fafcfb; }
table.data .num { text-align: right; font-variant-numeric: tabular-nums; }
table.data .center { text-align: center; }
table.data tfoot td { font-weight: 700; border-top: 1.5px solid #c9d5cf; border-bottom: 0; }
.badge { display: inline-block; padding: 1px 6px; border-radius: 999px; background: #eef4f0; color: #075c47;
  font-size: 8.5px; font-weight: 700; }
.badge.warn { background: #fdf3e2; color: #a2650a; }
.badge.danger { background: #fbe9e7; color: #a93226; }
.summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; margin: 12px 0; }
.summary-box { border: 1px solid #dce5e1; border-radius: 7px; padding: 8px 10px; background: #fbfdfc; }
.summary-box .k { color: #64716d; font-size: 8px; text-transform: uppercase; letter-spacing: .6px; }
.summary-box .v { font-size: 15px; font-weight: 700; color: #063328; margin-top: 2px; font-variant-numeric: tabular-nums; }
.summary-box .h { color: #64716d; font-size: 8.5px; margin-top: 1px; }
.totals { width: 70mm; margin-left: auto; margin-top: 10px; border: 1px solid #dce5e1; border-radius: 7px; overflow: hidden; }
.totals .row { display: flex; justify-content: space-between; padding: 5px 10px; border-bottom: 1px solid #eef3f0; font-size: 10px; }
.totals .row:last-child { border-bottom: 0; }
.totals .row.grand { background: #f2f7f4; font-weight: 700; font-size: 12px; color: #063328; }
.note { margin-top: 14px; padding: 9px 11px; background: #fcfaf4; border-left: 3px solid #c9a447;
  color: #58625f; font-size: 9.5px; }
.signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin: 36px 8px 0; }
.signatures .sign { border-top: 1px solid #8a9691; padding-top: 5px; color: #64716d; font-size: 9px; }
.doc-footer { position: absolute; bottom: 9mm; left: 14mm; right: 14mm; border-top: 1px solid #dce5e1;
  padding-top: 5px; display: flex; justify-content: space-between; gap: 10px; color: #7a8c85; font-size: 8px; }
.watermark { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
  color: #075c47; opacity: .05; font-size: 74px; font-weight: 800; transform: rotate(-28deg);
  letter-spacing: 8px; text-transform: uppercase; pointer-events: none; }
.page-break { page-break-before: always; }
.avoid-break { page-break-inside: avoid; }
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.small { font-size: 9px; color: #64716d; }
.right { text-align: right; }
.center { text-align: center; }
.strong { font-weight: 700; }
.id-card { width: 86mm; height: 54mm; border: 1px solid #c9d5cf; border-radius: 8px; overflow: hidden;
  position: relative; background: #fff; margin-bottom: 8mm; }
.id-card .stripe { height: 5px; background: linear-gradient(90deg, #075c47 0 76%, #c9a447 76%); }
.id-card .body { padding: 8px 11px; }
.id-card .school { font-size: 10px; font-weight: 700; color: #063328; text-transform: uppercase; letter-spacing: .4px; }
.id-card .name { font-size: 15px; font-weight: 700; margin-top: 4px; color: #1f2a27; }
.id-card .meta-line { font-size: 9px; color: #64716d; margin-top: 2px; }
.id-card .foot { position: absolute; bottom: 7px; left: 11px; right: 11px; display: flex;
  justify-content: space-between; font-size: 8px; color: #64716d; border-top: 1px solid #eef3f0; padding-top: 4px; }
@media print {
  body { background: #fff; }
  .sheet { margin: 0; box-shadow: none; }
}
CSS;
}

/** Build one branded A4 sheet. */
function school_document_sheet(string $body, array $options = []): string
{
    $brand = school_document_brand();
    $title = (string) ($options['title'] ?? 'School document');
    $subtitle = (string) ($options['subtitle'] ?? '');
    $documentNo = (string) ($options['document_no'] ?? '');
    $orientation = ($options['orientation'] ?? 'portrait') === 'landscape' ? ' landscape' : '';
    $footerNote = (string) ($options['footer_note'] ?? 'This document was generated from official school records.');
    $watermark = (string) ($options['watermark'] ?? '');
    $logo = school_logo_data_uri($brand['logo_path'] ?? null);
    $contact = implode("\n", array_filter([
        $brand['address'] ?? null,
        $brand['phone'] ?? null,
        $brand['email'] ?? null,
        $brand['website'] ?? null,
    ]));
    $signatures = $options['signatures'] ?? ['Class teacher', 'Head teacher'];
    $signatureHtml = '';
    if ($signatures !== []) {
        $signatureHtml = '<div class="signatures">';
        foreach ($signatures as $signature) {
            $signatureHtml .= '<div class="sign">' . e((string) $signature) . '</div>';
        }
        $signatureHtml .= '</div>';
    }

    return '<div class="sheet' . $orientation . '">'
        . '<div class="top-rule"></div>'
        . ($watermark !== '' ? '<div class="watermark">' . e($watermark) . '</div>' : '')
        . '<header class="doc-head"><div class="brand">'
        . ($logo ? '<img src="' . e($logo) . '" alt="">' : '')
        . '<div><h1>' . e((string) ($brand['name'] ?? 'School Management')) . '</h1>'
        . (!empty($brand['motto']) ? '<div class="motto">' . e((string) $brand['motto']) . '</div>' : '')
        . '</div></div><div class="contact">' . e($contact) . '</div></header>'
        . '<div class="doc-title-row"><div><h2>' . e($title) . '</h2>'
        . ($subtitle !== '' ? '<div class="subtitle">' . e($subtitle) . '</div>' : '')
        . '</div>' . ($documentNo !== '' ? '<div class="doc-number">Document no.<strong>' . e($documentNo) . '</strong></div>' : '') . '</div>'
        . $body
        . $signatureHtml
        . '<footer class="doc-footer"><span>' . e((string) ($brand['name'] ?? 'School Management')) . '</span>'
        . '<span>' . e($footerNote) . ' · ' . e(date('d/m/Y H:i')) . '</span></footer>'
        . '</div>';
}

/**
 * Wrap body markup in the branded A4 shell.
 *
 * @param array{title?:string,subtitle?:string,document_no?:string,orientation?:string,
 *              footer_note?:string,signatures?:array<int,string>,watermark?:string} $options
 */
function school_document_shell(string $body, array $options = []): string
{
    $brand = school_document_brand();
    $title = (string) ($options['title'] ?? 'School document');
    $orientation = ($options['orientation'] ?? 'portrait') === 'landscape';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' | ' . e((string) ($brand['name'] ?? 'School')) . '</title>'
        . '<style>' . school_document_styles() . ($orientation ? '@page { size: A4 landscape; margin: 0; }' : '') . '</style>'
        . '</head><body>' . school_document_sheet($body, $options) . '</body></html>';
}

/**
 * Combine several documents into one PDF (one sheet per document).
 *
 * @param array<int,array{body:string,title?:string,subtitle?:string,document_no?:string,signatures?:array<int,string>}> $documents
 */
function school_document_bundle(array $documents, array $options = []): string
{
    $brand = school_document_brand();
    $orientation = ($options['orientation'] ?? 'portrait') === 'landscape';
    $sheets = '';
    foreach ($documents as $index => $document) {
        $sheetOptions = array_merge($options, $document);
        unset($sheetOptions['body']);
        $sheet = school_document_sheet((string) ($document['body'] ?? ''), $sheetOptions);
        if ($index < count($documents) - 1) {
            $sheet = str_replace('<div class="sheet', '<div style="page-break-after:always" class="sheet', $sheet);
        }
        $sheets .= $sheet;
    }
    $title = (string) ($options['title'] ?? 'School documents');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' | ' . e((string) ($brand['name'] ?? 'School')) . '</title>'
        . '<style>' . school_document_styles() . ($orientation ? '@page { size: A4 landscape; margin: 0; }' : '') . '</style>'
        . '</head><body>' . $sheets . '</body></html>';
}

/** Metadata strip used by most documents. */
function school_document_meta(array $items): string
{
    $html = '<div class="meta-grid">';
    foreach ($items as $label => $value) {
        $html .= '<div class="meta"><span class="label">' . e((string) $label) . '</span><span class="value">'
            . e($value === null || $value === '' ? '—' : (string) $value) . '</span></div>';
    }
    return $html . '</div>';
}

/** KPI summary strip. */
function school_document_summary(array $items): string
{
    $html = '<div class="summary-grid">';
    foreach ($items as $item) {
        $html .= '<div class="summary-box"><div class="k">' . e((string) ($item['label'] ?? '')) . '</div>'
            . '<div class="v">' . e((string) ($item['value'] ?? '')) . '</div>'
            . (!empty($item['hint']) ? '<div class="h">' . e((string) $item['hint']) . '</div>' : '')
            . '</div>';
    }
    return $html . '</div>';
}
