(() => {
  'use strict';
  const modalElement = document.getElementById('otp-verification-success-modal');
  if (!modalElement || !window.bootstrap?.Modal) return;

  const dialog = window.bootstrap.Modal.getOrCreateInstance(modalElement, {
    backdrop: 'static',
    keyboard: false
  });
  modalElement.addEventListener('hidden.bs.modal', () => {
    const emailField = document.getElementById('email');
    emailField?.focus();
  }, { once: true });
  dialog.show();
})();
