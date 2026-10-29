(() => {
  'use strict';

  const form = document.getElementById('applicant-registration-form');
  if (!form) return;
  const steps = [...form.querySelectorAll('[data-registration-step]')];
  if (steps.length < 2) return;

  const progress = document.createElement('nav');
  progress.className = 'registration-step-progress';
  progress.setAttribute('aria-label', 'Registration progress');
  const progressList = document.createElement('ol');
  const markers = steps.map((step, index) => {
    const item = document.createElement('li');
    const number = document.createElement('span');
    const label = document.createElement('span');
    number.className = 'registration-step-number';
    number.textContent = String(index + 1).padStart(2, '0');
    label.className = 'registration-step-label';
    label.textContent = step.querySelector('h3')?.textContent?.trim() || 'Step ' + (index + 1);
    item.append(number, label);
    progressList.append(item);
    return item;
  });
  progress.append(progressList);
  steps[0].before(progress);

  let currentStep = 0;

  function showStep(index, scroll = true) {
    if (index < 0 || index >= steps.length) return;
    currentStep = index;
    steps.forEach((step, stepIndex) => {
      const active = stepIndex === index;
      step.hidden = !active;
      step.classList.toggle('is-active', active);
      if (active) step.setAttribute('aria-current', 'step');
      else step.removeAttribute('aria-current');
    });
    markers.forEach((marker, markerIndex) => {
      marker.classList.toggle('is-active', markerIndex === index);
      marker.classList.toggle('is-complete', markerIndex < index);
      if (markerIndex === index) marker.setAttribute('aria-current', 'step');
      else marker.removeAttribute('aria-current');
    });
    if (scroll) progress.scrollIntoView({behavior:'smooth',block:'start'});
  }

  function firstInvalidField(step) {
    return [...step.querySelectorAll('input,select,textarea')]
      .find(field => !field.disabled && !field.checkValidity()) || null;
  }

  function goNext(index) {
    const invalid = firstInvalidField(steps[index]);
    if (invalid) {
      form.classList.add('was-validated');
      invalid.reportValidity();
      invalid.focus({preventScroll:true});
      invalid.scrollIntoView({behavior:'smooth',block:'center'});
      return;
    }
    showStep(index + 1);
  }

  steps.forEach((step, index) => {
    const actions = document.createElement('div');
    actions.className = 'registration-step-actions';
    if (index > 0) {
      const previous = document.createElement('button');
      previous.type = 'button';
      previous.className = 'btn btn-outline-secondary';
      previous.innerHTML = '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Previous';
      previous.addEventListener('click', () => showStep(index - 1));
      actions.append(previous);
    }
    if (index < steps.length - 1) {
      const next = document.createElement('button');
      next.type = 'button';
      next.className = 'btn btn-primary ms-auto';
      next.innerHTML = 'Next<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>';
      next.addEventListener('click', () => goNext(index));
      actions.append(next);
      step.append(actions);
    } else {
      const finalActions = step.querySelector('[data-registration-review-open]')?.parentElement;
      if (finalActions) finalActions.prepend(...actions.children);
    }
  });

  form.addEventListener('registration:reveal-field', event => {
    const target = event.detail?.target;
    const step = target instanceof Element ? target.closest('[data-registration-step]') : null;
    const index = steps.indexOf(step);
    if (index >= 0 && index !== currentStep) showStep(index, false);
  });

  showStep(0, false);
})();
