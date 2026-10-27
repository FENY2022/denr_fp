(() => {
  'use strict';
  const modal = document.getElementById('registration-document-modal');
  if (!modal || !window.bootstrap?.Modal) return;
  const area = document.getElementById('registration-document-preview');
  const title = document.getElementById('registration-document-modal-title');
  const download = document.getElementById('registration-document-download');
  const error = document.getElementById('registration-document-error');
  let scale = 1;
  let media = null;
  document.querySelectorAll('[data-document-preview]').forEach(link => {
    link.addEventListener('click', event => {
      event.preventDefault();
      const mime = link.dataset.documentMime;
      if (!['application/pdf', 'image/jpeg', 'image/png'].includes(mime)) return;
      area.replaceChildren();
      error.classList.add('d-none');
      scale = 1;
      title.textContent = link.dataset.documentName || 'Document Preview';
      const previewUrl = link.href;
      const url = new URL(previewUrl, location.href);
      url.searchParams.delete('preview');
      download.href = url.href;
      media = document.createElement(mime === 'application/pdf' ? 'iframe' : 'img');
      media.src = previewUrl;
      media.setAttribute('aria-label', title.textContent);
      if (mime === 'application/pdf') {
        media.title = title.textContent;
        media.style.width = '100%';
        media.style.height = '65vh';
        media.style.border = '0';
      } else {
        media.alt = title.textContent;
        media.style.maxWidth = '100%';
        media.style.height = 'auto';
      }
      media.addEventListener('error', () => error.classList.remove('d-none'));
      area.appendChild(media);
      window.bootstrap.Modal.getOrCreateInstance(modal).show();
    });
  });
  modal.querySelectorAll('[data-document-zoom]').forEach(button => {
    button.addEventListener('click', () => {
      if (!media) return;
      const action = button.dataset.documentZoom;
      scale = action === 'reset' ? 1 : Math.min(3, Math.max(0.5, scale + (action === 'in' ? 0.25 : -0.25)));
      if (media.tagName === 'IMG') {
        media.style.maxWidth = 'none';
        media.style.width = (scale * 100) + '%';
      } else {
        media.style.width = (scale * 100) + '%';
      }
    });
  });
  modal.addEventListener('hidden.bs.modal', () => { area.replaceChildren(); media = null; });
})();
