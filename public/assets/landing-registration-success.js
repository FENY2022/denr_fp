(() => {
  'use strict';
  const modal = document.getElementById('registration-success-modal');
  if (!modal) return;
  // Never store the registration password; clear only the non-secret form draft.
  try { window.localStorage.removeItem('fpoms.registration.form.v1'); }
  catch (_) { /* Storage may be disabled. */ }
  if (window.bootstrap?.Modal) {
    new window.bootstrap.Modal(modal, { backdrop: 'static', keyboard: false }).show();
  }
})();
