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
    exit('Result entry is not permitted for your account.');
}

$pdo = SchoolDatabase::connection();
$service = new ResultImportService();
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    try {
        if (!SchoolAuth::verifyCsrf()) throw new RuntimeException('Your session expired. Please try again.');
        $scope = $service->resolveScope(
            (int) ($_POST['examination_id'] ?? 0),
            (int) ($_POST['class_group_id'] ?? 0),
            (int) ($_POST['subject_id'] ?? 0),
            $user,
            $role,
            true,
            (float) ($_POST['max_mark'] ?? 100)
        );
        $payload = json_decode((string) ($_POST['rows_json'] ?? '[]'), true);
        if (!is_array($payload)) throw new RuntimeException('The entry sheet could not be read.');
        $parsed = [];
        $rowNumber = 1;
        foreach ($payload as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $admission = trim((string) ($row['admission'] ?? ''));
            $mark = trim((string) ($row['mark'] ?? ''));
            if ($name === '' && $admission === '' && $mark === '') continue;
            $parsed[] = ['row_number' => $rowNumber++, 'raw_name' => $name, 'raw_admission' => $admission, 'raw_mark' => $mark, 'raw_comment' => trim((string) ($row['comment'] ?? ''))];
        }
        if ($parsed === []) throw new RuntimeException('Enter at least one mark before submitting.');
        $batchId = $service->createBatch($scope, $parsed, 'full-screen-entry-' . date('Ymd-His') . '.csv', (int) $user['id']);
        $counts = $service->counts($batchId);
        if ($counts['review'] > 0 || $counts['error'] > 0) {
            school_flash('warning', 'Entry saved as import #' . $batchId . '. Resolve the flagged rows before applying.');
            header('Location: ' . SCHOOL_BASE_URL . 'academics/import-review.php?batch_id=' . $batchId);
            exit;
        }
        $result = $service->apply($batchId, (int) $user['id']);
        school_flash('success', $result['applied'] . ' result(s) saved from the full-screen entry sheet.');
        header('Location: ' . SCHOOL_BASE_URL . 'academics/entry.php?examination_id=' . (int) $scope['exam_id'] . '&class_group_id=' . (int) $scope['class_id'] . '&subject_id=' . (int) $scope['subject_id']);
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

// Options.
$assignments = [];
if (in_array($role, ['class_teacher', 'subject_teacher'], true)) {
    $query = $pdo->prepare("SELECT DISTINCT c.id class_id,c.name class_name,s.id subject_id,s.name subject_name,y.name year_name FROM teacher_assignments ta JOIN staff sf ON sf.id=ta.staff_id JOIN class_groups c ON c.id=ta.class_group_id JOIN academic_years y ON y.id=ta.academic_year_id JOIN subjects s ON s.id=ta.subject_id WHERE sf.user_id=:user AND sf.employment_status='active' AND ta.ended_at IS NULL ORDER BY y.starts_on DESC,c.name,s.name");
    $query->execute(['user' => (int) $user['id']]);
    $assignments = $query->fetchAll();
} else {
    $assignments = $pdo->query("SELECT c.id class_id,c.name class_name,s.id subject_id,s.name subject_name,y.name year_name FROM class_groups c JOIN academic_years y ON y.id=c.academic_year_id CROSS JOIN subjects s WHERE s.active=1 ORDER BY y.starts_on DESC,c.name,s.name")->fetchAll();
}
$classes = [];
$subjects = [];
foreach ($assignments as $assignment) {
    $classes[(int) $assignment['class_id']] = $assignment['class_name'] . ' · ' . $assignment['year_name'];
    $subjects[(int) $assignment['class_id']][(int) $assignment['subject_id']] = $assignment['subject_name'];
}
$exams = $pdo->query("SELECT e.id,e.name,e.workflow_status,t.name term_name,y.name year_name FROM examinations e JOIN terms t ON t.id=e.term_id JOIN academic_years y ON y.id=t.academic_year_id WHERE e.workflow_status IN ('draft','open') ORDER BY y.starts_on DESC,e.id DESC")->fetchAll();

$examId = (int) ($_GET['examination_id'] ?? 0);
$classId = (int) ($_GET['class_group_id'] ?? 0);
$subjectId = (int) ($_GET['subject_id'] ?? 0);
$scope = null;
if ($examId > 0 && $classId > 0 && $subjectId > 0) {
    try {
        $scope = $service->resolveScope($examId, $classId, $subjectId, $user, $role, true, (float) ($_GET['max_mark'] ?? 100));
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}
$schoolName = (string) ($pdo->query('SELECT name FROM school_profile ORDER BY id LIMIT 1')->fetchColumn() ?: 'School Management');
$flashes = school_take_flashes();
$rosterJson = json_encode($scope['roster'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Result entry · <?= e($schoolName) ?></title>
<meta name="csrf-token" content="<?= e(SchoolAuth::csrfToken()) ?>">
<link rel="stylesheet" href="<?= SCHOOL_ASSET_URL ?>vendor/bootstrap.min.css">
<link rel="stylesheet" href="<?= SCHOOL_ASSET_URL ?>vendor/bootstrap-icons.css">
<style>
:root{--g900:#063328;--g700:#075c47;--g100:#eef4f0;--gold:#c9a447;--ink:#1f2a27;--muted:#64716d;--line:#dce5e1}
*{box-sizing:border-box}
body{margin:0;background:#eef2ef;color:var(--ink);font:14px/1.4 "Segoe UI",Arial,sans-serif}
.entry-top{position:sticky;top:0;z-index:30;background:linear-gradient(100deg,var(--g900),var(--g700));color:#fff;padding:10px 18px;display:flex;align-items:center;gap:16px;box-shadow:0 4px 18px #06332833}
.entry-top .brand{font-weight:700;letter-spacing:.3px;display:flex;align-items:center;gap:8px}
.entry-top .meta{font-size:12px;opacity:.85;display:flex;flex-wrap:wrap;gap:4px 14px}
.entry-top .spacer{flex:1}
.entry-top a,.entry-top button{color:#fff;text-decoration:none;border:1px solid #ffffff55;background:#ffffff14;border-radius:7px;padding:6px 11px;font-size:12.5px}
.entry-top a:hover,.entry-top button:hover{background:#ffffff26}
.entry-wrap{max-width:1180px;margin:18px auto 60px;padding:0 16px}
.card{background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 2px 10px #0633280a}
.card-head{padding:11px 14px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.card-head strong{color:var(--g900);font-size:13px;letter-spacing:.2px}
.card-body{padding:14px}
.grid-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.grid-wrap{overflow:auto;max-height:calc(100vh - 260px);border:1px solid var(--line);border-radius:8px}
table.sheet{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}
table.sheet th{position:sticky;top:0;z-index:5;background:var(--g700);color:#fff;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;padding:8px 8px;text-align:left;white-space:nowrap}
table.sheet td{border-bottom:1px solid #eef3f0;padding:4px 6px;vertical-align:middle;background:#fff}
table.sheet tr:nth-child(even) td{background:#fbfdfc}
table.sheet td.idx{color:var(--muted);font-size:11px;text-align:right;width:38px;font-variant-numeric:tabular-nums}
table.sheet input{width:100%;border:1px solid transparent;background:transparent;padding:5px 6px;border-radius:5px;font:inherit;color:inherit}
table.sheet input:focus{outline:none;border-color:#7fb8a5;background:#fff;box-shadow:0 0 0 2px #169e6b22}
table.sheet input.mark{text-align:right;font-variant-numeric:tabular-nums;font-weight:650}
table.sheet input.mark.filled{background:#fff8e6;border-color:#f0d9a6}
table.sheet tr.row-ok input.mark{background:#eaf7f1;border-color:#bfe4d3}
table.sheet td.remove{width:34px;text-align:center}
table.sheet td.remove button{border:0;background:transparent;color:#b23b2e;font-size:15px;line-height:1;cursor:pointer;padding:2px 5px;border-radius:4px}
table.sheet td.remove button:hover{background:#fbe9e7}
.ac-wrap{position:relative}
.ac-menu{position:absolute;z-index:50;top:100%;left:0;right:0;background:#fff;border:1px solid var(--line);border-radius:7px;box-shadow:0 8px 24px #06332822;max-height:220px;overflow:auto;display:none;min-width:280px}
.ac-menu.show{display:block}
.ac-item{padding:7px 9px;cursor:pointer;font-size:12.5px;display:flex;justify-content:space-between;gap:10px}
.ac-item:hover,.ac-item.active{background:var(--g100)}
.ac-item small{color:var(--muted)}
.progress{height:7px;background:#e6eeea;border-radius:999px;overflow:hidden}
.progress span{display:block;height:100%;background:linear-gradient(90deg,#169e6b,#4f81bd);width:0;transition:width .2s}
.counter{font-size:12px;color:var(--muted);font-variant-numeric:tabular-nums}
.badge-soft{background:var(--g100);color:var(--g700);border-radius:999px;padding:3px 9px;font-size:11px;font-weight:650}
.paste-panel{display:none;margin-top:10px}
.paste-panel.show{display:block}
.paste-panel textarea{width:100%;min-height:110px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;border:1px solid var(--line);border-radius:7px;padding:8px}
.setup{max-width:760px;margin:40px auto}
.setup .card-body{padding:22px}
.alert{border-radius:8px}
@media (max-width:767.98px){.entry-top{flex-wrap:wrap}.entry-top .meta{display:none}.grid-wrap{max-height:none}table.sheet input{min-width:110px}}
</style>
</head><body>
<div class="entry-top">
  <div class="brand"><i class="bi bi-mortarboard-fill"></i><?= e($schoolName) ?></div>
  <?php if ($scope): ?><div class="meta"><span><i class="bi bi-journal-check me-1"></i><?= e($scope['exam_name']) ?></span><span><i class="bi bi-door-open me-1"></i><?= e($scope['class_name']) ?></span><span><i class="bi bi-book me-1"></i><?= e($scope['subject_name']) ?></span><span><i class="bi bi-123 me-1"></i>Max <?= e(num($scope['max_mark'])) ?></span></div><?php endif; ?>
  <div class="spacer"></div>
  <a href="<?= SCHOOL_BASE_URL ?>academics/import.php"><i class="bi bi-arrow-left me-1"></i>Imports</a>
  <a href="<?= SCHOOL_BASE_URL ?>academics/index.php"><i class="bi bi-journal-check me-1"></i>Academics</a>
  <span style="font-size:12px;opacity:.8"><?= e($user['full_name']) ?></span>
</div>
<div class="entry-wrap">
<?php foreach ($flashes as $flash): ?><div class="alert alert-<?= e($flash['type']) ?> mt-3"><?= e($flash['message']) ?></div><?php endforeach; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger mt-3"><i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?></div><?php endif; ?>
<?php if (!$exams): ?><div class="alert alert-info mt-3"><i class="bi bi-info-circle me-1"></i>No assessment period is currently open for result entry. Ask the academic office to create a draft assessment, or open an existing one.</div><?php endif; ?>

<?php if (!$scope): ?>
  <div class="setup"><div class="card"><div class="card-body">
    <h4 class="mb-1" style="color:var(--g900)">Full-screen result entry</h4>
    <p class="text-muted">Choose the assessment, class and subject. The sheet pre-fills the class register; type marks and submit. Names use roster autocomplete, so exact matches are applied immediately.</p>
    <form method="get" class="row g-2">
      <div class="col-md-12"><label class="form-label">Assessment period</label><select name="examination_id" class="form-select" required><option value="">Choose assessment</option><?php foreach ($exams as $exam): ?><option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>><?= e($exam['year_name'] . ' · ' . $exam['term_name'] . ' · ' . $exam['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-5"><label class="form-label">Class</label><select name="class_group_id" class="form-select" id="classSelect" required><option value="">Choose class</option><?php foreach ($classes as $id => $label): ?><option value="<?= (int) $id ?>" <?= $classId === (int) $id ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-5"><label class="form-label">Subject</label><select name="subject_id" class="form-select" id="subjectSelect" required><option value="">Choose class first</option></select></div>
      <div class="col-md-2"><label class="form-label">Max mark</label><input type="number" name="max_mark" class="form-control" value="100" min="1" step="0.01"></div>
      <div class="col-12 mt-3"><button class="btn btn-primary"><i class="bi bi-grid-3x3-gap me-1"></i>Open entry sheet</button></div>
    </form>
  </div></div></div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <div><strong><i class="bi bi-grid-3x3-gap me-1"></i>Spreadsheet entry</strong><div class="counter mt-1"><span id="enteredCount">0</span> of <span id="rowCount"><?= count($scope['roster']) ?></span> marks entered</div></div>
      <div class="grid-tools">
        <span class="badge-soft"><i class="bi bi-people me-1"></i><?= count($scope['roster']) ?> learners</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="addRow"><i class="bi bi-plus-lg me-1"></i>Add row</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="pasteToggle"><i class="bi bi-clipboard me-1"></i>Paste marks</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="clearMarks"><i class="bi bi-eraser me-1"></i>Clear</button>
      </div>
    </div>
    <div class="card-body">
      <div class="progress mb-3"><span id="entryProgress"></span></div>
      <div class="paste-panel" id="pastePanel">
        <div class="small text-muted mb-1">Paste one learner per line: <code>Name &lt;tab&gt; Mark</code>, <code>Admission &lt;tab&gt; Mark</code>, or just marks in roster order.</div>
        <textarea id="pasteBox" placeholder="Jane Doe&#9;78&#10;ADM-001&#9;65&#10;82"></textarea>
        <div class="d-flex gap-2 mt-2"><button type="button" class="btn btn-sm btn-primary" id="applyPaste">Apply paste</button><button type="button" class="btn btn-sm btn-outline-secondary" id="cancelPaste">Cancel</button></div>
      </div>
      <div class="grid-wrap">
        <table class="sheet" id="sheet">
          <thead><tr><th style="width:38px">#</th><th style="min-width:230px">Student name</th><th style="width:150px">Admission</th><th style="width:100px">Mark / <?= e(num($scope['max_mark'])) ?></th><th style="min-width:180px">Comment</th><th></th></tr></thead>
          <tbody id="sheetBody"></tbody>
        </table>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div class="small text-muted"><i class="bi bi-info-circle me-1"></i>Exact names or admission numbers apply immediately. Near matches are sent to review.</div>
        <button type="button" class="btn btn-success" id="submitSheet"><i class="bi bi-check2-circle me-1"></i>Submit results</button>
      </div>
    </div>
  </div>
  <form method="post" id="submitForm" class="d-none">
    <?= SchoolAuth::csrfField() ?>
    <input type="hidden" name="action" value="submit">
    <input type="hidden" name="examination_id" value="<?= (int) $scope['exam_id'] ?>">
    <input type="hidden" name="class_group_id" value="<?= (int) $scope['class_id'] ?>">
    <input type="hidden" name="subject_id" value="<?= (int) $scope['subject_id'] ?>">
    <input type="hidden" name="max_mark" value="<?= e($scope['max_mark']) ?>">
    <input type="hidden" name="rows_json" id="rowsJson">
  </form>
<?php endif; ?>
</div>

<script>
(function () {
  const roster = <?= $rosterJson ?>;
  const maxMark = <?= json_encode((float) ($scope['max_mark'] ?? 100)) ?>;
  const subjects = <?= json_encode($subjects, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
  const classSelect = document.getElementById('classSelect');
  const subjectSelect = document.getElementById('subjectSelect');
  if (classSelect && subjectSelect) {
    function refreshSubjects() {
      const list = subjects[classSelect.value] || {};
      subjectSelect.innerHTML = '<option value="">Choose subject</option>';
      Object.keys(list).sort((a, b) => list[a].localeCompare(list[b])).forEach(function (id) {
        const option = document.createElement('option'); option.value = id; option.textContent = list[id]; subjectSelect.appendChild(option);
      });
    }
    classSelect.addEventListener('change', refreshSubjects);
    if (classSelect.value) refreshSubjects();
  }

  const body = document.getElementById('sheetBody');
  if (!body) return;
  const form = document.getElementById('submitForm');
  const rowsJson = document.getElementById('rowsJson');
  const enteredCount = document.getElementById('enteredCount');
  const rowCount = document.getElementById('rowCount');
  const progress = document.getElementById('entryProgress');

  function normalize(value) {
    return (value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function makeRow(data) {
    const tr = document.createElement('tr');
    const idx = document.createElement('td'); idx.className = 'idx';
    const nameCell = document.createElement('td');
    const wrap = document.createElement('div'); wrap.className = 'ac-wrap';
    const nameInput = document.createElement('input'); nameInput.className = 'name'; nameInput.placeholder = 'Start typing a learner name'; nameInput.autocomplete = 'off'; nameInput.value = data.name || '';
    const menu = document.createElement('div'); menu.className = 'ac-menu';
    wrap.appendChild(nameInput); wrap.appendChild(menu); nameCell.appendChild(wrap);
    const admissionCell = document.createElement('td'); const admissionInput = document.createElement('input'); admissionInput.className = 'admission'; admissionInput.placeholder = 'Admission'; admissionInput.value = data.admission || ''; admissionCell.appendChild(admissionInput);
    const markCell = document.createElement('td'); const markInput = document.createElement('input'); markInput.className = 'mark'; markInput.type = 'number'; markInput.min = '0'; markInput.max = String(maxMark); markInput.step = '0.01'; markInput.placeholder = '—'; markInput.value = data.mark || ''; markCell.appendChild(markInput);
    const commentCell = document.createElement('td'); const commentInput = document.createElement('input'); commentInput.className = 'comment'; commentInput.placeholder = 'Optional'; commentInput.value = data.comment || ''; commentCell.appendChild(commentInput);
    const removeCell = document.createElement('td'); removeCell.className = 'remove'; const remove = document.createElement('button'); remove.type = 'button'; remove.innerHTML = '&times;'; remove.title = 'Remove row'; removeCell.appendChild(remove);
    tr.append(idx, nameCell, admissionCell, markCell, commentCell, removeCell);
    body.appendChild(tr);

    function findMatches(term) {
      const key = normalize(term);
      if (!key) return [];
      return roster.filter(function (student) {
        return normalize(student.full_name).includes(key) || String(student.admission_number).toLowerCase().includes(key);
      }).slice(0, 8);
    }

    function showMenu(matches) {
      menu.innerHTML = '';
      if (!matches.length) { menu.classList.remove('show'); return; }
      matches.forEach(function (student, index) {
        const item = document.createElement('div'); item.className = 'ac-item' + (index === 0 ? ' active' : '');
        item.innerHTML = '<span>' + student.full_name.replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }) + '</span><small>' + student.admission_number + '</small>';
        item.addEventListener('mousedown', function (event) { event.preventDefault(); pick(student); });
        menu.appendChild(item);
      });
      menu.classList.add('show');
    }

    function pick(student) {
      nameInput.value = student.full_name;
      admissionInput.value = student.admission_number;
      menu.classList.remove('show');
      markInput.focus();
      refresh();
    }

    nameInput.addEventListener('input', function () { showMenu(findMatches(nameInput.value)); });
    nameInput.addEventListener('focus', function () { if (nameInput.value) showMenu(findMatches(nameInput.value)); });
    nameInput.addEventListener('blur', function () { setTimeout(function () { menu.classList.remove('show'); }, 120); });
    nameInput.addEventListener('keydown', function (event) {
      const items = Array.from(menu.querySelectorAll('.ac-item'));
      const activeIndex = items.findIndex(function (item) { return item.classList.contains('active'); });
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (!items.length) return;
        const next = event.key === 'ArrowDown' ? Math.min(items.length - 1, activeIndex + 1) : Math.max(0, activeIndex - 1);
        items.forEach(function (item, index) { item.classList.toggle('active', index === next); });
      } else if (event.key === 'Enter') {
        event.preventDefault();
        if (items.length) items[Math.max(0, activeIndex)].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
      } else if (event.key === 'Escape') {
        menu.classList.remove('show');
      }
    });
    admissionInput.addEventListener('change', function () {
      const key = normalize(admissionInput.value);
      if (!key) return;
      const student = roster.find(function (item) { return normalize(item.admission_number) === key; });
      if (student && !nameInput.value) { nameInput.value = student.full_name; refresh(); }
    });
    markInput.addEventListener('input', refresh);
    remove.addEventListener('click', function () { tr.remove(); renumber(); refresh(); });
    tr._markInput = markInput;
    tr._nameInput = nameInput;
    tr._admissionInput = admissionInput;
    tr._commentInput = commentInput;
    return tr;
  }

  function renumber() {
    Array.from(body.querySelectorAll('tr')).forEach(function (tr, index) { tr.querySelector('.idx').textContent = index + 1; });
  }

  function refresh() {
    let filled = 0;
    Array.from(body.querySelectorAll('tr')).forEach(function (tr) {
      const mark = tr._markInput.value.trim();
      tr._markInput.classList.toggle('filled', mark !== '');
      tr.classList.toggle('row-ok', mark !== '' && mark !== '' && !isNaN(Number(mark)) && Number(mark) >= 0 && Number(mark) <= maxMark);
      if (mark !== '') filled++;
    });
    if (enteredCount) enteredCount.textContent = filled;
    if (rowCount) rowCount.textContent = body.querySelectorAll('tr').length;
    if (progress) progress.style.width = Math.min(100, (filled / Math.max(1, body.querySelectorAll('tr').length)) * 100) + '%';
  }

  roster.forEach(function (student) { makeRow({ name: student.full_name, admission: student.admission_number }); });
  renumber(); refresh();

  document.getElementById('addRow').addEventListener('click', function () {
    const tr = makeRow({}); body.appendChild(tr); renumber(); tr._nameInput.focus();
  });
  document.getElementById('clearMarks').addEventListener('click', function () {
    if (!confirm('Clear every mark in this sheet?')) return;
    body.querySelectorAll('input.mark').forEach(function (input) { input.value = ''; });
    refresh();
  });
  document.getElementById('pasteToggle').addEventListener('click', function () { document.getElementById('pastePanel').classList.toggle('show'); });
  document.getElementById('cancelPaste').addEventListener('click', function () { document.getElementById('pastePanel').classList.remove('show'); });

  function rowsArray() {
    return Array.from(body.querySelectorAll('tr')).map(function (tr) {
      return { name: tr._nameInput.value, admission: tr._admissionInput.value, mark: tr._markInput.value, comment: tr._commentInput.value };
    }).filter(function (row) { return row.mark !== ''; });
  }

  function applyPasteLine(columns, fallbackIndex) {
    const clean = columns.map(function (value) { return value.trim(); }).filter(function (value) { return value !== ''; });
    if (!clean.length) return;
    let target = null;
    let mark = null;
    if (clean.length === 1) {
      mark = clean[0];
      target = body.querySelectorAll('tr')[fallbackIndex] || null;
    } else {
      const first = clean[0];
      const second = clean[1];
      const firstKey = normalize(first);
      const match = roster.find(function (student) { return normalize(student.admission_number) === firstKey || normalize(student.full_name) === firstKey; });
      if (match) {
        target = Array.from(body.querySelectorAll('tr')).find(function (tr) { return normalize(tr._admissionInput.value) === normalize(match.admission_number); });
        if (!target) { target = makeRow({ name: match.full_name, admission: match.admission_number }); body.appendChild(target); }
        mark = second;
      } else {
        const markCandidate = second;
        if (/^-?\d+(\.\d+)?$/.test(markCandidate)) {
          target = Array.from(body.querySelectorAll('tr')).find(function (tr) { return normalize(tr._nameInput.value) === firstKey; });
          if (!target) { target = makeRow({ name: first }); body.appendChild(target); }
          mark = markCandidate;
        }
      }
    }
    if (target && mark !== null && /^-?\d+(\.\d+)?$/.test(mark)) target._markInput.value = mark;
  }

  document.getElementById('applyPaste').addEventListener('click', function () {
    const text = document.getElementById('pasteBox').value || '';
    const lines = text.split(/\r?\n/).filter(function (line) { return line.trim() !== ''; });
    lines.forEach(function (line, index) { applyPasteLine(line.split(/\t|,|;|\s{2,}/), index); });
    renumber(); refresh();
    document.getElementById('pastePanel').classList.remove('show');
  });

  document.getElementById('submitSheet').addEventListener('click', function () {
    const rows = rowsArray();
    if (!rows.length) { alert('Enter at least one mark.'); return; }
    const invalid = rows.find(function (row) { return row.mark !== '' && (isNaN(Number(row.mark)) || Number(row.mark) < 0 || Number(row.mark) > maxMark); });
    if (invalid) { alert('Every mark must be between 0 and ' + maxMark + '.'); return; }
    const missingName = rows.find(function (row) { return row.mark !== '' && !row.name && !row.admission; });
    if (missingName) { alert('Choose a learner for every entered mark.'); return; }
    if (!confirm('Submit ' + rows.filter(function (row) { return row.mark !== ''; }).length + ' mark(s)? Exact matches are applied immediately; near matches go to review.')) return;
    rowsJson.value = JSON.stringify(rows);
    form.submit();
  });
})();
</script>
</body></html>
