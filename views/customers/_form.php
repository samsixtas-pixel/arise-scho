<?php
/**
 * views/customers/_form.php — shared registration/edit form.
 *
 * Expects: $customer (array|null), $errors (array), $officers (array), $isEdit (bool)
 */

$isEdit   = $isEdit ?? false;
$customer = $customer ?? null;
$errors   = $errors ?? [];
$officers = $officers ?? [];

$value = static function (string $key, $fallback = '') use ($customer) {
    return old($key, $customer[$key] ?? $fallback);
};

$photo       = $isEdit ? PhotoStorage::url($customer['photo_path'] ?? null) : null;
$action      = $isEdit
    ? BASE_URL . 'views/customers/edit.php?id=' . (int) $customer['id']
    : BASE_URL . 'views/customers/add.php';
$statusValue = $value('status', 'active');
$genderValue = (string) $value('gender', $customer['gender'] ?? '');
$draftId     = $isEdit ? 'edit-' . (int) $customer['id'] : 'new-' . bin2hex(random_bytes(6));
?>

<?= errorList($errors) ?>

<form method="POST" action="<?= e($action) ?>" enctype="multipart/form-data" novalidate
      id="customerForm" data-draft-id="<?= e($draftId) ?>">
  <?= Security::csrfField() ?>
  <input type="hidden" name="capture_draft_id" value="<?= e($draftId) ?>">
  <input type="hidden" name="remove_photo" id="removePhoto" value="0">

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="form-section h-100">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <h6 class="mb-0"><i class="bi bi-camera me-1"></i><?= t('Customer photograph') ?></h6>
          <span class="pill pill-grey <?= $photo ? '' : 'd-none' ?>" id="captureSourceBadge">
            <?= $photo ? t('Current photo') : '' ?>
          </span>
        </div>

        <div class="camera-box <?= $photo ? 'captured' : '' ?>" id="captureBox"
             data-purpose="portrait" data-draft-id="<?= e($draftId) ?>"
             data-current-photo="<?= $photo ? '1' : '0' ?>">

          <div class="capture-live d-none" id="captureLive">
            <video id="captureVideo" playsinline muted autoplay></video>
            <div class="capture-guide portrait" aria-hidden="true"></div>
            <div class="capture-live-hint"><?= t('Head and shoulders, facing the camera') ?></div>
          </div>

          <img id="photoPreview" class="preview <?= $photo ? '' : 'd-none' ?>"
               src="<?= $photo ? e($photo) : '' ?>" alt="<?= t('Customer photo') ?>">

          <div class="camera-placeholder <?= $photo ? 'd-none' : '' ?>" id="capturePlaceholder">
            <i class="bi bi-person-bounding-box"></i>
            <div class="fw-semibold small"><?= t('Capture the customer’s face') ?></div>
            <div class="camera-hint mb-2"><?= t('Neutral background, no hat, eyes open.') ?></div>

            <div class="capture-modes">
              <button type="button" class="btn btn-primary btn-sm" id="btnCaptureThis">
                <i class="bi bi-camera-video me-1"></i><span class="mode-label"><?= t('Capture on this device') ?></span>
              </button>
              <button type="button" class="btn btn-outline-primary btn-sm" id="btnCapturePhone">
                <i class="bi bi-phone me-1"></i><?= t('Capture using a phone') ?>
              </button>
              <label class="btn btn-outline-secondary btn-sm mb-0">
                <i class="bi bi-image me-1"></i><?= t('Upload image') ?>
                <input type="file" name="photo_file" id="photoFile" accept="image/*" hidden>
              </label>
            </div>

            <div class="capture-note" id="captureNote"></div>
          </div>

          <div class="camera-controls d-none" id="cameraControls">
            <button type="button" class="btn btn-primary btn-sm" id="btnShoot">
              <i class="bi bi-camera me-1"></i><?= t('Capture') ?>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnSwitchCamera">
              <i class="bi bi-arrow-repeat me-1"></i><?= t('Switch') ?>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnStopCamera">
              <?= t('Cancel') ?>
            </button>
          </div>

          <div class="camera-controls <?= $photo ? '' : 'd-none' ?>" id="photoControls">
            <button type="button" class="btn btn-outline-primary btn-sm" id="btnRetakePhoto">
              <i class="bi bi-camera me-1"></i><?= t('Retake') ?>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnReplacePhoto">
              <i class="bi bi-arrow-repeat me-1"></i><?= t('Replace') ?>
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm" id="btnRemovePhoto">
              <i class="bi bi-trash me-1"></i><?= t('Remove') ?>
            </button>
          </div>

          <input type="hidden" name="photo_data" id="photoData" value="">
        </div>

        <div class="form-text mt-2">
          <?= $isEdit
              ? t('Leave the photo unchanged, retake it, or upload a replacement.')
              : t('A photo is required for every new customer.') ?>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="form-section">
        <h6><i class="bi bi-person-vcard me-1"></i><?= t('Identity') ?></h6>

        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label <?= $isEdit ? '' : 'required' ?>" for="first_name"><?= t('First name') ?></label>
            <input class="form-control" type="text" id="first_name" name="first_name" value="<?= e($value('first_name')) ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label <?= $isEdit ? '' : 'required' ?>" for="middle_name"><?= t('Middle name') ?></label>
            <input class="form-control" type="text" id="middle_name" name="middle_name" value="<?= e($value('middle_name')) ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label <?= $isEdit ? '' : 'required' ?>" for="last_name"><?= t('Last name') ?></label>
            <input class="form-control" type="text" id="last_name" name="last_name" value="<?= e($value('last_name')) ?>" required>
          </div>

          <div class="col-md-4">
            <label class="form-label <?= $isEdit ? '' : 'required' ?>" for="gender"><?= t('Gender') ?></label>
            <select class="form-select" id="gender" name="gender" <?= $isEdit ? '' : 'required' ?>>
              <option value=""><?= t('Select gender') ?></option>
              <option value="male" <?= $genderValue === 'male' ? 'selected' : '' ?>><?= t('Male') ?></option>
              <option value="female" <?= $genderValue === 'female' ? 'selected' : '' ?>><?= t('Female') ?></option>
              <option value="other" <?= $genderValue === 'other' ? 'selected' : '' ?>><?= t('Other') ?></option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label <?= $isEdit ? '' : 'required' ?>" for="nida"><?= t('NIDA number') ?></label>
            <input class="form-control" type="text" id="nida" name="nida" inputmode="numeric"
                   maxlength="20" value="<?= e($value('nida')) ?>"
                   placeholder="20 digits" <?= $isEdit ? '' : 'required' ?>>
            <div class="form-text"><?= t('The 20-digit national ID number.') ?></div>
          </div>

          <div class="col-md-4">
            <label class="form-label <?= $isEdit ? '' : 'required' ?>" for="phone"><?= t('Phone number') ?></label>
            <input class="form-control" type="tel" id="phone" name="phone" value="<?= e($value('phone')) ?>"
                   placeholder="0744 909 605" <?= $isEdit ? '' : 'required' ?>>
            <div class="form-text"><?= t('Used for follow-up calls and SMS.') ?></div>
          </div>

          <div class="col-md-6">
            <label class="form-label" for="phone_alt"><?= t('Alternate phone') ?></label>
            <input class="form-control" type="tel" id="phone_alt" name="phone_alt" value="<?= e($value('phone_alt')) ?>">
          </div>

          <div class="col-md-6">
            <label class="form-label" for="emergency_contact"><?= t('Emergency contact') ?></label>
            <input class="form-control" type="text" id="emergency_contact" name="emergency_contact"
                   value="<?= e($value('emergency_contact')) ?>" placeholder="<?= t('Name and relationship') ?>">
          </div>

          <div class="col-md-6">
            <label class="form-label" for="emergency_phone"><?= t('Emergency phone') ?></label>
            <input class="form-control" type="tel" id="emergency_phone" name="emergency_phone"
                   value="<?= e($value('emergency_phone')) ?>">
          </div>

          <?php if (User::isAdmin()): ?>
          <div class="col-md-6">
            <label class="form-label" for="assigned_officer_id"><?= t('Assigned officer') ?></label>
            <select class="form-select" id="assigned_officer_id" name="assigned_officer_id">
              <option value=""><?= t('Unassigned') ?></option>
              <?php foreach ($officers as $officer): ?>
                <option value="<?= (int) $officer['id'] ?>"
                  <?= (string) $value('assigned_officer_id', $customer['assigned_officer_id'] ?? '') === (string) $officer['id'] ? 'selected' : '' ?>>
                  <?= e($officer['full_name']) ?> (<?= e(t(ucfirst($officer['role']))) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <?php if ($isEdit): ?>
          <div class="col-md-6">
            <label class="form-label" for="status"><?= t('Status') ?></label>
            <select class="form-select" id="status" name="status">
              <?php foreach (['active', 'inactive', 'blacklisted'] as $status): ?>
                <option value="<?= $status ?>" <?= $statusValue === $status ? 'selected' : '' ?>><?= e(t(ucfirst($status))) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <div class="col-12">
            <label class="form-label" for="notes"><?= t('Notes') ?></label>
            <textarea class="form-control" id="notes" name="notes" rows="2"
                      placeholder="<?= t('Anything the next officer should know') ?>"><?= e($value('notes')) ?></textarea>
          </div>
        </div>
      </div>

      <div class="d-flex gap-2 flex-wrap mt-3">
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-check2-circle me-1"></i><?= $isEdit ? t('Save changes') : t('Register customer') ?>
        </button>
        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>views/customers/index.php"><?= t('Cancel') ?></a>
      </div>
    </div>
  </div>
</form>

<!-- Image editor -->
<div class="modal fade" id="imageEditorModal" tabindex="-1" aria-labelledby="imageEditorTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="imageEditorTitle"><i class="bi bi-crop me-1"></i><?= t('Adjust photograph') ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button>
      </div>
      <div class="modal-body">
        <div class="editor-stage portrait" id="imageEditorStage">
          <canvas id="imageEditorCanvas"></canvas>
          <div class="editor-frame" aria-hidden="true"></div>
        </div>
        <div class="editor-tools">
          <button type="button" class="btn btn-outline-secondary btn-sm btn-icon" data-editor-rotate="-90"
                  title="<?= t('Rotate left') ?>"><i class="bi bi-arrow-counterclockwise"></i></button>
          <div class="editor-zoom">
            <i class="bi bi-zoom-out"></i>
            <input type="range" id="editorZoom" min="1" max="3" step="0.01" value="1" aria-label="<?= t('Zoom') ?>">
            <i class="bi bi-zoom-in"></i>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm btn-icon" data-editor-rotate="90"
                  title="<?= t('Rotate right') ?>"><i class="bi bi-arrow-clockwise"></i></button>
          <button type="button" class="btn btn-outline-secondary btn-sm" id="btnEditorReset">
            <i class="bi bi-arrow-repeat me-1"></i><?= t('Reset') ?>
          </button>
        </div>
        <div class="form-text mt-2"><?= t('Drag the photo to reposition it. Zoom, rotate and centre the face inside the frame.') ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
        <button type="button" class="btn btn-primary" id="btnEditorUse">
          <i class="bi bi-check2 me-1"></i><?= t('Use this photo') ?>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- QR phone pairing -->
<div class="modal fade" id="pairPhoneModal" tabindex="-1" aria-labelledby="pairPhoneTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="pairPhoneTitle"><i class="bi bi-phone me-1"></i><?= t('Use a phone as the camera') ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button>
      </div>
      <div class="modal-body">
        <div class="pair-grid">
          <div class="pair-qr" id="pairQr">
            <div class="pair-qr-fallback"><i class="bi bi-qr-code"></i><span><?= t('Preparing QR code…') ?></span></div>
          </div>
          <div class="pair-copy">
            <h6><?= t('Scan this code with the phone camera') ?></h6>
            <p class="small text-muted mb-2">
              <?= t('No app or file transfer is needed. The photo appears here automatically after the phone confirms it.') ?>
            </p>
            <div class="pair-status waiting" id="pairStatus"><?= t('Waiting for the phone…') ?></div>
            <div class="pair-timer" id="pairTimer"><?= t('Preparing…') ?></div>
            <a class="small d-none" id="pairLink" href="#" target="_blank" rel="noopener">
              <?= t('Open the capture page on this device') ?>
            </a>
          </div>
        </div>
        <div class="alert alert-success small mt-3 mb-0 py-2">
          <i class="bi bi-shield-check me-1"></i>
          <?= t('The link only allows one photo to be sent to this registration. It expires automatically and shows no customer information.') ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="btnPairCancel"><?= t('Cancel capture') ?></button>
        <button type="button" class="btn btn-primary d-none" id="btnPairRenew">
          <i class="bi bi-arrow-repeat me-1"></i><?= t('New code') ?>
        </button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= t('Close') ?></button>
      </div>
    </div>
  </div>
</div>
