<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/charts.php';

use School\Core\SchoolAuth;
use School\Repositories\SchoolAnalyticsRepository;

SchoolAuth::requireLogin();
$user = SchoolAuth::user();
if (!$user) { header('Location: ' . SCHOOL_BASE_URL . 'login.php'); exit; }
$role = school_current_role($user);

$canSchool = SchoolAuth::hasPermission('analytics.school');
$canAcademic = SchoolAuth::hasPermission('analytics.academic');
$canFinance = SchoolAuth::hasPermission('analytics.finance');
$canAssigned = SchoolAuth::hasPermission('analytics.assigned');
$canReports = SchoolAuth::hasPermission('reports.view');
if (!$canSchool && !$canAcademic && !$canFinance && !$canAssigned && !$canReports) {
    http_response_code(403);
    exit('Analytics access is not permitted for your account.');
}

$repo = new SchoolAnalyticsRepository();
$filters = [
    'year_id' => (int) ($_GET['year_id'] ?? 0),
    'term_id' => (int) ($_GET['term_id'] ?? 0),
    'class_id' => (int) ($_GET['class_id'] ?? 0),
    'from' => (string) ($_GET['from'] ?? ''),
    'to' => (string) ($_GET['to'] ?? ''),
];
$data = $repo->data($user, $role, $filters);
$context = $data['context'];
$kpis = $data['kpis'];
$finance = $data['finance'];
$academic = $data['academic'];
$palette = chart_palette();

$schoolName = (string) ($context['school']['name'] ?? 'School Management');
$pageTitle = 'Insights & analytics';
$yearLabel = $context['year']['name'] ?? 'All years';
$termLabel = $context['term']['name'] ?? 'All terms';
$classLabel = $context['class']['name'] ?? 'All classes';
$genderTotal = array_sum($data['gender']);
$genderSeries = [
    ['label' => 'Female', 'value' => $data['gender']['female'], 'color' => '#C9808E'],
    ['label' => 'Male', 'value' => $data['gender']['male'], 'color' => '#4F81BD'],
    ['label' => 'Other', 'value' => $data['gender']['other'], 'color' => '#8064A2'],
    ['label' => 'Undisclosed', 'value' => $data['gender']['undisclosed'], 'color' => '#B9C6C0'],
];
$admissionSeries = $data['admission_trend'];
$admissionTotal = array_sum(array_column($admissionSeries, 'value'));
$attendanceRate = (float) $kpis['attendance_rate'];
$feeRate = (float) $finance['collection_rate'];
$exportQuery = http_build_query(array_filter($filters, static fn($value): bool => (int) $value !== 0 && $value !== ''));
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div><h4>Insights &amp; analytics</h4><p>Live, permission-scoped analysis of enrolment, attendance, results and fees.</p></div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary btn-sm" href="<?= SCHOOL_BASE_URL ?>insights/index.php"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</a>
    <a class="btn btn-outline-primary btn-sm" href="<?= SCHOOL_BASE_URL ?>insights/export.php?<?= e($exportQuery) ?>"><i class="bi bi-filetype-csv me-1"></i>Export CSV</a>
    <button class="btn btn-primary btn-sm" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print report</button>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="analytics-filters">
      <div><label class="form-label">Academic year</label><select class="form-select form-select-sm" name="year_id"><option value="0">All years</option><?php foreach ($data['options']['years'] as $year): ?><option value="<?= (int) $year['id'] ?>" <?= (int) ($context['year']['id'] ?? 0) === (int) $year['id'] ? 'selected' : '' ?>><?= e($year['name']) ?></option><?php endforeach; ?></select></div>
      <div><label class="form-label">Term</label><select class="form-select form-select-sm" name="term_id"><option value="0">All terms</option><?php foreach ($data['options']['terms'] as $term): ?><option value="<?= (int) $term['id'] ?>" <?= (int) ($context['term']['id'] ?? 0) === (int) $term['id'] ? 'selected' : '' ?>><?= e($term['year_name'] . ' · ' . $term['name']) ?></option><?php endforeach; ?></select></div>
      <div><label class="form-label">Class</label><select class="form-select form-select-sm" name="class_id"><option value="0">All classes</option><?php foreach ($data['options']['classes'] as $class): ?><option value="<?= (int) $class['id'] ?>" <?= (int) ($context['class']['id'] ?? 0) === (int) $class['id'] ? 'selected' : '' ?>><?= e($class['year_name'] . ' · ' . $class['name']) ?></option><?php endforeach; ?></select></div>
      <div><label class="form-label">From</label><input class="form-control form-control-sm" type="date" name="from" value="<?= e($context['from']) ?>"></div>
      <div><label class="form-label">To</label><input class="form-control form-control-sm" type="date" name="to" value="<?= e($context['to']) ?>"></div>
      <div><button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Apply filters</button></div>
    </form>
    <div class="analytics-meta mt-2">
      <span class="pill"><i class="bi bi-calendar3"></i><?= e(formatDate($context['from'])) ?> – <?= e(formatDate($context['to'])) ?></span>
      <span class="pill"><i class="bi bi-mortarboard"></i><?= e($yearLabel) ?></span>
      <span class="pill"><i class="bi bi-journal-bookmark"></i><?= e($termLabel) ?></span>
      <span class="pill"><i class="bi bi-door-open"></i><?= e($classLabel) ?></span>
      <span class="pill"><i class="bi bi-shield-check"></i><?= e(school_role_label($role)) ?></span>
    </div>
  </div>
</div>

<div class="kpi-grid mb-3">
  <div class="kpi"><div class="k-icon"><i class="bi bi-people-fill"></i></div><div class="k-body"><div class="k-label">Active learners</div><div class="k-value"><?= number_format($kpis['students']) ?></div><div class="k-hint"><?= number_format($kpis['classes']) ?> classes in scope</div></div></div>
  <div class="kpi gold"><div class="k-icon"><i class="bi bi-calendar2-check-fill"></i></div><div class="k-body"><div class="k-label">Attendance rate</div><div class="k-value"><?= e(num($attendanceRate)) ?>%</div><div class="k-hint"><?= number_format($kpis['marked_total']) ?> records marked</div></div></div>
  <div class="kpi red"><div class="k-icon"><i class="bi bi-journal-check"></i></div><div class="k-body"><div class="k-label">Average mark</div><div class="k-value"><?= e(num($kpis['average_mark'])) ?>%</div><div class="k-hint"><?= e(num($kpis['pass_rate'])) ?>% pass rate</div></div></div>
  <div class="kpi ink"><div class="k-icon"><i class="bi bi-cash-stack"></i></div><div class="k-body"><div class="k-label">Fee collection</div><div class="k-value"><?= e(num($feeRate)) ?>%</div><div class="k-hint"><?= e(money($finance['outstanding'])) ?> outstanding</div></div></div>
</div>

<div class="analytics-grid">
  <div class="card chart-card span-8">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-graph-up-arrow me-1"></i>Enrolment trend</span><span class="card-link">Last 12 months to <?= e(formatDate($context['to'])) ?></span></div>
    <div class="card-body">
      <div class="chart-head"><div><div class="chart-total"><?= number_format($admissionTotal) ?> new admissions</div><div class="chart-sub">Monthly student admissions</div></div><div class="text-end"><div class="chart-total"><?= number_format($kpis['students']) ?></div><div class="chart-sub">Active learners now</div></div></div>
      <?= svg_line_chart([['label' => 'Admissions', 'color' => $palette['green'], 'fill' => true, 'points' => $admissionSeries]], ['title' => 'Monthly admissions', 'height' => 250, 'empty_text' => 'Admissions will appear once students are registered.', 'area' => true]) ?>
    </div>
  </div>

  <div class="card chart-card span-4">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-pie-chart-fill me-1"></i>Attendance mix</span><span class="card-link"><?= e(formatDate($context['from'])) ?> – <?= e(formatDate($context['to'])) ?></span></div>
    <div class="card-body">
      <?= svg_donut_chart($data['attendance_status'], ['title' => 'Attendance status', 'center_value' => num($attendanceRate) . '%', 'center_label' => 'present']) ?>
    </div>
  </div>

  <div class="card chart-card span-8">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-activity me-1"></i>Daily attendance rate</span><span class="card-link">Present ÷ marked</span></div>
    <div class="card-body"><?= svg_line_chart([['label' => 'Attendance rate', 'color' => $palette['teal'], 'fill' => true, 'points' => $data['attendance_trend']]], ['title' => 'Daily attendance rate', 'height' => 240, 'value_suffix' => '%', 'y_max' => 100, 'empty_text' => 'Attendance records will populate this chart.', 'area' => true]) ?></div>
  </div>

  <div class="card chart-card span-4">
    <div class="card-header"><span class="card-title-sm"><i class="bi bi-gender-ambiguous me-1"></i>Learner gender</span></div>
    <div class="card-body"><?= svg_donut_chart($genderSeries, ['title' => 'Gender distribution', 'center_value' => num($genderTotal), 'center_label' => 'learners']) ?></div>
  </div>

  <div class="card chart-card span-8">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-bar-chart-steps me-1"></i>Attendance by class</span><span class="card-link">Present, absent and late</span></div>
    <div class="card-body"><?= svg_grouped_bar_chart($data['attendance_by_class']['labels'], [
        ['label' => 'Present', 'color' => '#169E6B', 'values' => $data['attendance_by_class']['present']],
        ['label' => 'Absent', 'color' => '#DC3545', 'values' => $data['attendance_by_class']['absent']],
        ['label' => 'Late', 'color' => '#C98012', 'values' => $data['attendance_by_class']['late']],
    ], ['title' => 'Attendance by class', 'height' => 280, 'empty_text' => 'Class attendance will appear once registers are marked.']) ?></div>
  </div>

  <div class="card chart-card span-4">
    <div class="card-header"><span class="card-title-sm"><i class="bi bi-people me-1"></i>Class distribution</span></div>
    <div class="card-body"><?= svg_ranked_bars($data['class_distribution'], ['color' => $palette['blue'], 'empty_text' => 'Active enrolments will appear here.']) ?></div>
  </div>

  <?php if ($canFinance || $canSchool): ?>
  <div class="card chart-card span-8">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-cash-coin me-1"></i>Fees collected by month</span><span class="card-link">TZS · successful allocations</span></div>
    <div class="card-body">
      <div class="chart-head"><div><div class="chart-total"><?= e(money($finance['paid'])) ?> collected</div><div class="chart-sub">Against <?= e(money($finance['charged'])) ?> charged</div></div><div class="text-end"><div class="chart-total"><?= e(money($finance['outstanding'])) ?></div><div class="chart-sub">Outstanding balance</div></div></div>
      <?= svg_grouped_bar_chart($finance['monthly']['labels'], [
          ['label' => 'Collected', 'color' => '#169E6B', 'values' => $finance['monthly']['collected']],
          ['label' => 'Outstanding (current)', 'color' => '#C98012', 'values' => $finance['monthly']['outstanding']],
      ], ['title' => 'Monthly fee collections', 'height' => 270, 'value_prefix' => 'TZS ', 'empty_text' => 'Payments will appear after invoices are settled.']) ?>
    </div>
  </div>

  <div class="card chart-card span-4">
    <div class="card-header"><span class="card-title-sm"><i class="bi bi-wallet2 me-1"></i>Fee position</span></div>
    <div class="card-body">
      <?= svg_donut_chart([
          ['label' => 'Collected', 'value' => $finance['paid'], 'color' => '#169E6B'],
          ['label' => 'Outstanding', 'value' => $finance['outstanding'], 'color' => '#C98012'],
      ], ['title' => 'Fee collection', 'center_value' => num($feeRate) . '%', 'center_label' => 'collected']) ?>
      <div class="d-flex justify-content-between small text-muted mt-2"><span><?= number_format($finance['invoice_count']) ?> invoices</span><span><?= number_format($finance['paid_invoices']) ?> fully paid</span></div>
      <div class="progress-thin mt-2"><span style="width: <?= min(100, max(0, $feeRate)) ?>%"></span></div>
    </div>
  </div>

  <div class="card span-6">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-exclamation-triangle me-1"></i>Largest fee balances</span><a class="card-link" href="<?= SCHOOL_BASE_URL ?>finance/index.php">Open finance</a></div>
    <div class="table-responsive"><table class="compare-table"><thead><tr><th>Learner</th><th>Class</th><th class="text-end">Balance</th></tr></thead><tbody>
      <?php foreach ($finance['top_debtors'] as $debtor): ?><tr><td><a href="<?= SCHOOL_BASE_URL ?>students/record.php?id=<?= (int) $debtor['id'] ?>"><?= e($debtor['name']) ?></a><div class="small text-muted"><?= e($debtor['admission']) ?></div></td><td><?= e($debtor['class'] ?: '—') ?></td><td class="text-end"><?= e(money($debtor['balance'])) ?></td></tr><?php endforeach; ?>
      <?php if (!$finance['top_debtors']): ?><tr><td colspan="3" class="text-center text-muted py-4">No outstanding fee balances in scope.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>

  <div class="card span-3">
    <div class="card-header"><span class="card-title-sm"><i class="bi bi-credit-card me-1"></i>Payment methods</span></div>
    <div class="card-body"><?= svg_donut_chart($finance['methods'], ['title' => 'Payment methods', 'size' => 160, 'stroke' => 22, 'center_value' => number_format(array_sum(array_column($finance['methods'], 'value'))), 'center_label' => 'payments']) ?></div>
  </div>

  <div class="card span-3">
    <div class="card-header"><span class="card-title-sm"><i class="bi bi-receipt me-1"></i>Invoice status</span></div>
    <div class="card-body"><?= svg_donut_chart($finance['status'], ['title' => 'Invoice status', 'size' => 160, 'stroke' => 22, 'center_value' => number_format($finance['invoice_count']), 'center_label' => 'invoices']) ?></div>
  </div>
  <?php endif; ?>

  <div class="card chart-card span-8">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-bar-chart-line me-1"></i>Subject performance</span><span class="card-link">Average % · approved and published results</span></div>
    <div class="card-body"><?= svg_ranked_bars(array_map(static fn(array $row): array => ['label' => $row['label'], 'value' => $row['value'], 'color' => ((float) $row['value'] >= 50 ? '#169E6B' : '#C0392B')], $academic['subjects']), ['value_prefix' => '', 'empty_text' => 'Subject averages appear once results are approved.']) ?>
      <div class="small text-muted mt-2"><?= number_format($kpis['result_entries']) ?> result entries · <?= e(num($kpis['pass_rate'])) ?>% at or above the pass mark.</div>
    </div>
  </div>

  <div class="card chart-card span-4">
    <div class="card-header"><span class="card-title-sm"><i class="bi bi-award me-1"></i>Grade distribution</span></div>
    <div class="card-body"><?= svg_donut_chart($academic['grades'], ['title' => 'Grade distribution', 'center_value' => num($kpis['average_mark']) . '%', 'center_label' => 'average']) ?></div>
  </div>

  <div class="card chart-card span-12">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-graph-up me-1"></i>Performance by term</span><span class="card-link">Average % across approved results</span></div>
    <div class="card-body"><?= svg_line_chart([['label' => 'Average mark', 'color' => $palette['purple'], 'fill' => true, 'points' => $academic['exam_trend']]], ['title' => 'Performance by term', 'height' => 230, 'value_suffix' => '%', 'y_max' => 100, 'empty_text' => 'Term-by-term performance appears after results are approved.', 'area' => true]) ?></div>
  </div>

  <div class="card span-7">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-trophy me-1"></i>Top learners</span><span class="card-link">Average across subjects</span></div>
    <div class="table-responsive"><table class="compare-table"><thead><tr><th>#</th><th>Learner</th><th>Class</th><th class="text-end">Subjects</th><th class="text-end">Average</th></tr></thead><tbody>
      <?php foreach ($academic['top_students'] as $index => $student): ?><tr><td><?= $index + 1 ?></td><td><a href="<?= SCHOOL_BASE_URL ?>students/record.php?id=<?= (int) $student['id'] ?>"><?= e($student['full_name']) ?></a><div class="small text-muted"><?= e($student['admission_number']) ?></div></td><td><?= e($student['class_name'] ?: '—') ?></td><td class="text-end"><?= (int) $student['subjects'] ?></td><td class="text-end"><strong><?= e(num($student['average'])) ?>%</strong></td></tr><?php endforeach; ?>
      <?php if (!$academic['top_students']): ?><tr><td colspan="5" class="text-center text-muted py-4">Approved results will rank learners here.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>

  <div class="card span-5">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-shield-exclamation me-1"></i>Learners needing support</span><span class="card-link">Low attendance or fee balance</span></div>
    <div class="table-responsive"><table class="compare-table"><thead><tr><th>Learner</th><th class="text-end">Attendance</th><th class="text-end">Balance</th></tr></thead><tbody>
      <?php foreach ($data['at_risk'] as $risk): ?><tr><td><a href="<?= SCHOOL_BASE_URL ?>students/record.php?id=<?= (int) $risk['id'] ?>"><?= e($risk['full_name']) ?></a><div class="small text-muted"><?= e($risk['class_name'] ?: $risk['admission_number']) ?></div></td><td class="text-end <?= (float) $risk['attendance_rate'] < 75 ? 'delta-down' : '' ?>"><?= e(num($risk['attendance_rate'])) ?>%</td><td class="text-end"><?= e(money($risk['balance'])) ?></td></tr><?php endforeach; ?>
      <?php if (!$data['at_risk']): ?><tr><td colspan="3" class="text-center text-muted py-4">No learners currently cross the support threshold.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>

  <?php if ($data['activity']): ?>
  <div class="card span-12">
    <div class="card-header"><span class="card-title-sm"><i class="bi bi-clock-history me-1"></i>Recent system activity</span></div>
    <div class="table-responsive"><table class="compare-table"><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Subject</th></tr></thead><tbody>
      <?php foreach ($data['activity'] as $event): ?><tr><td><?= e(date('d/m/Y H:i', strtotime((string) $event['created_at']))) ?></td><td><?= e($event['actor'] ?? 'System') ?></td><td><?= e(str_replace(['.', '_'], [' · ', ' '], (string) $event['action'])) ?></td><td><?= e($event['subject_type'] . ($event['subject_id'] ? ' #' . $event['subject_id'] : '')) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
  <?php endif; ?>
</div>

<div class="card mt-3"><div class="card-body small text-muted"><i class="bi bi-info-circle me-1"></i>All figures are calculated live from school records. Charts respect your role scope; empty panels mean the underlying records have not been entered yet. Use <strong>Export CSV</strong> for the raw analytics tables, or <strong>Print report</strong> for a PDF-ready copy.</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
