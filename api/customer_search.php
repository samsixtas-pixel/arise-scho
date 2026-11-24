<?php
/**
 * api/customer_search.php — JSON typeahead for customer pickers.
 */

require_once __DIR__ . '/../includes/autoload.php';
User::requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = trim((string) ($_GET['q'] ?? ''));

if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'customers' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $rows = (new Customer())->search($q, 15);

    $customers = array_map(static function (array $row): array {
        return [
            'id'        => (int) $row['id'],
            'reference' => (string) $row['reference'],
            'full_name' => (string) $row['full_name'],
            'phone'     => formatPhone($row['phone']),
            'complete'  => $row['data_status'] === 'complete',
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'customers' => $customers], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => t('Search failed. Please try again.')], JSON_UNESCAPED_UNICODE);
}
