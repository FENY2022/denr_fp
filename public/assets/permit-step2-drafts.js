(() => {
  'use strict';
  const root = document.getElementById('step2-draft-uploader');
  if (!root) return;
  const form = root.closest('form');
  const input = root.querySelector('#application_files');
  const list = root.querySelector('[data-step2-items]');
  const queue = root.querySelector('[data-step2-queue]');
  const count = root.querySelector('[data-step2-count]');
  const empty = root.querySelector('[data-step2-empty]');
  const feedback = root.querySelector('[data-step2-feedback]');
  const submit = form.querySelector('button[type="submit"]');
  const csrf = form.querySelector('input[name="csrf_token"]')?.value || '';
  const scope = root.dataset.applicationId || '0';
  const maxBytes = Number(root.dataset.maxBytes || 5242880);
  let busy = false;

  // Remove the no-JS notice only after the handlers have initialized.
  const jsWarning = root.querySelector('[data-step2-js-warning]');

  // Compatibility mode: migration 015 has not been installed. Keep native
  // form submission and make its selected files visible without calling them saved.
  if (root.dataset.step2Mode === 'direct') {
    let selected = Array.from(input.files || []);
    const valid = file => file.size > 0 && file.size <= maxBytes
      && /\.(pdf|jpe?g|png)$/i.test(file.name);

    function directNotice(value, isError = false) {
      feedback.textContent = value;
      feedback.classList.toggle('text-danger', isError);
      feedback.classList.toggle('text-secondary', !isError);
    }
    function syncNativeFiles() {
      const transfer = new DataTransfer();
      for (const file of selected) transfer.items.add(file);
      input.files = transfer.files;
    }
    function drawDirectFiles() {
      list.replaceChildren();
      for (const [index, file] of selected.entries()) {
        const item = document.createElement('li');
        item.className = 'selected-file-card is-pending';
        const icon = document.createElement('span');
        icon.className = 'selected-file-icon';
        icon.setAttribute('aria-hidden', 'true');
        const glyph = document.createElement('i');
        glyph.className = 'bi bi-file-earmark-text-fill';
        icon.append(glyph);
        const info = document.createElement('div');
        info.className = 'selected-file-info';
        const name = document.createElement('strong');
        name.className = 'selected-file-name';
        name.textContent = file.name;
        const status = document.createElement('span');
        status.className = 'selected-file-status';
        status.textContent = 'Selected — uploads when you submit · Not saved yet';
        info.append(name,status);
        const remove = document.createElement('button');
        remove.className = 'selected-file-remove';
        remove.type = 'button';
        remove.dataset.step2LocalIndex = String(index);
        remove.setAttribute('aria-label','Remove selected file: '+file.name);
        remove.title = 'Remove file';
        const x = document.createElement('i');
        x.className = 'bi bi-x-lg';
        x.setAttribute('aria-hidden', 'true');
        remove.append(x);
        const progress = document.createElement('progress');
        progress.className = 'selected-file-progress';
        progress.max = 100;
        progress.value = 0;
        progress.setAttribute('aria-label', 'Not yet uploaded');
        item.append(icon,info,remove,progress);
        list.append(item);
      }
      count.textContent = String(selected.length);
      empty.hidden = selected.length > 0;
      input.disabled = false;
    }
    input.addEventListener('change', () => {
      const newlyPicked = Array.from(input.files || []);
      // Preserve earlier picks when the user opens the chooser again.
      // Repeated identical picks are kept as the user explicitly selected them.
      const room = Math.max(0,10-selected.length);
      let rejected = 0;
      for (const file of newlyPicked) {
        if (!valid(file) || selected.length >= 10) { rejected++; continue; }
        selected.push(file);
      }
      try {
        syncNativeFiles();
      } catch (error) {
        // Without DataTransfer support, use the native picker contents as-is.
        selected = newlyPicked;
        directNotice('This browser cannot edit the file selection. Re-select files to change them.', true);
      }
      drawDirectFiles();
      if (rejected) directNotice('Some files were not added: maximum 10, PDF/JPG/PNG, size limit applies.',true);
      else directNotice('Selected files are not saved yet. They upload only on final submission.');
    });
    list.addEventListener('click', event => {
      const button = event.target.closest('[data-step2-local-index]');
      if (!button) return;
      const index = Number(button.dataset.step2LocalIndex);
      if (!Number.isSafeInteger(index) || index < 0 || index >= selected.length) return;
      const next = selected.filter((_,i)=>i!==index);
      const previous = selected;
      selected = next;
      try {
        syncNativeFiles();
        drawDirectFiles();
        directNotice('File removed from this submission.');
      } catch (error) {
        selected = previous;
        directNotice('Cannot remove a single file in this browser. Choose files again.',true);
      }
    });
    form.addEventListener('submit', event => {
      if (selected.length > 10 || selected.some(file => !valid(file))
          || (scope === '0' && selected.length === 0)) {
        event.preventDefault();
        directNotice('Select at least one valid supporting document (maximum 10).',true);
      }
    });
    drawDirectFiles();
    if (jsWarning) jsWarning.hidden = true;
    return;
  }

  function notice(value, isError = false) {
    feedback.textContent = value;
    feedback.classList.toggle('text-danger', isError);
    feedback.classList.toggle('text-secondary', !isError);
  }
  function savedCount() { return list.querySelectorAll('[data-step2-draft-id]').length; }
  function refresh() {
    const n = savedCount();
    count.textContent = String(n);
    empty.hidden = n !== 0 || queue.children.length > 0;
    input.disabled = busy || n >= 10;
    submit.disabled = busy;
  }
  function card(name, id = null) {
    const li = document.createElement('li');
    li.className = 'selected-file-card ' + (id === null ? 'is-uploading' : 'is-saved');
    if (id !== null) li.dataset.step2DraftId = String(id);
    const icon = document.createElement('span');
    icon.className = 'selected-file-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = '<i class="bi bi-file-earmark-text-fill"></i>';
    const info = document.createElement('div');
    info.className = 'selected-file-info';
    const filename = document.createElement('strong');
    filename.className = 'selected-file-name';
    filename.textContent = name;
    const state = document.createElement('span');
    state.className = 'selected-file-status';
    state.textContent = id === null ? 'Preparing upload…' : 'Saved — ready for submission · Supporting Document';
    info.append(filename, state);
    const remove = document.createElement('button');
    remove.className = 'selected-file-remove';
    remove.type = 'button';
    remove.setAttribute('aria-label','Remove saved attachment: '+name);
    remove.title = 'Remove file';
    remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
    if (id === null) remove.disabled = true;
    else remove.dataset.step2Remove = '';
    const progress = document.createElement('progress');
    progress.className = 'selected-file-progress';
    progress.max = 100;
    progress.value = id === null ? 2 : 100;
    li.append(icon,info,remove,progress);
    return {li,state,progress};
  }
  function render(docs) {
    list.replaceChildren();
    for (const doc of docs || []) list.append(card(String(doc.original_name),doc.id).li);
    refresh();
  }
  function post(url, data, progress) {
    return new Promise((resolve,reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST',url,true);
      xhr.responseType = 'json';
      xhr.upload.onprogress = event => {
        if (progress && event.lengthComputable) {
          progress(Math.round(event.loaded * 100 / event.total));
        }
      };
      xhr.onload = () => {
        const body = xhr.response;
        if (xhr.status >= 200 && xhr.status < 300 && body && !body.error) resolve(body);
        else reject(new Error(body?.error || 'Request failed. Refresh the page and try again.'));
      };
      xhr.onerror = () => reject(new Error('Network error. Try again.'));
      xhr.send(data);
    });
  }
  input.addEventListener('change', async () => {
    const files = Array.from(input.files || []);
    input.value = '';
    const free = Math.max(0,10 - savedCount());
    if (files.length > free) notice('Only '+free+' more files can be saved.',true);
    busy = true;
    refresh();
    for (const file of files.slice(0,free)) {
      if (file.size < 1 || file.size > maxBytes || !/\.(pdf|jpe?g|png)$/i.test(file.name)) {
        notice('Invalid file or file size. PDF/JPG/PNG only, up to the stated limit.',true);
        continue;
      }
      const item = card(file.name);
      queue.append(item.li);
      refresh();
      const data = new FormData();
      data.set('csrf_token',csrf);
      data.set('application_id',scope);
      data.set('attachment',file);
      try {
        const response = await post('permit-draft-upload',data,pct => {
          item.progress.value = Math.max(2,pct);
          item.state.textContent = pct < 100 ? 'Uploading… '+pct+'%' : 'Finishing secure upload…';
        });
        render(response.documents);
        notice('Supporting document saved securely. Available after page refresh.');
      } catch (error) {
        notice(error.message || 'File could not be saved.',true);
      } finally {
        item.li.remove();
        refresh();
      }
    }
    busy = false;
    refresh();
  });
  list.addEventListener('click', async event => {
    const button = event.target.closest('[data-step2-remove]');
    const item = button?.closest('[data-step2-draft-id]');
    if (!item || busy) return;
    busy = true;
    refresh();
    const data = new FormData();
    data.set('csrf_token',csrf);
    data.set('application_id',scope);
    data.set('id',item.dataset.step2DraftId);
    try {
      const response = await post('permit-draft-delete',data);
      render(response.documents);
      notice('Saved attachment removed.');
    } catch (error) {
      notice(error.message || 'Could not remove file.',true);
    } finally {
      busy = false;
      refresh();
    }
  });
  form.addEventListener('submit', event => {
    if (busy || (scope === '0' && savedCount() === 0)) {
      event.preventDefault();
      notice(busy ? 'Finish file uploads before submitting.'
        : 'Upload at least one FP supporting document.',true);
    }
  });
  refresh();
  if (jsWarning) jsWarning.hidden = true;
})();
