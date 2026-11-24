<?php
/**
 * views/payments/add.php — record a repayment.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$paymentModel = new Payment();
$errors       = [];
$loanId       = (int) ($_GET['loan_id'] ?? $_POST['loan_id'] ?? 0);

if (isPost()) {
    $loanId = (int) ($_POST['loan_id'] ?? 0);

    try {
        $paymentId = $paymentModel->record($_POST);
        clearOld();
        flash('success', t('Payment received. The receipt is ready to print.'));

        redirect(BASE_URL . 'views/payments/receipt.php?id=' . $paymentId);
    } catch (ValidationException $e) {
        $errors = $e->errors();
        setOld($_POST);
    } catch (Throwable $e) {
        $errors = [$e->getMessage()];
        setOld($_POST);
    }
}

$db = Database::getConnection();

$loans = $db->query(
    "SELECT v.loan_id, v.loan_reference, v.customer_id, v.customer_name, v.phone_number,
            v.remaining_balance, v.weekly_amount, v.next_payment_date, v.loan_status, v.days_overdue
       FROM v_loans_with_status v
      WHERE v.loan_status IN ('ACTIVE','DUE','OVERDUE')
        AND v.remaining_balance > 0
      ORDER BY v.customer_name"
)->fetchAll();

$selected = null;

foreach ($loans as $loan) {
    if ((int) $loan['loan_id'] === $loanId) {
        $selected = $loan;
        break;
    }
}

// A closed loan may still be opened directly (e.g. from an old link).
if ($selected === null && $loanId > 0) {
    $stmt = $db->prepare(
        "SELECT v.loan_id, v.loan_reference, v.customer_id, v.customer_name, v.phone_number,
                v.remaining_balance, v.weekly_amount, v.next_payment_date, v.loan_status, v.days_overdue
           FROM v_loans_with_status v
          WHERE v.loan_id = :id
          LIMIT 1"
    );
    $stmt->execute(['id' => $loanId]);
    $selected = $stmt->fetch() ?: null;
}

$amountDefault = old('amount', $selected ? (float) $selected['weekly_amount'] : '');
$dateDefault   = old('paid_at', date('Y-m-d\TH:i'));

if (is_string($dateDefault)) {
    $dateDefault = str_replace(' ', 'T', $dateDefault);
}

$methodDefault = old('method', 'cash');

$recent = $selected ? $paymentModel->forLoan((int) $selected['loan_id'], true) : [];

$pageTitle = t('Record payment');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Record payment') ?></h4>
    <p><?= t('Repayments are applied to the oldest unpaid instalment first.') ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/payments/index.php">
      <i class="bi bi-arrow-left me-1"></i><?= t('Back to payments') ?>
    </a>
  </div>
</div>

<?= errorList($errors) ?>

<form method="POST" action="<?= BASE_URL ?>views/payments/add.php" novalidate>
  <?= Security::csrfField() ?>

  <div class="row g-3">
    <div class="col-lg-7">

      <div class="form-section">
        <h6><i class="bi bi-journal-text me-1"></i><?= t('Loan') ?></h6>

        <label class="form-label required" for="loan_id"><?= t('Choose the loan') ?></label>
        <select class="form-select" id="loan_id" name="loan_id" required>
          <option value=""><?= t('Select an open loan…') ?></option>
          <?php foreach ($loans as $loan): ?>
            <option value="<?= (int) $loan['loan_id'] ?>"
                    <?= (int) $loan['loan_id'] === $loanId ? 'selected' : '' ?>
                    data-balance="<?= e((float) $loan['remaining_balance']) ?>"
                    data-weekly="<?= e((float) $loan['weekly_amount']) ?>"
                    data-next="<?= e(formatDate($loan['next_payment_date'])) ?>"
                    data-customer="<?= e($loan['customer_name']) ?>"
                    data-status="<?= e(t(ucwords(strtolower($loan['loan_status'])))) ?>">
              <?= e($loan['loan_reference']) ?> — <?= e($loan['customer_name']) ?>
              (<?= e(num($loan['remaining_balance'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>

        <div class="form-text">
          <?= t('Only loans with an outstanding balance are listed.') ?>
        </div>

        <div class="balance-panel mt-3 <?= $selected ? '' : 'd-none' ?>" id="loan_balance_box"></div>

        <?php if ($selected && (int) $selected['days_overdue'] > 0): ?>
          <div class="alert alert-danger small mt-3 mb-0">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <?= t('This loan is :n days overdue.', ['n' => (int) $selected['days_overdue']]) ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="form-section">
        <h6><i class="bi bi-cash-stack me-1"></i><?= t('Receipt') ?></h6>

        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label required" for="amount"><?= t('Amount received') ?></label>
            <div class="input-group">
              <span class="input-group-text">TZS</span>
              <input class="form-control" type="number" id="amount" name="amount" min="1" step="1000"
                     value="<?= e($amountDefault) ?>" required>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label required" for="paid_at"><?= t('Date and time received') ?></label>
            <input class="form-control" type="datetime-local" id="paid_at" name="paid_at"
                   value="<?= e($dateDefault) ?>" max="<?= date('Y-m-d\TH:i') ?>" required>
          </div>

          <div class="col-md-6">
            <label class="form-label required" for="method"><?= t('Method') ?></label>
            <select class="form-select" id="method" name="method">
              <?php foreach (['cash' => 'Cash', 'mobile_money' => 'Mobile money', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'] as $key => $label): ?>
                <option value="<?= $key ?>" <?= $methodDefault === $key ? 'selected' : '' ?>><?= e(t($label)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label" for="external_reference"><?= t('Transaction reference') ?></label>
            <input class="form-control" type="text" id="external_reference" name="external_reference"
                   value="<?= e(old('external_reference')) ?>"
                   placeholder="<?= t('M-Pesa / bank slip number (optional)') ?>">
          </div>

          <div class="col-12">
            <label class="form-label" for="notes"><?= t('Notes') ?></label>
            <textarea class="form-control" id="notes" name="notes" rows="2"
                      placeholder="<?= t('Optional') ?>"><?= e(old('notes')) ?></textarea>
          </div>
        </div>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-check2-circle me-1"></i><?= t('Save payment') ?>
        </button>
        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>views/payments/index.php"><?= t('Cancel') ?></a>
      </div>
    </div>

    <div class="col-lg-5">

      <?php if ($selected): ?>
      <div class="card mb-3">
        <div class="card-header">
          <span class="card-title-sm"><i class="bi bi-person-vcard me-1"></i><?= t('Customer') ?></span>
        </div>
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <div>
              <a class="fw-semibold text-decoration-none" href="<?= BASE_URL ?>views/customers/view.php?id=<?= (int) $selected['customer_id'] ?>">
                <?= e($selected['customer_name']) ?>
              </a>
              <div class="small text-muted"><?= e(formatPhone($selected['phone_number']) ?: t('No phone on file')) ?></div>
            </div>
            <?= loanBadge($selected['loan_status']) ?>
          </div>

          <dl class="detail-list">
            <div><dt><?= t('Loan') ?></dt><dd><span class="ref"><?= e($selected['loan_reference']) ?></span></dd></div>
            <div><dt><?= t('Outstanding') ?></dt><dd><?= e(money($selected['remaining_balance'])) ?></dd></div>
            <div><dt><?= t('Weekly amount') ?></dt><dd><?= e(money($selected['weekly_amount'])) ?></dd></div>
            <div><dt><?= t('Next due') ?></dt><dd><?= e(formatDate($selected['next_payment_date'])) ?></dd></div>
          </dl>

          <a class="btn btn-outline-secondary btn-sm w-100 mt-3" href="<?= BASE_URL ?>views/loans/view.php?id=<?= (int) $selected['loan_id'] ?>">
            <i class="bi bi-journal-text me-1"></i><?= t('Open loan file') ?>
          </a>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <span class="card-title-sm"><i class="bi bi-clock-history me-1"></i><?= t('Recent receipts on this loan') ?></span>
        </div>

        <?php if (!$recent): ?>
          <div class="card-body small text-muted"><?= t('No payments yet on this loan.') ?></div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th><?= t('Receipt') ?></th>
                  <th><?= t('Date') ?></th>
                  <th class="num"><?= t('Amount') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_slice(array_reverse($recent), 0, 6) as $row): ?>
                  <tr class="<?= $row['status'] === 'reversed' ? 'text-muted' : '' ?>">
                    <td><span class="ref"><?= e($row['payment_reference']) ?></span></td>
                    <td class="small"><?= e(formatDate($row['payment_date'])) ?></td>
                    <td class="num"><?= e(num($row['amount_paid'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="card">
        <div class="card-header">
          <span class="card-title-sm"><i class="bi bi-info-circle me-1"></i><?= t('Before you save') ?></span>
        </div>
        <div class="card-body small text-muted">
          <ul class="mb-0 ps-3">
            <li><?= t('Choose the loan the customer is paying.') ?></li>
            <li><?= t('The weekly amount is filled in for you; change it if they pay more or less.') ?></li>
            <li><?= t('Payments cannot be dated before the loan date or in the future.') ?></li>
            <li><?= t('A receipt is created automatically and can be printed.') ?></li>
            <li><?= t('Only an administrator can reverse a receipt.') ?></li>
          </ul>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
