<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

use School\Core\SchoolAuth;
use School\Core\SchoolDatabase;

SchoolAuth::requireLogin();
$user = SchoolAuth::user();
if (!$user) { header('Location: ' . SCHOOL_BASE_URL . 'login.php'); exit; }
$role = school_current_role($user);
if (!SchoolAuth::hasPermission('documents.view') && !SchoolAuth::hasPermission('reports.view') && !SchoolAuth::hasPermission('students.view') && !SchoolAuth::hasPermission('finance.view')) {
    http_response_code(403);
    exit('The document centre is not available for your account.');
}

$pdo = SchoolDatabase::connection();
$students = $pdo->query("SELECT s.id,s.admission_number,s.full_name FROM students s WHERE s.status='active' ORDER BY s.full_name")->fetchAll();
$classes = $pdo->query('SELECT c.id,c.name,c.level,y.name year_name FROM class_groups c JOIN academic_years y ON y.id=c.academic_year_id ORDER BY y.starts_on DESC,c.name')->fetchAll();
$exams = $pdo->query("SELECT e.id,e.name,e.workflow_status,t.name term_name,y.name year_name FROM examinations e JOIN terms t ON t.id=e.term_id JOIN academic_years y ON y.id=t.academic_year_id ORDER BY y.starts_on DESC,e.id DESC")->fetchAll();
$invoices = $pdo->query("SELECT i.id,i.invoice_number,i.status,s.full_name FROM invoices i JOIN students s ON s.id=i.student_id ORDER BY i.id DESC LIMIT 200")->fetchAll();
$payments = $pdo->query("SELECT p.id,p.amount,p.method,p.created_at,s.full_name FROM payments p LEFT JOIN payment_allocations pa ON pa.payment_id=p.id LEFT JOIN invoices i ON i.id=pa.invoice_id LEFT JOIN students s ON s.id=i.student_id WHERE p.status='succeeded' ORDER BY p.id DESC LIMIT 200")->fetchAll();
$years = $pdo->query('SELECT id,name FROM academic_years ORDER BY starts_on DESC')->fetchAll();
$terms = $pdo->query('SELECT t.id,t.name,y.name year_name FROM terms t JOIN academic_years y ON y.id=t.academic_year_id ORDER BY y.starts_on DESC,t.starts_on DESC')->fetchAll();
$renderer = new \School\Services\PdfRenderer();
$schoolName = (string) ($pdo->query('SELECT name FROM school_profile ORDER BY id LIMIT 1')->fetchColumn() ?: 'School Management');
$pageTitle = 'Reports & documents';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div><h4>Reports &amp; documents</h4><p>Generate print-ready PDFs from live school records. Documents use the school letterhead and respect your role scope.</p></div>
  <div class="page-actions">
    <span class="badge <?= $renderer->available() ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?> p-2">
      <i class="bi <?= $renderer->available() ? 'bi-check2-circle' : 'bi-printer' ?> me-1"></i>
      PDF engine: <?= e($renderer->engineName()) ?>
    </span>
  </div>
</div>

<?php if (!$renderer->available()): ?>
<div class="alert alert-warning d-flex gap-2"><i class="bi bi-info-circle-fill"></i><div>No server-side PDF engine was detected. Download buttons will open the document in a new tab and launch your browser's print dialog — choose <strong>Save as PDF</strong> there. Install Chrome/Chromium or WeasyPrint for direct PDF downloads.</div></div>
<?php endif; ?>

<div class="row g-3">
  <?php if (SchoolAuth::hasPermission('results.review') || SchoolAuth::hasPermission('results.publish') || SchoolAuth::hasPermission('school.manage') || SchoolAuth::hasPermission('results.import')): ?>
  <div class="col-xl-6">
    <div class="card h-100"><div class="card-header"><span class="card-title-sm"><i class="bi bi-journal-check me-1"></i>Report cards</span></div><div class="card-body">
      <form method="get" action="<?= SCHOOL_BASE_URL ?>reports/pdf.php" target="_blank" class="row g-2 mb-3">
        <input type="hidden" name="type" value="report_card">
        <div class="col-md-6"><label class="form-label">Assessment</label><select name="exam_id" class="form-select form-select-sm" required><option value="">Choose assessment</option><?php foreach ($exams as $exam): ?><option value="<?= (int) $exam['id'] ?>"><?= e($exam['year_name'] . ' · ' . $exam['term_name'] . ' · ' . $exam['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Learner</label><select name="student_id" class="form-select form-select-sm" required><option value="">Choose learner</option><?php foreach ($students as $student): ?><option value="<?= (int) $student['id'] ?>"><?= e($student['full_name'] . ' · ' . $student['admission_number']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>Download report card</button></div>
      </form>
      <hr>
      <form method="get" action="<?= SCHOOL_BASE_URL ?>reports/pdf.php" target="_blank" class="row g-2">
        <input type="hidden" name="type" value="class_reports">
        <div class="col-md-6"><label class="form-label">Assessment</label><select name="exam_id" class="form-select form-select-sm" required><option value="">Choose assessment</option><?php foreach ($exams as $exam): ?><option value="<?= (int) $exam['id'] ?>"><?= e($exam['year_name'] . ' · ' . $exam['term_name'] . ' · ' . $exam['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Class</label><select name="class_id" class="form-select form-select-sm" required><option value="">Choose class</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>"><?= e($class['year_name'] . ' · ' . $class['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-collection me-1"></i>Download class report cards</button><span class="small text-muted ms-2">One PDF, one report per learner.</span></div>
      </form>
    </div></div>
  </div>
  <?php endif; ?>

  <?php if (SchoolAuth::hasPermission('finance.view')): ?>
  <div class="col-xl-6">
    <div class="card h-100"><div class="card-header"><span class="card-title-sm"><i class="bi bi-receipt me-1"></i>Finance documents</span></div><div class="card-body">
      <form method="get" action="<?= SCHOOL_BASE_URL ?>reports/pdf.php" target="_blank" class="row g-2 mb-3">
        <input type="hidden" name="type" value="invoice">
        <div class="col-md-8"><label class="form-label">Invoice</label><select name="invoice_id" class="form-select form-select-sm" required><option value="">Choose invoice</option><?php foreach ($invoices as $invoice): ?><option value="<?= (int) $invoice['id'] ?>"><?= e($invoice['invoice_number'] . ' · ' . $invoice['full_name'] . ' · ' . $invoice['status']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-file-earmark-pdf me-1"></i>Invoice PDF</button></div>
      </form>
      <form method="get" action="<?= SCHOOL_BASE_URL ?>reports/pdf.php" target="_blank" class="row g-2 mb-3">
        <input type="hidden" name="type" value="receipt">
        <div class="col-md-8"><label class="form-label">Payment receipt</label><select name="payment_id" class="form-select form-select-sm" required><option value="">Choose payment</option><?php foreach ($payments as $payment): ?><option value="<?= (int) $payment['id'] ?>"><?= e('RCPT-' . $payment['id'] . ' · ' . money($payment['amount']) . ' · ' . ($payment['full_name'] ?? 'Unallocated')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4 d-flex align-items-end"><button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-file-earmark-pdf me-1"></i>Receipt PDF</button></div>
      </form>
      <form method="get" action="<?= SCHOOL_BASE_URL ?>reports/pdf.php" target="_blank" class="row g-2">
        <input type="hidden" name="type" value="fee_statement">
        <div class="col-md-5"><label class="form-label">Learner</label><select name="student_id" class="form-select form-select-sm" required><option value="">Choose learner</option><?php foreach ($students as $student): ?><option value="<?= (int) $student['id'] ?>"><?= e($student['full_name'] . ' · ' . $student['admission_number']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Academic year</label><select name="year_id" class="form-select form-select-sm"><option value="0">All years</option><?php foreach ($years as $year): ?><option value="<?= (int) $year['id'] ?>"><?= e($year['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 d-flex align-items-end"><button class="btn btn-outline-primary btn-sm w-100">Statement</button></div>
      </form>
    </div></div>
  </div>
  <?php endif; ?>

  <div class="col-xl-6">
    <div class="card h-100"><div class="card-header"><span class="card-title-sm"><i class="bi bi-people me-1"></i>Class registers &amp; attendance</span></div><div class="card-body">
      <form method="get" action="<?= SCHOOL_BASE_URL ?>reports/pdf.php" target="_blank" class="row g-2 mb-3">
        <input type="hidden" name="type" value="class_list">
        <div class="col-md-8"><label class="form-label">Class</label><select name="class_id" class="form-select form-select-sm" required><option value="">Choose class</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>"><?= e($class['year_name'] . ' · ' . $class['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-list-columns me-1"></i>Class register</button></div>
      </form>
      <form method="get" action="<?= SCHOOL_BASE_URL ?>reports/pdf.php" target="_blank" class="row g-2">
        <input type="hidden" name="type" value="attendance">
        <div class="col-md-4"><label class="form-label">Class</label><select name="class_id" class="form-select form-select-sm" required><option value="">Class</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>"><?= e($class['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e(date('Y-m-01')) ?>" required></div>
        <div class="col-md-3"><label class="form-label">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e(date('Y-m-d')) ?>" required></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-primary btn-sm w-100">PDF</button></div>
      </form>
    </div></div>
  </div>

  <div class="col-xl-6">
    <div class="card h-100"><div class="card-header"><span class="card-title-sm"><i class="bi bi-person-vcard me-1"></i>Learner documents</span></div><div class="card-body">
      <form method="get" action="<?= SCHOOL_BASE_URL ?>reports/pdf.php" target="_blank" class="row g-2">
        <div class="col-md-7"><label class="form-label">Learner</label><select name="student_id" class="form-select form-select-sm" id="learnerDocSelect" required><option value="">Choose learner</option><?php foreach ($students as $student): ?><option value="<?= (int) $student['id'] ?>"><?= e($student['full_name'] . ' · ' . $student['admission_number']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label class="form-label">Document</label><select name="type" class="form-select form-select-sm" required><option value="transcript">Academic transcript</option><option value="admission_letter">Admission letter</option><option value="id_cards">Learner ID card</option><option value="fee_statement">Fee statement</option></select></div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-download me-1"></i>Download document</button></div>
      </form>
      <hr>
      <form method="get" action="<?= SCHOOL_BASE_URL ?>reports/pdf.php" target="_blank" class="row g-2">
        <input type="hidden" name="type" value="id_cards">
        <div class="col-md-8"><label class="form-label">Class ID cards (batch)</label><select name="class_id" class="form-select form-select-sm" required><option value="">Choose class</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>"><?= e($class['year_name'] . ' · ' . $class['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4 d-flex align-items-end"><button class="btn btn-outline-primary btn-sm w-100">ID card sheet</button></div>
      </form>
    </div></div>
  </div>

  <?php if (SchoolAuth::hasPermission('reports.view') || SchoolAuth::hasPermission('analytics.school') || SchoolAuth::hasPermission('analytics.finance')): ?>
  <div class="col-12">
    <div class="card"><div class="card-header"><span class="card-title-sm"><i class="bi bi-clipboard-data me-1"></i>Management report</span></div><div class="card-body">
      <form method="get" action="<?= SCHOOL_BASE_URL ?>reports/pdf.php" target="_blank" class="analytics-filters">
        <input type="hidden" name="type" value="management_report">
        <div><label class="form-label">Academic year</label><select name="year_id" class="form-select form-select-sm"><option value="0">All years</option><?php foreach ($years as $year): ?><option value="<?= (int) $year['id'] ?>"><?= e($year['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Term</label><select name="term_id" class="form-select form-select-sm"><option value="0">All terms</option><?php foreach ($terms as $term): ?><option value="<?= (int) $term['id'] ?>"><?= e($term['year_name'] . ' · ' . $term['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Class</label><select name="class_id" class="form-select form-select-sm"><option value="0">All classes</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>"><?= e($class['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e(date('Y-m-01')) ?>"></div>
        <div><label class="form-label">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e(date('Y-m-d')) ?>"></div>
        <div><button class="btn btn-primary btn-sm w-100"><i class="bi bi-file-earmark-bar-graph me-1"></i>Generate report</button></div>
      </form>
    </div></div>
  </div>
  <?php endif; ?>
</div>

<div class="card mt-3"><div class="card-body small text-muted"><i class="bi bi-shield-lock me-1"></i>Every download is authorised against your role, assignment and guardian links. PDFs are generated on demand and are not stored on the web server.</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
