(() => {
  'use strict';

  const modal = document.getElementById('userEditModal');
  const profileForm = modal?.querySelector('#editUserProfileForm');
  const passwordForm = modal?.querySelector('#editUserPasswordForm');
  if (!modal || !profileForm || !passwordForm) return;

  // App shell may use transforms or overflow; move dialogs to the document body.
  document.body.appendChild(modal);

  const input = (form, name) => form.elements.namedItem(name);
  const profileSave = profileForm.querySelector('[type="submit"]');
  const resetSave = passwordForm.querySelector('#editUserResetButton');
  const resetNav = modal.querySelector('[data-edit-reset-tab]');
  const selfNotice = modal.querySelector('[data-edit-self-notice]');
  const multiRoleNotice = modal.querySelector('[data-edit-multi-role-notice]');
  const userIdLabel = modal.querySelector('[data-edit-user-id]');
  const usernameLabel = modal.querySelector('[data-edit-user-username]');
  const officeReferenceLabel = modal.querySelector('[data-edit-office-reference]');
  const profileTab = modal.querySelector('#editUserProfileTab');
  const passwordTab = modal.querySelector('#editUserPasswordTab');
  const password = input(passwordForm, 'new_password');
  const confirm = input(passwordForm, 'confirm_password');
  const passwordFeedback = modal.querySelector('[data-edit-password-feedback]');
  const confirmFeedback = modal.querySelector('[data-edit-confirm-feedback]');

  if (!profileSave || !resetSave || !resetNav || !selfNotice || !multiRoleNotice ||
      !userIdLabel || !usernameLabel || !officeReferenceLabel || !profileTab || !passwordTab ||
      !password || !confirm || !passwordFeedback || !confirmFeedback) return;

  let editSelf = modal.dataset.reopenSelf === '1';
  const validPassword = value => {
    const size = new TextEncoder().encode(value).length;
    return size >= 12 && size <= 200 && /[A-Za-z]/.test(value) && /[0-9]/.test(value);
  };

  function validateProfile() {
    const role = input(profileForm, 'role');
    const status = input(profileForm, 'status');
    // This supplements (never replaces) server-side self-protection and role checks.
    const protectedAdmin = editSelf && (role.value !== 'super_admin' || status.value !== 'active');
    status.setCustomValidity(protectedAdmin
      ? 'You cannot suspend your own administrator account or remove its administrator role.'
      : '');
    const good = profileForm.checkValidity();
    profileSave.disabled = !good;
    return good;
  }

  function validatePassword() {
    const value = password.value;
    const entered = value.length > 0;
    const goodPassword = validPassword(value);
    const hasConfirmation = confirm.value.length > 0;
    const matches = hasConfirmation && confirm.value === value;

    password.setCustomValidity(entered && !goodPassword
      ? 'Temporary password must be 12 to 200 characters with letters and numbers.'
      : '');
    confirm.setCustomValidity(hasConfirmation && !matches ? 'Passwords do not match.' : '');

    passwordFeedback.textContent = !entered
      ? '12–200 characters with at least one letter and one number.'
      : goodPassword ? 'Password meets the requirements.'
        : 'Use 12–200 characters including a letter and a number.';
    passwordFeedback.className = 'form-text ' +
      (entered ? (goodPassword ? 'text-success' : 'text-danger') : 'text-secondary');

    confirmFeedback.textContent = !hasConfirmation ? 'Re-enter the temporary password.'
      : matches ? 'Passwords match.' : 'Passwords do not match.';
    confirmFeedback.className = 'form-text ' +
      (hasConfirmation ? (matches ? 'text-success' : 'text-danger') : 'text-secondary');

    const good = passwordForm.checkValidity() && goodPassword && matches && !resetNav.hidden;
    resetSave.disabled = !good;
    return good;
  }

  function resetPasswordVisibility() {
    for (const button of modal.querySelectorAll('[data-toggle-edit-password]')) {
      const target = modal.querySelector('#' + button.dataset.toggleEditPassword);
      if (target) target.type = 'password';
      button.setAttribute('aria-pressed', 'false');
      button.setAttribute('aria-label', target === password
        ? 'Show temporary password' : 'Show confirmation password');
      button.title = 'Show password';
      const icon = button.querySelector('i');
      if (icon) icon.className = 'bi bi-eye';
    }
  }

  function setProfileField(name, value) {
    const field = input(profileForm, name);
    if (field) field.value = value ?? '';
  }

  modal.addEventListener('show.bs.modal', event => {
    const button = event.relatedTarget?.closest?.('[data-user-edit-trigger]');
    if (!button) {
      // Server-side validation errors: retain submitted non-password fields.
      validateProfile();
      validatePassword();
      return;
    }

    const userId = Number(button.dataset.userId);
    const action = button.dataset.editUrl;
    if (!Number.isSafeInteger(userId) || userId < 1 || !action ||
        !['active', 'suspended'].includes(button.dataset.userStatus)) {
      event.preventDefault();
      return;
    }

    profileForm.reset();
    passwordForm.reset();
    password.setCustomValidity('');
    confirm.setCustomValidity('');
    resetPasswordVisibility();

    profileForm.action = action;
    passwordForm.action = action;
    userIdLabel.textContent = String(userId);
    usernameLabel.textContent = button.dataset.userUsername || 'Not assigned';
    officeReferenceLabel.textContent = button.dataset.userReferenceEmail || 'Not provided';

    setProfileField('name', button.dataset.userName);
    setProfileField('email', button.dataset.userEmail);
    setProfileField('contact_number', button.dataset.userContact);
    setProfileField('office_station', button.dataset.userOffice);
    setProfileField('role', button.dataset.userRole);
    setProfileField('status', button.dataset.userStatus);

    editSelf = button.dataset.isSelf === '1';
    selfNotice.hidden = !editSelf;
    resetNav.hidden = button.dataset.canReset === '0';
    const assignedRoles = (button.dataset.userRoles || '').split(',').filter(Boolean);
    multiRoleNotice.hidden = assignedRoles.length <= 1;

    // Start with profile editing; resets are an explicit separate operation.
    if (window.bootstrap?.Tab) {
      window.bootstrap.Tab.getOrCreateInstance(profileTab).show();
    }
    validateProfile();
    validatePassword();
  });

  modal.addEventListener('shown.bs.modal', () => {
    // The server chooses which tab to reopen after a failed POST.
    if (modal.dataset.reopen === '1' && modal.dataset.reopenAction === 'reset_password'
        && !resetNav.hidden && window.bootstrap?.Tab) {
      window.bootstrap.Tab.getOrCreateInstance(passwordTab).show();
      password.focus();
    } else {
      input(profileForm, 'name')?.focus();
    }
  });

  modal.addEventListener('hidden.bs.modal', () => {
    profileForm.reset();
    passwordForm.reset();
    password.setCustomValidity('');
    confirm.setCustomValidity('');
    input(profileForm, 'status')?.setCustomValidity('');
    resetPasswordVisibility();
    editSelf = false;
    validateProfile();
    validatePassword();
  });

  for (const control of profileForm.querySelectorAll('input,select')) {
    control.addEventListener('input', validateProfile);
    control.addEventListener('change', validateProfile);
  }
  password.addEventListener('input', validatePassword);
  confirm.addEventListener('input', validatePassword);

  for (const button of modal.querySelectorAll('[data-toggle-edit-password]')) {
    button.addEventListener('click', () => {
      const target = modal.querySelector('#' + button.dataset.toggleEditPassword);
      if (!target) return;
      const show = target.type === 'password';
      target.type = show ? 'text' : 'password';
      button.setAttribute('aria-pressed', String(show));
      button.setAttribute('aria-label', (show ? 'Hide ' : 'Show ') +
        (target === password ? 'temporary password' : 'confirmation password'));
      button.title = show ? 'Hide password' : 'Show password';
      const icon = button.querySelector('i');
      if (icon) icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  }

  profileForm.addEventListener('submit', event => {
    if (!validateProfile()) {
      event.preventDefault();
      profileForm.querySelector(':invalid')?.focus();
      return;
    }
    profileSave.disabled = true; // Stop accidental double-submit.
  });
  passwordForm.addEventListener('submit', event => {
    if (!validatePassword()) {
      event.preventDefault();
      passwordForm.querySelector(':invalid')?.focus();
      return;
    }
    resetSave.disabled = true;
  });

  if (modal.dataset.reopen === '1' && window.bootstrap?.Modal) {
    window.bootstrap.Modal.getOrCreateInstance(modal).show();
  }
})();
