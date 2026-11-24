<?php
declare(strict_types=1);

namespace School\Services;

use School\Core\SchoolConfig;
use School\Core\SchoolDatabase;
use RuntimeException;

final class DemoUserSeeder
{
    private const PASSWORD = 'SchoolDemo@2026!';

    public function seed(): void
    {
        if (!SchoolConfig::isDemoAllowed()) {
            throw new RuntimeException('Demo seeding is disabled unless SCHOOL_APP_ENV is local or testing.');
        }
        $pdo = SchoolDatabase::connection();
        $roles = array_column($pdo->query('SELECT id, name FROM roles')->fetchAll(), 'id', 'name');
        $accounts = [
            ['Parent Demo', 'parent.demo@school.test', 'parent_guardian'],
            ['Teacher Demo', 'teacher.demo@school.test', 'subject_teacher'],
            ['Accountant Demo', 'accountant.demo@school.test', 'accountant'],
            ['Manager Demo', 'manager.demo@school.test', 'school_manager'],
            ['School Owner Demo', 'owner.demo@school.test', 'school_owner'],
        ];
        SchoolDatabase::transaction(function (\PDO $pdo) use ($accounts, $roles): void {
            foreach ($accounts as [$name, $email, $roleName]) {
                if (!isset($roles[$roleName])) throw new RuntimeException("Missing role: {$roleName}");
                $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, status, demo_account, created_at, updated_at) VALUES (:name, :email, :password, 'active', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), status = 'active', demo_account = 1, updated_at = UTC_TIMESTAMP()");
                $stmt->execute(['name' => $name, 'email' => $email, 'password' => $hash]);
                $userId = (int) $pdo->lastInsertId();
                if (!$userId) {
                    $lookup = $pdo->prepare('SELECT id FROM users WHERE email = :email');
                    $lookup->execute(['email' => $email]);
                    $userId = (int) $lookup->fetchColumn();
                }
                $link = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:user, :role)');
                $link->execute(['user' => $userId, 'role' => $roles[$roleName]]);
            }
        });
        $this->seedSampleData();
    }

    private function seedSampleData(): void
    {
        $pdo = SchoolDatabase::connection();
        SchoolDatabase::transaction(function (\PDO $pdo): void {
            $pdo->exec("INSERT INTO school_profile (id, name, motto, address, timezone, currency) VALUES (1, 'Demo School', 'Learning with purpose', 'Dar es Salaam, Tanzania', 'Africa/Dar_es_Salaam', 'TZS') ON DUPLICATE KEY UPDATE name = VALUES(name)");
            $pdo->exec("INSERT INTO academic_years (name, starts_on, ends_on, status) VALUES ('2026', '2026-01-05', '2026-12-15', 'active') ON DUPLICATE KEY UPDATE status = 'active'");
            $yearId = (int) $pdo->query("SELECT id FROM academic_years WHERE name = '2026'")->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO terms (academic_year_id, name, starts_on, ends_on, status) VALUES (:year, 'Term 3', '2026-09-01', '2026-12-15', 'active') ON DUPLICATE KEY UPDATE status = 'active'");
            $stmt->execute(['year' => $yearId]);
            $termId = (int) $pdo->query("SELECT id FROM terms WHERE academic_year_id = {$yearId} AND name = 'Term 3'")->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO class_groups (academic_year_id, level, name, capacity) VALUES (:year, 'primary', 'Standard 6', 40) ON DUPLICATE KEY UPDATE capacity = VALUES(capacity)");
            $stmt->execute(['year' => $yearId]);
            $classId = (int) $pdo->query("SELECT id FROM class_groups WHERE academic_year_id = {$yearId} AND name = 'Standard 6'")->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO streams (class_group_id, name) VALUES (:class, 'A') ON DUPLICATE KEY UPDATE name = VALUES(name)");
            $stmt->execute(['class' => $classId]);
            $streamId = (int) $pdo->query("SELECT id FROM streams WHERE class_group_id = {$classId} AND name = 'A'")->fetchColumn();
            $pdo->exec("INSERT INTO subjects (code, name, level) VALUES ('MATH', 'Mathematics', 'both'), ('ENG', 'English', 'both') ON DUPLICATE KEY UPDATE name = VALUES(name)");

            $users = [];
            $lookup = $pdo->query("SELECT id, email FROM users WHERE email IN ('parent.demo@school.test','teacher.demo@school.test','accountant.demo@school.test','manager.demo@school.test','owner.demo@school.test')");
            foreach ($lookup->fetchAll() as $row) $users[$row['email']] = (int) $row['id'];
            $parentId = $users['parent.demo@school.test'];
            $teacherId = $users['teacher.demo@school.test'];
            $stmt = $pdo->prepare("INSERT INTO guardians (user_id, relationship_label) VALUES (:user, 'Parent') ON DUPLICATE KEY UPDATE relationship_label = VALUES(relationship_label)");
            $stmt->execute(['user' => $parentId]);
            $guardianId = (int) $pdo->query("SELECT id FROM guardians WHERE user_id = {$parentId}")->fetchColumn();
            $pdo->exec("INSERT INTO students (admission_number, full_name, date_of_birth, gender, admission_date) VALUES ('DEMO-26001','Amina Joseph','2014-04-12','female','2022-01-10'),('DEMO-26002','Daniel Joseph','2013-08-21','male','2021-01-11') ON DUPLICATE KEY UPDATE full_name = VALUES(full_name)");
            $studentIds = [];
            foreach (['DEMO-26001', 'DEMO-26002'] as $admission) {
                $q = $pdo->prepare('SELECT id FROM students WHERE admission_number = :admission'); $q->execute(['admission' => $admission]); $studentIds[] = (int) $q->fetchColumn();
            }
            $link = $pdo->prepare("INSERT INTO student_guardians (student_id, guardian_id, relationship, verification_status, verified_by, verified_at) VALUES (:student, :guardian, 'Parent', 'verified', :verifier, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE verification_status = 'verified', verified_by = VALUES(verified_by), verified_at = VALUES(verified_at), ended_at = NULL");
            foreach ($studentIds as $studentId) {
                $link->execute(['student' => $studentId, 'guardian' => $guardianId, 'verifier' => $users['owner.demo@school.test']]);
                $enroll = $pdo->prepare("INSERT INTO enrollments (student_id, academic_year_id, class_group_id, stream_id, status, started_at) VALUES (:student, :year, :class, :stream, 'active', '2026-01-05') ON DUPLICATE KEY UPDATE class_group_id = VALUES(class_group_id), stream_id = VALUES(stream_id), status = 'active'");
                $enroll->execute(['student' => $studentId, 'year' => $yearId, 'class' => $classId, 'stream' => $streamId]);
            }
            $stmt = $pdo->prepare("INSERT INTO staff (user_id, employee_number, department, employment_status) VALUES (:user, 'DEMO-T001', 'Academics', 'active') ON DUPLICATE KEY UPDATE department = VALUES(department)");
            $stmt->execute(['user' => $teacherId]);
            $staffId = (int) $pdo->query("SELECT id FROM staff WHERE user_id = {$teacherId}")->fetchColumn();
            $subjectId = (int) $pdo->query("SELECT id FROM subjects WHERE code = 'MATH'")->fetchColumn();
            $assignment = $pdo->prepare('INSERT IGNORE INTO teacher_assignments (staff_id, academic_year_id, class_group_id, stream_id, subject_id) VALUES (:staff,:year,:class,:stream,:subject)');
            $assignment->execute(['staff' => $staffId, 'year' => $yearId, 'class' => $classId, 'stream' => $streamId, 'subject' => $subjectId]);

            $stmt = $pdo->prepare("INSERT INTO examinations (term_id, name, type, workflow_status, starts_on, ends_on) VALUES (:term, 'Term 3 Assessment', 'continuous', 'open', '2026-11-02', '2026-11-20') ON DUPLICATE KEY UPDATE workflow_status = 'open'");
            $stmt->execute(['term' => $termId]);
            $examId = (int) $pdo->query("SELECT id FROM examinations WHERE term_id = {$termId} AND name = 'Term 3 Assessment'")->fetchColumn();
            if ($examId) {
                $assessment = $pdo->prepare('INSERT IGNORE INTO assessments (examination_id, subject_id, class_group_id, max_mark, weight) VALUES (:exam,:subject,:class,100,1)');
                $assessment->execute(['exam' => $examId, 'subject' => $subjectId, 'class' => $classId]);
                $assessmentLookup = $pdo->prepare('SELECT id FROM assessments WHERE examination_id = :exam AND subject_id = :subject AND class_group_id = :class');
                $assessmentLookup->execute(['exam' => $examId, 'subject' => $subjectId, 'class' => $classId]);
                $assessmentId = (int) $assessmentLookup->fetchColumn();
                if ($assessmentId) {
                    $result = $pdo->prepare("INSERT IGNORE INTO results (assessment_id, student_id, mark, status) VALUES (:assessment,:student,:mark,'submitted')");
                    foreach ($studentIds as $index => $studentId) $result->execute(['assessment' => $assessmentId, 'student' => $studentId, 'mark' => $index === 0 ? 82 : 76]);
                }
            }
            $stmt = $pdo->prepare("INSERT INTO fee_structures (term_id, class_group_id, code, label, amount, due_date) VALUES (:term,:class,'TUITION','Tuition fee',250000,'2026-11-30') ON DUPLICATE KEY UPDATE amount = VALUES(amount)");
            $stmt->execute(['term' => $termId, 'class' => $classId]);
            foreach ($studentIds as $index => $studentId) {
                $number = 'DEMO-INV-' . ($index + 1);
                $inv = $pdo->prepare("INSERT INTO invoices (student_id, term_id, invoice_number, status, issued_at, due_at) VALUES (:student,:term,:number,'issued',UTC_TIMESTAMP(),'2026-11-30') ON DUPLICATE KEY UPDATE status = 'issued'");
                $inv->execute(['student' => $studentId, 'term' => $termId, 'number' => $number]);
                $invoiceId = (int) $pdo->query('SELECT id FROM invoices WHERE invoice_number = ' . $pdo->quote($number))->fetchColumn();
                $feeId = (int) $pdo->query("SELECT id FROM fee_structures WHERE term_id = {$termId} AND class_group_id = {$classId} AND code = 'TUITION'")->fetchColumn();
                $item = $pdo->prepare("INSERT INTO invoice_items (invoice_id, fee_structure_id, description, amount) SELECT :invoice,:fee,'Tuition fee',250000 WHERE NOT EXISTS (SELECT 1 FROM invoice_items WHERE invoice_id = :check)");
                $item->execute(['invoice' => $invoiceId, 'fee' => $feeId, 'check' => $invoiceId]);
            }
            $session = $pdo->prepare("INSERT INTO attendance_sessions (class_group_id, stream_id, session_date, period_label, taken_by) VALUES (:class,:stream,'2026-11-17','Daily',:teacher) ON DUPLICATE KEY UPDATE taken_by = VALUES(taken_by)");
            $session->execute(['class' => $classId, 'stream' => $streamId, 'teacher' => $teacherId]);
            $sessionId = (int) $pdo->query("SELECT id FROM attendance_sessions WHERE class_group_id = {$classId} AND stream_id = {$streamId} AND session_date = '2026-11-17' AND period_label = 'Daily'")->fetchColumn();
            $attendance = $pdo->prepare('INSERT IGNORE INTO attendance_records (session_id, student_id, state, recorded_by) VALUES (:session,:student,:state,:teacher)');
            foreach ($studentIds as $index => $studentId) $attendance->execute(['session' => $sessionId, 'student' => $studentId, 'state' => $index === 0 ? 'present' : 'late', 'teacher' => $teacherId]);

            $teacherAssignmentId = (int) $pdo->query("SELECT id FROM teacher_assignments WHERE staff_id = {$staffId} AND academic_year_id = {$yearId} AND class_group_id = {$classId} AND subject_id = {$subjectId}")->fetchColumn();
            $homework = $pdo->prepare("INSERT INTO assignments (teacher_assignment_id, title, instructions, due_at, status) VALUES (:assignment, 'Fractions practice', 'Complete exercises 1–8 in your workbook.', '2026-11-24 14:00:00', 'published')");
            if (!(int) $pdo->query("SELECT COUNT(*) FROM assignments WHERE teacher_assignment_id = {$teacherAssignmentId} AND title = 'Fractions practice'")->fetchColumn()) {
                $homework->execute(['assignment' => $teacherAssignmentId]);
                $homeworkId = (int) $pdo->lastInsertId();
                $submission = $pdo->prepare("INSERT IGNORE INTO submissions (assignment_id, student_id, status) VALUES (:assignment,:student,'missing')");
                foreach ($studentIds as $studentId) $submission->execute(['assignment' => $homeworkId, 'student' => $studentId]);
            }

            $firstInvoice = (int) $pdo->query("SELECT id FROM invoices WHERE invoice_number = 'DEMO-INV-1'")->fetchColumn();
            $pdo->exec("INSERT INTO payments (payer_user_id, amount, currency, method, reference, status, idempotency_key, created_at) VALUES ({$parentId},100000,'TZS','mobile_money','DEMO-PAY-001','succeeded','demo-pay-001',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status = 'succeeded'");
            $paymentId = (int) $pdo->query("SELECT id FROM payments WHERE reference = 'DEMO-PAY-001'")->fetchColumn();
            $allocation = $pdo->prepare('INSERT IGNORE INTO payment_allocations (payment_id, invoice_id, amount) VALUES (:payment,:invoice,100000)');
            $allocation->execute(['payment' => $paymentId, 'invoice' => $firstInvoice]);
            $pdo->exec("UPDATE invoices SET status = 'part_paid' WHERE id = {$firstInvoice}");
        });
    }
}
