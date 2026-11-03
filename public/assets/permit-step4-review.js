(() => {
  'use strict';

  const wizard = document.querySelector('[data-step4-wizard]');
  const form = document.getElementById('step4-decision-form');
  const modal = document.getElementById('step4-decision-modal');
  if (!wizard || !form || !modal || !window.bootstrap?.Modal) return;

  const hidden = form.querySelector('[data-step4-decision-value]');
  const message = modal.querySelector('[data-step4-modal-message]');
  const title = modal.querySelector('[data-step4-modal-title]');
  const confirm = modal.querySelector('[data-step4-confirm]');
  const validation = form.querySelector('[data-step4-validation]');
  const schedule = form.elements.namedItem('field_validation_schedule');
  const overall = form.elements.namedItem('overall_remarks');
  const instructions = form.elements.namedItem('applicant_instructions');
  const assessments = [...form.querySelectorAll('[data-step4-assessment]')];
  const steps = [...form.querySelectorAll('[data-step4-wizard-step]')]
    .sort((a, b) => Number(a.dataset.step4WizardStep) - Number(b.dataset.step4WizardStep));
  const progressCount = wizard.querySelector('[data-step4-progress-count]');
  const progressPercent = wizard.querySelector('[data-step4-progress-percent]');
  const progressMeter = wizard.querySelector('[data-step4-progress-meter]');
  const stepLabel = wizard.querySelector('[data-step4-step-label]');
  const stepTitle = wizard.querySelector('[data-step4-step-title]');
  const indicators = [...wizard.querySelectorAll('[data-step4-step-indicator]')];
  const completeButton = form.querySelector('[data-step4-decision="complete"]');
  const instance = window.bootstrap.Modal.getOrCreateInstance(modal);
  let currentStep = Math.min(4, Math.max(1, Number(wizard.dataset.step4InitialStep || 1)));

  const assessmentMeta = {
    complete: ['Complete', 'success'],
    incomplete: ['Incomplete / Missing', 'danger'],
    insufficient: ['Insufficient', 'warning'],
    not_applicable: ['Not Applicable', 'secondary']
  };

  const showValidation = text => {
    if (!validation) return;
    validation.textContent = text;
    validation.classList.toggle('d-none', !text);
  };

  const updateProgress = () => {
    const completed = assessments.filter(select => select.value !== '').length;
    const total = assessments.length;
    const percent = total > 0 ? Math.round((completed / total) * 100) : 0;
    if (progressCount) progressCount.textContent = String(completed);
    if (progressPercent) progressPercent.textContent = percent + '%';
    if (progressMeter) {
      progressMeter.value = completed;
      progressMeter.max = Math.max(1, total);
      progressMeter.textContent = completed + ' of ' + total;
    }
    if (completeButton) completeButton.disabled = completed < total;
  };

  const assessmentFor = type =>
    form.querySelector('[name="assessment[' + CSS.escape(type) + ']"]');

  const remarksFor = type =>
    form.querySelector('[name="requirement_remarks[' + CSS.escape(type) + ']"]');

  const stepRequirementSelects = step =>
    [...step.querySelectorAll('[data-step4-assessment]')];

  const validateRequirementStep = step => {
    const selects = stepRequirementSelects(step);
    for (const select of selects) {
      if (!select.value) {
        select.focus();
        return 'Select an assessment for every documentary requirement in this section.';
      }
      if (['incomplete', 'insufficient'].includes(select.value)) {
        const type = select.name.match(/^assessment\[(.+)\]$/)?.[1] || '';
        const remarks = type ? remarksFor(type) : null;
        if (!remarks || remarks.value.trim().length < 5) {
          remarks?.focus();
          return 'Add a short deficiency remark for every Incomplete / Missing or Insufficient requirement.';
        }
      }
    }
    return '';
  };

  const updateSummary = () => {
    for (const select of assessments) {
      const type = select.name.match(/^assessment\[(.+)\]$/)?.[1] || '';
      if (!type) continue;
      const badge = form.querySelector('[data-step4-summary-assessment="' + CSS.escape(type) + '"]');
      const note = form.querySelector('[data-step4-summary-remarks="' + CSS.escape(type) + '"]');
      const meta = assessmentMeta[select.value] || ['Not assessed', 'secondary'];
      if (badge) {
        badge.textContent = meta[0];
        badge.className = 'badge text-bg-' + meta[1];
      }
      if (note) {
        const remarks = remarksFor(type)?.value.trim() || '';
        note.textContent = remarks || '—';
      }
    }

    const overallSummary = form.querySelector('[data-step4-summary-overall]');
    const instructionSummary = form.querySelector('[data-step4-summary-instructions]');
    const scheduleSummary = form.querySelector('[data-step4-summary-schedule]');
    if (overallSummary) overallSummary.textContent = overall?.value.trim() || '—';
    if (instructionSummary) instructionSummary.textContent = instructions?.value.trim() || '—';
    if (scheduleSummary) {
      const raw = schedule?.value || '';
      if (!raw) {
        scheduleSummary.textContent = 'Not set';
      } else {
        const parsed = new Date(raw);
        scheduleSummary.textContent = Number.isNaN(parsed.getTime())
          ? raw : parsed.toLocaleString();
      }
    }
  };

  const renderStep = stepNumber => {
    currentStep = Math.min(4, Math.max(1, stepNumber));
    for (const step of steps) {
      step.classList.toggle('d-none', Number(step.dataset.step4WizardStep) !== currentStep);
    }

    const active = steps.find(step => Number(step.dataset.step4WizardStep) === currentStep);
    if (stepLabel) stepLabel.textContent = 'Step ' + currentStep + ' of 4';
    if (stepTitle) stepTitle.textContent = active?.dataset.step4StepName || '';

    for (const indicator of indicators) {
      const number = Number(indicator.dataset.step4StepIndicator);
      indicator.classList.toggle('fw-semibold', number === currentStep);
      indicator.classList.toggle('text-success', number <= currentStep);
      indicator.classList.toggle('text-secondary', number > currentStep);
    }

    if (currentStep === 4) updateSummary();
    showValidation('');
    active?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  const assessed = () => assessments.length > 0
    && assessments.every(select => select.value !== '');

  const hasDeficiency = () => assessments
    .some(select => ['incomplete', 'insufficient'].includes(select.value));

  const allSufficient = () => assessments
    .every(select => ['complete', 'not_applicable'].includes(select.value));

  const stepForAssessment = select => {
    const step = select.closest('[data-step4-wizard-step]');
    return step ? Number(step.dataset.step4WizardStep) : 1;
  };

  for (const select of assessments) {
    select.addEventListener('change', () => {
      showValidation('');
      updateProgress();
      if (currentStep === 4) updateSummary();
    });
  }

  for (const field of [overall, instructions, schedule]) {
    field?.addEventListener('input', () => {
      showValidation('');
      if (currentStep === 4) updateSummary();
    });
  }

  for (const button of form.querySelectorAll('[data-step4-next]')) {
    button.addEventListener('click', () => {
      const active = steps.find(step => Number(step.dataset.step4WizardStep) === currentStep);
      if (!active) return;
      if (currentStep <= 2) {
        const error = validateRequirementStep(active);
        if (error) {
          let inline = active.querySelector('[data-step4-inline-validation]');
          if (!inline) {
            inline = document.createElement('div');
            inline.className = 'alert alert-warning small mt-3';
            inline.dataset.step4InlineValidation = '';
            active.querySelector('.border.rounded-3')?.append(inline);
          }
          inline.textContent = error;
          return;
        }
      }
      active.querySelector('[data-step4-inline-validation]')?.remove();
      renderStep(currentStep + 1);
    });
  }

  for (const button of form.querySelectorAll('[data-step4-prev]')) {
    button.addEventListener('click', () => renderStep(currentStep - 1));
  }

  for (const button of form.querySelectorAll('[data-step4-decision]')) {
    button.addEventListener('click', () => {
      const decision = button.dataset.step4Decision;
      showValidation('');

      if (!assessed()) {
        showValidation('Assess every documentary requirement before recording a Step 4 decision.');
        const missing = assessments.find(select => select.value === '');
        if (missing) {
          renderStep(stepForAssessment(missing));
          missing.focus();
        }
        return;
      }

      for (const select of assessments) {
        if (['incomplete', 'insufficient'].includes(select.value)) {
          const type = select.name.match(/^assessment\[(.+)\]$/)?.[1] || '';
          const note = type ? remarksFor(type) : null;
          if (!note || note.value.trim().length < 5) {
            renderStep(stepForAssessment(select));
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
          showValidation('Provide an overall deficiency explanation of at least 10 characters.');
          renderStep(3);
          overall?.focus();
          return;
        }
        hidden.value = 'return';
        title.textContent = 'Return Application for Compliance?';
        message.textContent = 'The applicant will be notified to correct deficiencies, complete missing requirements, and resubmit directly for Step 4 re-evaluation.';
        confirm.className = 'btn btn-danger';
        confirm.textContent = 'Confirm Return';
      } else if (decision === 'complete') {
        if (!allSufficient()) {
          showValidation('Every requirement must be Complete or Not Applicable before marking the package complete and sufficient.');
          return;
        }
        if (!schedule?.value) {
          showValidation('Set the Field Validation / Assessment Schedule before completing Step 4.');
          renderStep(3);
          schedule?.focus();
          return;
        }
        hidden.value = 'complete';
        title.textContent = 'Confirm Complete & Sufficient?';
        message.textContent = 'The applicant will be instructed to submit hard copies and pay the application fee, and CENRO TWG will be notified for Step 5 preparation.';
        confirm.className = 'btn btn-success';
        confirm.textContent = 'Confirm Complete & Sufficient';
      } else {
        return;
      }
      instance.show();
    });
  }

  confirm.addEventListener('click', () => {
    if (!hidden.value) return;
    confirm.disabled = true;
    form.requestSubmit();
  });

  form.addEventListener('submit', event => {
    if (!hidden.value) {
      event.preventDefault();
      renderStep(4);
      showValidation('Choose a Step 4 decision first.');
    }
  });

  updateProgress();
  renderStep(currentStep);
})();
