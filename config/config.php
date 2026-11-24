<?php
/**
 * config.php — Weekly Loan Management System
 * -------------------------------------------------------
 * Global configuration, error handling, session hardening
 * and the application constants every page relies on.
 * -------------------------------------------------------
 */

declare(strict_types=1);

// ============================================================
// 1. ENVIRONMENT
// ============================================================
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        || in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true)
        || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost');

define('APP_ENV', $isLocal ? 'development' : 'production');

// ============================================================
// 2. ERROR HANDLING
// ============================================================
error_reporting(E_ALL);

if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    ini_set('error_log', $logDir . '/php_errors.log');
}

// ============================================================
// 3. TIMEZONE
// ============================================================
date_default_timezone_set('Africa/Dar_es_Salaam');

// ============================================================
// 4. SESSION HARDENING
// ============================================================
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '28800');   // 8 hours
ini_set('session.cookie_samesite', 'Lax');

if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}

session_name('LMS_SESSION');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 5. APPLICATION CONSTANTS
// ============================================================
define('APP_NAME', 'Loan Manager');
define('APP_TAGLINE', 'Weekly Loan Management System');
define('APP_VERSION', '1.0.0');
define('BASE_URL', '/loan/');
define('APP_ROOT', dirname(__DIR__));
define('SESSION_FINGERPRINT_SECRET', 'lms-tz-2026-change-this-long-random-secret');

define('DEFAULT_CURRENCY', 'TZS');
define('DISPLAY_DATE_FORMAT', 'd/m/Y');   // DD/MM/YYYY
define('DB_DATE_FORMAT', 'Y-m-d');

define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('UPLOAD_URL', BASE_URL . 'uploads');

// Customer photographs live outside the served asset folders and are
// streamed by media.php after an authentication check.
define('PRIVATE_DIR', APP_ROOT . '/storage/private');

define('MAX_PHOTO_BYTES', 4 * 1024 * 1024);   // 4 MB

define('DEFAULT_WEEKS', 12);
define('DEFAULT_WEEKLY_AMOUNT', 20000);
define('OVERDUE_GRACE_DAYS', 0);

// ============================================================
// 6. BOOT SECURITY (before anything touches the session data)
// ============================================================
require_once __DIR__ . '/../includes/security.php';
