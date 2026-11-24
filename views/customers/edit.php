<?php
/**
 * views/customers/edit.php — update a customer record.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$customerModel = new Customer();
$id            = (int) ($_GET['id'] ?? 0);
$customer      = $customerModel->findDetailed($id);

if (!$customer) {
    flash('error', t('Customer not found.'));
    redirect(BASE_URL . 'views/customers/index.php');
}

$errors = [];
$isEdit = true;

if (isPost()) {
    try {
        $customerModel->update(
            $id,
            array_merge($_POST, ['id' => $id]),
            $_POST['photo_data'] ?? null,
            $_FILES['photo_file'] ?? null
        );

        clearOld();
        flash('success', t('Customer record updated.'));
        redirect(BASE_URL . 'views/customers/view.php?id=' . $id);
    } catch (ValidationException $e) {
        $errors   = $e->errors();
        $customer = array_merge($customer, $_POST);
        setOld($_POST);
    } catch (Throwable $e) {
        $errors = [$e->getMessage()];
        setOld($_POST);
    }
}

$officers  = User::isAdmin() ? (new User())->officers() : [];
$pageTitle = t('Edit customer');
$pageScripts = [
    'assets/vendor/qrcode.js',
    'assets/js/image-editor.js',
    'assets/js/identity-capture.js',
];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Edit customer') ?> · <span class="ref"><?= e($customer['reference']) ?></span></h4>
    <p><?= e($customer['full_name']) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/customers/view.php?id=<?= $id ?>">
      <i class="bi bi-eye me-1"></i><?= t('View profile') ?>
    </a>
  </div>
</div>

<?php if ($customer['data_status'] === 'incomplete'): ?>
  <div class="alert alert-success d-flex gap-2 align-items-start">
    <i class="bi bi-info-circle mt-1"></i>
    <div class="small">
      <?= t('This record came from the imported workbook and is missing its NIDA number, phone or photo. Add them here when the customer visits.') ?>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/_form.php'; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
