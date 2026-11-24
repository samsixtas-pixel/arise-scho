<?php
/**
 * User.php
 * -------------------------------------------------------
 * Accounts, authentication and role checks.
 * Roles: admin (full control) and officer (daily lending).
 * -------------------------------------------------------
 */

class User extends Model
{
    protected function getTable(): string
    {
        return 'users';
    }

    public function login(string $username, string $password): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM users
              WHERE username = :username
                AND status = 'active'
              LIMIT 1"
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            return $user;
        }

        return false;
    }

    public function createSession(array $user): void
    {
        $_SESSION['user_id']            = (int) $user['id'];
        $_SESSION['full_name']          = $user['full_name'];
        $_SESSION['username']           = $user['username'];
        $_SESSION['role']               = $user['role'];
        $_SESSION['preferred_language'] = $user['preferred_language'] ?? 'en';
        $_SESSION['lang']               = $user['preferred_language'] ?? 'en';
        $_SESSION['logged_in']          = true;
        $_SESSION['last_activity']      = time();
        $_SESSION['fingerprint']        = Security::sessionFingerprint();

        AuditLog::record('auth.login', 'users', (int) $user['id'],
            'Login as ' . $user['username'] . ' (' . $user['role'] . ')');
    }

    public function destroySession(): void
    {
        AuditLog::record('auth.logout', 'users', currentUserId(), 'User logged out');

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain']);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function touchLastLogin(int $userId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE users
                SET last_login_at = NOW(),
                    first_login_at = COALESCE(first_login_at, NOW())
              WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);
    }

    public function saveLanguage(int $userId, string $lang): void
    {
        $lang = in_array($lang, ['en', 'sw'], true) ? $lang : 'en';
        $stmt = $this->db->prepare("UPDATE users SET preferred_language = :l WHERE id = :id");
        $stmt->execute(['l' => $lang, 'id' => $userId]);
    }

    public function addUser(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO users (full_name, username, password, role, status, phone, officer_code, preferred_language)
             VALUES (:full_name, :username, :password, :role, :status, :phone, :officer_code, :lang)"
        );

        $stmt->execute([
            'full_name'    => $data['full_name'],
            'username'     => strtolower(trim($data['username'])),
            'password'     => password_hash($data['password'], PASSWORD_DEFAULT),
            'role'         => $data['role'] ?? 'officer',
            'status'       => $data['status'] ?? 'active',
            'phone'        => $data['phone'] ?? null,
            'officer_code' => $data['officer_code'] ?? null,
            'lang'         => $data['preferred_language'] ?? 'en',
        ]);

        $id = (int) $this->lastInsertId();

        AuditLog::record('user.create', 'users', $id,
            'Created user ' . $data['username'] . ' (' . ($data['role'] ?? 'officer') . ')');

        return $id;
    }

    public function updateUser(int $id, array $data, ?string $password = null): bool
    {
        $sql = "UPDATE users
                   SET full_name = :full_name,
                       username  = :username,
                       role      = :role,
                       status    = :status,
                       phone     = :phone,
                       officer_code = :officer_code,
                       preferred_language = :lang";

        $params = [
            'full_name'    => $data['full_name'],
            'username'     => strtolower(trim($data['username'])),
            'role'         => $data['role'],
            'status'       => $data['status'],
            'phone'        => $data['phone'] ?? null,
            'officer_code' => $data['officer_code'] ?? null,
            'lang'         => $data['preferred_language'] ?? 'en',
            'id'           => $id,
        ];

        if ($password !== null && $password !== '') {
            $sql .= ", password = :password";
            $params['password'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $sql .= " WHERE id = :id";

        $ok = $this->db->prepare($sql)->execute($params);

        AuditLog::record('user.update', 'users', $id, 'Updated user ' . $data['username']);

        return $ok;
    }

    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $sql    = "SELECT COUNT(*) FROM users WHERE username = :username";
        $params = ['username' => strtolower(trim($username))];

        if ($excludeId !== null) {
            $sql .= " AND id != :id";
            $params['id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** Officers visible to assignment dropdowns. */
    public function officers(): array
    {
        return $this->db->query(
            "SELECT id, full_name, username, role, status, officer_code
               FROM users
              WHERE status = 'active'
              ORDER BY role = 'admin' DESC, full_name"
        )->fetchAll();
    }

    /** ---------- session helpers ---------- */

    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }

    public static function isAdmin(): bool
    {
        return self::isLoggedIn() && ($_SESSION['role'] ?? '') === 'admin';
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            $next = $_SERVER['REQUEST_URI'] ?? '';
            $qs   = $next !== '' ? '?next=' . urlencode($next) : '';
            redirect(BASE_URL . 'login.php' . $qs);
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();

        if (!self::isAdmin()) {
            flash('error', t('Administrator access is required for that page.'));
            redirect(BASE_URL . 'dashboard.php');
        }
    }
}
