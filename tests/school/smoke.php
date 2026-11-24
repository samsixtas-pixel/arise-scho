<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/autoload.php';

use School\Core\SchoolConfig;
use School\Services\DemoUserSeeder;

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};

putenv('SCHOOL_APP_ENV=production');
$expect(!SchoolConfig::isDemoAllowed(), 'Production must not permit demo seeding.');
try {
    (new DemoUserSeeder())->seed();
    throw new RuntimeException('Demo seeder unexpectedly ran in production.');
} catch (RuntimeException $exception) {
    $expect(str_contains($exception->getMessage(), 'disabled'), 'Production guard should reject before database access.');
}
putenv('SCHOOL_APP_ENV=local');
$expect(SchoolConfig::isDemoAllowed(), 'Local environment should allow demo seeding.');
putenv('SCHOOL_APP_ENV=testing');
$expect(SchoolConfig::isDemoAllowed(), 'Testing environment should allow demo seeding.');
putenv('SCHOOL_APP_ENV');

$passwordHash = password_hash('SchoolDemo@2026!', PASSWORD_DEFAULT);
$expect(password_verify('SchoolDemo@2026!', $passwordHash), 'Demo password should verify against a secure hash.');
$expect(!password_verify('incorrect', $passwordHash), 'Incorrect passwords must not verify.');

echo "School smoke checks passed: {$checks}\n";
