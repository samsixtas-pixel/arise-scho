<?php
declare(strict_types=1);

namespace School\Core;

final class SchoolConfig
{
    private static ?array $local = null;

    public static function env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') return $value;
        if (self::$local === null) {
            $path = self::root() . '/config/school.local.php';
            self::$local = is_file($path) ? (require $path) : [];
        }
        $localValue = self::$local[$key] ?? null;
        return is_string($localValue) && $localValue !== '' ? $localValue : $default;
    }

    public static function environment(): string
    {
        return self::env('SCHOOL_APP_ENV', 'production') ?? 'production';
    }

    public static function isDemoAllowed(): bool
    {
        return in_array(self::environment(), ['local', 'testing'], true);
    }

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
