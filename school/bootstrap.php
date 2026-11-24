<?php
declare(strict_types=1);

use School\Core\SchoolAuth;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/autoload.php';
date_default_timezone_set('Africa/Dar_es_Salaam');
SchoolAuth::start();
if (PHP_VERSION_ID < 80300) { http_response_code(500); exit('School Management requires PHP 8.3 or newer.'); }
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

$schoolScriptPath = '/' . ltrim(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/school/login.php')), '/');
$schoolSchoolPosition = strpos($schoolScriptPath, '/school');
$schoolProjectBase = $schoolSchoolPosition === false ? '' : substr($schoolScriptPath, 0, $schoolSchoolPosition);
define('SCHOOL_BASE_URL', $schoolProjectBase . '/school/');
define('SCHOOL_ASSET_URL', $schoolProjectBase . '/assets/');

function school_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function e(mixed $value): string { return school_e($value); }
function t(string $text, array $replace = []): string
{
    foreach ($replace as $key => $value) $text = str_replace(':' . $key, (string) $value, $text);
    return $text;
}
function money(mixed $amount, bool $decimals = false): string { return 'TZS ' . number_format((float) $amount, $decimals ? 2 : 0, '.', ','); }
function num(mixed $amount, bool $decimals = false): string { return number_format((float) $amount, $decimals ? 2 : 0, '.', ','); }
function percent(mixed $part, mixed $whole): float { return (float) $whole > 0 ? round((float) $part * 100 / (float) $whole, 1) : 0.0; }
function formatDate(?string $date, string $fallback = '—'): string
{
    if (!$date || $date === '0000-00-00') return $fallback;
    $time = strtotime($date); return $time ? date('d/m/Y', $time) : $fallback;
}
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    return strtoupper(substr($parts[0] ?? 'S', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}
function school_flash(string $type, string $message): void { $_SESSION['school_flashes'][] = ['type' => $type, 'message' => $message]; }
function school_take_flashes(): array { $items = $_SESSION['school_flashes'] ?? []; unset($_SESSION['school_flashes']); return $items; }

function school_current_role(array $user): string
{
    $roles = array_column($user['roles'] ?? [], 'name');
    // Highest-responsibility role first. Finance roles sit below school
    // leadership but above teaching roles so a dual-role account lands on the
    // workspace that carries the widest permission set.
    $priority = [
        'school_owner','company_owner','school_manager','head_teacher','second_master',
        'academic_admin','school_administrator','finance_director','chief_accountant',
        'system_admin','accountant','senior_accountant','general_accountant',
        'accounts_receivable_officer','accounts_payable_officer','payroll_officer',
        'tax_accountant','cashier','procurement_officer','internal_auditor','external_auditor',
        'admissions_officer','secretary','discipline_master','sports_master','patron','matron',
        'school_nurse','storekeeper','librarian','transport_officer','department_manager',
        'readonly_manager','class_teacher','subject_teacher','parent_guardian','student',
    ];
    foreach ($priority as $role) if (in_array($role, $roles, true)) return $role;
    return 'user';
}

function school_role_label(string $role): string
{
    return match ($role) {
        'school_owner' => 'School Owner', 'school_manager' => 'School Manager', 'head_teacher' => 'Head Teacher',
        'second_master' => 'Second Master', 'academic_admin' => 'Academic Administrator',
        'school_administrator' => 'School Administrator', 'system_admin' => 'System Administrator',
        'accountant' => 'Accountant', 'class_teacher' => 'Class Teacher', 'subject_teacher' => 'Subject Teacher',
        'parent_guardian' => 'Parent / Guardian', 'student' => 'Student', 'librarian' => 'Librarian',
        'transport_officer' => 'Transport Officer', 'discipline_master' => 'Discipline Master',
        'sports_master' => 'Sports Master', 'patron' => 'Patron', 'matron' => 'Matron',
        'secretary' => 'Secretary', 'admissions_officer' => 'Admissions Officer',
        'school_nurse' => 'School Nurse', 'storekeeper' => 'Storekeeper',
        'company_owner' => 'Company Owner', 'finance_director' => 'Finance Director',
        'chief_accountant' => 'Chief Accountant', 'senior_accountant' => 'Senior Accountant',
        'general_accountant' => 'General Accountant', 'accounts_receivable_officer' => 'Accounts Receivable Officer',
        'accounts_payable_officer' => 'Accounts Payable Officer', 'payroll_officer' => 'Payroll Officer',
        'tax_accountant' => 'Tax Accountant', 'cashier' => 'Cashier', 'procurement_officer' => 'Procurement Officer',
        'internal_auditor' => 'Internal Auditor', 'external_auditor' => 'External Auditor',
        'department_manager' => 'Department Manager', 'readonly_manager' => 'Read-only Management',
        default => 'School User'
    };
}

/** True when the account belongs to the finance/accounting workspace. */
function school_is_finance_role(string $role): bool
{
    return in_array($role, [
        'accountant','company_owner','finance_director','chief_accountant','senior_accountant',
        'general_accountant','accounts_receivable_officer','accounts_payable_officer','payroll_officer',
        'tax_accountant','cashier','procurement_officer','internal_auditor','external_auditor',
    ], true);
}
