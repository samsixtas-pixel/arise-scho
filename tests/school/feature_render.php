<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/app/autoload.php';
use School\Core\SchoolDatabase;

$route = (string)($argv[1] ?? '');
$username = (string)($argv[2] ?? '');
$expectDenied = in_array('deny', $argv, true);
$routes = [
    'dashboard' => ['file'=>'school/dashboard.php','expect'=>'Active students'],
    'students' => ['file'=>'school/students/index.php','expect'=>'Student register'],
    'attendance' => ['file'=>'school/attendance/index.php','expect'=>'Attendance'],
    'academic_setup' => ['file'=>'school/settings/academic.php','expect'=>'Academic structure'],
    'school_profile' => ['file'=>'school/settings/profile.php','expect'=>'School details'],
    'academics' => ['file'=>'school/academics/index.php','expect'=>'Assessment register'],
    'report' => ['file'=>'school/academics/report.php','expect'=>'Academic Report Card'],
    'finance' => ['file'=>'school/finance/index.php','expect'=>'Fees & finance'],
    'staff' => ['file'=>'school/staff/index.php','expect'=>'Staff & assignments'],
    'children' => ['file'=>'school/portal/children.php','expect'=>'My children'],
    'child' => ['file'=>'school/portal/child.php','expect'=>'Published academic results'],
    'homework' => ['file'=>'school/homework/index.php','expect'=>'Homework'],
    'guardians' => ['file'=>'school/students/guardians.php','expect'=>'Guardian links'],
    'record' => ['file'=>'school/students/record.php','expect'=>'Access and history'],
    'documents' => ['file'=>'school/students/documents.php','expect'=>'Stored documents'],
    'receipt' => ['file'=>'school/finance/receipt.php','expect'=>'SCHOOL INVOICE'],
];
if (!isset($routes[$route]) || $username === '') { fwrite(STDERR, "Usage: php tests/school/feature_render.php <route> <username> [student_or_invoice_id]\n"); exit(2); }
$pdo = SchoolDatabase::connection();
$q = $pdo->prepare('SELECT id FROM users WHERE username=:username AND status=\'active\''); $q->execute(['username'=>$username]); $userId=(int)$q->fetchColumn();
if (!$userId) { fwrite(STDERR, "Test account not found.\n"); exit(1); }
session_save_path(sys_get_temp_dir()); session_name('SCHOOL_SESSION'); session_id('school-render-'.bin2hex(random_bytes(8))); session_start();
$_SESSION['school_user_id']=$userId;$_SESSION['school_login_at']=time();$_SESSION['school_csrf']=bin2hex(random_bytes(32));
$_SERVER['SCRIPT_NAME']='/school/'.str_replace('_','/',$route).'.php';
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REMOTE_ADDR']='127.0.0.1';
if($route==='academic_setup')$_SERVER['SCRIPT_NAME']='/school/settings/academic.php';
if($route==='school_profile')$_SERVER['SCRIPT_NAME']='/school/settings/profile.php';
if($route==='academics')$_SERVER['SCRIPT_NAME']='/school/academics/index.php';
if($route==='report') { $_SERVER['SCRIPT_NAME']='/school/academics/report.php';$_GET['id']=isset($argv[3])&&ctype_digit($argv[3])?(int)$argv[3]:0; }
if($route==='finance')$_SERVER['SCRIPT_NAME']='/school/finance/index.php';
if($route==='staff')$_SERVER['SCRIPT_NAME']='/school/staff/index.php';
if($route==='children')$_SERVER['SCRIPT_NAME']='/school/portal/children.php';
if($route==='child') { $_SERVER['SCRIPT_NAME']='/school/portal/child.php';$_GET['id']=isset($argv[3])&&ctype_digit($argv[3])?(int)$argv[3]:0; }
if($route==='receipt') { $_SERVER['SCRIPT_NAME']='/school/finance/receipt.php';$_GET['invoice_id']=isset($argv[3])&&ctype_digit($argv[3])?(int)$argv[3]:0; }
if($route==='attendance')$_SERVER['SCRIPT_NAME']='/school/attendance/index.php';
if($route==='students')$_SERVER['SCRIPT_NAME']='/school/students/index.php';
if($route==='homework')$_SERVER['SCRIPT_NAME']='/school/homework/index.php';
if($route==='guardians')$_SERVER['SCRIPT_NAME']='/school/students/guardians.php';
if($route==='record') { $_SERVER['SCRIPT_NAME']='/school/students/record.php';$_GET['id']=isset($argv[3])&&ctype_digit($argv[3])?(int)$argv[3]:0; }
if($route==='documents') { $_SERVER['SCRIPT_NAME']='/school/students/documents.php';$_GET['id']=isset($argv[3])&&ctype_digit($argv[3])?(int)$argv[3]:0; }
if($route==='dashboard')$_SERVER['SCRIPT_NAME']='/school/dashboard.php';
ob_start();
if($expectDenied){register_shutdown_function(static function()use($route):void{$html=ob_get_contents()?:'';$status=http_response_code();while(ob_get_level()>0)ob_end_clean();if($status<400){fwrite(STDERR,"FAIL authorization: {$route} was not denied.\n");exit(1);}echo "PASS DENY {$route}\n";});}
require dirname(__DIR__, 2).'/'.$routes[$route]['file'];
$html=ob_get_clean();
if($route==='report'&&($outputPath=getenv('SCHOOL_TEST_RENDER_OUTPUT'))){if(file_put_contents($outputPath,$html)===false){fwrite(STDERR,"Could not save rendered report HTML.\n");exit(1);}}
if($expectDenied){fwrite(STDERR,"FAIL authorization: {$route} rendered for a denied account.\n");exit(1);}
if(!str_contains($html,$routes[$route]['expect'])){fwrite(STDERR,"Rendered page missing expected content for {$route}.\n");exit(1);}
if(!in_array($route,['receipt','report'],true)&&(!str_contains($html,'href="/school/')||!str_contains($html,'href="/assets/css/style.css'))){fwrite(STDERR,"Nested route did not render the shared school links and loan-system stylesheet for {$route}.\n");exit(1);}
echo "PASS {$route} as {$username}\n";
