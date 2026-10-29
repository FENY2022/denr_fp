(() => {
  'use strict';

  const form = document.getElementById('applicant-registration-form');
  if (!form) return;

  const password = form.querySelector('#password');
  const confirmation = form.querySelector('#password_confirm');
  const passwordFeedback = form.querySelector('#password-feedback');
  const confirmationFeedback = form.querySelector('#password-confirm-feedback');
  const rules = [...form.querySelectorAll('[data-password-rule]')];
  if (!password || !confirmation || !passwordFeedback || !confirmationFeedback) return;

  const byteLength = value => new TextEncoder().encode(value).length;

  function validate() {
    const value = password.value;
    const length = byteLength(value);
    const checks = {
      minimum: length >= 12,
      letter: /[A-Za-z]/.test(value),
      number: /[0-9]/.test(value),
      maximum: value.length > 0 && length <= 200
    };

    for (const rule of rules) {
      const passed = Boolean(checks[rule.dataset.passwordRule]);
      rule.classList.toggle('text-success', passed);
      rule.classList.toggle('text-secondary', !passed);
      const icon = rule.querySelector('i');
      if (icon) icon.className = passed ? 'bi bi-check-circle-fill me-2' : 'bi bi-circle me-2';
      const status = rule.querySelector('.password-rule-status');
      if (status) status.textContent = passed ? 'Met' : 'Not met';
    }

    const validPassword = Object.values(checks).every(Boolean);
    const confirmationEntered = confirmation.value.length > 0;
    const matches = confirmationEntered && confirmation.value === value;

    password.setCustomValidity(value && !validPassword
      ? 'Password must be 12 to 200 characters long and include letters and numbers.'
      : '');
    confirmation.setCustomValidity(confirmationEntered && !matches ? 'Passwords do not match.' : '');

    passwordFeedback.textContent = !value ? 'Enter a password to check its requirements.'
      : validPassword ? 'Password meets all requirements.' : 'Please complete all password requirements.';
    passwordFeedback.className = 'small mt-2 ' + (validPassword ? 'text-success' : 'text-secondary');

    confirmationFeedback.textContent = !confirmationEntered ? 'Re-enter your password to confirm.'
      : matches ? 'Passwords match.' : 'Passwords do not match.';
    confirmationFeedback.className = 'small mt-2 ' + (!confirmationEntered ? 'text-secondary' : matches ? 'text-success' : 'text-danger');

    return {validPassword, matches};
  }

  password.addEventListener('input', validate);
  confirmation.addEventListener('input', validate);

  for (const button of form.querySelectorAll('[data-toggle-password]')) {
    const input = document.getElementById(button.dataset.togglePassword);
    if (!input) continue;
    button.addEventListener('click', () => {
      const showing = input.type === 'password';
      input.type = showing ? 'text' : 'password';
      button.setAttribute('aria-pressed', String(showing));
      button.setAttribute('aria-label', showing ? 'Hide ' + (input === password ? 'password' : 'confirmation password')
        : 'Show ' + (input === password ? 'password' : 'confirmation password'));
      button.title = showing ? 'Hide password' : 'Show password';
      const icon = button.querySelector('i');
      if (icon) icon.className = showing ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  }

  form.addEventListener('submit', event => {
    const state = validate();
    if (!state.validPassword || !state.matches) {
      event.preventDefault();
      (state.validPassword ? confirmation : password).focus();
    }
  });

  validate();
})();
