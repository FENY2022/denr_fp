(() => {
  'use strict';

  document.querySelectorAll('[data-payment-review-modal]').forEach(modal => {
    const form = document.getElementById(modal.dataset.paymentFormId || '');
    const frame = modal.querySelector('[data-payment-receipt-frame]');
    const image = modal.querySelector('[data-payment-receipt-image]');
    const review = modal.querySelector('[data-payment-receipt-review]');
    const message = modal.querySelector('[data-payment-receipt-message]');
    const fileType = modal.querySelector('[data-payment-review-file-type]');
    const zoomOut = modal.querySelector('[data-payment-receipt-zoom-out]');
    const fit = modal.querySelector('[data-payment-receipt-fit]');
    const zoomIn = modal.querySelector('[data-payment-receipt-zoom-in]');
    const zoomLabel = modal.querySelector('[data-payment-receipt-zoom-label]');
    if (!form || !frame || !image || !review || !message || !fileType
        || !zoomOut || !fit || !zoomIn || !zoomLabel) return;

    let objectUrl = '';
    let previewKind = '';
    let fitMode = true;
    let zoomIndex = 2;
    const zoomLevels = [50, 75, 100, 125, 150, 200];
    const setValue = (name, value) => {
      const target = modal.querySelector(`[data-payment-review-value="${name}"]`);
      if (target) target.textContent = value || '—';
    };
    const releasePreview = () => {
      frame.removeAttribute('src');
      image.removeAttribute('src');
      frame.classList.add('d-none');
      image.classList.add('d-none');
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      objectUrl = '';
      previewKind = '';
      fitMode = true;
      zoomIndex = 2;
      image.style.removeProperty('width');
      image.style.removeProperty('max-width');
      image.style.removeProperty('max-height');
      review.classList.remove('is-zoomed');
      zoomOut.disabled = true;
      fit.disabled = true;
      zoomIn.disabled = true;
      zoomLabel.textContent = 'Fit';
    };
    const applyZoom = () => {
      if (!objectUrl || !previewKind) return;
      const percent = zoomLevels[zoomIndex];
      zoomLabel.textContent = fitMode ? 'Fit' : `${percent}%`;
      zoomOut.disabled = !fitMode && zoomIndex === 0;
      zoomIn.disabled = !fitMode && zoomIndex === zoomLevels.length - 1;
      fit.disabled = fitMode;
      if (previewKind === 'pdf') {
        review.classList.remove('is-zoomed');
        frame.src = `${objectUrl}#zoom=${fitMode ? 'page-fit' : percent}`;
        return;
      }
      if (fitMode) {
        review.classList.remove('is-zoomed');
        image.style.removeProperty('width');
        image.style.maxWidth = '100%';
        image.style.maxHeight = 'clamp(320px, 52vh, 620px)';
      } else {
        review.classList.add('is-zoomed');
        image.style.width = `${percent}%`;
        image.style.maxWidth = 'none';
        image.style.maxHeight = 'none';
      }
    };
    const showReview = () => {
      const receiptNumber = form.elements.namedItem('receipt_number')?.value.trim() || '';
      const rawAmount = form.elements.namedItem('amount_paid')?.value.trim() || '';
      const paymentDate = form.elements.namedItem('payment_date')?.value || '';
      const notes = form.elements.namedItem('notes')?.value.trim() || '';
      const fileInput = form.elements.namedItem('official_receipt');
      const file = fileInput?.files?.[0];

      setValue('receipt_number', receiptNumber);
      setValue('amount_paid', rawAmount ? `PHP ${rawAmount}` : '');
      setValue('payment_date', paymentDate);
      setValue('official_receipt', file?.name || '');
      setValue('notes', notes || 'None');

      releasePreview();
      if (!file) {
        message.textContent = 'No Official Receipt selected.';
        message.classList.remove('d-none');
        fileType.textContent = 'No file';
        return;
      }
      objectUrl = URL.createObjectURL(file);
      const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
      const isImage = file.type.startsWith('image/') || /\.(?:jpe?g|png)$/i.test(file.name);
      fileType.textContent = isPdf ? 'PDF Receipt' : (isImage ? 'Image Receipt' : 'Selected file');
      message.classList.add('d-none');
      if (isPdf) {
        previewKind = 'pdf';
        frame.classList.remove('d-none');
        applyZoom();
      } else if (isImage) {
        previewKind = 'image';
        image.src = objectUrl;
        image.classList.remove('d-none');
        applyZoom();
      } else {
        message.textContent = 'Preview is unavailable for this file type.';
        message.classList.remove('d-none');
      }
    };

    modal.addEventListener('show.bs.modal', event => {
      if (!form.checkValidity()) {
        event.preventDefault();
        form.reportValidity();
        form.querySelector(':invalid')?.focus();
        return;
      }
      showReview();
    });
    modal.addEventListener('hidden.bs.modal', releasePreview);
    zoomOut.addEventListener('click', () => {
      if (fitMode) {
        fitMode = false;
        zoomIndex = 2;
      }
      zoomIndex = Math.max(0, zoomIndex - 1);
      applyZoom();
    });
    fit.addEventListener('click', () => {
      fitMode = true;
      applyZoom();
    });
    zoomIn.addEventListener('click', () => {
      if (fitMode) {
        fitMode = false;
        zoomIndex = 2;
      }
      zoomIndex = Math.min(zoomLevels.length - 1, zoomIndex + 1);
      applyZoom();
    });
    form.elements.namedItem('official_receipt')?.addEventListener('change', () => {
      if (modal.classList.contains('show')) showReview();
    });
  });
})();
