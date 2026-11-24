<?php
declare(strict_types=1);

namespace School\Core;

final class Request
{
    public function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }
    public function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return '/' . trim($path, '/');
    }
    public function query(string $key, mixed $default = null): mixed { return $_GET[$key] ?? $default; }
    public function input(string $key, mixed $default = null): mixed { return $_POST[$key] ?? $default; }
    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? $default;
    }
}
