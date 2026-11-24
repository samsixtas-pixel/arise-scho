<?php
/**
 * views/payments/index.php — the receipt ledger.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$paymentModel = new Payment();
$userModel    = new User();

$filters = [
    'q'       => trim((string) ($_GET['q'] ?? '')),
    'from'    => (string) ($_GET['from'] ?? ''),
    'to'      => (string) ($_GET['to'] ?? ''),
    'method'  => (string) ($_GET['method'] ?? ''),
    'status'  => (string) ($_GET['status'] ?? ''),
    'officer' => (string) ($_GET['officer'] ?? ''),
];

if (isPost()) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'reverse') {
        User::requireAdmin();

        try {
            $paymentModel->reverse(
                (int) ($_POST['payment_id'] ?? 0),
                trim((string) ($_POST['reason'] ?? ''))
            );
            flash('success', t('Payment reversed. The schedule has been rebuilt.'));
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
    }

    redirect(BASE_URL . 'views/payments/index.php' . queryWith([]));
}

$result   = $paymentModel->paginate($filters, (int) ($_GET['page'] ?? 1), 25);
$officers = User::isAdmin() ? $userModel->officers() : [];

$summaryFrom = $filters['from'] !== '' ? $filters['from'] : '1970-01-01';
$summaryTo   = $filters['to'] !== '' ? $filters['to'] : date('Y-m-d');
$summary     = $paymentModel->summary(
    $summaryFrom,
    $summaryTo,
    !empty($filters['officer']) ? (int) $filters['officer'] : null
);

$pageTitle = t('Payments');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Payments') ?></h4>
    <p><?= t(':n receipts match this view', ['n' => $result['total']]) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/payments/add.php">
      <i class="bi bi-plus-circle me-1"></i><?= t('Record payment') ?>
    </a>
  </div>
</div>

<form class="filter-bar" method="GET" action="<?= BASE_URL ?>views/payments/index.php">
  <div class="field grow">
    <label for="q"><?= t('Search') ?></label>
    <input class="form-control" type="search" id="q" name="q" value="<?= e($filters['q']) ?>"
           placeholder="<?= t('Receipt, loan, customer or phone') ?>">
  </div>

  <div class="field">
    <label for="from"><?= t('From') ?></label>
    <input class="form-control" type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
  </div>

  <div class="field">
    <label for="to"><?= t('To') ?></label>
    <input class="form-control" type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
  </div>

  <div class="field">
    <label for="method"><?= t('Method') ?></label>
    <select class="form-select" id="method" name="method" data-auto-submit>
      <option value=""><?= t('All') ?></option>
      <?php foreach (['cash' => 'Cash', 'mobile_money' => 'Mobile money', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'] as $key => $label): ?>
        <option value="<?= $key ?>" <?= $filters['method'] === $key ? 'selected' : '' ?>><?= e(t($label)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="status"><?= t('Status') ?></label>
    <select class="form-select" id="status" name="status" data-auto-submit>
      <option value=""><?= t('All') ?></option>
      <?php foreach (['confirmed', 'reversed', 'pending'] as $status): ?>
        <option value="<?= $status ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(t(ucfirst($status))) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if (User::isAdmin()): ?>
  <div class="field">
    <label for="officer"><?= t('Received by') ?></label>
    <select class="form-select" id="officer" name="officer" data-auto-submit>
      <option value=""><?= t('All') ?></option>
      <?php foreach ($officers as $officer): ?>
        <option value="<?= (int) $officer['id'] ?>" <?= (string) $officer['id'] === $filters['officer'] ? 'selected' : '' ?>>
          <?= e($officer['full_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <div class="field">
    <label>&nbsp;</label>
    <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i><?= t('Filter') ?></button>
  </div>

  <?php if (array_filter($filters)): ?>
  <div class="field">
    <label>&nbsp;</label>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>views/payments/index.php"><?= t('Clear') ?></a>
  </div>
  <?php endif; ?>
</form>

<div class="kpi-grid">
  <div class="kpi">
    <div class="k-icon"><i class="bi bi-cash-stack"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Collected in this range') ?></div>
      <div class="k-value"><?= e(money($summary['total'] ?? 0)) ?></div>
      <div class="k-hint"><?= t(':n receipts', ['n' => (int) ($summary['receipts'] ?? 0)]) ?></div>
    </div>
  </div>
  <div class="kpi ink">
    <div class="k-icon"><i class="bi bi-phone"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Mobile money') ?></div>
      <div class="k-value"><?= e(money($summary['mobile'] ?? 0)) ?></div>
      <div class="k-hint"><?= t('Cash: :amount', ['amount' => money($summary['cash'] ?? 0)]) ?></div>
    </div>
  </div>
  <div class="kpi gold">
    <div class="k-icon"><i class="bi bi-people"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Customers served') ?></div>
      <div class="k-value"><?= (int) ($summary['customers'] ?? 0) ?></div>
      <div class="k-hint"><?= t('Bank: :amount · Other: :other', [
          'amount' => money($summary['bank'] ?? 0),
          'other'  => money($summary['other'] ?? 0),
      ]) ?></div>
    </div>
  </div>
</div>

<div class="card">
  <?php if (empty($result['rows'])): ?>
    <div class="empty-state">
      <i class="bi bi-receipt"></i>
      <strong><?= t('No payments match this filter') ?></strong>
      <p><?= t('Record a repayment, or adjust the search.') ?></p>
      <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/payments/add.php"><?= t('Record payment') ?></a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th><?= t('Receipt') ?></th>
            <th><?= t('Customer') ?></th>
            <th class="d-none d-lg-table-cell"><?= t('Loan') ?></th>
            <th class="d-none d-md-table-cell"><?= t('Date') ?></th>
            <th class="num"><?= t('Amount') ?></th>
            <th class="num d-none d-md-table-cell"><?= t('Running total') ?></th>
            <th class="d-none d-xl-table-cell"><?= t('Received by') ?></th>
            <th><?= t('Status') ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($result['rows'] as $payment): ?>
            <tr class="<?= $payment['status'] === 'reversed' ? 'text-muted' : '' ?>">
              <td>
                <span class="ref"><?= e($payment['payment_reference']) ?></span>
                <?php if (!empty($payment['legacy_reference'])): ?>
                  <span class="sub"><?= e($payment['legacy_reference']) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <a class="name text-decoration-none" href="<?= BASE_URL ?>views/customers/view.php?id=<?= (int) $payment['customer_id'] ?>">
                  <?= e($payment['customer_name']) ?>
                </a>
                <span class="sub"><?= e(formatPhone($payment['phone_number']) ?: t('no phone')) ?></span>
              </td>
              <td class="d-none d-lg-table-cell">
                <a class="ref text-decoration-none" href="<?= BASE_URL ?>views/loans/view.php?id=<?= (int) $payment['loan_id'] ?>">
                  <?= e($payment['loan_reference']) ?>
                </a>
              </td>
              <td class="d-none d-md-table-cell"><?= e(formatDateTime($payment['payment_date'])) ?></td>
              <td class="num fw-semibold <?= $payment['status'] === 'reversed' ? 'text-decoration-line-through' : '' ?>">
                <?= e(money($payment['amount_paid'])) ?>
              </td>
              <td class="num d-none d-md-table-cell"><?= e(num($payment['total_paid_to_date'])) ?></td>
              <td class="d-none d-xl-table-cell small text-muted"><?= e($payment['received_by_name'] ?? '—') ?></td>
              <td><?= statusBadge($payment['status']) ?></td>
              <td class="text-end">
                <div class="table-actions">
                  <a class="btn btn-sm btn-outline-secondary btn-icon" title="<?= t('Receipt') ?>"
                     href="<?= BASE_URL ?>views/payments/receipt.php?id=<?= (int) $payment['payment_id'] ?>">
                    <i class="bi bi-printer"></i>
                  </a>
                  <?php if (User::isAdmin() && $payment['status'] === 'confirmed'): ?>
                    <button class="btn btn-sm btn-outline-danger btn-icon" type="button"
                            title="<?= t('Reverse') ?>"
                            data-bs-toggle="modal" data-bs-target="#reverseModal"
                            data-payment-id="<?= (int) $payment['payment_id'] ?>"
                            data-payment-ref="<?= e($payment['payment_reference']) ?>"
                            data-payment-amount="<?= e(money($payment['amount_paid'])) ?>">
                      <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?= pagination($result['page'], $result['pages'], BASE_URL . 'views/payments/index.php') ?>

<?php if (User::isAdmin()): ?>
<div class="modal fade" id="reverseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="POST" action="<?= BASE_URL ?>views/payments/index.php">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="reverse">
      <input type="hidden" name="payment_id" id="reversePaymentId" value="">

      <div class="modal-header">
        <h5 class="modal-title"><?= t('Reverse payment') ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button>
      </div>

      <div class="modal-body">
        <p class="small text-muted mb-3">
          <?= t('This keeps the receipt in the ledger but removes its value from the loan balance.') ?>
        </p>
        <div class="alert alert-warning small" id="reverseSummary"></div>

        <label class="form-label required" for="reverseReason"><?= t('Reason') ?></label>
        <textarea class="form-control" id="reverseReason" name="reason" rows="3" required
                  placeholder="<?= t('e.g. receipt entered twice') ?>"></textarea>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-arrow-counterclockwise me-1"></i><?= t('Reverse') ?></button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('reverseModal')?.addEventListener('show.bs.modal', function (event) {
  const button = event.relatedTarget;
  if (!button) return;
  document.getElementById('reversePaymentId').value = button.dataset.paymentId || '';
  document.getElementById('reverseSummary').textContent =
    (button.dataset.paymentRef || '') + ' · ' + (button.dataset.paymentAmount || '');
  document.getElementById('reverseReason').value = '';
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
