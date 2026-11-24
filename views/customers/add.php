<?php
/**
 * views/customers/add.php — register a customer.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$errors   = [];
$customer = null;
$isEdit   = false;

if (isPost()) {
    try {
        $id = (new Customer())->create(
            $_POST,
            $_POST['photo_data'] ?? null,
            $_FILES['photo_file'] ?? null
        );

        clearOld();

        $created = (new Customer())->find($id);
        flash('success', t('Customer :ref registered successfully.', ['ref' => $created['reference'] ?? '']));

        redirect(BASE_URL . 'views/customers/view.php?id=' . $id);
    } catch (ValidationException $e) {
        $errors = $e->errors();
        setOld($_POST);
    } catch (Throwable $e) {
        $errors = [$e->getMessage()];
        setOld($_POST);
    }
}

$officers  = User::isAdmin() ? (new User())->officers() : [];
$pageTitle = t('Register customer');
$pageScripts = [
    'assets/vendor/qrcode.js',
    'assets/js/image-editor.js',
    'assets/js/identity-capture.js',
];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Register customer') ?></h4>
    <p><?= t('Three names, NIDA number, phone and a passport photo. That is all you need.') ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/customers/index.php">
      <i class="bi bi-arrow-left me-1"></i><?= t('Back to customers') ?>
    </a>
  </div>
</div>

<?php require __DIR__ . '/_form.php'; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
