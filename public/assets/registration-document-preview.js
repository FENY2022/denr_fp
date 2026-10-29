(() => {
  'use strict';

  const modal = document.getElementById('registration-document-modal');
  if (!modal || !window.bootstrap?.Modal) return;

  const area = document.getElementById('registration-document-preview');
  const title = document.getElementById('registration-document-modal-title');
  const download = document.getElementById('registration-document-download');
  const error = document.getElementById('registration-document-error');
  const previous = modal.querySelector('[data-document-previous]');
  const next = modal.querySelector('[data-document-next]');
  const counter = modal.querySelector('[data-document-counter]');
  if (!area || !title || !download || !error) return;

  // The current page renders one registration at a time. Only its submitted,
  // authorized document links are used; no extra documents are fetched here.
  const documents = [...document.querySelectorAll('[data-document-preview]')]
    .filter(link => ['application/pdf', 'image/jpeg', 'image/png']
      .includes(link.dataset.documentMime));

  let currentIndex = -1;
  let scale = 1;
  let media = null;
  const zoomClasses = Array.from(
    { length: 11 }, (_, i) => 'registration-document-zoom-' + (50 + i * 25)
  );

  function navigationState() {
    if (previous) previous.disabled = currentIndex <= 0;
    if (next) next.disabled = currentIndex < 0 || currentIndex >= documents.length - 1;
    if (counter) {
      counter.textContent = currentIndex < 0
        ? '0 / ' + documents.length
        : (currentIndex + 1) + ' / ' + documents.length;
    }
  }

  function resetZoom() {
    scale = 1;
    if (!media) return;
    for (const className of zoomClasses) media.classList.remove(className);
    media.classList.add('registration-document-zoom-100');
  }

  function viewDocument(index, openModal = false) {
    if (!Number.isInteger(index) || index < 0 || index >= documents.length) return;
    const link = documents[index];
    const mime = link.dataset.documentMime;
    if (!['application/pdf', 'image/jpeg', 'image/png'].includes(mime)) return;

    currentIndex = index;
    title.textContent = link.dataset.documentName || 'Document Preview';

    const url = new URL(link.href, location.href);
    url.searchParams.delete('preview');
    download.href = url.href;
    download.setAttribute('aria-label', 'Download ' + title.textContent);

    error.classList.add('d-none');
    media = document.createElement(mime === 'application/pdf' ? 'iframe' : 'img');
    media.classList.add('registration-document-preview-media');
    media.classList.add(mime === 'application/pdf'
      ? 'registration-document-preview-pdf' : 'registration-document-preview-image');
    media.classList.add('registration-document-zoom-100');
    media.setAttribute('aria-label', title.textContent);
    if (mime === 'application/pdf') {
      media.title = title.textContent;
    } else {
      media.alt = title.textContent;
    }
    media.addEventListener('error', () => error.classList.remove('d-none'));
    media.src = link.href;

    // Replacing the previous iframe/image releases its viewer on navigation.
    area.replaceChildren(media);
    resetZoom();
    navigationState();
    if (openModal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
  }

  for (const [index, link] of documents.entries()) {
    link.addEventListener('click', event => {
      event.preventDefault();
      viewDocument(index, true);
    });
  }

  previous?.addEventListener('click', () => viewDocument(currentIndex - 1));
  next?.addEventListener('click', () => viewDocument(currentIndex + 1));

  modal.querySelectorAll('[data-document-zoom]').forEach(button => {
    button.addEventListener('click', () => {
      if (!media) return;
      const action = button.dataset.documentZoom;
      scale = action === 'reset' ? 1
        : Math.min(3, Math.max(0.5, scale + (action === 'in' ? 0.25 : -0.25)));
      for (const className of zoomClasses) media.classList.remove(className);
      media.classList.add('registration-document-zoom-' + Math.round(scale * 100));
    });
  });

  modal.addEventListener('hidden.bs.modal', () => {
    area.replaceChildren();
    media = null;
    currentIndex = -1;
    scale = 1;
    navigationState();
  });

  navigationState();
})();
