<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

use School\Core\SchoolAuth;
use School\Core\SchoolDatabase;
use School\Services\PdfRenderer;
use School\Services\SchoolDocumentBuilder;

SchoolAuth::requireLogin();
$user = SchoolAuth::user();
if (!$user) { header('Location: ' . SCHOOL_BASE_URL . 'login.php'); exit; }
$role = school_current_role($user);
$type = (string) ($_GET['type'] ?? '');
$builder = new SchoolDocumentBuilder();
$pdo = SchoolDatabase::connection();

$deny = static function (string $message, int $status = 403): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
};

$studentAccess = static function (int $studentId) use ($user, $role, $deny): void {
    if ($studentId <= 0) $deny('A learner is required.', 400);
    if ($role === 'parent_guardian') {
        if (!SchoolAuth::can('portal.child', ['student_id' => $studentId])) $deny('You are not permitted to download this learner document.');
        return;
    }
    if ($role === 'student') {
        if (!SchoolAuth::can('portal.self', ['student_id' => $studentId])) $deny('You are not permitted to download this learner document.');
        return;
    }
    if (!SchoolAuth::hasPermission('students.view') && !SchoolAuth::hasPermission('results.review') && !SchoolAuth::hasPermission('finance.view')) {
        $deny('Learner document access is not permitted for your account.');
    }
};

$classAccess = static function (int $classId) use ($user, $role, $deny): array {
    if ($classId <= 0) $deny('A class is required.', 400);
    $query = SchoolDatabase::connection()->prepare('SELECT c.id,c.name,c.academic_year_id FROM class_groups c WHERE c.id=:id');
    $query->execute(['id' => $classId]);
    $class = $query->fetch();
    if (!$class) $deny('Class not found.', 404);
    if (in_array($role, ['class_teacher', 'subject_teacher'], true)) {
        $check = SchoolDatabase::connection()->prepare("SELECT 1 FROM teacher_assignments ta JOIN staff sf ON sf.id=ta.staff_id WHERE sf.user_id=:user AND ta.class_group_id=:class AND ta.academic_year_id=:year AND ta.ended_at IS NULL AND sf.employment_status='active' LIMIT 1");
        $check->execute(['user' => (int) $user['id'], 'class' => $classId, 'year' => (int) $class['academic_year_id']]);
        if (!$check->fetchColumn()) $deny('You are not assigned to this class.');
    }
    return $class;
};

try {
    switch ($type) {
        case 'report_card':
            $studentId = (int) ($_GET['student_id'] ?? 0);
            $examId = (int) ($_GET['exam_id'] ?? 0);
            if ($examId <= 0) $deny('An assessment is required.', 400);
            $studentAccess($studentId);
            if (in_array($role, ['class_teacher', 'subject_teacher'], true)) {
                $check = $pdo->prepare("SELECT 1 FROM results r JOIN assessments a ON a.id=r.assessment_id JOIN examinations e ON e.id=a.examination_id JOIN terms t ON t.id=e.term_id JOIN teacher_assignments ta ON ta.class_group_id=a.class_group_id AND ta.subject_id=a.subject_id AND ta.academic_year_id=t.academic_year_id JOIN staff sf ON sf.id=ta.staff_id WHERE sf.user_id=:user AND a.examination_id=:exam AND r.student_id=:student AND ta.ended_at IS NULL LIMIT 1");
                $check->execute(['user' => (int) $user['id'], 'exam' => $examId, 'student' => $studentId]);
                if (!$check->fetchColumn()) $deny('You are not assigned to this learner\'s assessment.');
            } elseif (!in_array($role, ['parent_guardian', 'student'], true) && !SchoolAuth::hasPermission('results.review') && !SchoolAuth::hasPermission('results.publish') && !SchoolAuth::hasPermission('school.manage')) {
                $deny('Report card access is not permitted for your account.');
            }
            $document = $builder->reportCard($studentId, $examId);
            break;

        case 'class_reports':
            $examId = (int) ($_GET['exam_id'] ?? 0);
            $classId = (int) ($_GET['class_id'] ?? 0);
            if ($examId <= 0) $deny('An assessment is required.', 400);
            $classAccess($classId);
            if (!SchoolAuth::hasPermission('results.review') && !SchoolAuth::hasPermission('results.publish') && !SchoolAuth::hasPermission('school.manage') && !SchoolAuth::hasPermission('results.import')) {
                $deny('Bulk report cards are not permitted for your account.');
            }
            $document = $builder->classReportCards($examId, $classId);
            break;

        case 'invoice':
            if (!SchoolAuth::hasPermission('finance.view')) $deny('Finance access is not permitted.');
            $invoiceId = (int) ($_GET['invoice_id'] ?? 0);
            $owner = $pdo->prepare('SELECT student_id FROM invoices WHERE id=:id');
            $owner->execute(['id' => $invoiceId]);
            $studentId = (int) $owner->fetchColumn();
            if ($studentId <= 0) $deny('Invoice not found.', 404);
            if (in_array($role, ['parent_guardian', 'student'], true)) $studentAccess($studentId);
            $document = $builder->invoice($invoiceId);
            break;

        case 'receipt':
            if (!SchoolAuth::hasPermission('finance.view')) $deny('Finance access is not permitted.');
            $paymentId = (int) ($_GET['payment_id'] ?? 0);
            $owner = $pdo->prepare('SELECT i.student_id FROM payment_allocations pa JOIN invoices i ON i.id=pa.invoice_id WHERE pa.payment_id=:id LIMIT 1');
            $owner->execute(['id' => $paymentId]);
            $studentId = (int) $owner->fetchColumn();
            if ($studentId > 0 && in_array($role, ['parent_guardian', 'student'], true)) $studentAccess($studentId);
            $document = $builder->receipt($paymentId);
            break;

        case 'fee_statement':
            if (!SchoolAuth::hasPermission('finance.view') && !in_array($role, ['parent_guardian', 'student'], true)) $deny('Fee statement access is not permitted.');
            $studentId = (int) ($_GET['student_id'] ?? 0);
            $studentAccess($studentId);
            $document = $builder->feeStatement($studentId, (int) ($_GET['year_id'] ?? 0));
            break;

        case 'class_list':
            $classId = (int) ($_GET['class_id'] ?? 0);
            $classAccess($classId);
            if (!SchoolAuth::hasPermission('students.view')) $deny('Class register access is not permitted.');
            $document = $builder->classList($classId);
            break;

        case 'attendance':
            $classId = (int) ($_GET['class_id'] ?? 0);
            $classAccess($classId);
            if (!SchoolAuth::hasPermission('attendance.view')) $deny('Attendance report access is not permitted.');
            $from = (string) ($_GET['from'] ?? date('Y-m-01'));
            $to = (string) ($_GET['to'] ?? date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $deny('Enter valid report dates.', 400);
            $document = $builder->attendanceReport($classId, $from, $to);
            break;

        case 'transcript':
            $studentId = (int) ($_GET['student_id'] ?? 0);
            $studentAccess($studentId);
            $document = $builder->transcript($studentId);
            break;

        case 'admission_letter':
            $studentId = (int) ($_GET['student_id'] ?? 0);
            $studentAccess($studentId);
            if (!SchoolAuth::hasPermission('students.view') && !SchoolAuth::hasPermission('admissions.view')) $deny('Admission letter access is not permitted.');
            $document = $builder->admissionLetter($studentId);
            break;

        case 'id_cards':
            $studentId = (int) ($_GET['student_id'] ?? 0);
            $classId = (int) ($_GET['class_id'] ?? 0);
            if ($studentId > 0) {
                $studentAccess($studentId);
            } else {
                $classAccess($classId);
            }
            if (!SchoolAuth::hasPermission('students.view') && !SchoolAuth::hasPermission('documents.manage')) $deny('Identity card access is not permitted.');
            $document = $builder->idCards($studentId, $classId);
            break;

        case 'management_report':
            if (!SchoolAuth::hasPermission('reports.view') && !SchoolAuth::hasPermission('analytics.school') && !SchoolAuth::hasPermission('analytics.finance')) {
                $deny('Management report access is not permitted.');
            }
            $document = $builder->managementReport([
                'year_id' => (int) ($_GET['year_id'] ?? 0),
                'term_id' => (int) ($_GET['term_id'] ?? 0),
                'class_id' => (int) ($_GET['class_id'] ?? 0),
                'from' => (string) ($_GET['from'] ?? ''),
                'to' => (string) ($_GET['to'] ?? ''),
            ], $user, $role);
            break;

        default:
            $deny('Unknown document type.', 404);
    }
} catch (Throwable $exception) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($exception->getMessage());
}

$renderer = new PdfRenderer();
$inline = (bool) ($_GET['inline'] ?? false);

if (!$renderer->available()) {
    // Browser-print fallback: the same document, opened with the print dialog.
    header('Content-Type: text/html; charset=UTF-8');
    echo str_replace('</body>', '<script>window.addEventListener("load",function(){window.print();});</script></body>', $document['html']);
    exit;
}

try {
    $renderer->stream($document['html'], $document['filename'], $inline, ['landscape' => (bool) ($document['landscape'] ?? false)]);
} catch (Throwable $exception) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('The PDF could not be generated. ' . $exception->getMessage());
}
