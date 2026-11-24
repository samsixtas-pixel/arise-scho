<?php
/**
 * views/customers/index.php — customer register.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$customerModel = new Customer();

$filters = [
    'q'           => trim((string) ($_GET['q'] ?? '')),
    'status'      => (string) ($_GET['status'] ?? ''),
    'data_status' => (string) ($_GET['data_status'] ?? ''),
    'officer'     => (string) ($_GET['officer'] ?? ''),
];

$result   = $customerModel->paginate($filters, (int) ($_GET['page'] ?? 1), 20);
$officers = (new User())->officers();

$pageTitle = t('Customers');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Customers') ?></h4>
    <p><?= t(':n customers in the register', ['n' => $result['total']]) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/customers/add.php">
      <i class="bi bi-person-plus me-1"></i><?= t('Register customer') ?>
    </a>
  </div>
</div>

<form class="filter-bar" method="GET" action="<?= BASE_URL ?>views/customers/index.php">
  <div class="field grow">
    <label for="q"><?= t('Search') ?></label>
    <input class="form-control" type="search" id="q" name="q" value="<?= e($filters['q']) ?>"
           placeholder="<?= t('Name, phone, NIDA or reference') ?>">
  </div>

  <div class="field">
    <label for="status"><?= t('Status') ?></label>
    <select class="form-select" id="status" name="status" data-auto-submit>
      <option value=""><?= t('All') ?></option>
      <?php foreach (['active', 'inactive', 'blacklisted'] as $status): ?>
        <option value="<?= $status ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(t(ucfirst($status))) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="data_status"><?= t('Record') ?></label>
    <select class="form-select" id="data_status" name="data_status" data-auto-submit>
      <option value=""><?= t('All') ?></option>
      <option value="complete" <?= $filters['data_status'] === 'complete' ? 'selected' : '' ?>><?= t('Complete') ?></option>
      <option value="incomplete" <?= $filters['data_status'] === 'incomplete' ? 'selected' : '' ?>><?= t('Incomplete') ?></option>
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
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>views/customers/index.php"><?= t('Clear') ?></a>
  </div>
  <?php endif; ?>
</form>

<div class="card">
  <?php if (empty($result['rows'])): ?>
    <div class="empty-state">
      <i class="bi bi-people"></i>
      <strong><?= t('No customers match this filter') ?></strong>
      <p><?= t('Adjust the search, or register a new customer.') ?></p>
      <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/customers/add.php"><?= t('Register customer') ?></a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th><?= t('Customer') ?></th>
            <th class="d-none d-md-table-cell"><?= t('Phone') ?></th>
            <th class="d-none d-lg-table-cell"><?= t('Officer') ?></th>
            <th class="num"><?= t('Loans') ?></th>
            <th class="num"><?= t('Outstanding') ?></th>
            <th><?= t('Status') ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($result['rows'] as $customer):
              $photo = PhotoStorage::thumbUrl($customer['photo_path']);
          ?>
            <tr>
              <td>
                <div class="customer-cell">
                  <?php if ($photo): ?>
                    <img class="avatar-photo" src="<?= e($photo) ?>" alt="">
                  <?php else: ?>
                    <span class="avatar-fallback"><?= e(initials($customer['full_name'])) ?></span>
                  <?php endif; ?>
                  <div>
                    <a class="name text-decoration-none" href="<?= BASE_URL ?>views/customers/view.php?id=<?= (int) $customer['id'] ?>">
                      <?= e($customer['full_name']) ?>
                    </a>
                    <span class="sub">
                      <span class="ref"><?= e($customer['reference']) ?></span>
                      <?php if ($customer['data_status'] === 'incomplete'): ?>
                        · <span class="pill pill-gold"><?= t('incomplete') ?></span>
                      <?php endif; ?>
                    </span>
                  </div>
                </div>
              </td>
              <td class="d-none d-md-table-cell">
                <?= e(formatPhone($customer['phone']) ?: '—') ?>
                <?php if (!$customer['phone']): ?><span class="sub text-warning"><?= t('missing') ?></span><?php endif; ?>
              </td>
              <td class="d-none d-lg-table-cell small text-muted"><?= e($customer['officer_name'] ?? '—') ?></td>
              <td class="num"><?= (int) $customer['loan_count'] ?></td>
              <td class="num <?= (float) $customer['outstanding'] > 0 ? 'fw-semibold' : 'text-muted' ?>">
                <?= e(money($customer['outstanding'])) ?>
              </td>
              <td>
                <?= statusBadge($customer['status']) ?>
                <?php if ((int) $customer['overdue_count'] > 0): ?>
                  <span class="pill pill-red ms-1"><?= (int) $customer['overdue_count'] ?> <?= t('late') ?></span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <div class="table-actions">
                  <a class="btn btn-sm btn-outline-secondary btn-icon" title="<?= t('View') ?>"
                     href="<?= BASE_URL ?>views/customers/view.php?id=<?= (int) $customer['id'] ?>">
                    <i class="bi bi-eye"></i>
                  </a>
                  <a class="btn btn-sm btn-outline-primary btn-icon" title="<?= t('Edit') ?>"
                     href="<?= BASE_URL ?>views/customers/edit.php?id=<?= (int) $customer['id'] ?>">
                    <i class="bi bi-pencil"></i>
                  </a>
                  <a class="btn btn-sm btn-outline-primary btn-icon" title="<?= t('New loan') ?>"
                     href="<?= BASE_URL ?>views/loans/add.php?customer_id=<?= (int) $customer['id'] ?>">
                    <i class="bi bi-cash-coin"></i>
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

<?= pagination($result['page'], $result['pages'], BASE_URL . 'views/customers/index.php') ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
