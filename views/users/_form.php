<?php
/**
 * views/users/_form.php — shared add/edit form.
 *
 * Expects: $errors, $user, $isEdit
 */
?>

<?= errorList($errors) ?>

<form method="POST" novalidate>
  <?= Security::csrfField() ?>

  <div class="row g-3">
    <div class="col-lg-8">

      <div class="form-section">
        <h6><i class="bi bi-person-badge me-1"></i><?= t('Account') ?></h6>

        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label required" for="full_name"><?= t('Full name') ?></label>
            <input class="form-control" type="text" id="full_name" name="full_name"
                   value="<?= e($user['full_name']) ?>" maxlength="150" required>
          </div>

          <div class="col-md-6">
            <label class="form-label required" for="username"><?= t('Username') ?></label>
            <input class="form-control" type="text" id="username" name="username"
                   value="<?= e($user['username']) ?>" maxlength="60"
                   pattern="[A-Za-z0-9._-]{3,60}" autocomplete="off" required>
            <div class="form-text"><?= t('Letters, numbers, dots, dashes; at least 3 characters.') ?></div>
          </div>

          <div class="col-md-6">
            <label class="form-label required" for="role"><?= t('Role') ?></label>
            <select class="form-select" id="role" name="role">
              <option value="officer" <?= $user['role'] === 'officer' ? 'selected' : '' ?>><?= t('Loan Officer') ?></option>
              <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>><?= t('Administrator') ?></option>
            </select>
            <div class="form-text"><?= t('Administrators can manage users, settings and the audit log.') ?></div>
          </div>

          <div class="col-md-6">
            <label class="form-label required" for="status"><?= t('Status') ?></label>
            <select class="form-select" id="status" name="status">
              <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>><?= t('Active') ?></option>
              <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>><?= t('Inactive') ?></option>
              <option value="suspended" <?= $user['status'] === 'suspended' ? 'selected' : '' ?>><?= t('Suspended') ?></option>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label" for="phone"><?= t('Phone') ?></label>
            <input class="form-control" type="tel" id="phone" name="phone"
                   value="<?= e($user['phone']) ?>" maxlength="30" placeholder="0744 909 605">
          </div>

          <div class="col-md-6">
            <label class="form-label" for="officer_code"><?= t('Officer code') ?></label>
            <input class="form-control" type="text" id="officer_code" name="officer_code"
                   value="<?= e($user['officer_code']) ?>" maxlength="20"
                   placeholder="<?= t('Short code, e.g. OF-01') ?>">
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
        </div>
      </div>

      <div class="form-section">
        <h6><i class="bi bi-key me-1"></i><?= t($isEdit ? 'Reset password' : 'Password') ?></h6>

        <?php if ($isEdit): ?>
          <div class="alert alert-light border small">
            <?= t('Leave both fields blank to keep the current password.') ?>
          </div>
        <?php endif; ?>

        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label <?= $isEdit ? '' : 'required' ?>" for="password">
              <?= t($isEdit ? 'New password' : 'Password') ?>
            </label>
            <input class="form-control" type="password" id="password" name="password"
                   minlength="8" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>>
          </div>

          <div class="col-md-6">
            <label class="form-label <?= $isEdit ? '' : 'required' ?>" for="password_confirm">
              <?= t('Confirm password') ?>
            </label>
            <input class="form-control" type="password" id="password_confirm" name="password_confirm"
                   minlength="8" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>>
          </div>
        </div>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-check2-circle me-1"></i><?= t($isEdit ? 'Save changes' : 'Create user') ?>
        </button>
        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>views/users/index.php"><?= t('Cancel') ?></a>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-header">
          <span class="card-title-sm"><i class="bi bi-shield-check me-1"></i><?= t('Access') ?></span>
        </div>
        <div class="card-body small text-muted">
          <p class="mb-2"><strong><?= t('Loan Officer') ?></strong></p>
          <ul class="ps-3 mb-3">
            <li><?= t('Registers customers and issues loans.') ?></li>
            <li><?= t('Records payments and follow-ups.') ?></li>
            <li><?= t('Sees the whole loan book.') ?></li>
          </ul>

          <p class="mb-2"><strong><?= t('Administrator') ?></strong></p>
          <ul class="ps-3 mb-0">
            <li><?= t('Everything an officer can do.') ?></li>
            <li><?= t('Manages users and settings.') ?></li>
            <li><?= t('Reverses payments and writes off loans.') ?></li>
            <li><?= t('Reads the audit log.') ?></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</form>
