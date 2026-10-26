(() => {
  'use strict';

  const modal = document.getElementById('userSetupModal');
  const form = modal?.querySelector('#userSetupForm');
  if (!modal || !form) return;

  // Prevent parent app layout (sidebar/transforms) from clipping the backdrop and dialog.
  document.body.appendChild(modal);

  const field = name => form.elements.namedItem(name);
  const password = field('password');
  const confirmation = field('confirm_password');
  const identity = field('confirm_identity');
  const activate = modal.querySelector('#setupActivateButton');
  const passwordFeedback = modal.querySelector('[data-setup-password-feedback]');
  const confirmFeedback = modal.querySelector('[data-setup-confirm-feedback]');
  const officeReference = modal.querySelector('[data-office-reference]');
  const notes = modal.querySelector('[data-personnel-notes]');
  const fullPageLink = modal.querySelector('#setupFullPageLink');

  if (!password || !confirmation || !identity || !activate || !passwordFeedback ||
      !confirmFeedback || !officeReference || !notes || !fullPageLink) return;

  const strength = value => {
    const size = new TextEncoder().encode(value).length;
    return size >= 12 && size <= 200 && /[A-Za-z]/.test(value) && /[0-9]/.test(value);
  };

  function validate() {
    const hasPassword = password.value.length > 0;
    const validPassword = strength(password.value);
    const hasConfirmation = confirmation.value.length > 0;
    const matches = hasConfirmation && confirmation.value === password.value;

    // Native form validation remains available; the server independently validates.
    password.setCustomValidity(hasPassword && !validPassword
      ? 'Use 12–200 characters including at least one letter and one number.'
      : '');
    confirmation.setCustomValidity(hasConfirmation && !matches
      ? 'The passwords do not match.' : '');

    passwordFeedback.textContent = !hasPassword
      ? '12–200 characters, with at least one letter and one number.'
      : validPassword ? 'Password meets the requirements.'
        : 'Password must contain letters and numbers and be 12–200 characters.';
    passwordFeedback.className = 'form-text ' +
      (hasPassword ? (validPassword ? 'text-success' : 'text-danger') : 'text-secondary');

    confirmFeedback.textContent = !hasConfirmation ? 'Enter the same temporary password.'
      : matches ? 'Passwords match.' : 'Passwords do not match.';
    confirmFeedback.className = 'form-text ' +
      (hasConfirmation ? (matches ? 'text-success' : 'text-danger') : 'text-secondary');

    const complete = form.checkValidity() && validPassword && matches && identity.checked;
    activate.disabled = !complete;
    return complete;
  }

  function setField(name, value) {
    const target = field(name);
    if (target) target.value = value ?? '';
  }

  function resetPasswordVisibility() {
    for (const button of modal.querySelectorAll('[data-toggle-setup-password]')) {
      const input = modal.querySelector('#' + button.dataset.toggleSetupPassword);
      if (input) input.type = 'password';
      button.setAttribute('aria-pressed', 'false');
      button.setAttribute('aria-label', input === password
        ? 'Show temporary password' : 'Show confirmation password');
      button.title = 'Show password';
      const icon = button.querySelector('i');
      if (icon) icon.className = 'bi bi-eye';
    }
  }

  // Bootstrap supplies the clicked Set Up button as relatedTarget.
  modal.addEventListener('show.bs.modal', event => {
    const button = event.relatedTarget?.closest?.('[data-user-setup-trigger]');
    if (!button) {
      // Server-side validation error: keep the submitted fields, but never keep passwords.
      validate();
      return;
    }

    const userId = Number(button.dataset.userId);
    const action = button.dataset.setupUrl;
    if (!Number.isSafeInteger(userId) || userId <= 0 || !action) {
      event.preventDefault();
      return;
    }

    form.reset();
    resetPasswordVisibility();
    form.action = action;
    fullPageLink.href = action;
    setField('name', button.dataset.userName);
    setField('email', ''); // Shared office contact emails must never be copied into login email.
    setField('contact_number', button.dataset.userContact);
    setField('office_station', button.dataset.userOffice);
    setField('role', button.dataset.userRole);

    officeReference.textContent = button.dataset.userReference || 'Not provided';
    notes.textContent = button.dataset.userNotes || 'No additional notes.';
    notes.hidden = !button.dataset.userNotes;
    identity.checked = false;
    password.value = '';
    confirmation.value = '';
    validate();
  });

  modal.addEventListener('shown.bs.modal', () => {
    field('email')?.focus();
  });

  modal.addEventListener('hidden.bs.modal', () => {
    form.reset();
    password.setCustomValidity('');
    confirmation.setCustomValidity('');
    resetPasswordVisibility();
    validate();
  });

  for (const input of form.querySelectorAll('input, select')) {
    input.addEventListener('input', validate);
    input.addEventListener('change', validate);
  }

  for (const toggle of modal.querySelectorAll('[data-toggle-setup-password]')) {
    toggle.addEventListener('click', () => {
      const target = modal.querySelector('#' + toggle.dataset.toggleSetupPassword);
      if (!target) return;
      const showing = target.type === 'password';
      target.type = showing ? 'text' : 'password';
      toggle.setAttribute('aria-pressed', String(showing));
      toggle.setAttribute('aria-label', (showing ? 'Hide ' : 'Show ') +
        (target === password ? 'temporary password' : 'confirmation password'));
      toggle.title = showing ? 'Hide password' : 'Show password';
      const icon = toggle.querySelector('i');
      if (icon) icon.className = showing ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  }

  form.addEventListener('submit', event => {
    if (!validate()) {
      event.preventDefault();
      const firstInvalid = form.querySelector(':invalid');
      (firstInvalid || field('email'))?.focus();
      return;
    }
    // Avoid double clicks while HTTP POST is in flight. Server revalidates all fields.
    activate.disabled = true;
  });

  if (modal.dataset.reopen === '1' && window.bootstrap?.Modal) {
    // Reopen the same popup after server-side activation errors.
    window.bootstrap.Modal.getOrCreateInstance(modal).show();
  }
})();
