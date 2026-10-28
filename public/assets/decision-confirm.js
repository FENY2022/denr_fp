(() => {
  'use strict';

  // Confirm only consequential LPDD-FUS review decisions; do not touch
  // ordinary navigation, form fields, logout, or unrelated submit buttons.
  const decisions = Object.freeze({
    registration: Object.freeze({
      approved: {
        title: 'Confirm Account Approval',
        message: 'Are you sure you want to approve this applicant registration and activate the account?',
        status: 'Active',
        button: 'Confirm Approval',
        variant: 'success',
        minRemarks: 0
      },
      needs_correction: {
        title: 'Confirm Return for Correction',
        message: 'Are you sure you want to return this applicant registration for correction? The applicant will receive your remarks.',
        status: 'Needs Correction',
        button: 'Confirm Return for Correction',
        variant: 'danger',
        minRemarks: 1
      }
    }),
    step2: Object.freeze({
      forward: {
        title: 'Confirm Forwarding to RO Receiving',
        message: 'Has the LPDD-FUS Technical Staff completed the initial document check? Confirm to forward this FP application to RO Receiving.',
        status: 'Forwarded to RO Receiving',
        button: 'Confirm Forwarding',
        variant: 'success',
        minRemarks: 0
      },
      return: {
        title: 'Confirm Incomplete Application',
        message: 'Are you sure you want to return this FP application to the applicant? The specific deficiencies below will be sent for correction.',
        status: 'Corrections Required',
        button: 'Confirm Return for Correction',
        variant: 'danger',
        minRemarks: 10
      }
    })
  });

  function init() {
    const form = document.querySelector('form[data-decision-confirm-form]');
    const dialog = document.getElementById('decision-confirm-modal');
    if (!form || !dialog) return;

    const decisionInput = form.querySelector('[data-confirm-decision-value]');
    const notes = form.querySelector('textarea[name="remarks"]');
    const error = form.querySelector('[data-decision-confirm-validation]');
    const confirmButton = dialog.querySelector('#decision-confirm-submit');
    const title = dialog.querySelector('#decision-confirm-title');
    const description = dialog.querySelector('#decision-confirm-description');
    const reference = dialog.querySelector('#decision-confirm-reference');
    const applicant = dialog.querySelector('#decision-confirm-applicant');
    const newStatus = dialog.querySelector('#decision-confirm-status');
    const remarksGroup = dialog.querySelector('#decision-confirm-remarks-group');
    const remarksText = dialog.querySelector('#decision-confirm-remarks');
    const actionButtons = [...form.querySelectorAll('button[data-confirm-decision]')];

    if (!decisionInput || !notes || !error || !confirmButton || !title ||
        !description || !reference || !applicant || !newStatus ||
        !remarksGroup || !remarksText || !actionButtons.length) return;

    const kind = form.dataset.confirmKind;
    if (!Object.hasOwn(decisions, kind)) return;

    let selectedAction = '';
    let confirmed = false;
    let sending = false;

    const report = message => {
      error.textContent = message;
      error.classList.toggle('d-none', message === '');
    };

    const clearChoice = () => {
      if (sending) return;
      selectedAction = '';
      confirmed = false;
      decisionInput.value = '';
    };

    // The browser's normal submit event never posts directly before a
    // confirmed choice, including submission with Enter. No decision is
    // supplied until the modal Confirm button is explicitly pressed.
    form.addEventListener('submit', event => {
      if (!confirmed || !selectedAction || !decisionInput.value ||
          decisionInput.value !== selectedAction || sending) {
        event.preventDefault();
        if (!sending) report('Please choose an action and confirm it in the dialog.');
        return;
      }
      sending = true;
      confirmButton.disabled = true;
      confirmButton.textContent = 'Submitting...';
      actionButtons.forEach(button => { button.disabled = true; });
    });

    notes.addEventListener('input', () => report(''));

    actionButtons.forEach(button => {
      button.addEventListener('click', () => {
        if (sending) return;
        const action = button.dataset.confirmDecision;
        const options = decisions[kind][action];
        if (!options) return;

        const typed = notes.value.trim();
        if (typed.length < options.minRemarks) {
          const message = options.minRemarks === 1
            ? 'Enter remarks explaining the required registration corrections before continuing.'
            : 'List the lacking FP documentary requirements (at least 10 characters) before continuing.';
          report(message);
          notes.focus();
          return;
        }

        if (!window.bootstrap?.Modal) {
          report('The confirmation dialog could not be loaded. Refresh the page and try again.');
          return;
        }

        report('');
        selectedAction = action;
        confirmed = false;
        decisionInput.value = '';
        title.textContent = options.title;
        description.textContent = options.message;
        reference.textContent = form.dataset.confirmReference || '';
        applicant.textContent = form.dataset.confirmApplicant || '';
        newStatus.textContent = options.status;
        remarksGroup.classList.toggle('d-none', typed === '');
        remarksText.textContent = typed;
        confirmButton.textContent = options.button;
        confirmButton.disabled = false;
        confirmButton.className = 'btn btn-' + options.variant;

        window.bootstrap.Modal.getOrCreateInstance(dialog).show();
      });
    });

    dialog.addEventListener('hidden.bs.modal', clearChoice);

    confirmButton.addEventListener('click', () => {
      if (sending || !selectedAction) return;
      const config = decisions[kind][selectedAction];
      if (!config) return;

      // Re-check remarks in case they were edited while the modal was open.
      if (notes.value.trim().length < config.minRemarks) {
        window.bootstrap?.Modal.getOrCreateInstance(dialog)?.hide();
        report('The review remarks are required before this action can continue.');
        notes.focus();
        return;
      }

      confirmed = true;
      decisionInput.value = selectedAction;
      // requestSubmit performs standard HTML validation and fires the form's
      // submit event. The existing PHP route still checks CSRF, authorization,
      // valid decisions and DB state before committing any change.
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        confirmed = false;
        decisionInput.value = '';
        report('Your browser does not support confirmed form submission. Update or switch browsers.');
        window.bootstrap?.Modal.getOrCreateInstance(dialog)?.hide();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, {once: true});
  } else {
    init();
  }
})();
