(() => {
  'use strict';
  const form = document.getElementById('permit-step2-form');
  const type = form?.querySelector('[data-fp-project-type]');
  const typeField = form?.querySelector('[data-fp-project-type-field]');
  const typePicker = form?.querySelector('[data-fp-project-type-picker]');
  const typeOptions = typePicker
    ? [...typePicker.querySelectorAll('[data-fp-project-type-option]')]
    : [];
  const other = form?.querySelector('[data-fp-other-project]');
  const otherWrap = form?.querySelector('[data-fp-other-project-wrap]');
  const doeCard = form?.querySelector('[data-step2-doe-upload]');
  const category = form?.querySelector('[data-step2-category-select]');
  const hint = form?.querySelector('[data-fp-doe-hint]');
  if (!form || !type || !other || !otherWrap) return;

  if (typeField && typePicker && typeOptions.length) {
    typeField.classList.add('is-enhanced');
  }

  const exemption = value => value === 'solar' || value === 'biomass';
  const syncPicker = () => {
    if (!typeOptions.length) return;
    const selected = typeOptions.find(option =>
      option.dataset.fpProjectTypeOption === type.value);
    typeOptions.forEach((option, index) => {
      const active = option === selected;
      option.classList.toggle('is-selected', active);
      option.setAttribute('aria-checked', active ? 'true' : 'false');
      option.tabIndex = active || (!selected && index === 0) ? 0 : -1;
    });
    typePicker.classList.toggle('is-invalid', !type.validity.valid && type.classList.contains('is-invalid'));
  };

  const chooseType = (option, focus = true) => {
    const value = option?.dataset.fpProjectTypeOption || '';
    if (!value || type.value === value) {
      if (focus) option?.focus();
      return;
    }
    type.value = value;
    type.classList.remove('is-invalid');
    type.dispatchEvent(new Event('input', {bubbles: true}));
    type.dispatchEvent(new Event('change', {bubbles: true}));
    if (focus) option.focus();
  };

  typeOptions.forEach((option, index) => {
    option.addEventListener('click', () => chooseType(option));
    option.addEventListener('keydown', event => {
      let next = index;
      if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % typeOptions.length;
      else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (index - 1 + typeOptions.length) % typeOptions.length;
      else if (event.key === 'Home') next = 0;
      else if (event.key === 'End') next = typeOptions.length - 1;
      else return;
      event.preventDefault();
      chooseType(typeOptions[next]);
    });
  });

  type.addEventListener('focus', () => {
    if (!typeField?.classList.contains('is-enhanced')) return;
    const target = typeOptions.find(option => option.dataset.fpProjectTypeOption === type.value)
      || typeOptions[0];
    window.requestAnimationFrame(() => target?.focus());
  });
  type.addEventListener('invalid', () => {
    typePicker?.classList.add('is-invalid');
  });

  const update = () => {
    const kind = type.value;
    const isOther = kind === 'other';
    const exempt = exemption(kind);
    otherWrap.classList.toggle('d-none', !isOther);
    other.required = isOther;
    other.disabled = !isOther;
    const savedDoe = !!form.querySelector(
      '[data-step2-draft-id][data-step2-requirement-type="doe_esc_coa"]'
    );
    const directDoe = !!form.querySelector(
      '[data-step2-requirement-type="doe_esc_coa"].is-pending'
    );
    // Keep a previously uploaded DOE file visible for removal on exempt types.
    if (doeCard) {
      doeCard.closest('.col-md-6')?.classList.toggle('d-none', exempt && !savedDoe && !directDoe);
      const uploadButton = doeCard.querySelector('[data-step2-pick-requirement]');
      if (uploadButton) uploadButton.disabled = exempt;
    }
    const doeOption = category?.querySelector('option[value="doe_esc_coa"]');
    if (doeOption) doeOption.disabled = exempt;
    if (exempt && category?.value === 'doe_esc_coa') {
      category.value = '';
      category.dispatchEvent(new Event('change', {bubbles: true}));
    }
    if (hint) {
      hint.textContent = exempt
        ? 'Annex 1 #10 (DOE ESC/COA) is Not Applicable to Solar/Biomass.'
        : isOther
          ? 'Annex 1 #10 (DOE ESC/COA) is required for Other projects.'
          : 'Select your Type of Project to determine whether DOE #10 applies.';
    }
    syncPicker();
  };
  other.addEventListener('input', () => other.setCustomValidity(''));
  type.addEventListener('change', update);
  type.addEventListener('input', update);
  window.addEventListener('pageshow', update);
  if (doeCard && typeof MutationObserver !== 'undefined') {
    new MutationObserver(update).observe(doeCard,{childList:true,subtree:true});
  }
  update();

  // Submission validation precedes the persistent-draft submit handler.
  // Server-side validation is authoritative, especially for stored revisions.
  form.addEventListener('submit', event => {
    other.setCustomValidity('');
    if (!type.value) {
      type.reportValidity();
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    if (type.value === 'other' && other.value.trim().length < 3) {
      other.setCustomValidity('Specify the Other Type of Project (at least 3 characters).');
      other.reportValidity();
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    const staged = [...form.querySelectorAll(
      '[data-step2-draft-id][data-step2-requirement-type="doe_esc_coa"]'
    )].length;
    const direct = [...form.querySelectorAll(
      'input[name="application_file_categories[]"]'
    )].filter(input => input.value === 'doe_esc_coa').length;
    const addedDoe = staged + direct > 0;
    const isNew = (form.querySelector('[data-step2-mode]')?.dataset.applicationId || '0') === '0';
    const invalidNew = type.value === 'other' && isNew && !addedDoe;
    const invalidExempt = exemption(type.value) && addedDoe;
    if (invalidNew || invalidExempt) {
      event.preventDefault();
      event.stopImmediatePropagation();
      const feedback = form.querySelector('[data-step2-feedback]');
      if (feedback) {
        feedback.classList.add('text-danger');
        feedback.textContent = invalidNew
          ? 'Other projects require upload of Annex 1 #10 DOE ESC/COA before submission.'
          : 'Solar/Biomass are exempt from DOE #10. Remove new DOE documents from the upload queue.';
      }
      const target = form.querySelector('[data-step2-requirement-card="doe_esc_coa"]')
        || form.querySelector('#step2-draft-uploader');
      form.dispatchEvent(new CustomEvent('step2:submission-invalid',{
        detail:{target}
      }));
    }
  }, {capture: true});
})();
