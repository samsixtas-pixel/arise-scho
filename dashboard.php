<?php
/**
 * dashboard.php — the daily control panel.
 */

require_once __DIR__ . '/includes/autoload.php';
require_once __DIR__ . '/includes/charts.php';
User::requireLogin();

$report = new Report();
$data   = $report->dashboard();

$portfolio = $data['portfolio'];
$customers = $data['customers'];
$weekly    = $data['weekly'];
$today     = $data['today'];
$aging     = $data['aging'];

$pageTitle = t('Dashboard');
require_once __DIR__ . '/includes/header.php';

$hour     = (int) date('G');
$greeting = $hour < 12 ? t('Good morning') : ($hour < 17 ? t('Good afternoon') : t('Good evening'));

$openLoans = (float) $portfolio['open_loans'];
$paidLoans = (float) $portfolio['paid_loans'];
$total     = (float) $portfolio['total_loans'];

$palette = chart_palette();

$weeklySeries = [];
$weeklyTotal  = 0.0;

foreach ($weekly as $week) {
    $value         = (float) $week['collected'];
    $weeklyTotal  += $value;
    $weeklySeries[] = [
        'label' => formatDate($week['week_start'], ''),
        'value' => $value,
    ];
}

$statusSeries = [
    ['label' => t('Active'),   'value' => (int) $portfolio['active_loans'],   'color' => $palette['blue']],
    ['label' => t('Due'),      'value' => (int) $portfolio['due_loans'],      'color' => $palette['red']],
    ['label' => t('Overdue'),  'value' => (int) $portfolio['overdue_loans'],  'color' => $palette['green']],
    ['label' => t('Paid'),     'value' => (int) $portfolio['paid_loans'],     'color' => $palette['purple']],
    ['label' => t('Overpaid'), 'value' => (int) $portfolio['overpaid_loans'], 'color' => $palette['teal']],
];

$moneySeries = [
    ['label' => t('Collected'),   'value' => (float) $portfolio['total_collected'],   'color' => $palette['blue']],
    ['label' => t('Outstanding'), 'value' => (float) $portfolio['total_outstanding'], 'color' => $palette['gold']],
    ['label' => t('Overdue'),     'value' => (float) $portfolio['total_overdue'],     'color' => $palette['red']],
];
?>

<div class="page-head">
  <div>
    <h4><?= e($greeting) ?>, <?= e(explode(' ', (string) ($_SESSION['full_name'] ?? ''))[0]) ?></h4>
    <p><?= e(date(DISPLAY_DATE_FORMAT)) ?> · <?= t('Here is where the loan book stands today.') ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/payments/add.php">
      <i class="bi bi-plus-circle me-1"></i><?= t('Record payment') ?>
    </a>
    <a class="btn btn-outline-primary btn-sm" href="<?= BASE_URL ?>views/loans/add.php">
      <i class="bi bi-cash-coin me-1"></i><?= t('New loan') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/customers/add.php">
      <i class="bi bi-person-plus me-1"></i><?= t('New customer') ?>
    </a>
  </div>
</div>

<!-- ── graphs first, numbers underneath ───────────────────── -->
<div class="chart-grid">

  <div class="card chart-card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span class="card-title-sm"><i class="bi bi-bar-chart-line-fill me-1"></i><?= t('Collections trend') ?></span>
      <a class="card-link" href="<?= BASE_URL ?>views/reports/collections.php"><?= t('View report') ?></a>
    </div>
    <div class="card-body">
      <div class="chart-head">
        <div>
          <div class="chart-total"><?= e(money($weeklyTotal)) ?></div>
          <div class="chart-sub"><?= t('Last :n weeks', ['n' => count($weekly)]) ?></div>
        </div>
        <div class="text-end">
          <div class="chart-total"><?= e(money($data['week_total'])) ?></div>
          <div class="chart-sub"><?= t('This week') ?></div>
        </div>
      </div>
      <?= svg_column_chart($weeklySeries, [
            'title'       => t('Weekly collections'),
            'empty_text'  => t('Collections will appear here as soon as the first receipt is recorded.'),
            'height'      => 238,
            'color'       => $palette['blue'],
            'gradient'    => 'chart-weekly',
            'label_every' => count($weeklySeries) > 9 ? 2 : 1,
          ]) ?>
    </div>
  </div>

  <div class="card chart-card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span class="card-title-sm"><i class="bi bi-pie-chart-fill me-1"></i><?= t('Portfolio by status') ?></span>
      <a class="card-link" href="<?= BASE_URL ?>views/loans/index.php"><?= t('All loans') ?></a>
    </div>
    <div class="card-body">
      <div class="chart-head">
        <div>
          <div class="chart-total"><?= (int) $total ?> <?= t('loans') ?></div>
          <div class="chart-sub"><?= t('Live loan status mix') ?></div>
        </div>
        <div class="text-end">
          <div class="chart-total text-danger"><?= (int) $portfolio['overdue_loans'] ?></div>
          <div class="chart-sub"><?= t('Overdue') ?></div>
        </div>
      </div>
      <?= svg_donut_chart($statusSeries, ['title' => t('Loan status distribution')]) ?>
    </div>
  </div>

  <div class="card chart-card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span class="card-title-sm"><i class="bi bi-graph-up-arrow me-1"></i><?= t('Collections vs book') ?></span>
      <a class="card-link" href="<?= BASE_URL ?>views/reports/arrears.php"><?= t('Arrears') ?></a>
    </div>
    <div class="card-body">
      <div class="chart-head">
        <div>
          <div class="chart-total"><?= e(percent($portfolio['total_collected'], $portfolio['total_payable'])) ?>%</div>
          <div class="chart-sub"><?= t('of the amount payable') ?></div>
        </div>
        <div class="text-end">
          <div class="chart-total"><?= e(money($portfolio['total_outstanding'])) ?></div>
          <div class="chart-sub"><?= t('Outstanding') ?></div>
        </div>
      </div>
      <?= svg_column_chart($moneySeries, [
            'title'       => t('Collections vs outstanding vs overdue'),
            'height'      => 238,
            'show_values' => true,
            'value_prefix'=> 'TZS ',
            'pad_left'    => 48,
          ]) ?>
    </div>
  </div>

</div>

<!-- ── money KPIs ─────────────────────────────────────────── -->
<div class="kpi-grid">
  <div class="kpi">
    <div class="k-icon"><i class="bi bi-wallet2"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Total collected') ?></div>
      <div class="k-value"><?= e(money($portfolio['total_collected'])) ?></div>
      <div class="k-hint"><?= t(':n% of the amount payable', ['n' => percent($portfolio['total_collected'], $portfolio['total_payable'])]) ?></div>
    </div>
  </div>

  <div class="kpi gold">
    <div class="k-icon"><i class="bi bi-hourglass-split"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Outstanding') ?></div>
      <div class="k-value"><?= e(money($portfolio['total_outstanding'])) ?></div>
      <div class="k-hint"><?= t('Across :n open loans', ['n' => (int) $portfolio['open_loans']]) ?></div>
    </div>
  </div>

  <div class="kpi red">
    <div class="k-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Overdue balance') ?></div>
      <div class="k-value"><?= e(money($portfolio['total_overdue'])) ?></div>
      <div class="k-hint"><?= t(':n loans past due', ['n' => (int) $portfolio['overdue_loans']]) ?></div>
    </div>
  </div>

  <div class="kpi ink">
    <div class="k-icon"><i class="bi bi-cash-stack"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Collected today') ?></div>
      <div class="k-value"><?= e(money($today['total'] ?? 0)) ?></div>
      <div class="k-hint"><?= t(':n receipts', ['n' => (int) ($today['receipts'] ?? 0)]) ?></div>
    </div>
  </div>
</div>

<div class="row g-3">

  <!-- ── collection summary ───────────────────────────────── -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title-sm"><i class="bi bi-cash-coin me-1"></i><?= t('Collections summary') ?></span>
        <a class="card-link" href="<?= BASE_URL ?>views/reports/collections.php"><?= t('View report') ?></a>
      </div>
      <div class="card-body">
        <div class="detail-list">
          <div>
            <dt><?= t('Collected today') ?></dt>
            <dd><?= e(money($today['total'] ?? 0)) ?></dd>
          </div>
          <div>
            <dt><?= t('This week') ?></dt>
            <dd><?= e(money($data['week_total'])) ?></dd>
          </div>
          <div>
            <dt><?= t('This month') ?></dt>
            <dd><?= e(money($data['month_total'])) ?></dd>
          </div>
          <div>
            <dt><?= t('Last :n weeks', ['n' => count($weekly)]) ?></dt>
            <dd><?= e(money($weeklyTotal)) ?></dd>
          </div>
          <div>
            <dt><?= t('Average per week') ?></dt>
            <dd><?= e(money(count($weekly) > 0 ? $weeklyTotal / count($weekly) : 0)) ?></dd>
          </div>
        </div>

        <div class="d-flex justify-content-between align-items-end mt-3 mb-1">
          <span class="small text-muted"><?= t('Collection efficiency') ?></span>
          <strong class="text-tabular"><?= e(percent($portfolio['total_collected'], $portfolio['total_payable'])) ?>%</strong>
        </div>
        <div class="progress-thin">
          <span style="width: <?= round(percent($portfolio['total_collected'], $portfolio['total_payable']), 1) ?>%"></span>
        </div>
        <div class="small text-muted mt-2">
          <?= t(':paid collected of :payable payable', [
                'paid'    => money($portfolio['total_collected']),
                'payable' => money($portfolio['total_payable']),
              ]) ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ── portfolio health ─────────────────────────────────── -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">
        <span class="card-title-sm"><i class="bi bi-pie-chart-fill me-1"></i><?= t('Portfolio health') ?></span>
      </div>
      <div class="card-body">

        <div class="d-flex justify-content-between align-items-end mb-1">
          <span class="small text-muted"><?= t('Repayment progress') ?></span>
          <strong class="text-tabular"><?= e(percent($portfolio['total_collected'], $portfolio['total_payable'])) ?>%</strong>
        </div>
        <div class="progress-thin mb-3">
          <span style="width: <?= round(percent($portfolio['total_collected'], $portfolio['total_payable']), 1) ?>%"></span>
        </div>

        <div class="detail-list">
          <div>
            <dt><?= t('Total loans') ?></dt>
            <dd><?= (int) $total ?></dd>
          </div>
          <div>
            <dt><?= t('Open loans') ?></dt>
            <dd><?= (int) $openLoans ?></dd>
          </div>
          <div>
            <dt><?= t('Paid off') ?></dt>
            <dd class="text-success"><?= (int) $paidLoans ?></dd>
          </div>
          <div>
            <dt><?= t('Active borrowers') ?></dt>
            <dd><?= (int) $customers['borrowers'] ?> / <?= (int) $customers['total_customers'] ?></dd>
          </div>
          <div>
            <dt><?= t('Average loan size') ?></dt>
            <dd><?= e(money($total > 0 ? $portfolio['total_principal'] / $total : 0)) ?></dd>
          </div>
        </div>

        <?php if ((int) $data['incomplete'] > 0): ?>
          <a class="d-flex align-items-center gap-2 mt-3 text-decoration-none" href="<?= BASE_URL ?>views/customers/index.php?data_status=incomplete">
            <span class="pill pill-gold"><i class="bi bi-person-exclamation"></i><?= (int) $data['incomplete'] ?> <?= t('incomplete') ?></span>
            <span class="small text-muted"><?= t('Customer records need NIDA or a photo') ?></span>
          </a>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- ── overdue ──────────────────────────────────────────── -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title-sm"><i class="bi bi-exclamation-triangle-fill me-1 text-danger"></i><?= t('Follow up today') ?></span>
        <a class="card-link" href="<?= BASE_URL ?>views/overdue/index.php"><?= t('All overdue') ?></a>
      </div>

      <?php if (empty($data['top_overdue'])): ?>
        <div class="empty-state">
          <i class="bi bi-emoji-smile"></i>
          <strong><?= t('No overdue loans') ?></strong>
          <p><?= t('Every customer is up to date. Nice work.') ?></p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th><?= t('Customer') ?></th>
                <th><?= t('Loan') ?></th>
                <th class="num"><?= t('Balance') ?></th>
                <th class="num"><?= t('Days late') ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data['top_overdue'] as $loan): ?>
                <tr class="row-danger">
                  <td>
                    <span class="name"><?= e($loan['customer_name']) ?></span>
                    <span class="sub"><?= e(formatPhone($loan['phone_number']) ?: t('no phone')) ?></span>
                  </td>
                  <td><span class="ref"><?= e($loan['loan_reference']) ?></span></td>
                  <td class="num"><?= e(money($loan['remaining_balance'])) ?></td>
                  <td class="num"><span class="pill pill-red"><?= (int) $loan['days_overdue'] ?></span></td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-primary btn-icon" title="<?= t('Record payment') ?>"
                       href="<?= BASE_URL ?>views/payments/add.php?loan_id=<?= (int) $loan['loan_id'] ?>">
                      <i class="bi bi-cash"></i>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── ageing ───────────────────────────────────────────── -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">
        <span class="card-title-sm"><i class="bi bi-clock-history me-1"></i><?= t('Arrears ageing') ?></span>
      </div>
      <div class="card-body">
        <?php foreach ($aging as $label => $bucket): ?>
          <div class="d-flex justify-content-between align-items-center py-1">
            <span class="small">
              <span class="pill <?= $label === '60+' ? 'pill-red' : ($label === '1-7' ? 'pill-gold' : 'pill-grey') ?>"><?= e($label) ?> <?= t('days') ?></span>
            </span>
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

  <!-- ── recent receipts ──────────────────────────────────── -->
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title-sm"><i class="bi bi-receipt me-1"></i><?= t('Latest receipts') ?></span>
        <a class="card-link" href="<?= BASE_URL ?>views/payments/index.php"><?= t('All payments') ?></a>
      </div>

      <?php if (empty($data['recent_payments'])): ?>
        <div class="empty-state">
          <i class="bi bi-receipt"></i>
          <strong><?= t('No payments recorded yet') ?></strong>
          <p><?= t('Record the first repayment to start the trail.') ?></p>
          <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/payments/add.php"><?= t('Record payment') ?></a>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th><?= t('Receipt') ?></th>
                <th><?= t('Customer') ?></th>
                <th class="d-none d-md-table-cell"><?= t('Loan') ?></th>
                <th class="d-none d-lg-table-cell"><?= t('Date') ?></th>
                <th class="num"><?= t('Amount') ?></th>
                <th class="d-none d-md-table-cell"><?= t('Received by') ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data['recent_payments'] as $payment): ?>
                <tr>
                  <td><span class="ref"><?= e($payment['payment_reference']) ?></span></td>
                  <td>
                    <span class="name"><?= e($payment['customer_name']) ?></span>
                    <span class="sub"><?= e($payment['loan_reference']) ?></span>
                  </td>
                  <td class="d-none d-md-table-cell"><span class="ref"><?= e($payment['loan_reference']) ?></span></td>
                  <td class="d-none d-lg-table-cell"><?= e(formatDateTime($payment['payment_date'])) ?></td>
                  <td class="num fw-semibold"><?= e(money($payment['amount_paid'])) ?></td>
                  <td class="d-none d-md-table-cell small text-muted"><?= e($payment['received_by_name'] ?? '—') ?></td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-secondary btn-icon" title="<?= t('Receipt') ?>"
                       href="<?= BASE_URL ?>views/payments/receipt.php?id=<?= (int) $payment['payment_id'] ?>">
                      <i class="bi bi-printer"></i>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
