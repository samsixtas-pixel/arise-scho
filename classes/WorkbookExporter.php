<?php
/**
 * WorkbookExporter.php
 * -------------------------------------------------------
 * Builds the seven-sheet Excel workbook from live data.
 *
 * The supplied workbook is used as a template so the layout,
 * colours and column widths stay exactly as the office knows
 * them; every data cell is then overwritten with a static
 * value (no fragile cross-sheet formulas).
 * -------------------------------------------------------
 */

use PhpOffice\PhpSpreadsheet\Calculation\Information\ErrorValue;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class WorkbookExporter
{
    private PDO $db;
    private string $template;

    /** Last row that already carries styling inside each template sheet. */
    private const CAPACITY = [
        'CUSTOMERS'     => 63,
        'LOANS'         => 63,
        'PAYMENTS'      => 63,
        'OVERDUE LOANS' => 64,
    ];

    public function __construct(?string $template = null)
    {
        $this->db       = Database::getConnection();
        $this->template = $template ?? APP_ROOT . '/resources/templates/lms_export_template.xlsx';

        if (!is_file($this->template)) {
            throw new RuntimeException(t('The workbook template is missing at :path.', ['path' => $this->template]));
        }
    }

    /** Sheet keys available for single-sheet exports. */
    public const EXPORT_SHEETS = [
        'dashboard' => 'DASHBOARD',
        'customers' => 'CUSTOMERS',
        'loans'     => 'LOANS',
        'payments'  => 'PAYMENTS',
        'overdue'   => 'OVERDUE LOANS',
        'settings'  => 'SETTINGS',
        'statement' => 'STATEMENT',
    ];

    /**
     * Build a workbook that contains only one of the seven sheets.
     * The sheet keeps the template formatting, styles and (for the
     * dashboard) its three charts.
     */
    public function buildSheet(string $kind, int $statementLoanId = 0): string
    {
        $kind = strtolower(trim($kind));
        $name = self::EXPORT_SHEETS[$kind] ?? null;

        if ($name === null) {
            throw new RuntimeException(t('Unknown export sheet: :name.', ['name' => $kind]));
        }

        $reader = IOFactory::createReader('Xlsx');
        $reader->setIncludeCharts(true);
        $workbook = $reader->load($this->template);

        switch ($kind) {
            case 'dashboard':
                $this->writeDashboard(
                    $this->sheet($workbook, $name),
                    (new Loan())->portfolio(),
                    (new Loan())->customerMetrics()
                );
                break;

            case 'customers':
                $this->writeCustomers($this->sheet($workbook, $name));
                break;

            case 'loans':
                $this->writeLoans($this->sheet($workbook, $name));
                break;

            case 'payments':
                $this->writePayments($this->sheet($workbook, $name));
                break;

            case 'overdue':
                $this->writeOverdue($this->sheet($workbook, $name));
                break;

            case 'settings':
                $this->writeSettings($this->sheet($workbook, $name));
                break;

            case 'statement':
                $this->writeStatement($this->sheet($workbook, $name), $statementLoanId);
                break;
        }

        // Remove every other sheet so the download contains one report only.
        foreach ($workbook->getSheetNames() as $sheetName) {
            if ($sheetName === $name) {
                continue;
            }

            $worksheet = $workbook->getSheetByName($sheetName);

            if ($worksheet !== null) {
                $workbook->removeSheetByIndex($workbook->getIndex($worksheet));
            }
        }

        $workbook->setActiveSheetIndex(0);

        $this->flattenFormulas($workbook);
        $this->resizeTables($workbook);

        $path = sys_get_temp_dir() . '/lms_sheet_' . bin2hex(random_bytes(8)) . '.xlsx';

        $writer = IOFactory::createWriter($workbook, 'Xlsx');
        $writer->setIncludeCharts(true);
        $writer->save($path);

        return $path;
    }

    /**
     * Build the workbook and return the temporary file path.
     * The caller streams the file and then deletes it.
     */
    public function build(int $statementLoanId = 0): string
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setIncludeCharts(true);
        $workbook = $reader->load($this->template);

        $portfolio  = (new Loan())->portfolio();
        $customers  = (new Loan())->customerMetrics();

        $this->writeCustomers($this->sheet($workbook, 'CUSTOMERS'));
        $this->writeLoans($this->sheet($workbook, 'LOANS'));
        $this->writePayments($this->sheet($workbook, 'PAYMENTS'));
        $this->writeOverdue($this->sheet($workbook, 'OVERDUE LOANS'));
        $this->writeDashboard($this->sheet($workbook, 'DASHBOARD'), $portfolio, $customers);
        $this->writeSettings($this->sheet($workbook, 'SETTINGS'));
        $this->writeStatement($this->sheet($workbook, 'STATEMENT'), $statementLoanId);

        $workbook->setActiveSheetIndex(0);

        // The runtime template is already formula-free. Flattening again is
        // cheap insurance in case the full reference workbook is dropped in.
        $this->flattenFormulas($workbook);

        // Keep the three DASHBOARD charts and let the writer refresh their
        // cached values from the freshly written cells.
        $this->resizeTables($workbook);

        $path = sys_get_temp_dir() . '/lms_workbook_' . bin2hex(random_bytes(8)) . '.xlsx';

        $writer = IOFactory::createWriter($workbook, 'Xlsx');
        $writer->setIncludeCharts(true);
        $writer->save($path);

        return $path;
    }

    // ------------------------------------------------------------------
    // Sheets
    // ------------------------------------------------------------------

    private function writeCustomers($ws): void
    {
        $rows = $this->db->query(
            "SELECT * FROM customers WHERE deleted_at IS NULL ORDER BY id"
        )->fetchAll();

        $row = 4;

        foreach ($rows as $customer) {
            $this->prepareRow($ws, 'CUSTOMERS', $row, 4, 'G');

            $this->text($ws, "A{$row}", $customer['reference']);
            $this->text($ws, "B{$row}", $customer['full_name']);
            $this->text($ws, "C{$row}", $customer['phone'] ?? '');
            $this->text($ws, "D{$row}", $customer['emergency_contact'] ?? '');
            $this->text($ws, "E{$row}", $customer['emergency_phone'] ?? '');
            $this->date($ws, "F{$row}", $customer['registered_at'] ?? $customer['created_at']);
            $this->text($ws, "G{$row}", $customer['notes'] ?? '');

            $row++;
        }
    }

    private function writeLoans($ws): void
    {
        $rows = $this->db->query(
            "SELECT * FROM v_loans_with_status ORDER BY loan_id"
        )->fetchAll();

        $row = 4;
        $seq = 1;
        $overdueSeq = 0;

        foreach ($rows as $loan) {
            $this->prepareRow($ws, 'LOANS', $row, 4, 'S');

            $isOverdue = $loan['loan_status'] === 'OVERDUE';
            if ($isOverdue) {
                $overdueSeq++;
            }

            $ws->setCellValue("A{$row}", $seq++);
            $this->text($ws, "B{$row}", $loan['loan_reference']);
            $this->text($ws, "C{$row}", $loan['customer_name']);
            $this->date($ws, "D{$row}", $loan['loan_date']);
            $ws->setCellValue("E{$row}", (float) $loan['principal_amount']);
            $ws->setCellValue("F{$row}", (float) $loan['weekly_amount']);
            $ws->setCellValue("G{$row}", (int) $loan['number_of_weeks']);
            $ws->setCellValue("H{$row}", (float) $loan['total_amount_payable']);
            $this->date($ws, "I{$row}", $loan['first_payment_date']);
            $this->date($ws, "J{$row}", $loan['final_payment_date']);
            $ws->setCellValue("K{$row}", (float) $loan['amount_paid']);
            $ws->setCellValue("L{$row}", (float) $loan['remaining_balance']);
            $ws->setCellValue("M{$row}", (int) $loan['weeks_paid']);
            $ws->setCellValue("N{$row}", (int) $loan['weeks_remaining']);
            $this->date($ws, "O{$row}", $loan['next_payment_date']);
            $ws->setCellValue("P{$row}", (int) $loan['days_overdue']);
            $this->text($ws, "Q{$row}", $loan['loan_status']);
            $ws->setCellValue("R{$row}", $isOverdue ? 1 : 0);
            if ($isOverdue) {
                $ws->setCellValue("S{$row}", $overdueSeq);
            } else {
                $ws->setCellValue("S{$row}", null);
            }

            $row++;
        }
    }

    private function writePayments($ws): void
    {
        $rows = $this->db->query(
            "SELECT * FROM v_payments_with_balance
              WHERE status = 'confirmed'
              ORDER BY payment_date, payment_id"
        )->fetchAll();

        $row   = 4;
        $seq   = 1;
        $ranks = [];

        foreach ($rows as $payment) {
            $this->prepareRow($ws, 'PAYMENTS', $row, 4, 'N');

            $loanId = (int) $payment['loan_id'];
            $ranks[$loanId] = ($ranks[$loanId] ?? 0) + 1;

            $ws->setCellValue("A{$row}", $seq++);
            $this->text($ws, "B{$row}", $payment['payment_reference']);
            $this->text($ws, "C{$row}", $payment['loan_reference']);
            $this->text($ws, "D{$row}", $payment['customer_name']);
            $this->date($ws, "E{$row}", $payment['loan_date']);
            $this->date($ws, "F{$row}", $payment['payment_date']);
            $ws->setCellValue("G{$row}", (float) $payment['weekly_amount']);
            $ws->setCellValue("H{$row}", (float) $payment['amount_paid']);
            $ws->setCellValue("I{$row}", (float) $payment['total_amount_payable']);
            $ws->setCellValue("J{$row}", (float) $payment['total_paid_to_date']);
            $ws->setCellValue("K{$row}", (float) $payment['remaining_balance']);
            $this->date($ws, "L{$row}", $payment['next_payment_date']);
            $this->text($ws, "M{$row}", $payment['loan_status']);
            $ws->setCellValue("N{$row}", $ranks[$loanId]);

            $row++;
        }
    }

    private function writeOverdue($ws): void
    {
        $rows = $this->db->query(
            "SELECT * FROM v_overdue_loans ORDER BY days_overdue DESC, remaining_balance DESC"
        )->fetchAll();

        $ws->setCellValue("A2", count($rows) === 0
            ? 'NO OVERDUE LOANS - All accounts are current.'
            : count($rows) . ' OVERDUE LOAN(S) FOUND - immediate follow-up required');

        $row = 5;
        $seq = 1;

        foreach ($rows as $loan) {
            $this->prepareRow($ws, 'OVERDUE LOANS', $row, 5, 'L');

            $ws->setCellValue("A{$row}", $seq++);
            $this->text($ws, "B{$row}", $loan['customer_name']);
            $this->text($ws, "C{$row}", $loan['phone_number'] ?? '');
            $this->text($ws, "D{$row}", $loan['loan_reference']);
            $this->date($ws, "E{$row}", $loan['loan_date']);
            $ws->setCellValue("F{$row}", (float) $loan['weekly_amount']);
            $ws->setCellValue("G{$row}", (float) $loan['total_amount_payable']);
            $ws->setCellValue("H{$row}", (float) $loan['amount_paid']);
            $ws->setCellValue("I{$row}", (float) $loan['remaining_balance']);
            $this->date($ws, "J{$row}", $loan['next_payment_date']);
            $ws->setCellValue("K{$row}", (int) $loan['days_overdue']);
            $this->text($ws, "L{$row}", $loan['loan_status']);

            $row++;
        }
    }

    private function writeDashboard($ws, array $portfolio, array $customers): void
    {
        $this->text($ws, 'A2', t('Report generated') . ': ' . date(DISPLAY_DATE_FORMAT));

        $ws->setCellValue('A6', (int) $customers['total_customers']);
        $ws->setCellValue('D6', (int) $customers['borrowers']);

        $ws->setCellValue('A11', (int) $portfolio['total_loans']);
        $ws->setCellValue('D11', (int) $portfolio['active_loans']);
        $ws->setCellValue('G11', (int) $portfolio['due_loans']);

        $ws->setCellValue('A14', (int) $portfolio['overdue_loans']);
        $ws->setCellValue('D14', (int) $portfolio['paid_loans']);
        $ws->setCellValue('G14', (int) $portfolio['overpaid_loans']);

        $ws->setCellValue('A19', (float) $portfolio['total_principal']);
        $ws->setCellValue('D19', (float) $portfolio['total_payable']);
        $ws->setCellValue('G19', (float) $portfolio['total_collected']);

        $ws->setCellValue('A22', (float) $portfolio['total_outstanding']);
        $ws->setCellValue('D22', (float) $portfolio['total_overdue']);

        // Weekly collections: the 13 most recent weeks (Monday based).
        $weekly = $this->db->query(
            "SELECT DATE_SUB(DATE(paid_at), INTERVAL WEEKDAY(paid_at) DAY) AS week_start,
                    SUM(amount) AS collected
               FROM payments
              WHERE status = 'confirmed'
              GROUP BY week_start"
        )->fetchAll();

        $byWeek = [];
        foreach ($weekly as $week) {
            $byWeek[$week['week_start']] = (float) $week['collected'];
        }

        $monday = date('Y-m-d', strtotime('monday this week'));
        $start  = date('Y-m-d', strtotime($monday . ' -12 weeks'));
        $total  = 0.0;

        for ($i = 0; $i < 13; $i++) {
            $weekDate = date('Y-m-d', strtotime($start . " +{$i} weeks"));
            $value    = $byWeek[$weekDate] ?? 0.0;
            $total   += $value;

            $row = 28 + $i;
            $this->date($ws, "A{$row}", $weekDate);
            $ws->setCellValue("B{$row}", $value);
        }

        $this->text($ws, 'A41', t('Total Collected'));
        $ws->setCellValue('B41', $total);
        $this->text($ws, 'A42', t('Period') . ': ' . formatDate($start) . ' - ' . formatDate($monday));

        // Right-hand metric blocks.
        $ws->setCellValue('M46', (int) $portfolio['active_loans']);
        $ws->setCellValue('M47', (int) $portfolio['due_loans']);
        $ws->setCellValue('M48', (int) $portfolio['overdue_loans']);
        $ws->setCellValue('M49', (int) $portfolio['paid_loans']);
        $ws->setCellValue('M50', (int) $portfolio['overpaid_loans']);

        $ws->setCellValue('M54', (float) $portfolio['total_collected']);
        $ws->setCellValue('M55', (float) $portfolio['total_outstanding']);
        $ws->setCellValue('M56', (float) $portfolio['total_overdue']);

        $ws->setCellValue('M60', (int) $customers['total_customers']);
        $ws->setCellValue('M61', (int) $customers['borrowers']);
        $ws->setCellValue('M62', (int) $portfolio['total_loans']);
        $ws->setCellValue('M63', (int) $portfolio['active_loans']);
        $ws->setCellValue('M64', (int) $portfolio['due_loans']);
        $ws->setCellValue('M65', (int) $portfolio['overdue_loans']);
        $ws->setCellValue('M66', (int) $portfolio['paid_loans']);
        $ws->setCellValue('M67', (int) $portfolio['overpaid_loans']);
        $ws->setCellValue('M68', (float) $portfolio['total_principal']);
        $ws->setCellValue('M69', (float) $portfolio['total_payable']);
        $ws->setCellValue('M70', (float) $portfolio['total_collected']);
        $ws->setCellValue('M71', (float) $portfolio['total_outstanding']);
        $ws->setCellValue('M72', (float) $portfolio['total_overdue']);
    }

    private function writeSettings($ws): void
    {
        $this->text($ws, 'B4', Settings::businessName());
        $this->text($ws, 'B5', Settings::businessPhone());
        $ws->setCellValue('B6', Settings::defaultWeeks());
        $ws->setCellValue('B7', Settings::defaultWeeklyAmount());
        $this->text($ws, 'B8', Settings::currency());
        $this->text($ws, 'B9', 'DD/MM/YYYY');
        $ws->setCellValue('B10', Settings::graceDays());

        $ws->getStyle('B7')->getNumberFormat()->setFormatCode('#,##0" TZS"');
    }

    private function writeStatement($ws, int $loanId): void
    {
        $loan = false;

        if ($loanId > 0) {
            $stmt = $this->db->prepare("SELECT * FROM v_loans_with_status WHERE loan_id = :id LIMIT 1");
            $stmt->execute(['id' => $loanId]);
            $loan = $stmt->fetch();
        }

        if (!$loan) {
            $loan = $this->db->query(
                "SELECT v.* FROM v_loans_with_status v
                  WHERE EXISTS (SELECT 1 FROM payments p
                                 WHERE p.loan_id = v.loan_id AND p.status = 'confirmed')
                  ORDER BY v.loan_id DESC LIMIT 1"
            )->fetch();
        }

        if (!$loan) {
            $loan = $this->db->query(
                "SELECT * FROM v_loans_with_status ORDER BY loan_id DESC LIMIT 1"
            )->fetch();
        }

        if (!$loan) {
            return;
        }

        $this->text($ws, 'B3', $loan['loan_reference']);

        $this->text($ws, 'B5', $loan['customer_name']);
        $this->text($ws, 'B6', $loan['phone_number'] ?? '');
        $this->text($ws, 'B7', $loan['loan_reference']);
        $this->date($ws, 'B8', $loan['loan_date']);
        $ws->setCellValue('B9', (float) $loan['principal_amount']);
        $ws->setCellValue('B10', (float) $loan['weekly_amount']);
        $ws->setCellValue('B11', (int) $loan['number_of_weeks']);
        $ws->setCellValue('B12', (float) $loan['total_amount_payable']);
        $ws->setCellValue('B13', (float) $loan['amount_paid']);
        $ws->setCellValue('B14', (float) $loan['remaining_balance']);
        $this->date($ws, 'B15', $loan['next_payment_date']);
        $this->text($ws, 'B16', $loan['loan_status']);

        $stmt = $this->db->prepare(
            "SELECT * FROM v_payments_with_balance
              WHERE loan_id = :id AND status = 'confirmed'
              ORDER BY payment_date, payment_id"
        );
        $stmt->execute(['id' => $loan['loan_id']]);
        $payments = $stmt->fetchAll();

        // Wipe the template's 150 formula rows first, then write history.
        for ($row = 20; $row <= 169; $row++) {
            $ws->setCellValue("A{$row}", null);
            $ws->setCellValue("B{$row}", null);
            $ws->setCellValue("C{$row}", null);
            $ws->setCellValue("D{$row}", null);
            $ws->setCellValue("E{$row}", null);
        }

        $row = 20;

        foreach ($payments as $index => $payment) {
            $ws->setCellValue("A{$row}", $index + 1);
            $this->date($ws, "B{$row}", $payment['payment_date']);
            $ws->setCellValue("C{$row}", (float) $payment['amount_paid']);
            $ws->setCellValue("D{$row}", (float) $payment['total_paid_to_date']);
            $ws->setCellValue("E{$row}", (float) $payment['remaining_balance']);
            $row++;
        }
    }

    // ------------------------------------------------------------------
    // Cell helpers
    // ------------------------------------------------------------------

    /**
     * Replace every formula cell left in the workbook with the value that
     * was cached in the template file. Empty / errored cached values are
     * cleared so no stale "#REF!"-style text leaks into the export.
     */
    private function flattenFormulas($workbook): void
    {
        foreach ($workbook->getAllSheets() as $worksheet) {
            foreach ($worksheet->getCellCollection()->getCoordinates() as $coordinate) {
                $cell = $worksheet->getCell($coordinate);

                if ($cell->getDataType() !== DataType::TYPE_FORMULA) {
                    continue;
                }

                try {
                    $value = $cell->getOldCalculatedValue();
                } catch (Throwable $e) {
                    $value = null;
                }

                if ($value === null || $value === '' || (is_string($value) && ErrorValue::isError($value))) {
                    $worksheet->setCellValue($coordinate, null);
                    continue;
                }

                if (is_bool($value)) {
                    $worksheet->setCellValue($coordinate, $value);
                } elseif (is_numeric($value)) {
                    $worksheet->setCellValue($coordinate, (float) $value);
                } else {
                    $worksheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                }
            }
        }
    }

    /**
     * Keep each Excel table's range in step with the rows we actually wrote,
     * so filters and banding cover every exported record.
     */
    private function resizeTables($workbook): void
    {
        $tables = [
            'CUSTOMERS' => ['tblCustomers', 'G'],
            'LOANS'     => ['tblLoans', 'S'],
            'PAYMENTS'  => ['tblPayments', 'N'],
        ];

        foreach ($tables as $sheetName => [$tableName, $lastColumn]) {
            $worksheet = $workbook->getSheetByName($sheetName);

            if ($worksheet === null) {
                continue;
            }

            $lastRow = max(4, $worksheet->getHighestDataRow());

            foreach ($worksheet->getTableCollection() as $table) {
                if ($table->getName() === $tableName) {
                    $table->setRange('A3:' . $lastColumn . $lastRow);
                }
            }
        }
    }

    private function sheet($workbook, string $name)
    {
        $sheet = $workbook->getSheetByName($name);

        if ($sheet === null) {
            throw new RuntimeException(t('The workbook template is missing the “:name” sheet.', ['name' => $name]));
        }

        return $sheet;
    }

    /** Copy the template row style when writing below the styled range. */
    private function prepareRow($ws, string $sheetName, int $row, int $firstRow, string $lastCol): void
    {
        $capacity = self::CAPACITY[$sheetName] ?? 0;

        if ($row > $capacity) {
            $ws->duplicateStyle(
                $ws->getStyle("A{$firstRow}:{$lastCol}{$firstRow}"),
                "A{$row}:{$lastCol}{$row}"
            );
        }
    }

    private function text($ws, string $coord, ?string $value): void
    {
        if ($value === null || $value === '') {
            $ws->setCellValue($coord, null);
            return;
        }

        $ws->setCellValueExplicit($coord, $value, DataType::TYPE_STRING);
    }

    private function date($ws, string $coord, ?string $date): void
    {
        if (empty($date) || $date === '0000-00-00') {
            $ws->setCellValue($coord, null);
            return;
        }

        try {
            $value = ExcelDate::PHPToExcel(new DateTimeImmutable($date));
        } catch (Throwable $e) {
            $ws->setCellValue($coord, null);
            return;
        }

        $ws->setCellValue($coord, $value);
        $ws->getStyle($coord)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    }
}
