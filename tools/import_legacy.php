<?php
/**
 * tools/import_legacy.php — import the workbook into the LMS (CLI).
 *
 *   /opt/lampp/bin/php tools/import_legacy.php [path/to/workbook.xlsx] [--force]
 *
 * Behaviour agreed with the business:
 *   • repeat borrowers merged into one customer (40 real people)
 *   • every workbook customer key kept in customer_legacy_refs
 *   • customers flagged "incomplete" where NIDA / phone / photo is missing
 *   • zero-amount placeholder payment rows skipped
 *   • original PMT-/LN-/CUST- numbers preserved as legacy references
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This importer can only be run from the command line.');
}

require_once __DIR__ . '/../includes/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

/** Convert a PhpSpreadsheet cell into Y-m-d, or null. */
function cellDate($value): ?string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    if (is_numeric($value) && (float) $value > 1000) {
        try {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }

    if (is_string($value) && trim($value) !== '') {
        $ts = strtotime(trim($value));
        return $ts ? date('Y-m-d', $ts) : null;
    }

    return null;
}

function cleanName($value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
}

/** Merge key: uppercase, collapse spaces, drop the " 2 / 2A / 2B" suffix. */
function mergeKey(string $name): string
{
    $key = strtoupper(cleanName($name));
    $key = preg_replace('/\s+2[AB]?$/', '', $key) ?? $key;

    // Known spelling variants in the LOANS sheet.
    $key = str_replace('ROSE ALODI MAHINYA', 'ROSE ANOLDI MAHINYA', $key);
    $key = str_replace('ROSE ANOLDI MAHINYILA', 'ROSE ANOLDI MAHINYA', $key);

    return trim($key);
}

function splitName(string $full): array
{
    $parts = preg_split('/\s+/', cleanName($full)) ?: [];

    if (count($parts) === 0) {
        return ['', '', ''];
    }

    if (count($parts) === 1) {
        return [$parts[0], '', ''];
    }

    if (count($parts) === 2) {
        return [$parts[0], '', $parts[1]];
    }

    $first  = array_shift($parts);
    $last   = array_pop($parts);
    $middle = implode(' ', $parts);

    return [$first, $middle, $last];
}

$args      = array_slice($argv, 1);
$force     = in_array('--force', $args, true);
$path      = null;

foreach ($args as $arg) {
    if ($arg !== '--force') {
        $path = $arg;
        break;
    }
}

$path = $path ?: '/home/shock/Downloads/Weekly_Loan_Management_System.xlsx';

out('──────────────────────────────────────────────');
out(' Legacy workbook import');
out('──────────────────────────────────────────────');

if (!is_file($path)) {
    die('✖ Workbook not found: ' . $path . PHP_EOL);
}

$db = Database::getConnection();
$adminId = (int) $db->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();

if ($adminId <= 0) {
    die('✖ Run tools/install.php first (no admin account found).' . PHP_EOL);
}

$existingCustomers = (int) $db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$existingLoans     = (int) $db->query("SELECT COUNT(*) FROM loans")->fetchColumn();

if (($existingCustomers > 0 || $existingLoans > 0) && !$force) {
    die('✖ The database already contains data. Re-run with --force to wipe and re-import.' . PHP_EOL);
}

out('… Reading ' . basename($path));

$reader = IOFactory::createReaderForFile($path);
$reader->setReadDataOnly(true);
$book = $reader->load($path);

$customersSheet = $book->getSheetByName('CUSTOMERS');
$loansSheet     = $book->getSheetByName('LOANS');
$paymentsSheet  = $book->getSheetByName('PAYMENTS');

if (!$customersSheet || !$loansSheet || !$paymentsSheet) {
    die('✖ The workbook is missing CUSTOMERS, LOANS or PAYMENTS.' . PHP_EOL);
}

// ---------------------------------------------------------------- read rows
$customerRows = [];

for ($row = 4; $row <= $customersSheet->getHighestRow(); $row++) {
    $name = cleanName($customersSheet->getCell('B' . $row)->getValue());

    if ($name === '') {
        continue;
    }

    $customerRows[] = [
        'row'      => $row,
        'legacy'   => 'CUST-' . str_pad((string) ($row - 3), 4, '0', STR_PAD_LEFT),
        'name'     => $name,
        'phone'    => trim((string) $customersSheet->getCell('C' . $row)->getValue()),
        'emergency'=> trim((string) $customersSheet->getCell('D' . $row)->getValue()),
        'em_phone' => trim((string) $customersSheet->getCell('E' . $row)->getValue()),
        'reg_date' => cellDate($customersSheet->getCell('F' . $row)->getValue()) ?? date('Y-m-d'),
        'notes'    => trim((string) $customersSheet->getCell('G' . $row)->getValue()),
    ];
}

$loanRows = [];

for ($row = 4; $row <= $loansSheet->getHighestRow(); $row++) {
    $name = cleanName($loansSheet->getCell('C' . $row)->getValue());

    if ($name === '') {
        continue;
    }

    $loanRows[] = [
        'row'       => $row,
        'legacy'    => 'LN-' . str_pad((string) ($row - 3), 4, '0', STR_PAD_LEFT),
        'name'      => $name,
        'loan_date' => cellDate($loansSheet->getCell('D' . $row)->getValue()),
        'principal' => (float) $loansSheet->getCell('E' . $row)->getValue(),
        'weekly'    => (float) $loansSheet->getCell('F' . $row)->getValue(),
        'weeks'     => (int) $loansSheet->getCell('G' . $row)->getValue(),
    ];
}

$paymentRows = [];

for ($row = 4; $row <= $paymentsSheet->getHighestRow(); $row++) {
    $loanRef = trim((string) $paymentsSheet->getCell('C' . $row)->getValue());

    if ($loanRef === '') {
        continue;
    }

    $amount = (float) $paymentsSheet->getCell('H' . $row)->getValue();
    $date   = cellDate($paymentsSheet->getCell('F' . $row)->getValue());

    $paymentRows[] = [
        'row'    => $row,
        'legacy' => 'PMT-' . str_pad((string) ($row - 3), 4, '0', STR_PAD_LEFT),
        'loan'   => $loanRef,
        'date'   => $date,
        'amount' => $amount,
        'skip'   => $amount <= 0 || $date === null,
    ];
}

out(sprintf('… Workbook: %d customer rows, %d loans, %d payment rows (%d empty)',
    count($customerRows), count($loanRows), count($paymentRows),
    count(array_filter($paymentRows, fn ($r) => $r['skip']))));

// ---------------------------------------------------------------- wipe
if ($force) {
    out('… Wiping existing loan data');

    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'payment_allocations', 'payment_reversals', 'payments', 'repayment_schedules',
        'disbursements', 'collection_activities', 'customer_assignments',
        'customer_legacy_refs', 'loans', 'customers',
    ] as $table) {
        $db->exec("TRUNCATE TABLE {$table}");
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
}

// ---------------------------------------------------------------- customers
$groups = [];

foreach ($customerRows as $row) {
    $key = mergeKey($row['name']);

    if (!isset($groups[$key])) {
        $groups[$key] = [
            'name'     => $row['name'],
            'phone'    => '',
            'emergency'=> '',
            'em_phone' => '',
            'reg_date' => $row['reg_date'],
            'notes'    => $row['notes'],
            'legacy'   => [],
        ];
    }

    if ($groups[$key]['phone'] === '' && $row['phone'] !== '') {
        $groups[$key]['phone'] = $row['phone'];
    }

    if ($groups[$key]['em_phone'] === '' && $row['em_phone'] !== '') {
        $groups[$key]['em_phone'] = $row['em_phone'];
        $groups[$key]['emergency'] = $row['emergency'];
    }

    if ($row['reg_date'] < $groups[$key]['reg_date']) {
        $groups[$key]['reg_date'] = $row['reg_date'];
    }

    $groups[$key]['legacy'][] = ['ref' => $row['legacy'], 'name' => $row['name']];
}

out('… Merged ' . count($customerRows) . ' workbook customers into ' . count($groups) . ' people');

$db->beginTransaction();

try {
    $customerIds = [];
    $sequence    = 0;

    $insertCustomer = $db->prepare(
        "INSERT INTO customers
            (reference, first_name, middle_name, last_name, full_name, phone, emergency_contact,
             emergency_phone, notes, data_status, status, registered_by, assigned_officer_id, registered_at)
         VALUES
            (:reference, :first_name, :middle_name, :last_name, :full_name, :phone, :emergency,
             :emergency_phone, :notes, :data_status, 'active', :registered_by, :officer_id, :registered_at)"
    );

    $insertLegacy = $db->prepare(
        "INSERT INTO customer_legacy_refs (customer_id, legacy_reference, legacy_name, source)
         VALUES (:customer_id, :legacy_reference, :legacy_name, 'workbook')"
    );

    foreach ($groups as $key => $group) {
        $sequence++;
        $reference = 'CUST-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        [$first, $middle, $last] = splitName($group['name']);

        $phone = $group['phone'] !== '' ? normalisePhone($group['phone']) : null;
        $complete = $phone !== null && isValidTzPhone($phone);

        $insertCustomer->execute([
            'reference'      => $reference,
            'first_name'     => $first,
            'middle_name'    => $middle,
            'last_name'      => $last,
            'full_name'      => $group['name'],
            'phone'          => $phone,
            'emergency'      => $group['emergency'] ?: null,
            'emergency_phone'=> $group['em_phone'] !== '' ? normalisePhone($group['em_phone']) : null,
            'notes'          => $complete
                ? ($group['notes'] ?: null)
                : trim('Imported from workbook. ' . ($group['notes'] ?? '') . ' NIDA number and passport photo pending.'),
            'data_status'    => 'incomplete',   // none of the workbook rows carry NIDA or a photo
            'registered_by'  => $adminId,
            'officer_id'     => $adminId,
            'registered_at'  => $group['reg_date'] . ' 00:00:00',
        ]);

        $customerId = (int) $db->lastInsertId();
        $customerIds[$reference] = $customerId;
        $customerIds['key:' . $key] = $customerId;

        foreach ($group['legacy'] as $legacy) {
            $insertLegacy->execute([
                'customer_id'      => $customerId,
                'legacy_reference' => $legacy['ref'],
                'legacy_name'      => $legacy['name'],
            ]);
        }

        $db->prepare(
            "INSERT INTO customer_assignments (customer_id, officer_id, assigned_by)
             VALUES (:c, :o, :b)"
        )->execute(['c' => $customerId, 'o' => $adminId, 'b' => $adminId]);
    }

    out('✔ ' . $sequence . ' customers created');

    // ------------------------------------------------------------ loans
    $insertLoan = $db->prepare(
        "INSERT INTO loans
            (reference, legacy_reference, customer_id, principal, weekly_amount, number_of_weeks,
             total_payable, loan_date, first_payment_date, final_payment_date, disbursed_at, created_by)
         VALUES
            (:reference, :legacy, :customer_id, :principal, :weekly, :weeks,
             :total, :loan_date, :first_payment, :final_payment, :disbursed_at, :created_by)"
    );

    $insertDisbursement = $db->prepare(
        "INSERT INTO disbursements (loan_id, amount, method, disbursed_by, disbursed_at)
         VALUES (:loan_id, :amount, 'cash', :user_id, :disbursed_at)"
    );

    $schedule = new RepaymentSchedule();
    $loanIds  = [];
    $missing  = [];

    foreach ($loanRows as $row) {
        $key = mergeKey($row['name']);
        $customerId = $customerIds['key:' . $key] ?? null;

        if ($customerId === null) {
            $missing[] = $row['legacy'] . ' → ' . $row['name'];
            continue;
        }

        $loanDate = $row['loan_date'] ?? date('Y-m-d');
        $weeks    = $row['weeks'] ?: 12;
        $weekly   = $row['weekly'];
        $first    = addDays($loanDate, 7);
        $final    = addDays($first, ($weeks - 1) * 7);

        $insertLoan->execute([
            'reference'     => $row['legacy'],
            'legacy'        => $row['legacy'],
            'customer_id'   => $customerId,
            'principal'     => $row['principal'],
            'weekly'        => $weekly,
            'weeks'         => $weeks,
            'total'         => round($weekly * $weeks, 2),
            'loan_date'     => $loanDate,
            'first_payment' => $first,
            'final_payment' => $final,
            'disbursed_at'  => $loanDate . ' 09:00:00',
            'created_by'    => $adminId,
        ]);

        $loanId = (int) $db->lastInsertId();
        $loanIds[$row['legacy']] = $loanId;

        $insertDisbursement->execute([
            'loan_id'     => $loanId,
            'amount'      => $row['principal'],
            'user_id'     => $adminId,
            'disbursed_at'=> $loanDate . ' 09:00:00',
        ]);

        $schedule->generate($loanId, $first, $weeks, $weekly);
    }

    out('✔ ' . count($loanIds) . ' loans created with weekly schedules');

    if ($missing) {
        out('⚠ Loans without a matching customer:');
        foreach ($missing as $line) {
            out('   - ' . $line);
        }
    }

    // ------------------------------------------------------------ payments
    $insertPayment = $db->prepare(
        "INSERT INTO payments
            (reference, legacy_reference, loan_id, customer_id, amount, method, status,
             received_by, paid_at, notes)
         VALUES
            (:reference, :legacy, :loan_id, :customer_id, :amount, 'cash', 'confirmed',
             :received_by, :paid_at, :notes)"
    );

    $customerOfLoan = $db->prepare("SELECT customer_id FROM loans WHERE id = :id");

    $newRef  = 0;
    $skipped = 0;
    $imported = 0;

    foreach ($paymentRows as $row) {
        if ($row['skip']) {
            $skipped++;
            continue;
        }

        $loanId = $loanIds[$row['loan']] ?? null;

        if ($loanId === null) {
            $skipped++;
            continue;
        }

        $customerOfLoan->execute(['id' => $loanId]);
        $customerId = (int) $customerOfLoan->fetchColumn();

        $newRef++;

        $insertPayment->execute([
            'reference'     => 'PMT-' . str_pad((string) $newRef, 4, '0', STR_PAD_LEFT),
            'legacy'        => $row['legacy'],
            'loan_id'       => $loanId,
            'customer_id'   => $customerId,
            'amount'        => $row['amount'],
            'received_by'   => $adminId,
            'paid_at'       => $row['date'] . ' 10:00:00',
            'notes'         => null,
        ]);

        $imported++;
    }

    out('✔ ' . $imported . ' payments imported (' . $skipped . ' empty rows skipped)');

    // ------------------------------------------------------------ allocations
    foreach ($loanIds as $loanId) {
        $schedule->rebuild($loanId);
    }

    out('✔ Repayment schedules allocated');

    AuditLog::record('import.workbook', null, null, sprintf(
        'Imported workbook: %d customers, %d loans, %d payments',
        count($groups), count($loanIds), $imported
    ));

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    out('✖ Import failed: ' . $e->getMessage());
    out('  ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
}

// ---------------------------------------------------------------- verify
out('');
out('Verification');

$check = $db->query(
    "SELECT COUNT(*) AS customers,
            (SELECT COUNT(*) FROM loans) AS loans,
            (SELECT COUNT(*) FROM payments) AS payments,
            (SELECT COALESCE(SUM(principal), 0) FROM loans) AS principal,
            (SELECT COALESCE(SUM(total_payable), 0) FROM loans) AS payable,
            (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'confirmed') AS collected
       FROM customers"
)->fetch();

out(sprintf('  customers ......... %d', $check['customers']));
out(sprintf('  loans ............. %d', $check['loans']));
out(sprintf('  payments .......... %d', $check['payments']));
out(sprintf('  total principal ... %s', money($check['principal'])));
out(sprintf('  total payable ..... %s', money($check['payable'])));
out(sprintf('  total collected ... %s', money($check['collected'])));

$expected = [
    'principal' => 20900000,
    'payable'   => 29976000,
    'collected' => 13685000,
];

$ok = (float) $check['principal'] === (float) $expected['principal']
   && (float) $check['payable'] === (float) $expected['payable']
   && (float) $check['collected'] === (float) $expected['collected'];

out($ok ? '✔ Totals match the workbook exactly' : '⚠ Totals differ from the workbook — review the import');
out('');
