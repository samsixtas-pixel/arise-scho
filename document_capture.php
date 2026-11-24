<?php
/**
 * document_capture.php — save a camera-captured identity document.
 * -------------------------------------------------------
 * JSON endpoint used by the customer profile "capture document"
 * panel (direct camera, phone QR pairing, or edited upload).
 *
 * Authentication + CSRF are enforced globally (security.php) for
 * this file; the image arrives as a re-encoded JPEG data URL and
 * is written to private storage by CustomerDocument::storeCaptured().
 * -------------------------------------------------------
 */

require_once __DIR__ . '/includes/autoload.php';
User::requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $category   = (string) ($_POST['category'] ?? 'supporting');
    $image      = (string) ($_POST['image'] ?? '');
    $issueDate  = (string) ($_POST['issue_date'] ?? '');
    $expiryDate = (string) ($_POST['expiry_date'] ?? '');

    if ($customerId <= 0) {
        $respond(['ok' => false, 'error' => t('Customer not found.')], 422);
    }

    if (!array_key_exists($category, CustomerDocument::CATEGORIES)) {
        $respond(['ok' => false, 'error' => t('Unsupported action.')], 422);
    }

    if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#i', $image)) {
        $respond(['ok' => false, 'error' => t('No image was received.')], 422);
    }

    $issue  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate) ? $issueDate : null;
    $expiry = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate) ? $expiryDate : null;

    if ($issue && $expiry && strtotime($expiry) < strtotime($issue)) {
        $respond(['ok' => false, 'error' => t('The expiry date cannot be before the issue date.')], 422);
    }

    $documents = new CustomerDocument();
    $documentId = $documents->storeCaptured($customerId, $image, $category, $issue, $expiry);

    $respond([
        'ok'       => true,
        'id'       => $documentId,
        'url'      => CustomerDocument::url($documentId, 'full'),
        'thumb'    => CustomerDocument::url($documentId, 'thumb'),
        'category' => $category,
        'message'  => t('Document added to the customer file.'),
    ]);
} catch (ValidationException $e) {
    $respond(['ok' => false, 'error' => implode(' ', $e->errors())], 422);
} catch (Throwable $e) {
    error_log('Document capture endpoint: ' . $e->getMessage());
    $respond(['ok' => false, 'error' => t('The image could not be processed. Please try again.')], 500);
}
