(() => {
  'use strict';

  const form = document.getElementById('permit-step2-form');
  if (!form) return;

  const sections = [...form.querySelectorAll('[data-step2-form-section]')];
  const links = [...document.querySelectorAll('.step2-section-nav a[href^="#step2-section-"]')];
  if (sections.length < 2 || links.length !== sections.length) return;

  let current = 0;
  let furthest = 0;
  let invalidTimer = null;

  function showValidationToast(errors) {
    let container = document.querySelector('[data-step2-toast-container]');
    if (!container) {
      container = document.createElement('div');
      container.className = 'toast-container position-fixed top-0 end-0 p-3 step2-toast-container';
      container.dataset.step2ToastContainer = '';
      container.setAttribute('aria-live', 'assertive');
      container.setAttribute('aria-atomic', 'true');
      document.body.append(container);
    }

    const previous = container.querySelector('.toast');
    if (previous) window.bootstrap?.Toast.getInstance(previous)?.dispose();
    container.replaceChildren();
    const toast = document.createElement('div');
    toast.className = 'toast step2-validation-toast';
    toast.setAttribute('role', 'alert');
    const header = document.createElement('div');
    header.className = 'toast-header text-bg-danger';
    const title = document.createElement('strong');
    title.className = 'me-auto';
    title.textContent = 'Incomplete application';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close btn-close-white';
    close.dataset.bsDismiss = 'toast';
    close.setAttribute('aria-label', 'Close');
    header.append(title, close);
    const body = document.createElement('div');
    body.className = 'toast-body';
    body.textContent = errors.length === 1
      ? errors[0].message
      : `${errors.length} items need attention. ${errors[0].message}`;
    toast.append(header, body);
    container.append(toast);
    if (window.bootstrap?.Toast) {
      window.bootstrap.Toast.getOrCreateInstance(toast, {delay:5000}).show();
    } else {
      toast.classList.add('show');
    }
  }

  function showSection(index, scroll = true) {
    if (index < 0 || index >= sections.length) return;
    current = index;
    furthest = Math.max(furthest, index);

    sections.forEach((section, sectionIndex) => {
      const active = sectionIndex === index;
      section.hidden = !active;
      section.classList.toggle('is-active', active);
      if (active) section.setAttribute('aria-current', 'step');
      else section.removeAttribute('aria-current');
    });

    links.forEach((link, linkIndex) => {
      const active = linkIndex === index;
      link.classList.toggle('is-active', active);
      link.classList.toggle('is-complete', linkIndex < furthest && !active);
      link.setAttribute('aria-disabled', linkIndex > furthest ? 'true' : 'false');
      if (active) link.setAttribute('aria-current', 'step');
      else link.removeAttribute('aria-current');
    });

    sections[index].dispatchEvent(new CustomEvent('step2:section-shown', {bubbles:true}));
    if (scroll) {
      document.querySelector('.step2-section-nav')?.scrollIntoView({
        behavior:'smooth', block:'start'
      });
    }
  }

  function fieldLabel(field) {
    const label = field.id ? form.querySelector(`label[for="${CSS.escape(field.id)}"]`) : null;
    return label?.textContent.replace('*', '').trim() || field.name || 'This field';
  }

  function fieldMessage(field) {
    const label = fieldLabel(field);
    if (field.validity.valueMissing) return `${label} is required.`;
    if (field.validity.tooShort) return `${label} is too short.`;
    if (field.validity.tooLong) return `${label} is too long.`;
    return field.validationMessage || `${label} is invalid.`;
  }

  function sectionErrors(section) {
    const uploader = section.querySelector('#step2-draft-uploader');
    const errors = [...section.querySelectorAll('input,select,textarea')]
      .filter(field => !uploader?.classList.contains('is-enhanced')
        || !field.closest('#step2-draft-uploader'))
      .filter(field => !field.disabled && !field.validity.valid)
      .map(field => ({target:field, message:fieldMessage(field)}));
    if (!uploader || !uploader.classList.contains('is-enhanced')
        || uploader.dataset.applicationId !== '0') return errors;

    const files = section.querySelectorAll(
      '[data-step2-draft-id], .selected-file-card.is-pending'
    );
    const uploading = section.querySelector('.selected-file-card.is-uploading');
    if (uploading) {
      errors.push({target:uploader, message:'Wait for supporting documents to finish uploading.'});
    } else if (files.length === 0) {
      errors.push({target:uploader, message:'Upload at least one supporting document.'});
    }
    return errors;
  }

  function showErrors(index, errors) {
    const section = sections[index];
    section.querySelectorAll('.is-invalid').forEach(field => field.classList.remove('is-invalid'));
    section.querySelector('#step2-draft-uploader')?.classList.remove('has-validation-error');
    links[index].classList.toggle('has-errors', errors.length > 0);
    errors.forEach(error => {
      error.target.classList.add(error.target.id === 'step2-draft-uploader'
        ? 'has-validation-error' : 'is-invalid');
    });
  }

  function goNext(index) {
    const errors = sectionErrors(sections[index]);
    showErrors(index, errors);
    if (errors.length) {
      const invalid = errors[0].target;
      form.classList.add('was-validated');
      showValidationToast(errors);
      invalid.focus({preventScroll:true});
      invalid.scrollIntoView({behavior:'smooth', block:'center'});
      return;
    }
    showSection(index + 1);
  }

  sections.forEach((section, index) => {
    const actions = document.createElement('div');
    actions.className = 'step2-section-actions';

    if (index > 0) {
      const previous = document.createElement('button');
      previous.type = 'button';
      previous.className = 'btn btn-outline-secondary';
      previous.innerHTML = '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Previous';
      previous.addEventListener('click', () => showSection(index - 1));
      actions.append(previous);
    }

    if (index < sections.length - 1) {
      const next = document.createElement('button');
      next.type = 'button';
      next.className = 'btn btn-primary ms-auto';
      next.innerHTML = 'Next<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>';
      next.addEventListener('click', () => goNext(index));
      actions.append(next);
      section.append(actions);
      return;
    }

    const clear = section.querySelector('[data-bs-target="#step2-clear-modal"]');
    const submit = section.querySelector('button[type="submit"]');
    if (clear) actions.append(clear);
    if (submit) actions.append(submit);
    section.append(actions);
  });

  links.forEach((link, index) => {
    link.addEventListener('click', event => {
      event.preventDefault();
      if (index <= furthest) showSection(index);
      else if (index === current + 1) goNext(current);
    });
  });

  // Native validation may find a required control inside a hidden section.
  form.addEventListener('invalid', event => {
    event.preventDefault();
    clearTimeout(invalidTimer);
    invalidTimer = setTimeout(() => {
      const section = sections.find(candidate => sectionErrors(candidate).length > 0);
      const index = sections.indexOf(section);
      if (index < 0) return;
      const errors = sectionErrors(section);
      showSection(index, false);
      showErrors(index, errors);
      showValidationToast(errors);
      errors[0].target.focus({preventScroll:true});
      errors[0].target.scrollIntoView({behavior:'smooth', block:'center'});
    }, 0);
  }, true);

  form.addEventListener('step2:submission-invalid', event => {
    const section = event.detail?.target?.closest('[data-step2-form-section]');
    const index = sections.indexOf(section);
    if (index >= 0) {
      showSection(index);
      const errors = sectionErrors(section);
      showErrors(index, errors.length ? errors : [{
        target:event.detail.target,
        message:'Complete the supporting document upload before submitting.'
      }]);
      const displayedErrors = errors.length ? errors : [{
        target:event.detail.target,
        message:'Complete the supporting document upload before submitting.'
      }];
      showValidationToast(displayedErrors);
      displayedErrors[0].target.focus({preventScroll:true});
      displayedErrors[0].target.scrollIntoView({behavior:'smooth', block:'center'});
    }
  });

  form.addEventListener('input', event => {
    event.target.classList?.remove('is-invalid');
  });
  form.addEventListener('change', event => {
    event.target.classList?.remove('is-invalid');
  });

  showSection(0, false);
})();
