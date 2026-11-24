<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

use School\Core\SchoolAuth;
use School\Core\SchoolDatabase;
use School\Services\ResultImportService;
use School\Services\ResultTemplateBuilder;

SchoolAuth::requireLogin();
$user = SchoolAuth::user();
if (!$user) { header('Location: ' . SCHOOL_BASE_URL . 'login.php'); exit; }
$role = school_current_role($user);
if (!SchoolAuth::hasPermission('results.import')) {
    http_response_code(403);
    exit('Result import is not permitted for your account.');
}

try {
    $service = new ResultImportService();
    $scope = $service->resolveScope(
        (int) ($_GET['examination_id'] ?? 0),
        (int) ($_GET['class_group_id'] ?? 0),
        (int) ($_GET['subject_id'] ?? 0),
        $user,
        $role,
        true,
        (float) ($_GET['max_mark'] ?? 100)
    );
    $schoolName = (string) (SchoolDatabase::connection()->query('SELECT name FROM school_profile ORDER BY id LIMIT 1')->fetchColumn() ?: 'School Management');
    $path = (new ResultTemplateBuilder())->build([
        'school_name' => $schoolName,
        'year_name' => $scope['year_name'],
        'term_name' => $scope['term_name'],
        'exam_name' => $scope['exam_name'],
        'class_name' => $scope['class_name'],
        'subject_name' => $scope['subject_name'],
        'max_mark' => $scope['max_mark'],
        'generated_by' => $user['full_name'],
        'roster' => $scope['roster'],
    ]);
    $filename = 'results-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $scope['class_name']) . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $scope['subject_code']) . '-' . date('Ymd') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    @unlink($path);
    exit;
} catch (Throwable $exception) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($exception->getMessage());
}
