<?php
declare(strict_types=1);

namespace School\Core;

use PDO;
use PDOException;
use RuntimeException;

final class SchoolDatabase
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $name = SchoolConfig::env('SCHOOL_DB_NAME');
        $user = SchoolConfig::env('SCHOOL_DB_USER');
        if (!$name || !$user) {
            throw new RuntimeException('School database is not configured. Set SCHOOL_DB_NAME and SCHOOL_DB_USER.');
        }
        $host = SchoolConfig::env('SCHOOL_DB_HOST', '127.0.0.1');
        $socket = SchoolConfig::env('SCHOOL_DB_SOCKET');
        // An explicit host override opts into TCP even when the local config also
        // defines a Unix socket (useful when the app and database run in separate namespaces).
        if (getenv('SCHOOL_DB_HOST') !== false && getenv('SCHOOL_DB_SOCKET') === false) $socket = null;
        $dsn = $socket
            ? "mysql:unix_socket={$socket};dbname={$name};charset=utf8mb4"
            : "mysql:host={$host};dbname={$name};charset=utf8mb4";

        try {
            self::$connection = new PDO($dsn, $user, SchoolConfig::env('SCHOOL_DB_PASS', '') ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$connection->exec("SET time_zone = '+00:00'");
        } catch (PDOException $exception) {
            error_log('School database connection failed: ' . $exception->getMessage());
            throw new RuntimeException('School service is temporarily unavailable.', 0, $exception);
        }

        return self::$connection;
    }

    public static function transaction(callable $operation): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $operation($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
