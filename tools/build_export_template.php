<?php
/**
 * build_export_template.php
 * -------------------------------------------------------
 * One-off maintenance script.
 *
 * The original workbook is beautiful but slow to parse: the
 * data sheets carry tens of thousands of styled rows and live
 * formulas that reference Excel tables. That makes every
 * export take about a minute and makes PhpSpreadsheet choke
 * on the structured references.
 *
 * This script produces a compact runtime template:
 *   • every formula is frozen to the value Excel cached for it
 *   • the data sheets are trimmed to a practical number of rows
 *   • the Excel table ranges are shrunk to match
 *   • the three DASHBOARD charts are kept
 *
 * Run it once after replacing the reference workbook:
 *   /opt/lampp/bin/php tools/build_export_template.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Calculation\Information\ErrorValue;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

$source = $argv[1] ?? __DIR__ . '/../resources/templates/Weekly_Loan_Management_System.xlsx';
$target = $argv[2] ?? __DIR__ . '/../resources/templates/lms_export_template.xlsx';

/** Data rows kept in the runtime template, per sheet. */
const KEEP_ROWS = [
    'CUSTOMERS'     => 60,   // data starts at row 4
    'LOANS'         => 60,   // data starts at row 4
    'PAYMENTS'      => 60,   // data starts at row 4
    'OVERDUE LOANS' => 60,   // data starts at row 5
];

const FIRST_DATA_ROW = [
    'CUSTOMERS'     => 4,
    'LOANS'         => 4,
    'PAYMENTS'      => 4,
    'OVERDUE LOANS' => 5,
];

const LAST_COLUMN = [
    'CUSTOMERS'     => 'G',
    'LOANS'         => 'S',
    'PAYMENTS'      => 'N',
    'OVERDUE LOANS' => 'L',
];

const TABLE_FOR_SHEET = [
    'CUSTOMERS' => 'tblCustomers',
    'LOANS'     => 'tblLoans',
    'PAYMENTS'  => 'tblPayments',
];

$started = microtime(true);
$log = static function (string $message) use (&$started): void {
    fwrite(STDOUT, sprintf("[%6.2fs] %s\n", microtime(true) - $started, $message));
};

$log('Reading ' . $source);

$reader = IOFactory::createReader('Xlsx');
$reader->setIncludeCharts(true);
$reader->setReadDataOnly(false);
$workbook = $reader->load($source);

$log('Loaded ' . $workbook->getSheetCount() . ' sheets');

// ------------------------------------------------------------------
// 1. Freeze every remaining formula to its cached value.
// ------------------------------------------------------------------
$formulas = 0;

foreach ($workbook->getAllSheets() as $worksheet) {
    foreach ($worksheet->getCellCollection()->getCoordinates() as $coordinate) {
        $cell = $worksheet->getCell($coordinate);

        if ($cell->getDataType() !== DataType::TYPE_FORMULA) {
            continue;
        }

        $formulas++;

        try {
            $value = $cell->getOldCalculatedValue();
        } catch (Throwable $e) {
            $value = null;
        }

        if ($value === null || $value === '' || (is_string($value) && ErrorValue::isError($value))) {
            $worksheet->setCellValue($coordinate, null);
        } elseif (is_bool($value)) {
            $worksheet->setCellValue($coordinate, $value);
        } elseif (is_numeric($value)) {
            $worksheet->setCellValue($coordinate, (float) $value);
        } else {
            $worksheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
        }
    }
}

$log('Froze ' . $formulas . ' formulas');

// ------------------------------------------------------------------
// 2. Trim the data sheets and shrink their table ranges.
// ------------------------------------------------------------------
foreach (KEEP_ROWS as $sheetName => $keep) {
    $worksheet = $workbook->getSheetByName($sheetName);

    if ($worksheet === null) {
        $log('WARNING: sheet not found: ' . $sheetName);
        continue;
    }

    $firstRow  = FIRST_DATA_ROW[$sheetName];
    $lastRow   = $firstRow + $keep - 1;
    $highest   = $worksheet->getHighestDataRow();

    if ($highest > $lastRow) {
        $worksheet->removeRow($lastRow + 1, $highest - $lastRow);
        $log(sprintf('%s: removed rows %d-%d', $sheetName, $lastRow + 1, $highest));
    }

    $tableName = TABLE_FOR_SHEET[$sheetName] ?? null;

    if ($tableName !== null) {
        foreach ($worksheet->getTableCollection() as $table) {
            if ($table->getName() !== $tableName) {
                continue;
            }

            $table->setRange('A3:' . LAST_COLUMN[$sheetName] . $lastRow);
            $log(sprintf('%s: table %s range set to A3:%s%d', $sheetName, $tableName, LAST_COLUMN[$sheetName], $lastRow));
        }
    }
}

// ------------------------------------------------------------------
// 3. Save the compact template, charts included.
// ------------------------------------------------------------------
$writer = IOFactory::createWriter($workbook, 'Xlsx');
$writer->setIncludeCharts(true);
$writer->setPreCalculateFormulas(false);
$writer->save($target);

$log('Wrote ' . $target . ' (' . number_format(filesize($target)) . ' bytes)');
$log('Done.');
