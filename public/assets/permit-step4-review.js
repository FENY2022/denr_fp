(() => {
  'use strict';

  const form = document.getElementById('step4-decision-form');
  const modal = document.getElementById('step4-decision-modal');
  if (!form || !modal || !window.bootstrap?.Modal) return;

  const hidden = form.querySelector('[data-step4-decision-value]');
  const message = modal.querySelector('[data-step4-modal-message]');
  const title = modal.querySelector('[data-step4-modal-title]');
  const confirm = modal.querySelector('[data-step4-confirm]');
  const validation = form.querySelector('[data-step4-validation]');
  const schedule = form.elements.namedItem('field_validation_schedule');
  const instance = window.bootstrap.Modal.getOrCreateInstance(modal);

  const showValidation = text => {
    if (!validation) return;
    validation.textContent = text;
    validation.classList.toggle('d-none', !text);
  };

  const assessed = () => {
    const selects = [...form.querySelectorAll('select[name^="assessment["]')];
    return selects.length > 0 && selects.every(select => select.value !== '');
  };

  const hasDeficiency = () => [...form.querySelectorAll('select[name^="assessment["]')]
    .some(select => ['incomplete','insufficient'].includes(select.value));

  const allSufficient = () => [...form.querySelectorAll('select[name^="assessment["]')]
    .every(select => ['complete','not_applicable'].includes(select.value));

  for (const button of form.querySelectorAll('[data-step4-decision]')) {
    button.addEventListener('click', () => {
      const decision = button.dataset.step4Decision;
      showValidation('');

      if (!assessed()) {
        showValidation('Assess every documentary requirement before recording a Step 4 decision.');
        form.querySelector('select[name^="assessment["]:invalid')?.focus();
        return;
      }

      if (decision === 'return') {
        if (!hasDeficiency()) {
          showValidation('Mark at least one requirement Incomplete / Missing or Insufficient before returning the application.');
          return;
        }
        const overall = String(form.elements.namedItem('overall_remarks')?.value || '').trim();
        if (overall.length < 10) {
          showValidation('Provide an overall deficiency explanation of at least 10 characters.');
          form.elements.namedItem('overall_remarks')?.focus();
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
      showValidation('Choose a Step 4 decision first.');
    }
  });
})();
