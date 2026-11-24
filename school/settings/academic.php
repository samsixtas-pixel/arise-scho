<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
use School\Core\SchoolAuth;
use School\Core\SchoolDatabase;
use School\Services\AuditService;

SchoolAuth::requireLogin();
$user = SchoolAuth::user();
if (!SchoolAuth::hasPermission('school.manage')) { http_response_code(403); exit('School structure access is not permitted.'); }
$pdo = SchoolDatabase::connection();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SchoolAuth::verifyCsrf()) $error = 'Your session expired. Please try again.';
    else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            School\Core\SchoolDatabase::transaction(function (PDO $pdo) use ($action, $user): void {
                if ($action === 'year') {
                    $name = trim((string) ($_POST['name'] ?? ''));
                    $start = (string) ($_POST['starts_on'] ?? ''); $end = (string) ($_POST['ends_on'] ?? ''); $status=(string)($_POST['status']??'planned');
                    if ($name === '' || $start === '' || $end < $start || !in_array($status,['planned','active'],true)) throw new RuntimeException('Enter a valid academic year and date range.');
                    if($status==='active')$pdo->exec("UPDATE academic_years SET status='closed' WHERE status='active'");
                    $q = $pdo->prepare("INSERT INTO academic_years (name,starts_on,ends_on,status) VALUES (:name,:start,:end,:status)");
                    $q->execute(['name'=>$name,'start'=>$start,'end'=>$end,'status'=>$status]);
                    (new AuditService())->record((int)$user['id'],'academic_year.create','academic_years',(int)$pdo->lastInsertId());
                } elseif ($action === 'term') {
                    $year=(int)($_POST['academic_year_id']??0); $name=trim((string)($_POST['name']??'')); $start=(string)($_POST['starts_on']??''); $end=(string)($_POST['ends_on']??''); $status=(string)($_POST['status']??'planned');
                    $q=$pdo->prepare('SELECT starts_on,ends_on FROM academic_years WHERE id=:id'); $q->execute(['id'=>$year]); $period=$q->fetch();
                    if (!$period || $name==='' || $start<$period['starts_on'] || $end>$period['ends_on'] || $end<$start || !in_array($status,['planned','active'],true)) throw new RuntimeException('Choose a valid academic year and term dates within that year.');
                    if($status==='active'){$q=$pdo->prepare("UPDATE terms SET status='closed' WHERE academic_year_id=:year AND status='active'");$q->execute(['year'=>$year]);}
                    $q=$pdo->prepare("INSERT INTO terms (academic_year_id,name,starts_on,ends_on,status) VALUES (:year,:name,:start,:end,:status)"); $q->execute(['year'=>$year,'name'=>$name,'start'=>$start,'end'=>$end,'status'=>$status]);
                    (new AuditService())->record((int)$user['id'],'term.create','terms',(int)$pdo->lastInsertId());
                } elseif ($action === 'class') {
                    $year=(int)($_POST['academic_year_id']??0); $level=(string)($_POST['level']??'primary'); $name=trim((string)($_POST['name']??'')); $capacity=(int)($_POST['capacity']??0);
                    if (!$year || !in_array($level,['primary','secondary'],true) || $name==='' || $capacity<0) throw new RuntimeException('Enter a valid class name, level, and capacity.');
                    $q=$pdo->prepare('INSERT INTO class_groups (academic_year_id,level,name,capacity) VALUES (:year,:level,:name,:capacity)'); $q->execute(['year'=>$year,'level'=>$level,'name'=>$name,'capacity'=>$capacity?:null]); $classId=(int)$pdo->lastInsertId();
                    $stream=trim((string)($_POST['stream']??'')); if($stream!==''){$q=$pdo->prepare('INSERT INTO streams (class_group_id,name) VALUES (:class,:name)');$q->execute(['class'=>$classId,'name'=>$stream]);}
                    (new AuditService())->record((int)$user['id'],'class.create','class_groups',$classId);
                } else throw new RuntimeException('Choose a valid setup action.');
            });
            school_flash('success','School structure saved.'); header('Location: '.SCHOOL_BASE_URL.'settings/academic.php'); exit;
        } catch (Throwable $exception) { $error=$exception instanceof PDOException ? 'That name or record already exists. Check the current school setup.' : $exception->getMessage(); }
    }
}
$years=$pdo->query('SELECT * FROM academic_years ORDER BY starts_on DESC')->fetchAll();
$terms=$pdo->query('SELECT t.*,y.name year_name FROM terms t JOIN academic_years y ON y.id=t.academic_year_id ORDER BY t.starts_on DESC')->fetchAll();
$classes=$pdo->query('SELECT c.*,y.name year_name,(SELECT COUNT(*) FROM streams s WHERE s.class_group_id=c.id) stream_count FROM class_groups c JOIN academic_years y ON y.id=c.academic_year_id ORDER BY y.starts_on DESC,c.name')->fetchAll();
$schoolName=(string)($pdo->query('SELECT name FROM school_profile ORDER BY id LIMIT 1')->fetchColumn()?:'School Management'); $schoolRole=school_current_role($user); $pageTitle='Classes & terms';
require __DIR__.'/../includes/header.php';
?>
<div class="page-head"><div><h4>Academic structure</h4><p>Configure years, terms, classes, and streams for this school.</p></div></div>
<?php if($error!==''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="row g-3 mb-3">
  <div class="col-lg-4"><div class="card h-100"><div class="card-header"><span class="card-title-sm"><i class="bi bi-calendar-plus me-1"></i>Academic year</span></div><div class="card-body"><form method="post"><?= SchoolAuth::csrfField() ?><input type="hidden" name="action" value="year"><div class="mb-3"><label class="form-label">Year name</label><input class="form-control" name="name" placeholder="2027" required></div><div class="mb-3"><label class="form-label">Starts</label><input class="form-control" name="starts_on" type="date" required></div><div class="mb-3"><label class="form-label">Ends</label><input class="form-control" name="ends_on" type="date" required></div><div class="mb-3"><label class="form-label">Status</label><select class="form-select" name="status"><option value="planned">Planned</option><option value="active">Make active</option></select></div><button class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1"></i>Save academic year</button></form></div></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header"><span class="card-title-sm"><i class="bi bi-calendar2-week me-1"></i>Term</span></div><div class="card-body"><form method="post"><?= SchoolAuth::csrfField() ?><input type="hidden" name="action" value="term"><div class="mb-3"><label class="form-label">Academic year</label><select class="form-select" name="academic_year_id" required><option value="">Choose year</option><?php foreach($years as $year): ?><option value="<?= (int)$year['id'] ?>"><?= e($year['name']) ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">Term name</label><input class="form-control" name="name" placeholder="Term 1" required></div><div class="row"><div class="col"><label class="form-label">Starts</label><input class="form-control" name="starts_on" type="date" required></div><div class="col"><label class="form-label">Ends</label><input class="form-control" name="ends_on" type="date" required></div></div><div class="mt-3 mb-2"><label class="form-label">Status</label><select class="form-select" name="status"><option value="planned">Planned</option><option value="active">Make active</option></select></div><button class="btn btn-primary btn-sm mt-1"><i class="bi bi-check2 me-1"></i>Save term</button></form></div></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header"><span class="card-title-sm"><i class="bi bi-door-open-fill me-1"></i>Class & stream</span></div><div class="card-body"><form method="post"><?= SchoolAuth::csrfField() ?><input type="hidden" name="action" value="class"><div class="mb-3"><label class="form-label">Academic year</label><select class="form-select" name="academic_year_id" required><option value="">Choose year</option><?php foreach($years as $year): ?><option value="<?= (int)$year['id'] ?>"><?= e($year['name']) ?></option><?php endforeach; ?></select></div><div class="row mb-3"><div class="col"><label class="form-label">Level</label><select class="form-select" name="level"><option value="primary">Primary</option><option value="secondary">Secondary</option></select></div><div class="col"><label class="form-label">Capacity</label><input class="form-control" name="capacity" type="number" min="0" value="40"></div></div><div class="mb-3"><label class="form-label">Class name</label><input class="form-control" name="name" placeholder="Standard 6" required></div><div class="mb-3"><label class="form-label">First stream (optional)</label><input class="form-control" name="stream" placeholder="A"></div><button class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1"></i>Save class</button></form></div></div></div>
</div>
<div class="card mb-3"><div class="card-header"><span class="card-title-sm">Academic years and terms</span></div><div class="table-wrap"><table class="table table-hover align-middle mb-0"><thead><tr><th>Academic year</th><th>Dates</th><th>Status</th><th>Terms</th></tr></thead><tbody><?php if(!$years): ?><tr><td colspan="4" class="text-center text-muted py-4">No academic years have been configured.</td></tr><?php endif; foreach($years as $year): ?><tr><td class="fw-semibold"><?= e($year['name']) ?></td><td><?= e(formatDate($year['starts_on'])) ?> – <?= e(formatDate($year['ends_on'])) ?></td><td><?= e(ucfirst($year['status'])) ?></td><td><?= e(implode(', ',array_column(array_filter($terms,fn($term)=>(int)$term['academic_year_id']===(int)$year['id']),'name'))?:'—') ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card"><div class="card-header"><span class="card-title-sm">Classes and streams</span></div><div class="table-wrap"><table class="table table-hover align-middle mb-0"><thead><tr><th>Class</th><th>Level</th><th>Academic year</th><th>Capacity</th><th>Streams</th></tr></thead><tbody><?php if(!$classes): ?><tr><td colspan="5" class="text-center text-muted py-4">No classes configured yet.</td></tr><?php endif; foreach($classes as $class): ?><tr><td class="fw-semibold"><?= e($class['name']) ?></td><td><?= e(ucfirst($class['level'])) ?></td><td><?= e($class['year_name']) ?></td><td><?= e($class['capacity']??'—') ?></td><td><?= (int)$class['stream_count'] ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php require __DIR__.'/../includes/footer.php'; ?>
