(() => {
  'use strict';

  const form = document.getElementById('permit-step2-form');
  if (!form) return;

  const sections = [...form.querySelectorAll('[data-step2-form-section]')];
  const links = [...document.querySelectorAll('.step2-section-nav a[href^="#step2-section-"]')];
  if (sections.length < 2 || links.length !== sections.length) return;

  let current = 0;
  let furthest = 0;

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

  function firstInvalid(section) {
    return [...section.querySelectorAll('input,select,textarea')]
      .find(field => !field.disabled && !field.checkValidity()) || null;
  }

  function goNext(index) {
    const invalid = firstInvalid(sections[index]);
    if (invalid) {
      form.classList.add('was-validated');
      invalid.reportValidity();
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
    const section = event.target.closest('[data-step2-form-section]');
    const index = sections.indexOf(section);
    if (index >= 0 && index !== current) showSection(index, false);
  }, true);

  form.addEventListener('step2:submission-invalid', event => {
    const section = event.detail?.target?.closest('[data-step2-form-section]');
    const index = sections.indexOf(section);
    if (index >= 0) showSection(index);
  });

  showSection(0, false);
})();
