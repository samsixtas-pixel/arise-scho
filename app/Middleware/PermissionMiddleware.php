<?php
declare(strict_types=1);

namespace School\Middleware;

use School\Core\SchoolAuth;

final class PermissionMiddleware
{
    public function require(string $permission, array $context = []): void
    {
        SchoolAuth::requireLogin();
        if (!SchoolAuth::can($permission, $context)) {
            http_response_code(403);
            exit('You are not authorized to access this school record.');
        }
    }
}
