<?php
/**
 * views/loans/view.php — loan file with schedule, payments and follow-ups.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$loanModel     = new Loan();
$paymentModel  = new Payment();
$scheduleModel = new RepaymentSchedule();
$id            = (int) ($_GET['id'] ?? 0);

if (isPost()) {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'write_off') {
            User::requireAdmin();
            $loanModel->writeOff($id, trim((string) ($_POST['reason'] ?? '')));
            flash('success', t('Loan written off.'));
        } elseif ($action === 'delete') {
            User::requireAdmin();
            $loanModel->softDelete($id);
            flash('success', t('Loan deleted.'));
            redirect(BASE_URL . 'views/loans/index.php');
        } elseif ($action === 'note') {
            $type = in_array($_POST['activity_type'] ?? 'note', ['call', 'sms', 'visit', 'note', 'promise'], true)
                ? $_POST['activity_type'] : 'note';

            Database::getConnection()->prepare(
                "INSERT INTO collection_activities (loan_id, officer_id, activity_type, notes, promise_date, promise_amount)
                 VALUES (:loan_id, :officer, :type, :notes, :promise_date, :promise_amount)"
            )->execute([
                'loan_id'       => $id,
                'officer'       => currentUserId(),
                'type'          => $type,
                'notes'         => trim((string) ($_POST['notes'] ?? '')),
                'promise_date'  => !empty($_POST['promise_date']) ? Security::safeDate($_POST['promise_date']) : null,
                'promise_amount'=> !empty($_POST['promise_amount']) ? (float) $_POST['promise_amount'] : null,
            ]);

            AuditLog::record('loan.followup', 'loans', $id, 'Follow-up logged (' . $type . ')');
            flash('success', t('Follow-up saved.'));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(BASE_URL . 'views/loans/view.php?id=' . $id);
}

$loan = $loanModel->findDetailed($id);

if (!$loan) {
    flash('error', t('Loan not found.'));
    redirect(BASE_URL . 'views/loans/index.php');
}

$payments = $paymentModel->forLoan($id, true);
$schedule = $scheduleModel->forLoan($id);

$stmt = Database::getConnection()->prepare(
    "SELECT a.*, u.full_name AS officer_name
       FROM collection_activities a
  LEFT JOIN users u ON u.id = a.officer_id
      WHERE a.loan_id = :id
      ORDER BY a.id DESC
      LIMIT 20"
);
$stmt->execute(['id' => $id]);
$activities = $stmt->fetchAll();

$progress = percent($loan['amount_paid'], $loan['total_amount_payable']);
$photo    = PhotoStorage::url($loan['photo_path']);

$pageTitle = t('Loan') . ' ' . $loan['loan_reference'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4>
      <span class="ref"><?= e($loan['loan_reference']) ?></span>
      · <?= e($loan['customer_name']) ?>
      <?= ' ' ?><?= loanBadge($loan['loan_status']) ?>
    </h4>
    <p>
      <?= t('Issued :date', ['date' => formatDate($loan['loan_date'])]) ?>
      · <?= e(money($loan['weekly_amount'])) ?> × <?= (int) $loan['number_of_weeks'] ?> <?= t('weeks') ?>
      <?php if ((int) $loan['days_overdue'] > 0): ?>
        · <span class="text-danger fw-semibold"><?= (int) $loan['days_overdue'] ?> <?= t('days overdue') ?></span>
      <?php elseif ($loan['next_payment_date']): ?>
        · <?= t('Next due :date', ['date' => formatDate($loan['next_payment_date'])]) ?>
      <?php endif; ?>
    </p>
  </div>
  <div class="page-actions">
    <?php if ($loan['loan_status'] !== 'PAID' && !(int) $loan['is_written_off']): ?>
      <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/payments/add.php?loan_id=<?= $id ?>">
        <i class="bi bi-cash me-1"></i><?= t('Record payment') ?>
      </a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/reports/statement.php?loan_id=<?= $id ?>">
      <i class="bi bi-file-earmark-text me-1"></i><?= t('Statement') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/customers/view.php?id=<?= (int) $loan['customer_id'] ?>">
      <i class="bi bi-person me-1"></i><?= t('Customer') ?>
    </a>
  </div>
</div>

<?php if ((int) $loan['is_written_off'] === 1): ?>
  <div class="alert alert-danger d-flex gap-2">
    <i class="bi bi-x-octagon mt-1"></i>
    <div class="small">
      <strong><?= t('Written off') ?></strong> — <?= e($loan['write_off_reason'] ?? '') ?>
    </div>
  </div>
<?php endif; ?>

<div class="kpi-grid">
  <div class="kpi">
    <div class="k-icon"><i class="bi bi-box-arrow-up"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Principal issued') ?></div>
      <div class="k-value"><?= e(money($loan['principal_amount'])) ?></div>
    </div>
  </div>
  <div class="kpi ink">
    <div class="k-icon"><i class="bi bi-file-earmark-text"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Total payable') ?></div>
      <div class="k-value"><?= e(money($loan['total_amount_payable'])) ?></div>
    </div>
  </div>
  <div class="kpi">
    <div class="k-icon"><i class="bi bi-check2-circle"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Paid so far') ?></div>
      <div class="k-value"><?= e(money($loan['amount_paid'])) ?></div>
    </div>
  </div>
  <div class="kpi <?= (float) $loan['remaining_balance'] > 0 ? 'gold' : '' ?>">
    <div class="k-icon"><i class="bi bi-hourglass-split"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Balance') ?></div>
      <div class="k-value"><?= e(money($loan['remaining_balance'])) ?></div>
    </div>
  </div>
</div>

<div class="row g-3">

  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header">
        <span class="card-title-sm"><i class="bi bi-person-vcard me-1"></i><?= t('Loan details') ?></span>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <?php if ($photo): ?>
            <img class="avatar-photo" src="<?= e($photo) ?>" alt="">
          <?php else: ?>
            <span class="avatar-fallback"><?= e(initials($loan['customer_name'])) ?></span>
          <?php endif; ?>
          <div>
            <a class="fw-semibold text-decoration-none" href="<?= BASE_URL ?>views/customers/view.php?id=<?= (int) $loan['customer_id'] ?>">
              <?= e($loan['customer_name']) ?>
            </a>
            <div class="small text-muted"><?= e(formatPhone($loan['phone_number']) ?: t('No phone on file')) ?></div>
          </div>
        </div>

        <dl class="detail-list">
          <div><dt><?= t('Loan date') ?></dt><dd><?= e(formatDate($loan['loan_date'])) ?></dd></div>
          <div><dt><?= t('First payment') ?></dt><dd><?= e(formatDate($loan['first_payment_date'])) ?></dd></div>
          <div><dt><?= t('Final payment') ?></dt><dd><?= e(formatDate($loan['final_payment_date'])) ?></dd></div>
          <div><dt><?= t('Weekly amount') ?></dt><dd><?= e(money($loan['weekly_amount'])) ?></dd></div>
          <div><dt><?= t('Instalments paid') ?></dt><dd><?= (int) $loan['installments_paid'] ?> / <?= (int) $loan['number_of_weeks'] ?></dd></div>
          <div><dt><?= t('Missed instalments') ?></dt><dd class="<?= (int) $loan['installments_missed'] > 0 ? 'text-danger' : '' ?>"><?= (int) $loan['installments_missed'] ?></dd></div>
          <div><dt><?= t('Next payment') ?></dt><dd><?= e(formatDate($loan['next_payment_date'])) ?></dd></div>
          <div><dt><?= t('Officer') ?></dt><dd><?= e($loan['officer_name'] ?? '—') ?></dd></div>
        </dl>

        <div class="divide-row"></div>
        <div class="d-flex justify-content-between small mb-1">
          <span class="text-muted"><?= t('Repayment progress') ?></span>
          <strong><?= $progress ?>%</strong>
        </div>
        <div class="progress-thin <?= $loan['loan_status'] === 'OVERDUE' ? 'red' : '' ?>">
          <span style="width: <?= min(100, round($progress, 1)) ?>%"></span>
        </div>

        <?php if (!empty($loan['notes'])): ?>
          <div class="divide-row"></div>
          <div class="small text-muted"><?= nl2br(e($loan['notes'])) ?></div>
        <?php endif; ?>
      </div>

      <?php if (User::isAdmin() && !(int) $loan['is_written_off']): ?>
      <div class="card-body border-top">
        <form method="POST" data-confirm="<?= t('Writing off stops this loan counting as collectable. It cannot be undone.') ?>" class="d-flex gap-2 flex-wrap">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="write_off">
          <input class="form-control form-control-sm" style="max-width:220px" type="text" name="reason"
                 placeholder="<?= t('Reason for write-off') ?>" required>
          <button class="btn btn-sm btn-outline-danger" type="submit"><?= t('Write off') ?></button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-8">

    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title-sm"><i class="bi bi-list-check me-1"></i><?= t('Repayment schedule') ?></span>
        <div class="form-check form-switch m-0">
          <input class="form-check-input" type="checkbox" id="showSchedule" checked>
          <label class="form-check-label small" for="showSchedule"><?= t('Show') ?></label>
        </div>
      </div>

      <div class="table-wrap" id="scheduleTable">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th><?= t('Due date') ?></th>
              <th class="num"><?= t('Amount due') ?></th>
              <th class="num"><?= t('Paid') ?></th>
              <th><?= t('Status') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($schedule as $instalment): ?>
              <tr class="<?= $instalment['status'] === 'missed' ? 'row-danger' : '' ?>">
                <td class="fw-semibold"><?= (int) $instalment['installment_number'] ?></td>
                <td><?= e(formatDate($instalment['due_date'])) ?></td>
                <td class="num"><?= e(num($instalment['amount_due'])) ?></td>
                <td class="num <?= (float) $instalment['amount_paid'] > 0 ? 'text-success' : 'text-muted' ?>">
                  <?= e(num($instalment['amount_paid'])) ?>
                </td>
                <td><?= statusBadge($instalment['status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title-sm"><i class="bi bi-receipt me-1"></i><?= t('Payments') ?></span>
        <?php if ($loan['loan_status'] !== 'PAID'): ?>
          <a class="card-link" href="<?= BASE_URL ?>views/payments/add.php?loan_id=<?= $id ?>"><?= t('Record payment') ?></a>
        <?php endif; ?>
      </div>

      <?php if (!$payments): ?>
        <div class="empty-state">
          <i class="bi bi-receipt"></i>
          <strong><?= t('No payments yet') ?></strong>
          <p><?= t('The first receipt will appear here.') ?></p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th><?= t('Receipt') ?></th>
                <th><?= t('Date') ?></th>
                <th class="num"><?= t('Amount') ?></th>
                <th class="num d-none d-md-table-cell"><?= t('Running total') ?></th>
                <th><?= t('Method') ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (array_reverse($payments) as $payment): ?>
                <tr class="<?= $payment['status'] === 'reversed' ? 'text-muted' : '' ?>">
                  <td>
                    <span class="ref"><?= e($payment['payment_reference']) ?></span>
                    <?php if ($payment['status'] === 'reversed'): ?>
                      <span class="sub"><?= t('reversed') ?></span>
                    <?php endif; ?>
                  </td>
                  <td><?= e(formatDateTime($payment['payment_date'])) ?></td>
                  <td class="num fw-semibold"><?= e(money($payment['amount_paid'])) ?></td>
                  <td class="num d-none d-md-table-cell"><?= e(num($payment['total_paid_to_date'])) ?></td>
                  <td class="small"><?= e(t(ucwords(str_replace('_', ' ', $payment['method'])))) ?></td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-secondary btn-icon" title="<?= t('Receipt') ?>"
                       href="<?= BASE_URL ?>views/payments/receipt.php?id=<?= (int) $payment['payment_id'] ?>">
                      <i class="bi bi-printer"></i>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header">
        <span class="card-title-sm"><i class="bi bi-telephone me-1"></i><?= t('Follow-up log') ?></span>
      </div>
      <div class="card-body">

        <form method="POST" class="row g-2 mb-3">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="note">

          <div class="col-md-3">
            <select class="form-select form-select-sm" name="activity_type">
              <option value="call"><?= t('Call') ?></option>
              <option value="sms"><?= t('SMS') ?></option>
              <option value="visit"><?= t('Visit') ?></option>
              <option value="promise"><?= t('Promise') ?></option>
              <option value="note" selected><?= t('Note') ?></option>
            </select>
          </div>

          <div class="col-md-4">
            <input class="form-control form-control-sm" type="text" name="notes" placeholder="<?= t('What happened?') ?>">
          </div>

          <div class="col-md-2">
            <input class="form-control form-control-sm" type="date" name="promise_date" title="<?= t('Promise date') ?>">
          </div>

          <div class="col-md-auto">
            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-plus-lg me-1"></i><?= t('Add') ?></button>
          </div>
        </form>

        <?php if (!$activities): ?>
          <div class="small text-muted"><?= t('No follow-ups recorded yet.') ?></div>
        <?php else: ?>
          <ul class="timeline">
            <?php foreach ($activities as $activity): ?>
              <li>
                <div class="t-title">
                  <?= e(t(ucfirst($activity['activity_type']))) ?>
                  <?php if (!empty($activity['notes'])): ?>
                    — <?= e($activity['notes']) ?>
                  <?php endif; ?>
                </div>
                <div class="t-meta">
                  <?= e($activity['officer_name'] ?? '—') ?> · <?= e(formatDateTime($activity['created_at'])) ?>
                  <?php if ($activity['promise_date']): ?>
                    · <?= t('promised :date', ['date' => formatDate($activity['promise_date'])]) ?>
                  <?php endif; ?>
                  <?php if ($activity['promise_amount']): ?>
                    · <?= e(money($activity['promise_amount'])) ?>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
