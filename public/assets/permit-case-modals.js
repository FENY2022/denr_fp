(() => {
  'use strict';

  const filesModal = document.getElementById('fp-files-modal');
  const auditModal = document.getElementById('fp-audit-trail-modal');
  if (!filesModal || !auditModal || !window.bootstrap?.Modal) return;

  const previewModal = document.getElementById('registration-document-modal');
  const Modal = window.bootstrap.Modal;
  let returnToFileList = false;

  // Bootstrap permits only one active modal at a time. Close the document
  // list completely before invoking the existing, authorized preview viewer.
  filesModal.addEventListener('click', event => {
    const element = event.target;
    const link = element instanceof Element
      ? element.closest('a[data-document-preview]') : null;
    if (!link || !filesModal.contains(link)
        || !filesModal.classList.contains('show') || !previewModal) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    filesModal.addEventListener('hidden.bs.modal', () => {
      returnToFileList = true;
      link.click();
    }, { once: true });
    Modal.getOrCreateInstance(filesModal).hide();
  }, true);

  // When the PDF/image preview closes, return to the same document list.
  previewModal?.addEventListener('hidden.bs.modal', () => {
    if (!returnToFileList) return;
    returnToFileList = false;
    Modal.getOrCreateInstance(filesModal).show();
  });

  // Action-list links open the case first, then only the selected modal.
  // A query parameter never authorizes access; the PHP case route does.
  const selected = new URLSearchParams(window.location.search).get('view');
  if (selected === 'files') {
    Modal.getOrCreateInstance(filesModal).show();
  } else if (selected === 'audit') {
    Modal.getOrCreateInstance(auditModal).show();
  }
})();
