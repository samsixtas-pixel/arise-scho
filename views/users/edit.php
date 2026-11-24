<?php
/**
 * views/users/edit.php — edit a staff account (administrator only).
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireAdmin();

$userModel = new User();
$id        = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$existing  = $id > 0 ? $userModel->find($id) : false;

if (!$existing) {
    flash('error', t('User not found.'));
    redirect(BASE_URL . 'views/users/index.php');
}

$errors = [];
$isEdit = true;

$user = [
    'full_name'          => $existing['full_name'],
    'username'           => $existing['username'],
    'role'               => $existing['role'],
    'status'             => $existing['status'],
    'phone'              => $existing['phone'] ?? '',
    'officer_code'       => $existing['officer_code'] ?? '',
    'preferred_language' => $existing['preferred_language'] ?? 'en',
];

if (isPost()) {
    $user = [
        'full_name'          => trim((string) ($_POST['full_name'] ?? '')),
        'username'           => strtolower(trim((string) ($_POST['username'] ?? ''))),
        'role'               => (string) ($_POST['role'] ?? 'officer'),
        'status'             => (string) ($_POST['status'] ?? 'active'),
        'phone'              => trim((string) ($_POST['phone'] ?? '')),
        'officer_code'       => trim((string) ($_POST['officer_code'] ?? '')),
        'preferred_language' => (string) ($_POST['preferred_language'] ?? 'en'),
    ];

    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if ($user['full_name'] === '') {
        $errors[] = t('Full name is required.');
    }

    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $user['username'])) {
        $errors[] = t('Use a username of 3–60 letters, numbers, dots, dashes or underscores.');
    } elseif ($userModel->usernameExists($user['username'], $id)) {
        $errors[] = t('That username is already taken.');
    }

    if (!in_array($user['role'], ['admin', 'officer'], true)) {
        $errors[] = t('Select a valid role.');
    }

    if (!in_array($user['status'], ['active', 'inactive', 'suspended'], true)) {
        $errors[] = t('Select a valid status.');
    }

    if ($user['phone'] !== '' && !isValidTzPhone($user['phone'])) {
        $errors[] = t('Enter a valid Tanzanian phone number, e.g. 0744 909 605.');
    }

    if ($user['officer_code'] !== '' && $user['officer_code'] !== ($existing['officer_code'] ?? '')) {
        $exists = Database::getConnection()->prepare(
            "SELECT COUNT(*) FROM users WHERE officer_code = :c AND id != :id"
        );
        $exists->execute(['c' => $user['officer_code'], 'id' => $id]);

        if ((int) $exists->fetchColumn() > 0) {
            $errors[] = t('That officer code is already in use.');
        }
    }

    if ($id === currentUserId()) {
        if ($user['role'] !== 'admin') {
            $errors[] = t('You cannot remove your own administrator role.');
            $user['role'] = 'admin';
        }

        if ($user['status'] !== 'active') {
            $errors[] = t('You cannot deactivate your own account.');
            $user['status'] = 'active';
        }
    }

    if ($user['role'] !== 'admin' && $existing['role'] === 'admin') {
        $remaining = (int) Database::getConnection()->query(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id != {$id}"
        )->fetchColumn();

        if ($remaining === 0) {
            $errors[] = t('At least one active administrator must remain.');
        }
    }

    if ($password !== '' || $confirm !== '') {
        if (mb_strlen($password) < 8) {
            $errors[] = t('The password must be at least 8 characters.');
        }

        if ($password !== $confirm) {
            $errors[] = t('The passwords do not match.');
        }
    }

    if (!$errors) {
        try {
            $userModel->updateUser($id, [
                'full_name'          => $user['full_name'],
                'username'           => $user['username'],
                'role'               => $user['role'],
                'status'             => $user['status'],
                'phone'              => $user['phone'] !== '' ? normalisePhone($user['phone']) : null,
                'officer_code'       => $user['officer_code'] !== '' ? $user['officer_code'] : null,
                'preferred_language' => $user['preferred_language'],
            ], $password !== '' ? $password : null);

            if ($id === currentUserId()) {
                $_SESSION['full_name']          = $user['full_name'];
                $_SESSION['username']           = $user['username'];
                $_SESSION['language']           = $user['preferred_language'];
            }

            flash('success', t('User account updated.'));
            redirect(BASE_URL . 'views/users/index.php');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = t('Edit user');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Edit user') ?></h4>
    <p><?= e($existing['full_name']) ?> · @<?= e($existing['username']) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/users/index.php">
      <i class="bi bi-arrow-left me-1"></i><?= t('Back to users') ?>
    </a>
  </div>
</div>

<?php require __DIR__ . '/_form.php'; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
