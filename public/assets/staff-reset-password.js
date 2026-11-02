(() => {
  'use strict';

  const form = document.getElementById('staff-reset-password-form');
  if (!form) return;

  const password = form.querySelector('#password');
  const confirmation = form.querySelector('#confirm_password');
  const feedback = form.querySelector('#staff-password-feedback');
  const confirmFeedback = form.querySelector('#staff-confirm-feedback');
  const rules = [...form.querySelectorAll('[data-staff-password-rule]')];
  if (!password || !confirmation || !feedback || !confirmFeedback) return;

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
      const name = rule.dataset.staffPasswordRule;
      if (name === 'different') continue;
      const passed = Boolean(checks[name]);
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

    feedback.textContent = !value ? 'Enter a new password to check its requirements.'
      : validPassword ? 'Password meets the visible requirements. Current-password reuse is checked securely when submitted.'
        : 'Please complete all password requirements.';
    feedback.className = 'small mt-2 ' + (validPassword ? 'text-success' : 'text-secondary');

    confirmFeedback.textContent = !confirmationEntered ? 'Re-enter your new password to confirm.'
      : matches ? 'Passwords match.' : 'Passwords do not match.';
    confirmFeedback.className = 'small mt-2 '
      + (!confirmationEntered ? 'text-secondary' : matches ? 'text-success' : 'text-danger');

    return {validPassword, matches};
  }

  password.addEventListener('input', validate);
  confirmation.addEventListener('input', validate);

  for (const button of form.querySelectorAll('[data-staff-password-toggle]')) {
    const input = form.querySelector('#' + button.dataset.staffPasswordToggle);
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
    if (!state.validPassword || !state.matches) {
      event.preventDefault();
      (state.validPassword ? confirmation : password).focus();
    }
  });

  window.addEventListener('pageshow', validate);
  validate();
})();
