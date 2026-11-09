(() => {
  'use strict';

  const filesModal = document.getElementById('fp-files-modal');
  const auditModal = document.getElementById('fp-audit-trail-modal');
  if (!filesModal || !auditModal || !window.bootstrap?.Modal) return;

  const previewModal = document.getElementById('registration-document-modal');
  const Modal = window.bootstrap.Modal;
  const filesBody = filesModal.querySelector('.modal-body');
  const documentLinks = [...filesModal.querySelectorAll('a[data-document-preview]')];
  const query = new URLSearchParams(window.location.search);
  const memoryKey = 'fp-files-modal:' + window.location.pathname + ':' + (query.get('id') || 'unknown');
  const nextFrame = window.requestAnimationFrame?.bind(window) || (callback => callback());
  let returnToFileList = false;
  let restoringScroll = false;
  let memory = { scrollTop: 0, viewed: [] };

  try {
    const saved = JSON.parse(window.sessionStorage?.getItem(memoryKey) || 'null');
    if (saved && Number.isFinite(saved.scrollTop) && Array.isArray(saved.viewed)) {
      memory = { scrollTop: saved.scrollTop, viewed: saved.viewed.map(String) };
    }
  } catch (error) {
    // Storage can be unavailable in privacy-restricted browser contexts.
  }

  const saveMemory = () => {
    try {
      window.sessionStorage?.setItem(memoryKey, JSON.stringify(memory));
    } catch (error) {
      // The modal still works when browser storage is unavailable.
    }
  };
  const documentId = link => String(link.dataset.documentId || link.href || '');
  const markViewed = link => {
    const id = documentId(link);
    if (id && !memory.viewed.includes(id)) memory.viewed.push(id);
    link.classList.add('step2-requirement-file--viewed');
    saveMemory();
  };

  documentLinks.forEach(link => {
    if (memory.viewed.includes(documentId(link))) {
      link.classList.add('step2-requirement-file--viewed');
    }
  });
  filesBody?.addEventListener('scroll', () => {
    if (restoringScroll || !filesModal.classList.contains('show')) return;
    memory.scrollTop = filesBody.scrollTop;
    saveMemory();
  }, { passive: true });
  filesModal.addEventListener('shown.bs.modal', () => {
    if (!filesBody) return;
    const savedScrollTop = memory.scrollTop;
    restoringScroll = true;
    filesBody.scrollTop = savedScrollTop;
    nextFrame(() => {
      filesBody.scrollTop = savedScrollTop;
      nextFrame(() => {
        filesBody.scrollTop = savedScrollTop;
        restoringScroll = false;
      });
    });
  });

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
    if (filesBody) memory.scrollTop = filesBody.scrollTop;
    restoringScroll = true;
    saveMemory();
    markViewed(link);
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

  // The application detail opens its audit trail by default. Action-list links
  // can still explicitly select the files modal. A query parameter never
  // authorizes access; the PHP case route does.
  const selected = query.get('view');
  if (selected === 'files') {
    Modal.getOrCreateInstance(filesModal).show();
  } else if (selected === 'audit' || selected === null) {
    Modal.getOrCreateInstance(auditModal).show();
  }
})();
