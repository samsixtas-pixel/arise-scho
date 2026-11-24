<?php
declare(strict_types=1);

namespace School\Repositories;

use School\Core\SchoolDatabase;

final class UserRepository
{
    public function findActiveByLogin(string $login): ?array
    {
        $query = SchoolDatabase::connection()->prepare("SELECT id, full_name, username, email, password_hash, demo_account FROM users WHERE (email = :email_login OR username = :username_login) AND status = 'active' LIMIT 1");
        $normalized = mb_strtolower(trim($login));
        $query->execute(['email_login' => $normalized, 'username_login' => $normalized]);
        return $query->fetch() ?: null;
    }

    public function findWithRoles(int $id): ?array
    {
        $pdo = SchoolDatabase::connection();
        $query = $pdo->prepare("SELECT id, full_name, username, email, demo_account, must_change_password FROM users WHERE id = :id AND status = 'active'");
        $query->execute(['id' => $id]);
        $user = $query->fetch();
        if (!$user) return null;
        $query = $pdo->prepare('SELECT r.name, r.label FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :id');
        $query->execute(['id' => $id]);
        $user['roles'] = $query->fetchAll();
        return $user;
    }
}
