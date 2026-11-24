<?php
/**
 * camera_session.php — PC-side camera pairing endpoint (JSON).
 * -------------------------------------------------------
 * Authenticated + CSRF protected. Supports:
 *   POST action=create   {purpose, draft_id}
 *   GET  action=status   &id=…
 *   POST action=complete &id=…
 *   POST action=cancel   &id=…
 *   POST action=renew    &id=…
 * -------------------------------------------------------
 */

require_once __DIR__ . '/includes/autoload.php';
User::requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$model  = new CameraSession();
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'status');

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    if ($action === 'create') {
        $purpose = (string) ($_POST['purpose'] ?? 'portrait');
        $draft   = trim((string) ($_POST['draft_id'] ?? ''));

        $created = $model->create(currentUserId(), $purpose, $draft !== '' ? $draft : null);

        $respond([
            'ok'         => true,
            'id'         => $created['id'],
            'uuid'       => $created['uuid'],
            'url'        => $created['url'],
            'purpose'    => $created['purpose'],
            'expires_at' => $created['expires_at'],
            'expires_in' => max(0, strtotime($created['expires_at']) - time()),
            'server_time'=> date('c'),
        ]);
    }

    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

    if ($id <= 0) {
        $respond(['ok' => false, 'error' => t('Capture session not found.')], 422);
    }

    if ($action === 'status') {
        $status = $model->statusFor($id, currentUserId());
        $status['ok']          = true;
        $status['expires_in']  = max(0, strtotime($status['expires_at']) - time());
        $status['server_time'] = date('c');
        $respond($status);
    }

    if ($action === 'complete') {
        $model->complete($id, currentUserId());
        $respond(['ok' => true, 'status' => 'completed']);
    }

    if ($action === 'cancel') {
        $model->cancel($id, currentUserId());
        $respond(['ok' => true, 'status' => 'cancelled']);
    }

    if ($action === 'renew') {
        $expires = $model->renew($id, currentUserId());
        $respond(['ok' => true, 'status' => 'pending', 'expires_at' => $expires]);
    }

    $respond(['ok' => false, 'error' => t('Unsupported action.')], 422);
} catch (RuntimeException | ValidationException $e) {
    $respond(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('Camera session endpoint: ' . $e->getMessage());
    $respond(['ok' => false, 'error' => t('The capture session could not be processed.')], 500);
}
