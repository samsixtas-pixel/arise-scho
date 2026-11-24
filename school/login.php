<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
use School\Core\SchoolAuth;

if (SchoolAuth::userId()) { header('Location: ' . SCHOOL_BASE_URL . 'dashboard.php'); exit; }
$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SchoolAuth::verifyCsrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $username = mb_strtolower(trim((string) ($_POST['username'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || $password === '') $error = 'Enter your username and password.';
        else {
            try {
                if (SchoolAuth::login($username, $password)) { header('Location: ' . SCHOOL_BASE_URL . 'dashboard.php'); exit; }
                $error = 'Invalid username or password.';
            } catch (Throwable $exception) {
                error_log('School sign-in unavailable: ' . $exception->getMessage());
                $error = 'The school system is temporarily unavailable. Check the school database connection.';
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in | School Management</title><meta name="theme-color" content="#041F18">
<link rel="stylesheet" href="<?= SCHOOL_ASSET_URL ?>vendor/bootstrap-icons.css">
<link rel="stylesheet" href="<?= SCHOOL_ASSET_URL ?>css/login-glass.css">
</head><body>
<div class="scene"><div class="login-shell"><div class="login-card">
  <div class="wordmark"><span class="brand-badge"><i class="bi bi-mortarboard-fill"></i></span><span>School <b>Management</b></span></div>
  <div class="intro"><span class="eyebrow"><span class="dot"></span>ACADEMIC & PARENT PORTAL</span><h1>Welcome back</h1><p>Sign in to continue to your school workspace.</p></div>
  <?php if ($error !== ''): ?><div class="alert-box" role="alert"><i class="bi bi-exclamation-triangle-fill"></i><span><?= e($error) ?></span></div><?php endif; ?>
  <form class="login-form" method="post" autocomplete="on">
    <?= SchoolAuth::csrfField() ?>
    <label class="field"><i class="bi bi-person"></i><input type="text" name="username" value="<?= e($username) ?>" placeholder="Username" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></label>
    <label class="field"><i class="bi bi-lock"></i><input type="password" name="password" id="password" placeholder="Password" autocomplete="current-password" required><button type="button" class="reveal" id="revealBtn" aria-label="Show password"><i class="bi bi-eye"></i></button></label>
    <button type="submit" class="submit" id="submitBtn"><i class="bi bi-box-arrow-in-right"></i><span>Sign in</span></button>
  </form>
  <div class="meta-row"><span><i class="bi bi-shield-lock"></i> Secure school access</span><span><i class="bi bi-geo-alt"></i> Tanzania</span></div>
</div><div class="login-foot">School Management · Academic Intelligence & Parent Engagement</div></div></div>
<script>
document.getElementById('revealBtn').addEventListener('click',function(){const input=document.getElementById('password');const icon=this.querySelector('i');const show=input.type==='password';input.type=show?'text':'password';icon.className=show?'bi bi-eye-slash':'bi bi-eye';});
document.querySelector('.login-form').addEventListener('submit',function(){const button=document.getElementById('submitBtn');button.disabled=true;button.querySelector('span').textContent='Signing in…';});
</script></body></html>
