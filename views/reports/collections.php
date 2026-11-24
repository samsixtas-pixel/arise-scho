<?php
/**
 * views/reports/collections.php — collections by day, method and officer.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$paymentModel = new Payment();
$reportModel  = new Report();

$from = (string) ($_GET['from'] ?? '');
$to   = (string) ($_GET['to'] ?? '');

if ($from === '') {
    $from = date('Y-m-01');
}
if ($to === '') {
    $to = date('Y-m-d');
}

if (strtotime($from) > strtotime($to)) {
    [$from, $to] = [$to, $from];
}

$officerId = !empty($_GET['officer']) ? (int) $_GET['officer'] : null;
$officers  = User::isAdmin() ? (new User())->officers() : [];

$summary     = $paymentModel->summary($from, $to, $officerId);
$daily       = $paymentModel->dailyTotals($from, $to, $officerId);
$performance = $reportModel->officerPerformance($from, $to);

$maxDay = 0.0;
foreach ($daily as $day) {
    $maxDay = max($maxDay, (float) $day['total']);
}

$pageTitle = t('Collections report');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Collections report') ?></h4>
    <p><?= t('Receipts from :from to :to', ['from' => formatDate($from), 'to' => formatDate($to)]) ?></p>
  </div>
  <div class="page-actions">
    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()">
      <i class="bi bi-printer me-1"></i><?= t('Print') ?>
    </button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/reports/index.php">
      <i class="bi bi-arrow-left me-1"></i><?= t('Reports') ?>
    </a>
  </div>
</div>

<form class="filter-bar" method="GET" action="<?= BASE_URL ?>views/reports/collections.php">
  <div class="field">
    <label for="from"><?= t('From') ?></label>
    <input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>">
  </div>

  <div class="field">
    <label for="to"><?= t('To') ?></label>
    <input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>">
  </div>

  <?php if (User::isAdmin()): ?>
  <div class="field">
    <label for="officer"><?= t('Officer') ?></label>
    <select class="form-select" id="officer" name="officer" data-auto-submit>
      <option value=""><?= t('All officers') ?></option>
      <?php foreach ($officers as $officer): ?>
        <option value="<?= (int) $officer['id'] ?>" <?= (string) $officer['id'] === (string) $officerId ? 'selected' : '' ?>>
          <?= e($officer['full_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <div class="field">
    <label>&nbsp;</label>
    <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i><?= t('Apply') ?></button>
  </div>
</form>

<div class="kpi-grid">
  <div class="kpi">
    <div class="k-icon"><i class="bi bi-cash-stack"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Total collected') ?></div>
      <div class="k-value"><?= e(money($summary['total'] ?? 0)) ?></div>
      <div class="k-hint"><?= t(':n receipts', ['n' => (int) ($summary['receipts'] ?? 0)]) ?></div>
    </div>
  </div>
  <div class="kpi ink">
    <div class="k-icon"><i class="bi bi-cash"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Cash') ?></div>
      <div class="k-value"><?= e(money($summary['cash'] ?? 0)) ?></div>
      <div class="k-hint"><?= t(':n% of collections', ['n' => percent($summary['cash'] ?? 0, $summary['total'] ?? 0)]) ?></div>
    </div>
  </div>
  <div class="kpi gold">
    <div class="k-icon"><i class="bi bi-phone"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Mobile money') ?></div>
      <div class="k-value"><?= e(money($summary['mobile'] ?? 0)) ?></div>
      <div class="k-hint"><?= t(':n% of collections', ['n' => percent($summary['mobile'] ?? 0, $summary['total'] ?? 0)]) ?></div>
    </div>
  </div>
  <div class="kpi">
    <div class="k-icon"><i class="bi bi-bank"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Bank transfer') ?></div>
      <div class="k-value"><?= e(money($summary['bank'] ?? 0)) ?></div>
      <div class="k-hint"><?= t('Other: :amount', ['amount' => money($summary['other'] ?? 0)]) ?></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">
        <span class="card-title-sm"><i class="bi bi-calendar3 me-1"></i><?= t('Daily collections') ?></span>
      </div>

      <?php if (!$daily): ?>
        <div class="empty-state">
          <i class="bi bi-inbox"></i>
          <strong><?= t('No receipts in this range') ?></strong>
          <p><?= t('Try a wider date range.') ?></p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th><?= t('Date') ?></th>
                <th class="num"><?= t('Receipts') ?></th>
                <th class="num"><?= t('Collected') ?></th>
                <th style="min-width:130px" class="d-none d-md-table-cell"><?= t('Share') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (array_reverse($daily) as $day):
                  $value = (float) $day['total'];
                  $width = $maxDay > 0 ? max(2, ($value / $maxDay) * 100) : 2;
              ?>
                <tr>
                  <td><?= e(formatDate($day['day'])) ?></td>
                  <td class="num"><?= (int) $day['receipts'] ?></td>
                  <td class="num fw-semibold"><?= e(money($value)) ?></td>
                  <td class="d-none d-md-table-cell">
                    <div class="progress-thin"><span style="width: <?= round($width, 1) ?>%"></span></div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <th><?= t('Total') ?></th>
                <th class="num"><?= (int) ($summary['receipts'] ?? 0) ?></th>
                <th class="num"><?= e(money($summary['total'] ?? 0)) ?></th>
                <th></th>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">
        <span class="card-title-sm"><i class="bi bi-person-badge me-1"></i><?= t('Officer performance') ?></span>
      </div>

      <?php if (!$performance): ?>
        <div class="card-body small text-muted"><?= t('No active staff.') ?></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th><?= t('Officer') ?></th>
                <th class="num"><?= t('Receipts') ?></th>
                <th class="num"><?= t('Collected') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($performance as $row): ?>
                <tr>
                  <td>
                    <span class="name"><?= e($row['full_name']) ?></span>
                    <span class="sub"><?= e(t(ucfirst($row['role']))) ?></span>
                  </td>
                  <td class="num"><?= (int) $row['receipts'] ?></td>
                  <td class="num fw-semibold"><?= e(money($row['collected'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span class="card-title-sm"><i class="bi bi-receipt me-1"></i><?= t('Receipts in this range') ?></span>
    <a class="card-link" href="<?= BASE_URL ?>views/payments/index.php?from=<?= e($from) ?>&to=<?= e($to) ?>">
      <?= t('Open ledger') ?>
    </a>
  </div>

  <?php
  $recent = $paymentModel->paginate([
      'from'    => $from,
      'to'      => $to,
      'officer' => $officerId,
  ], 1, 100)['rows'];
  ?>

  <?php if (!$recent): ?>
    <div class="card-body small text-muted"><?= t('No receipts to show.') ?></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th><?= t('Receipt') ?></th>
            <th><?= t('Date') ?></th>
            <th><?= t('Customer') ?></th>
            <th class="d-none d-md-table-cell"><?= t('Loan') ?></th>
            <th><?= t('Method') ?></th>
            <th class="d-none d-lg-table-cell"><?= t('Received by') ?></th>
            <th class="num"><?= t('Amount') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $payment): ?>
            <tr>
              <td><span class="ref"><?= e($payment['payment_reference']) ?></span></td>
              <td><?= e(formatDateTime($payment['payment_date'])) ?></td>
              <td><?= e($payment['customer_name']) ?></td>
              <td class="d-none d-md-table-cell"><span class="ref"><?= e($payment['loan_reference']) ?></span></td>
              <td class="small"><?= e(t(ucwords(str_replace('_', ' ', $payment['method'])))) ?></td>
              <td class="d-none d-lg-table-cell small text-muted"><?= e($payment['received_by_name'] ?? '—') ?></td>
              <td class="num fw-semibold"><?= e(money($payment['amount_paid'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
