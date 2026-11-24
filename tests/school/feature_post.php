<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2).'/app/autoload.php';
use School\Core\SchoolDatabase;

$case=(string)($argv[1]??'');$json=(string)($argv[2]??'{}');$payload=json_decode($json,true);
$cases=[
 'exam'=>'school/academics/index.php','assessment'=>'school/academics/index.php','transition'=>'school/academics/index.php','marks'=>'school/academics/index.php','fee'=>'school/finance/index.php','invoice'=>'school/finance/index.php','payment'=>'school/finance/index.php','staff'=>'school/staff/index.php','assignment'=>'school/staff/index.php','homework'=>'school/homework/index.php','guardian_request'=>'school/students/guardians.php','guardian_verify'=>'school/students/guardians.php','student_update'=>'school/students/record.php','enrollment'=>'school/students/record.php','school_profile'=>'school/settings/profile.php'
];
if(!isset($cases[$case])||!is_array($payload)){fwrite(STDERR,"Unknown test case or invalid JSON.\n");exit(2);}
$pdo=SchoolDatabase::connection();$username=(string)($payload['_username']??'dejene');$expectFailure=(bool)($payload['_expect_fail']??false);$recordId=(int)($payload['_record_id']??0);unset($payload['_username'],$payload['_expect_fail'],$payload['_record_id']);$q=$pdo->prepare("SELECT id FROM users WHERE username=:username AND status='active'");$q->execute(['username'=>$username]);$uid=(int)$q->fetchColumn();if(!$uid){fwrite(STDERR,"Test account not found.\n");exit(1);}
session_save_path(sys_get_temp_dir());session_name('SCHOOL_SESSION');session_id('school-post-'.bin2hex(random_bytes(8)));session_start();$_SESSION['school_user_id']=$uid;$_SESSION['school_login_at']=time();$_SESSION['school_csrf']=bin2hex(random_bytes(32));
$_SERVER['SCRIPT_NAME']='/school/'.$cases[$case];$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']='127.0.0.1';if(in_array($case,['student_update','enrollment'],true))$_GET['id']=$recordId;$_POST=$payload+['csrf_token'=>$_SESSION['school_csrf']];
ob_start();
register_shutdown_function(static function()use($case,$expectFailure):void{$html=ob_get_contents()?:'';$status=http_response_code();while(ob_get_level()>0)ob_end_clean();$rejected=$status>=400||str_contains($html,'alert-danger');if($expectFailure){if(!$rejected){fwrite(STDERR,"FAIL {$case}: invalid request was accepted.\n");exit(1);}echo "PASS REJECT {$case}\n";return;}if($rejected){preg_match('/<div class="alert alert-danger">(.*?)<\/div>/s',$html,$m);$message=trim(strip_tags($m[1]??('HTTP '.$status)));fwrite(STDERR,"FAIL {$case}: {$message}\n");exit(1);}echo "PASS POST {$case}\n";});
require dirname(__DIR__,2).'/'.$cases[$case];
echo "POST did not redirect; request rendered the form again.\n";
