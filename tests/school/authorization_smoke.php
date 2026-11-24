<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/app/autoload.php';
use School\Core\SchoolDatabase;
use School\Policies\AuthorizationPolicy;
use School\Repositories\UserRepository;
$pdo=SchoolDatabase::connection();$repo=new UserRepository();$policy=new AuthorizationPolicy();$errors=[];
$userIds=[];$q=$pdo->query("SELECT id,username FROM users WHERE username IN ('doreen','mmari','tutu')");foreach($q->fetchAll() as $row)$userIds[$row['username']]=(int)$row['id'];
foreach(['doreen','mmari','tutu'] as $username)if(empty($userIds[$username]))$errors[]="missing account {$username}";
if(!$errors){$parent=$repo->findWithRoles($userIds['doreen']);$teacher=$repo->findWithRoles($userIds['mmari']);$accountant=$repo->findWithRoles($userIds['tutu']);
 $studentId=(int)$pdo->query("SELECT id FROM students WHERE admission_number='DEMO-26001'")->fetchColumn();$unlinkedId=(int)$pdo->query("SELECT id FROM students WHERE admission_number='UNLINKED-TEST'")->fetchColumn();if(!$unlinkedId){$pdo->exec("INSERT INTO students (admission_number,full_name,gender,admission_date,status) VALUES ('UNLINKED-TEST','Unlinked Test Learner','undisclosed','2026-11-19','active')");$unlinkedId=(int)$pdo->lastInsertId();}
 $yearId=(int)$pdo->query("SELECT id FROM academic_years WHERE name='2026'")->fetchColumn();$assignedClass=(int)$pdo->query("SELECT class_group_id FROM teacher_assignments ta JOIN staff st ON st.id=ta.staff_id WHERE st.user_id=".$userIds['mmari'].' LIMIT 1')->fetchColumn();$class=(int)$pdo->query("SELECT id FROM class_groups WHERE academic_year_id={$yearId} AND id<>".$assignedClass.' LIMIT 1')->fetchColumn();if(!$class){$pdo->exec("INSERT INTO class_groups (academic_year_id,level,name,capacity) VALUES ({$yearId},'primary','Unassigned Test Class',20)");$class=(int)$pdo->lastInsertId();}
 if(!$policy->allows($parent,'portal.child',['student_id'=>$studentId]))$errors[]='parent cannot access verified linked child';
 if($policy->allows($parent,'portal.child',['student_id'=>$unlinkedId]))$errors[]='parent can access an unlinked student';
 if(!$policy->allows($teacher,'attendance.manage',['class_group_id'=>$assignedClass,'academic_year_id'=>$yearId]))$errors[]='assigned teacher cannot manage assigned class attendance';
 if($policy->allows($teacher,'attendance.manage',['class_group_id'=>$class,'academic_year_id'=>$yearId]))$errors[]='teacher can manage unassigned class attendance';
 if(!$policy->hasPermission($accountant,'finance.manage'))$errors[]='accountant lacks finance permission';
 if($policy->hasPermission($accountant,'results.publish'))$errors[]='accountant can publish results';
}
if($errors){fwrite(STDERR,"FAIL authorization: ".implode('; ',$errors)."\n");exit(1);}echo "PASS guardian and teacher record scopes; accountant role restrictions\n";
