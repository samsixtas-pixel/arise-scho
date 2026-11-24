<?php
/**
 * views/loans/add.php — issue a weekly loan.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$errors      = [];
$customers   = new Customer();
$preselected = null;

$customerId = (int) ($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);

if ($customerId > 0) {
    $preselected = $customers->findDetailed($customerId);
}

if (isPost()) {
    try {
        $loanId = (new Loan())->create($_POST);
        clearOld();
        flash('success', t('Loan issued successfully.'));
        redirect(BASE_URL . 'views/loans/view.php?id=' . $loanId);
    } catch (ValidationException $e) {
        $errors = $e->errors();
        setOld($_POST);
        if ($customerId > 0) {
            $preselected = $customers->findDetailed($customerId);
        }
    } catch (Throwable $e) {
        $errors = [$e->getMessage()];
        setOld($_POST);
    }
}

$defaults = [
    'loan_date'        => old('loan_date', date('Y-m-d')),
    'principal'        => old('principal'),
    'weekly_amount'    => old('weekly_amount', Settings::defaultWeeklyAmount()),
    'number_of_weeks'  => old('number_of_weeks', Settings::defaultWeeks()),
    'method'           => old('method', 'cash'),
    'disbursement_reference' => old('disbursement_reference'),
    'notes'            => old('notes'),
];

$pageTitle = t('New loan');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Issue a weekly loan') ?></h4>
    <p><?= t('The first instalment falls due one week after the loan date.') ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/loans/index.php">
      <i class="bi bi-arrow-left me-1"></i><?= t('Back to loans') ?>
    </a>
  </div>
</div>

<?= errorList($errors) ?>

<form method="POST" action="<?= BASE_URL ?>views/loans/add.php" novalidate>
  <?= Security::csrfField() ?>
  <input type="hidden" name="customer_id" id="customer_id" value="<?= $customerId ?: '' ?>">

  <div class="row g-3">
    <div class="col-lg-7">

      <div class="form-section">
        <h6><i class="bi bi-person-check me-1"></i><?= t('Borrower') ?></h6>

        <?php if ($preselected): ?>
          <div class="d-flex align-items-center gap-3 mb-2">
            <?php $photo = PhotoStorage::url($preselected['photo_path']); ?>
            <?php if ($photo): ?>
              <img class="avatar-photo" src="<?= e($photo) ?>" alt="">
            <?php else: ?>
              <span class="avatar-fallback"><?= e(initials($preselected['full_name'])) ?></span>
            <?php endif; ?>
            <div>
              <div class="fw-semibold"><?= e($preselected['full_name']) ?></div>
              <div class="small text-muted">
                <span class="ref"><?= e($preselected['reference']) ?></span>
                · <?= e($preselected['phone'] ? formatPhone($preselected['phone']) : t('no phone')) ?>
                <?php if ((float) $preselected['outstanding'] > 0): ?>
                  · <span class="text-warning"><?= e(money($preselected['outstanding'])) ?> <?= t('outstanding') ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <input type="hidden" id="customerSearch" value="<?= e($preselected['full_name']) ?>" readonly>
          <div class="form-text">
            <a href="<?= BASE_URL ?>views/loans/add.php"><?= t('Choose a different customer') ?></a>
          </div>
        <?php else: ?>
          <label class="form-label required" for="customerSearch"><?= t('Search customer') ?></label>
          <input class="form-control" type="text" id="customerSearch" autocomplete="off"
                 placeholder="<?= t('Type a name, phone or reference…') ?>">
          <div class="typeahead-results" id="customerResults"></div>
          <div class="form-text">
            <?= t('Only registered customers can borrow.') ?>
            <a href="<?= BASE_URL ?>views/customers/add.php"><?= t('Register a new customer') ?></a>
          </div>
        <?php endif; ?>
      </div>

      <div class="form-section">
        <h6><i class="bi bi-calculator me-1"></i><?= t('Loan terms') ?></h6>

        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label required" for="loan_date"><?= t('Loan date') ?></label>
            <input class="form-control" type="date" id="loan_date" name="loan_date"
                   value="<?= e(isoDate($defaults['loan_date'])) ?>" max="<?= date('Y-m-d') ?>" required>
            <div class="form-text"><?= t('First instalment: :date', ['date' => formatDate(addDays($defaults['loan_date'], 7))]) ?>
              <span id="first_payment_out" class="d-none"></span>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label required" for="principal"><?= t('Principal / cash issued') ?></label>
            <div class="input-group">
              <span class="input-group-text">TZS</span>
              <input class="form-control" type="number" id="principal" name="principal" min="1" step="1000"
                     value="<?= e($defaults['principal']) ?>" placeholder="500000" required>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label required" for="weekly_amount"><?= t('Weekly repayment') ?></label>
            <div class="input-group">
              <span class="input-group-text">TZS</span>
              <input class="form-control" type="number" id="weekly_amount" name="weekly_amount" min="1" step="1000"
                     value="<?= e($defaults['weekly_amount']) ?>" required>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label required" for="number_of_weeks"><?= t('Number of weeks') ?></label>
            <input class="form-control" type="number" id="number_of_weeks" name="number_of_weeks"
                   min="1" max="104" value="<?= e($defaults['number_of_weeks']) ?>" required>
          </div>
        </div>

        <div class="divide-row"></div>

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <div class="small text-muted"><?= t('Total amount payable') ?></div>
            <div class="fs-5 fw-bold text-tabular" id="loan_total_out">—</div>
          </div>
          <div class="text-end">
            <span class="hint-inline" id="loan_shortfall"></span>
          </div>
        </div>
      </div>

      <div class="form-section">
        <h6><i class="bi bi-wallet me-1"></i><?= t('Disbursement') ?></h6>

        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label" for="method"><?= t('Method') ?></label>
            <select class="form-select" id="method" name="method">
              <?php foreach (['cash' => 'Cash', 'mobile_money' => 'Mobile money', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'] as $key => $label): ?>
                <option value="<?= $key ?>" <?= $defaults['method'] === $key ? 'selected' : '' ?>><?= e(t($label)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="disbursement_reference"><?= t('Reference') ?></label>
            <input class="form-control" type="text" id="disbursement_reference" name="disbursement_reference"
                   value="<?= e($defaults['disbursement_reference']) ?>" placeholder="<?= t('Optional') ?>">
          </div>
          <div class="col-12">
            <label class="form-label" for="notes"><?= t('Notes') ?></label>
            <textarea class="form-control" id="notes" name="notes" rows="2"><?= e($defaults['notes']) ?></textarea>
          </div>
        </div>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-check2-circle me-1"></i><?= t('Issue loan') ?>
        </button>
        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>views/loans/index.php"><?= t('Cancel') ?></a>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card">
        <div class="card-header">
          <span class="card-title-sm"><i class="bi bi-info-circle me-1"></i><?= t('How the schedule works') ?></span>
        </div>
        <div class="card-body small text-muted">
          <p class="mb-2"><?= t('This is a flat weekly loan: the customer repays the same amount every week for the agreed number of weeks.') ?></p>
          <ul class="mb-3 ps-3">
            <li><?= t('First instalment: loan date + 7 days.') ?></li>
            <li><?= t('Every following instalment: +7 days.') ?></li>
            <li><?= t('A loan is overdue when the next instalment date has passed and a balance remains.') ?></li>
            <li><?= t('Payments are applied to the oldest unpaid instalment first.') ?></li>
          </ul>

          <div class="alert alert-success mb-0">
            <strong><?= t('Example') ?></strong><br>
            <?= t('TZS 500,000 over 12 weeks at TZS 58,000 per week = TZS 696,000 payable.') ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
