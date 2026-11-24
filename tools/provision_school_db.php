<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$socket = getenv('SCHOOL_DB_SOCKET') ?: '/opt/lampp/var/mysql/mysql.sock';
$pdo = new PDO("mysql:unix_socket={$socket};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :name');
$exists->execute(['name' => 'school_management']);
if ((int) $exists->fetchColumn() !== 0) {
    fwrite(STDERR, "school_management already exists; refusing to reuse or overwrite it.\n");
    exit(3);
}

$password = bin2hex(random_bytes(32));
$quotedPassword = $pdo->quote($password);
$pdo->exec('CREATE DATABASE school_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec("CREATE USER 'school_app'@'localhost' IDENTIFIED BY {$quotedPassword}");
$pdo->exec("GRANT ALL PRIVILEGES ON school_management.* TO 'school_app'@'localhost'");
$settings = [
    'SCHOOL_DB_NAME' => 'school_management',
    'SCHOOL_DB_USER' => 'school_app',
    'SCHOOL_DB_PASS' => $password,
    'SCHOOL_DB_HOST' => 'localhost',
    'SCHOOL_DB_SOCKET' => $socket,
];
$path = dirname(__DIR__) . '/config/school.local.php';
if (file_put_contents($path, "<?php\nreturn " . var_export($settings, true) . ";\n", LOCK_EX) === false) {
    fwrite(STDERR, "Database created, but could not write the local school connection file.\n");
    exit(4);
}
chmod($path, 0600);
echo "Created a fresh school_management database and a database-scoped school_app user.\n";
