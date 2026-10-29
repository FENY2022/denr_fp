(() => {
  'use strict';
  const modalElement = document.getElementById('fp-pdf-organizer-modal');
  if (!modalElement) return;

  const grid = modalElement.querySelector('[data-pdf-page-grid]');
  const preview = modalElement.querySelector('[data-pdf-preview]');
  const counter = modalElement.querySelector('[data-pdf-page-count]');
  const filename = modalElement.querySelector('[data-pdf-file-name]');
  const status = modalElement.querySelector('[data-pdf-status]');
  const saveButton = modalElement.querySelector('[data-pdf-save]');
  const resetButton = modalElement.querySelector('[data-pdf-reset]');
  const cancelButtons = Array.from(modalElement.querySelectorAll('[data-pdf-cancel]'));
  const maxPages = 160;
  let session = null;
  let pdfjsPromise = null;

  const setStatus = (message, isError = false) => {
    status.textContent = message;
    status.classList.toggle('text-danger', isError);
    status.classList.toggle('text-secondary', !isError);
  };
  const refreshControls = () => {
    if (!session) return;
    const count = session.order.length;
    counter.textContent = count + (count === 1 ? ' page' : ' pages');
    saveButton.disabled = session.working || count === 0 || !session.document;
    resetButton.disabled = session.working || !session.document;
    cancelButtons.forEach(button => { button.disabled = session.working; });
    grid.querySelectorAll('button').forEach(button => {
      button.disabled = session.working || button.dataset.pdfAction === 'remove' && count === 1;
    });
  };
  const makeButton = (action, icon, label) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-sm btn-outline-secondary fp-pdf-page-action';
    button.dataset.pdfAction = action;
    button.title = label;
    button.setAttribute('aria-label', label);
    const symbol = document.createElement('i');
    symbol.className = 'bi bi-' + icon;
    symbol.setAttribute('aria-hidden','true');
    button.append(symbol);
    return button;
  };

  function showPages() {
    if (!session || !session.document) return;
    const state = session;
    const cards = document.createDocumentFragment();
    state.order.forEach((original, index) => {
      const tile = document.createElement('article');
      tile.className = 'fp-pdf-page' + (index === state.selected ? ' is-selected' : '');
      tile.dataset.pdfIndex = String(index);
      tile.draggable = !state.working;
      tile.tabIndex = 0;
      tile.setAttribute('aria-label','Position ' + (index + 1) + ', original page ' + (original + 1));
      const frame = document.createElement('div');
      frame.className = 'fp-pdf-page-thumbnail';
      frame.dataset.pdfOrigin = String(original);
      const thumb = state.thumbnails.get(original);
      if (thumb) {
        const image = document.createElement('img');
        image.src = thumb;
        image.alt = 'Original page ' + (original + 1);
        image.loading = 'lazy';
        frame.append(image);
      } else {
        const placeholder = document.createElement('span');
        placeholder.textContent = 'Page ' + (original + 1);
        frame.append(placeholder);
      }
      const label = document.createElement('div');
      label.className = 'fp-pdf-page-label';
      label.textContent = 'Position ' + (index + 1) + ' · Page ' + (original + 1);
      const actions = document.createElement('div');
      actions.className = 'fp-pdf-page-actions';
      const up = makeButton('up','arrow-left','Move page left');
      const down = makeButton('down','arrow-right','Move page right');
      up.disabled = state.working || index === 0;
      down.disabled = state.working || index === state.order.length - 1;
      actions.append(
        up, down,
        makeButton('duplicate','copy','Duplicate this page'),
        makeButton('remove','trash','Remove this page'),
        makeButton('preview','eye','Preview this page')
      );
      tile.append(frame,label,actions);
      cards.append(tile);
    });
    grid.replaceChildren(cards);
    refreshControls();
    updatePreview();
    refreshThumbnails();
  }

  async function pdfjs() {
    if (!pdfjsPromise) {
      pdfjsPromise = import('https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.min.mjs')
        .then(library => {
          library.GlobalWorkerOptions.workerSrc =
            'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.worker.min.mjs';
          return library;
        });
    }
    return pdfjsPromise;
  }
  async function renderPdfPage(document, originalIndex, canvas, maxWidth) {
    const page = await document.getPage(originalIndex + 1);
    const base = page.getViewport({scale:1});
    const viewport = page.getViewport({scale:Math.min(1.8, maxWidth / Math.max(base.width,1))});
    canvas.width = Math.max(1,Math.round(viewport.width));
    canvas.height = Math.max(1,Math.round(viewport.height));
    const context = canvas.getContext('2d',{alpha:false});
    if (!context) throw new Error('Canvas rendering is unavailable.');
    await page.render({canvasContext:context,viewport}).promise;
  }
  async function refreshThumbnails() {
    const state = session;
    if (!state || !state.reader) return;
    const unique = [...new Set(state.order)].slice(0,80);
    for (const origin of unique) {
      if (session !== state) return;
      if (state.thumbnails.has(origin)) continue;
      try {
        const canvas = document.createElement('canvas');
        await renderPdfPage(state.reader,origin,canvas,120);
        if (session !== state) return;
        state.thumbnails.set(origin,canvas.toDataURL('image/png'));
        grid.querySelectorAll('[data-pdf-origin="' + origin + '"]').forEach(frame => {
          const image = document.createElement('img');
          image.src = state.thumbnails.get(origin);
          image.alt = 'Original page ' + (origin + 1);
          image.loading = 'lazy';
          frame.replaceChildren(image);
        });
      } catch (error) {
        setStatus('Some thumbnails are unavailable. Page order controls still work.',true);
        break;
      }
    }
  }
  async function updatePreview() {
    const state = session;
    if (!state || !state.document) return;
    const origin = state.order[state.selected];
    if (origin === undefined) return;
    const stamp = ++state.previewStamp;
    const heading = document.createElement('p');
    heading.className = 'small fw-semibold mb-2';
    heading.textContent = 'Position ' + (state.selected + 1) + ' · Original page ' + (origin + 1);
    preview.replaceChildren(heading);
    if (!state.reader) {
      const message = document.createElement('p');
      message.textContent = 'PDF preview is not available; page controls can still be used.';
      preview.append(message);
      return;
    }
    try {
      const canvas = document.createElement('canvas');
      await renderPdfPage(state.reader,origin,canvas,450);
      if (session === state && stamp === state.previewStamp) preview.replaceChildren(heading,canvas);
    } catch (error) {
      if (session === state) setStatus('Preview unavailable; the pages can still be arranged.',true);
    }
  }

  function move(from,to) {
    if (!session || session.working || from === to ||
        from < 0 || to < 0 || from >= session.order.length || to >= session.order.length) return;
    const [page] = session.order.splice(from,1);
    session.order.splice(to,0,page);
    session.selected = to;
    showPages();
  }
  grid.addEventListener('click', event => {
    if (!session || session.working || !session.document) return;
    const tile = event.target.closest('[data-pdf-index]');
    if (!tile) return;
    const index = Number(tile.dataset.pdfIndex);
    const action = event.target.closest('[data-pdf-action]')?.dataset.pdfAction;
    if (action === 'up') return move(index,index - 1);
    if (action === 'down') return move(index,index + 1);
    if (action === 'duplicate') {
      if (session.order.length >= maxPages) {
        setStatus('Maximum ' + maxPages + ' arranged pages. Remove pages to continue.',true);
        return;
      }
      session.order.splice(index + 1,0,session.order[index]);
      session.selected = index + 1;
    } else if (action === 'remove') {
      if (session.order.length === 1) {
        setStatus('At least one page must remain in the PDF.',true);
        return;
      }
      session.order.splice(index,1);
      session.selected = Math.min(index,session.order.length - 1);
    } else {
      session.selected = index;
    }
    showPages();
  });
  grid.addEventListener('keydown', event => {
    if (event.target.closest('button') || !['Enter',' '].includes(event.key)) return;
    const tile = event.target.closest('[data-pdf-index]');
    if (!tile) return;
    event.preventDefault();
    session.selected = Number(tile.dataset.pdfIndex);
    showPages();
  });
  let dragging = null;
  grid.addEventListener('dragstart',event => {
    const tile = event.target.closest('[data-pdf-index]');
    if (!tile || !session || session.working) return;
    dragging = Number(tile.dataset.pdfIndex);
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain',String(dragging));
  });
  grid.addEventListener('dragover',event => {
    if (dragging === null || !event.target.closest('[data-pdf-index]')) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
  });
  grid.addEventListener('drop',event => {
    const tile = event.target.closest('[data-pdf-index]');
    if (tile && dragging !== null) {
      event.preventDefault();
      move(dragging,Number(tile.dataset.pdfIndex));
    }
    dragging = null;
  });
  grid.addEventListener('dragend',() => { dragging = null; });
  resetButton.addEventListener('click',() => {
    if (!session || session.working) return;
    session.order = Array.from({length:session.document.getPageCount()},(_,i) => i);
    session.selected = 0;
    setStatus('Original page order restored.');
    showPages();
  });

  async function save() {
    const state = session;
    if (!state || state.working || !state.document || !state.order.length) return;
    state.working = true;
    refreshControls();
    setStatus('Generating your arranged PDF in this browser…');
    try {
      const unchanged = state.order.length === state.document.getPageCount()
        && state.order.every((n,i) => n === i);
      let output = state.file;
      if (!unchanged) {
        const created = await window.PDFLib.PDFDocument.create();
        const pages = await created.copyPages(state.document,state.order);
        for (const page of pages) created.addPage(page);
        const bytes = await created.save();
        output = new File([bytes],state.file.name,{type:'application/pdf',lastModified:Date.now()});
      }
      if (output.size > state.maxBytes) {
        throw new Error('Organized PDF is too large (' +
          (output.size/1048576).toFixed(2) + ' MB). Remove pages or duplicates to meet the ' +
          (state.maxBytes/1048576).toFixed(1) + ' MB limit.');
      }
      if (session !== state) return;
      state.done = true;
      state.result = output;
      state.modal.hide();
    } catch (error) {
      if (session === state) {
        setStatus(error.message || 'Could not create the organized PDF.',true);
        state.working = false;
        refreshControls();
      }
    }
  }
  saveButton.addEventListener('click',save);
  cancelButtons.forEach(button => button.addEventListener('click',() => {
    if (session && !session.working) {
      session.done = true;
      session.result = null;
      session.modal.hide();
    }
  }));
  modalElement.addEventListener('hidden.bs.modal',() => {
    const state = session;
    if (!state) return;
    session = null;
    if (state.reader) state.reader.destroy().catch(() => {});
    preview.replaceChildren();
    grid.replaceChildren();
    state.finish(state.result || null);
  });

  /** Return an approved File, or null when the user cancels. No uploads occur here. */
  window.FPOMSOrganizePdf = async function(file,maxBytes,uploadMode = 'saved') {
    if (session) throw new Error('The PDF organizer is already open.');
    if (!window.bootstrap?.Modal) throw new Error('Bootstrap PDF organizer dialog is unavailable.');
    const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement,{
      backdrop:'static',keyboard:false,focus:true
    });
    return new Promise(resolve => {
      session = {
        file,maxBytes,modal,finish:resolve,done:false,result:null,working:false,
        document:null,reader:null,order:[],selected:0,previewStamp:0,thumbnails:new Map()
      };
      filename.textContent = file.name;
      const saveLabel = saveButton.querySelector('[data-pdf-save-label]');
      if (saveLabel) {
        saveLabel.textContent = uploadMode === 'saved'
          ? 'Save & Upload PDF' : 'Apply PDF Changes';
      }
      counter.textContent = 'Opening PDF…';
      grid.replaceChildren();
      preview.replaceChildren();
      refreshControls();
      setStatus('Reading PDF locally. No file is uploaded yet.');
      modal.show();
      (async () => {
        try {
          if (!window.PDFLib?.PDFDocument) {
            throw new Error('PDF editor library did not load. Check your internet connection and try again.');
          }
          const state = session;
          const bytes = await file.arrayBuffer();
          const source = await window.PDFLib.PDFDocument.load(bytes);
          if (session !== state) return;
          if (!source.getPageCount() || source.getPageCount() > 120) {
            throw new Error('PDF organizer supports 1–120 source pages per file.');
          }
          state.document = source;
          state.order = Array.from({length:source.getPageCount()},(_,i) => i);
          state.working = false;
          showPages();
          setStatus('Drag page cards, use arrows, duplicate or remove pages. Save when ready.');
          try {
            const library = await pdfjs();
            if (session !== state) return;
            state.reader = await library.getDocument({
              data: new Uint8Array(bytes.slice(0)),isEvalSupported:false,
              useSystemFonts:true
            }).promise;
            if (session === state) {
              refreshThumbnails();
              updatePreview();
            } else {
              state.reader.destroy().catch(()=>{});
            }
          } catch (error) {
            if (session === state) {
              setStatus('Thumbnails unavailable (PDF.js could not load). Page editing still works.',true);
            }
          }
        } catch (error) {
          if (!session) return;
          session.working = false;
          session.document = null;
          setStatus(error.message || 'Unable to open this PDF. Try another file.',true);
          refreshControls();
        }
      })();
    });
  };
})();
