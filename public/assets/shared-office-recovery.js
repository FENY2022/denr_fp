'use strict';
(() => {
  const form = document.getElementById('shared-office-account-form');
  if (!form) return;
  const opener = form.parentElement.querySelector('[data-office-open-confirm]')
      || form.querySelector('[data-office-open-confirm]');
  const confirm = document.querySelector('[data-office-confirm-submit]');
  const name = document.getElementById('shared-office-confirm-name');
  const modalElement = document.getElementById('shared-office-confirm-modal');
  if (!opener || !confirm || !name || !modalElement) return;
  opener.addEventListener('click', () => {
    const choice = form.querySelector('input[name="staff_id"]:checked');
    if (!choice) {
      const first = form.querySelector('input[name="staff_id"]');
      if (first) {
        first.setCustomValidity('Select your own DENR account before continuing.');
        first.reportValidity();
        first.setCustomValidity('');
      }
      return;
    }
    name.textContent = choice.dataset.accountLabel || 'selected account';
    if (window.bootstrap && bootstrap.Modal) {
      bootstrap.Modal.getOrCreateInstance(modalElement).show();
    } else {
      // Fail closed if Bootstrap modal is unavailable.
      name.textContent = 'Modal unavailable. Refresh this page.';
    }
  });
  confirm.addEventListener('click', () => {
    const choice = form.querySelector('input[name="staff_id"]:checked');
    if (!choice || confirm.disabled) return;
    confirm.disabled = true;
    confirm.textContent = 'Sending recovery request...';
    form.requestSubmit();
  });
})();