<?php
/**
 * Database.php
 * -------------------------------------------------------
 * PDO connection singleton.
 *
 * Credentials can be overridden with environment variables
 * (DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_SOCKET) so the same
 * code runs on XAMPP locally and on a shared host.
 * -------------------------------------------------------
 */

class Database
{
    private static ?PDO $instance = null;

    private function __construct()
    {
    }

    public static function getConnection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host   = getenv('DB_HOST') ?: 'localhost';
        $dbName = getenv('DB_NAME') ?: 'loan_ims';
        $user   = getenv('DB_USER') ?: 'root';
        $pass   = getenv('DB_PASS');
        $pass   = $pass === false ? '' : $pass;
        $socket = getenv('DB_SOCKET') ?: '/opt/lampp/var/mysql/mysql.sock';

        // is_file() returns false for unix sockets (they are not regular
        // files), so use file_exists() to detect the socket path.
        $dsn = ($socket !== '' && file_exists($socket))
            ? "mysql:unix_socket={$socket};dbname={$dbName};charset=utf8mb4"
            : "mysql:host={$host};dbname={$dbName};charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        try {
            self::$instance = new PDO($dsn, $user, $pass, $options);
            self::$instance->exec("SET time_zone = '+03:00'");
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());

            if (defined('APP_ENV') && APP_ENV === 'development') {
                http_response_code(500);
                die('Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            }

            http_response_code(500);
            die('Service temporarily unavailable. Please contact the administrator.');
        }

        return self::$instance;
    }

    /** Test whether a connection can be established (installer only). */
    public static function canConnect(): bool
    {
        try {
            self::getConnection();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function __clone()
    {
    }

    public function __wakeup()
    {
        throw new RuntimeException('Database cannot be unserialised.');
    }
}
