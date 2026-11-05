(() => {
  'use strict';

  const modal = document.getElementById('step4-evaluation-modal');
  if (modal && document.body && modal.parentElement !== document.body) {
    document.body.appendChild(modal);
  }

  const form = modal?.querySelector('#step4-decision-form');
  const wizard = modal?.querySelector('[data-step4-wizard]');
  const modern = wizard?.dataset.step4FlowVersion === '2';
  if (!modal || !form || !wizard || !window.bootstrap?.Modal) return;

  const hidden = form.querySelector('[data-step4-decision-value]');
  const validation = form.querySelector('[data-step4-validation]');
  const confirmation = form.querySelector('[data-step4-confirm-panel]');
  const confirmTitle = confirmation?.querySelector('[data-step4-modal-title]');
  const confirmMessage = confirmation?.querySelector('[data-step4-modal-message]');
  const confirmButton = confirmation?.querySelector('[data-step4-confirm]');
  const cancelConfirmation = confirmation?.querySelector('[data-step4-cancel-confirm]');
  const completeButton = modal.querySelector('[data-step4-decision="complete"]');

  const schedule = form.elements.namedItem('field_validation_schedule');
  const overall = form.elements.namedItem('overall_remarks');
  const instructions = form.elements.namedItem('applicant_instructions');
  const assessments = [...form.querySelectorAll('[data-step4-assessment]')];
  const steps = [...form.querySelectorAll('[data-step4-wizard-step]')]
    .sort((a, b) => Number(a.dataset.step4WizardStep) - Number(b.dataset.step4WizardStep));

  const stepLabel = wizard.querySelector('[data-step4-step-label]');
  const stepTitle = wizard.querySelector('[data-step4-step-title]');
  const footerLabel = modal.querySelector('[data-step4-footer-label]');
  const indicators = [...wizard.querySelectorAll('[data-step4-step-indicator]')];
  const progressCount = wizard.querySelector('[data-step4-progress-count]');
  const progressPercent = wizard.querySelector('[data-step4-progress-percent]');
  const progressMeter = wizard.querySelector('[data-step4-progress-meter]');
  const stickyProgress = wizard.querySelector('[data-step4-sticky-progress]');
  const modalBody = modal.querySelector('[data-step4-modal-body]');
  const previousButton = modal.querySelector('[data-step4-prev]');
  const nextButton = modal.querySelector('[data-step4-next]');
  const decisionControls = modal.querySelector('[data-step4-decision-controls]');

  // Child document modal owned by the Step 4 parent modal.
  const child = modal.querySelector('[data-step4-child-modal]');
  const childTitle = child?.querySelector('[data-step4-child-title]');
  const childFiles = child?.querySelector('[data-step4-child-files]');
  const childPreview = child?.querySelector('[data-step4-child-preview]');
  const childClosers = [...(child?.querySelectorAll('[data-step4-child-close]') || [])];
  const backFiles = child?.querySelector('[data-step4-back-files]');
  const previewNavigation = child?.querySelector('[data-step4-preview-navigation]');
  const previewName = child?.querySelector('[data-step4-preview-name]');
  const previewCategory = child?.querySelector('[data-step4-preview-category]');
  const previewArea = child?.querySelector('[data-step4-preview-area]');
  const previewError = child?.querySelector('[data-step4-preview-error]');
  const download = child?.querySelector('[data-step4-download]');
  const filePrevious = child?.querySelector('[data-step4-file-prev]');
  const fileNext = child?.querySelector('[data-step4-file-next]');
  const fileCount = child?.querySelector('[data-step4-file-count]');
  const fullscreenButton = child?.querySelector('[data-step4-fullscreen]');
  const fullscreenLabel = child?.querySelector('[data-step4-fullscreen-label]');

  const assessmentMeta = {
    complete: ['Complete', 'success'],
    incomplete: ['Lacking', 'danger'],
    insufficient: ['Inappropriate', 'warning'],
    not_applicable: ['Not Applicable', 'secondary']
  };

  let currentStep = Math.min(4, Math.max(1, Number(wizard.dataset.step4InitialStep || 1)));
  let currentFileIndex = -1;
  let childOrigin = 'direct';
  let childTrigger = null;
  let lastProgressScrollTop = modalBody?.scrollTop || 0;
  let ignoreProgressScroll = false;

  const fileLinks = [...modal.querySelectorAll('[data-step4-file-link]')]
    .filter(link => ['application/pdf', 'image/png', 'image/jpeg']
      .includes(link.dataset.documentMime));
  const files = [];
  const fileIndices = new Map();

  for (const link of fileLinks) {
    const key = link.dataset.documentId || link.href;
    if (!fileIndices.has(key)) {
      fileIndices.set(key, files.length);
      files.push(link);
    }
  }

  function setProgressCompact(compact) {
    if (!stickyProgress) return;
    stickyProgress.classList.toggle('step4-progress-compact', compact);
    stickyProgress.setAttribute('aria-label', compact
      ? 'Documentary evaluation progress — compact view'
      : 'Documentary evaluation progress — expanded view');
  }

  function updateProgress() {
    const completed = assessments.filter(select => select.value !== '').length;
    const total = assessments.length;
    const percent = total > 0 ? Math.round(completed / total * 100) : 0;
    if (progressCount) progressCount.textContent = String(completed);
    if (progressPercent) progressPercent.textContent = percent + '%';
    if (progressMeter) {
      progressMeter.value = completed;
      progressMeter.max = Math.max(1, total);
      progressMeter.textContent = completed + ' of ' + total;
    }
    if (completeButton) completeButton.disabled = completed < total;
  }

  function showValidation(text) {
    if (!validation) return;
    validation.textContent = text;
    validation.classList.toggle('d-none', !text);
  }

  function clearPreview() {
    previewArea?.replaceChildren();
    currentFileIndex = -1;
    previewError?.classList.add('d-none');
    if (download) {
      download.removeAttribute('href');
      download.removeAttribute('download');
    }
  }

  function showChildFiles() {
    if (!child) return;
    childFiles?.classList.remove('d-none');
    childPreview?.classList.add('d-none');
    previewNavigation?.classList.add('d-none');
    backFiles?.classList.add('d-none');
    if (childTitle) childTitle.textContent = 'Uploaded Documents';
    if (previewCategory) previewCategory.textContent = '';
    clearPreview();
  }

  function openChild(mode, trigger = null) {
    if (!child) return;
    childTrigger = trigger || document.activeElement;
    child.classList.remove('d-none');
    modal.classList.add('step4-child-open');
    if (mode === 'files') showChildFiles();
    child.querySelector('[data-step4-child-close]')?.focus();
  }

  function setChildFullscreen(active) {
    if (!child) return;
    child.classList.toggle('step4-child-fullscreen', active);
    if (fullscreenLabel) fullscreenLabel.textContent = active ? 'Exit Full Screen' : 'Full Screen';
    if (fullscreenButton) {
      fullscreenButton.classList.toggle('btn-outline-primary', !active);
      fullscreenButton.classList.toggle('btn-primary', active);
      fullscreenButton.setAttribute('aria-pressed', active ? 'true' : 'false');
      const icon = fullscreenButton.querySelector('i');
      if (icon) icon.className = active
        ? 'bi bi-fullscreen-exit me-1'
        : 'bi bi-arrows-fullscreen me-1';
    }
  }

  function closeChild() {
    if (!child) return;
    setChildFullscreen(false);
    child.classList.add('d-none');
    modal.classList.remove('step4-child-open');
    showChildFiles();
    if (childTrigger && typeof childTrigger.focus === 'function') childTrigger.focus();
    childTrigger = null;
  }

  function renderFile(index) {
    if (!Number.isInteger(index) || index < 0 || index >= files.length || !previewArea) return;
    const link = files[index];
    const mime = link.dataset.documentMime;
    const name = link.dataset.documentName || 'Uploaded Document';
    const category = link.dataset.documentCategory || '';
    clearPreview();
    currentFileIndex = index;

    childFiles?.classList.add('d-none');
    childPreview?.classList.remove('d-none');
    previewNavigation?.classList.remove('d-none');
    backFiles?.classList.toggle('d-none', childOrigin !== 'files');
    if (childTitle) childTitle.textContent = 'Document Preview';
    if (previewName) previewName.textContent = name;
    if (previewCategory) previewCategory.textContent = category;

    const previewUrl = new URL(link.href, location.href);
    if (previewUrl.origin !== location.origin) {
      previewError?.classList.remove('d-none');
      return;
    }
    const downloadUrl = new URL(previewUrl.href);
    downloadUrl.searchParams.delete('preview');
    if (download) {
      download.href = downloadUrl.href;
      download.download = name;
    }

    const media = document.createElement(mime === 'application/pdf' ? 'iframe' : 'img');
    media.className = mime === 'application/pdf' ? 'step4-modal-pdf' : 'step4-modal-image';
    if (mime === 'application/pdf') media.title = name;
    else media.alt = name;
    media.addEventListener('error', () => previewError?.classList.remove('d-none'));
    media.src = previewUrl.href;
    previewArea.replaceChildren(media);

    if (fileCount) fileCount.textContent = (index + 1) + '/' + files.length;
    if (filePrevious) filePrevious.disabled = index === 0;
    if (fileNext) fileNext.disabled = index === files.length - 1;
  }

  function openFile(link, origin = 'direct') {
    const key = link.dataset.documentId || link.href;
    const index = fileIndices.get(key);
    if (!Number.isInteger(index)) return;
    childOrigin = origin;
    openChild('preview', link);
    renderFile(index);
  }

  const remarksFor = type => form.elements.namedItem('requirement_remarks[' + type + ']');
  const stepSelects = step => [...step.querySelectorAll('[data-step4-assessment]')];

  function validationForStep(step) {
    for (const select of stepSelects(step)) {
      if (!select.value) {
        select.focus();
        return 'Select an assessment for every documentary requirement in this section.';
      }
      if (['incomplete', 'insufficient'].includes(select.value)) {
        const type = select.name.match(/^assessment\[(.+)\]$/)?.[1] || '';
        const note = remarksFor(type);
        if (!note || note.value.trim().length < 5) {
          note?.focus();
          return 'Add a deficiency remark of at least five characters for each Lacking or Inappropriate requirement.';
        }
      }
    }
    return '';
  }

  function showStepError(step, text) {
    if (!step) return;
    let inline = step.querySelector('[data-step4-inline-validation]');
    if (!inline) {
      inline = document.createElement('div');
      inline.className = 'alert alert-warning mt-3 small';
      inline.dataset.step4InlineValidation = '';
      step.append(inline);
    }
    inline.textContent = text;
  }

  function updateSummary() {
    for (const select of assessments) {
      const type = select.name.match(/^assessment\[(.+)\]$/)?.[1] || '';
      const badge = form.querySelector('[data-step4-summary-assessment="' + type + '"]');
      const note = form.querySelector('[data-step4-summary-remarks="' + type + '"]');
      const [label, tone] = assessmentMeta[select.value] || ['Not assessed', 'secondary'];
      if (badge) {
        badge.textContent = label;
        badge.className = 'badge text-bg-' + tone;
      }
      if (note) note.textContent = remarksFor(type)?.value.trim() || '—';
    }
    const overallSummary = form.querySelector('[data-step4-summary-overall]');
    const instructionSummary = form.querySelector('[data-step4-summary-instructions]');
    const scheduleSummary = form.querySelector('[data-step4-summary-schedule]');
    if (overallSummary) overallSummary.textContent = overall?.value.trim() || '—';
    if (instructionSummary) instructionSummary.textContent = instructions?.value.trim() || '—';
    if (scheduleSummary) {
      const raw = schedule?.value || '';
      const parsed = raw ? new Date(raw) : null;
      scheduleSummary.textContent = !raw ? 'Not set'
        : (parsed && !Number.isNaN(parsed.getTime()) ? parsed.toLocaleString() : raw);
    }
  }

  function cancelConfirm() {
    hidden.value = '';
    confirmation?.classList.add('d-none');
    if (decisionControls && currentStep === 4) decisionControls.classList.remove('d-none');
  }

  function renderStep(number) {
    currentStep = Math.min(4, Math.max(1, number));
    for (const step of steps) {
      step.classList.toggle('d-none', Number(step.dataset.step4WizardStep) !== currentStep);
    }
    const active = steps.find(step => Number(step.dataset.step4WizardStep) === currentStep);
    const label = 'Step ' + currentStep + ' of 4';
    if (stepLabel) stepLabel.textContent = label;
    if (footerLabel) footerLabel.textContent = label;
    if (stepTitle) stepTitle.textContent = active?.dataset.step4StepName || '';

    for (const indicator of indicators) {
      const n = Number(indicator.dataset.step4StepIndicator);
      indicator.classList.toggle('fw-semibold', n === currentStep);
      indicator.classList.toggle('text-success', n <= currentStep);
      indicator.classList.toggle('text-secondary', n > currentStep);
    }

    previousButton?.classList.toggle('d-none', currentStep === 1);
    nextButton?.classList.toggle('d-none', currentStep === 4);
    decisionControls?.classList.toggle('d-none', currentStep !== 4);
    if (nextButton) nextButton.textContent = currentStep === 3 ? 'Review Decision →' : 'Next →';
    if (currentStep === 4) updateSummary();
    showValidation('');
    setProgressCompact(false);
    if (modalBody) {
      modalBody.scrollTop = 0;
      lastProgressScrollTop = 0;
    }
  }

  const assessed = () => assessments.length > 0
    && assessments.every(select => select.value !== '');
  const hasDeficiency = () => assessments
    .some(select => ['incomplete', 'insufficient'].includes(select.value));
  const allSufficient = () => assessments
    .every(select => ['complete', 'not_applicable'].includes(select.value));
  const stepFor = select =>
    Number(select.closest('[data-step4-wizard-step]')?.dataset.step4WizardStep || 1);

  nextButton?.addEventListener('click', () => {
    const active = steps.find(step => Number(step.dataset.step4WizardStep) === currentStep);
    if (!active || currentStep === 4) return;
    if (currentStep <= 2) {
      const error = validationForStep(active);
      if (error) return showStepError(active, error);
    }
    active.querySelector('[data-step4-inline-validation]')?.remove();
    renderStep(currentStep + 1);
  });

  previousButton?.addEventListener('click', () => {
    cancelConfirm();
    renderStep(currentStep - 1);
  });

  modalBody?.addEventListener('scroll', () => {
    if (ignoreProgressScroll || !stickyProgress || !child?.classList.contains('d-none')) return;

    const current = modalBody.scrollTop;
    const delta = current - lastProgressScrollTop;

    // At the top, always show the complete tracker. Scrolling down past the
    // introductory area leaves only the progress bar; any meaningful upward
    // scroll expands the tracker again.
    if (current <= 24) {
      setProgressCompact(false);
    } else if (delta > 6 && current > 80) {
      setProgressCompact(true);
    } else if (delta < -6) {
      setProgressCompact(false);
    }

    lastProgressScrollTop = current;
    ignoreProgressScroll = true;
    requestAnimationFrame(() => {
      lastProgressScrollTop = modalBody.scrollTop;
      ignoreProgressScroll = false;
    });
  }, { passive: true });

  for (const select of assessments) {
    select.addEventListener('change', () => {
      updateProgress();
      select.closest('[data-step4-wizard-step]')
        ?.querySelector('[data-step4-inline-validation]')?.remove();
      if (currentStep === 4) updateSummary();
    });
  }

  for (const field of [overall, instructions, schedule]) {
    field?.addEventListener('input', () => {
      showValidation('');
      if (currentStep === 4) updateSummary();
    });
  }

  for (const button of modal.querySelectorAll('[data-step4-decision]')) {
    button.addEventListener('click', () => {
      const decision = button.dataset.step4Decision;
      showValidation('');
      cancelConfirm();

      if (!assessed()) {
        const missing = assessments.find(select => !select.value);
        if (missing) {
          renderStep(stepFor(missing));
          showStepError(missing.closest('[data-step4-wizard-step]'),
            'Assess every documentary requirement before finalizing.');
          missing.focus();
        }
        return;
      }

      for (const select of assessments) {
        if (['incomplete','insufficient'].includes(select.value)) {
          const type = select.name.match(/^assessment\[(.+)\]$/)?.[1] || '';
          const note = remarksFor(type);
          if (!note || note.value.trim().length < 5) {
            renderStep(stepFor(select));
            showStepError(select.closest('[data-step4-wizard-step]'),
              'A Lacking or Inappropriate requirement requires at least five characters of remarks.');
            note?.focus();
            return;
          }
        }
      }

      if (decision === 'return') {
        if (!hasDeficiency()) {
          showValidation('Mark at least one requirement Lacking or Inappropriate before returning the application.');
          return;
        }
        if ((overall?.value || '').trim().length < 10) {
          renderStep(3);
          showStepError(steps[2], 'Provide an overall deficiency explanation of at least ten characters.');
          overall?.focus();
          return;
        }
        hidden.value = 'return';
        if (confirmTitle) confirmTitle.textContent = 'Return Application for Compliance?';
        if (confirmMessage) confirmMessage.textContent = modern
          ? 'The system will generate an unsigned deficiency PDF for RED review. The applicant will NOT be notified until RED signs and approves it.'
          : 'The applicant will be notified about deficiencies and may resubmit directly for Step 4 re-evaluation.';
        if (confirmButton) {
          confirmButton.className = 'btn btn-danger';
          confirmButton.textContent = modern ? 'Generate Draft for RED Approval' : 'Confirm Return';
        }
      } else if (decision === 'complete') {
        if (wizard.dataset.step4ProjectType === 'other'
            && form.querySelector('[name="assessment[doe_esc_coa]"]')?.value !== 'complete') {
          showValidation('Other project types require DOE ESC/COA (#10) assessed Complete.');
          return;
        }
        if (!allSufficient()) {
          showValidation('Every requirement must be Complete or Not Applicable.');
          return;
        }
        if (!modern && !schedule?.value) {
          renderStep(3);
          showStepError(steps[2], 'Set the field validation / assessment schedule before completing legacy Step 4.');
          schedule?.focus();
          return;
        }
        hidden.value = 'complete';
        if (confirmTitle) confirmTitle.textContent = 'Confirm Complete & Sufficient?';
        if (confirmMessage) confirmMessage.textContent = modern
          ? 'The application passes LPDD-FUS Step 2 and is forwarded to RO Receiving Step 3. Payment and scheduling will happen after RO.'
          : 'The applicant will be instructed to submit hard copies and pay the fee; CENRO TWG will be notified for Step 5 preparation.';
        if (confirmButton) {
          confirmButton.className = 'btn btn-success';
          confirmButton.textContent = 'Confirm Complete & Sufficient';
        }
      } else {
        return;
      }

      confirmation?.classList.remove('d-none');
      decisionControls?.classList.add('d-none');
      if (modalBody) modalBody.scrollTop = modalBody.scrollHeight;
      confirmButton?.focus();
    });
  }

  cancelConfirmation?.addEventListener('click', cancelConfirm);

  confirmButton?.addEventListener('click', () => {
    if (!hidden.value || currentStep !== 4 || !child?.classList.contains('d-none')) return;
    confirmButton.disabled = true;
    form.requestSubmit();
  });

  form.addEventListener('submit', event => {
    if (!hidden.value || currentStep !== 4 || !child?.classList.contains('d-none')) {
      event.preventDefault();
      cancelConfirm();
      renderStep(4);
      showValidation('Confirm a Step 4 decision before submitting.');
    }
  });

  modal.querySelector('[data-step4-view-all]')?.addEventListener('click', event => {
    childOrigin = 'files';
    openChild('files', event.currentTarget);
  });

  for (const link of fileLinks) {
    link.addEventListener('click', event => {
      event.preventDefault();
      const origin = link.closest('[data-step4-child-files]') ? 'files' : 'direct';
      openFile(link, origin);
    });
  }

  backFiles?.addEventListener('click', () => {
    childOrigin = 'files';
    showChildFiles();
  });
  fullscreenButton?.addEventListener('click', () => {
    setChildFullscreen(!child.classList.contains('step4-child-fullscreen'));
  });
  filePrevious?.addEventListener('click', () => renderFile(currentFileIndex - 1));
  fileNext?.addEventListener('click', () => renderFile(currentFileIndex + 1));
  for (const closer of childClosers) closer.addEventListener('click', closeChild);

  modal.addEventListener('keydown', event => {
    if (event.key === 'Escape' && child && !child.classList.contains('d-none')) {
      event.preventDefault();
      event.stopPropagation();
      if (child.classList.contains('step4-child-fullscreen')) {
        setChildFullscreen(false);
      } else {
        closeChild();
      }
    }
  });

  modal.addEventListener('hidden.bs.modal', () => {
    closeChild();
    cancelConfirm();
    // Keep entered form values on the current page; no decision is persisted
    // until the reviewer confirms the final Step 4 action.
  });

  updateProgress();
  renderStep(currentStep);

  if (Number(wizard.dataset.step4InitialStep) > 1) {
    window.bootstrap.Modal.getOrCreateInstance(modal).show();
  }
})();