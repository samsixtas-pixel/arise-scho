<?php
declare(strict_types=1);

namespace School\Services;

use PDO;
use RuntimeException;
use School\Core\SchoolConfig;
use School\Core\SchoolDatabase;

/** Seeds clearly labeled local demonstration records without creating/resetting user accounts. */
final class DemoSchoolDataSeeder
{
    public function seed(): void
    {
        if (!SchoolConfig::isDemoAllowed()) throw new RuntimeException('Sample school data can only be seeded in local or testing mode.');
        SchoolDatabase::transaction(function (PDO $pdo): void {
            $users = [];
            $query = $pdo->query("SELECT id,username FROM users WHERE username IN ('doreen','mmari','dejene')");
            foreach ($query->fetchAll() as $row) $users[$row['username']] = (int) $row['id'];
            foreach (['doreen','mmari','dejene'] as $required) if (empty($users[$required])) throw new RuntimeException("Create the {$required} role account before seeding sample records.");

            $q=$pdo->prepare("INSERT INTO academic_years (name,starts_on,ends_on,status) VALUES ('2026','2026-01-05','2026-12-15','active') ON DUPLICATE KEY UPDATE status='active'");$q->execute();
            $yearId=(int)$pdo->query("SELECT id FROM academic_years WHERE name='2026'")->fetchColumn();
            $q=$pdo->prepare("INSERT INTO terms (academic_year_id,name,starts_on,ends_on,status) VALUES (:year,'Term 3','2026-09-01','2026-12-15','active') ON DUPLICATE KEY UPDATE status='active'");$q->execute(['year'=>$yearId]);
            $termId=(int)$pdo->query("SELECT id FROM terms WHERE academic_year_id={$yearId} AND name='Term 3'")->fetchColumn();
            $q=$pdo->prepare("INSERT INTO class_groups (academic_year_id,level,name,capacity) VALUES (:year,'primary','Standard 6',40) ON DUPLICATE KEY UPDATE capacity=VALUES(capacity)");$q->execute(['year'=>$yearId]);
            $classId=(int)$pdo->query("SELECT id FROM class_groups WHERE academic_year_id={$yearId} AND name='Standard 6'")->fetchColumn();
            $q=$pdo->prepare("INSERT INTO streams (class_group_id,name) VALUES (:class,'A') ON DUPLICATE KEY UPDATE name=VALUES(name)");$q->execute(['class'=>$classId]);
            $streamId=(int)$pdo->query("SELECT id FROM streams WHERE class_group_id={$classId} AND name='A'")->fetchColumn();
            $pdo->exec("INSERT INTO subjects (code,name,level) VALUES ('MATH','Mathematics','both'),('ENG','English','both') ON DUPLICATE KEY UPDATE name=VALUES(name)");

            $pdo->exec("INSERT INTO students (admission_number,full_name,date_of_birth,gender,admission_date,status) VALUES ('DEMO-26001','Amina Sample','2014-04-12','female','2026-01-12','active'),('DEMO-26002','Daniel Sample','2013-08-21','male','2026-02-09','active') ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),status='active'");
            $studentIds=[];foreach(['DEMO-26001','DEMO-26002'] as $admission){$q=$pdo->prepare('SELECT id FROM students WHERE admission_number=:admission');$q->execute(['admission'=>$admission]);$studentIds[]=(int)$q->fetchColumn();}
            $q=$pdo->prepare("INSERT INTO guardians (user_id,relationship_label) VALUES (:user,'Parent / Guardian') ON DUPLICATE KEY UPDATE relationship_label=VALUES(relationship_label)");$q->execute(['user'=>$users['doreen']]);
            $guardianId=(int)$pdo->query('SELECT id FROM guardians WHERE user_id='.(int)$users['doreen'])->fetchColumn();
            $link=$pdo->prepare("INSERT INTO student_guardians (student_id,guardian_id,relationship,verification_status,verified_by,verified_at) VALUES (:student,:guardian,'Parent','verified',:owner,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE verification_status='verified',verified_by=VALUES(verified_by),verified_at=VALUES(verified_at),ended_at=NULL");
            $enroll=$pdo->prepare("INSERT INTO enrollments (student_id,academic_year_id,class_group_id,stream_id,status,started_at) VALUES (:student,:year,:class,:stream,'active',:date) ON DUPLICATE KEY UPDATE class_group_id=VALUES(class_group_id),stream_id=VALUES(stream_id),status='active'");
            foreach($studentIds as $index=>$studentId){$link->execute(['student'=>$studentId,'guardian'=>$guardianId,'owner'=>$users['dejene']]);$enroll->execute(['student'=>$studentId,'year'=>$yearId,'class'=>$classId,'stream'=>$streamId,'date'=>$index===0?'2026-01-12':'2026-02-09']);}

            $q=$pdo->prepare("INSERT INTO staff (user_id,employee_number,department,employment_status) VALUES (:user,'DEMO-T001','Academics','active') ON DUPLICATE KEY UPDATE department=VALUES(department),employment_status='active'");$q->execute(['user'=>$users['mmari']]);
            $staffId=(int)$pdo->query('SELECT id FROM staff WHERE user_id='.(int)$users['mmari'])->fetchColumn();
            $mathId=(int)$pdo->query("SELECT id FROM subjects WHERE code='MATH'")->fetchColumn();
            $q=$pdo->prepare('INSERT IGNORE INTO teacher_assignments (staff_id,academic_year_id,class_group_id,stream_id,subject_id) VALUES (:staff,:year,:class,:stream,:subject)');$q->execute(['staff'=>$staffId,'year'=>$yearId,'class'=>$classId,'stream'=>$streamId,'subject'=>$mathId]);

            $q=$pdo->prepare("INSERT INTO examinations (term_id,name,type,workflow_status,starts_on,ends_on) VALUES (:term,'Term 3 Sample Results','terminal','published','2026-11-02','2026-11-20') ON DUPLICATE KEY UPDATE workflow_status='published'");$q->execute(['term'=>$termId]);
            $examId=(int)$pdo->query("SELECT id FROM examinations WHERE term_id={$termId} AND name='Term 3 Sample Results'")->fetchColumn();
            $q=$pdo->prepare('INSERT IGNORE INTO assessments (examination_id,subject_id,class_group_id,max_mark,weight) VALUES (:exam,:subject,:class,100,1)');$q->execute(['exam'=>$examId,'subject'=>$mathId,'class'=>$classId]);
            $assessmentId=(int)$pdo->query("SELECT id FROM assessments WHERE examination_id={$examId} AND subject_id={$mathId} AND class_group_id={$classId}")->fetchColumn();
            $marks=[84,77];$result=$pdo->prepare("INSERT INTO results (assessment_id,student_id,mark,status,current_version) VALUES (:assessment,:student,:mark,'published',1) ON DUPLICATE KEY UPDATE mark=VALUES(mark),status='published'");
            $version=$pdo->prepare('INSERT IGNORE INTO result_versions (result_id,version,mark,grade,changed_by,reason,created_at) VALUES (:result,1,:mark,:grade,:actor,\'Local sample data\',UTC_TIMESTAMP())');
            foreach($studentIds as $i=>$studentId){$result->execute(['assessment'=>$assessmentId,'student'=>$studentId,'mark'=>$marks[$i]]);$q=$pdo->prepare('SELECT id FROM results WHERE assessment_id=:assessment AND student_id=:student');$q->execute(['assessment'=>$assessmentId,'student'=>$studentId]);$resultId=(int)$q->fetchColumn();$version->execute(['result'=>$resultId,'mark'=>$marks[$i],'grade'=>$marks[$i]>=80?'A':'B','actor'=>$users['mmari']]);}

            $attendance=$pdo->prepare("INSERT INTO attendance_sessions (class_group_id,stream_id,session_date,period_label,taken_by) VALUES (:class,:stream,:date,'Daily',:teacher) ON DUPLICATE KEY UPDATE taken_by=VALUES(taken_by)");
            $record=$pdo->prepare("INSERT INTO attendance_records (session_id,student_id,state,recorded_by) VALUES (:session,:student,:state,:teacher) ON DUPLICATE KEY UPDATE state=VALUES(state)");
            foreach(['2026-11-16','2026-11-17','2026-11-18'] as $dayIndex=>$day){$attendance->execute(['class'=>$classId,'stream'=>$streamId,'date'=>$day,'teacher'=>$users['mmari']]);$sessionId=(int)$pdo->query("SELECT id FROM attendance_sessions WHERE class_group_id={$classId} AND stream_id={$streamId} AND session_date='{$day}' AND period_label='Daily'")->fetchColumn();foreach($studentIds as $i=>$studentId){$state=($dayIndex===1&&$i===1)?'absent':(($dayIndex===0&&$i===1)?'late':'present');$record->execute(['session'=>$sessionId,'student'=>$studentId,'state'=>$state,'teacher'=>$users['mmari']]);}}

            $q=$pdo->prepare("INSERT INTO fee_structures (term_id,class_group_id,code,label,amount,due_date) VALUES (:term,:class,'TUITION','Tuition fee',250000,'2026-10-30') ON DUPLICATE KEY UPDATE amount=VALUES(amount)");$q->execute(['term'=>$termId,'class'=>$classId]);
            $feeId=(int)$pdo->query("SELECT id FROM fee_structures WHERE term_id={$termId} AND class_group_id={$classId} AND code='TUITION'")->fetchColumn();
            foreach($studentIds as $index=>$studentId){$number='DEMO-INV-'.($index+1);$q=$pdo->prepare("INSERT INTO invoices (student_id,term_id,invoice_number,status,issued_at,due_at) VALUES (:student,:term,:number,:status,'2026-09-01','2026-10-30') ON DUPLICATE KEY UPDATE status=VALUES(status)");$q->execute(['student'=>$studentId,'term'=>$termId,'number'=>$number,'status'=>$index===0?'part_paid':'issued']);$invoiceId=(int)$pdo->query('SELECT id FROM invoices WHERE invoice_number='.$pdo->quote($number))->fetchColumn();$q=$pdo->prepare("INSERT INTO invoice_items (invoice_id,fee_structure_id,description,amount) SELECT :invoice,:fee,'Tuition fee',250000 WHERE NOT EXISTS (SELECT 1 FROM invoice_items WHERE invoice_id=:check)");$q->execute(['invoice'=>$invoiceId,'fee'=>$feeId,'check'=>$invoiceId]);}
            $q=$pdo->prepare("INSERT INTO payments (payer_user_id,amount,currency,method,reference,status,idempotency_key,created_at) VALUES (:payer,100000,'TZS','mobile_money','DEMO-PAY-001','succeeded','DEMO-PAY-001',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status='succeeded'");$q->execute(['payer'=>$users['doreen']]);$paymentId=(int)$pdo->query("SELECT id FROM payments WHERE reference='DEMO-PAY-001'")->fetchColumn();$invoiceId=(int)$pdo->query("SELECT id FROM invoices WHERE invoice_number='DEMO-INV-1'")->fetchColumn();$q=$pdo->prepare('INSERT IGNORE INTO payment_allocations (payment_id,invoice_id,amount) VALUES (:payment,:invoice,100000)');$q->execute(['payment'=>$paymentId,'invoice'=>$invoiceId]);
        });
    }
}
