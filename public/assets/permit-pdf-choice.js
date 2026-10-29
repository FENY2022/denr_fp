(() => {
  'use strict';
  // A PDF must not be staged until the applicant explicitly chooses an action.
  const element = document.getElementById('fp-pdf-upload-choice-modal');
  if (!element) return;

  const nameLabel = element.querySelector('[data-pdf-choice-filename]');
  const noButton = element.querySelector('[data-pdf-choice-no]');
  const yesButton = element.querySelector('[data-pdf-choice-yes]');
  const cancelButtons = element.querySelectorAll('[data-pdf-choice-cancel]');
  const modeNote = element.querySelector('[data-pdf-choice-mode-note]');
  let active = null;

  function choose(action) {
    if (!active || active.action) return;
    active.action = action;
    active.modal.hide();
  }

  noButton.addEventListener('click', () => choose('original'));
  yesButton.addEventListener('click', () => choose('organize'));
  cancelButtons.forEach(button => {
    button.addEventListener('click', () => choose('cancel'));
  });

  // Only resolve after the first modal fully closes, avoiding overlapping
  // Bootstrap backdrops and focus traps when the PDF editor opens.
  element.addEventListener('hidden.bs.modal', () => {
    const pending = active;
    if (!pending) return;
    active = null;
    if (pending.action === 'original') {
      pending.resolve(pending.file);
    } else if (pending.action === 'organize') {
      if (typeof window.FPOMSOrganizePdf !== 'function') {
        pending.reject(new Error('PDF Page Organizer is unavailable. Refresh and try again.'));
        return;
      }
      Promise.resolve()
        .then(() => window.FPOMSOrganizePdf(pending.file,pending.maxBytes,pending.mode))
        .then(pending.resolve,pending.reject);
    } else {
      pending.resolve(null);
    }
  });

  /**
   * Ask before showing the editor. Returns the original File, an edited File,
   * or null when cancelled. The caller alone performs secure upload/staging.
   */
  window.FPOMSChoosePdfUpload = (file,maxBytes,mode='saved') => {
    if (active) return Promise.reject(new Error('Finish the current PDF choice first.'));
    if (!window.bootstrap?.Modal) {
      return Promise.reject(new Error('PDF upload confirmation is unavailable. Refresh and try again.'));
    }
    if (!file || !/\.pdf$/i.test(file.name || '')) {
      return Promise.reject(new Error('Only PDF files can use the PDF upload confirmation.'));
    }
    const modal = window.bootstrap.Modal.getOrCreateInstance(element,{
      backdrop:'static',keyboard:false,focus:true
    });
    nameLabel.textContent = file.name;
    const staging = mode === 'saved';
    noButton.textContent = staging ? 'No, Upload Original PDF' : 'No, Use Original PDF';
    modeNote.textContent = staging
      ? 'Choosing No saves the original PDF without changing its pages.'
      : 'Choosing No keeps the original PDF selected. It uploads only when you submit the application.';
    return new Promise((resolve,reject) => {
      active = {file,maxBytes,mode,modal,resolve,reject,action:null};
      try {
        modal.show();
      } catch (error) {
        active = null;
        reject(error);
      }
    });
  };
})();
