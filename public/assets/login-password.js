(() => {
  'use strict';

  const form = document.getElementById('login-form');
  const password = form?.querySelector('#password');
  const toggle = form?.querySelector('#login-password-toggle');
  if (!password || !toggle) return;

  toggle.addEventListener('click', () => {
    const show = password.type === 'password';
    password.type = show ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', String(show));
    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    toggle.title = show ? 'Hide password' : 'Show password';
    const icon = toggle.querySelector('i');
    if (icon) icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
  });
})();
