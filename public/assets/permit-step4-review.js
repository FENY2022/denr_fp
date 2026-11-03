(() => {
  'use strict';

  // One full-screen Bootstrap modal owns the wizard, file list, PDF/image
  // preview, and the final confirmation. Never open a second modal here.
  const modal = document.getElementById('step4-evaluation-modal');
  const form = modal?.querySelector('#step4-decision-form');
  const wizard = modal?.querySelector('[data-step4-wizard]');
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

  const wizardView = modal.querySelector('[data-step4-main-view]');
  const filesView = modal.querySelector('[data-step4-files-view]');
  const previewView = modal.querySelector('[data-step4-preview-view]');
  const wizardFooter = modal.querySelector('[data-step4-wizard-footer]');
  const filesFooter = modal.querySelector('[data-step4-files-footer]');
  const previewFooter = modal.querySelector('[data-step4-preview-footer]');
  const modalBody = modal.querySelector('[data-step4-modal-body]');
  const previousButton = modal.querySelector('[data-step4-prev]');
  const nextButton = modal.querySelector('[data-step4-next]');
  const decisionControls = modal.querySelector('[data-step4-decision-controls]');

  const previewName = modal.querySelector('[data-step4-preview-name]');
  const previewCategory = modal.querySelector('[data-step4-preview-category]');
  const previewArea = modal.querySelector('[data-step4-preview-area]');
  const previewError = modal.querySelector('[data-step4-preview-error]');
  const download = modal.querySelector('[data-step4-download]');
  const filePrevious = modal.querySelector('[data-step4-file-prev]');
  const fileNext = modal.querySelector('[data-step4-file-next]');
  const fileCount = modal.querySelector('[data-step4-file-count]');

  const assessmentMeta = {
    complete: ['Complete', 'success'],
    incomplete: ['Incomplete / Missing', 'danger'],
    insufficient: ['Insufficient', 'warning'],
    not_applicable: ['Not Applicable', 'secondary']
  };

  let currentStep = Math.min(4, Math.max(1, Number(wizard.dataset.step4InitialStep || 1)));
  let mode = 'wizard';
  let returnFromPreview = 'wizard';
  let currentFileIndex = -1;
  const links = [...modal.querySelectorAll('[data-step4-file-link]')]
    .filter(link => ['application/pdf', 'image/png', 'image/jpeg']
      .includes(link.dataset.documentMime));
  const files = [];
  const fileIndices = new Map();

  for (const link of links) {
    const key = link.dataset.documentId || link.href;
    if (!fileIndices.has(key)) {
      fileIndices.set(key, files.length);
      files.push(link);
    }
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

  function showMode(nextMode) {
    mode = nextMode;
    const items = [
      [wizardView, 'wizard'], [filesView, 'files'], [previewView, 'preview'],
      [wizardFooter, 'wizard'], [filesFooter, 'files'], [previewFooter, 'preview']
    ];
    for (const [element, key] of items) {
      element?.classList.toggle('d-none', key !== mode);
    }
    if (modalBody) modalBody.scrollTop = 0;
  }

  function clearPreview() {
    previewArea?.replaceChildren();
    currentFileIndex = -1;
    if (previewError) previewError.classList.add('d-none');
  }

  function renderFile(index) {
    if (!Number.isInteger(index) || index < 0 || index >= files.length || !previewArea) return;
    const link = files[index];
    const mime = link.dataset.documentMime;
    const name = link.dataset.documentName || 'Uploaded Document';
    currentFileIndex = index;
    clearPreview();
    currentFileIndex = index;

    if (previewName) previewName.textContent = name;
    if (previewCategory) previewCategory.textContent = link.dataset.documentCategory || '';
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin) return;
    url.searchParams.delete('preview');
    if (download) {
      download.href = url.href;
      download.download = name;
    }
    const media = document.createElement(mime === 'application/pdf' ? 'iframe' : 'img');
    media.className = mime === 'application/pdf'
      ? 'step4-modal-pdf' : 'step4-modal-image';
    if (mime === 'application/pdf') media.title = name;
    else media.alt = name;
    media.addEventListener('error', () => previewError?.classList.remove('d-none'));
    media.src = link.href;
    previewArea.replaceChildren(media);
    if (fileCount) fileCount.textContent = (index + 1) + '/' + files.length;
    if (filePrevious) filePrevious.disabled = index === 0;
    if (fileNext) fileNext.disabled = index === files.length - 1;
  }

  function openPreview(link) {
    const key = link.dataset.documentId || link.href;
    const index = fileIndices.get(key);
    if (!Number.isInteger(index)) return;
    returnFromPreview = mode;
    showMode('preview');
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
          return 'Add a deficiency remark of at least five characters for each incomplete or insufficient requirement.';
        }
      }
    }
    return '';
  }

  function showStepError(step, text) {
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
    if (modalBody) modalBody.scrollTop = 0;
  }

  const assessed = () => assessments.length > 0
    && assessments.every(select => select.value !== '');
  const hasDeficiency = () => assessments
    .some(select => ['incomplete', 'insufficient'].includes(select.value));
  const allSufficient = () => assessments
    .every(select => ['complete', 'not_applicable'].includes(select.value));
  const stepFor = select => Number(select.closest('[data-step4-wizard-step]')?.dataset.step4WizardStep || 1);

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
              'A missing or insufficient requirement requires at least five characters of remarks.');
            note?.focus();
            return;
          }
        }
      }

      if (decision === 'return') {
        if (!hasDeficiency()) {
          showValidation('Mark at least one requirement Incomplete / Missing or Insufficient before returning the application.');
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
        if (confirmMessage) confirmMessage.textContent =
          'The applicant will be notified about deficiencies and may resubmit directly for Step 4 re-evaluation.';
        if (confirmButton) {
          confirmButton.className = 'btn btn-danger';
          confirmButton.textContent = 'Confirm Return';
        }
      } else if (decision === 'complete') {
        if (!allSufficient()) {
          showValidation('Every requirement must be Complete or Not Applicable.');
          return;
        }
        if (!schedule?.value) {
          renderStep(3);
          showStepError(steps[2], 'Set the field validation / assessment schedule before completing Step 4.');
          schedule?.focus();
          return;
        }
        hidden.value = 'complete';
        if (confirmTitle) confirmTitle.textContent = 'Confirm Complete & Sufficient?';
        if (confirmMessage) confirmMessage.textContent =
          'The applicant will be instructed to submit hard copies and pay the fee; CENRO TWG will be notified for Step 5 preparation.';
        if (confirmButton) {
          confirmButton.className = 'btn btn-success';
          confirmButton.textContent = 'Confirm Complete & Sufficient';
        }
      } else return;

      confirmation?.classList.remove('d-none');
      decisionControls?.classList.add('d-none');
      if (modalBody) modalBody.scrollTop = modalBody.scrollHeight;
      confirmButton?.focus();
    });
  }

  cancelConfirmation?.addEventListener('click', cancelConfirm);
  confirmButton?.addEventListener('click', () => {
    if (!hidden.value || currentStep !== 4 || mode !== 'wizard') return;
    confirmButton.disabled = true;
    form.requestSubmit();
  });

  form.addEventListener('submit', event => {
    if (!hidden.value || currentStep !== 4 || mode !== 'wizard') {
      event.preventDefault();
      cancelConfirm();
      showMode('wizard');
      renderStep(4);
      showValidation('Confirm a Step 4 decision before submitting.');
    }
  });

  modal.querySelector('[data-step4-view-all]')?.addEventListener('click', () => showMode('files'));
  modal.querySelector('[data-step4-back-review]')?.addEventListener('click', () => showMode('wizard'));
  modal.querySelector('[data-step4-back-preview]')?.addEventListener('click', () => {
    clearPreview();
    showMode(returnFromPreview);
  });
  for (const link of links) {
    link.addEventListener('click', event => {
      event.preventDefault();
      openPreview(link);
    });
  }
  filePrevious?.addEventListener('click', () => renderFile(currentFileIndex - 1));
  fileNext?.addEventListener('click', () => renderFile(currentFileIndex + 1));

  modal.addEventListener('hidden.bs.modal', () => {
    clearPreview();
    cancelConfirm();
    showMode('wizard');
    // Preserve entered values on this page; none are saved until final submission.
  });

  updateProgress();
  renderStep(currentStep);
  showMode('wizard');
  if (Number(wizard.dataset.step4InitialStep) > 1) {
    window.bootstrap.Modal.getOrCreateInstance(modal).show();
  }
})();