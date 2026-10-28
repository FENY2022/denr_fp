(() => {
  'use strict';

  const form = document.getElementById('change-password-form');
  if (!form) return;

  const current = form.querySelector('#current_password');
  const password = form.querySelector('#new_password');
  const confirmation = form.querySelector('#confirm_password');
  const feedback = form.querySelector('#change-password-feedback');
  const confirmFeedback = form.querySelector('#change-confirm-feedback');
  const submit = form.querySelector('button[type="submit"]');
  const rules = [...form.querySelectorAll('[data-change-password-rule]')];
  if (!current || !password || !confirmation || !feedback || !confirmFeedback || !submit) return;

  // Keep client checks consistent with PHP strlen() (UTF-8 byte count).
  const byteLength = value => new TextEncoder().encode(value).length;

  function validate() {
    const value = password.value;
    const count = byteLength(value);
    const checks = {
      minimum: count >= 12,
      letter: /[A-Za-z]/.test(value),
      number: /[0-9]/.test(value),
      maximum: value.length > 0 && count <= 200,
      different: current.value.length > 0 && value.length > 0 && value !== current.value
    };

    rules.forEach(rule => {
      const met = Boolean(checks[rule.dataset.changePasswordRule]);
      rule.classList.toggle('text-success', met);
      rule.classList.toggle('text-secondary', !met);
      const icon = rule.querySelector('i');
      if (icon) icon.className = met ? 'bi bi-check-circle-fill me-2' : 'bi bi-circle me-2';
      const status = rule.querySelector('.password-rule-status');
      if (status) status.textContent = met ? 'Met' : 'Not met';
    });

    const validPassword = Object.values(checks).every(Boolean);
    const hasConfirmation = confirmation.value.length > 0;
    const matches = hasConfirmation && confirmation.value === value;
    password.setCustomValidity(value && !validPassword
      ? 'Use 12 to 200 characters with a letter and a number, different from your current password.'
      : '');
    confirmation.setCustomValidity(hasConfirmation && !matches ? 'New passwords do not match.' : '');

    feedback.textContent = !value ? 'Enter a new password to check its requirements.'
      : validPassword ? 'New password meets all requirements.' : 'Please complete the password requirements.';
    feedback.className = 'small mt-2 ' + (validPassword ? 'text-success' : 'text-secondary');

    confirmFeedback.textContent = !hasConfirmation ? 'Re-enter your new password to confirm.'
      : matches ? 'Passwords match.' : 'Passwords do not match.';
    confirmFeedback.className = 'small mt-2 '
      + (!hasConfirmation ? 'text-secondary' : matches ? 'text-success' : 'text-danger');

    submit.disabled = !(current.value.length > 0 && validPassword && matches);
    return {validPassword, matches};
  }

  for (const input of [current, password, confirmation]) {
    input.addEventListener('input', validate);
    input.addEventListener('change', validate);
  }

  for (const button of form.querySelectorAll('[data-change-password-toggle]')) {
    const input = form.querySelector('#' + button.dataset.changePasswordToggle);
    if (!input) continue;
    button.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      button.setAttribute('aria-pressed', String(show));
      button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      button.title = show ? 'Hide password' : 'Show password';
      const icon = button.querySelector('i');
      if (icon) icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  }

  form.addEventListener('submit', event => {
    const state = validate();
    if (!state.validPassword || !state.matches || !current.value) {
      event.preventDefault();
      (!state.validPassword ? password : !state.matches ? confirmation : current).focus();
    }
  });

  // Refresh any browser-autofilled fields without persisting credentials.
  window.addEventListener('pageshow', validate);
  validate();
})();
