<?php
/**
 * views/reports/arrears.php — ageing summary and full overdue register.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$reportModel = new Report();
$db          = Database::getConnection();

$filters = [
    'q'      => trim((string) ($_GET['q'] ?? '')),
    'bucket' => (string) ($_GET['bucket'] ?? ''),
];

$where  = ['1=1'];
$params = [];

if ($filters['q'] !== '') {
    $where[] = "(customer_name LIKE :q OR phone_number LIKE :q OR loan_reference LIKE :q)";
    $params['q'] = '%' . $filters['q'] . '%';
}

switch ($filters['bucket']) {
    case '1-7':
        $where[] = "days_overdue BETWEEN 1 AND 7";
        break;
    case '8-14':
        $where[] = "days_overdue BETWEEN 8 AND 14";
        break;
    case '15-30':
        $where[] = "days_overdue BETWEEN 15 AND 30";
        break;
    case '31-60':
        $where[] = "days_overdue BETWEEN 31 AND 60";
        break;
    case '60+':
        $where[] = "days_overdue > 60";
        break;
}

$stmt = $db->prepare(
    "SELECT * FROM v_overdue_loans
      WHERE " . implode(' AND ', $where) . "
      ORDER BY days_overdue DESC, remaining_balance DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalBalance = 0.0;
$totalPayable = 0.0;

foreach ($rows as $row) {
    $totalBalance += (float) $row['remaining_balance'];
    $totalPayable += (float) $row['total_amount_payable'];
}

$aging      = $reportModel->aging();
$agingTotal = array_sum(array_column($aging, 'amount'));
$agingLoans = array_sum(array_column($aging, 'loans'));

$pageTitle = t('Arrears report');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Arrears report') ?></h4>
    <p><?= t('Overdue loans by ageing bucket as at :date', ['date' => date(DISPLAY_DATE_FORMAT)]) ?></p>
  </div>
  <div class="page-actions">
    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()">
      <i class="bi bi-printer me-1"></i><?= t('Print') ?>
    </button>
    <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/overdue/index.php">
      <i class="bi bi-exclamation-triangle me-1"></i><?= t('Follow-up list') ?>
    </a>
  </div>
</div>

<div class="kpi-grid">
  <div class="kpi red">
    <div class="k-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Total arrears') ?></div>
      <div class="k-value"><?= e(money($agingTotal)) ?></div>
      <div class="k-hint"><?= t('across :n overdue loans', ['n' => (int) $agingLoans]) ?></div>
    </div>
  </div>
  <div class="kpi gold">
    <div class="k-icon"><i class="bi bi-hourglass-split"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Portfolio at risk') ?></div>
      <div class="k-value"><?= e(percent($agingTotal, $totalPayable + $agingTotal) . '%') ?></div>
      <div class="k-hint"><?= t('of the amount payable') ?></div>
    </div>
  </div>
  <div class="kpi ink">
    <div class="k-icon"><i class="bi bi-list-ol"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Worst bucket') ?></div>
      <div class="k-value">60+ <?= t('days') ?></div>
      <div class="k-hint"><?= (int) $aging['60+']['loans'] ?> <?= t('loans') ?> · <?= e(money($aging['60+']['amount'])) ?></div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <span class="card-title-sm"><i class="bi bi-clock-history me-1"></i><?= t('Ageing summary') ?></span>
  </div>
  <div class="table-wrap">
    <table class="table align-middle mb-0">
      <thead>
        <tr>
          <th><?= t('Bucket') ?></th>
          <th class="num"><?= t('Loans') ?></th>
          <th class="num"><?= t('Balance') ?></th>
          <th class="num"><?= t('Share of arrears') ?></th>
          <th style="min-width:140px" class="d-none d-md-table-cell"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($aging as $label => $bucket): ?>
          <tr>
            <td>
              <a class="text-decoration-none" href="<?= BASE_URL ?>views/reports/arrears.php?bucket=<?= e($label) ?>">
                <span class="pill <?= $label === '60+' ? 'pill-red' : ($label === '1-7' ? 'pill-gold' : 'pill-grey') ?>"><?= e($label) ?> <?= t('days') ?></span>
              </a>
            </td>
            <td class="num"><?= (int) $bucket['loans'] ?></td>
            <td class="num fw-semibold"><?= e(money($bucket['amount'])) ?></td>
            <td class="num"><?= e(percent($bucket['amount'], $agingTotal) . '%') ?></td>
            <td class="d-none d-md-table-cell">
              <div class="progress-thin <?= $label === '60+' ? 'red' : ($label === '1-7' ? 'gold' : '') ?>">
                <span style="width: <?= round(percent($bucket['amount'], $agingTotal), 1) ?>%"></span>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th><?= t('Total') ?></th>
          <th class="num"><?= (int) $agingLoans ?></th>
          <th class="num"><?= e(money($agingTotal)) ?></th>
          <th colspan="2"></th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<form class="filter-bar" method="GET" action="<?= BASE_URL ?>views/reports/arrears.php">
  <div class="field grow">
    <label for="q"><?= t('Search') ?></label>
    <input class="form-control" type="search" id="q" name="q" value="<?= e($filters['q']) ?>"
           placeholder="<?= t('Customer, phone or loan') ?>">
  </div>

  <div class="field">
    <label for="bucket"><?= t('Ageing') ?></label>
    <select class="form-select" id="bucket" name="bucket" data-auto-submit>
      <option value=""><?= t('All overdue') ?></option>
      <?php foreach (['1-7' => '1–7 days', '8-14' => '8–14 days', '15-30' => '15–30 days', '31-60' => '31–60 days', '60+' => 'Over 60 days'] as $key => $label): ?>
        <option value="<?= $key ?>" <?= $filters['bucket'] === $key ? 'selected' : '' ?>><?= e(t($label)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label>&nbsp;</label>
    <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i><?= t('Filter') ?></button>
  </div>

  <?php if (array_filter($filters)): ?>
  <div class="field">
    <label>&nbsp;</label>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>views/reports/arrears.php"><?= t('Clear') ?></a>
  </div>
  <?php endif; ?>
</form>

<div class="card">
  <div class="card-header">
    <span class="card-title-sm"><i class="bi bi-list-check me-1"></i><?= t('Overdue register') ?> (<?= count($rows) ?>)</span>
  </div>

  <?php if (!$rows): ?>
    <div class="empty-state">
      <i class="bi bi-emoji-smile"></i>
      <strong><?= t('No overdue loans match this filter') ?></strong>
      <p><?= t('Clear the filters to see the whole arrears book.') ?></p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th><?= t('Loan') ?></th>
            <th><?= t('Customer') ?></th>
            <th class="d-none d-lg-table-cell"><?= t('Issued') ?></th>
            <th class="num d-none d-md-table-cell"><?= t('Payable') ?></th>
            <th class="num d-none d-md-table-cell"><?= t('Paid') ?></th>
            <th class="num"><?= t('Balance') ?></th>
            <th class="num"><?= t('Days late') ?></th>
            <th><?= t('Status') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr class="row-danger">
              <td>
                <a class="ref text-decoration-none" href="<?= BASE_URL ?>views/loans/view.php?id=<?= (int) $row['loan_id'] ?>">
                  <?= e($row['loan_reference']) ?>
                </a>
              </td>
              <td>
                <span class="name"><?= e($row['customer_name']) ?></span>
                <span class="sub"><?= e(formatPhone($row['phone_number']) ?: t('no phone')) ?></span>
              </td>
              <td class="d-none d-lg-table-cell"><?= e(formatDate($row['loan_date'])) ?></td>
              <td class="num d-none d-md-table-cell"><?= e(num($row['total_amount_payable'])) ?></td>
              <td class="num d-none d-md-table-cell"><?= e(num($row['amount_paid'])) ?></td>
              <td class="num fw-semibold"><?= e(num($row['remaining_balance'])) ?></td>
              <td class="num"><span class="pill pill-red"><?= (int) $row['days_overdue'] ?></span></td>
              <td><?= loanBadge($row['loan_status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="5"><?= t('Total') ?></th>
            <th class="num"><?= e(num($totalBalance)) ?></th>
            <th colspan="2"></th>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
