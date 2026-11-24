<?php
/**
 * views/overdue/index.php — overdue work list with follow-up actions.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$db = Database::getConnection();

if (isPost()) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'log') {
        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $type   = in_array($_POST['activity_type'] ?? 'call', ['call', 'sms', 'visit', 'note', 'promise'], true)
            ? $_POST['activity_type'] : 'note';

        try {
            if ($loanId <= 0) {
                throw new RuntimeException(t('Select a valid loan.'));
            }

            $db->prepare(
                "INSERT INTO collection_activities
                    (loan_id, officer_id, activity_type, notes, promise_date, promise_amount)
                 VALUES
                    (:loan_id, :officer, :type, :notes, :promise_date, :promise_amount)"
            )->execute([
                'loan_id'        => $loanId,
                'officer'        => currentUserId(),
                'type'           => $type,
                'notes'          => trim((string) ($_POST['notes'] ?? '')),
                'promise_date'   => !empty($_POST['promise_date']) ? Security::safeDate($_POST['promise_date']) : null,
                'promise_amount' => !empty($_POST['promise_amount']) ? (float) $_POST['promise_amount'] : null,
            ]);

            AuditLog::record('loan.followup', 'loans', $loanId, 'Overdue follow-up logged (' . $type . ')');
            flash('success', t('Follow-up saved.'));
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
    }

    redirect(BASE_URL . 'views/overdue/index.php' . queryWith([]));
}

$filters = [
    'q'      => trim((string) ($_GET['q'] ?? '')),
    'bucket' => (string) ($_GET['bucket'] ?? ''),
    'officer' => (string) ($_GET['officer'] ?? ''),
];

$where  = ['1=1'];
$params = [];

if ($filters['q'] !== '') {
    $where[] = "(customer_name LIKE :q OR phone_number LIKE :q OR loan_reference LIKE :q)";
    $params['q'] = '%' . $filters['q'] . '%';
}

if ($filters['officer'] !== '') {
    $where[] = "customer_id IN (SELECT id FROM customers WHERE assigned_officer_id = :officer)";
    $params['officer'] = (int) $filters['officer'];
}

switch ($filters['bucket']) {
    case '1-7':
        $where[] = "days_overdue BETWEEN 1 AND 7";
        break;
    case '8-14':
        $where[] = "days_overdue BETWEEN 8 AND 14";
        break;
    case '15-30':
        $where[] = "days_overdue BETWEEN 15 AND 30";
        break;
    case '31-60':
        $where[] = "days_overdue BETWEEN 31 AND 60";
        break;
    case '60+':
        $where[] = "days_overdue > 60";
        break;
}

$sql = "SELECT v.*,
               (SELECT MAX(p.paid_at) FROM payments p
                 WHERE p.loan_id = v.loan_id AND p.status = 'confirmed') AS last_payment_at,
               (SELECT a.activity_type FROM collection_activities a
                 WHERE a.loan_id = v.loan_id ORDER BY a.id DESC LIMIT 1) AS last_activity
          FROM v_overdue_loans v
         WHERE " . implode(' AND ', $where) . "
         ORDER BY v.days_overdue DESC, v.remaining_balance DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalBalance = 0.0;
$totalDays    = 0;
$worst        = 0;

foreach ($rows as $row) {
    $totalBalance += (float) $row['remaining_balance'];
    $totalDays    += (int) $row['days_overdue'];
    $worst         = max($worst, (int) $row['days_overdue']);
}

$avgDays  = $rows ? round($totalDays / count($rows), 1) : 0;
$officers = User::isAdmin() ? (new User())->officers() : [];

$pageTitle = t('Overdue loans');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Overdue loans') ?></h4>
    <p><?= t(':n loans need follow-up today', ['n' => count($rows)]) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/reports/arrears.php">
      <i class="bi bi-bar-chart me-1"></i><?= t('Arrears report') ?>
    </a>
    <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/payments/add.php">
      <i class="bi bi-cash me-1"></i><?= t('Record payment') ?>
    </a>
  </div>
</div>

<div class="kpi-grid">
  <div class="kpi red">
    <div class="k-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Overdue balance') ?></div>
      <div class="k-value"><?= e(money($totalBalance)) ?></div>
      <div class="k-hint"><?= t('across :n loans', ['n' => count($rows)]) ?></div>
    </div>
  </div>
  <div class="kpi gold">
    <div class="k-icon"><i class="bi bi-clock-history"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Average days late') ?></div>
      <div class="k-value"><?= e((string) $avgDays) ?></div>
      <div class="k-hint"><?= t('days') ?></div>
    </div>
  </div>
  <div class="kpi ink">
    <div class="k-icon"><i class="bi bi-calendar-x"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Oldest arrears') ?></div>
      <div class="k-value"><?= $worst ?> <?= t('days') ?></div>
      <div class="k-hint"><?= t('worst case in this view') ?></div>
    </div>
  </div>
</div>

<form class="filter-bar" method="GET" action="<?= BASE_URL ?>views/overdue/index.php">
  <div class="field grow">
    <label for="q"><?= t('Search') ?></label>
    <input class="form-control" type="search" id="q" name="q" value="<?= e($filters['q']) ?>"
           placeholder="<?= t('Customer, phone or loan') ?>">
  </div>

  <div class="field">
    <label for="bucket"><?= t('Ageing') ?></label>
    <select class="form-select" id="bucket" name="bucket" data-auto-submit>
      <option value=""><?= t('All overdue') ?></option>
      <?php foreach (['1-7' => '1–7 days', '8-14' => '8–14 days', '15-30' => '15–30 days', '31-60' => '31–60 days', '60+' => 'Over 60 days'] as $key => $label): ?>
        <option value="<?= $key ?>" <?= $filters['bucket'] === $key ? 'selected' : '' ?>><?= e(t($label)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if (User::isAdmin()): ?>
  <div class="field">
    <label for="officer"><?= t('Officer') ?></label>
    <select class="form-select" id="officer" name="officer" data-auto-submit>
      <option value=""><?= t('All') ?></option>
      <?php foreach ($officers as $officer): ?>
        <option value="<?= (int) $officer['id'] ?>" <?= (string) $officer['id'] === $filters['officer'] ? 'selected' : '' ?>>
          <?= e($officer['full_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <div class="field">
    <label>&nbsp;</label>
    <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i><?= t('Filter') ?></button>
  </div>

  <?php if (array_filter($filters)): ?>
  <div class="field">
    <label>&nbsp;</label>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>views/overdue/index.php"><?= t('Clear') ?></a>
  </div>
  <?php endif; ?>
</form>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty-state">
      <i class="bi bi-emoji-smile"></i>
      <strong><?= t('No overdue loans in this view') ?></strong>
      <p><?= t('Either every customer is up to date, or the filter is too narrow.') ?></p>
      <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/overdue/index.php"><?= t('Show all overdue') ?></a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th><?= t('Customer') ?></th>
            <th class="d-none d-lg-table-cell"><?= t('Loan') ?></th>
            <th class="num d-none d-md-table-cell"><?= t('Weekly') ?></th>
            <th class="num"><?= t('Balance') ?></th>
            <th class="num"><?= t('Days late') ?></th>
            <th class="d-none d-xl-table-cell"><?= t('Last payment') ?></th>
            <th><?= t('Follow-up') ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr class="row-danger">
              <td>
                <a class="name text-decoration-none" href="<?= BASE_URL ?>views/customers/view.php?id=<?= (int) $row['customer_id'] ?>">
                  <?= e($row['customer_name']) ?>
                </a>
                <span class="sub"><?= e(formatPhone($row['phone_number']) ?: t('no phone')) ?></span>
              </td>
              <td class="d-none d-lg-table-cell">
                <a class="ref text-decoration-none" href="<?= BASE_URL ?>views/loans/view.php?id=<?= (int) $row['loan_id'] ?>">
                  <?= e($row['loan_reference']) ?>
                </a>
                <span class="sub"><?= (int) $row['weeks_paid'] ?>/<?= (int) $row['number_of_weeks'] ?> <?= t('weeks paid') ?></span>
              </td>
              <td class="num d-none d-md-table-cell"><?= e(num($row['weekly_amount'])) ?></td>
              <td class="num fw-semibold"><?= e(money($row['remaining_balance'])) ?></td>
              <td class="num"><span class="pill pill-red"><?= (int) $row['days_overdue'] ?></span></td>
              <td class="d-none d-xl-table-cell small text-muted">
                <?= e(formatDate($row['last_payment_at'], t('never'))) ?>
                <?php if (!empty($row['last_activity'])): ?>
                  <span class="sub"><?= e(t(ucfirst($row['last_activity']))) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <div class="table-actions">
                  <?php if (!empty($row['phone_number'])): ?>
                    <a class="btn btn-sm btn-outline-secondary btn-icon" title="<?= t('Call') ?>"
                       href="tel:<?= e(normalisePhone($row['phone_number'])) ?>">
                      <i class="bi bi-telephone"></i>
                    </a>
                    <a class="btn btn-sm btn-outline-secondary btn-icon" title="<?= t('SMS') ?>"
                       href="sms:<?= e(normalisePhone($row['phone_number'])) ?>">
                      <i class="bi bi-chat-dots"></i>
                    </a>
                  <?php endif; ?>
                  <button class="btn btn-sm btn-outline-primary btn-icon" type="button" title="<?= t('Log follow-up') ?>"
                          data-bs-toggle="modal" data-bs-target="#followUpModal"
                          data-loan-id="<?= (int) $row['loan_id'] ?>"
                          data-loan-ref="<?= e($row['loan_reference']) ?>"
                          data-customer="<?= e($row['customer_name']) ?>"
                          data-balance="<?= e(money($row['remaining_balance'])) ?>">
                    <i class="bi bi-journal-plus"></i>
                  </button>
                  <a class="btn btn-sm btn-outline-primary btn-icon" title="<?= t('Record payment') ?>"
                     href="<?= BASE_URL ?>views/payments/add.php?loan_id=<?= (int) $row['loan_id'] ?>">
                    <i class="bi bi-cash"></i>
                  </a>
                </div>
              </td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary btn-icon" title="<?= t('Open loan') ?>"
                   href="<?= BASE_URL ?>views/loans/view.php?id=<?= (int) $row['loan_id'] ?>">
                  <i class="bi bi-eye"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="modal fade" id="followUpModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="POST" action="<?= BASE_URL ?>views/overdue/index.php">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="log">
      <input type="hidden" name="loan_id" id="followLoanId" value="">

      <div class="modal-header">
        <h5 class="modal-title"><?= t('Log follow-up') ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button>
      </div>

      <div class="modal-body">
        <div class="alert alert-light border small mb-3" id="followSummary"></div>

        <div class="row g-2">
          <div class="col-md-5">
            <label class="form-label" for="followType"><?= t('Type') ?></label>
            <select class="form-select" id="followType" name="activity_type">
              <option value="call"><?= t('Call') ?></option>
              <option value="sms"><?= t('SMS') ?></option>
              <option value="visit"><?= t('Visit') ?></option>
              <option value="promise"><?= t('Promise') ?></option>
              <option value="note" selected><?= t('Note') ?></option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="followPromiseDate"><?= t('Promise date') ?></label>
            <input class="form-control" type="date" id="followPromiseDate" name="promise_date">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="followPromiseAmount"><?= t('Amount') ?></label>
            <input class="form-control" type="number" id="followPromiseAmount" name="promise_amount" min="0" step="1000">
          </div>
          <div class="col-12">
            <label class="form-label required" for="followNotes"><?= t('What happened?') ?></label>
            <textarea class="form-control" id="followNotes" name="notes" rows="3" required
                      placeholder="<?= t('e.g. promised to pay on Friday') ?>"></textarea>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= t('Save follow-up') ?></button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('followUpModal')?.addEventListener('show.bs.modal', function (event) {
  const button = event.relatedTarget;
  if (!button) return;
  document.getElementById('followLoanId').value = button.dataset.loanId || '';
  document.getElementById('followSummary').innerHTML =
    '<strong>' + (button.dataset.customer || '') + '</strong> · ' +
    (button.dataset.loanRef || '') + ' · ' + (button.dataset.balance || '');
  document.getElementById('followNotes').value = '';
  document.getElementById('followPromiseDate').value = '';
  document.getElementById('followPromiseAmount').value = '';
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
