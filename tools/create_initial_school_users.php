<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app/autoload.php';

$password = getenv('SCHOOL_INITIAL_USER_PASSWORD');
if ($password === false || $password === '') {
    fwrite(STDERR, "Set SCHOOL_INITIAL_USER_PASSWORD for this one-time account creation.\n");
    exit(2);
}
$accounts = [
    ['mmari', null, 'Teacher', 'subject_teacher'],
    ['shock', null, 'Manager', 'school_manager'],
    ['tutu', null, 'Accountant', 'accountant'],
    ['dejene', null, 'School Owner', 'school_owner'],
];
$pdo = School\Core\SchoolDatabase::connection();
$pdo->beginTransaction();
try {
    $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
    foreach ($accounts as [$username]) {
        $check->execute(['username' => $username]);
        if ((int) $check->fetchColumn() !== 0) throw new RuntimeException("Refusing to overwrite existing account {$username}.");
    }
    $roleLookup = $pdo->prepare('SELECT id FROM roles WHERE name = :name');
    $insert = $pdo->prepare("INSERT INTO users (full_name, username, email, password_hash, status, demo_account, created_at, updated_at) VALUES (:name, :username, :email, :hash, 'active', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $link = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)');
    $audit = $pdo->prepare("INSERT INTO audit_logs (actor_user_id, action, subject_type, subject_id, metadata_json, created_at) VALUES (NULL, 'user.initial_create', 'users', :id, :metadata, UTC_TIMESTAMP())");
    foreach ($accounts as [$username, $email, $displayRole, $roleName]) {
        $roleLookup->execute(['name' => $roleName]);
        $roleId = (int) $roleLookup->fetchColumn();
        if (!$roleId) throw new RuntimeException("Role {$roleName} is missing. Apply migrations first.");
        $insert->execute(['name' => ucfirst($username), 'username' => $username, 'email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $pdo->lastInsertId();
        $link->execute(['user' => $userId, 'role' => $roleId]);
        $audit->execute(['id' => $userId, 'metadata' => json_encode(['username' => $username, 'assigned_role' => $roleName], JSON_THROW_ON_ERROR)]);
    }
    $pdo->commit();
    foreach ($accounts as [$username, , $role]) echo "Created {$username} — {$role}\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
