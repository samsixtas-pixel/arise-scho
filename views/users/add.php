<?php
/**
 * views/users/add.php — create a staff account (administrator only).
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireAdmin();

$userModel = new User();
$errors    = [];
$isEdit    = false;

$user = [
    'full_name'          => '',
    'username'           => '',
    'role'               => 'officer',
    'status'             => 'active',
    'phone'              => '',
    'officer_code'       => '',
    'preferred_language' => current_lang(),
];

if (isPost()) {
    $user = array_merge($user, [
        'full_name'          => trim((string) ($_POST['full_name'] ?? '')),
        'username'           => strtolower(trim((string) ($_POST['username'] ?? ''))),
        'role'               => (string) ($_POST['role'] ?? 'officer'),
        'status'             => (string) ($_POST['status'] ?? 'active'),
        'phone'              => trim((string) ($_POST['phone'] ?? '')),
        'officer_code'       => trim((string) ($_POST['officer_code'] ?? '')),
        'preferred_language' => (string) ($_POST['preferred_language'] ?? 'en'),
    ]);

    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if ($user['full_name'] === '') {
        $errors[] = t('Full name is required.');
    }

    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $user['username'])) {
        $errors[] = t('Use a username of 3–60 letters, numbers, dots, dashes or underscores.');
    } elseif ($userModel->usernameExists($user['username'])) {
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

    if ($user['officer_code'] !== '') {
        $exists = Database::getConnection()->prepare(
            "SELECT COUNT(*) FROM users WHERE officer_code = :c"
        );
        $exists->execute(['c' => $user['officer_code']]);

        if ((int) $exists->fetchColumn() > 0) {
            $errors[] = t('That officer code is already in use.');
        }
    }

    if (mb_strlen($password) < 8) {
        $errors[] = t('The password must be at least 8 characters.');
    }

    if ($password !== $confirm) {
        $errors[] = t('The passwords do not match.');
    }

    if (!$errors) {
        try {
            $userModel->addUser([
                'full_name'          => $user['full_name'],
                'username'           => $user['username'],
                'password'           => $password,
                'role'               => $user['role'],
                'status'             => $user['status'],
                'phone'              => $user['phone'] !== '' ? normalisePhone($user['phone']) : null,
                'officer_code'       => $user['officer_code'] !== '' ? $user['officer_code'] : null,
                'preferred_language' => $user['preferred_language'],
            ]);

            flash('success', t('User account created.'));
            redirect(BASE_URL . 'views/users/index.php');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = t('New user');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('New user') ?></h4>
    <p><?= t('Create a loan officer or administrator account.') ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/users/index.php">
      <i class="bi bi-arrow-left me-1"></i><?= t('Back to users') ?>
    </a>
  </div>
</div>

<?php require __DIR__ . '/_form.php'; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
