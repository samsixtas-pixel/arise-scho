<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/autoload.php';
use School\Core\SchoolConfig;
use School\Core\SchoolDatabase;
use School\Services\DemoUserSeeder;
use School\Services\DemoSchoolDataSeeder;
use School\Services\AccountProvisioner;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$command = $argv[1] ?? '';
if (!in_array($command, ['migrate','seed-demo','seed-sample-data','provision-accounts','list-accounts'], true)) {
    fwrite(STDERR, "Usage: php tools/school.php migrate|seed-demo|seed-sample-data|provision-accounts|list-accounts\n");
    exit(2);
}
try {
    if ($command === 'migrate') {
        $pdo = SchoolDatabase::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS school_schema_migrations (migration VARCHAR(190) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $files = glob(dirname(__DIR__) . '/database/school/migrations/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $name = basename($file);
            $check = $pdo->prepare('SELECT 1 FROM school_schema_migrations WHERE migration = :name');
            $check->execute(['name' => $name]);
            if ($check->fetchColumn()) { echo "Skip {$name}\n"; continue; }
            $sql = file_get_contents($file);
            if ($sql === false) throw new RuntimeException("Cannot read migration {$name}");
            $pdo->exec($sql);
            $insert = $pdo->prepare('INSERT INTO school_schema_migrations (migration) VALUES (:name)');
            $insert->execute(['name' => $name]);
            echo "Applied {$name}\n";
        }
    } elseif ($command === 'seed-demo') {
        if (!SchoolConfig::isDemoAllowed()) throw new RuntimeException('Demo seeding requires SCHOOL_APP_ENV=local or testing.');
        (new DemoUserSeeder())->seed();
        echo "Seeded default development accounts and sample data.\n";
        echo "All default demo users use SchoolDemo@2026!\n";
    } elseif ($command === 'seed-sample-data') {
        if (!SchoolConfig::isDemoAllowed()) throw new RuntimeException('Sample data seeding requires SCHOOL_APP_ENV=local or testing.');
        (new DemoSchoolDataSeeder())->seed();
        echo "Seeded clearly labeled sample school records for the existing users.\n";
    } elseif ($command === 'provision-accounts') {
        $results = (new AccountProvisioner())->provision();
        $created = count(array_filter($results, static fn(array $row): bool => $row['created']));
        echo "Provisioned " . count($results) . " accounts ({$created} created, " . (count($results) - $created) . " refreshed).\n";
        echo "Initial password for every listed account: " . AccountProvisioner::INITIAL_PASSWORD . "\n";
        foreach ($results as $row) {
            printf("  %-18s %-38s %s\n", $row['username'], $row['role_label'], $row['created'] ? 'created' : 'updated');
        }
    } else {
        $pdo = SchoolDatabase::connection();
        $rows = $pdo->query("SELECT u.username,u.full_name,GROUP_CONCAT(r.label ORDER BY r.label SEPARATOR ', ') roles
            FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id
            WHERE u.status='active' GROUP BY u.id,u.username,u.full_name ORDER BY u.username")->fetchAll();
        echo "Active accounts (" . count($rows) . ")\n";
        foreach ($rows as $row) {
            printf("  %-18s %-34s %s\n", $row['username'], $row['full_name'], $row['roles'] ?? '—');
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
