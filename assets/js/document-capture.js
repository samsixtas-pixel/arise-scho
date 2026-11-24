/* ============================================================
   document-capture.js — capture/upload identity documents
   ------------------------------------------------------------
   Used on the customer profile "Documents" tab. Supports:
   - direct camera (phone/tablet/webcam) + editor
   - QR pairing so a phone acts as the camera
   - plain image upload
   Confirmed images are posted to document_capture.php and the
   page reloads the document list.
   ============================================================ */
(function () {
  'use strict';

  const panel = document.getElementById('docCapturePanel');
  if (!panel) return;

  const customerId = panel.dataset.customer || '';
  const csrfToken  = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  const msgs = {
    cameraUnavailable: panel.dataset.msgCameraUnavailable || 'Camera not available on this device.',
    httpsRequired:     panel.dataset.msgHttps || 'Camera access requires HTTPS (or localhost).',
    permission:        panel.dataset.msgPermission || 'Camera permission was denied.',
    saved:             panel.dataset.msgSaved || 'Document added to the customer file.',
    failed:            panel.dataset.msgFailed || 'The image could not be saved. Please try again.',
    uploading:         panel.dataset.msgUploading || 'Saving the document…',
    waiting:           panel.dataset.msgWaiting || 'Waiting for the phone…',
    received:          panel.dataset.msgReceived || 'Image received from the phone.',
    expired:           panel.dataset.msgExpired || 'The capture code expired. Create a new one.',
    cancelled:         panel.dataset.msgCancelled || 'Capture cancelled.'
  };

  const categorySelect = document.getElementById('docCaptureCategory');
  const btnThis        = document.getElementById('docCaptureThis');
  const btnPhone       = document.getElementById('docCapturePhone');
  const fileInput      = document.getElementById('docCaptureFile');
  const liveWrap       = document.getElementById('docCaptureLive');
  const video          = document.getElementById('docCaptureVideo');
  const btnShoot       = document.getElementById('docCaptureShoot');
  const btnSwitch      = document.getElementById('docCaptureSwitch');
  const btnStop        = document.getElementById('docCaptureStop');
  const statusEl       = document.getElementById('docCaptureStatus');
  const previewWrap    = document.getElementById('docCapturePreviewWrap');
  const previewImg     = document.getElementById('docCapturePreview');

  const editorModal  = document.getElementById('docEditorModal');
  const editorCanvas = document.getElementById('docEditorCanvas');
  const editorStage  = document.getElementById('docEditorStage');
  const editorZoom   = document.getElementById('docEditorZoom');
  const btnEditorUse = document.getElementById('docEditorUse');
  const btnEditorReset = document.getElementById('docEditorReset');

  const pairModal   = document.getElementById('docPairModal');
  const pairQr      = document.getElementById('docPairQr');
  const pairStatus  = document.getElementById('docPairStatus');
  const pairTimerEl = document.getElementById('docPairTimer');
  const pairRenew   = document.getElementById('docPairRenew');
  const pairCancel  = document.getElementById('docPairCancel');

  const coarsePointer = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  const smallScreen   = Math.min(window.screen.width || 0, window.screen.height || 0) <= 820;
  const isHandheld    = coarsePointer && smallScreen;
  const hasCameraApi  = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  const secureContext = window.isSecureContext
    || location.hostname === 'localhost'
    || location.hostname === '127.0.0.1';

  let stream = null;
  let facing = 'environment';
  let editor = null;
  let editorSource = '';

  let pairSession = null;
  let pollTimer = null;
  let tickTimer = null;
  let expiresAt = 0;
  let lastAsset = 0;
  let busy = false;

  /* ---------------------------------------------------------- */
  function setStatus(text, tone) {
    if (!statusEl) return;
    statusEl.textContent = text || '';
    statusEl.className = 'form-text doc-capture-status' + (tone ? ' ' + tone : '');
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
      stream.getTracks().forEach(function (t) { t.stop(); });
      stream = null;
    }
    if (video) {
      video.pause();
      video.srcObject = null;
    }
    if (liveWrap) liveWrap.classList.add('d-none');
  }

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    if (tickTimer) { clearInterval(tickTimer); tickTimer = null; }
  }

  function purposeFor(category) {
    if (category === 'passport') return 'passport';
    if (category === 'nida') return 'nida';
    if (category === 'portrait') return 'portrait';
    return 'document';
  }

  /* ----------------------------------------------------------
     Editor
     ---------------------------------------------------------- */
  function openEditor(dataUrl, source) {
    editorSource = source || 'camera';

    if (!editor) {
      editor = new window.LmsImageEditor({
        canvas: editorCanvas,
        stage: editorStage,
        aspect: 1.42,
        maxEdge: 1800,
        quality: 0.9
      });
    }

    editor.load(dataUrl).then(function () {
      if (editorZoom) editorZoom.value = '1';
      showModal(editorModal);
    }).catch(function () {
      setStatus(msgs.failed, 'error');
    });
  }

  if (editorZoom) {
    editorZoom.addEventListener('input', function () {
      if (editor) editor.setZoom(parseFloat(editorZoom.value) || 1);
    });
  }

  document.querySelectorAll('[data-doc-rotate]').forEach(function (button) {
    button.addEventListener('click', function () {
      if (editor) editor.rotate(parseInt(button.dataset.docRotate, 10) || 90);
    });
  });

  if (btnEditorReset) {
    btnEditorReset.addEventListener('click', function () {
      if (editor) { editor.reset(); if (editorZoom) editorZoom.value = '1'; }
    });
  }

  if (btnEditorUse) {
    btnEditorUse.addEventListener('click', function () {
      if (!editor) return;
      const dataUrl = editor.toDataURL();
      hideModal(editorModal);
      upload(dataUrl, editorSource);
    });
  }

  /* ----------------------------------------------------------
     Upload to the server
     ---------------------------------------------------------- */
  async function upload(dataUrl, source) {
    const category = categorySelect ? categorySelect.value : 'supporting';
    setStatus(msgs.uploading, 'waiting');

    if (previewImg) previewImg.src = dataUrl;
    if (previewWrap) previewWrap.classList.remove('d-none');

    const body = new FormData();
    body.append('customer_id', customerId);
    body.append('category', category);
    body.append('image', dataUrl);

    const headers = { 'Accept': 'application/json' };
    if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

    try {
      const response = await fetch(BASE_URL + 'document_capture.php', {
        method: 'POST',
        headers: headers,
        body: body
      });
      const data = await response.json();

      if (!response.ok || !data.ok) {
        throw new Error(data.error || msgs.failed);
      }

      setStatus((source ? source + ' · ' : '') + msgs.saved, 'ok');

      if (window.Swal) {
        Swal.fire({
          icon: 'success',
          title: msgs.saved,
          timer: 1200,
          showConfirmButton: false
        });
      }

      setTimeout(function () { window.location.reload(); }, 900);
    } catch (error) {
      setStatus(error.message || msgs.failed, 'error');
    }
  }

  /* ----------------------------------------------------------
     Direct camera
     ---------------------------------------------------------- */
  async function startCamera() {
    if (!hasCameraApi) {
      setStatus(msgs.cameraUnavailable, 'error');
      return;
    }

    if (!secureContext) {
      setStatus(msgs.httpsRequired, 'error');
      return;
    }

    stopCamera();

    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: {
          facingMode: { ideal: facing },
          width: { ideal: 1920 },
          height: { ideal: 1440 }
        },
        audio: false
      });

      video.srcObject = stream;
      await video.play();

      if (liveWrap) liveWrap.classList.remove('d-none');
      setStatus('', '');
    } catch (error) {
      stopCamera();
      setStatus(msgs.permission, 'error');
    }
  }

  function shoot() {
    if (!video || !stream) return;

    const width = video.videoWidth;
    const height = video.videoHeight;

    if (!width || !height) {
      setStatus(msgs.cameraUnavailable, 'error');
      return;
    }

    const canvas = document.createElement('canvas');
    const max = 2200;
    const scale = Math.min(1, max / Math.max(width, height));

    canvas.width = Math.round(width * scale);
    canvas.height = Math.round(height * scale);
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    stopCamera();
    openEditor(canvas.toDataURL('image/jpeg', 0.94), 'Camera');
  }

  if (btnThis) btnThis.addEventListener('click', startCamera);
  if (btnShoot) btnShoot.addEventListener('click', shoot);

  if (btnSwitch) {
    btnSwitch.addEventListener('click', function () {
      facing = facing === 'user' ? 'environment' : 'user';
      startCamera();
    });
  }

  if (btnStop) btnStop.addEventListener('click', stopCamera);

  if (fileInput) {
    fileInput.addEventListener('change', function () {
      const file = fileInput.files && fileInput.files[0];
      if (!file) return;

      if (file.size > 12 * 1024 * 1024) {
        setStatus(msgs.failed, 'error');
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

  function tick() {
    if (!pairTimerEl || !expiresAt) return;

    const left = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
    const min = Math.floor(left / 60);
    const sec = left % 60;

    pairTimerEl.textContent = left > 0
      ? min + ':' + (sec < 10 ? '0' : '') + sec
      : '0:00';
  }

  function startCountdown(seconds) {
    expiresAt = Date.now() + Math.max(0, parseInt(seconds, 10) || 0) * 1000;
    tick();
    if (tickTimer) clearInterval(tickTimer);
    tickTimer = setInterval(tick, 1000);
  }

  function renderQr(url) {
    if (!pairQr) return;

    if (typeof qrcode !== 'function') {
      pairQr.innerHTML = '<div class="pair-qr-fallback"><i class="bi bi-qr-code"></i></div>';
      return;
    }

    try {
      const qr = qrcode(0, 'M');
      qr.addData(url);
      qr.make();
      pairQr.innerHTML = qr.createImgTag(6, 8);
    } catch (error) {
      pairQr.innerHTML = '<div class="pair-qr-fallback"><i class="bi bi-qr-code"></i></div>';
    }
  }

  function postHeaders() {
    const headers = { 'Accept': 'application/json' };
    if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
    return headers;
  }

  async function createSession() {
    if (busy) return;
    busy = true;

    const category = categorySelect ? categorySelect.value : 'document';
    setPairStatus(msgs.waiting, 'waiting');

    try {
      const body = new FormData();
      body.append('action', 'create');
      body.append('purpose', purposeFor(category));
      body.append('draft_id', 'doc-' + customerId + '-' + category);

      const response = await fetch(BASE_URL + 'camera_session.php', {
        method: 'POST',
        headers: postHeaders(),
        body: body
      });
      const data = await response.json();

      if (!response.ok || !data.ok) {
        throw new Error(data.error || msgs.failed);
      }

      pairSession = { id: data.id, url: data.url };
      lastAsset = 0;

      renderQr(data.url);
      setPairStatus(msgs.waiting, 'waiting');
      startCountdown(data.expires_in);
      if (pairRenew) pairRenew.classList.add('d-none');

      startPolling();
    } catch (error) {
      setPairStatus(error.message || msgs.failed, 'error');
    } finally {
      busy = false;
    }
  }

  function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    if (!pairSession) return;

    pollTimer = setInterval(poll, 2000);
    poll();
  }

  async function poll() {
    if (!pairSession || busy) return;

    try {
      const response = await fetch(
        BASE_URL + 'camera_session.php?action=status&id=' + encodeURIComponent(pairSession.id),
        { headers: { 'Accept': 'application/json' } }
      );
      const data = await response.json();

      if (!response.ok || !data.ok) throw new Error(data.error || msgs.failed);

      if (data.expires_in !== undefined) startCountdown(data.expires_in);

      if (data.status === 'expired') {
        setPairStatus(msgs.expired, 'error');
        if (pairRenew) pairRenew.classList.remove('d-none');
        stopPolling();
        return;
      }

      if (data.status === 'cancelled') {
        setPairStatus(msgs.cancelled, 'error');
        stopPolling();
        return;
      }

      if (data.asset && data.asset.id && data.asset.id !== lastAsset) {
        lastAsset = data.asset.id;
        busy = true;
        setPairStatus(msgs.received, 'ok');
        stopPolling();

        try {
          const assetResponse = await fetch(data.asset.url, { credentials: 'same-origin' });

          if (!assetResponse.ok) throw new Error(msgs.failed);

          const blob = await assetResponse.blob();
          const dataUrl = await new Promise(function (resolve, reject) {
            const reader = new FileReader();
            reader.onload = function () { resolve(reader.result); };
            reader.onerror = reject;
            reader.readAsDataURL(blob);
          });

          const complete = new FormData();
          complete.append('action', 'complete');
          complete.append('id', pairSession.id);
          fetch(BASE_URL + 'camera_session.php', {
            method: 'POST',
            headers: postHeaders(),
            body: complete
          }).catch(function () {});

          hideModal(pairModal);
          upload(dataUrl, 'Phone camera');
        } catch (error) {
          setPairStatus(error.message || msgs.failed, 'error');
        } finally {
          busy = false;
        }
      }
    } catch (error) {
      setPairStatus(error.message || msgs.failed, 'error');
    }
  }

  if (btnPhone) {
    btnPhone.addEventListener('click', function () {
      showModal(pairModal);
      if (!pairSession) createSession();
      else startPolling();
    });
  }

  if (pairRenew) {
    pairRenew.addEventListener('click', async function () {
      if (!pairSession) { createSession(); return; }

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

        if (!response.ok || !data.ok) throw new Error(data.error || msgs.failed);

        startCountdown(parseInt(data.expires_in, 10) || 900);
        setPairStatus(msgs.waiting, 'waiting');
        pairRenew.classList.add('d-none');
        startPolling();
      } catch (error) {
        setPairStatus(error.message || msgs.failed, 'error');
      }
    });
  }

  if (pairCancel) {
    pairCancel.addEventListener('click', function () {
      stopPolling();

      if (pairSession) {
        const body = new FormData();
        body.append('action', 'cancel');
        body.append('id', pairSession.id);
        fetch(BASE_URL + 'camera_session.php', {
          method: 'POST',
          headers: postHeaders(),
          body: body
        }).catch(function () {});
      }

      pairSession = null;
      hideModal(pairModal);
    });
  }

  if (pairModal) {
    pairModal.addEventListener('hidden.bs.modal', stopPolling);
  }

  window.addEventListener('pagehide', function () {
    stopCamera();
    stopPolling();
  });
})();
