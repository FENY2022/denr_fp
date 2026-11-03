(() => {
  'use strict';
  const modal = document.getElementById('forceLogoutModal');
  const form = modal?.querySelector('#forceLogoutForm');
  const userId = form?.elements.namedItem('user_id');
  const reason = form?.elements.namedItem('reason');
  const account = modal?.querySelector('[data-force-logout-account]');
  if (!modal || !form || !userId || !reason || !account) return;

  modal.addEventListener('show.bs.modal', event => {
    const button = event.relatedTarget?.closest?.('[data-force-logout-trigger]');
    if (!button) {
      event.preventDefault();
      return;
    }
    const id = Number(button.dataset.userId);
    if (!Number.isSafeInteger(id) || id < 1 || button.disabled) {
      event.preventDefault();
      return;
    }
    form.reset();
    userId.value = String(id);
    account.textContent = button.dataset.userName || button.dataset.userUsername || ('User #' + id);
  });

  modal.addEventListener('shown.bs.modal', () => reason.focus());
  modal.addEventListener('hidden.bs.modal', () => {
    form.reset();
    userId.value = '';
    account.textContent = '';
  });

  form.addEventListener('submit', event => {
    const value = reason.value.trim();
    if (value.length < 3 || value.length > 500) {
      event.preventDefault();
      reason.setCustomValidity('Enter a security reason between 3 and 500 characters.');
      reason.reportValidity();
      return;
    }
    reason.setCustomValidity('');
    const button = form.querySelector('[type="submit"]');
    if (button) button.disabled = true;
  });
  reason.addEventListener('input', () => reason.setCustomValidity(''));
})();
