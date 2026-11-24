<?php
/**
 * views/profile.php — the signed-in user's own account.
 */

require_once __DIR__ . '/../includes/autoload.php';
User::requireLogin();

$userModel = new User();
$userId    = currentUserId();
$user      = $userModel->find($userId);

if (!$user) {
    flash('error', t('Your account could not be loaded. Please sign in again.'));
    redirect(BASE_URL . 'logout.php');
}

$errors = [];
$active = (string) ($_POST['action'] ?? '');

if (isPost()) {
    try {
        if ($active === 'profile') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $phone    = trim((string) ($_POST['phone'] ?? ''));
            $lang     = (string) ($_POST['preferred_language'] ?? 'en');

            if ($fullName === '' || mb_strlen($fullName) > 150) {
                $errors[] = t('Enter your full name (150 characters or fewer).');
            }

            if ($phone !== '' && !isValidTzPhone($phone)) {
                $errors[] = t('Enter a valid Tanzanian phone number, e.g. 0744 909 605.');
            }

            if (!in_array($lang, ['en', 'sw'], true)) {
                $lang = 'en';
            }

            if (!$errors) {
                Database::getConnection()->prepare(
                    "UPDATE users SET full_name = :n, phone = :p, preferred_language = :l WHERE id = :id"
                )->execute([
                    'n'  => $fullName,
                    'p'  => $phone !== '' ? normalisePhone($phone) : null,
                    'l'  => $lang,
                    'id' => $userId,
                ]);

                $_SESSION['full_name']          = $fullName;
                $_SESSION['preferred_language'] = $lang;
                $_SESSION['lang']               = $lang;

                AuditLog::record('user.profile', 'users', $userId, 'Updated own profile');
                flash('success', t('Profile updated.'));
                redirect(BASE_URL . 'views/profile.php');
            }
        } elseif ($active === 'password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $new     = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['new_password_confirm'] ?? '');

            if (!password_verify($current, $user['password'])) {
                $errors[] = t('Your current password is not correct.');
            }

            if (mb_strlen($new) < 8) {
                $errors[] = t('The new password must be at least 8 characters.');
            }

            if ($new !== $confirm) {
                $errors[] = t('The new passwords do not match.');
            }

            if (!$errors) {
                Database::getConnection()->prepare(
                    "UPDATE users SET password = :p WHERE id = :id"
                )->execute([
                    'p'  => password_hash($new, PASSWORD_DEFAULT),
                    'id' => $userId,
                ]);

                AuditLog::record('user.password', 'users', $userId, 'Changed own password');
                Security::regenerateSession();

                flash('success', t('Password changed.'));
                redirect(BASE_URL . 'views/profile.php');
            }
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$db = Database::getConnection();

$stats = $db->prepare(
    "SELECT
        (SELECT COUNT(*) FROM payments WHERE received_by = :id1 AND status = 'confirmed') AS receipts,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE received_by = :id2 AND status = 'confirmed') AS collected,
        (SELECT COUNT(*) FROM customers WHERE assigned_officer_id = :id3 AND deleted_at IS NULL) AS customers"
);
$stats->execute(['id1' => $userId, 'id2' => $userId, 'id3' => $userId]);
$numbers = $stats->fetch() ?: ['receipts' => 0, 'collected' => 0, 'customers' => 0];

$pageTitle = t('My profile');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('My profile') ?></h4>
    <p><?= e($user['full_name']) ?> · <?= e(t(ucfirst($user['role']))) ?></p>
  </div>
</div>

<?= errorList($errors) ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-body text-center">
        <span class="avatar-fallback lg mx-auto mb-2"><?= e(initials($user['full_name'])) ?></span>
        <h6 class="mb-1"><?= e($user['full_name']) ?></h6>
        <div class="small text-muted mb-2">@<?= e($user['username']) ?></div>
        <span class="badge bg-dark"><?= e(t(ucfirst($user['role']))) ?></span>
        <?= statusBadge($user['status']) ?>
      </div>
      <div class="card-body border-top">
        <dl class="detail-list">
          <div><dt><?= t('Phone') ?></dt><dd><?= e(formatPhone($user['phone']) ?: '—') ?></dd></div>
          <div><dt><?= t('Officer code') ?></dt><dd><?= e($user['officer_code'] ?? '—') ?></dd></div>
          <div><dt><?= t('Last login') ?></dt><dd class="small"><?= e(formatDateTime($user['last_login_at'], t('never'))) ?></dd></div>
          <div><dt><?= t('Account created') ?></dt><dd class="small"><?= e(formatDate($user['created_at'])) ?></dd></div>
        </dl>
      </div>
    </div>

    <div class="kpi-grid" style="grid-template-columns:1fr">
      <div class="kpi">
        <div class="k-icon"><i class="bi bi-cash-stack"></i></div>
        <div class="k-body">
          <div class="k-label"><?= t('Collected by you') ?></div>
          <div class="k-value"><?= e(money($numbers['collected'])) ?></div>
          <div class="k-hint"><?= t(':n receipts', ['n' => (int) $numbers['receipts']]) ?></div>
        </div>
      </div>
      <div class="kpi gold">
        <div class="k-icon"><i class="bi bi-people"></i></div>
        <div class="k-body">
          <div class="k-label"><?= t('Customers assigned') ?></div>
          <div class="k-value"><?= (int) $numbers['customers'] ?></div>
          <div class="k-hint"><?= t('active portfolio') ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-header">
        <span class="card-title-sm"><i class="bi bi-person-circle me-1"></i><?= t('Profile details') ?></span>
      </div>
      <div class="card-body">
        <form method="POST" novalidate>
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="profile">

          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label required" for="full_name"><?= t('Full name') ?></label>
              <input class="form-control" type="text" id="full_name" name="full_name"
                     value="<?= e(old('full_name', $user['full_name'])) ?>" maxlength="150" required>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="phone"><?= t('Phone') ?></label>
              <input class="form-control" type="tel" id="phone" name="phone"
                     value="<?= e(old('phone', $user['phone'] ?? '')) ?>" maxlength="30" placeholder="0744 909 605">
            </div>

            <div class="col-md-6">
              <label class="form-label" for="preferred_language"><?= t('Preferred language') ?></label>
              <select class="form-select" id="preferred_language" name="preferred_language">
                <?php foreach (available_languages() as $code => $label): ?>
                  <option value="<?= e($code) ?>" <?= $user['preferred_language'] === $code ? 'selected' : '' ?>>
                    <?= e($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label"><?= t('Username') ?></label>
              <input class="form-control" type="text" value="<?= e($user['username']) ?>" disabled>
              <div class="form-text"><?= t('Only an administrator can change your username.') ?></div>
            </div>
          </div>

          <div class="mt-3">
            <button class="btn btn-primary" type="submit">
              <i class="bi bi-check2-circle me-1"></i><?= t('Save profile') ?>
            </button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <span class="card-title-sm"><i class="bi bi-shield-lock me-1"></i><?= t('Change password') ?></span>
      </div>
      <div class="card-body">
        <form method="POST" novalidate>
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="password">

          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label required" for="current_password"><?= t('Current password') ?></label>
              <input class="form-control" type="password" id="current_password" name="current_password"
                     autocomplete="current-password" required>
            </div>

            <div class="col-md-4">
              <label class="form-label required" for="new_password"><?= t('New password') ?></label>
              <input class="form-control" type="password" id="new_password" name="new_password"
                     minlength="8" autocomplete="new-password" required>
            </div>

            <div class="col-md-4">
              <label class="form-label required" for="new_password_confirm"><?= t('Confirm new password') ?></label>
              <input class="form-control" type="password" id="new_password_confirm" name="new_password_confirm"
                     minlength="8" autocomplete="new-password" required>
            </div>
          </div>

          <div class="form-text mt-2"><?= t('Use at least 8 characters. Changing your password signs out other devices.') ?></div>

          <div class="mt-3">
            <button class="btn btn-outline-primary" type="submit">
              <i class="bi bi-key me-1"></i><?= t('Change password') ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
