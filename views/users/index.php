<?php
/**
 * views/users/index.php — staff accounts (administrator only).
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireAdmin();

$db = Database::getConnection();

if (isPost()) {
    $action = (string) ($_POST['action'] ?? '');
    $userId = (int) ($_POST['user_id'] ?? 0);
    $target = $userId > 0 ? (new User())->find($userId) : false;

    try {
        if (!$target) {
            throw new RuntimeException(t('User not found.'));
        }

        if ($target['id'] == currentUserId() && $action !== 'activate') {
            throw new RuntimeException(t('You cannot change the status of your own account.'));
        }

        if ($action === 'activate' || $action === 'deactivate') {
            $newStatus = $action === 'activate' ? 'active' : 'inactive';

            if ($newStatus !== 'active' && $target['role'] === 'admin') {
                $remaining = (int) $db->query(
                    "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id != " . (int) $target['id']
                )->fetchColumn();

                if ($remaining === 0) {
                    throw new RuntimeException(t('At least one active administrator must remain.'));
                }
            }

            $db->prepare("UPDATE users SET status = :s WHERE id = :id")
               ->execute(['s' => $newStatus, 'id' => $target['id']]);

            AuditLog::record('user.status', 'users', (int) $target['id'],
                'Status set to ' . $newStatus . ' for ' . $target['username']);

            flash('success', t('User status updated.'));
        } elseif ($action === 'reset_password') {
            $newPassword = (string) ($_POST['new_password'] ?? '');

            if (mb_strlen($newPassword) < 8) {
                throw new RuntimeException(t('The new password must be at least 8 characters.'));
            }

            $db->prepare("UPDATE users SET password = :p WHERE id = :id")
               ->execute(['p' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $target['id']]);

            AuditLog::record('user.password_reset', 'users', (int) $target['id'],
                'Password reset for ' . $target['username']);

            flash('success', t('Password reset.'));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(BASE_URL . 'views/users/index.php');
}

$users = $db->query(
    "SELECT u.*,
            (SELECT COUNT(*) FROM customers c WHERE c.assigned_officer_id = u.id AND c.deleted_at IS NULL) AS customers,
            (SELECT COUNT(*) FROM payments p WHERE p.received_by = u.id AND p.status = 'confirmed') AS receipts
       FROM users u
      ORDER BY u.role = 'admin' DESC, u.full_name"
)->fetchAll();

$activeCount = 0;
foreach ($users as $user) {
    if ($user['status'] === 'active') {
        $activeCount++;
    }
}

$pageTitle = t('Users');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Users') ?></h4>
    <p><?= t(':n accounts · :active active', ['n' => count($users), 'active' => $activeCount]) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/users/add.php">
      <i class="bi bi-person-plus me-1"></i><?= t('New user') ?>
    </a>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th><?= t('User') ?></th>
          <th><?= t('Role') ?></th>
          <th class="d-none d-md-table-cell"><?= t('Phone') ?></th>
          <th class="num d-none d-lg-table-cell"><?= t('Customers') ?></th>
          <th class="num d-none d-lg-table-cell"><?= t('Receipts') ?></th>
          <th class="d-none d-md-table-cell"><?= t('Last login') ?></th>
          <th><?= t('Status') ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <span class="avatar-fallback"><?= e(initials($user['full_name'])) ?></span>
                <div>
                  <span class="name"><?= e($user['full_name']) ?></span>
                  <span class="sub">@<?= e($user['username']) ?><?= $user['id'] == currentUserId() ? ' · ' . t('you') : '' ?></span>
                </div>
              </div>
            </td>
            <td><?= $user['role'] === 'admin'
                ? '<span class="badge bg-dark">' . t('Administrator') . '</span>'
                : '<span class="badge bg-primary">' . t('Loan Officer') . '</span>' ?></td>
            <td class="d-none d-md-table-cell"><?= e(formatPhone($user['phone']) ?: '—') ?></td>
            <td class="num d-none d-lg-table-cell"><?= (int) $user['customers'] ?></td>
            <td class="num d-none d-lg-table-cell"><?= (int) $user['receipts'] ?></td>
            <td class="d-none d-md-table-cell small text-muted">
              <?= e(formatDateTime($user['last_login_at'], t('never'))) ?>
            </td>
            <td><?= statusBadge($user['status']) ?></td>
            <td class="text-end">
              <div class="table-actions">
                <a class="btn btn-sm btn-outline-primary btn-icon" title="<?= t('Edit') ?>"
                   href="<?= BASE_URL ?>views/users/edit.php?id=<?= (int) $user['id'] ?>">
                  <i class="bi bi-pencil"></i>
                </a>

                <?php if ($user['id'] != currentUserId()): ?>
                  <form method="POST" class="d-inline"
                        data-confirm="<?= $user['status'] === 'active'
                            ? t('Deactivate this account? They will not be able to sign in.')
                            : t('Reactivate this account?') ?>">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                    <input type="hidden" name="action" value="<?= $user['status'] === 'active' ? 'deactivate' : 'activate' ?>">
                    <button class="btn btn-sm <?= $user['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?> btn-icon"
                            type="submit" title="<?= $user['status'] === 'active' ? t('Deactivate') : t('Activate') ?>">
                      <i class="bi <?= $user['status'] === 'active' ? 'bi-person-slash' : 'bi-person-check' ?>"></i>
                    </button>
                  </form>
                <?php endif; ?>

                <button class="btn btn-sm btn-outline-secondary btn-icon" type="button" title="<?= t('Reset password') ?>"
                        data-bs-toggle="modal" data-bs-target="#resetModal"
                        data-user-id="<?= (int) $user['id'] ?>"
                        data-user-name="<?= e($user['full_name']) ?>">
                  <i class="bi bi-key"></i>
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="resetModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="POST" action="<?= BASE_URL ?>views/users/index.php">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="resetUserId" value="">

      <div class="modal-header">
        <h5 class="modal-title"><?= t('Reset password') ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button>
      </div>

      <div class="modal-body">
        <p class="small text-muted" id="resetSummary"></p>

        <label class="form-label required" for="resetPassword"><?= t('New password') ?></label>
        <input class="form-control" type="password" id="resetPassword" name="new_password"
               minlength="8" autocomplete="new-password" required>
        <div class="form-text"><?= t('At least 8 characters. Share it with the user and ask them to change it.') ?></div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i><?= t('Reset') ?></button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('resetModal')?.addEventListener('show.bs.modal', function (event) {
  const button = event.relatedTarget;
  if (!button) return;
  document.getElementById('resetUserId').value = button.dataset.userId || '';
  document.getElementById('resetSummary').textContent =
    '<?= t('Set a new password for') ?> ' + (button.dataset.userName || '');
  document.getElementById('resetPassword').value = '';
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
