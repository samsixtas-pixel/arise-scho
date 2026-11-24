<?php
/**
 * views/reports/index.php — reporting hub.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$report    = new Report();
$portfolio = (new Loan())->portfolio();
$customers = (new Loan())->customerMetrics();
$monthly   = $report->monthlyTrend(6);
$aging     = $report->aging();

$monthlyMax = 0.0;
foreach ($monthly as $month) {
    $monthlyMax = max($monthlyMax, (float) $month['collected']);
}

$overdueCount = array_sum(array_column($aging, 'loans'));
$overdueValue = array_sum(array_column($aging, 'amount'));

$pageTitle = t('Reports');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Reports') ?></h4>
    <p><?= t('Collections, arrears and statements for the whole loan book.') ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>export.php">
      <i class="bi bi-file-earmark-excel me-1"></i><?= t('Workbook export') ?>
    </a>
  </div>
</div>

<div class="kpi-grid">
  <div class="kpi">
    <div class="k-icon"><i class="bi bi-wallet2"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Total collected') ?></div>
      <div class="k-value"><?= e(money($portfolio['total_collected'])) ?></div>
      <div class="k-hint"><?= t(':n% of payable', ['n' => percent($portfolio['total_collected'], $portfolio['total_payable'])]) ?></div>
    </div>
  </div>
  <div class="kpi gold">
    <div class="k-icon"><i class="bi bi-hourglass-split"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Outstanding') ?></div>
      <div class="k-value"><?= e(money($portfolio['total_outstanding'])) ?></div>
      <div class="k-hint"><?= t('across :n open loans', ['n' => (int) $portfolio['open_loans']]) ?></div>
    </div>
  </div>
  <div class="kpi red">
    <div class="k-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Arrears') ?></div>
      <div class="k-value"><?= e(money($overdueValue)) ?></div>
      <div class="k-hint"><?= t(':n overdue loans', ['n' => (int) $overdueCount]) ?></div>
    </div>
  </div>
  <div class="kpi ink">
    <div class="k-icon"><i class="bi bi-people-fill"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Borrowers') ?></div>
      <div class="k-value"><?= (int) $customers['borrowers'] ?></div>
      <div class="k-hint"><?= t('of :n customers', ['n' => (int) $customers['total_customers']]) ?></div>
    </div>
  </div>
</div>

<div class="row g-3">

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">
        <span class="card-title-sm"><i class="bi bi-graph-up me-1"></i><?= t('Monthly collections') ?></span>
      </div>
      <div class="card-body">
        <?php if (!$monthly): ?>
          <div class="empty-state">
            <i class="bi bi-bar-chart"></i>
            <strong><?= t('No collections yet') ?></strong>
            <p><?= t('The trend appears once receipts are recorded.') ?></p>
          </div>
        <?php else: ?>
          <div class="chart-bars">
            <?php foreach ($monthly as $month):
                $value = (float) $month['collected'];
                $width = $monthlyMax > 0 ? max(2, ($value / $monthlyMax) * 100) : 2;
            ?>
              <div class="bar-row">
                <span class="bar-label"><?= e(date('M Y', strtotime($month['ym'] . '-01'))) ?></span>
                <span class="bar-track"><span class="bar-fill" style="width: <?= round($width, 1) ?>%"></span></span>
                <span class="bar-value"><?= e(num($value)) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">
        <span class="card-title-sm"><i class="bi bi-clock-history me-1"></i><?= t('Arrears ageing') ?></span>
      </div>
      <div class="card-body">
        <?php foreach ($aging as $label => $bucket): ?>
          <div class="d-flex justify-content-between align-items-center py-1">
            <span class="pill <?= $label === '60+' ? 'pill-red' : ($label === '1-7' ? 'pill-gold' : 'pill-grey') ?>"><?= e($label) ?> <?= t('days') ?></span>
            <span class="small text-muted"><?= (int) $bucket['loans'] ?> <?= t('loans') ?></span>
            <strong class="small text-tabular"><?= e(money($bucket['amount'])) ?></strong>
          </div>
        <?php endforeach; ?>
        <div class="divide-row"></div>
        <a class="btn btn-outline-secondary btn-sm w-100" href="<?= BASE_URL ?>views/reports/arrears.php">
          <i class="bi bi-list-check me-1"></i><?= t('Open arrears report') ?>
        </a>
      </div>
    </div>
  </div>

  <div class="col-md-6 col-lg-3">
    <a class="report-card" href="<?= BASE_URL ?>views/reports/collections.php">
      <span class="r-icon bg-success"><i class="bi bi-cash-stack"></i></span>
      <span><span class="r-title"><?= t('Collections') ?></span><br>
      <span class="r-desc"><?= t('Daily receipts, methods and officer performance for any date range.') ?></span></span>
    </a>
  </div>

  <div class="col-md-6 col-lg-3">
    <a class="report-card" href="<?= BASE_URL ?>views/reports/arrears.php">
      <span class="r-icon bg-danger"><i class="bi bi-exclamation-triangle"></i></span>
      <span><span class="r-title"><?= t('Arrears') ?></span><br>
      <span class="r-desc"><?= t('Ageing buckets and the full overdue list, ready to print.') ?></span></span>
    </a>
  </div>

  <div class="col-md-6 col-lg-3">
    <a class="report-card" href="<?= BASE_URL ?>views/reports/statement.php">
      <span class="r-icon bg-primary"><i class="bi bi-file-earmark-text"></i></span>
      <span><span class="r-title"><?= t('Loan statement') ?></span><br>
      <span class="r-desc"><?= t('One loan’s terms, payment history and running balance.') ?></span></span>
    </a>
  </div>

  <div class="col-md-6 col-lg-3">
    <a class="report-card" href="<?= BASE_URL ?>export.php">
      <span class="r-icon bg-warning"><i class="bi bi-file-earmark-excel"></i></span>
      <span><span class="r-title"><?= t('Workbook export') ?></span><br>
      <span class="r-desc"><?= t('Download the seven-sheet Excel workbook with live data.') ?></span></span>
    </a>
  </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
