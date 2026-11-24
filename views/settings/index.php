<?php
/**
 * views/settings/index.php — application settings (administrator only).
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireAdmin();

$errors = [];

$fields = [
    'business_name'         => '',
    'business_phone'        => '',
    'default_weeks'         => Settings::defaultWeeks(),
    'default_weekly_amount' => Settings::defaultWeeklyAmount(),
    'currency'              => Settings::currency(),
    'overdue_grace_days'    => Settings::graceDays(),
    'default_language'      => Settings::defaultLanguage(),
];

if (isPost()) {
    $input = [
        'business_name'         => trim((string) ($_POST['business_name'] ?? '')),
        'business_phone'        => trim((string) ($_POST['business_phone'] ?? '')),
        'default_weeks'         => (int) ($_POST['default_weeks'] ?? 0),
        'default_weekly_amount' => (float) ($_POST['default_weekly_amount'] ?? 0),
        'currency'              => strtoupper(trim((string) ($_POST['currency'] ?? 'TZS'))),
        'overdue_grace_days'    => (int) ($_POST['overdue_grace_days'] ?? 0),
        'default_language'      => (string) ($_POST['default_language'] ?? 'en'),
    ];

    if (mb_strlen($input['business_name']) > 120) {
        $errors[] = t('The business name must be 120 characters or fewer.');
    }

    if ($input['business_phone'] !== '' && mb_strlen($input['business_phone']) > 30) {
        $errors[] = t('The phone number is too long.');
    }

    if ($input['default_weeks'] < 1 || $input['default_weeks'] > 104) {
        $errors[] = t('Default weeks must be between 1 and 104.');
    }

    if ($input['default_weekly_amount'] <= 0 || $input['default_weekly_amount'] > 100000000) {
        $errors[] = t('Enter a sensible default weekly amount.');
    }

    if (!preg_match('/^[A-Z]{2,6}$/', $input['currency'])) {
        $errors[] = t('The currency must be a short code such as TZS.');
    }

    if ($input['overdue_grace_days'] < 0 || $input['overdue_grace_days'] > 90) {
        $errors[] = t('Grace days must be between 0 and 90.');
    }

    if (!in_array($input['default_language'], ['en', 'sw'], true)) {
        $input['default_language'] = 'en';
    }

    if (!$errors) {
        foreach ($input as $key => $value) {
            Settings::set($key, $value);
        }

        AuditLog::record('settings.update', 'settings', null, 'Application settings saved');

        flash('success', t('Settings saved.'));
        redirect(BASE_URL . 'views/settings/index.php');
    }

    $fields = $input;
}

$pageTitle = t('Settings');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Settings') ?></h4>
    <p><?= t('Business details and the defaults used when creating loans.') ?></p>
  </div>
</div>

<?= errorList($errors) ?>

<form method="POST" action="<?= BASE_URL ?>views/settings/index.php" novalidate>
  <?= Security::csrfField() ?>

  <div class="row g-3">
    <div class="col-lg-7">

      <div class="form-section">
        <h6><i class="bi bi-shop me-1"></i><?= t('Business') ?></h6>

        <div class="row g-2">
          <div class="col-md-7">
            <label class="form-label" for="business_name"><?= t('Business name') ?></label>
            <input class="form-control" type="text" id="business_name" name="business_name"
                   value="<?= e($fields['business_name']) ?>" maxlength="120"
                   placeholder="<?= t('Shown on statements and receipts') ?>">
          </div>

          <div class="col-md-5">
            <label class="form-label" for="business_phone"><?= t('Business phone') ?></label>
            <input class="form-control" type="text" id="business_phone" name="business_phone"
                   value="<?= e($fields['business_phone']) ?>" maxlength="30"
                   placeholder="<?= t('e.g. 0744 909 605') ?>">
          </div>

          <div class="col-md-6">
            <label class="form-label" for="currency"><?= t('Currency code') ?></label>
            <input class="form-control" type="text" id="currency" name="currency"
                   value="<?= e($fields['currency']) ?>" maxlength="6" required>
            <div class="form-text"><?= t('Used on receipts and reports, e.g. TZS.') ?></div>
          </div>

          <div class="col-md-6">
            <label class="form-label" for="default_language"><?= t('Default language') ?></label>
            <select class="form-select" id="default_language" name="default_language">
              <?php foreach (available_languages() as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $fields['default_language'] === $code ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label" for="date_format_display"><?= t('Date format') ?></label>
            <input class="form-control" type="text" id="date_format_display" value="DD/MM/YYYY" disabled>
            <div class="form-text"><?= t('Fixed across the whole system.') ?></div>
          </div>
        </div>
      </div>

      <div class="form-section">
        <h6><i class="bi bi-sliders me-1"></i><?= t('Loan defaults') ?></h6>

        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label" for="default_weeks"><?= t('Default number of weeks') ?></label>
            <input class="form-control" type="number" id="default_weeks" name="default_weeks"
                   value="<?= e($fields['default_weeks']) ?>" min="1" max="104" required>
          </div>

          <div class="col-md-4">
            <label class="form-label" for="default_weekly_amount"><?= t('Default weekly amount') ?></label>
            <div class="input-group">
              <span class="input-group-text"><?= e($fields['currency'] ?: 'TZS') ?></span>
              <input class="form-control" type="number" id="default_weekly_amount" name="default_weekly_amount"
                     value="<?= e($fields['default_weekly_amount']) ?>" min="1" step="1000" required>
            </div>
          </div>

          <div class="col-md-4">
            <label class="form-label" for="overdue_grace_days"><?= t('Overdue grace days') ?></label>
            <input class="form-control" type="number" id="overdue_grace_days" name="overdue_grace_days"
                   value="<?= e($fields['overdue_grace_days']) ?>" min="0" max="90" required>
            <div class="form-text"><?= t('Days after the due date before a loan is flagged overdue.') ?></div>
          </div>
        </div>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-check2-circle me-1"></i><?= t('Save settings') ?>
        </button>
        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>dashboard.php"><?= t('Cancel') ?></a>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card mb-3">
        <div class="card-header">
          <span class="card-title-sm"><i class="bi bi-info-circle me-1"></i><?= t('How these settings behave') ?></span>
        </div>
        <div class="card-body small text-muted">
          <ul class="mb-0 ps-3">
            <li><?= t('Defaults are suggested when you issue a loan; they never change existing loans.') ?></li>
            <li><?= t('The business name appears on statements, receipts and the sidebar.') ?></li>
            <li><?= t('Every change is written to the audit log.') ?></li>
            <li><?= t('Dates are always shown as DD/MM/YYYY.') ?></li>
          </ul>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <span class="card-title-sm"><i class="bi bi-shield-check me-1"></i><?= t('Security') ?></span>
        </div>
        <div class="card-body small text-muted">
          <p class="mb-2"><?= t('Change your own password from your profile.') ?></p>
          <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/profile.php">
            <i class="bi bi-person-circle me-1"></i><?= t('My profile') ?>
          </a>
        </div>
      </div>
    </div>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
