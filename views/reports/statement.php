<?php
/**
 * views/reports/statement.php — one loan's statement (workbook STATEMENT layout).
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$reportModel = new Report();
$loanId      = (int) ($_GET['loan_id'] ?? 0);
$customerId  = (int) ($_GET['customer_id'] ?? 0);

$loans = Database::getConnection()->query(
    "SELECT loan_id, loan_reference, customer_id, customer_name, loan_status, remaining_balance
       FROM v_loans_with_status
      ORDER BY customer_name, loan_id DESC"
)->fetchAll();

$statement = $loanId > 0 ? $reportModel->statement($loanId) : false;

if ($loanId > 0 && !$statement) {
    flash('error', t('Loan not found.'));
}

$pageTitle = t('Loan statement');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head no-print">
  <div>
    <h4><?= t('Loan statement') ?></h4>
    <p><?= t('Pick a loan to print or download its statement.') ?></p>
  </div>
  <div class="page-actions">
    <?php if ($statement): ?>
      <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">
        <i class="bi bi-printer me-1"></i><?= t('Print statement') ?>
      </button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/loans/view.php?id=<?= $loanId ?>">
        <i class="bi bi-journal-text me-1"></i><?= t('Open loan file') ?>
      </a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/reports/index.php">
      <i class="bi bi-arrow-left me-1"></i><?= t('Reports') ?>
    </a>
  </div>
</div>

<form class="filter-bar no-print" method="GET" action="<?= BASE_URL ?>views/reports/statement.php">
  <div class="field grow">
    <label for="loan_id"><?= t('Select loan reference') ?></label>
    <select class="form-select" id="loan_id" name="loan_id" data-auto-submit>
      <option value=""><?= t('Choose a loan…') ?></option>
      <?php foreach ($loans as $loan): ?>
        <option value="<?= (int) $loan['loan_id'] ?>"
                <?= (int) $loan['loan_id'] === $loanId ? 'selected' : '' ?>
                <?= $customerId > 0 && (int) $loan['customer_id'] !== $customerId ? 'hidden' : '' ?>>
          <?= e($loan['loan_reference']) ?> — <?= e($loan['customer_name']) ?>
          (<?= e(num($loan['remaining_balance'])) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label>&nbsp;</label>
    <button class="btn btn-outline-primary" type="submit"><i class="bi bi-file-earmark-text me-1"></i><?= t('Show') ?></button>
  </div>
</form>

<?php if (!$statement): ?>

  <div class="card">
    <div class="empty-state">
      <i class="bi bi-file-earmark-text"></i>
      <strong><?= t('Choose a loan to see its statement') ?></strong>
      <p><?= t('The statement shows the loan terms, every receipt and the running balance.') ?></p>
    </div>
  </div>

<?php else:
    $loan     = $statement['loan'];
    $payments = $statement['payments'];
    $schedule = $statement['schedule'];
    $photo    = PhotoStorage::url($loan['photo_path']);

    $running = 0.0;
?>

<div class="statement-sheet">

  <div class="statement-head">
    <div>
      <h4><?= e(Settings::businessName()) ?></h4>
      <div class="muted">
        <?= t('Weekly Loan Management System') ?><br>
        <?php if (Settings::businessPhone() !== ''): ?>
          <?= e(Settings::businessPhone()) ?><br>
        <?php endif; ?>
        <?= t('Statement generated :date', ['date' => date(DISPLAY_DATE_FORMAT)]) ?>
      </div>
    </div>
    <div class="text-end">
      <div class="small text-muted"><?= t('LOAN STATEMENT') ?></div>
      <div class="fs-5 fw-bold"><?= e($loan['loan_reference']) ?></div>
      <?= loanBadge($loan['loan_status']) ?>
    </div>
  </div>

  <div class="d-flex align-items-center gap-3 mb-3">
    <?php if ($photo): ?>
      <img class="avatar-photo lg" src="<?= e($photo) ?>" alt="">
    <?php else: ?>
      <span class="avatar-fallback lg"><?= e(initials($loan['customer_name'])) ?></span>
    <?php endif; ?>
    <div>
      <div class="fs-6 fw-bold"><?= e($loan['customer_name']) ?></div>
      <div class="small text-muted">
        <?= e($loan['customer_reference']) ?>
        · <?= e(formatPhone($loan['phone_number']) ?: t('No phone on file')) ?>
      </div>
      <?php if (!empty($loan['nida'])): ?>
        <div class="small text-muted">NIDA: <?= e($loan['nida']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-2 mb-3">
    <div class="col-6 col-md-4">
      <div class="border rounded p-2 h-100">
        <div class="small text-muted"><?= t('Loan date') ?></div>
        <div class="fw-semibold"><?= e(formatDate($loan['loan_date'])) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="border rounded p-2 h-100">
        <div class="small text-muted"><?= t('Weekly amount') ?></div>
        <div class="fw-semibold"><?= e(money($loan['weekly_amount'])) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="border rounded p-2 h-100">
        <div class="small text-muted"><?= t('Number of weeks') ?></div>
        <div class="fw-semibold"><?= (int) $loan['number_of_weeks'] ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="border rounded p-2 h-100">
        <div class="small text-muted"><?= t('Principal amount') ?></div>
        <div class="fw-semibold"><?= e(money($loan['principal_amount'])) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="border rounded p-2 h-100">
        <div class="small text-muted"><?= t('Total amount payable') ?></div>
        <div class="fw-semibold"><?= e(money($loan['total_amount_payable'])) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="border rounded p-2 h-100">
        <div class="small text-muted"><?= t('Next payment date') ?></div>
        <div class="fw-semibold"><?= e(formatDate($loan['next_payment_date'])) ?></div>
      </div>
    </div>
  </div>

  <div class="statement-total mb-3">
    <span><?= t('Total paid') ?>: <strong><?= e(money($loan['amount_paid'])) ?></strong></span>
    <span><?= t('Remaining balance') ?>: <strong><?= e(money($loan['remaining_balance'])) ?></strong></span>
    <span><?= t('Progress') ?>: <strong><?= e(percent($loan['amount_paid'], $loan['total_amount_payable'])) ?>%</strong></span>
  </div>

  <h6 class="fw-bold mt-4 mb-2"><?= t('PAYMENT HISTORY') ?></h6>

  <div class="table-wrap">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th style="width:50px">#</th>
          <th><?= t('Payment date') ?></th>
          <th><?= t('Receipt') ?></th>
          <th class="num"><?= t('Amount paid') ?></th>
          <th class="num"><?= t('Running total') ?></th>
          <th class="num"><?= t('Remaining balance') ?></th>
          <th><?= t('Method') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$payments): ?>
          <tr>
            <td colspan="7" class="text-center text-muted py-3"><?= t('No payments recorded on this loan yet.') ?></td>
          </tr>
        <?php else: ?>
          <?php foreach ($payments as $index => $payment):
              $running += (float) $payment['amount_paid'];
          ?>
            <tr>
              <td class="fw-semibold"><?= $index + 1 ?></td>
              <td><?= e(formatDate($payment['payment_date'])) ?></td>
              <td><span class="ref"><?= e($payment['payment_reference']) ?></span></td>
              <td class="num"><?= e(num($payment['amount_paid'])) ?></td>
              <td class="num"><?= e(num($payment['total_paid_to_date'])) ?></td>
              <td class="num"><?= e(num($payment['remaining_balance'])) ?></td>
              <td class="small"><?= e(t(ucwords(str_replace('_', ' ', $payment['method'])))) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="3"><?= t('Total') ?></th>
          <th class="num"><?= e(num($loan['amount_paid'])) ?></th>
          <th></th>
          <th class="num"><?= e(num($loan['remaining_balance'])) ?></th>
          <th></th>
        </tr>
      </tfoot>
    </table>
  </div>

  <h6 class="fw-bold mt-4 mb-2"><?= t('REPAYMENT SCHEDULE') ?></h6>

  <div class="table-wrap">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th style="width:50px">#</th>
          <th><?= t('Due date') ?></th>
          <th class="num"><?= t('Amount due') ?></th>
          <th class="num"><?= t('Paid') ?></th>
          <th><?= t('Status') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($schedule as $instalment): ?>
          <tr class="<?= $instalment['status'] === 'missed' ? 'row-danger' : '' ?>">
            <td class="fw-semibold"><?= (int) $instalment['installment_number'] ?></td>
            <td><?= e(formatDate($instalment['due_date'])) ?></td>
            <td class="num"><?= e(num($instalment['amount_due'])) ?></td>
            <td class="num"><?= e(num($instalment['amount_paid'])) ?></td>
            <td><?= statusBadge($instalment['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="small text-muted mt-4">
    <?= t('This statement was generated from the live loan ledger on :date at :time.', [
        'date' => date(DISPLAY_DATE_FORMAT),
        'time' => date('H:i'),
    ]) ?>
  </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
