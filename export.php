<?php
/**
 * export.php — download the seven-sheet workbook built from live data.
 */

require_once __DIR__ . '/includes/autoload.php';
User::requireLogin();

if (isset($_GET['download'])) {
    try {
        $exporter  = new WorkbookExporter();
        $kind      = strtolower(trim((string) ($_GET['sheet'] ?? '')));
        $statement = (int) ($_GET['statement_loan_id'] ?? 0);

        if ($kind !== '' && isset(WorkbookExporter::EXPORT_SHEETS[$kind])) {
            $path     = $exporter->buildSheet($kind, $statement);
            $filename = 'LMS_' . strtoupper($kind) . '_' . date('Y-m-d') . '.xlsx';
        } else {
            $path     = $exporter->build($statement);
            $filename = 'Weekly_Loan_Management_System_' . date('Y-m-d') . '.xlsx';
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        readfile($path);
        @unlink($path);
        exit;
    } catch (Throwable $e) {
        flash('error', t('The workbook could not be generated: :error', ['error' => $e->getMessage()]));
        redirect(BASE_URL . 'export.php');
    }
}

$db = Database::getConnection();

$counts = [
    'customers' => (int) $db->query("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL")->fetchColumn(),
    'loans'     => (int) $db->query("SELECT COUNT(*) FROM v_loans_with_status")->fetchColumn(),
    'payments'  => (int) $db->query("SELECT COUNT(*) FROM payments WHERE status = 'confirmed'")->fetchColumn(),
    'overdue'   => (int) $db->query("SELECT COUNT(*) FROM v_overdue_loans")->fetchColumn(),
];

$loans = $db->query(
    "SELECT loan_id, loan_reference, customer_name, loan_status
       FROM v_loans_with_status
      ORDER BY customer_name, loan_id DESC"
)->fetchAll();

$pageTitle = t('Workbook export');
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Workbook export') ?></h4>
    <p><?= t('Download the familiar seven-sheet Excel workbook with live data.') ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/reports/index.php">
      <i class="bi bi-arrow-left me-1"></i><?= t('Reports') ?>
    </a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">
        <span class="card-title-sm"><i class="bi bi-file-earmark-excel me-1"></i><?= t('What is inside') ?></span>
      </div>
      <div class="card-body">
        <p class="small text-muted">
          <?= t('The download uses your original workbook as the template, so the colours, column widths and sheet names stay exactly as they are. Every data cell is refreshed from the live ledger.') ?>
        </p>

        <div class="table-wrap">
          <table class="table align-middle mb-0">
            <thead>
              <tr>
                <th><?= t('Sheet') ?></th>
                <th><?= t('Contents') ?></th>
                <th class="num"><?= t('Rows') ?></th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><span class="ref">DASHBOARD</span></td>
                <td class="small text-muted"><?= t('Counters, money totals and weekly collections') ?></td>
                <td class="num">—</td>
              </tr>
              <tr>
                <td><span class="ref">CUSTOMERS</span></td>
                <td class="small text-muted"><?= t('Customer register with phone and registration date') ?></td>
                <td class="num"><?= $counts['customers'] ?></td>
              </tr>
              <tr>
                <td><span class="ref">LOANS</span></td>
                <td class="small text-muted"><?= t('Loan book with paid, balance, weeks and status') ?></td>
                <td class="num"><?= $counts['loans'] ?></td>
              </tr>
              <tr>
                <td><span class="ref">PAYMENTS</span></td>
                <td class="small text-muted"><?= t('Receipts with the corrected cumulative paid-to-date') ?></td>
                <td class="num"><?= $counts['payments'] ?></td>
              </tr>
              <tr>
                <td><span class="ref">OVERDUE LOANS</span></td>
                <td class="small text-muted"><?= t('Overdue work list, worst first') ?></td>
                <td class="num"><?= $counts['overdue'] ?></td>
              </tr>
              <tr>
                <td><span class="ref">SETTINGS</span></td>
                <td class="small text-muted"><?= t('Business details and loan defaults') ?></td>
                <td class="num">—</td>
              </tr>
              <tr>
                <td><span class="ref">STATEMENT</span></td>
                <td class="small text-muted"><?= t('One loan statement with a sequential # and running balance') ?></td>
                <td class="num">—</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">
        <span class="card-title-sm"><i class="bi bi-download me-1"></i><?= t('Download') ?></span>
      </div>
      <div class="card-body">
        <form method="GET" action="<?= BASE_URL ?>export.php">
          <input type="hidden" name="download" value="1">

          <label class="form-label" for="statement_loan_id"><?= t('Statement sheet: choose a loan') ?></label>
          <select class="form-select" id="statement_loan_id" name="statement_loan_id">
            <option value="0"><?= t('Most recent loan with payments') ?></option>
            <?php foreach ($loans as $loan): ?>
              <option value="<?= (int) $loan['loan_id'] ?>">
                <?= e($loan['loan_reference']) ?> — <?= e($loan['customer_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">
            <?= t('Only one loan can be shown on the STATEMENT sheet; the other sheets always include everything.') ?>
          </div>

          <button class="btn btn-primary w-100 mt-3" type="submit">
            <i class="bi bi-file-earmark-excel me-1"></i><?= t('Download workbook') ?>
          </button>
        </form>
      </div>

      <div class="card-body border-top small text-muted">
        <div class="d-flex justify-content-between">
          <span><?= t('Customers') ?></span><strong><?= $counts['customers'] ?></strong>
        </div>
        <div class="d-flex justify-content-between">
          <span><?= t('Loans') ?></span><strong><?= $counts['loans'] ?></strong>
        </div>
        <div class="d-flex justify-content-between">
          <span><?= t('Confirmed receipts') ?></span><strong><?= $counts['payments'] ?></strong>
        </div>
        <div class="d-flex justify-content-between">
          <span><?= t('Overdue loans') ?></span><strong><?= $counts['overdue'] ?></strong>
        </div>
      </div>
    </div>

    <div class="alert alert-success mt-3 small mb-0">
      <i class="bi bi-check2-circle me-1"></i>
      <?= t('The export is generated on the server each time, so it always matches the live data. Nothing is stored permanently.') ?>
    </div>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header">
    <span class="card-title-sm"><i class="bi bi-files me-1"></i><?= t('Single-sheet exports') ?></span>
  </div>
  <div class="card-body">
    <p class="small text-muted mb-3"><?= t('Download just one part of the workbook, with the same layout.') ?></p>

    <div class="row g-2">
      <?php
        $sheets = [
            ['customers', 'bi-people',             t('Export customer register')],
            ['loans',     'bi-cash-stack',         t('Export loans')],
            ['payments',  'bi-receipt',            t('Export payments')],
            ['overdue',   'bi-exclamation-octagon',t('Export overdue loans')],
            ['statement', 'bi-file-text',          t('Export individual loan statement')],
            ['dashboard', 'bi-speedometer2',       t('Export dashboard')],
        ];
      ?>

      <?php foreach ($sheets as [$kind, $icon, $label]): ?>
        <div class="col-sm-6 col-xl-4">
          <a class="btn btn-outline-secondary w-100 text-start"
             href="<?= BASE_URL ?>export.php?download=1&amp;sheet=<?= e($kind) ?>">
            <i class="bi <?= e($icon) ?> me-1"></i><?= e($label) ?>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="form-text mt-2">
      <?= t('The statement uses the most recent loan with payments. Choose a specific loan above for the full workbook.') ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
