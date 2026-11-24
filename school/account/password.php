<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

use School\Core\SchoolAuth;
use School\Core\SchoolDatabase;
use School\Services\AuditService;

SchoolAuth::requireLogin();
$user = SchoolAuth::user();
if (!$user) { header('Location: ' . SCHOOL_BASE_URL . 'login.php'); exit; }
$pdo = SchoolDatabase::connection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!SchoolAuth::verifyCsrf()) throw new RuntimeException('Your session expired. Please try again.');
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $query = $pdo->prepare('SELECT password_hash FROM users WHERE id=:id AND status=\'active\'');
        $query->execute(['id' => (int) $user['id']]);
        $hash = (string) $query->fetchColumn();
        if ($hash === '' || !password_verify($current, $hash)) throw new RuntimeException('Your current password is not correct.');
        if (strlen($new) < 8) throw new RuntimeException('Choose a new password with at least 8 characters.');
        if ($new !== $confirm) throw new RuntimeException('The new password and confirmation do not match.');
        if (password_verify($new, $hash)) throw new RuntimeException('Choose a password different from your current one.');

        $update = $pdo->prepare('UPDATE users SET password_hash=:hash, must_change_password=0, password_changed_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=:id');
        $update->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => (int) $user['id']]);
        (new AuditService())->record((int) $user['id'], 'auth.password.change', 'users', (int) $user['id']);
        session_regenerate_id(true);
        school_flash('success', 'Your password has been changed.');
        header('Location: ' . SCHOOL_BASE_URL . 'dashboard.php');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$schoolName = (string) ($pdo->query('SELECT name FROM school_profile ORDER BY id LIMIT 1')->fetchColumn() ?: 'School Management');
$pageTitle = 'Change password';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><div><h4>Change password</h4><p>Keep your account secure. Use at least 8 characters and avoid reusing the shared initial password.</p></div></div>
<?php foreach (school_take_flashes() as $flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
<?php if (!empty($user['must_change_password'])): ?><div class="alert alert-warning"><i class="bi bi-shield-exclamation me-1"></i>This account is still using the shared initial password. Change it now to protect school records.</div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><span class="card-title-sm"><i class="bi bi-key me-1"></i>Update your password</span></div><div class="card-body">
      <form method="post" class="row g-3" autocomplete="off">
        <?= SchoolAuth::csrfField() ?>
        <div class="col-12"><label class="form-label">Current password</label><input type="password" name="current_password" class="form-control" autocomplete="current-password" required></div>
        <div class="col-12"><label class="form-label">New password</label><input type="password" name="new_password" class="form-control" minlength="8" autocomplete="new-password" required><div class="form-text">At least 8 characters. A mix of letters, numbers and symbols is recommended.</div></div>
        <div class="col-12"><label class="form-label">Confirm new password</label><input type="password" name="confirm_password" class="form-control" minlength="8" autocomplete="new-password" required></div>
        <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Change password</button></div>
      </form>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header"><span class="card-title-sm"><i class="bi bi-person-badge me-1"></i>Account details</span></div><div class="card-body">
      <div class="detail-list">
        <div><dt>Name</dt><dd><?= e($user['full_name']) ?></dd></div>
        <div><dt>Username</dt><dd><?= e($user['username'] ?? '—') ?></dd></div>
        <div><dt>Role</dt><dd><?= e(school_role_label(school_current_role($user))) ?></dd></div>
        <div><dt>Initial password</dt><dd><?= !empty($user['must_change_password']) ? 'Still in use' : 'Changed' ?></dd></div>
      </div>
      <div class="small text-muted mt-3"><i class="bi bi-info-circle me-1"></i>Password changes are written to the school audit trail. Contact the system administrator if you cannot sign in.</div>
    </div></div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
