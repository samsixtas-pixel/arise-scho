<?php
/**
 * tools/install.php — idempotent loan-schema installer (CLI).
 *
 *   /opt/lampp/bin/php tools/install.php
 *
 * Creates missing tables, refreshes the reporting views, and seeds any
 * missing default settings and loan product without replacing user data.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This installer can only be run from the command line.');
}

require_once __DIR__ . '/../includes/autoload.php';

function out(string $message): void
{
    echo $message . PHP_EOL;
}

/** Split a SQL file into statements, respecting quotes and comments. */
function splitSql(string $sql): array
{
    $statements = [];
    $buffer     = '';
    $inString   = false;
    $stringChar = '';
    $length     = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        // Strip -- comments (not inside strings)
        if (!$inString && $char === '-' && ($sql[$i + 1] ?? '') === '-') {
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            $buffer .= "\n";
            continue;
        }

        if ($inString) {
            $buffer .= $char;
            if ($char === '\\') {
                $buffer .= $sql[$i + 1] ?? '';
                $i++;
            } elseif ($char === $stringChar) {
                $inString = false;
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $inString   = true;
            $stringChar = $char;
            $buffer    .= $char;
            continue;
        }

        if ($char === ';') {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

out('──────────────────────────────────────────────');
out(' Weekly Loan Management System — installer');
out('──────────────────────────────────────────────');

$db = Database::getConnection();
out('✔ Connected to database: ' . $db->query('SELECT DATABASE()')->fetchColumn());

$schemaFile = APP_ROOT . '/database/schema.sql';

if (!is_file($schemaFile)) {
    die('✖ database/schema.sql is missing.' . PHP_EOL);
}

$statements = splitSql(file_get_contents($schemaFile));

out('… Applying ' . count($statements) . ' schema statements');

foreach ($statements as $index => $statement) {
    try {
        $db->exec($statement);
    } catch (Throwable $e) {
        out('✖ Statement #' . ($index + 1) . ' failed: ' . $e->getMessage());
        out('  ' . substr(preg_replace('/\s+/', ' ', $statement), 0, 160));
        exit(1);
    }
}

out('✔ Loan schema applied; existing tables and records were preserved');

// ---------------------------------------------------------------- upgrades
// CREATE TABLE IF NOT EXISTS never adds columns to an existing table, so
// installs created before the identity-capture release get their missing
// columns here. This keeps tools/install.php idempotent.
$upgrades = [
    'customers' => [
        'gender' => "ENUM('male','female','other') DEFAULT NULL AFTER `last_name`",
    ],
    'customer_documents' => [
        'document_uuid'     => 'CHAR(36) DEFAULT NULL AFTER `id`',
        'document_category' => "VARCHAR(40) NOT NULL DEFAULT 'supporting' AFTER `doc_type`",
        'storage_key'       => 'VARCHAR(255) DEFAULT NULL AFTER `path`',
        'thumbnail_key'     => 'VARCHAR(255) DEFAULT NULL AFTER `storage_key`',
        'mime_type'         => 'VARCHAR(100) DEFAULT NULL AFTER `thumbnail_key`',
        'file_size_bytes'   => 'BIGINT UNSIGNED DEFAULT NULL AFTER `mime_type`',
        'width'             => 'INT UNSIGNED DEFAULT NULL AFTER `file_size_bytes`',
        'height'            => 'INT UNSIGNED DEFAULT NULL AFTER `width`',
        'checksum'          => 'CHAR(64) DEFAULT NULL AFTER `height`',
        'original_filename' => 'VARCHAR(255) DEFAULT NULL AFTER `checksum`',
        'document_status'   => "ENUM('processing','active','rejected','deleted') NOT NULL DEFAULT 'active' AFTER `original_filename`",
        'version_number'    => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER `document_status`',
        'is_primary'        => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `version_number`',
        'issue_date'        => 'DATE DEFAULT NULL AFTER `is_primary`',
        'expiry_date'       => 'DATE DEFAULT NULL AFTER `issue_date`',
        'ocr_status'        => "ENUM('none','pending','done','failed','unavailable') NOT NULL DEFAULT 'none' AFTER `expiry_date`",
        'updated_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `uploaded_at`',
        'deleted_at'        => 'DATETIME DEFAULT NULL AFTER `updated_at`',
    ],
];

$columnExists = static function (string $table, string $column) use ($db): bool {
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    return (bool) $stmt->fetchColumn();
};

$added = 0;

foreach ($upgrades as $table => $columns) {
    foreach ($columns as $column => $definition) {
        if ($columnExists($table, $column)) {
            continue;
        }

        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        $added++;
    }
}

if ($added > 0) {
    out('✔ Added ' . $added . ' missing column(s) for identity capture');
}

// Indexes that only exist on installs created after the identity release.
$indexes = [
    'customer_documents' => [
        'uq_document_uuid' => 'UNIQUE KEY `uq_document_uuid` (`document_uuid`)',
        'idx_docs_category' => 'KEY `idx_docs_category` (`customer_id`,`document_category`)',
        'idx_docs_status'   => 'KEY `idx_docs_status` (`document_status`)',
        'idx_docs_expiry'   => 'KEY `idx_docs_expiry` (`expiry_date`)',
    ],
];

$indexExists = static function (string $table, string $index) use ($db): bool {
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i"
    );
    $stmt->execute(['t' => $table, 'i' => $index]);
    return (bool) $stmt->fetchColumn();
};

$addedIndexes = 0;

foreach ($indexes as $table => $definitions) {
    foreach ($definitions as $name => $definition) {
        if ($indexExists($table, $name)) {
            continue;
        }

        $db->exec("ALTER TABLE `{$table}` ADD {$definition}");
        $addedIndexes++;
    }
}

if ($addedIndexes > 0) {
    out('✔ Added ' . $addedIndexes . ' missing index(es) for identity capture');
}

// ---------------------------------------------------------------- backfill
// Legacy rows only have doc_type; classify them into the document-category
// taxonomy so the Documents tab shows the right label and icon.
$legacyUpdate = $db->prepare(
    "UPDATE customer_documents
        SET document_category = CASE WHEN doc_type = 'photo' THEN 'portrait' ELSE doc_type END
      WHERE (storage_key IS NULL OR storage_key = '')
        AND document_status <> 'deleted'
        AND doc_type IN ('photo','portrait','passport','nida','other_id',
                         'loan_application','guarantor','agreement','supporting','other')
        AND document_category <> CASE WHEN doc_type = 'photo' THEN 'portrait' ELSE doc_type END"
);
$legacyUpdate->execute();

if ($legacyUpdate->rowCount() > 0) {
    out('✔ Classified ' . $legacyUpdate->rowCount() . ' legacy document(s)');
}

// ---------------------------------------------------------------- settings
$settings = [
    ['business_name',        '',            'Shown on statements and reports'],
    ['business_phone',       '',            'Contact number printed on statements'],
    ['default_weeks',        '12',          'Suggested default when creating a loan'],
    ['default_weekly_amount','20000',       'Suggested default weekly repayment (TZS)'],
    ['currency',             'TZS',         'Currency label used everywhere'],
    ['date_format',          'DD/MM/YYYY',  'Display format used across the app'],
    ['overdue_grace_days',   '0',           'Days after the due date before a loan is OVERDUE'],
    ['default_language',     'en',          'Default interface language (en / sw)'],
    ['receipt_prefix',       'PMT',         'Payment reference prefix'],
    ['loan_prefix',          'LN',          'Loan reference prefix'],
    ['customer_prefix',      'CUST',        'Customer reference prefix'],
];

$stmt = $db->prepare(
    "INSERT INTO app_settings (setting_key, setting_value, notes, updated_by)
     VALUES (:k, :v, :n, :u)
     ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key)"
);

foreach ($settings as [$key, $value, $notes]) {
    $stmt->execute(['k' => $key, 'v' => $value, 'n' => $notes, 'u' => null]);
}

out('✔ ' . count($settings) . ' settings seeded');

// ---------------------------------------------------------------- product
$product = (int) $db->query("SELECT COUNT(*) FROM loan_products")->fetchColumn();

if ($product === 0) {
    $db->prepare(
        "INSERT INTO loan_products (name, default_weeks, default_weekly_amount, interest_type, interest_rate, grace_days, status)
         VALUES ('Weekly Flat Loan', 12, 20000.00, 'flat', 0.0000, 0, 'active')"
    )->execute();
    out('✔ Default loan product created');
}

out('');
out('Installation complete.');
out('  1. Open ' . (defined('BASE_URL') ? BASE_URL : '/loan/') . 'login.php');
out('  2. Create the first administrator in the users table with a password hash.');
out('  3. Run: /opt/lampp/bin/php tools/import_legacy.php (only if importing workbook data)');
out('');
