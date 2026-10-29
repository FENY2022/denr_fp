(() => {
  'use strict';
  const html = document.documentElement;
  const safeStorage = (() => { try { return window.localStorage; } catch (_) { return null; } })();
  const sizeControl = document.getElementById('textSizeSelect');
  const sizeKey = 'fpoms.ui.textSize';
  const allowed = ['small', 'medium', 'large', 'xl', 'xxl', 'xxxl'];
  const applyTextSize = (size) => {
    const value = allowed.includes(size) ? size : 'medium';
    html.dataset.textSize = value;
    if (sizeControl) sizeControl.value = value;
    safeStorage?.setItem(sizeKey, value);
  };
  applyTextSize(safeStorage?.getItem(sizeKey) || 'medium');
  sizeControl?.addEventListener('change', () => applyTextSize(sizeControl.value));

  const collapseButton = document.querySelector('[data-sidebar-toggle]');
  const collapsedKey = 'fpoms.sidebar.collapsed';
  const setCollapsed = (collapsed) => {
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    if (collapseButton) {
      collapseButton.setAttribute('aria-expanded', String(!collapsed));
      collapseButton.setAttribute('aria-label', collapsed ? 'Expand navigation sidebar' : 'Collapse navigation sidebar');
      collapseButton.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }
    safeStorage?.setItem(collapsedKey, collapsed ? '1' : '0');
  };
  if (collapseButton) {
    setCollapsed(safeStorage?.getItem(collapsedKey) === '1');
    collapseButton.addEventListener('click', () => setCollapsed(!document.body.classList.contains('sidebar-collapsed')));
  }
  const sidebarScroll = document.querySelector('#appSidebar .sidebar-scroll');
  const scrollKey = 'fpoms.sidebar.scroll';
  if (sidebarScroll && safeStorage) {
    const previous = Number(safeStorage.getItem(scrollKey) || 0);
    if (Number.isFinite(previous) && previous >= 0) sidebarScroll.scrollTop = previous;
    sidebarScroll.addEventListener('scroll', () => safeStorage.setItem(scrollKey, String(sidebarScroll.scrollTop)), {passive:true});
  }

  const userManagementPage = document.getElementById('user-management-search');
  const userManagementScrollKey = 'fpoms.userManagement.scroll';
  let sessionStore = null;
  try { sessionStore = window.sessionStorage; } catch (_) {}
  if (userManagementPage && sessionStore) {
    const savedPosition = sessionStore.getItem(userManagementScrollKey);
    const previous = Number(savedPosition);
    if (savedPosition !== null && Number.isFinite(previous) && previous >= 0) {
      sessionStore.removeItem(userManagementScrollKey);
      window.addEventListener('load', () => window.scrollTo(0, previous), {once:true});
    }

    document.addEventListener('submit', event => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
      const fromManagementModal = form.querySelector(
        'input[name="setup_origin"][value="user_management_modal"],'
        + 'input[name="edit_origin"][value="user_management_modal"]'
      );
      if (fromManagementModal) sessionStore.setItem(userManagementScrollKey, String(window.scrollY));
    });
  }

  if ('serviceWorker' in navigator && (window.isSecureContext || location.hostname === 'localhost')) {
    window.addEventListener('load', () => navigator.serviceWorker.register('service-worker.js', {scope:'./'}).catch(() => {}));
  }
  const installButton = document.getElementById('installAppButton');
  const installHelp = document.getElementById('installAppHelp');
  let installEvent = null;
  const help = (message) => { if (installHelp) installHelp.textContent = message; };
  const markInstalled = () => {
    if (installButton) { installButton.disabled = true; installButton.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Installed'; }
    help('FP-OMS is installed. Find it on your desktop, Start menu, or home screen.');
  };
  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();installEvent = event;
    if (installButton) installButton.disabled = false;
  });
  window.addEventListener('appinstalled', () => { installEvent = null; markInstalled(); });
  if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) markInstalled();
  installButton?.addEventListener('click', async () => {
    if (installEvent) {
      const event = installEvent; installEvent = null;
      try { await event.prompt(); const answer = await event.userChoice;
        help(answer?.outcome === 'accepted' ? 'Installation requested. Follow your browser instructions.' : 'Installation dismissed. You can try again from the browser menu.');
      } catch (_) {help('Use your browser menu and choose Install app or Add to Home Screen.');}
      return;
    }
    const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
    help(ios ? 'On iPhone/iPad, tap Share and choose Add to Home Screen.' : 'Open your browser menu and select Install app or Add to Home Screen. Chrome and Edge usually require HTTPS (or localhost).');
  });

  // FP-OMS global page loader: shared by landing, public, and authenticated pages.
  const loader = document.createElement('div');
  loader.id = 'fpomsPageLoader';
  loader.className = 'page-loader';
  loader.setAttribute('aria-hidden', 'true');
  loader.innerHTML = '<div class="page-loader-panel" role="status" aria-live="polite">'
    + '<img class="page-loader-logo" src="assets/fpoms-icon-192.png?v=24" alt="">'
    + '<div class="page-loader-brand">DENR FP-OMS</div>'
    + '<div class="page-loader-subtitle">Feasibility Permit Online Management System</div>'
    + '<span class="page-loader-spinner" aria-hidden="true"></span>'
    + '<div class="page-loader-message">Loading DENR FP-OMS...</div>'
    + '<div class="page-loader-help">Please wait</div></div>';
  document.body.appendChild(loader);

  let loaderVisible = false;
  const showPageLoader = () => {
    loaderVisible = true;
    loader.classList.add('is-visible');
    loader.setAttribute('aria-hidden', 'false');
    document.body.classList.add('page-loading');
  };
  const hidePageLoader = () => {
    loaderVisible = false;
    loader.classList.remove('is-visible');
    loader.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('page-loading');
  };

  showPageLoader();
  const finishInitialLoad = () => requestAnimationFrame(() => requestAnimationFrame(hidePageLoader));
  if (document.readyState === 'complete') finishInitialLoad();
  else window.addEventListener('load', finishInitialLoad, {once:true});

  document.addEventListener('click', event => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('a[href]');
    if (!link || link.target === '_blank' || link.hasAttribute('download')) return;
    const rawHref = link.getAttribute('href') || '';
    if (!rawHref || rawHref.startsWith('#') || rawHref.startsWith('javascript:')) return;
    let target;
    try { target = new URL(link.href, window.location.href); } catch (_) { return; }
    if (target.origin !== window.location.origin) return;
    if (target.pathname === window.location.pathname && target.search === window.location.search && target.hash) return;
    const path = target.pathname.toLowerCase();
    if (path.includes('/download-reg') || path.includes('/download_reg')
      || path.includes('/permit-document') || path.includes('/permit_document')) return;
    showPageLoader();
  }, true);

  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || (form.target && form.target !== '_self')) return;
    if (form.hasAttribute('data-inline-submit-loading')) return;
    showPageLoader();
    setTimeout(() => { if (event.defaultPrevented && loaderVisible) hidePageLoader(); }, 0);
  }, true);

  window.addEventListener('beforeunload', showPageLoader);
  window.addEventListener('pageshow', event => {
    if (event.persisted) hidePageLoader();
  });


  // Forgot Password: keep the form visible while SMTP processing is in progress,
  // prevent duplicate submissions, and retain the email value in the POST.
  const forgotPasswordForm = document.getElementById('forgotPasswordForm');
  const resetForgotPasswordSendingState = () => {
    if (!forgotPasswordForm) return;
    forgotPasswordForm.dataset.submitting = '0';
    forgotPasswordForm.classList.remove('is-sending');
    const email = forgotPasswordForm.querySelector('input[name="email"]');
    const submit = document.getElementById('forgotPasswordSubmit');
    const spinner = document.getElementById('forgotPasswordSpinner');
    const buttonText = document.getElementById('forgotPasswordButtonText');
    const status = document.getElementById('forgotPasswordSendingStatus');
    if (email) {
      email.readOnly = false;
      email.removeAttribute('aria-readonly');
    }
    if (submit) submit.disabled = false;
    spinner?.classList.add('d-none');
    if (buttonText) buttonText.textContent = 'Send Reset Link';
    status?.classList.add('d-none');
  };

  forgotPasswordForm?.addEventListener('submit', event => {
    if (!forgotPasswordForm.checkValidity()) return;
    if (forgotPasswordForm.dataset.submitting === '1') {
      event.preventDefault();
      return;
    }

    forgotPasswordForm.dataset.submitting = '1';
    forgotPasswordForm.classList.add('is-sending');

    const email = forgotPasswordForm.querySelector('input[name="email"]');
    const submit = document.getElementById('forgotPasswordSubmit');
    const spinner = document.getElementById('forgotPasswordSpinner');
    const buttonText = document.getElementById('forgotPasswordButtonText');
    const status = document.getElementById('forgotPasswordSendingStatus');

    // readonly keeps the email included in the POST; disabled inputs would be omitted.
    if (email) {
      email.readOnly = true;
      email.setAttribute('aria-readonly', 'true');
    }
    if (submit) submit.disabled = true;
    spinner?.classList.remove('d-none');
    if (buttonText) buttonText.textContent = 'Sending reset link…';
    status?.classList.remove('d-none');
  });

  window.addEventListener('pageshow', event => {
    if (event.persisted) resetForgotPasswordSendingState();
  });


  const generateAdminPassword = document.getElementById('generateAdminPassword');
  const adminPassword = document.getElementById('password');
  const adminPasswordConfirm = document.getElementById('confirm_password');
  const generatedPasswordNotice = document.getElementById('generatedPasswordNotice');
  generateAdminPassword?.addEventListener('click', () => {
    if (!adminPassword || !adminPasswordConfirm || !generatedPasswordNotice || !window.crypto?.getRandomValues) return;
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    const bytes = new Uint32Array(18);
    window.crypto.getRandomValues(bytes);
    let value = '';
    for (const item of bytes) value += alphabet[item % alphabet.length];
    adminPassword.value = value;
    adminPasswordConfirm.value = value;
    generatedPasswordNotice.textContent = 'A secure temporary password was generated and filled into both password fields. Copy it now and share it only through an approved secure channel.';
    generatedPasswordNotice.classList.remove('d-none');
    adminPassword.focus();
    adminPassword.select();
  });


  // Authenticated Profile Photo editor: device file picker + camera + square crop.
  const profileEditForm = document.getElementById('profileEditForm');
  if (profileEditForm) {
    const MAX_PROFILE_BYTES = 2 * 1024 * 1024;
    const ALLOWED_PROFILE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    const CROP_SIZE = 600;

    const sourceInput = document.getElementById('profilePhotoSource');
    const uploadInput = document.getElementById('profilePhotoUpload');
    const chooseButton = document.getElementById('profileChoosePhoto');
    const cameraButton = document.getElementById('profileUseCamera');
    const pendingNotice = document.getElementById('profilePhotoPending');
    const preview = document.getElementById('profilePhotoPreview');

    const cameraModalEl = document.getElementById('profileCameraModal');
    const cameraVideo = document.getElementById('profileCameraVideo');
    const cameraSelect = document.getElementById('profileCameraSelect');
    const cameraRestart = document.getElementById('profileCameraRestart');
    const cameraCapture = document.getElementById('profileCameraCapture');
    const cameraAlert = document.getElementById('profileCameraAlert');

    const adjustModalEl = document.getElementById('profileAdjustModal');
    const cropCanvas = document.getElementById('profileCropCanvas');
    const cropZoom = document.getElementById('profileCropZoom');
    const cropFlip = document.getElementById('profileCropFlip');
    const cropReset = document.getElementById('profileCropReset');
    const cropApply = document.getElementById('profileCropApply');

    let cameraStream = null;
    let cameraFrontFacing = true;
    let cameraRequestGeneration = 0;
    let pendingCapturedBlob = null;
    let previewObjectUrl = null;

    const editor = {
      image: null,
      zoom: 1,
      offsetX: 0,
      offsetY: 0,
      flipX: false,
      dragging: false,
      pointerX: 0,
      pointerY: 0
    };

    const showCameraAlert = (message = '') => {
      if (!cameraAlert) return;
      cameraAlert.textContent = message;
      cameraAlert.classList.toggle('d-none', !message);
    };

    const stopCameraTracks = () => {
      if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
      }
      if (cameraVideo) {
        cameraVideo.pause();
        cameraVideo.srcObject = null;
        cameraVideo.classList.remove('is-mirrored');
      }
    };

    const stopCamera = () => {
      cameraRequestGeneration += 1;
      stopCameraTracks();
    };

    const refreshCameraList = async (selectedId = '') => {
      if (!cameraSelect || !navigator.mediaDevices?.enumerateDevices) return;
      const devices = await navigator.mediaDevices.enumerateDevices();
      const cameras = devices.filter(device => device.kind === 'videoinput');

      cameraSelect.innerHTML = '';
      cameras.forEach((camera, index) => {
        const option = document.createElement('option');
        option.value = camera.deviceId;
        option.textContent = camera.label || `Camera ${index + 1}`;
        if (selectedId && camera.deviceId === selectedId) option.selected = true;
        cameraSelect.appendChild(option);
      });

      cameraSelect.disabled = cameras.length < 2;
    };

    const startCamera = async (deviceId = '') => {
      if (!navigator.mediaDevices?.getUserMedia || !cameraVideo) {
        showCameraAlert('Camera access is not supported by this browser. Use Choose Photo instead.');
        return;
      }

      const requestGeneration = ++cameraRequestGeneration;
      stopCameraTracks();
      showCameraAlert('');

      try {
        const videoConstraints = deviceId
          ? {deviceId: {exact: deviceId}, width: {ideal: 1280}, height: {ideal: 1280}}
          : {facingMode: {ideal: 'user'}, width: {ideal: 1280}, height: {ideal: 1280}};

        const requestedStream = await navigator.mediaDevices.getUserMedia({video: videoConstraints, audio: false});
        if (requestGeneration !== cameraRequestGeneration || !cameraModalEl?.classList.contains('show')) {
          requestedStream.getTracks().forEach(track => track.stop());
          return;
        }
        cameraStream = requestedStream;
        cameraVideo.srcObject = cameraStream;
        await cameraVideo.play();

        const track = cameraStream.getVideoTracks()[0];
        const settings = track?.getSettings?.() || {};
        const label = String(track?.label || '').toLowerCase();
        cameraFrontFacing = settings.facingMode === 'user'
          || (!settings.facingMode && /front|user|facetime|selfie/.test(label));

        cameraVideo.classList.toggle('is-mirrored', cameraFrontFacing);
        await refreshCameraList(String(settings.deviceId || deviceId || ''));
      } catch (error) {
        if (requestGeneration !== cameraRequestGeneration) return;
        stopCameraTracks();
        const denied = error?.name === 'NotAllowedError' || error?.name === 'SecurityError';
        showCameraAlert(denied
          ? 'Camera permission was denied. Allow camera access for this site, then click Restart Camera.'
          : 'The selected camera could not be started. Try another camera or use Choose Photo.');
      }
    };

    const loadImageFromBlob = (blob) => new Promise((resolve, reject) => {
      const objectUrl = URL.createObjectURL(blob);
      const image = new Image();
      image.onload = () => {
        URL.revokeObjectURL(objectUrl);
        resolve(image);
      };
      image.onerror = () => {
        URL.revokeObjectURL(objectUrl);
        reject(new Error('Image could not be opened.'));
      };
      image.src = objectUrl;
    });

    const editorGeometry = () => {
      if (!editor.image) return null;
      const base = Math.max(CROP_SIZE / editor.image.naturalWidth, CROP_SIZE / editor.image.naturalHeight);
      const scale = base * editor.zoom;
      const width = editor.image.naturalWidth * scale;
      const height = editor.image.naturalHeight * scale;
      const maxX = Math.max(0, (width - CROP_SIZE) / 2);
      const maxY = Math.max(0, (height - CROP_SIZE) / 2);
      editor.offsetX = Math.max(-maxX, Math.min(maxX, editor.offsetX));
      editor.offsetY = Math.max(-maxY, Math.min(maxY, editor.offsetY));

      return {
        width,
        height,
        x: (CROP_SIZE - width) / 2 + editor.offsetX,
        y: (CROP_SIZE - height) / 2 + editor.offsetY
      };
    };

    const renderCrop = () => {
      if (!cropCanvas || !editor.image) return;
      const ctx = cropCanvas.getContext('2d');
      const geometry = editorGeometry();
      if (!ctx || !geometry) return;

      ctx.save();
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.clearRect(0, 0, CROP_SIZE, CROP_SIZE);
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, CROP_SIZE, CROP_SIZE);

      if (editor.flipX) {
        ctx.translate(CROP_SIZE, 0);
        ctx.scale(-1, 1);
        ctx.drawImage(
          editor.image,
          CROP_SIZE - geometry.x - geometry.width,
          geometry.y,
          geometry.width,
          geometry.height
        );
      } else {
        ctx.drawImage(editor.image, geometry.x, geometry.y, geometry.width, geometry.height);
      }
      ctx.restore();
    };

    const resetEditor = () => {
      editor.zoom = 1;
      editor.offsetX = 0;
      editor.offsetY = 0;
      editor.flipX = false;
      if (cropZoom) cropZoom.value = '1';
      if (cropFlip) cropFlip.classList.remove('active');
      renderCrop();
    };

    const openAdjustment = async (blob) => {
      try {
        const image = await loadImageFromBlob(blob);
        if (image.naturalWidth < 100 || image.naturalHeight < 100) {
          window.alert('Profile photo must be at least 100×100 pixels.');
          return;
        }

        editor.image = image;
        resetEditor();

        if (window.bootstrap && adjustModalEl) {
          window.bootstrap.Modal.getOrCreateInstance(adjustModalEl).show();
        }
      } catch (_) {
        window.alert('The selected file is not a readable image.');
      }
    };

    chooseButton?.addEventListener('click', () => sourceInput?.click());

    sourceInput?.addEventListener('change', () => {
      const file = sourceInput.files?.[0];
      sourceInput.value = '';
      if (!file) return;

      if (!ALLOWED_PROFILE_TYPES.includes(file.type)) {
        window.alert('Choose a JPG, PNG, or WEBP image.');
        return;
      }
      if (file.size > MAX_PROFILE_BYTES) {
        window.alert('Profile photo must be 2 MB or smaller.');
        return;
      }

      openAdjustment(file);
    });

    cameraModalEl?.addEventListener('shown.bs.modal', () => startCamera(cameraSelect?.value || ''));
    cameraModalEl?.addEventListener('hidden.bs.modal', () => {
      stopCamera();
      if (pendingCapturedBlob) {
        const blob = pendingCapturedBlob;
        pendingCapturedBlob = null;
        openAdjustment(blob);
      }
    });

    cameraSelect?.addEventListener('change', () => startCamera(cameraSelect.value));
    cameraRestart?.addEventListener('click', () => startCamera(cameraSelect?.value || ''));

    cameraCapture?.addEventListener('click', () => {
      if (!cameraVideo || !cameraVideo.videoWidth || !cameraVideo.videoHeight) {
        showCameraAlert('Camera is not ready yet. Wait a moment and try again.');
        return;
      }

      const capture = document.createElement('canvas');
      capture.width = cameraVideo.videoWidth;
      capture.height = cameraVideo.videoHeight;
      const ctx = capture.getContext('2d');
      if (!ctx) return;

      // The front camera preview is mirrored for familiarity, but the raw captured
      // frame remains correctly oriented. The editor offers an explicit Flip control.
      ctx.drawImage(cameraVideo, 0, 0, capture.width, capture.height);
      capture.toBlob(blob => {
        if (!blob) {
          showCameraAlert('Could not capture the camera image.');
          return;
        }
        pendingCapturedBlob = blob;
        window.bootstrap?.Modal.getOrCreateInstance(cameraModalEl)?.hide();
      }, 'image/jpeg', 0.92);
    });

    cropZoom?.addEventListener('input', () => {
      editor.zoom = Math.max(1, Math.min(3, Number(cropZoom.value) || 1));
      renderCrop();
    });

    cropFlip?.addEventListener('click', () => {
      editor.flipX = !editor.flipX;
      cropFlip.classList.toggle('active', editor.flipX);
      renderCrop();
    });

    cropReset?.addEventListener('click', resetEditor);

    cropCanvas?.addEventListener('pointerdown', event => {
      if (!editor.image) return;
      editor.dragging = true;
      editor.pointerX = event.clientX;
      editor.pointerY = event.clientY;
      cropCanvas.setPointerCapture?.(event.pointerId);
      cropCanvas.classList.add('is-dragging');
    });

    cropCanvas?.addEventListener('pointermove', event => {
      if (!editor.dragging || !editor.image) return;
      const rect = cropCanvas.getBoundingClientRect();
      const ratio = rect.width > 0 ? CROP_SIZE / rect.width : 1;
      editor.offsetX += (event.clientX - editor.pointerX) * ratio;
      editor.offsetY += (event.clientY - editor.pointerY) * ratio;
      editor.pointerX = event.clientX;
      editor.pointerY = event.clientY;
      renderCrop();
    });

    const stopCropDrag = event => {
      if (!editor.dragging) return;
      editor.dragging = false;
      cropCanvas?.releasePointerCapture?.(event.pointerId);
      cropCanvas?.classList.remove('is-dragging');
    };
    cropCanvas?.addEventListener('pointerup', stopCropDrag);
    cropCanvas?.addEventListener('pointercancel', stopCropDrag);

    const canvasToJpeg = (quality) => new Promise(resolve => {
      cropCanvas?.toBlob(blob => resolve(blob), 'image/jpeg', quality);
    });

    cropApply?.addEventListener('click', async () => {
      if (!editor.image || !cropCanvas || !uploadInput) return;

      renderCrop();
      let blob = await canvasToJpeg(0.9);
      if (blob && blob.size > MAX_PROFILE_BYTES) blob = await canvasToJpeg(0.78);

      if (!blob || blob.size > MAX_PROFILE_BYTES) {
        window.alert('The adjusted image is still larger than 2 MB. Try a different image.');
        return;
      }

      const file = new File([blob], 'profile-photo.jpg', {type: 'image/jpeg', lastModified: Date.now()});
      if (typeof DataTransfer !== 'function') {
        window.alert('This browser cannot attach the adjusted image to the form. Please use a current Chrome, Edge, Firefox, or Safari version.');
        return;
      }
      const transfer = new DataTransfer();
      transfer.items.add(file);
      uploadInput.files = transfer.files;

      if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
      previewObjectUrl = URL.createObjectURL(blob);

      if (preview) {
        preview.innerHTML = '';
        const image = document.createElement('img');
        image.src = previewObjectUrl;
        image.alt = 'Adjusted profile photo ready to upload';
        preview.appendChild(image);
      }

      pendingNotice?.classList.remove('d-none');
      window.bootstrap?.Modal.getOrCreateInstance(adjustModalEl)?.hide();
    });

    window.addEventListener('beforeunload', () => {
      stopCamera();
      if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
    });
  };

})();
