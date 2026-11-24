<?php
/**
 * login.php — sign in
 */

require_once __DIR__ . '/includes/autoload.php';

if (User::isLoggedIn()) {
    redirect(BASE_URL . 'dashboard.php');
}

$error    = '';
$username = '';

$flashedError = $_GET['error'] ?? '';
if ($flashedError !== '') {
    $error = (string) $flashedError;
}

if (isPost()) {
    if (!Security::verifyCsrf()) {
        $error = t('Your session expired. Please try again.');
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ip       = clientIp();

        if ($username === '' || $password === '') {
            $error = t('Enter your username and password.');
        } else {
            $limit = Security::checkLoginRateLimit($username, $ip);

            if (!$limit['allowed']) {
                $minutes = max(1, (int) ceil($limit['retry_after'] / 60));
                $error   = t('Too many failed attempts. Try again in :n minute(s).', ['n' => $minutes]);
            } else {
                $userModel = new User();
                $user      = $userModel->login($username, $password);

                Security::recordLoginAttempt($username, $ip, $user !== false);

                if ($user === false) {
                    $error = t('Invalid username or password.');
                    AuditLog::record('auth.failed', 'users', null, 'Failed login for "' . $username . '"');
                } else {
                    Security::regenerateSession();
                    $userModel->createSession($user);
                    $userModel->touchLastLogin((int) $user['id']);

                    $next = (string) ($_POST['next'] ?? '');

                    if ($next !== '' && str_starts_with($next, BASE_URL) && !str_contains($next, '//')) {
                        redirect($next);
                    }

                    redirect(BASE_URL . 'dashboard.php');
                }
            }
        }
    }
}

$next = (string) ($_GET['next'] ?? '');
$lang = current_lang();
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('Sign in') ?> | <?= e(Settings::businessName()) ?></title>
<meta name="theme-color" content="#041F18">
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/loan-manager-icon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap-icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/login-glass.css?v=2">
</head>
<body>

<div class="scene">
  <div class="login-shell">
    <div class="login-card">

      <div class="wordmark">
        <span class="brand-badge"><i class="bi bi-cash-coin"></i></span>
        <span>Loan <b>Manager</b></span>
      </div>

      <div class="intro">
        <span class="eyebrow"><span class="dot"></span><?= t('Weekly Loan Management') ?></span>
        <h1><?= t('Welcome back') ?></h1>
        <p><?= t('Sign in to record payments, follow up arrears and manage the loan book.') ?></p>
      </div>

      <?php if ($error !== ''): ?>
        <div class="alert-box" role="alert">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <form class="login-form" method="POST" action="<?= BASE_URL ?>login.php" autocomplete="on" novalidate>
        <?= Security::csrfField() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">

        <label class="field">
          <i class="bi bi-person"></i>
          <input type="text" name="username" value="<?= e($username) ?>" placeholder="<?= t('Username') ?>"
                 autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </label>

        <label class="field">
          <i class="bi bi-lock"></i>
          <input type="password" name="password" id="password" placeholder="<?= t('Password') ?>"
                 autocomplete="current-password" required>
          <button type="button" class="reveal" id="revealBtn" aria-label="<?= t('Show password') ?>">
            <i class="bi bi-eye"></i>
          </button>
        </label>

        <button type="submit" class="submit" id="submitBtn">
          <i class="bi bi-box-arrow-in-right"></i>
          <span><?= t('Sign in') ?></span>
        </button>
      </form>

      <div class="meta-row">
        <span><i class="bi bi-shield-lock"></i> <?= t('Secure session · 8 hour limit') ?></span>
        <span class="lang-pill">
          <?php foreach (available_languages() as $code => $label): ?>
            <a class="<?= $code === $lang ? 'on' : '' ?>" href="?lang=<?= e($code) ?>"><?= strtoupper($code) ?></a>
          <?php endforeach; ?>
        </span>
      </div>

    </div>

    <div class="login-foot">
      <?= e(Settings::businessName()) ?> · <?= t('Weekly Loan Management System') ?>
    </div>
  </div>
</div>

<script>
document.getElementById('revealBtn').addEventListener('click', function () {
  const input = document.getElementById('password');
  const icon  = this.querySelector('i');
  const show  = input.type === 'password';
  input.type  = show ? 'text' : 'password';
  icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
});

document.querySelector('.login-form').addEventListener('submit', function () {
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.querySelector('span').textContent = <?= json_encode(t('Signing in…')) ?>;
});
</script>

</body>
</html>
