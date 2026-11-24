<?php
declare(strict_types=1);

namespace School\Core;

use School\Repositories\UserRepository;
use School\Services\AuditService;

final class SchoolAuth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('SCHOOL_SESSION');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', '1');
        }
        session_start();
    }

    public static function csrfToken(): string
    {
        self::start();
        return $_SESSION['school_csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function verifyCsrf(): bool
    {
        self::start();
        $sent = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        return $sent !== '' && isset($_SESSION['school_csrf']) && hash_equals($_SESSION['school_csrf'], $sent);
    }

    public static function login(string $email, string $password): bool
    {
        self::start();
        $identityHash = hash('sha256', mb_strtolower(trim($email)));
        $ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $pdo = SchoolDatabase::connection();
        $throttle = $pdo->prepare('SELECT COUNT(*) FROM school_login_attempts WHERE (identity_hash = :identity OR ip_hash = :ip) AND succeeded = 0 AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE');
        $throttle->execute(['identity' => $identityHash, 'ip' => $ipHash]);
        if ((int) $throttle->fetchColumn() >= 8) return false;
        $user = (new UserRepository())->findActiveByLogin($email);
        $valid = $user && password_verify($password, $user['password_hash']);
        $attempt = $pdo->prepare('INSERT INTO school_login_attempts (identity_hash, ip_hash, succeeded, attempted_at) VALUES (:identity, :ip, :success, UTC_TIMESTAMP())');
        $attempt->execute(['identity' => $identityHash, 'ip' => $ipHash, 'success' => $valid ? 1 : 0]);
        if (!$valid) return false;
        session_regenerate_id(true);
        $_SESSION['school_user_id'] = (int) $user['id'];
        $_SESSION['school_name'] = $user['full_name'];
        $_SESSION['school_demo'] = (bool) $user['demo_account'];
        $_SESSION['school_login_at'] = time();
        $update = $pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id');
        $update->execute(['id' => (int) $user['id']]);
        (new AuditService())->record((int) $user['id'], 'auth.login', 'users', (int) $user['id']);
        return true;
    }

    public static function userId(): ?int
    {
        self::start();
        if (empty($_SESSION['school_user_id'])) return null;
        if (time() - (int) ($_SESSION['school_login_at'] ?? time()) > 28800) {
            self::logout();
            return null;
        }
        $_SESSION['school_login_at'] = time();
        return (int) $_SESSION['school_user_id'];
    }

    public static function user(): ?array
    {
        $id = self::userId();
        return $id ? (new UserRepository())->findWithRoles($id) : null;
    }

    public static function hasPermission(string $permission): bool
    {
        $user = self::user();
        return $user ? (new \School\Policies\AuthorizationPolicy())->hasPermission($user, $permission) : false;
    }

    public static function can(string $permission, array $context = []): bool
    {
        $user = self::user();
        if (!$user) return false;
        return (new \School\Policies\AuthorizationPolicy())->allows($user, $permission, $context);
    }

    public static function requireLogin(): void
    {
        if (!self::userId()) {
            header('Location: ' . (defined('SCHOOL_BASE_URL') ? SCHOOL_BASE_URL : '/school/') . 'login.php');
            exit;
        }
    }

    public static function logout(): void
    {
        self::start();
        $id = self::userIdRaw();
        if ($id) {
            try { (new AuditService())->record($id, 'auth.logout', 'users', $id); } catch (\Throwable $exception) { error_log('School logout audit failed: ' . $exception->getMessage()); }
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    private static function userIdRaw(): ?int
    {
        return isset($_SESSION['school_user_id']) ? (int) $_SESSION['school_user_id'] : null;
    }
}
