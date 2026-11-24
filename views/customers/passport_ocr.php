<?php
/**
 * views/customers/passport_ocr.php — passport MRZ reader.
 * -------------------------------------------------------
 * OCR is optional; when Tesseract is unavailable the officer
 * can still paste the two MRZ lines. Results are advisory and
 * always require manual confirmation.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$customerModel = new Customer();
$documentModel = new CustomerDocument();
$ocr           = new PassportOcrService();
$id            = (int) ($_GET['id'] ?? 0);
$documentId    = (int) ($_GET['doc_id'] ?? 0);

$customer = $customerModel->findDetailed($id);
$document = $documentModel->findDetailed($documentId);

if (!$customer || !$document || (int) $document['customer_id'] !== $id) {
    flash('error', t('Document not found.'));
    redirect(BASE_URL . 'views/customers/index.php');
}

$url = BASE_URL . 'views/customers/passport_ocr.php?id=' . $id . '&doc_id=' . $documentId;

if (isPost()) {
    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'parse') {
            $text   = trim((string) ($_POST['mrz_text'] ?? ''));
            $result = $ocr->parseText($text);

            if ($text === '') {
                throw new ValidationException([t('Paste the two lines printed at the bottom of the passport.')]);
            }

            $ocr->record($documentId, $result);
            flash($result['status'] === 'valid' ? 'success' : 'error',
                $result['status'] === 'valid'
                    ? t('MRZ read. Check every field against the passport.')
                    : t('The MRZ could not be validated. Check the two lines and try again.'));
        } elseif ($action === 'ocr') {
            $path = DocumentStorage::absolutePath($document['storage_key'] ?: $document['path']);

            if ($path === null) {
                throw new ValidationException([t('The document image is not available.')]);
            }

            $result = $ocr->extract($path);
            $ocr->record($documentId, $result);

            flash(
                $result['status'] === 'unavailable' ? 'warning' : ($result['status'] === 'valid' ? 'success' : 'error'),
                $result['error'] ?? t('OCR finished. Check every field against the passport.')
            );
        } elseif ($action === 'apply') {
            if (empty($_POST['confirmed'])) {
                throw new ValidationException([t('Confirm that you compared the values with the passport.')]);
            }

            $first  = trim((string) ($_POST['first_name'] ?? ''));
            $middle = trim((string) ($_POST['middle_name'] ?? ''));
            $last   = trim((string) ($_POST['last_name'] ?? ''));
            $gender = (string) ($_POST['gender'] ?? '');

            if ($first === '' || $last === '') {
                throw new ValidationException([t('First name and surname are required.')]);
            }

            // Merge with the stored record so other fields are not cleared.
            (new Customer())->update($id, [
                'first_name'         => $first,
                'middle_name'        => $middle,
                'last_name'          => $last,
                'gender'             => $gender !== '' ? $gender : ($customer['gender'] ?? ''),
                'nida'               => $customer['nida'],
                'phone'              => $customer['phone'],
                'phone_alt'          => $customer['phone_alt'],
                'emergency_contact'  => $customer['emergency_contact'],
                'emergency_phone'    => $customer['emergency_phone'],
                'notes'              => $customer['notes'],
                'status'             => $customer['status'],
                'assigned_officer_id'=> $customer['assigned_officer_id'],
            ]);

            AuditLog::record('customer.mrz_apply', 'customers', $id,
                'Officer applied MRZ values from document #' . $documentId);

            flash('success', t('Customer details updated after passport review.'));
        }
    } catch (ValidationException $e) {
        flash('error', implode(' ', $e->errors()));
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect($url);
}

$stmt = Database::getConnection()->prepare(
    "SELECT * FROM document_ocr_results
      WHERE document_id = :id
   ORDER BY id DESC LIMIT 1"
);
$stmt->execute(['id' => $documentId]);
$latest = $stmt->fetch();

$parsed = null;
if ($latest && !empty($latest['parsed_json'])) {
    $parsed = json_decode((string) $latest['parsed_json'], true);
}

$mrzGiven  = preg_split('/\s+/', trim((string) ($parsed['given_names'] ?? ''))) ?: [];
$mrzFirst  = $mrzGiven[0] ?? '';
$mrzMiddle = count($mrzGiven) > 1 ? implode(' ', array_slice($mrzGiven, 1)) : '';
$mrzLast   = trim((string) ($parsed['surname'] ?? ''));
$mrzGender = in_array($parsed['sex'] ?? '', ['male', 'female'], true) ? (string) $parsed['sex'] : '';

$engineStatus = $ocr->status();
$pageTitle    = t('Passport MRZ reader');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Passport MRZ reader') ?></h4>
    <p>
      <span class="ref"><?= e($customer['reference']) ?></span>
      · <?= e($customer['full_name']) ?>
      · <?= e(CustomerDocument::label($document['document_category'])) ?>
    </p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/customers/view.php?id=<?= $id ?>&tab=documents">
      <i class="bi bi-arrow-left me-1"></i><?= t('Back to customer file') ?>
    </a>
  </div>
</div>

<div class="alert alert-<?= $engineStatus['available'] ? 'success' : 'warning' ?> d-flex gap-2 align-items-start">
  <i class="bi bi-<?= $engineStatus['available'] ? 'cpu' : 'info-circle' ?> mt-1"></i>
  <div class="small">
    <strong><?= $engineStatus['available'] ? t('OCR engine available') : t('OCR unavailable') ?></strong>
    <?= e($engineStatus['message']) ?>
    <?php if ($engineStatus['available'] && $engineStatus['version']): ?>
      (Tesseract <?= e($engineStatus['version']) ?>)
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><span class="card-title-sm"><i class="bi bi-image me-1"></i><?= t('Document image') ?></span></div>
      <div class="card-body text-center">
        <a href="<?= e(CustomerDocument::url($documentId, 'full')) ?>" target="_blank" rel="noopener">
          <img class="img-fluid rounded border" src="<?= e(CustomerDocument::url($documentId, 'full')) ?>"
               alt="<?= e(CustomerDocument::label($document['document_category'])) ?>">
        </a>
        <div class="form-text mt-2"><?= t('Open the full image in a new tab to read the printed details.') ?></div>

        <form method="POST" class="mt-3">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="ocr">
          <button class="btn btn-primary btn-sm" type="submit" <?= $engineStatus['available'] ? '' : 'disabled' ?>>
            <i class="bi bi-cpu me-1"></i><?= t('Run OCR on this image') ?>
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><span class="card-title-sm"><i class="bi bi-upc-scan me-1"></i><?= t('Read the MRZ text') ?></span></div>
      <div class="card-body">
        <form method="POST">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="parse">

          <label class="form-label" for="mrz_text"><?= t('Paste the two MRZ lines') ?></label>
          <textarea class="form-control font-monospace" id="mrz_text" name="mrz_text" rows="3"
                    placeholder="P&lt;TZA&lt;SURNAME&lt;&lt;GIVEN&lt;NAMES&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&#10;0123456789TZA9001011M3001011&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;&lt;02"></textarea>
          <div class="form-text">
            <?= t('Each line must be 44 characters. Check digits are validated, but a valid MRZ does not prove the passport is genuine.') ?>
          </div>

          <button class="btn btn-outline-primary btn-sm mt-2" type="submit">
            <i class="bi bi-check2-circle me-1"></i><?= t('Read MRZ') ?>
          </button>
        </form>
      </div>
    </div>

    <?php if ($latest && $parsed): ?>
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span class="card-title-sm"><i class="bi bi-card-checklist me-1"></i><?= t('Extracted values') ?></span>
          <span class="badge bg-<?= ($parsed['valid'] ?? false) ? 'success' : 'danger' ?>">
            <?= ($parsed['valid'] ?? false) ? t('Check digits valid') : t('Needs manual review') ?>
          </span>
        </div>
        <div class="card-body">
          <dl class="detail-list">
            <div><dt><?= t('Passport number') ?></dt><dd><?= e($parsed['passport_number'] ?? '—') ?></dd></div>
            <div><dt><?= t('Surname') ?></dt><dd><?= e($parsed['surname'] ?? '—') ?></dd></div>
            <div><dt><?= t('Given names') ?></dt><dd><?= e($parsed['given_names'] ?? '—') ?></dd></div>
            <div><dt><?= t('Nationality') ?></dt><dd><?= e($parsed['nationality'] ?? '—') ?></dd></div>
            <div><dt><?= t('Issuing country') ?></dt><dd><?= e($parsed['issuing_country'] ?? '—') ?></dd></div>
            <div><dt><?= t('Date of birth') ?></dt><dd><?= e(formatDate($parsed['date_of_birth'] ?? null)) ?></dd></div>
            <div><dt><?= t('Sex') ?></dt><dd><?= e(isset($parsed['sex']) && $parsed['sex'] ? t(ucfirst($parsed['sex'])) : '—') ?></dd></div>
            <div><dt><?= t('Expiry date') ?></dt><dd><?= e(formatDate($parsed['expiry_date'] ?? null)) ?></dd></div>
            <div><dt><?= t('Personal number') ?></dt><dd><?= e($parsed['personal_number'] ?? '—') ?></dd></div>
            <div><dt><?= t('Confidence') ?></dt><dd><?= e(number_format(((float) ($parsed['confidence'] ?? 0)) * 100, 0)) ?>%</dd></div>
          </dl>

          <?php if (!empty($parsed['errors'])): ?>
            <div class="alert alert-danger small py-2 mt-2 mb-0">
              <ul class="mb-0 ps-3">
                <?php foreach ($parsed['errors'] as $error): ?>
                  <li><?= e($error) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="form-text mt-2">
            <?= t('Recorded :date by :user · engine: :engine', [
                'date'   => formatDateTime($latest['created_at']),
                'user'   => $_SESSION['full_name'] ?? '—',
                'engine' => $latest['engine'],
            ]) ?>
          </div>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header">
          <span class="card-title-sm"><i class="bi bi-person-check me-1"></i><?= t('Apply verified values to the customer') ?></span>
        </div>
        <div class="card-body">
          <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="apply">

            <div class="row g-2">
              <div class="col-md-4">
                <label class="form-label" for="apply_first"><?= t('First name') ?></label>
                <input class="form-control form-control-sm" id="apply_first" name="first_name"
                       value="<?= e($mrzFirst) ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="apply_middle"><?= t('Middle name') ?></label>
                <input class="form-control form-control-sm" id="apply_middle" name="middle_name"
                       value="<?= e($mrzMiddle) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label" for="apply_last"><?= t('Last name') ?></label>
                <input class="form-control form-control-sm" id="apply_last" name="last_name"
                       value="<?= e($mrzLast) ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="apply_gender"><?= t('Gender') ?></label>
                <select class="form-select form-select-sm" id="apply_gender" name="gender">
                  <option value=""><?= t('Select gender') ?></option>
                  <?php foreach (['male', 'female', 'other'] as $g): ?>
                    <option value="<?= $g ?>" <?= $mrzGender === $g ? 'selected' : '' ?>><?= e(t(ucfirst($g))) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="form-check mt-3">
              <input class="form-check-input" type="checkbox" id="apply_confirm" name="confirmed" value="1" required>
              <label class="form-check-label small" for="apply_confirm">
                <?= t('I compared every value with the passport and confirm the correction.') ?>
              </label>
            </div>

            <button class="btn btn-primary btn-sm mt-2" type="submit">
              <i class="bi bi-check2-circle me-1"></i><?= t('Apply to customer') ?>
            </button>
          </form>
        </div>
      </div>
    <?php elseif ($latest): ?>
      <div class="alert alert-secondary small">
        <?= t('The last OCR attempt did not produce a readable MRZ. Try a sharper image or paste the lines manually.') ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
