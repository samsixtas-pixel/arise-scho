/* ============================================================
   identity-capture.js — device-aware customer photo capture
   ------------------------------------------------------------
   - Direct camera on phone/tablet/webcam
   - QR pairing so a phone can act as the PC camera
   - Upload fallback
   - Crop / rotate / zoom editor (image-editor.js)
   - Polls the capture session; never downloads the same asset twice
   ============================================================ */
(function () {
  'use strict';

  const box = document.getElementById('captureBox');

  if (!box) return;

  const video        = document.getElementById('captureVideo');
  const preview      = document.getElementById('photoPreview');
  const hidden       = document.getElementById('photoData');
  const placeholder  = document.getElementById('capturePlaceholder');
  const liveWrap     = document.getElementById('captureLive');
  const note         = document.getElementById('captureNote');
  const badge        = document.getElementById('captureSourceBadge');
  const cameraCtrls  = document.getElementById('cameraControls');
  const photoCtrls   = document.getElementById('photoControls');
  const btnThis      = document.getElementById('btnCaptureThis');
  const btnPhone     = document.getElementById('btnCapturePhone');
  const btnShoot     = document.getElementById('btnShoot');
  const btnSwitch    = document.getElementById('btnSwitchCamera');
  const btnStop      = document.getElementById('btnStopCamera');
  const btnRetake    = document.getElementById('btnRetakePhoto');
  const btnReplace   = document.getElementById('btnReplacePhoto');
  const btnRemove    = document.getElementById('btnRemovePhoto');
  const fileInput    = document.getElementById('photoFile');
  const removeField  = document.getElementById('removePhoto');

  const editorModal  = document.getElementById('imageEditorModal');
  const editorCanvas = document.getElementById('imageEditorCanvas');
  const editorStage  = document.getElementById('imageEditorStage');
  const editorZoom   = document.getElementById('editorZoom');
  const btnEditUse   = document.getElementById('btnEditorUse');

  const pairModal    = document.getElementById('pairPhoneModal');
  const pairQr       = document.getElementById('pairQr');
  const pairStatus   = document.getElementById('pairStatus');
  const pairTimer    = document.getElementById('pairTimer');
  const pairLink     = document.getElementById('pairLink');
  const btnPairRenew = document.getElementById('btnPairRenew');
  const btnPairCancel= document.getElementById('btnPairCancel');

  const purpose      = box.dataset.purpose || 'portrait';
  const draftId      = box.dataset.draftId || '';
  const isDocument   = purpose !== 'portrait';

  let stream       = null;
  let facing       = isDocument ? 'environment' : 'user';
  let editor       = null;
  let editorSource = 'this device';
  let pendingData  = null;

  let pairSession  = null;
  let pairTimerId  = null;
  let pollTimerId  = null;
  let lastAssetId  = 0;
  let pairExpiresAt= 0;
  let pairBusy     = false;

  /* CSRF token rendered in <meta name="csrf-token"> by header.php. */
  const csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  function postHeaders() {
    const headers = { 'Accept': 'application/json' };
    if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
    return headers;
  }

  /* ----------------------------------------------------------
     Device detection (capability + viewport, not user-agent)
     ---------------------------------------------------------- */
  const coarsePointer = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  const smallScreen   = Math.min(window.screen.width || 0, window.screen.height || 0) <= 820;
  const isHandheld    = coarsePointer && smallScreen;
  const hasCameraApi  = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  const secureContext = window.isSecureContext
    || location.hostname === 'localhost'
    || location.hostname === '127.0.0.1';

  box.classList.toggle('device-handheld', isHandheld);
  box.classList.toggle('device-desktop', !isHandheld);

  if (note) {
    note.textContent = isHandheld
      ? 'This device can use its own camera. Uploading a photo is also fine.'
      : 'Use the phone as a camera, or open this computer’s webcam.';
  }

  if (!hasCameraApi && btnThis) {
    btnThis.disabled = true;
    btnThis.title = 'This browser does not support camera capture.';
  }

  /* ----------------------------------------------------------
     Helpers
     ---------------------------------------------------------- */
  function swalIcon(kind, title, text) {
    if (window.Swal) {
      Swal.fire({ icon: kind, title: title, text: text, confirmButtonColor: '#0D6348' });
    } else {
      window.alert(title + '\n\n' + text);
    }
  }

  function showModal(el) {
    if (!el) return;
    if (window.bootstrap && window.bootstrap.Modal) {
      window.bootstrap.Modal.getOrCreateInstance(el).show();
    } else {
      el.classList.add('show');
      el.style.display = 'block';
    }
  }

  function hideModal(el) {
    if (!el) return;
    if (window.bootstrap && window.bootstrap.Modal) {
      window.bootstrap.Modal.getOrCreateInstance(el).hide();
    } else {
      el.classList.remove('show');
      el.style.display = 'none';
    }
  }

  function stopCamera() {
    if (stream) {
      stream.getTracks().forEach(function (track) { track.stop(); });
      stream = null;
    }

    if (video) {
      video.pause();
      video.srcObject = null;
    }

    if (liveWrap) liveWrap.classList.add('d-none');
    if (cameraCtrls) cameraCtrls.classList.add('d-none');
  }

  function setPhoto(dataUrl, sourceLabel) {
    if (!hidden || !preview) return;

    hidden.value = dataUrl;
    preview.src = dataUrl;
    preview.classList.remove('d-none');
    box.classList.add('captured');
    box.dataset.currentPhoto = '1';
    if (removeField) removeField.value = '0';
    if (placeholder) placeholder.classList.add('d-none');
    if (photoCtrls) photoCtrls.classList.remove('d-none');
    if (cameraCtrls) cameraCtrls.classList.add('d-none');
    if (liveWrap) liveWrap.classList.add('d-none');

    if (badge) {
      badge.textContent = sourceLabel || 'Photo ready';
      badge.classList.remove('d-none');
    }

    box.dispatchEvent(new CustomEvent('photo:changed', { detail: { source: sourceLabel || '' } }));
  }

  function clearPhoto() {
    if (hidden) hidden.value = '';
    if (removeField) removeField.value = '1';
    if (preview) {
      preview.src = '';
      preview.classList.add('d-none');
    }
    box.classList.remove('captured');
    box.dataset.currentPhoto = '0';
    if (placeholder) placeholder.classList.remove('d-none');
    if (photoCtrls) photoCtrls.classList.add('d-none');
    if (badge) badge.classList.add('d-none');
    if (fileInput) fileInput.value = '';
  }

  /* ----------------------------------------------------------
     Editor
     ---------------------------------------------------------- */
  function openEditor(dataUrl, sourceLabel) {
    pendingData  = dataUrl;
    editorSource = sourceLabel || 'this device';

    if (!editor) {
      editor = new window.LmsImageEditor({
        canvas: editorCanvas,
        stage: editorStage,
        aspect: isDocument ? 1.42 : 3 / 4,
        maxEdge: isDocument ? 1800 : 1200,
        quality: 0.9
      });
    }

    editor.load(dataUrl).then(function () {
      if (editorZoom) editorZoom.value = '1';
      showModal(editorModal);
    }).catch(function () {
      swalIcon('error', 'Image not readable', 'Please take the photo again.');
    });
  }

  if (editorZoom) {
    editorZoom.addEventListener('input', function () {
      if (editor) editor.setZoom(parseFloat(editorZoom.value) || 1);
    });
  }

  document.querySelectorAll('[data-editor-rotate]').forEach(function (button) {
    button.addEventListener('click', function () {
      if (editor) editor.rotate(parseInt(button.dataset.editorRotate, 10) || 90);
    });
  });

  const editorReset = document.getElementById('btnEditorReset');
  if (editorReset) {
    editorReset.addEventListener('click', function () {
      if (editor) { editor.reset(); if (editorZoom) editorZoom.value = '1'; }
    });
  }

  if (btnEditUse) {
    btnEditUse.addEventListener('click', function () {
      if (!editor) return;
      setPhoto(editor.toDataURL(), editorSource);
      hideModal(editorModal);
    });
  }

  if (editorModal) {
    editorModal.addEventListener('hidden.bs.modal', function () {
      pendingData = null;
    });
  }

  /* ----------------------------------------------------------
     Direct camera
     ---------------------------------------------------------- */
  async function startCamera() {
    if (!hasCameraApi) {
      swalIcon('info', 'Camera not available',
        'This browser cannot open a camera. Use Upload image, or capture using your phone.');
      return;
    }

    if (!secureContext) {
      swalIcon('warning', 'HTTPS is required',
        'Browsers only allow camera access on HTTPS (or localhost). Use the phone capture link or upload a photo instead.');
      return;
    }

    stopCamera();

    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: {
          facingMode: { ideal: facing },
          width: { ideal: isDocument ? 1920 : 1280 },
          height: { ideal: isDocument ? 1440 : 960 }
        },
        audio: false
      });

      video.srcObject = stream;
      await video.play();

      if (liveWrap) liveWrap.classList.remove('d-none');
      if (placeholder) placeholder.classList.add('d-none');
      if (preview) preview.classList.add('d-none');
      if (cameraCtrls) cameraCtrls.classList.remove('d-none');
      if (photoCtrls) photoCtrls.classList.add('d-none');
      box.classList.add('live');
    } catch (error) {
      stopCamera();

      const name = error && error.name ? error.name : '';
      let message = 'Allow camera access in the browser, or use another capture method.';

      if (name === 'NotAllowedError' || name === 'SecurityError') {
        message = 'Camera permission was denied. Allow it in the browser settings, or use the phone/upload options.';
      } else if (name === 'NotFoundError' || name === 'OverconstrainedError') {
        message = 'No usable camera was found on this device. Use the phone capture or upload option.';
      } else if (name === 'NotReadableError') {
        message = 'The camera is already in use by another application. Close it and try again.';
      }

      swalIcon('warning', 'Camera unavailable', message);
    }
  }

  function captureFrame() {
    if (!video || !stream) return;

    const width  = video.videoWidth;
    const height = video.videoHeight;

    if (!width || !height) {
      swalIcon('info', 'Camera starting', 'Wait a moment and press Capture again.');
      return;
    }

    const snap = document.createElement('canvas');
    const max  = 2200;
    const scale = Math.min(1, max / Math.max(width, height));

    snap.width  = Math.round(width * scale);
    snap.height = Math.round(height * scale);
    snap.getContext('2d').drawImage(video, 0, 0, snap.width, snap.height);

    stopCamera();
    openEditor(snap.toDataURL('image/jpeg', 0.94), 'Camera');
  }

  if (btnThis) btnThis.addEventListener('click', startCamera);
  if (btnShoot) btnShoot.addEventListener('click', captureFrame);

  if (btnSwitch) {
    btnSwitch.addEventListener('click', function () {
      facing = facing === 'user' ? 'environment' : 'user';
      startCamera();
    });
  }

  if (btnStop) {
    btnStop.addEventListener('click', function () {
      stopCamera();
      const keep = (hidden && hidden.value) || box.dataset.currentPhoto === '1';

      if (placeholder) placeholder.classList.toggle('d-none', !!keep);
      if (preview) preview.classList.toggle('d-none', !keep);
      if (photoCtrls) photoCtrls.classList.toggle('d-none', !keep);
    });
  }

  if (btnRetake) {
    btnRetake.addEventListener('click', function () {
      startCamera();
    });
  }

  if (btnReplace) {
    btnReplace.addEventListener('click', function () {
      if (fileInput) fileInput.click();
    });
  }

  if (btnRemove) {
    btnRemove.addEventListener('click', function () {
      if (window.Swal) {
        Swal.fire({
          icon: 'warning',
          title: 'Remove the photo?',
          text: 'The customer will need a new photo before saving.',
          showCancelButton: true,
          confirmButtonText: 'Remove',
          confirmButtonColor: '#B91C1C',
          cancelButtonColor: '#5C7068'
        }).then(function (result) {
          if (result.isConfirmed) clearPhoto();
        });
      } else {
        clearPhoto();
      }
    });
  }

  if (fileInput) {
    fileInput.addEventListener('change', function () {
      const file = fileInput.files && fileInput.files[0];
      if (!file) return;

      if (file.size > 12 * 1024 * 1024) {
        swalIcon('warning', 'Image too large', 'Choose an image smaller than 12 MB.');
        fileInput.value = '';
        return;
      }

      const reader = new FileReader();
      reader.onload = function (event) { openEditor(event.target.result, 'Upload'); };
      reader.readAsDataURL(file);
    });
  }

  /* ----------------------------------------------------------
     QR pairing
     ---------------------------------------------------------- */
  function setPairStatus(text, tone) {
    if (!pairStatus) return;
    pairStatus.textContent = text;
    pairStatus.className = 'pair-status' + (tone ? ' ' + tone : '');
  }

  function pairTick() {
    if (!pairTimer || !pairExpiresAt) return;

    const left = Math.max(0, Math.floor((pairExpiresAt - Date.now()) / 1000));
    const min  = Math.floor(left / 60);
    const sec  = left % 60;

    pairTimer.textContent = left > 0
      ? 'Expires in ' + min + ':' + (sec < 10 ? '0' : '') + sec
      : 'Code expired';

    pairTimer.classList.toggle('low', left <= 120);
  }

  function stopPolling() {
    if (pollTimerId) {
      clearInterval(pollTimerId);
      pollTimerId = null;
    }
    if (pairTimerId) {
      clearInterval(pairTimerId);
      pairTimerId = null;
    }
  }

  function startCountdown(expiresIn) {
    pairExpiresAt = Date.now() + Math.max(0, parseInt(expiresIn, 10) || 0) * 1000;
    pairTick();

    if (pairTimerId) clearInterval(pairTimerId);
    pairTimerId = setInterval(pairTick, 1000);
  }

  function renderQr(url) {
    if (!pairQr) return;

    if (pairLink) pairLink.href = url;

    if (typeof qrcode !== 'function') {
      pairQr.innerHTML = '<div class="pair-qr-fallback"><i class="bi bi-qr-code"></i><span>QR library not loaded</span></div>';
      return;
    }

    try {
      const qr = qrcode(0, 'M');
      qr.addData(url);
      qr.make();
      pairQr.innerHTML = qr.createImgTag(6, 8);
      const img = pairQr.querySelector('img');
      if (img) img.alt = 'Scan to capture with a phone';
    } catch (error) {
      pairQr.innerHTML = '<div class="pair-qr-fallback"><i class="bi bi-qr-code"></i><span>Could not render QR</span></div>';
    }
  }

  async function createPairSession() {
    if (pairBusy) return;
    pairBusy = true;
    setPairStatus('Creating a secure capture link…', '');

    try {
      const body = new FormData();
      body.append('action', 'create');
      body.append('purpose', purpose);
      body.append('draft_id', draftId);

      const response = await fetch(BASE_URL + 'camera_session.php', {
        method: 'POST',
        headers: postHeaders(),
        body: body
      });
      const data = await response.json();

      if (!response.ok || !data.ok) {
        throw new Error(data.error || 'Could not create the capture link.');
      }

      pairSession = { id: data.id, url: data.url };
      lastAssetId = 0;

      renderQr(data.url);
      setPairStatus('Waiting for the phone…', 'waiting');
      startCountdown(data.expires_in);

      if (btnPairRenew) btnPairRenew.classList.add('d-none');
      if (pairLink) pairLink.classList.remove('d-none');

      startPolling();
    } catch (error) {
      setPairStatus(error.message || 'Could not create the capture link.', 'error');
    } finally {
      pairBusy = false;
    }
  }

  function startPolling() {
    if (pollTimerId) clearInterval(pollTimerId);
    if (!pairSession) return;

    pollTimerId = setInterval(pollSession, 2000);
    pollSession();
  }

  async function pollSession() {
    if (!pairSession || pairBusy) return;

    try {
      const response = await fetch(
        BASE_URL + 'camera_session.php?action=status&id=' + encodeURIComponent(pairSession.id),
        { headers: { 'Accept': 'application/json' } }
      );
      const data = await response.json();

      if (!response.ok || !data.ok) {
        throw new Error(data.error || 'Capture session unavailable.');
      }

      if (data.expires_in !== undefined) startCountdown(data.expires_in);

      if (data.status === 'expired') {
        setPairStatus('The code expired. Generate a new one.', 'error');
        if (btnPairRenew) btnPairRenew.classList.remove('d-none');
        stopPolling();
        return;
      }

      if (data.status === 'cancelled') {
        setPairStatus('Capture cancelled.', 'error');
        stopPolling();
        hideModal(pairModal);
        return;
      }

      if (data.status === 'completed') {
        stopPolling();
        hideModal(pairModal);
        return;
      }

      if (data.asset && data.asset.id && data.asset.id !== lastAssetId) {
        lastAssetId = data.asset.id;
        setPairStatus('Image received — loading preview…', 'ok');
        stopPolling();

        const dataUrl = await fetchAsset(data.asset.url);
        setPhoto(dataUrl, 'Phone camera');

        // Tell the server the PC accepted the image; the temporary
        // asset can then be cleaned up by the maintenance script.
        const complete = new FormData();
        complete.append('action', 'complete');
        complete.append('id', pairSession.id);
        fetch(BASE_URL + 'camera_session.php', {
          method: 'POST',
          headers: postHeaders(),
          body: complete
        }).catch(function () {});

        setPairStatus('Photo received. You can close the phone page.', 'ok');
        setTimeout(function () { hideModal(pairModal); }, 900);
        return;
      }

      if (data.status === 'uploaded') {
        setPairStatus('Phone finished uploading. Preparing preview…', 'waiting');
      } else {
        setPairStatus('Waiting for the phone…', 'waiting');
      }
    } catch (error) {
      setPairStatus(error.message || 'Connection lost. Retrying…', 'error');
    }
  }

  async function fetchAsset(url) {
    const response = await fetch(url, { credentials: 'same-origin' });

    if (!response.ok) {
      throw new Error('The captured image could not be loaded.');
    }

    const blob = await response.blob();

    if (blob.size > 12 * 1024 * 1024) {
      throw new Error('The captured image is too large.');
    }

    return new Promise(function (resolve, reject) {
      const reader = new FileReader();
      reader.onload = function () { resolve(reader.result); };
      reader.onerror = function () { reject(new Error('The captured image could not be read.')); };
      reader.readAsDataURL(blob);
    });
  }

  if (btnPhone) {
    btnPhone.addEventListener('click', function () {
      if (pairSession) {
        showModal(pairModal);
        startPolling();
        return;
      }
      showModal(pairModal);
      createPairSession();
    });
  }

  if (btnPairRenew) {
    btnPairRenew.addEventListener('click', async function () {
      if (!pairSession) {
        await createPairSession();
        return;
      }

      try {
        const body = new FormData();
        body.append('action', 'renew');
        body.append('id', pairSession.id);

        const response = await fetch(BASE_URL + 'camera_session.php', {
          method: 'POST',
          headers: postHeaders(),
          body: body
        });
        const data = await response.json();

        if (!response.ok || !data.ok) throw new Error(data.error || 'Could not renew.');

        setPairStatus('Waiting for the phone…', 'waiting');
        startCountdown(parseInt(data.expires_in, 10) || 900);
        btnPairRenew.classList.add('d-none');
        startPolling();
      } catch (error) {
        setPairStatus(error.message || 'Could not renew the code.', 'error');
      }
    });
  }

  if (btnPairCancel) {
    btnPairCancel.addEventListener('click', async function () {
      stopPolling();

      if (pairSession) {
        try {
          const body = new FormData();
          body.append('action', 'cancel');
          body.append('id', pairSession.id);
          await fetch(BASE_URL + 'camera_session.php', {
            method: 'POST',
            headers: postHeaders(),
            body: body
          });
        } catch (error) {
          // Non-fatal: the session will expire on its own.
        }
      }

      pairSession = null;
      hideModal(pairModal);
    });
  }

  if (pairModal) {
    pairModal.addEventListener('hidden.bs.modal', function () {
      stopPolling();
    });
  }

  /* ----------------------------------------------------------
     Lifecycle
     ---------------------------------------------------------- */
  window.addEventListener('pagehide', function () {
    stopCamera();
    stopPolling();
  });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      stopCamera();
    }
  });

  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function () {
      stopCamera();
      stopPolling();
    });
  });
})();
