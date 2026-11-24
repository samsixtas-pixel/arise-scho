<?php
/**
 * capture.php — secure mobile capture page opened from the QR code.
 * -------------------------------------------------------
 * The URL carries a one-time, expiring session token. The page
 * can only upload an image to that session; it cannot read
 * customers, loans or the officer's account.
 * -------------------------------------------------------
 */

require_once __DIR__ . '/includes/autoload.php';

$token = (string) ($_GET['t'] ?? '');
$model = new CameraSession();

$json = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
};

// ------------------------------------------------------------------
// Upload (JSON: {image: "data:image/jpeg;base64,..."})
// ------------------------------------------------------------------
if (isPost()) {
    $session = $model->activeByToken($token);

    if (!$session) {
        $json(['ok' => false, 'error' => t('This capture link has expired. Ask the officer to show a new QR code.')], 410);
    }

    if (!in_array($session['status'], ['pending', 'uploaded'], true)) {
        $json(['ok' => false, 'error' => t('This capture session is already closed.')], 409);
    }

    $raw = file_get_contents('php://input');
    $input = json_decode((string) $raw, true);

    if (!is_array($input) || empty($input['image']) || !is_string($input['image'])) {
        $json(['ok' => false, 'error' => t('No image was received.')], 422);
    }

    try {
        $image = DocumentStorage::storeTemporary($input['image'], (string) $session['session_uuid']);
        $assetId = $model->saveAsset((int) $session['id'], $image);

        $json([
            'ok'       => true,
            'asset_id' => $assetId,
            'width'    => $image['width'],
            'height'   => $image['height'],
            'message'  => t('Image sent to the office computer.'),
        ]);
    } catch (ValidationException $e) {
        $json(['ok' => false, 'error' => implode(' ', $e->errors())], 422);
    } catch (Throwable $e) {
        error_log('Capture upload: ' . $e->getMessage());
        $json(['ok' => false, 'error' => t('The image could not be processed. Please try again.')], 500);
    }
}

// ------------------------------------------------------------------
// Page state
// ------------------------------------------------------------------
$session = $model->findByToken($token);
$state   = 'invalid';

if ($session) {
    if ($session['status'] === 'completed') {
        $state = 'completed';
    } elseif ($session['status'] === 'cancelled') {
        $state = 'cancelled';
    } elseif (in_array($session['status'], ['pending', 'uploaded'], true)
              && strtotime($session['expires_at']) >= time()) {
        $state = 'ready';
    } else {
        $state = 'expired';
    }
}

$purpose      = $session['capture_purpose'] ?? 'portrait';
$purposeLabel = CameraSession::PURPOSES[$purpose] ?? t('Customer photo');
$expiresEpoch = $session ? strtotime($session['expires_at']) : time();
$sessionCode  = $session ? strtoupper(substr(str_replace('-', '', $session['session_uuid']), -6)) : '';
$guideClass   = in_array($purpose, ['passport', 'nida', 'document'], true) ? 'document' : 'portrait';
$isReady      = $state === 'ready';
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0D6E5D">
<title><?= t('Capture image') ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap-icons.css">
<style>
  :root {
    --green: #0D6E5D;
    --green-dark: #084C40;
    --green-50: #E8F4F0;
    --ink: #16241F;
    --muted: #5C7068;
    --border: #DCE7E2;
    --red: #B91C1C;
    --gold: #B7791F;
    --r: 14px;
  }
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  html, body { margin: 0; min-height: 100%; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    background: #F3F7F5;
    color: var(--ink);
    -webkit-font-smoothing: antialiased;
  }
  .shell { max-width: 560px; margin: 0 auto; padding: 14px 14px 28px; }
  .topbar {
    display: flex; align-items: center; justify-content: space-between;
    gap: 10px; padding: 4px 2px 14px;
  }
  .brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .brand-mark {
    width: 38px; height: 38px; border-radius: 11px; display: grid; place-items: center;
    background: var(--green); color: #fff; font-size: 1.15rem; flex: 0 0 38px;
  }
  .brand strong { display: block; font-size: .95rem; line-height: 1.15; }
  .brand small { color: var(--muted); font-size: .72rem; }
  .timer {
    font-variant-numeric: tabular-nums; font-size: .78rem; font-weight: 700;
    background: #fff; border: 1px solid var(--border); color: var(--muted);
    padding: 6px 9px; border-radius: 999px; white-space: nowrap;
  }
  .timer.low { color: var(--red); border-color: #F3C6C6; background: #FEF2F2; }
  .purpose {
    display: flex; align-items: center; gap: 9px; background: var(--green-50);
    border: 1px solid #CBE5DB; border-radius: var(--r); padding: 11px 12px;
    font-size: .84rem; font-weight: 650; color: var(--green-dark); margin-bottom: 12px;
  }
  .purpose i { font-size: 1rem; }
  .purpose .code { margin-left: auto; font-size: .7rem; color: var(--muted); font-weight: 600; letter-spacing: .06em; }
  .card {
    background: #fff; border: 1px solid var(--border); border-radius: 18px;
    padding: 13px; box-shadow: 0 8px 24px rgba(16, 52, 40, .06);
  }
  .stage {
    position: relative; width: 100%; aspect-ratio: 3 / 4; border-radius: 14px;
    overflow: hidden; background: #0E1A16; display: grid; place-items: center;
  }
  .stage.document { aspect-ratio: 1.42; }
  .stage video, .stage canvas, .stage img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .stage canvas { touch-action: none; cursor: grab; }
  .stage canvas.dragging { cursor: grabbing; }
  .guide {
    position: absolute; inset: 0; pointer-events: none; display: grid; place-items: center;
  }
  .guide::before {
    content: ""; display: block; border: 2px solid rgba(255,255,255,.9);
    box-shadow: 0 0 0 999px rgba(5, 20, 15, .35); border-radius: 12px;
  }
  .guide.portrait::before { width: 64%; height: 82%; border-radius: 48% 48% 44% 44%; }
  .guide.document::before { width: 84%; height: 82%; border-radius: 10px; }
  .guide-note {
    position: absolute; left: 50%; transform: translateX(-50%); bottom: 12px;
    background: rgba(6, 24, 18, .72); color: #fff; font-size: .72rem;
    padding: 6px 10px; border-radius: 999px; white-space: nowrap; max-width: 92%;
    overflow: hidden; text-overflow: ellipsis;
  }
  .placeholder {
    position: absolute; inset: 0; display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: .5rem; color: #D8E7E1;
    text-align: center; padding: 22px; background: #10231D;
  }
  .placeholder i { font-size: 2rem; color: #8FB8AA; }
  .placeholder strong { color: #fff; font-size: .95rem; }
  .placeholder span { color: #A9C4BB; font-size: .76rem; line-height: 1.45; }
  .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin-top: 12px; }
  .actions.one { grid-template-columns: 1fr; }
  .btn {
    appearance: none; border: 0; border-radius: 12px; padding: 13px 14px;
    font-size: .9rem; font-weight: 700; display: inline-flex; align-items: center;
    justify-content: center; gap: .45rem; min-height: 48px; cursor: pointer;
    text-decoration: none; line-height: 1.1;
  }
  .btn-primary { background: var(--green); color: #fff; }
  .btn-primary:disabled { background: #9AB9AF; cursor: default; }
  .btn-secondary { background: #fff; color: var(--ink); border: 1px solid var(--border); }
  .btn-soft { background: var(--green-50); color: var(--green-dark); border: 1px solid #CBE5DB; }
  .btn-danger { background: #FEF2F2; color: var(--red); border: 1px solid #F3C6C6; }
  .btn i { font-size: 1.05rem; }
  .hint { color: var(--muted); font-size: .74rem; line-height: 1.5; text-align: center; margin: 10px 4px 0; }
  .tools { display: flex; align-items: center; gap: 8px; margin-top: 11px; }
  .tools .zoom { flex: 1; display: flex; align-items: center; gap: 7px; }
  .tools input[type=range] { width: 100%; accent-color: var(--green); }
  .tool-btn {
    width: 42px; height: 42px; border-radius: 11px; border: 1px solid var(--border);
    background: #fff; color: var(--ink); display: grid; place-items: center; font-size: 1.05rem;
  }
  .status {
    margin-top: 11px; border-radius: 11px; padding: 10px 12px; font-size: .8rem;
    display: none; line-height: 1.45;
  }
  .status.show { display: block; }
  .status.info { background: var(--green-50); color: var(--green-dark); border: 1px solid #CBE5DB; }
  .status.error { background: #FEF2F2; color: var(--red); border: 1px solid #F3C6C6; }
  .state { text-align: center; padding: 26px 16px; }
  .state-icon {
    width: 64px; height: 64px; border-radius: 20px; display: grid; place-items: center;
    margin: 0 auto 12px; font-size: 1.7rem;
  }
  .state-icon.ok { background: var(--green-50); color: var(--green); }
  .state-icon.warn { background: #FEF6E7; color: var(--gold); }
  .state-icon.bad { background: #FEF2F2; color: var(--red); }
  .state h1 { font-size: 1.12rem; margin: 0 0 6px; }
  .state p { color: var(--muted); font-size: .84rem; line-height: 1.55; margin: 0 auto 16px; max-width: 340px; }
  .spin {
    width: 16px; height: 16px; border-radius: 50%; border: 2px solid rgba(255,255,255,.45);
    border-top-color: #fff; animation: spin .7s linear infinite; display: inline-block;
  }
  @keyframes spin { to { transform: rotate(360deg); } }
  .hidden { display: none !important; }
  @media (min-width: 700px) { .shell { padding-top: 28px; } }
</style>
</head>
<body>
<div class="shell">
  <div class="topbar">
    <div class="brand">
      <span class="brand-mark"><i class="bi bi-camera"></i></span>
      <div><strong><?= t('Secure image capture') ?></strong><small><?= e(Settings::businessName()) ?></small></div>
    </div>
    <?php if ($isReady): ?>
      <span class="timer" id="timer" data-expires="<?= (int) $expiresEpoch ?>">--:--</span>
    <?php endif; ?>
  </div>

  <?php if ($state === 'ready'): ?>
    <div class="purpose">
      <i class="bi bi-<?= $guideClass === 'document' ? 'card-image' : 'person-bounding-box' ?>"></i>
      <span><?= e($purposeLabel) ?></span>
      <span class="code"><?= e($sessionCode) ?></span>
    </div>

    <div class="card">
      <div id="capturePane">
        <div class="stage <?= $guideClass ?>" id="stage">
          <video id="video" playsinline autoplay muted></video>
          <canvas id="snapCanvas" class="hidden"></canvas>
          <div class="guide <?= $guideClass ?>" id="guide"></div>
          <div class="placeholder" id="placeholder">
            <i class="bi bi-camera-video"></i>
            <strong><?= $guideClass === 'document' ? t('Place the document inside the frame') : t('Centre the customer’s face') ?></strong>
            <span><?= $guideClass === 'document'
                ? t('Keep all four corners and the small printed code visible. Avoid glare.')
                : t('Use a plain background, no hat, eyes open.') ?></span>
          </div>
          <div class="guide-note" id="guideNote"><?= $guideClass === 'document'
              ? t('All edges and text must stay inside the frame')
              : t('Head and shoulders, facing the camera') ?></div>
        </div>

        <div class="actions" id="captureActions">
          <button type="button" class="btn btn-primary" id="btnStart">
            <i class="bi bi-camera-video"></i><?= t('Open camera') ?>
          </button>
          <label class="btn btn-secondary" for="fileInput">
            <i class="bi bi-image"></i><?= t('Upload image') ?>
            <input type="file" id="fileInput" accept="image/*" capture="environment" hidden>
          </label>
        </div>

        <div class="actions hidden" id="shootActions">
          <button type="button" class="btn btn-secondary" id="btnSwitch">
            <i class="bi bi-arrow-repeat"></i><?= t('Switch camera') ?>
          </button>
          <button type="button" class="btn btn-primary" id="btnShot">
            <i class="bi bi-camera"></i><?= t('Capture') ?>
          </button>
        </div>

        <p class="hint"><?= t('The image is sent directly to the office computer. Nothing is stored on this phone.') ?></p>
      </div>

      <div id="editPane" class="hidden">
        <div class="stage <?= $guideClass ?>" id="editorStage">
          <canvas id="editorCanvas"></canvas>
        </div>
        <div class="tools">
          <button type="button" class="tool-btn" id="btnRotateLeft" title="<?= t('Rotate left') ?>"><i class="bi bi-arrow-counterclockwise"></i></button>
          <div class="zoom">
            <i class="bi bi-zoom-out" style="color:var(--muted)"></i>
            <input type="range" id="zoom" min="1" max="3" step="0.01" value="1" aria-label="<?= t('Zoom') ?>">
            <i class="bi bi-zoom-in" style="color:var(--muted)"></i>
          </div>
          <button type="button" class="tool-btn" id="btnRotateRight" title="<?= t('Rotate right') ?>"><i class="bi bi-arrow-clockwise"></i></button>
          <button type="button" class="tool-btn" id="btnReset" title="<?= t('Reset') ?>"><i class="bi bi-arrow-repeat"></i></button>
        </div>
        <div class="actions">
          <button type="button" class="btn btn-secondary" id="btnRetake"><i class="bi bi-arrow-counterclockwise"></i><?= t('Retake') ?></button>
          <button type="button" class="btn btn-primary" id="btnSend"><i class="bi bi-cloud-arrow-up"></i><?= t('Send to computer') ?></button>
        </div>
        <div class="status" id="uploadStatus"></div>
      </div>

      <div id="sentPane" class="hidden">
        <div class="state">
          <div class="state-icon ok"><i class="bi bi-check2-circle"></i></div>
          <h1><?= t('Image sent') ?></h1>
          <p><?= t('The office computer has received the image. Return to the computer to review and accept it.') ?></p>
          <button type="button" class="btn btn-soft" id="btnAgain"><i class="bi bi-camera"></i><?= t('Capture another') ?></button>
        </div>
      </div>
    </div>
    <p class="hint"><?= t('Session :code · expires :time', [
        'code' => $sessionCode,
        'time' => date('H:i', $expiresEpoch),
    ]) ?></p>
  <?php elseif ($state === 'completed'): ?>
    <div class="card state">
      <div class="state-icon ok"><i class="bi bi-check2-circle"></i></div>
      <h1><?= t('Image already received') ?></h1>
      <p><?= t('This capture session is complete. You can close this page.') ?></p>
    </div>
  <?php elseif ($state === 'cancelled'): ?>
    <div class="card state">
      <div class="state-icon warn"><i class="bi bi-x-circle"></i></div>
      <h1><?= t('Capture cancelled') ?></h1>
      <p><?= t('The officer cancelled this capture session. Ask them to show a new QR code.') ?></p>
    </div>
  <?php elseif ($state === 'expired'): ?>
    <div class="card state">
      <div class="state-icon warn"><i class="bi bi-clock-history"></i></div>
      <h1><?= t('Capture link expired') ?></h1>
      <p><?= t('For security, capture links last a short time. Ask the officer to create a new one.') ?></p>
    </div>
  <?php else: ?>
    <div class="card state">
      <div class="state-icon bad"><i class="bi bi-shield-x"></i></div>
      <h1><?= t('Invalid capture link') ?></h1>
      <p><?= t('This link is not valid. Scan the QR code shown on the office computer again.') ?></p>
    </div>
  <?php endif; ?>
</div>

<?php if ($isReady): ?>
<script src="<?= BASE_URL ?>assets/js/image-editor.js"></script>
<script>
(function () {
  'use strict';

  const token       = <?= json_encode($token) ?>;
  const purpose     = <?= json_encode($purpose) ?>;
  const expiresAt   = <?= (int) $expiresEpoch ?> * 1000;
  const isDocument  = <?= $guideClass === 'document' ? 'true' : 'false' ?>;

  const video       = document.getElementById('video');
  const placeholder = document.getElementById('placeholder');
  const capturePane = document.getElementById('capturePane');
  const editPane    = document.getElementById('editPane');
  const sentPane    = document.getElementById('sentPane');
  const captureActs = document.getElementById('captureActions');
  const shootActs   = document.getElementById('shootActions');
  const btnStart    = document.getElementById('btnStart');
  const btnShot     = document.getElementById('btnShot');
  const btnSwitch   = document.getElementById('btnSwitch');
  const btnRetake   = document.getElementById('btnRetake');
  const btnSend     = document.getElementById('btnSend');
  const btnAgain    = document.getElementById('btnAgain');
  const btnReset    = document.getElementById('btnReset');
  const fileInput   = document.getElementById('fileInput');
  const zoom        = document.getElementById('zoom');
  const statusBox   = document.getElementById('uploadStatus');
  const timer       = document.getElementById('timer');

  let stream = null;
  let facing = isDocument ? 'environment' : 'user';
  let editor = null;
  let dirty = false;
  let expired = false;

  function stopCamera() {
    if (stream) {
      stream.getTracks().forEach(function (t) { t.stop(); });
      stream = null;
    }
  }

  function showStatus(text, type) {
    statusBox.textContent = text;
    statusBox.className = 'status show ' + (type || 'info');
  }

  function hideStatus() {
    statusBox.className = 'status';
  }

  function updateTimer() {
    if (!timer) return;
    const left = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
    const m = Math.floor(left / 60);
    const s = left % 60;
    timer.textContent = (left > 0 ? (m + ':' + (s < 10 ? '0' : '') + s) : <?= json_encode(t('Expired')) ?>);
    timer.classList.toggle('low', left <= 120);

    if (left <= 0 && !expired) {
      expired = true;
      stopCamera();
      const btn = document.getElementById('btnSend');
      if (btn) btn.disabled = true;
      showStatus(<?= json_encode(t('This capture link has expired. Ask the officer for a new QR code.')) ?>, 'error');
    }
  }

  updateTimer();
  setInterval(updateTimer, 1000);

  async function startCamera() {
    if (expired) return;

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      showStatus(<?= json_encode(t('This browser cannot open the camera. Use Upload image instead.')) ?>, 'error');
      return;
    }

    stopCamera();

    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: {
          facingMode: { ideal: facing },
          width: { ideal: isDocument ? 1920 : 1440 },
          height: { ideal: isDocument ? 1440 : 1080 }
        },
        audio: false
      });

      video.srcObject = stream;
      await video.play();
      video.classList.remove('hidden');
      placeholder.classList.add('hidden');
      captureActs.classList.add('hidden');
      shootActs.classList.remove('hidden');
      hideStatus();
    } catch (err) {
      showStatus(
        <?= json_encode(t('Camera access was blocked. Allow camera permission in the browser, or upload an image instead.')) ?>,
        'error'
      );
      captureActs.classList.remove('hidden');
      shootActs.classList.add('hidden');
    }
  }

  function frameToEditor(source, width, height) {
    const snap = document.createElement('canvas');
    const max = 2200;
    const scale = Math.min(1, max / Math.max(width, height));
    snap.width = Math.round(width * scale);
    snap.height = Math.round(height * scale);

    snap.getContext('2d').drawImage(source, 0, 0, snap.width, snap.height);

    openEditor(snap.toDataURL('image/jpeg', 0.94));
  }

  function capture() {
    const vw = video.videoWidth;
    const vh = video.videoHeight;

    if (!vw || !vh) {
      showStatus(<?= json_encode(t('The camera is still starting. Try again in a moment.')) ?>, 'error');
      return;
    }

    frameToEditor(video, vw, vh);
    stopCamera();
  }

  function openEditor(dataUrl) {
    dirty = true;
    capturePane.classList.add('hidden');
    sentPane.classList.add('hidden');
    editPane.classList.remove('hidden');

    if (!editor) {
      editor = new LmsImageEditor({
        canvas: document.getElementById('editorCanvas'),
        stage: document.getElementById('editorStage'),
        aspect: isDocument ? 1.42 : 3 / 4,
        maxEdge: isDocument ? 1800 : 1200,
        quality: 0.9
      });
    }

    editor.load(dataUrl).then(function () {
      zoom.value = '1';
      hideStatus();
    }).catch(function () {
      showStatus(<?= json_encode(t('The image could not be opened. Please retake it.')) ?>, 'error');
    });
  }

  function resetToCapture() {
    stopCamera();
    dirty = false;
    editPane.classList.add('hidden');
    sentPane.classList.add('hidden');
    capturePane.classList.remove('hidden');
    captureActs.classList.remove('hidden');
    shootActs.classList.add('hidden');
    video.classList.add('hidden');
    placeholder.classList.remove('hidden');
    if (fileInput) fileInput.value = '';
    hideStatus();
    if (editor) editor.reset();
  }

  btnStart.addEventListener('click', startCamera);
  btnShot.addEventListener('click', capture);

  btnSwitch.addEventListener('click', function () {
    facing = facing === 'user' ? 'environment' : 'user';
    startCamera();
  });

  btnRetake.addEventListener('click', resetToCapture);
  btnAgain.addEventListener('click', resetToCapture);

  document.getElementById('btnRotateLeft').addEventListener('click', function () {
    if (editor) editor.rotate(-90);
  });

  document.getElementById('btnRotateRight').addEventListener('click', function () {
    if (editor) editor.rotate(90);
  });

  btnReset.addEventListener('click', function () {
    if (editor) { editor.reset(); zoom.value = '1'; }
  });

  zoom.addEventListener('input', function () {
    if (editor) editor.setZoom(parseFloat(zoom.value) || 1);
  });

  fileInput.addEventListener('change', function () {
    const file = fileInput.files && fileInput.files[0];
    if (!file) return;

    if (file.size > 12 * 1024 * 1024) {
      showStatus(<?= json_encode(t('The image must be smaller than 12 MB.')) ?>, 'error');
      return;
    }

    const reader = new FileReader();
    reader.onload = function (event) { openEditor(event.target.result); };
    reader.readAsDataURL(file);
  });

  btnSend.addEventListener('click', function () {
    if (!editor || expired) return;

    const dataUrl = editor.toDataURL();
    btnSend.disabled = true;
    btnSend.innerHTML = '<span class="spin"></span> ' + <?= json_encode(t('Sending…')) ?>;
    showStatus(<?= json_encode(t('Uploading the image securely…')) ?>, 'info');

    fetch('capture.php?t=' + encodeURIComponent(token), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ image: dataUrl })
    })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, status: response.status, data: data };
        });
      })
      .then(function (result) {
        if (!result.ok || !result.data.ok) {
          throw new Error(result.data.error || <?= json_encode(t('The upload failed. Please try again.')) ?>);
        }

        dirty = false;
        stopCamera();
        editPane.classList.add('hidden');
        sentPane.classList.remove('hidden');
      })
      .catch(function (error) {
        btnSend.disabled = false;
        btnSend.innerHTML = '<i class="bi bi-cloud-arrow-up"></i> ' + <?= json_encode(t('Send to computer')) ?>;
        showStatus(error.message || <?= json_encode(t('The upload failed. Check the connection and try again.')) ?>, 'error');
      });
  });

  window.addEventListener('beforeunload', function (event) {
    if (dirty && !expired) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  window.addEventListener('pagehide', stopCamera);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') stopCamera();
  });
})();
</script>
<?php endif; ?>
</body>
</html>
