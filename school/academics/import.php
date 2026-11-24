<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

use School\Core\SchoolAuth;
use School\Core\SchoolDatabase;
use School\Services\ResultImportService;

SchoolAuth::requireLogin();
$user = SchoolAuth::user();
if (!$user) { header('Location: ' . SCHOOL_BASE_URL . 'login.php'); exit; }
$role = school_current_role($user);
if (!SchoolAuth::hasPermission('results.import')) {
    http_response_code(403);
    exit('Result import is not permitted for your account.');
}

$pdo = SchoolDatabase::connection();
$service = new ResultImportService();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!SchoolAuth::verifyCsrf()) throw new RuntimeException('Your session expired. Please try again.');
        $examId = (int) ($_POST['examination_id'] ?? 0);
        $classId = (int) ($_POST['class_group_id'] ?? 0);
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        $maxMark = (float) ($_POST['max_mark'] ?? 100);
        if (!isset($_FILES['workbook']) || ($_FILES['workbook']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Choose the completed Excel or CSV file to upload.');
        }
        $file = $_FILES['workbook'];
        if ((int) $file['size'] <= 0 || (int) $file['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('The upload must be between 1 byte and 10 MB.');
        }
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new RuntimeException('Upload an .xlsx, .xls or .csv workbook.');
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            throw new RuntimeException('The upload could not be verified.');
        }
        $scope = $service->resolveScope($examId, $classId, $subjectId, $user, $role, true, $maxMark);
        $rows = $service->readSpreadsheet((string) $file['tmp_name']);
        $batchId = $service->createBatch($scope, $rows, (string) $file['name'], (int) $user['id']);
        school_flash('success', 'Workbook parsed. Review the matched and near-matched learners before applying.');
        header('Location: ' . SCHOOL_BASE_URL . 'academics/import-review.php?batch_id=' . $batchId);
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

// Scope options: teachers only see their own class/subject assignments.
$assignments = [];
if (in_array($role, ['class_teacher', 'subject_teacher'], true)) {
    $query = $pdo->prepare("SELECT DISTINCT c.id class_id,c.name class_name,c.level,s.id subject_id,s.code subject_code,s.name subject_name,y.id year_id,y.name year_name FROM teacher_assignments ta JOIN staff sf ON sf.id=ta.staff_id JOIN class_groups c ON c.id=ta.class_group_id JOIN academic_years y ON y.id=ta.academic_year_id JOIN subjects s ON s.id=ta.subject_id WHERE sf.user_id=:user AND sf.employment_status='active' AND ta.ended_at IS NULL ORDER BY y.starts_on DESC,c.name,s.name");
    $query->execute(['user' => (int) $user['id']]);
    $assignments = $query->fetchAll();
} else {
    $query = $pdo->query("SELECT c.id class_id,c.name class_name,c.level,s.id subject_id,s.code subject_code,s.name subject_name,y.id year_id,y.name year_name FROM class_groups c JOIN academic_years y ON y.id=c.academic_year_id CROSS JOIN subjects s WHERE s.active=1 ORDER BY y.starts_on DESC,c.name,s.name");
    $assignments = $query->fetchAll();
}
$classes = [];
$subjects = [];
foreach ($assignments as $assignment) {
    $classes[(int) $assignment['class_id']] = $assignment['class_name'] . ' · ' . $assignment['year_name'];
    $subjects[(int) $assignment['class_id']][(int) $assignment['subject_id']] = $assignment['subject_name'];
}
$exams = $pdo->query("SELECT e.id,e.name,e.workflow_status,t.name term_name,y.name year_name FROM examinations e JOIN terms t ON t.id=e.term_id JOIN academic_years y ON y.id=t.academic_year_id WHERE e.workflow_status IN ('draft','open') ORDER BY y.starts_on DESC,e.id DESC")->fetchAll();
$batches = $service->recentBatches($user, $role);
$schoolName = (string) ($pdo->query('SELECT name FROM school_profile ORDER BY id LIMIT 1')->fetchColumn() ?: 'School Management');
$pageTitle = 'Import results';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div><h4>Import results</h4><p>Download a pre-filled class roster, enter marks in Excel, then upload it for matched review and application.</p></div>
  <div class="page-actions"><a class="btn btn-outline-primary btn-sm" href="<?= SCHOOL_BASE_URL ?>academics/entry.php"><i class="bi bi-grid-3x3-gap me-1"></i>Open full-screen entry sheet</a></div>
</div>

<?php foreach (school_take_flashes() as $flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show"><?= e($flash['message']) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endforeach; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?></div><?php endif; ?>
<?php if (!$exams): ?><div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>No assessment period is currently open for result entry. Ask the academic office to create a draft assessment, or open an existing one, before importing marks.</div><?php endif; ?>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="card h-100">
      <div class="card-header"><span class="card-title-sm"><i class="bi bi-file-earmark-arrow-up me-1"></i>1. Choose the class and assessment</span></div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-2" id="importForm">
          <?= SchoolAuth::csrfField() ?>
          <div class="col-md-6"><label class="form-label">Assessment period</label><select name="examination_id" class="form-select form-select-sm" required><option value="">Choose assessment</option><?php foreach ($exams as $exam): ?><option value="<?= (int) $exam['id'] ?>"><?= e($exam['year_name'] . ' · ' . $exam['term_name'] . ' · ' . $exam['name'] . ' (' . $exam['workflow_status'] . ')') ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label">Class</label><select name="class_group_id" class="form-select form-select-sm" id="classSelect" required><option value="">Choose class</option><?php foreach ($classes as $id => $label): ?><option value="<?= (int) $id ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label">Subject</label><select name="subject_id" class="form-select form-select-sm" id="subjectSelect" required><option value="">Choose class first</option></select></div>
          <div class="col-md-3"><label class="form-label">Maximum mark</label><input type="number" name="max_mark" class="form-control form-control-sm" min="1" step="0.01" value="100" title="Used only when the subject assessment does not exist yet"></div>
          <div class="col-md-3 d-flex align-items-end"><button type="button" class="btn btn-outline-primary btn-sm w-100" id="templateButton"><i class="bi bi-file-earmark-excel me-1"></i>Template</button></div>
          <div class="col-12"><hr class="my-1"></div>
          <div class="col-12"><label class="form-label">2. Upload the completed workbook</label><input type="file" name="workbook" class="form-control form-control-sm" accept=".xlsx,.xls,.csv" required></div>
          <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Parse and review</button><span class="small text-muted ms-2">Nothing is written to results until you approve the review.</span></div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card h-100">
      <div class="card-header"><span class="card-title-sm"><i class="bi bi-info-circle me-1"></i>How matching works</span></div>
      <div class="card-body">
        <div class="detail-list">
          <div><dt>Exact admission number</dt><dd><span class="badge bg-success-subtle text-success">Auto</span></dd></div>
          <div><dt>Exact unique name</dt><dd><span class="badge bg-success-subtle text-success">Auto</span></dd></div>
          <div><dt>Near / fuzzy name</dt><dd><span class="badge bg-warning-subtle text-warning">Review</span></dd></div>
          <div><dt>No close match</dt><dd><span class="badge bg-danger-subtle text-danger">Error</span></dd></div>
        </div>
        <div class="small text-muted mt-3">Fuzzy rows are never applied silently. You get up to three ranked suggestions with confidence scores and can approve, reject or manually assign each one.</div>
        <div class="small text-muted mt-2">Only active learners in the selected class register are considered.</div>
      </div>
    </div>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-clock-history me-1"></i>Recent import batches</span><a class="card-link" href="<?= SCHOOL_BASE_URL ?>academics/index.php">Back to academics</a></div>
  <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Batch</th><th>Assessment</th><th>Class · subject</th><th class="text-center">Rows</th><th class="text-center">Review</th><th class="text-center">Errors</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($batches as $batch): ?>
    <tr><td><strong>#<?= (int) $batch['id'] ?></strong><div class="small text-muted"><?= e($batch['original_filename']) ?></div><div class="small text-muted"><?= e(date('d/m/Y H:i', strtotime((string) $batch['created_at']))) ?></div></td><td><?= e($batch['exam_name']) ?></td><td><?= e($batch['class_name'] . ' · ' . $batch['subject_name']) ?></td><td class="text-center"><?= (int) $batch['total_rows'] ?></td><td class="text-center"><?= (int) $batch['review_rows'] ?></td><td class="text-center"><?= (int) $batch['error_rows'] ?></td><td><span class="badge bg-<?= $batch['status'] === 'applied' ? 'success' : ($batch['status'] === 'ready' ? 'primary' : ($batch['status'] === 'cancelled' ? 'secondary' : 'warning')) ?>-subtle text-<?= $batch['status'] === 'applied' ? 'success' : ($batch['status'] === 'ready' ? 'primary' : ($batch['status'] === 'cancelled' ? 'secondary' : 'warning')) ?>"><?= e(ucfirst((string) $batch['status'])) ?></span></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= SCHOOL_BASE_URL ?>academics/import-review.php?batch_id=<?= (int) $batch['id'] ?>">Open</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$batches): ?><tr><td colspan="8" class="text-center text-muted py-4">No result imports yet. Download a template to begin.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<script>
(function () {
  const subjects = <?= json_encode($subjects, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
  const classSelect = document.getElementById('classSelect');
  const subjectSelect = document.getElementById('subjectSelect');
  const templateButton = document.getElementById('templateButton');
  const form = document.getElementById('importForm');

  function refreshSubjects() {
    const list = subjects[classSelect.value] || {};
    subjectSelect.innerHTML = '<option value="">Choose subject</option>';
    Object.keys(list).sort((a, b) => list[a].localeCompare(list[b])).forEach(function (id) {
      const option = document.createElement('option');
      option.value = id;
      option.textContent = list[id];
      subjectSelect.appendChild(option);
    });
  }
  classSelect.addEventListener('change', refreshSubjects);
  if (classSelect.value) refreshSubjects();

  templateButton.addEventListener('click', function () {
    const exam = form.querySelector('[name="examination_id"]').value;
    const cls = classSelect.value;
    const subject = subjectSelect.value;
    const max = form.querySelector('[name="max_mark"]').value || '100';
    if (!exam || !cls || !subject) { alert('Choose the assessment, class and subject first.'); return; }
    window.location.href = '<?= SCHOOL_BASE_URL ?>academics/import-template.php?examination_id=' + encodeURIComponent(exam) + '&class_group_id=' + encodeURIComponent(cls) + '&subject_id=' + encodeURIComponent(subject) + '&max_mark=' + encodeURIComponent(max);
  });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
