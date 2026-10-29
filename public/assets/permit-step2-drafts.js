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
})();
