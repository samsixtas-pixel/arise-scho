<?php
declare(strict_types=1);

namespace School\Services;

use PDO;
use RuntimeException;
use School\Core\SchoolDatabase;

/**
 * Provisions the complete school + finance role catalog requested for this
 * installation. It is idempotent: existing usernames are kept, their role is
 * ensured, and their initial password is reset to the requested shared value.
 *
 * This is an administrative bootstrap tool. It never deletes accounts and it
 * records an audit entry for every provisioning run.
 */
final class AccountProvisioner
{
    public const INITIAL_PASSWORD = 'kali4290';

    /** username, display name, role, department, receives staff profile */
    private const ACCOUNTS = [
        // Existing accounts are refreshed as well.
        ['mmari', 'Mmari', 'subject_teacher', 'Academics', true],
        ['shock', 'Shock', 'school_manager', 'Administration', true],
        ['tutu', 'Tutu', 'accountant', 'Finance', true],
        ['dejene', 'Dejene', 'school_owner', 'Management', true],
        ['doreen', 'Doreen', 'parent_guardian', 'Parent', false],
        // Part A school operations.
        ['headmaster', 'Head Master', 'head_teacher', 'Administration', true],
        ['secondmaster', 'Second Master', 'second_master', 'Administration', true],
        ['academic', 'Academic Master', 'academic_admin', 'Academics', true],
        ['classteacher', 'Class Teacher', 'class_teacher', 'Academics', true],
        ['student1', 'Student Account', 'student', 'Student', false],
        ['discipline', 'Discipline Master', 'discipline_master', 'Student Affairs', true],
        ['sports', 'Sports Master', 'sports_master', 'Sports', true],
        ['patron', 'Patron', 'patron', 'Clubs and Societies', true],
        ['matron', 'Matron', 'matron', 'Boarding', true],
        ['secretary', 'Secretary', 'secretary', 'Administration', true],
        ['admissions', 'Admissions Officer', 'admissions_officer', 'Admissions', true],
        ['schooladmin', 'School Administrator', 'school_administrator', 'Administration', true],
        ['nurse', 'School Nurse', 'school_nurse', 'Health', true],
        ['librarian', 'Librarian', 'librarian', 'Library', true],
        ['storekeeper', 'Storekeeper', 'storekeeper', 'Stores', true],
        ['transport', 'Transport Officer', 'transport_officer', 'Transport', true],
        ['sysadmin', 'System Administrator', 'system_admin', 'ICT', true],
        // Part B finance and accounting workspace roles.
        ['companyowner', 'Company Owner', 'company_owner', 'Finance', false],
        ['financedirector', 'Finance Director', 'finance_director', 'Finance', false],
        ['chiefaccountant', 'Chief Accountant', 'chief_accountant', 'Finance', false],
        ['senioraccountant', 'Senior Accountant', 'senior_accountant', 'Finance', false],
        ['generalaccountant', 'General Accountant', 'general_accountant', 'Finance', false],
        ['arofficer', 'Accounts Receivable Officer', 'accounts_receivable_officer', 'Finance', false],
        ['apofficer', 'Accounts Payable Officer', 'accounts_payable_officer', 'Finance', false],
        ['payroll', 'Payroll Officer', 'payroll_officer', 'Finance', false],
        ['taxaccountant', 'Tax Accountant', 'tax_accountant', 'Finance', false],
        ['cashier', 'Cashier', 'cashier', 'Finance', false],
        ['procurement', 'Procurement Officer', 'procurement_officer', 'Procurement', false],
        ['internalauditor', 'Internal Auditor', 'internal_auditor', 'Audit', false],
        ['externalauditor', 'External Auditor', 'external_auditor', 'Audit', false],
        ['departmentmanager', 'Department Manager', 'department_manager', 'Academics', true],
        ['readonlymanager', 'Read-only Management User', 'readonly_manager', 'Management', false],
    ];

    /** @return array<int,array{username:string,full_name:string,role_name:string,role_label:string,user_id:int,created:bool}> */
    public function provision(): array
    {
        $pdo = SchoolDatabase::connection();
        $passwordHash = password_hash(self::INITIAL_PASSWORD, PASSWORD_DEFAULT);
        $results = [];

        SchoolDatabase::transaction(function (PDO $pdo) use ($passwordHash, &$results): void {
            $roleLookup = $pdo->prepare('SELECT id,label FROM roles WHERE name=:name');
            $userLookup = $pdo->prepare('SELECT id FROM users WHERE username=:username');
            $insertUser = $pdo->prepare(
                "INSERT INTO users (full_name,username,email,password_hash,status,demo_account,must_change_password,created_at,updated_at)
                 VALUES (:name,:username,NULL,:hash,'active',0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
            );
            $updateUser = $pdo->prepare(
                "UPDATE users SET password_hash=:hash,status='active',must_change_password=1,updated_at=UTC_TIMESTAMP() WHERE id=:id"
            );
            $linkRole = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id,role_id) VALUES (:user,:role)');
            $staff = $pdo->prepare(
                "INSERT INTO staff (user_id,employee_number,department,employment_status)
                 VALUES (:user,:employee,:department,'active')
                 ON DUPLICATE KEY UPDATE department=VALUES(department),employment_status='active'"
            );

            foreach (self::ACCOUNTS as [$username, $fullName, $roleName, $department, $isStaff]) {
                $roleLookup->execute(['name' => $roleName]);
                $role = $roleLookup->fetch();
                if (!$role) throw new RuntimeException("Role {$roleName} is missing. Apply migration 007 first.");

                $userLookup->execute(['username' => $username]);
                $userId = (int) $userLookup->fetchColumn();
                $created = false;
                if ($userId > 0) {
                    $updateUser->execute(['hash' => $passwordHash, 'id' => $userId]);
                } else {
                    $insertUser->execute(['name' => $fullName, 'username' => $username, 'hash' => $passwordHash]);
                    $userId = (int) $pdo->lastInsertId();
                    $created = true;
                }

                $linkRole->execute(['user' => $userId, 'role' => (int) $role['id']]);
                if ($isStaff) {
                    $staff->execute([
                        'user' => $userId,
                        'employee' => 'EMP-' . strtoupper($username),
                        'department' => $department,
                    ]);
                }

                $results[] = [
                    'username' => $username,
                    'full_name' => $fullName,
                    'role_name' => $roleName,
                    'role_label' => (string) $role['label'],
                    'user_id' => $userId,
                    'created' => $created,
                ];
            }

            // Give the class-teacher account a real subject assignment so the
            // teacher workspaces are immediately usable in the demo school.
            $this->assignClassTeacher($pdo);
            $this->linkStudentAccount($pdo);

            $audit = $pdo->prepare(
                "INSERT INTO audit_logs (actor_user_id,action,subject_type,subject_id,metadata_json,created_at)
                 VALUES (NULL,'user.accounts.provision','users',NULL,:metadata,UTC_TIMESTAMP())"
            );
            $audit->execute(['metadata' => json_encode([
                'accounts' => count($results),
                'password_rotated' => true,
                'initial_password_hint' => 'shared bootstrap password; change after first sign-in',
            ], JSON_THROW_ON_ERROR)]);
        });

        return $results;
    }

    private function assignClassTeacher(PDO $pdo): void
    {
        $user = $pdo->prepare('SELECT id FROM users WHERE username=:username');
        $user->execute(['username' => 'classteacher']);
        $userId = (int) $user->fetchColumn();
        if (!$userId) return;
        $staff = $pdo->prepare('SELECT id FROM staff WHERE user_id=:user');
        $staff->execute(['user' => $userId]);
        $staffId = (int) $staff->fetchColumn();
        if (!$staffId) return;

        $year = $pdo->query("SELECT id FROM academic_years WHERE status='active' ORDER BY starts_on DESC LIMIT 1")->fetchColumn();
        if (!$year) return;
        $class = $pdo->prepare('SELECT id FROM class_groups WHERE academic_year_id=:year ORDER BY id LIMIT 1');
        $class->execute(['year' => $year]);
        $classId = (int) $class->fetchColumn();
        if (!$classId) return;
        $subject = $pdo->prepare("SELECT id FROM subjects WHERE code='ENG' LIMIT 1");
        $subject->execute();
        $subjectId = (int) $subject->fetchColumn();
        if (!$subjectId) {
            $subject = $pdo->prepare('SELECT id FROM subjects WHERE active=1 ORDER BY id LIMIT 1');
            $subject->execute();
            $subjectId = (int) $subject->fetchColumn();
        }
        if (!$subjectId) return;

        $assignment = $pdo->prepare(
            'INSERT IGNORE INTO teacher_assignments (staff_id,academic_year_id,class_group_id,stream_id,subject_id)
             VALUES (:staff,:year,:class,NULL,:subject)'
        );
        $assignment->execute(['staff' => $staffId, 'year' => (int) $year, 'class' => $classId, 'subject' => $subjectId]);
    }

    private function linkStudentAccount(PDO $pdo): void
    {
        $user = $pdo->prepare('SELECT id FROM users WHERE username=:username');
        $user->execute(['username' => 'student1']);
        $userId = (int) $user->fetchColumn();
        if (!$userId) return;

        $already = $pdo->prepare('SELECT COUNT(*) FROM students WHERE user_id=:user');
        $already->execute(['user' => $userId]);
        if ((int) $already->fetchColumn() > 0) return;

        $student = $pdo->prepare("SELECT id FROM students WHERE user_id IS NULL AND status='active' ORDER BY (admission_number='DEMO-26001') DESC,id LIMIT 1");
        $student->execute();
        $studentId = (int) $student->fetchColumn();
        if ($studentId > 0) {
            $link = $pdo->prepare('UPDATE students SET user_id=:user WHERE id=:student AND user_id IS NULL');
            $link->execute(['user' => $userId, 'student' => $studentId]);
        }
    }
}
