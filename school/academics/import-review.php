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
if (!SchoolAuth::hasPermission('results.import') && !SchoolAuth::hasPermission('results.review')) {
    http_response_code(403);
    exit('Result import review is not permitted for your account.');
}

$service = new ResultImportService();
$batchId = (int) ($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0);
$batch = $batchId > 0 ? $service->batch($batchId) : null;
if (!$batch) { http_response_code(404); exit('Import batch not found.'); }
if (in_array($role, ['class_teacher', 'subject_teacher'], true) && (int) $batch['uploaded_by'] !== (int) $user['id']) {
    http_response_code(403);
    exit('You can only review batches you uploaded.');
}

$pdo = SchoolDatabase::connection();
$yearQuery = $pdo->prepare('SELECT t.academic_year_id FROM examinations e JOIN terms t ON t.id=e.term_id WHERE e.id=:id');
$yearQuery->execute(['id' => (int) $batch['examination_id']]);
$yearId = (int) $yearQuery->fetchColumn();
$roster = $service->roster((int) $batch['class_group_id'], $yearId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!SchoolAuth::verifyCsrf()) throw new RuntimeException('Your session expired. Please try again.');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'approve') {
            $studentChoice = (int) ($_POST['student_id'] ?? 0);
            if ($studentChoice <= 0) $studentChoice = (int) ($_POST['manual_student_id'] ?? 0);
            $service->approveRow((int) ($_POST['row_id'] ?? 0), $studentChoice, (int) $user['id']);
            school_flash('success', 'Row approved. It will be written when the batch is applied.');
        } elseif ($action === 'reject') {
            $service->rejectRow((int) ($_POST['row_id'] ?? 0), (int) $user['id']);
            school_flash('success', 'Row rejected and excluded from the batch.');
        } elseif ($action === 'reopen') {
            $service->reopenRow((int) ($_POST['row_id'] ?? 0), (int) $user['id']);
            school_flash('success', 'Row returned to review.');
        } elseif ($action === 'apply') {
            $result = $service->apply($batchId, (int) $user['id']);
            $message = $result['applied'] . ' result(s) written to the register.';
            if ($result['skipped'] > 0) $message .= ' ' . $result['skipped'] . ' row(s) were skipped.';
            school_flash($result['errors'] ? 'warning' : 'success', $message . ($result['errors'] ? ' ' . implode(' ', array_slice($result['errors'], 0, 4)) : ''));
            header('Location: ' . SCHOOL_BASE_URL . 'academics/import.php');
            exit;
        } elseif ($action === 'cancel') {
            $service->cancel($batchId, (int) $user['id']);
            school_flash('success', 'Import batch cancelled.');
            header('Location: ' . SCHOOL_BASE_URL . 'academics/import.php');
            exit;
        }
    } catch (Throwable $exception) {
        school_flash('danger', $exception->getMessage());
    }
    header('Location: ' . SCHOOL_BASE_URL . 'academics/import-review.php?batch_id=' . $batchId);
    exit;
}

$rows = $service->rows($batchId);
$counts = $service->counts($batchId);
$reviewRows = array_values(array_filter($rows, static fn(array $row): bool => $row['match_status'] === 'review'));
$errorRows = array_values(array_filter($rows, static fn(array $row): bool => $row['match_status'] === 'error'));
$otherRows = array_values(array_filter($rows, static fn(array $row): bool => !in_array($row['match_status'], ['review', 'error'], true)));
$ready = $counts['review'] === 0 && $counts['error'] === 0 && !in_array($batch['status'], ['applied', 'cancelled'], true);
$schoolName = (string) ($pdo->query('SELECT name FROM school_profile ORDER BY id LIMIT 1')->fetchColumn() ?: 'School Management');
$pageTitle = 'Review import #' . $batchId;
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div><h4>Review import #<?= (int) $batchId ?></h4><p><?= e($batch['exam_name'] . ' · ' . $batch['class_name'] . ' · ' . $batch['subject_name']) ?> · <?= e($batch['original_filename']) ?></p></div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary btn-sm" href="<?= SCHOOL_BASE_URL ?>academics/import.php"><i class="bi bi-arrow-left me-1"></i>Back to imports</a>
    <?php if ($ready): ?><form method="post" onsubmit="return confirm('Apply every matched and approved row to the results register?');"><?= SchoolAuth::csrfField() ?><input type="hidden" name="batch_id" value="<?= (int) $batchId ?>"><input type="hidden" name="action" value="apply"><button class="btn btn-success btn-sm"><i class="bi bi-check2-circle me-1"></i>Apply <?= (int) ($counts['matched'] + $counts['approved']) ?> results</button></form><?php endif; ?>
  </div>
</div>

<?php foreach (school_take_flashes() as $flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show"><?= e($flash['message']) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endforeach; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <div class="card h-100"><div class="card-body">
      <div class="row g-3">
        <div class="col-6 col-md-3"><div class="kpi"><div class="k-body"><div class="k-label">Rows</div><div class="k-value"><?= (int) $batch['total_rows'] ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="kpi gold"><div class="k-body"><div class="k-label">Auto-matched</div><div class="k-value"><?= (int) $counts['matched'] ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="kpi red"><div class="k-body"><div class="k-label">Needs review</div><div class="k-value"><?= (int) $counts['review'] ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="kpi ink"><div class="k-body"><div class="k-label">Errors</div><div class="k-value"><?= (int) $counts['error'] ?></div></div></div></div>
      </div>
      <div class="progress-thin mt-3"><span style="width: <?= min(100, max(0, percent((int) $batch['total_rows'] - (int) $counts['review'] - (int) $counts['error'], (int) $batch['total_rows']))) ?>%"></span></div>
      <div class="small text-muted mt-1"><?= e(ucfirst((string) $batch['status'])) ?> · uploaded by <?= e($batch['uploader']) ?> on <?= e(date('d/m/Y H:i', strtotime((string) $batch['created_at']))) ?></div>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100"><div class="card-body">
      <div class="detail-list">
        <div><dt>Assessment</dt><dd><?= e($batch['exam_name']) ?></dd></div>
        <div><dt>Class</dt><dd><?= e($batch['class_name']) ?></dd></div>
        <div><dt>Subject</dt><dd><?= e($batch['subject_name']) ?></dd></div>
        <div><dt>Approved</dt><dd><?= (int) $counts['approved'] ?></dd></div>
        <div><dt>Rejected</dt><dd><?= (int) $counts['rejected'] ?></dd></div>
      </div>
      <?php if (in_array($batch['status'], ['review', 'ready'], true)): ?><form method="post" class="mt-3" onsubmit="return confirm('Cancel this import batch? No results will be written.');"><?= SchoolAuth::csrfField() ?><input type="hidden" name="batch_id" value="<?= (int) $batchId ?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-x-circle me-1"></i>Cancel batch</button></form><?php endif; ?>
    </div></div>
  </div>
</div>

<?php if ($batch['status'] === 'applied'): ?><div class="alert alert-success"><i class="bi bi-check2-circle me-1"></i>This batch was applied on <?= e(date('d/m/Y H:i', strtotime((string) $batch['applied_at']))) ?>. <?= (int) $batch['applied_rows'] ?> result(s) written. The rows below are read-only.</div><?php endif; ?>
<?php if ($counts['review'] > 0): ?><div class="alert alert-warning"><i class="bi bi-search me-1"></i><?= (int) $counts['review'] ?> near match(es) need a decision. Approve the right learner or reject the row. The batch cannot be applied until every review row is resolved.</div><?php endif; ?>
<?php if ($counts['error'] > 0): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= (int) $counts['error'] ?> row(s) have errors. Reject them or correct the workbook and upload a new batch.</div><?php endif; ?>

<?php if ($reviewRows): ?>
<div class="card mb-3">
  <div class="card-header"><span class="card-title-sm"><i class="bi bi-search me-1"></i>Rows needing review</span></div>
  <div class="card-body p-0">
    <?php foreach ($reviewRows as $row): ?>
    <div class="border-bottom p-3">
      <div class="row g-3 align-items-start">
        <div class="col-lg-3">
          <div class="small text-muted">Spreadsheet row <?= (int) $row['row_number'] ?></div>
          <div class="fw-bold"><?= e($row['raw_name'] ?: '—') ?></div>
          <div class="small text-muted">Admission: <?= e($row['raw_admission'] ?: '—') ?></div>
          <div class="small text-muted">Mark: <strong><?= e($row['raw_mark'] ?: '—') ?></strong></div>
          <?php if ($row['confidence'] !== null): ?><span class="badge bg-warning-subtle text-warning mt-1">Best <?= e(num($row['confidence'], true)) ?>%</span><?php endif; ?>
        </div>
        <div class="col-lg-6">
          <form method="post" class="d-flex flex-column gap-2">
            <?= SchoolAuth::csrfField() ?>
            <input type="hidden" name="batch_id" value="<?= (int) $batchId ?>">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="row_id" value="<?= (int) $row['id'] ?>">
            <?php foreach (($row['candidates'] ?? []) as $index => $candidate): ?>
            <label class="d-flex align-items-start gap-2 border rounded p-2 mb-0" style="cursor:pointer">
              <input class="form-check-input mt-1" type="radio" name="student_id" value="<?= (int) $candidate['student_id'] ?>" <?= $index === 0 ? 'checked' : '' ?> required>
              <span><strong><?= e($candidate['full_name']) ?></strong> <span class="small text-muted">· <?= e($candidate['admission_number']) ?></span><br><span class="small text-muted"><?= e($candidate['reason']) ?> · <?= e(num($candidate['confidence'], true)) ?>% confidence</span></span>
            </label>
            <?php endforeach; ?>
            <div class="input-group input-group-sm">
              <span class="input-group-text">Or choose</span>
              <select name="manual_student_id" class="form-select" onchange="if(this.value){this.closest('form').querySelectorAll('input[name=student_id]').forEach(function(input){input.checked=false; input.required=false;});}">
                <option value="">Full class roster…</option>
                <?php foreach ($roster as $learner): ?><option value="<?= (int) $learner['id'] ?>"><?= e($learner['full_name'] . ' · ' . $learner['admission_number']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="d-flex gap-2"><button class="btn btn-success btn-sm"><i class="bi bi-check2 me-1"></i>Approve learner</button><button class="btn btn-outline-danger btn-sm" type="submit" name="action" value="reject" formnovalidate><i class="bi bi-x me-1"></i>Reject row</button></div>
          </form>
        </div>
        <div class="col-lg-3">
          <div class="small text-muted">Why this needs review</div>
          <div class="small"><?= e($row['error_message'] ?: 'Near name match.') ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($errorRows): ?>
<div class="card mb-3">
  <div class="card-header"><span class="card-title-sm"><i class="bi bi-exclamation-triangle me-1"></i>Rows with errors</span></div>
  <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Row</th><th>Name</th><th>Admission</th><th>Mark</th><th>Problem</th><th></th></tr></thead><tbody>
    <?php foreach ($errorRows as $row): ?><tr><td><?= (int) $row['row_number'] ?></td><td><?= e($row['raw_name'] ?: '—') ?></td><td><?= e($row['raw_admission'] ?: '—') ?></td><td><?= e($row['raw_mark'] ?: '—') ?></td><td class="text-danger"><?= e($row['error_message'] ?: 'Unmatched row') ?></td><td class="text-end"><form method="post"><?= SchoolAuth::csrfField() ?><input type="hidden" name="batch_id" value="<?= (int) $batchId ?>"><input type="hidden" name="row_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="action" value="reject"><button class="btn btn-sm btn-outline-danger">Reject</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><span class="card-title-sm"><i class="bi bi-table me-1"></i>Resolved and auto-matched rows</span></div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Row</th><th>Spreadsheet name</th><th>Mark</th><th>Matched learner</th><th>Method</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($otherRows as $row): ?>
    <tr><td><?= (int) $row['row_number'] ?></td><td><?= e($row['raw_name']) ?><div class="small text-muted"><?= e($row['raw_admission'] ?: '') ?></div></td><td><?= e($row['mark'] !== null ? num($row['mark']) : '—') ?></td><td><?= e($row['matched_name'] ?: '—') ?><div class="small text-muted"><?= e($row['matched_admission'] ?: '') ?></div></td><td><?= e(ucwords(str_replace('_', ' ', (string) $row['match_method']))) ?></td><td><span class="badge bg-<?= $row['match_status'] === 'applied' ? 'success' : ($row['match_status'] === 'rejected' ? 'secondary' : 'primary') ?>-subtle text-<?= $row['match_status'] === 'applied' ? 'success' : ($row['match_status'] === 'rejected' ? 'secondary' : 'primary') ?>"><?= e(ucfirst((string) $row['match_status'])) ?></span></td><td class="text-end"><?php if ($row['match_status'] === 'rejected' && $batch['status'] !== 'applied'): ?><form method="post"><?= SchoolAuth::csrfField() ?><input type="hidden" name="batch_id" value="<?= (int) $batchId ?>"><input type="hidden" name="row_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="action" value="reopen"><button class="btn btn-sm btn-outline-secondary">Reopen</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$otherRows): ?><tr><td colspan="7" class="text-center text-muted py-4">No resolved rows yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<?php if ($ready): ?><div class="card mt-3"><div class="card-body d-flex justify-content-between align-items-center"><div><strong>Ready to apply.</strong><div class="small text-muted"><?= (int) ($counts['matched'] + $counts['approved']) ?> result(s) will be written as submitted marks. Existing unpublished marks are versioned; published marks are never overwritten.</div></div><form method="post" onsubmit="return confirm('Apply these results to the register now?');"><?= SchoolAuth::csrfField() ?><input type="hidden" name="batch_id" value="<?= (int) $batchId ?>"><input type="hidden" name="action" value="apply"><button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Apply results</button></form></div></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
