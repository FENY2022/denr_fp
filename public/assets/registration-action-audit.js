(() => {
  'use strict';
  const audit = document.getElementById('registration-action-audit-modal');
  if (!audit || !window.bootstrap?.Modal) return;
  const selected = new URLSearchParams(window.location.search).get('view');
  if (selected === 'audit') {
    window.bootstrap.Modal.getOrCreateInstance(audit).show();
  }
})();
