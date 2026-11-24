<?php
/**
 * security.php — Loan Manager
 * -------------------------------------------------------
 * Central security layer:
 *   - CSRF protection (forms + JSON headers)
 *   - Session hardening (fingerprint, idle timeout)
 *   - Login rate limiting
 *   - Security headers (CSP, framing, permissions)
 *   - Suspicious activity logging
 *   - Idempotent GET/POST helpers
 * -------------------------------------------------------
 */

class Security
{
    // ============================================================
    // 1. CSRF
    // ============================================================

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="'
             . htmlspecialchars(self::csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function verifyCsrf(?string $submitted = null): bool
    {
        $submitted = $submitted
            ?? ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if (empty($_SESSION['csrf_token']) || empty($submitted)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], (string) $submitted);
    }

    public static function verifyCsrfOrFail(): void
    {
        if (!self::verifyCsrf()) {
            self::logSuspicious('csrf.fail',
                'CSRF token invalid for ' . ($_SERVER['REQUEST_URI'] ?? '?'));

            $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
                      || str_starts_with($_SERVER['HTTP_CONTENT_TYPE'] ?? '', 'application/json');

            http_response_code(403);
            if ($wantsJson) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Invalid security token. Refresh and retry.']);
            } else {
                echo 'Invalid security token. Refresh the page and try again.';
            }
            exit;
        }
    }

    // ============================================================
    // 2. SESSION
    // ============================================================

    public static function regenerateSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['last_regen']   = time();
            $_SESSION['fingerprint']  = self::sessionFingerprint();
            $_SESSION['last_activity'] = time();
        }
    }

    public static function sessionFingerprint(): string
    {
        $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $secret = defined('SESSION_FINGERPRINT_SECRET') ? SESSION_FINGERPRINT_SECRET : 'lms-default-secret';
        return hash('sha256', $ua . '|' . $secret);
    }

    public static function checkSessionFingerprint(): bool
    {
        $current = self::sessionFingerprint();

        if (empty($_SESSION['fingerprint'])) {
            $_SESSION['fingerprint'] = $current;
            return true;
        }

        return hash_equals($_SESSION['fingerprint'], $current);
    }

    public static function sessionTimeout(int $maxSeconds = 28800): void
    {
        if (empty($_SESSION['logged_in'])) {
            return;
        }

        if (!self::checkSessionFingerprint()) {
            self::logSuspicious('session.fingerprint_mismatch',
                'Session fingerprint mismatch for user #' . ($_SESSION['user_id'] ?? '?'));
            self::destroyAndRedirect('Session invalidated. Please log in again.');
        }

        $last = $_SESSION['last_activity'] ?? time();
        if (time() - $last > $maxSeconds) {
            self::logSuspicious('session.timeout',
                'Session timed out for user #' . ($_SESSION['user_id'] ?? '?'));
            self::destroyAndRedirect('Session expired. Please log in again.');
        }

        $_SESSION['last_activity'] = time();
    }

    private static function destroyAndRedirect(string $message): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/') . 'login.php?error='
            . urlencode($message));
        exit;
    }

    // ============================================================
    // 3. HEADERS
    // ============================================================

    public static function setHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header("Content-Security-Policy: "
             . "default-src 'self'; "
             . "script-src 'self' 'unsafe-inline'; "
             . "style-src 'self' 'unsafe-inline'; "
             . "img-src 'self' data: blob:; "
             . "media-src 'self' blob:; "
             . "font-src 'self' data:; "
             . "connect-src 'self'; "
             . "frame-ancestors 'none'; "
             . "base-uri 'self'; "
             . "form-action 'self';");

        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // Camera capture is a core feature (customer passport photo).
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
        header('X-XSS-Protection: 1; mode=block');

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    // ============================================================
    // 4. LOGIN RATE LIMITING
    // ============================================================

    public static function checkLoginRateLimit(string $username, string $ip): array
    {
        $maxAttempts = 5;
        $windowSecs  = 900;

        try {
            $db = Database::getConnection();

            $db->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)")
               ->execute();

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM login_attempts
                 WHERE (username = :u OR ip_address = :ip)
                   AND success = 0
                   AND attempted_at >= DATE_SUB(NOW(), INTERVAL :w SECOND)"
            );
            $stmt->bindValue(':u', $username);
            $stmt->bindValue(':ip', $ip);
            $stmt->bindValue(':w', $windowSecs, PDO::PARAM_INT);
            $stmt->execute();
            $count = (int) $stmt->fetchColumn();

            if ($count >= $maxAttempts) {
                $stmt = $db->prepare(
                    "SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(attempted_at, INTERVAL :w SECOND)) AS wait_secs
                     FROM login_attempts
                     WHERE (username = :u OR ip_address = :ip) AND success = 0
                     ORDER BY attempted_at ASC LIMIT 1"
                );
                $stmt->bindValue(':u', $username);
                $stmt->bindValue(':ip', $ip);
                $stmt->bindValue(':w', $windowSecs, PDO::PARAM_INT);
                $stmt->execute();
                $retryAfter = max(1, (int) $stmt->fetchColumn());

                return ['allowed' => false, 'retry_after' => $retryAfter, 'attempts' => $count];
            }

            return ['allowed' => true, 'retry_after' => 0, 'attempts' => $count];
        } catch (Throwable $e) {
            return ['allowed' => true, 'retry_after' => 0, 'attempts' => 0];
        }
    }

    public static function recordLoginAttempt(string $username, string $ip, bool $success): void
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare(
                "INSERT INTO login_attempts (username, ip_address, success) VALUES (:u, :ip, :s)"
            );
            $stmt->execute(['u' => $username, 'ip' => $ip, 's' => $success ? 1 : 0]);

            if ($success) {
                $db->prepare("DELETE FROM login_attempts WHERE username = :u")->execute(['u' => $username]);
            }
        } catch (Throwable $e) {
        }
    }

    // ============================================================
    // 5. LOGGING
    // ============================================================

    public static function logSuspicious(string $action, string $details): void
    {
        try {
            AuditLog::record($action, null, null, $details);
        } catch (Throwable $e) {
        }
    }

    // ============================================================
    // 6. ACCESS CONTROL
    // ============================================================

    public static function requireOwnershipOrAdmin(int $ownerId): void
    {
        $currentUid = (int) ($_SESSION['user_id'] ?? 0);

        if (!User::isAdmin() && $currentUid !== $ownerId) {
            self::logSuspicious('idor.attempt',
                'User #' . $currentUid . ' tried to access resource of user #' . $ownerId);
            http_response_code(403);
            die('Access denied.');
        }
    }

    // ============================================================
    // 7. INPUT HELPERS
    // ============================================================

    public static function safeInt($v, int $default = 0): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function safeFloat($v, float $default = 0.0): float
    {
        return is_numeric($v) ? (float) $v : $default;
    }

    public static function safeDate($v, ?string $default = null): string
    {
        if (!is_string($v) || trim($v) === '') {
            return $default ?? date('Y-m-d');
        }

        // Accept DD/MM/YYYY and ISO YYYY-MM-DD.
        $ts = false;
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($v), $m)) {
            $ts = strtotime("{$m[3]}-{$m[2]}-{$m[1]}");
        } else {
            $ts = strtotime($v);
        }

        if ($ts === false) {
            return $default ?? date('Y-m-d');
        }
        if ($ts < strtotime('-10 years') || $ts > strtotime('+10 years')) {
            return $default ?? date('Y-m-d');
        }

        return date('Y-m-d', $ts);
    }

    // ============================================================
    // 8. BOOT
    // ============================================================

    public static function boot(): void
    {
        self::setHeaders();

        if (session_status() === PHP_SESSION_ACTIVE) {
            self::csrfToken();
        }

        self::sessionTimeout();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $currentFile = basename($_SERVER['PHP_SELF'] ?? '');
            $exempt = ['login.php', 'logout.php', 'capture.php'];
            if (!in_array($currentFile, $exempt, true)) {
                self::verifyCsrfOrFail();
            }
        }
    }
}

Security::boot();
