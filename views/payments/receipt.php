<?php
/**
 * views/payments/receipt.php — printable repayment receipt.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$paymentId = (int) ($_GET['id'] ?? 0);
$receipt   = (new Payment())->receipt($paymentId);

if (!$receipt) {
    flash('error', t('Receipt not found.'));
    redirect(BASE_URL . 'views/payments/index.php');
}

$pageTitle = t('Receipt') . ' ' . $receipt['payment_reference'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head no-print">
  <div>
    <h4><?= t('Receipt') ?> <span class="ref"><?= e($receipt['payment_reference']) ?></span></h4>
    <p><?= e($receipt['customer_name']) ?> · <?= e(formatDate($receipt['payment_date'])) ?></p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">
      <i class="bi bi-printer me-1"></i><?= t('Print receipt') ?>
    </button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/loans/view.php?id=<?= (int) $receipt['loan_id'] ?>">
      <i class="bi bi-journal-text me-1"></i><?= t('Open loan file') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/payments/index.php">
      <i class="bi bi-arrow-left me-1"></i><?= t('Back to payments') ?>
    </a>
  </div>
</div>

<?php if ($receipt['payment_status'] === 'reversed'): ?>
  <div class="alert alert-danger no-print">
    <i class="bi bi-exclamation-octagon me-1"></i>
    <?= t('This receipt has been reversed and no longer counts towards the loan balance.') ?>
  </div>
<?php endif; ?>

<div class="receipt">
  <div class="text-center mb-3">
    <h5 class="mb-0"><?= e(Settings::businessName()) ?></h5>
    <div class="small text-muted"><?= t('Weekly Loan Management System') ?></div>
    <?php if (Settings::businessPhone() !== ''): ?>
      <div class="small text-muted"><?= e(Settings::businessPhone()) ?></div>
    <?php endif; ?>
    <div class="fs-3 text-success mt-2" aria-hidden="true"><i class="bi bi-cash-coin"></i></div>
  </div>

  <div class="divider"></div>

  <div class="d-flex justify-content-between small">
    <span class="text-muted"><?= t('Receipt number') ?></span>
    <strong><?= e($receipt['payment_reference']) ?></strong>
  </div>
  <?php if (!empty($receipt['legacy_reference'])): ?>
  <div class="d-flex justify-content-between small">
    <span class="text-muted"><?= t('Legacy reference') ?></span>
    <span><?= e($receipt['legacy_reference']) ?></span>
  </div>
  <?php endif; ?>
  <div class="d-flex justify-content-between small">
    <span class="text-muted"><?= t('Date') ?></span>
    <span><?= e(formatDateTime($receipt['payment_date'])) ?></span>
  </div>
  <div class="d-flex justify-content-between small">
    <span class="text-muted"><?= t('Received from') ?></span>
    <span class="fw-semibold text-end"><?= e($receipt['customer_name']) ?></span>
  </div>
  <div class="d-flex justify-content-between small">
    <span class="text-muted"><?= t('Customer') ?></span>
    <span><?= e($receipt['customer_reference']) ?></span>
  </div>
  <div class="d-flex justify-content-between small">
    <span class="text-muted"><?= t('Loan') ?></span>
    <span><?= e($receipt['loan_reference']) ?></span>
  </div>

  <div class="divider"></div>

  <div class="text-center">
    <div class="small text-muted"><?= t('Amount received') ?></div>
    <div class="amount"><?= e(money($receipt['amount_paid'])) ?></div>
    <div class="small text-muted"><?= e(amountInWords($receipt['amount_paid'])) ?></div>
  </div>

  <div class="divider"></div>

  <div class="d-flex justify-content-between small">
    <span class="text-muted"><?= t('Method') ?></span>
    <span><?= e(t(ucwords(str_replace('_', ' ', $receipt['method'])))) ?></span>
  </div>
  <?php if (!empty($receipt['external_reference'])): ?>
  <div class="d-flex justify-content-between small">
    <span class="text-muted"><?= t('Transaction reference') ?></span>
    <span><?= e($receipt['external_reference']) ?></span>
  </div>
  <?php endif; ?>
  <div class="d-flex justify-content-between small">
    <span class="text-muted"><?= t('Paid to date') ?></span>
    <span><?= e(money($receipt['total_paid_to_date'])) ?></span>
  </div>
  <div class="d-flex justify-content-between small">
    <span class="text-muted"><?= t('Balance after this payment') ?></span>
    <strong><?= e(money($receipt['remaining_balance'])) ?></strong>
  </div>
  <?php if (!empty($receipt['notes'])): ?>
  <div class="divider"></div>
  <div class="small text-muted"><?= nl2br(e($receipt['notes'])) ?></div>
  <?php endif; ?>

  <div class="divider"></div>

  <div class="d-flex justify-content-between small text-muted">
    <span><?= t('Received by') ?>: <?= e($receipt['received_by_name'] ?? '—') ?></span>
    <span><?= t('Status') ?>: <?= e(t(ucfirst($receipt['payment_status']))) ?></span>
  </div>

  <div class="text-center small text-muted mt-3">
    <?= t('Thank you. Please keep this receipt.') ?><br>
    <?= e(date(DISPLAY_DATE_FORMAT)) ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
