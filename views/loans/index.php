<?php
/**
 * views/loans/index.php — the loan book.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$loanModel = new Loan();

$filters = [
    'q'      => trim((string) ($_GET['q'] ?? '')),
    'status' => (string) ($_GET['status'] ?? ''),
    'from'   => (string) ($_GET['from'] ?? ''),
    'to'     => (string) ($_GET['to'] ?? ''),
];

$result = $loanModel->paginate($filters, (int) ($_GET['page'] ?? 1), 20);

$sumPayable = 0.0;
$sumPaid    = 0.0;
$sumBalance = 0.0;

foreach ($result['rows'] as $row) {
    $sumPayable += (float) $row['total_amount_payable'];
    $sumPaid    += (float) $row['amount_paid'];
    $sumBalance += (float) $row['remaining_balance'];
}

$pageTitle = t('Loans');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Loans') ?></h4>
    <p><?= t(':n loans match this view', ['n' => $result['total']]) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/loans/add.php">
      <i class="bi bi-plus-circle me-1"></i><?= t('New loan') ?>
    </a>
  </div>
</div>

<form class="filter-bar" method="GET" action="<?= BASE_URL ?>views/loans/index.php">
  <div class="field grow">
    <label for="q"><?= t('Search') ?></label>
    <input class="form-control" type="search" id="q" name="q" value="<?= e($filters['q']) ?>"
           placeholder="<?= t('Loan reference, customer or phone') ?>">
  </div>

  <div class="field">
    <label for="status"><?= t('Status') ?></label>
    <select class="form-select" id="status" name="status" data-auto-submit>
      <option value=""><?= t('All') ?></option>
      <option value="OPEN" <?= $filters['status'] === 'OPEN' ? 'selected' : '' ?>><?= t('Open (active, due, overdue)') ?></option>
      <?php foreach (['ACTIVE', 'DUE', 'OVERDUE', 'PAID', 'OVERPAID', 'WRITTEN OFF'] as $status): ?>
        <option value="<?= $status ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(t(ucwords(strtolower($status)))) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="from"><?= t('Issued from') ?></label>
    <input class="form-control" type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
  </div>

  <div class="field">
    <label for="to"><?= t('to') ?></label>
    <input class="form-control" type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
  </div>

  <div class="field">
    <label>&nbsp;</label>
    <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i><?= t('Filter') ?></button>
  </div>

  <?php if (array_filter($filters)): ?>
  <div class="field">
    <label>&nbsp;</label>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>views/loans/index.php"><?= t('Clear') ?></a>
  </div>
  <?php endif; ?>
</form>

<?php if ($result['rows']): ?>
<div class="kpi-grid">
  <div class="kpi">
    <div class="k-icon"><i class="bi bi-file-earmark-text"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Payable (this view)') ?></div>
      <div class="k-value"><?= e(money($sumPayable)) ?></div>
    </div>
  </div>
  <div class="kpi">
    <div class="k-icon"><i class="bi bi-check2-circle"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Collected (this view)') ?></div>
      <div class="k-value"><?= e(money($sumPaid)) ?></div>
    </div>
  </div>
  <div class="kpi gold">
    <div class="k-icon"><i class="bi bi-hourglass-split"></i></div>
    <div class="k-body">
      <div class="k-label"><?= t('Balance (this view)') ?></div>
      <div class="k-value"><?= e(money($sumBalance)) ?></div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <?php if (empty($result['rows'])): ?>
    <div class="empty-state">
      <i class="bi bi-journal-x"></i>
      <strong><?= t('No loans match this filter') ?></strong>
      <p><?= t('Try a different search, or issue a new loan.') ?></p>
      <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/loans/add.php"><?= t('New loan') ?></a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th><?= t('Loan') ?></th>
            <th><?= t('Customer') ?></th>
            <th class="d-none d-md-table-cell"><?= t('Issued') ?></th>
            <th class="num d-none d-lg-table-cell"><?= t('Weekly') ?></th>
            <th class="num"><?= t('Balance') ?></th>
            <th class="d-none d-md-table-cell" style="min-width:120px"><?= t('Progress') ?></th>
            <th><?= t('Status') ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($result['rows'] as $loan):
              $progress = percent($loan['amount_paid'], $loan['total_amount_payable']);
          ?>
            <tr class="<?= $loan['loan_status'] === 'OVERDUE' ? 'row-danger' : '' ?>">
              <td>
                <a class="ref text-decoration-none" href="<?= BASE_URL ?>views/loans/view.php?id=<?= (int) $loan['loan_id'] ?>">
                  <?= e($loan['loan_reference']) ?>
                </a>
                <span class="sub"><?= (int) $loan['number_of_weeks'] ?> <?= t('weeks') ?></span>
              </td>
              <td>
                <span class="name"><?= e($loan['customer_name']) ?></span>
                <span class="sub"><?= e(formatPhone($loan['phone_number']) ?: t('no phone')) ?></span>
              </td>
              <td class="d-none d-md-table-cell"><?= e(formatDate($loan['loan_date'])) ?></td>
              <td class="num d-none d-lg-table-cell"><?= e(num($loan['weekly_amount'])) ?></td>
              <td class="num fw-semibold"><?= e(num($loan['remaining_balance'])) ?></td>
              <td class="d-none d-md-table-cell">
                <div class="progress-thin <?= $loan['loan_status'] === 'OVERDUE' ? 'red' : ($progress >= 100 ? 'gold' : '') ?>">
                  <span style="width: <?= min(100, round($progress, 1)) ?>%"></span>
                </div>
                <span class="sub mt-1"><?= $progress ?>% · <?= (int) $loan['weeks_paid'] ?>/<?= (int) $loan['number_of_weeks'] ?> <?= t('weeks') ?></span>
              </td>
              <td>
                <?= loanBadge($loan['loan_status']) ?>
                <?php if ((int) $loan['days_overdue'] > 0): ?>
                  <span class="sub"><?= (int) $loan['days_overdue'] ?> <?= t('days late') ?></span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <div class="table-actions">
                  <a class="btn btn-sm btn-outline-primary btn-icon" title="<?= t('Record payment') ?>"
                     href="<?= BASE_URL ?>views/payments/add.php?loan_id=<?= (int) $loan['loan_id'] ?>">
                    <i class="bi bi-cash"></i>
                  </a>
                  <a class="btn btn-sm btn-outline-secondary btn-icon" title="<?= t('Open') ?>"
                     href="<?= BASE_URL ?>views/loans/view.php?id=<?= (int) $loan['loan_id'] ?>">
                    <i class="bi bi-eye"></i>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?= pagination($result['page'], $result['pages'], BASE_URL . 'views/loans/index.php') ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
