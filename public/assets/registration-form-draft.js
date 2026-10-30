(() => {
  'use strict';

  const storageKey = 'fpoms.registration.form.v1';
  let storage = null;
  try { storage = window.localStorage; } catch (_) {}

  const url = new URL(window.location.href);
  if (url.searchParams.get('registration') === 'success') {
    storage?.removeItem(storageKey);
    url.searchParams.delete('registration');
    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
  }

  const form = document.getElementById('applicant-registration-form');
  if (!form || !storage) return;

  const fieldNames = [
    'representative_name', 'representative_position', 'email',
    'company_name', 'contact_number', 'company_address', 'organization_type'
  ];

  function readDraft() {
    try {
      const value = JSON.parse(storage.getItem(storageKey) || 'null');
      return value && typeof value === 'object' ? value : null;
    } catch (_) {
      storage.removeItem(storageKey);
      return null;
    }
  }

  function saveDraft() {
    const fields = {};
    for (const name of fieldNames) {
      const input = form.elements.namedItem(name);
      if (input) fields[name] = input.value;
    }
    const idTypes = [...form.querySelectorAll('[data-id-type]')].map(select => select.value);
    try { storage.setItem(storageKey, JSON.stringify({fields, idTypes})); } catch (_) {}
  }

  function restoreDraft() {
    const draft = readDraft();
    if (!draft) return;
    for (const name of fieldNames) {
      const input = form.elements.namedItem(name);
      const saved = draft.fields?.[name];
      // Server-rendered POST values take precedence after validation errors.
      if (input && !input.value && typeof saved === 'string') input.value = saved;
    }

    if (!Array.isArray(draft.idTypes)) return;
    const addButton = form.querySelector('[data-add-id]');
    while (form.querySelectorAll('[data-id-type]').length < draft.idTypes.length
        && addButton && !addButton.disabled) {
      addButton.click();
    }
    [...form.querySelectorAll('[data-id-type]')].forEach((select, index) => {
      if (!select.value && typeof draft.idTypes[index] === 'string') {
        select.value = draft.idTypes[index];
      }
    });
  }

  restoreDraft();
  form.addEventListener('input', saveDraft);
  form.addEventListener('change', saveDraft);

  document.querySelector('[data-confirm-clear-registration]')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    const errorMessage = form.ownerDocument.querySelector('[data-clear-registration-error]');
    window.FPOMSActionFeedback?.stop(button);
    window.FPOMSActionFeedback?.start(button,'Clearing…',{lock:true,duration:15000});
    button.disabled = true;
    if (errorMessage) errorMessage.classList.add('d-none');
    const data = new FormData();
    data.set('csrf_token', form.querySelector('[name="csrf_token"]')?.value || '');
    try {
      const response = await fetch('register-draft-clear', {
        method: 'POST', body: data, credentials: 'same-origin',
        headers: {'Accept': 'application/json'}
      });
      const result = await response.json();
      if (!response.ok || result.error) throw new Error(result.error || 'Unable to clear the form.');
      storage.removeItem(storageKey);
      form.reset();
      window.location.reload();
    } catch (error) {
      window.FPOMSActionFeedback?.stop(button);
      button.disabled = false;
      if (errorMessage) {
        errorMessage.textContent = error.message || 'Unable to clear the form. Please retry.';
        errorMessage.classList.remove('d-none');
      }
    }
  });
})();
