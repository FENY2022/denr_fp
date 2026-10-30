(() => {
  'use strict';

  /*
   * Shared loading acknowledgment for all FP-OMS pages.
   * Native forms and the existing AJAX/modal controllers own their outcomes:
   * never replace button HTML, change submitted values, or call form.submit().
   */
  const active = new Map();
  const forms = new Set();
  const TRANSIENT_MS = 750;
  const NAVIGATION_MS = 12000;
  const SUBMIT_MS = 20000;

  function labelFor(element) {
    const name = (
      element.dataset.fpomsLoadingText
      || element.textContent
      || element.value
      || element.getAttribute('aria-label')
      || element.getAttribute('title')
      || ''
    ).toLowerCase().trim();
    if (element.dataset.fpomsLoadingText) return element.dataset.fpomsLoadingText;
    if (/sign.?in|log.?in/.test(name)) return 'Signing in…';
    if (/register/.test(name)) return 'Registering…';
    if (/resubmit|submit|send|apply/.test(name)) return 'Submitting…';
    if (/upload|choose file|add document/.test(name)) return 'Opening…';
    if (/remove|delete|clear/.test(name)) return 'Removing…';
    if (/save|update|edit/.test(name)) return 'Saving…';
    if (/search|filter|find/.test(name)) return 'Searching…';
    if (/approve|activate|confirm|forward|reject|return for correction/.test(name)) return 'Confirming…';
    if (/preview|view document|next|previous/.test(name)) return 'Loading…';
    if (/download|print|export/.test(name)) return 'Preparing…';
    if (/close|cancel|hide/.test(name)) return 'Closing…';
    if (/open|view|dashboard|back|profile/.test(name)) return 'Opening…';
    if (/add|create/.test(name)) return 'Adding…';
    return 'Processing…';
  }

  function end(element) {
    const state = active.get(element);
    if (!state) return;
    clearTimeout(state.timer);
    state.indicator?.remove();
    element.classList.remove('fpoms-action-loading', 'fpoms-action-locked', 'fpoms-action-icon-only');
    if (state.busy === null) element.removeAttribute('aria-busy');
    else element.setAttribute('aria-busy', state.busy);
    active.delete(element);
  }

  function start(element, message, opts = {}) {
    if (!element || (element.disabled && !opts.allowDisabled)) return false;
    if (active.has(element)) return false;
    const indicator = document.createElement('span');
    indicator.className = 'fpoms-action-indicator';
    indicator.setAttribute('role', 'status');
    indicator.setAttribute('aria-live', 'polite');
    const spinner = document.createElement('span');
    spinner.className = 'fpoms-action-spinner';
    spinner.setAttribute('aria-hidden','true');
    const label = document.createElement('span');
    label.className = 'fpoms-action-message';
    label.textContent = message || labelFor(element);
    indicator.append(spinner, label);
    const state = {
      indicator, busy: element.getAttribute('aria-busy'),
      timer: null
    };
    active.set(element, state);
    element.classList.add('fpoms-action-loading');
    if (element.matches?.('.btn-close, [data-sidebar-toggle]')) {
      element.classList.add('fpoms-action-icon-only');
    }
    if (opts.lock) element.classList.add('fpoms-action-locked');
    element.setAttribute('aria-busy','true');
    // Inputs cannot own child elements; update their visible value instead.
    if (element.tagName === 'INPUT') {
      state.originalValue = element.value;
      element.value = label.textContent;
    } else element.append(indicator);
    state.timer = setTimeout(() => {
      if (state.originalValue !== undefined) element.value = state.originalValue;
      end(element);
      if (opts.form && opts.form.dataset.fpomsSubmitting === '1') {
        opts.form.dataset.fpomsSubmitting = '0';
        forms.delete(opts.form);
      }
    }, Math.min(Math.max(opts.duration || TRANSIENT_MS, 250), SUBMIT_MS));
    return true;
  }

  // Restore input labels if a request is interrupted/returns to a cached page.
  function stop(element) {
    const state = active.get(element);
    if (state?.originalValue !== undefined) element.value = state.originalValue;
    end(element);
  }

  window.FPOMSActionFeedback = Object.freeze({ start, stop, labelFor });

  // Capture makes feedback visible before a button's own handler can disable
  // it for an async request, and suppresses double-clicks without touching values.
  document.addEventListener('click', event => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const element = event.target.closest?.('button, input[type="submit"], input[type="button"], a.btn, a.sidebar-link, a.quick-action');
    if (!element) return;
    if (active.has(element) && element.classList.contains('fpoms-action-locked')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    if (element.disabled || element.matches('button[type="submit"], input[type="submit"]')
        || (element.tagName === 'BUTTON' && !element.hasAttribute('type') && element.closest('form'))) {
      return; // A confirmed, accepted submit event controls form feedback.
    }
    if (element.tagName !== 'A') {
      start(element, labelFor(element), {lock:true, duration: TRANSIENT_MS});
    }
  }, true);

  // Link handlers may intercept clicks for document preview or modals.
  // Observe defaultPrevented after those handlers to avoid a stuck navigation state.
  document.addEventListener('click', event => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const element = event.target.closest?.('a.btn, a.sidebar-link, a.quick-action');
    if (!element || active.has(element) || element.hasAttribute('download') || element.target === '_blank') return;
    const href = element.getAttribute('href') || '';
    if (!href || href.startsWith('javascript:')) return;
    const navigation = !event.defaultPrevented && href !== '#' && !href.startsWith('#')
      && !element.hasAttribute('data-document-preview')
      && !element.hasAttribute('data-bs-toggle');
    start(element, navigation ? 'Opening…' : labelFor(element),
      {lock:true, duration: navigation ? NAVIGATION_MS : TRANSIENT_MS});
  });

  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const button = event.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
    if (event.defaultPrevented) {
      // The form's own JS may be opening a confirmation modal or autosaving.
      // Never lock or permanently disable its submit control here.
      if (button && !active.has(button)) start(button, 'Preparing…');
      return;
    }
    if (form.dataset.fpomsSubmitting === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.fpomsSubmitting = '1';
    forms.add(form);
    if (button) start(button, labelFor(button),
      {lock:true, duration: SUBMIT_MS, form, allowDisabled:true});
  });

  function reset() {
    for (const element of [...active.keys()]) stop(element);
    for (const form of forms) form.dataset.fpomsSubmitting = '0';
    forms.clear();
  }
  window.addEventListener('pageshow', reset);
  window.addEventListener('pagehide', reset);
})();
