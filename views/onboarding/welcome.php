<?php
/**
 * views/onboarding/welcome.php
 * -------------------------------------------------------
 * First-login onboarding for cashier workflows.
 *
 * Shows a one-time welcome + opening cash declaration.
 * On submit:
 *   - Writes cash_declarations row with is_opening_only = 1
 *   - Stamps users.first_login_at = NOW()
 *   - Redirects to views/sales/daily.php
 *
 * Guarded: if the user is not staff or has already onboarded,
 * the page bounces back to the daily sales page.
 * -------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/autoload.php';
require_once __DIR__ . '/../../includes/functions.php';
User::requireLogin();

$userId    = (int) $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'staff';
$fullName  = $_SESSION['full_name'] ?? 'there';
$userModel = new User();

// Admins never see onboarding.
if ($role !== 'staff') {
    header('Location: ../../dashboard.php');
    exit;
}

// Already onboarded? Go straight through.
if (!$userModel->isFirstLogin($userId)) {
    header('Location: ../sales/daily.php');
    exit;
}

$errors = [];
$today  = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $opening = (float) ($_POST['opening_cash'] ?? 0);

    if ($opening < 0) {
        $errors[] = 'Opening cash cannot be negative.';
    }

    if (!$errors) {
        // 1. Create opening-only cash declaration
        $cashModel = new CashDeclaration();
        $ok = $cashModel->submit($userId, $today, [
            'opening_cash'       => $opening,
            'cash_in_hand'       => $opening,
            'mobile_money'       => 0,
            'notes'              => 'Opening balance (first login)',
            'difference_reason'  => '',
            'force_opening_only' => true,
        ]);

        if ($ok) {
            // 2. Mark first login done
            $userModel->touchFirstLogin($userId);

            AuditLog::record(
                'onboarding.complete', 'user', $userId,
                'First-login opening cash: TZS ' . number_format($opening, 2)
            );

            header('Location: ../sales/daily.php?success=' . urlencode(
                'Welcome! Opening balance saved: TZS ' . number_format($opening, 2)
            ));
            exit;
        }

        $errors[] = 'Could not save opening balance. Please try again.';
    }
}

$pageTitle = 'Welcome';
include __DIR__ . '/../../includes/header.php';
?>

<style>
  .onb-wrap {
    max-width: 560px;
    margin: 40px auto;
  }
  .onb-card {
    background: #fff;
    border: 1px solid #d7e0da;
    border-radius: 16px;
    padding: 32px 28px;
    box-shadow: 0 10px 40px rgba(13, 99, 72, 0.08);
  }
  .onb-greeting {
    font-size: 1.4rem;
    font-weight: 800;
    color: #0A4A38;
    margin-bottom: 8px;
  }
  .onb-sub {
    color: #4a5a52;
    margin-bottom: 24px;
    line-height: 1.55;
  }
  .onb-label {
    display: block;
    font-weight: 700;
    color: #0A4A38;
    margin-bottom: 6px;
  }
  .onb-hint {
    font-size: .85rem;
    color: #6c757d;
    margin-top: 6px;
    line-height: 1.4;
  }
  .onb-input {
    font-size: 1.5rem;
    font-weight: 800;
    padding: 14px 16px;
    border-radius: 12px;
    border: 2px solid #d7e0da;
    width: 100%;
  }
  .onb-input:focus {
    border-color: #0D6348;
    outline: none;
  }
  .onb-submit {
    margin-top: 20px;
    width: 100%;
    padding: 14px;
    font-size: 1.05rem;
    font-weight: 800;
    color: #F7F4E9;
    background: #0D6348;
    border: none;
    border-radius: 12px;
    cursor: pointer;
  }
  .onb-submit:hover { background: #0A4A38; }
  .onb-icon {
    width: 56px; height: 56px; border-radius: 50%;
    background: linear-gradient(135deg, #0D6348, #C98012);
    color: #fff; font-weight: 900; font-size: 1.4rem;
    display: inline-flex; align-items: center; justify-content: center;
    margin-bottom: 16px;
  }
</style>

<div class="onb-wrap">
  <div class="onb-card">

    <div class="onb-icon"><i class="bi bi-stars"></i></div>

    <div class="onb-greeting">Welcome, <?= e($fullName) ?>.</div>
    <div class="onb-sub">
      Let's set up your account. Before you start recording sales, count the cash
      currently in the till — every note and coin. Enter the total below. This is
      your <strong>opening balance</strong>, the starting point for reconciling every
      day's cash.
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST" id="onbForm" novalidate>
      <label class="onb-label" for="openingCash">Cash currently in the till (TZS)</label>
      <input type="number" id="openingCash" name="opening_cash"
             class="onb-input" placeholder="0.00" min="0" step="0.01"
             autofocus required>
      <div class="onb-hint">
        Count carefully. This amount is recorded as your opening balance for today
        and cannot be edited without the boss.
      </div>

      <button type="submit" class="onb-submit" id="onbSubmit">
        <i class="bi bi-check-circle-fill me-1"></i> Confirm opening balance
      </button>
    </form>

  </div>
</div>

<script>
(function () {
  'use strict';
  var form = document.getElementById('onbForm');
  var input = document.getElementById('openingCash');
  var btn = document.getElementById('onbSubmit');
  if (!form || !input || !btn) return;

  form.addEventListener('submit', function (e) {
    var v = parseFloat(input.value || '0');
    if (!isFinite(v) || v < 0) {
      e.preventDefault();
      Swal.fire({ icon: 'warning', title: 'Enter a valid amount' });
      return;
    }
    e.preventDefault();

    var formatted = 'TZS ' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    Swal.fire({
      icon: 'question',
      title: 'Confirm opening balance',
      html: 'You entered <strong style="font-size:1.4rem">' + formatted + '</strong>.<br>Please count again to confirm this is correct.',
      showCancelButton: true,
      confirmButtonText: 'Yes, correct',
      cancelButtonText: 'No, let me recount',
      confirmButtonColor: '#0D6348',
      cancelButtonColor: '#6c757d'
    }).then(function (res) {
      if (res.isConfirmed) {
        // Unhook so we don't loop the confirm
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Saving…';
        // Bypass the listener by submitting the form directly
        HTMLFormElement.prototype.submit.call(form);
      } else {
        input.focus();
        input.select();
      }
    });
  });
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>