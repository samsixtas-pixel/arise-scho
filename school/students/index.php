<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
use School\Core\SchoolAuth;
use School\Core\SchoolDatabase;

SchoolAuth::requireLogin(); $user=SchoolAuth::user(); $role=school_current_role($user);
if(!SchoolAuth::hasPermission('students.view')){http_response_code(403);exit('Student access is not permitted.');}
$pdo=SchoolDatabase::connection(); $where=" WHERE s.status <> 'archived'"; $params=[];
if($role==='parent_guardian'){$where.=" AND EXISTS(SELECT 1 FROM student_guardians sg JOIN guardians g ON g.id=sg.guardian_id WHERE sg.student_id=s.id AND g.user_id=:user AND sg.verification_status='verified' AND sg.ended_at IS NULL)";$params['user']=(int)$user['id'];}
elseif($role==='student'){$where.=' AND s.user_id=:user';$params['user']=(int)$user['id'];}
elseif(in_array($role,['class_teacher','subject_teacher'],true)){$where.=" AND EXISTS(SELECT 1 FROM enrollments ex JOIN staff sf ON sf.user_id=:user AND sf.id IN (SELECT staff_id FROM teacher_assignments WHERE ended_at IS NULL) JOIN teacher_assignments ta ON ta.staff_id=sf.id AND ta.class_group_id=ex.class_group_id AND ta.academic_year_id=ex.academic_year_id AND ta.ended_at IS NULL WHERE ex.student_id=s.id AND ex.status='active' AND sf.employment_status='active')";$params['user']=(int)$user['id'];}
$query=trim((string)($_GET['q']??'')); if($query!==''){$where.=' AND (s.full_name LIKE :query_name OR s.admission_number LIKE :query_admission)';$params['query_name']='%'.$query.'%';$params['query_admission']='%'.$query.'%';}
$sql="SELECT s.id,s.admission_number,s.full_name,s.date_of_birth,s.gender,s.status,e.status enrollment_status,c.name class_name,st.name stream_name FROM students s LEFT JOIN enrollments e ON e.student_id=s.id AND e.status='active' LEFT JOIN class_groups c ON c.id=e.class_group_id LEFT JOIN streams st ON st.id=e.stream_id {$where} ORDER BY s.full_name LIMIT 100";
$stmt=$pdo->prepare($sql);$stmt->execute($params);$students=$stmt->fetchAll();
$schoolName=(string)($pdo->query('SELECT name FROM school_profile ORDER BY id LIMIT 1')->fetchColumn()?:'School Management');$schoolRole=$role;$pageTitle='Students';$canManage=SchoolAuth::hasPermission('students.manage');
require __DIR__.'/../includes/header.php';
?>
<div class="page-head"><div><h4>Students</h4><p>Student records visible to your account.</p></div><?php if($canManage): ?><div class="page-actions"><a class="btn btn-primary btn-sm" href="<?= SCHOOL_BASE_URL ?>students/add.php"><i class="bi bi-person-plus me-1"></i>Register student</a></div><?php endif; ?></div>
<div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span class="card-title-sm"><i class="bi bi-people-fill me-1"></i>Student register</span><form class="d-flex gap-2" method="get"><input class="form-control form-control-sm" name="q" value="<?= e($query) ?>" placeholder="Name or admission number"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button></form></div>
<div class="table-wrap"><table class="table table-hover align-middle mb-0"><thead><tr><th>Admission no.</th><th>Student</th><th>Class</th><th>Gender</th><th>Status</th></tr></thead><tbody><?php if(!$students): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-people"></i><strong>No student records found</strong><p>Records appear here once students are registered and linked within your access scope.</p><?php if($canManage): ?><a class="btn btn-primary btn-sm" href="<?= SCHOOL_BASE_URL ?>students/add.php">Register first student</a><?php endif; ?></div></td></tr><?php endif; foreach($students as $student): ?><tr><td><span class="ref"><?= e($student['admission_number']) ?></span></td><td><a class="name text-decoration-none" href="<?= SCHOOL_BASE_URL ?>students/record.php?id=<?= (int)$student['id'] ?>"><?= e($student['full_name']) ?></a><span class="sub"><?= e($student['date_of_birth']?formatDate($student['date_of_birth']):'Date of birth not provided') ?></span></td><td><?= e(trim(($student['class_name']??'').($student['stream_name']?' · '.$student['stream_name']:''))?:'Not enrolled') ?></td><td><?= e(ucfirst($student['gender'])) ?></td><td><span class="badge bg-<?= $student['status']==='active'?'success':'secondary' ?>"><?= e(ucfirst($student['status'])) ?></span></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php require __DIR__.'/../includes/footer.php'; ?>
