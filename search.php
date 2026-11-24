<?php
/**
 * search.php — global search across customers, loans and receipts.
 */

require_once __DIR__ . '/includes/autoload.php';
User::requireLogin();

$q   = trim((string) ($_GET['q'] ?? ''));
$db  = Database::getConnection();

$customers = [];
$loans     = [];
$payments  = [];

if ($q !== '') {
    $like = '%' . $q . '%';

    $stmt = $db->prepare(
        "SELECT id, reference, full_name, phone, nida, data_status, status, photo_path
           FROM customers
          WHERE deleted_at IS NULL
            AND (full_name LIKE :q1 OR phone LIKE :q2 OR reference LIKE :q3 OR nida LIKE :q4)
          ORDER BY full_name
          LIMIT 25"
    );
    $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like]);
    $customers = $stmt->fetchAll();

    $stmt = $db->prepare(
        "SELECT loan_id, loan_reference, customer_id, customer_name, phone_number,
                loan_date, total_amount_payable, amount_paid, remaining_balance, loan_status, days_overdue
           FROM v_loans_with_status
          WHERE loan_reference LIKE :q1 OR customer_name LIKE :q2 OR phone_number LIKE :q3
          ORDER BY loan_id DESC
          LIMIT 25"
    );
    $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like]);
    $loans = $stmt->fetchAll();

    $stmt = $db->prepare(
        "SELECT payment_id, payment_reference, loan_id, loan_reference, customer_id, customer_name,
                payment_date, amount_paid, method, status
           FROM v_payments_with_balance
          WHERE payment_reference LIKE :q1 OR loan_reference LIKE :q2
             OR customer_name LIKE :q3 OR phone_number LIKE :q4
          ORDER BY payment_date DESC, payment_id DESC
          LIMIT 25"
    );
    $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like]);
    $payments = $stmt->fetchAll();
}

$found = count($customers) + count($loans) + count($payments);

$pageTitle = t('Search');
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Search') ?></h4>
    <p>
      <?php if ($q === ''): ?>
        <?= t('Find a customer, loan or receipt by name, phone or reference.') ?>
      <?php else: ?>
        <?= t(':n results for “:q”', ['n' => $found, 'q' => $q]) ?>
      <?php endif; ?>
    </p>
  </div>
</div>

<form class="filter-bar" method="GET" action="<?= BASE_URL ?>search.php">
  <div class="field grow">
    <label for="q"><?= t('Search') ?></label>
    <input class="form-control" type="search" id="q" name="q" value="<?= e($q) ?>" autofocus
           placeholder="<?= t('Name, phone, NIDA, loan or receipt reference') ?>">
  </div>
  <div class="field">
    <label>&nbsp;</label>
    <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i><?= t('Search') ?></button>
  </div>
</form>

<?php if ($q === ''): ?>

  <div class="card">
    <div class="empty-state">
      <i class="bi bi-search"></i>
      <strong><?= t('Start typing to search') ?></strong>
      <p><?= t('The search looks at customers, loans and receipts at the same time.') ?></p>
    </div>
  </div>

<?php elseif ($found === 0): ?>

  <div class="card">
    <div class="empty-state">
      <i class="bi bi-file-earmark-x"></i>
      <strong><?= t('Nothing found for “:q”', ['q' => $q]) ?></strong>
      <p><?= t('Check the spelling, or try a phone number or reference.') ?></p>
    </div>
  </div>

<?php else: ?>

  <?php if ($customers): ?>
  <div class="card mb-3">
    <div class="card-header">
      <span class="card-title-sm"><i class="bi bi-people me-1"></i><?= t('Customers') ?> (<?= count($customers) ?>)</span>
    </div>
    <div class="table-wrap">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th><?= t('Customer') ?></th>
            <th class="d-none d-md-table-cell"><?= t('Phone') ?></th>
            <th class="d-none d-lg-table-cell"><?= t('NIDA') ?></th>
            <th><?= t('Status') ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($customers as $customer): ?>
            <tr>
              <td>
                <a class="name text-decoration-none" href="<?= BASE_URL ?>views/customers/view.php?id=<?= (int) $customer['id'] ?>">
                  <?= e($customer['full_name']) ?>
                </a>
                <span class="sub"><span class="ref"><?= e($customer['reference']) ?></span></span>
              </td>
              <td class="d-none d-md-table-cell"><?= e(formatPhone($customer['phone']) ?: '—') ?></td>
              <td class="d-none d-lg-table-cell small text-muted"><?= e($customer['nida'] ?? '—') ?></td>
              <td><?= statusBadge($customer['status']) ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary btn-icon" title="<?= t('Open') ?>"
                   href="<?= BASE_URL ?>views/customers/view.php?id=<?= (int) $customer['id'] ?>">
                  <i class="bi bi-eye"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($loans): ?>
  <div class="card mb-3">
    <div class="card-header">
      <span class="card-title-sm"><i class="bi bi-cash-coin me-1"></i><?= t('Loans') ?> (<?= count($loans) ?>)</span>
    </div>
    <div class="table-wrap">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th><?= t('Loan') ?></th>
            <th><?= t('Customer') ?></th>
            <th class="d-none d-md-table-cell"><?= t('Issued') ?></th>
            <th class="num"><?= t('Balance') ?></th>
            <th><?= t('Status') ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($loans as $loan): ?>
            <tr>
              <td><span class="ref"><?= e($loan['loan_reference']) ?></span></td>
              <td>
                <a class="name text-decoration-none" href="<?= BASE_URL ?>views/customers/view.php?id=<?= (int) $loan['customer_id'] ?>">
                  <?= e($loan['customer_name']) ?>
                </a>
                <span class="sub"><?= e(formatPhone($loan['phone_number']) ?: t('no phone')) ?></span>
              </td>
              <td class="d-none d-md-table-cell"><?= e(formatDate($loan['loan_date'])) ?></td>
              <td class="num fw-semibold"><?= e(num($loan['remaining_balance'])) ?></td>
              <td><?= loanBadge($loan['loan_status']) ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary btn-icon" title="<?= t('Open') ?>"
                   href="<?= BASE_URL ?>views/loans/view.php?id=<?= (int) $loan['loan_id'] ?>">
                  <i class="bi bi-eye"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($payments): ?>
  <div class="card">
    <div class="card-header">
      <span class="card-title-sm"><i class="bi bi-receipt me-1"></i><?= t('Receipts') ?> (<?= count($payments) ?>)</span>
    </div>
    <div class="table-wrap">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th><?= t('Receipt') ?></th>
            <th><?= t('Customer') ?></th>
            <th class="d-none d-md-table-cell"><?= t('Loan') ?></th>
            <th class="d-none d-md-table-cell"><?= t('Date') ?></th>
            <th class="num"><?= t('Amount') ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $payment): ?>
            <tr>
              <td><span class="ref"><?= e($payment['payment_reference']) ?></span></td>
              <td><?= e($payment['customer_name']) ?></td>
              <td class="d-none d-md-table-cell"><span class="ref"><?= e($payment['loan_reference']) ?></span></td>
              <td class="d-none d-md-table-cell"><?= e(formatDate($payment['payment_date'])) ?></td>
              <td class="num fw-semibold"><?= e(num($payment['amount_paid'])) ?></td>
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
  </div>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
