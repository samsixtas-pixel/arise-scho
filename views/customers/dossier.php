<?php
/**
 * views/customers/dossier.php — on-demand printable customer dossier.
 * -------------------------------------------------------
 * Documents are never merged into a permanent PDF. This page
 * assembles the selected documents only when an authorised
 * officer asks for them, and the browser prints/saves the PDF.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$customerModel = new Customer();
$documentModel = new CustomerDocument();
$loanModel     = new Loan();
$id            = (int) ($_GET['id'] ?? 0);
$customer      = $customerModel->findDetailed($id);

if (!$customer) {
    flash('error', t('Customer not found.'));
    redirect(BASE_URL . 'views/customers/index.php');
}

$documents = $documentModel->forCustomer($id);

// Selection: ?docs[]=1&docs[]=2 (or docs=1,2). No submitted flag means "all".
$requested = $_GET['docs'] ?? null;
$submitted = !empty($_GET['submitted']);
$selectedIds = [];

if (is_array($requested)) {
    $selectedIds = array_map('intval', $requested);
} elseif (is_string($requested) && $requested !== '') {
    $selectedIds = array_map('intval', explode(',', $requested));
} elseif (!$submitted) {
    $selectedIds = array_map('intval', array_column($documents, 'id'));
}

$selectedIds = array_values(array_unique(array_filter($selectedIds)));

$selected = array_values(array_filter(
    $documents,
    static fn(array $doc): bool => in_array((int) $doc['id'], $selectedIds, true)
));

$loans   = $loanModel->forCustomer($id);
$portrait = null;
foreach ($selected as $doc) {
    if ($doc['document_category'] === 'portrait') {
        $portrait = $doc;
        break;
    }
}

$pdfTitle    = t('Customer dossier');
$pdfSubtitle = $customer['reference'] . ' · ' . $customer['full_name'];

AuditLog::record('customer_dossier.generate', 'customers', $id,
    'Generated dossier with ' . count($selected) . ' document(s)');

require_once __DIR__ . '/../../includes/pdf_header.php';
?>

<style>
  .dossier-picker {
    border: 1px solid #d8e6e0;
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 18px;
    background: #f7fbf9;
  }
  .dossier-picker h3 {
    margin: 0 0 8px;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #0D6E5D;
  }
  .dossier-checks {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 4px 14px;
    font-size: 12px;
  }
  .dossier-checks label { display: flex; gap: 6px; align-items: flex-start; }
  .dossier-actions { margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; }
  .dossier-actions button, .dossier-actions a {
    padding: 6px 12px; border-radius: 5px; border: 1px solid #0D6E5D;
    background: #0D6E5D; color: #fff; font-size: 12px; text-decoration: none; cursor: pointer;
  }
  .dossier-actions a { background: #fff; color: #0D6E5D; }

  .dossier-summary {
    display: grid;
    grid-template-columns: 1fr 120px;
    gap: 18px;
    align-items: start;
  }
  .dossier-portrait {
    width: 120px; height: 150px; object-fit: cover;
    border: 1px solid #ddd; border-radius: 6px;
  }
  .dossier-portrait-fallback {
    width: 120px; height: 150px; display: grid; place-items: center;
    border: 1px dashed #ccc; border-radius: 6px; color: #999; font-size: 11px; text-align: center;
  }

  .dossier-doc {
    border: 1px solid #d8e6e0;
    border-radius: 6px;
    padding: 10px;
    margin: 14px 0;
    page-break-inside: avoid;
  }
  .dossier-doc-head {
    display: flex; justify-content: space-between; gap: 10px;
    margin-bottom: 8px; font-size: 12px;
  }
  .dossier-doc-head strong { color: #0D6E5D; }
  .dossier-doc img {
    width: 100%; max-height: 120mm; object-fit: contain;
    display: block; border: 1px solid #eee; background: #fafafa;
  }

  @media print {
    .dossier-picker { display: none !important; }
    .dossier-doc { break-inside: avoid; }
  }
</style>

<form method="GET" class="dossier-picker no-print">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="submitted" value="1">
  <h3><?= t('Choose documents for this dossier') ?></h3>

  <?php if (!$documents): ?>
    <p class="small" style="margin:0;color:#666"><?= t('No documents on file. Add documents from the customer profile first.') ?></p>
  <?php else: ?>
    <div class="dossier-checks">
      <?php foreach ($documents as $doc): ?>
        <label>
          <input type="checkbox" name="docs[]" value="<?= (int) $doc['id'] ?>"
                 <?= in_array((int) $doc['id'], $selectedIds, true) ? 'checked' : '' ?>>
          <span><?= e(CustomerDocument::label($doc['document_category'])) ?>
            <span style="color:#777">· v<?= (int) $doc['version_number'] ?></span>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="dossier-actions">
      <button type="submit"><?= t('Update selection') ?></button>
      <a href="<?= BASE_URL ?>views/customers/view.php?id=<?= $id ?>&tab=documents"><?= t('Back to customer file') ?></a>
    </div>
  <?php endif; ?>
</form>

<div class="dossier-summary">
  <div>
    <div class="section-title" style="margin-top:0"><?= t('Customer identity') ?></div>
    <table class="tbl">
      <tr><th style="width:34%"><?= t('Full name') ?></th><td><?= e($customer['full_name']) ?></td></tr>
      <tr><th><?= t('Reference') ?></th><td><?= e($customer['reference']) ?></td></tr>
      <tr><th><?= t('Gender') ?></th><td><?= e($customer['gender'] ? t(ucfirst($customer['gender'])) : '—') ?></td></tr>
      <tr><th><?= t('NIDA') ?></th><td><?= e($customer['nida'] ?: '—') ?></td></tr>
      <tr><th><?= t('Phone') ?></th><td><?= e($customer['phone'] ? formatPhone($customer['phone']) : '—') ?></td></tr>
      <tr><th><?= t('Alternate phone') ?></th><td><?= e($customer['phone_alt'] ? formatPhone($customer['phone_alt']) : '—') ?></td></tr>
      <tr><th><?= t('Emergency contact') ?></th><td><?= e($customer['emergency_contact'] ?: '—') ?></td></tr>
      <tr><th><?= t('Officer') ?></th><td><?= e($customer['officer_name'] ?? '—') ?></td></tr>
      <tr><th><?= t('Registered') ?></th><td><?= e(formatDate($customer['registered_at'])) ?></td></tr>
    </table>
  </div>

  <div style="text-align:center">
    <?php if ($portrait): ?>
      <img class="dossier-portrait" src="<?= e(CustomerDocument::url((int) $portrait['id'], 'full')) ?>"
           alt="<?= e($customer['full_name']) ?>">
    <?php elseif ($customer['photo_path']): ?>
      <img class="dossier-portrait" src="<?= e(PhotoStorage::url($customer['photo_path'])) ?>"
           alt="<?= e($customer['full_name']) ?>">
    <?php else: ?>
      <div class="dossier-portrait-fallback"><?= t('No portrait on file') ?></div>
    <?php endif; ?>
    <div style="font-size:10px;color:#777;margin-top:4px"><?= t('Customer portrait') ?></div>
  </div>
</div>

<div class="section-title"><?= t('Loan summary') ?></div>
<?php if (!$loans): ?>
  <p class="small"><?= t('No loans issued to this customer.') ?></p>
<?php else: ?>
  <table class="tbl">
    <thead>
      <tr>
        <th><?= t('Loan') ?></th>
        <th><?= t('Issued') ?></th>
        <th><?= t('Payable') ?></th>
        <th><?= t('Paid') ?></th>
        <th><?= t('Balance') ?></th>
        <th><?= t('Status') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($loans as $loan): ?>
        <tr>
          <td><?= e($loan['loan_reference']) ?></td>
          <td><?= e(formatDate($loan['loan_date'])) ?></td>
          <td><?= e(money($loan['total_amount_payable'])) ?></td>
          <td><?= e(money($loan['amount_paid'])) ?></td>
          <td><?= e(money($loan['remaining_balance'])) ?></td>
          <td><?= e(t(ucwords(strtolower((string) $loan['loan_status'])))) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<div class="section-title"><?= t('Documents') ?></div>

<?php if (!$selected): ?>
  <p class="small"><?= t('No documents selected.') ?></p>
<?php else: ?>
  <?php foreach ($selected as $doc): ?>
    <div class="dossier-doc">
      <div class="dossier-doc-head">
        <div>
          <strong><?= e(CustomerDocument::label($doc['document_category'])) ?></strong>
          <div style="color:#666;font-size:11px">
            <?= t('Version :n', ['n' => (int) $doc['version_number']]) ?>
            · <?= t('Uploaded :date', ['date' => formatDate($doc['uploaded_at'])]) ?>
            <?php if (!empty($doc['uploaded_by_name'])): ?>
              · <?= e($doc['uploaded_by_name']) ?>
            <?php endif; ?>
          </div>
        </div>
        <div style="text-align:right;color:#666;font-size:11px">
          <?php if (!empty($doc['issue_date'])): ?>
            <?= t('Issued :date', ['date' => formatDate($doc['issue_date'])]) ?><br>
          <?php endif; ?>
          <?php if (!empty($doc['expiry_date'])): ?>
            <?= t('Expires :date', ['date' => formatDate($doc['expiry_date'])]) ?>
          <?php endif; ?>
        </div>
      </div>
      <img src="<?= e(CustomerDocument::url((int) $doc['id'], 'full')) ?>"
           alt="<?= e(CustomerDocument::label($doc['document_category'])) ?>">
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="section-title"><?= t('Verification note') ?></div>
<p style="font-size:11.5px;color:#444;line-height:1.6;margin:0">
  <?= t('This dossier is a compilation of images supplied by the customer and stored by the loan officer. It is not a certificate of identity, and OCR or check digits must not be treated as proof of authenticity. Verify original documents before approval.') ?>
</p>

<?php require_once __DIR__ . '/../../includes/pdf_footer.php'; ?>
