<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/charts.php';

use School\Core\SchoolAuth;
use School\Core\SchoolDatabase;
use School\Repositories\SchoolDashboardRepository;

SchoolAuth::requireLogin();
$schoolUser = SchoolAuth::user();
if (!$schoolUser) { SchoolAuth::logout(); header('Location: ' . SCHOOL_BASE_URL . 'login.php'); exit; }
$schoolRole = school_current_role($schoolUser);
$data = (new SchoolDashboardRepository())->dataFor($schoolUser, $schoolRole);
$schoolName = (string) ($data['school']['name'] ?? 'School Management');
$pageTitle = 'Dashboard';
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$palette = chart_palette();
$admissionSeries = $data['admission_trend'];
$admissionCount = array_sum(array_column($admissionSeries, 'value'));
$attendanceSeries = $data['attendance_status'];
$attendanceRate = $data['today_attendance'];
$canViewFinance = in_array($schoolRole, ['school_owner','school_manager','accountant','parent_guardian'], true);
$moneySeries = $canViewFinance ? $data['finance_series'] : $data['academic_series'];
$thirdChartTitle = $canViewFinance ? 'Fees: collected vs outstanding' : 'Published results by subject';
$thirdChartSubtitle = $canViewFinance ? 'TZS school fee position' : 'Average marks from approved results';
$thirdChartTotal = $canViewFinance ? money($data['finance']['total_paid']) : (count($moneySeries) ? num(array_sum(array_column($moneySeries, 'value')) / count($moneySeries)) . ' avg' : '—');
$yearLabel = $data['year']['name'] ?? 'No active academic year';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div><h4><?= e($greeting) ?>, <?= e(explode(' ', (string) ($schoolUser['full_name'] ?? ''))[0]) ?></h4><p><?= e(date('d/m/Y')) ?> · <?= e($schoolName) ?> · <?= e($yearLabel) ?></p></div>
  <div class="page-actions"><?php if (SchoolAuth::hasPermission('students.manage')): ?><a class="btn btn-primary btn-sm" href="<?= SCHOOL_BASE_URL ?>students/add.php"><i class="bi bi-person-plus me-1"></i>Register student</a><?php endif; ?><?php if (SchoolAuth::hasPermission('attendance.manage')): ?><a class="btn btn-outline-primary btn-sm" href="<?= SCHOOL_BASE_URL ?>attendance/index.php"><i class="bi bi-calendar2-check me-1"></i>Take attendance</a><?php endif; ?></div>
</div>

<div class="chart-grid" id="school-insights">
  <div class="card chart-card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-bar-chart-line-fill me-1"></i>Enrollment trend</span><span class="card-link"><?= e($yearLabel) ?></span></div>
    <div class="card-body"><div class="chart-head"><div><div class="chart-total"><?= (int) $admissionCount ?> new enrollments</div><div class="chart-sub">Last 12 months · actual admissions</div></div><div class="text-end"><div class="chart-total"><?= number_format($data['students']) ?></div><div class="chart-sub">Active learners in your scope</div></div></div>
      <?= svg_column_chart($admissionSeries, ['title' => 'Monthly student admissions', 'empty_text' => 'Student admissions will appear here when records are added.', 'height' => 238, 'color' => $palette['blue'], 'gradient' => 'school-admissions', 'label_every' => 2]) ?>
    </div>
  </div>

  <div class="card chart-card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-pie-chart-fill me-1"></i>Attendance by status</span><span class="card-link">Last 14 days</span></div>
    <div class="card-body"><div class="chart-head"><div><div class="chart-total"><?= number_format($data['attendance_total']) ?> records</div><div class="chart-sub">Present, absent, late and excused</div></div><div class="text-end"><div class="chart-total"><?= e(num($attendanceRate)) ?>%</div><div class="chart-sub">Present today</div></div></div>
      <?= svg_donut_chart($attendanceSeries, ['title' => 'Attendance status distribution']) ?>
    </div>
  </div>

  <div class="card chart-card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-graph-up-arrow me-1"></i><?= e($thirdChartTitle) ?></span><span class="card-link"><?= e($thirdChartSubtitle) ?></span></div>
    <div class="card-body"><div class="chart-head"><div><div class="chart-total"><?= e($thirdChartTotal) ?></div><div class="chart-sub"><?= $canViewFinance ? 'Aggregated invoice allocations' : 'Published and approved results only' ?></div></div><div class="text-end"><div class="chart-total"><?= $canViewFinance ? e(money($data['finance']['total_outstanding'])) : count($moneySeries) ?></div><div class="chart-sub"><?= $canViewFinance ? 'Outstanding' : 'Subjects with results' ?></div></div></div>
      <?= svg_column_chart($moneySeries, ['title' => $thirdChartTitle, 'empty_text' => $canViewFinance ? 'Fee totals will appear after invoices and payments are recorded.' : 'Approved subject results will appear here when published.', 'height' => 238, 'show_values' => true, 'value_prefix' => $canViewFinance ? 'TZS ' : '', 'pad_left' => $canViewFinance ? 48 : 42]) ?>
    </div>
  </div>
</div>

<div class="chart-grid mt-3">
  <div class="card chart-card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-activity me-1"></i>Attendance trend</span><span class="card-link">Last 14 days · present rate</span></div>
    <div class="card-body"><div class="chart-head"><div><div class="chart-total"><?= e(num($data['today_attendance'])) ?>%</div><div class="chart-sub">Present today</div></div><div class="text-end"><div class="chart-total"><?= number_format($data['present_total']) ?></div><div class="chart-sub">Present marks · 14 days</div></div></div>
      <?= svg_line_chart([['label' => 'Present rate', 'color' => $palette['teal'], 'fill' => true, 'points' => $data['attendance_trend']]], ['title' => 'Daily attendance rate', 'height' => 220, 'y_min' => 0, 'y_max' => 100, 'value_suffix' => '%', 'area' => true, 'empty_text' => 'Attendance rates appear after sessions are marked.']) ?>
    </div>
  </div>

  <div class="card chart-card">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-people me-1"></i>Class size distribution</span><span class="card-link"><?= e($yearLabel) ?></span></div>
    <div class="card-body"><div class="chart-head"><div><div class="chart-total"><?= number_format($data['classes']) ?></div><div class="chart-sub">Classes represented</div></div><div class="text-end"><div class="chart-total"><?= number_format($data['students']) ?></div><div class="chart-sub">Active learners</div></div></div>
      <?= svg_ranked_bars($data['class_distribution'], ['color' => $palette['blue'], 'empty_text' => 'Class distribution appears after learners are enrolled.']) ?>
    </div>
  </div>
</div>

<div class="kpi-grid">
  <div class="kpi"><div class="k-icon"><i class="bi bi-people-fill"></i></div><div class="k-body"><div class="k-label">Active students</div><div class="k-value"><?= number_format($data['students']) ?></div><div class="k-hint">In your permitted records</div></div></div>
  <div class="kpi gold"><div class="k-icon"><i class="bi bi-door-open-fill"></i></div><div class="k-body"><div class="k-label">Classes</div><div class="k-value"><?= number_format($data['classes']) ?></div><div class="k-hint">Current active enrollments</div></div></div>
  <div class="kpi red"><div class="k-icon"><i class="bi bi-calendar2-check-fill"></i></div><div class="k-body"><div class="k-label">Attendance today</div><div class="k-value"><?= e(num($attendanceRate)) ?>%</div><div class="k-hint">Present among marked records</div></div></div>
  <div class="kpi ink"><div class="k-icon"><i class="bi <?= $canViewFinance ? 'bi-cash-stack' : 'bi-journal-check' ?>"></i></div><div class="k-body"><div class="k-label"><?= $canViewFinance ? 'Fees collected this month' : 'Results awaiting review' ?></div><div class="k-value"><?= $canViewFinance ? e(money($data['month_paid'])) : number_format((int) ($data['todo']['results_pending'] ?? 0)) ?></div><div class="k-hint"><?= $canViewFinance ? 'Successful payment allocations' : 'Draft or submitted records' ?></div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-7"><div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-clipboard-data me-1"></i>School summary</span><span class="card-link"><?= e($yearLabel) ?></span></div><div class="card-body">
    <div class="detail-list"><div><dt>Active learners</dt><dd><?= number_format($data['students']) ?></dd></div><div><dt>Classes represented</dt><dd><?= number_format($data['classes']) ?></dd></div><div><dt>Attendance records · 14 days</dt><dd><?= number_format($data['attendance_total']) ?></dd></div><div><dt>Present · 14 days</dt><dd><?= number_format($data['present_total']) ?></dd></div><?php if ($canViewFinance): ?><div><dt>Outstanding fees</dt><dd><?= e(money($data['finance']['total_outstanding'])) ?></dd></div><div><dt>Overdue fees</dt><dd><?= e(money($data['finance']['total_overdue'])) ?></dd></div><?php endif; ?></div>
    <?php if ($canViewFinance): ?><div class="d-flex justify-content-between align-items-end mt-3 mb-1"><span class="small text-muted">Fee collection rate</span><strong class="text-tabular"><?= e(percent($data['finance']['total_paid'], $data['finance']['total_charged'])) ?>%</strong></div><div class="progress-thin"><span style="width: <?= min(100, max(0, percent($data['finance']['total_paid'], $data['finance']['total_charged']))) ?>%"></span></div><?php endif; ?>
  </div></div></div>
  <div class="col-lg-5"><div class="card h-100"><div class="card-header"><span class="card-title-sm"><i class="bi bi-shield-check me-1"></i>Data access</span></div><div class="card-body"><div class="detail-list"><div><dt>Your role</dt><dd><?= e(school_role_label($schoolRole)) ?></dd></div><div><dt>Academic year</dt><dd><?= e($yearLabel) ?></dd></div><div><dt>Student scope</dt><dd><?= in_array($schoolRole,['parent_guardian','student','class_teacher','subject_teacher'],true) ? 'Linked or assigned records' : 'School-wide aggregates' ?></dd></div></div><div class="small text-muted mt-3">Charts use database records and respect your account scope. Empty charts indicate that the corresponding records have not been entered yet.</div></div></div></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
