(() => {
  'use strict';

  const root = document.getElementById('registration-attachments');
  if (!root) return;

  const form = root.closest('form');
  const draftMode = root.dataset.draftMode === '1';
  // Both file groups have their own visible Selected Files list underneath.
  const savedLists = {
    id: draftMode ? root.querySelector('#registration-draft-list') : null,
    supporting: draftMode ? root.querySelector('#registration-supporting-draft-list') : null
  };
  const csrf = form?.querySelector('input[name="csrf_token"]')?.value || '';
  function savedListFor(kind) { return savedLists[kind] || null; }
  function uploadQueueFor(kind) { return savedListFor(kind)?.querySelector('[data-upload-queue]') || null; }
  const containers = {
    id: root.querySelector('#valid-id-rows'),
    supporting: root.querySelector('#supporting-document-rows')
  };
  const addButtons = {
    id: root.querySelector('[data-add-id]'),
    supporting: root.querySelector('[data-add-supporting]')
  };
  const limit = {
    id: Number(root.dataset.maxIds || 5),
    supporting: Number(root.dataset.maxSupporting || 5)
  };
  const maxBytes = Number(root.dataset.maxFileBytes || 5242880);
  const requireIds = root.dataset.requiredIds === '1';
  const feedback = root.querySelector('#attachment-feedback');
  const allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
  let pendingUploads = 0;

  if (root.dataset.multipleSupporting === '1') {
    root.querySelectorAll('[data-supporting-files]').forEach(input => { input.multiple = true; });
  }

  function rows(kind) {
    return [...(containers[kind]?.querySelectorAll('[data-attachment-row]') || [])];
  }

  function savedCount(kind) {
    const list = savedListFor(kind);
    return list ? list.querySelectorAll('[data-draft-document]').length : 0;
  }

  function setFeedback(message, invalid = false) {
    if (!feedback) return;
    feedback.textContent = message;
    feedback.classList.toggle('text-danger', invalid);
    feedback.classList.toggle('text-secondary', !invalid);
  }

  function revealProblem(target) {
    if (!(target instanceof HTMLElement)) return;
    form?.dispatchEvent(new CustomEvent('registration:reveal-field', {detail:{target}}));
    if (target.matches('input,select,textarea')) target.classList.add('is-invalid');
    if (typeof target.focus === 'function') target.focus({preventScroll:true});
    target.scrollIntoView({behavior:'smooth',block:'center'});
  }

  form?.addEventListener('input', event => event.target.classList?.remove('is-invalid'));
  form?.addEventListener('change', event => event.target.classList?.remove('is-invalid'));

  function checkFile(input, selectedFile = null) {
    const files = [...(input.files || [])];
    const file = selectedFile || files[0];
    const status = input.closest('[data-attachment-row]')?.querySelector('[data-file-name]');
    input.setCustomValidity('');
    if (!file) {
      if (status) status.textContent = 'No file selected';
      return;
    }
    if (status) status.textContent = files.length > 1 && !selectedFile
      ? files.length + ' files selected'
      : file.name + ' (' + (file.size / 1048576).toFixed(2) + ' MB)';
    const extension = file.name.split('.').pop()?.toLowerCase();
    if (!allowedExtensions.includes(extension)) {
      input.setCustomValidity('Only PDF, JPG and PNG files are allowed.');
    } else if (file.size < 1 || file.size > maxBytes) {
      input.setCustomValidity('Each file must be nonempty and at most ' +
        (maxBytes / 1048576).toFixed(1) + ' MB.');
    }
  }

  function refresh() {
    for (const kind of ['id', 'supporting']) {
      const items = rows(kind);
      items.forEach((row, index) => {
        const title = row.querySelector('[data-row-title]');
        if (title) title.textContent = kind === 'id'
          ? 'Valid ID ' + (index + 1) : 'Supporting Document ' + (index + 1);
        const remove = row.querySelector('[data-remove-attachment]');
        if (remove) remove.classList.toggle('d-none', items.length === 1);
        const file = row.querySelector('[data-attachment-file]');
        if (file) checkFile(file);
        const full = draftMode && savedCount(kind) >= limit[kind];
        const uploading = row.dataset.uploading === '1';
        if (file) file.disabled = full || uploading;

        if (kind === 'supporting') {
          const category = row.querySelector('[data-supporting-category]');
          if (category) category.disabled = full || uploading;
        }
        if (kind === 'id') {
          const idType = row.querySelector('[data-id-type]');
          if (!idType || !file) return;
          const started = idType.value !== '' || Boolean(file.files?.length);
          idType.required = !draftMode && (requireIds || started);
          file.required = !draftMode && (requireIds || started);
          idType.disabled = full || uploading;
        }
      });
      if (addButtons[kind]) {
        addButtons[kind].disabled = items.length >= limit[kind]
          || (draftMode && savedCount(kind) >= limit[kind]);
      }
    }
  }

  function addRow(kind) {
    const parent = containers[kind];
    const items = rows(kind);
    if (!parent || items.length >= limit[kind] || items.length === 0
        || (draftMode && savedCount(kind) >= limit[kind])) return;
    const index = Math.max(...items.map(row => Number(row.dataset.rowIndex) || 0)) + 1;
    const row = items[0].cloneNode(true);
    row.dataset.rowIndex = String(index);
    delete row.dataset.uploading;
    row._waitingIdCard = null;
    row.querySelectorAll('[id]').forEach(field => {
      field.id = field.id.replace(/_\d+$/, '_' + index);
    });
    row.querySelectorAll('label[for]').forEach(label => {
      label.htmlFor = label.htmlFor.replace(/_\d+$/, '_' + index);
    });
    row.querySelectorAll('[name]').forEach(field => {
      field.name = field.name.replace(/\[\d+\]$/, '[' + index + ']');
      if ('value' in field) field.value = '';
      if (field instanceof HTMLInputElement && field.type === 'file') field.setCustomValidity('');
    });
    const status = row.querySelector('[data-file-name]');
    if (status) status.textContent = 'No file selected';
    parent.appendChild(row);
    refresh();
    row.querySelector('select,input')?.focus();
  }

  function selectedFileCard({ id = null, name, type = '', status = '', percent = 0, isSaved = false, editable = false }) {
    const card = document.createElement('li');
    card.className = 'selected-file-card ' + (isSaved ? 'is-saved' : 'is-uploading');
    if (id !== null) {
      card.dataset.draftDocument = '';
      card.dataset.draftId = String(id);
      card.dataset.draftType = type;
    }
    const icon = document.createElement('span');
    icon.className = 'selected-file-icon';
    icon.setAttribute('aria-hidden', 'true');
    const iconGlyph = document.createElement('i');
    iconGlyph.className = 'bi bi-file-earmark-text-fill';
    icon.append(iconGlyph);

    const text = document.createElement('div');
    text.className = 'selected-file-info';
    let filename;
    if (editable) {
      const nameRow = document.createElement('div');
      nameRow.className = 'd-flex align-items-center gap-2 flex-wrap';
      filename = document.createElement('input');
      filename.type = 'text';
      filename.className = 'form-control form-control-sm selected-file-name flex-grow-1';
      filename.value = name;
      filename.maxLength = 220;
      filename.dataset.draftName = '';
      filename.setAttribute('aria-label', 'Supporting document file name');
      const saveName = document.createElement('button');
      saveName.type = 'button';
      saveName.className = 'btn btn-sm btn-outline-secondary';
      saveName.dataset.renameDraft = '';
      saveName.textContent = 'Save name';
      nameRow.append(filename, saveName);
      text.append(nameRow);
    } else {
      filename = document.createElement('strong');
      filename.className = 'selected-file-name';
      filename.textContent = name;
      text.append(filename);
    }
    const message = document.createElement('span');
    message.className = 'selected-file-status';
    message.textContent = status;
    text.append(message);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'selected-file-remove';
    close.setAttribute('aria-label', 'Remove saved attachment: ' + name);
    close.title = 'Remove file';
    const closeGlyph = document.createElement('i');
    closeGlyph.className = 'bi bi-x-lg';
    closeGlyph.setAttribute('aria-hidden', 'true');
    close.append(closeGlyph);
    if (isSaved) close.dataset.removeDraft = '';
    else close.disabled = true; // Avoid claiming an in-flight HTTP upload was canceled.
    // Native <progress> updates via its value property, not blocked inline CSS.
    const progress = document.createElement('progress');
    progress.className = 'selected-file-progress';
    progress.max = 100;
    progress.value = Math.min(100, Math.max(0, percent));
    progress.setAttribute('aria-label', 'File upload progress');
    card.append(icon, text, close, progress);
    return { card, message, bar: progress };
  }

  function clearWaitingForIdType(row) {
    if (row._waitingIdCard) row._waitingIdCard.remove();
    row._waitingIdCard = null;
  }

  function showWaitingForIdType(row, file) {
    const uploadQueue = uploadQueueFor('id');
    if (!uploadQueue) return;
    clearWaitingForIdType(row);
    const waiting = selectedFileCard({
      name: file.name,
      status: 'Waiting for ID Type — select an ID Type above to upload',
      percent: 0
    });
    waiting.card.classList.add('is-waiting');
    const remove = waiting.card.querySelector('.selected-file-remove');
    if (remove) {
      remove.disabled = false;
      remove.addEventListener('click', () => {
        const input = row.querySelector('[data-attachment-file]');
        if (input) input.value = '';
        clearWaitingForIdType(row);
        refresh();
        setFeedback('Selected Valid ID removed.');
      });
    }
    uploadQueue.append(waiting.card);
    row._waitingIdCard = waiting.card;
  }

  function renderDrafts(documents) {
    if (!Array.isArray(documents)) return;
    for (const kind of ['id', 'supporting']) {
      const savedList = savedListFor(kind);
      if (!savedList) continue;
      const selectedType = kind === 'id' ? 'valid_id' : 'supporting_document';
      const displayed = documents.filter(doc => doc.document_type === selectedType);
      const list = savedList.querySelector('[data-draft-items]');
      const count = savedList.querySelector('[data-draft-count]');
      const empty = savedList.querySelector('[data-draft-empty]');
      if (!list) continue;
      list.replaceChildren();
      for (const doc of displayed) {
        const item = selectedFileCard({
          id: doc.id,
          name: doc.original_name,
          type: doc.document_type,
          status: 'Saved — ready for submission · ' + (doc.id_label || 'Attachment'),
          percent: 100,
          isSaved: true,
          editable: kind === 'supporting'
        });
        item.card.dataset.draftRequirement = String(doc.requirement_type || 'other');
        list.append(item.card);
      }
      if (count) count.textContent = String(displayed.length);
      if (empty) empty.hidden = displayed.length > 0;
    }
    refresh();
    form?.dispatchEvent(new Event('registration:drafts-updated'));
  }

  function uploadWithProgress(endpoint, data, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint);
      xhr.withCredentials = true;
      xhr.responseType = 'json';
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.addEventListener('progress', event => {
        if (event.lengthComputable) onProgress(Math.round(event.loaded / event.total * 100));
      });
      xhr.addEventListener('load', () => {
        const response = xhr.response;
        if (xhr.status >= 200 && xhr.status < 300 && response && !response.error) {
          resolve(response);
        } else {
          reject(new Error(response?.error || 'Unable to save this file. Please retry.'));
        }
      });
      xhr.addEventListener('error', () => reject(new Error('Network error while uploading. Try again.')));
      xhr.addEventListener('abort', () => reject(new Error('Upload was interrupted. Select the file again.')));
      xhr.send(data);
    });
  }

  async function postDraft(endpoint, payload) {
    const response = await fetch(endpoint, {
      method: 'POST', body: payload, credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });
    const result = await response.json();
    if (!response.ok || result.error) {
      throw new Error(result.error || 'Attachment request failed.');
    }
    return result;
  }

  async function stageFile(kind, row, selectedFile = null) {
    if (!draftMode || !savedListFor(kind) || !csrf || row.dataset.uploading === '1') return;
    const input = row.querySelector('[data-attachment-file]');
    const selected = selectedFile || input?.files?.[0];
    if (!input || !selected) return;
    checkFile(input, selected);
    if (!input.checkValidity()) {
      input.reportValidity();
      return;
    }
    const selectedType = kind === 'id' ? (row.querySelector('[data-id-type]')?.value || '') : '';
    const category = kind === 'supporting'
      ? (row.querySelector('[data-supporting-category]')?.value || '') : 'other';
    if (kind === 'supporting' && !category) {
      setFeedback('Select a Document Category before uploading.',true);
      return;
    }
    if (kind === 'id' && !selectedType) {
      showWaitingForIdType(row, selected);
      setFeedback('Select the ID Type to start uploading this Valid ID.');
      return;
    }
    clearWaitingForIdType(row);
    if (savedCount(kind) >= limit[kind]) {
      setFeedback('Maximum saved files reached. Remove an attachment first.', true);
      return;
    }
    const data = new FormData();
    data.set('csrf_token', csrf);
    data.set('document_type', kind === 'id' ? 'valid_id' : 'supporting_document');
    data.set('id_type', selectedType);
    data.set('requirement_type',category);
    data.set('attachment', selected);
    const progress = selectedFileCard({
      name: selected.name,
      status: 'Preparing file…',
      percent: 2
    });
    const uploadQueue = uploadQueueFor(kind);
    if (uploadQueue) uploadQueue.append(progress.card);
    row.dataset.uploading = '1';
    pendingUploads++;
    refresh();
    setFeedback('Securely uploading ' + selected.name + '…');
    try {
      const response = await uploadWithProgress('register-draft-upload', data, percent => {
        progress.bar.value = Math.max(2, percent);
        progress.message.textContent = percent < 100
          ? 'Uploading… ' + percent + '%'
          : 'Finishing secure upload…';
      });
      progress.bar.value = 100;
      progress.message.textContent = 'Saved — ready for submission';
      progress.card.classList.remove('is-uploading');
      progress.card.classList.add('is-saved');
      renderDrafts(response.documents);
      progress.card.remove();
      setFeedback('Attachment saved securely. It will remain after page refresh.');
    } catch (error) {
      progress.card.classList.add('has-error');
      progress.message.textContent = 'Upload failed — select the file again';
      progress.card.remove();
      // File input is retained so the user can select another file or retry.
      setFeedback(error.message || 'Upload failed. Select the file again.', true);
    } finally {
      pendingUploads--;
      delete row.dataset.uploading;
      refresh();
    }
  }

  async function stageSelectedFiles(kind, row) {
    const input = row.querySelector('[data-attachment-file]');
    const selected = [...(input?.files || [])];
    if (!input || selected.length === 0) return;
    const available = limit[kind] - savedCount(kind);
    if (selected.length > available) {
      input.value = '';
      refresh();
      setFeedback('You can add only ' + available + ' more supporting document' + (available === 1 ? '' : 's') + '.', true);
      return;
    }
    for (const file of selected) {
      checkFile(input, file);
      if (!input.checkValidity()) {
        input.reportValidity();
        return;
      }
    }
    checkFile(input);
    for (const file of selected) await stageFile(kind, row, file);
    input.value = '';
    refresh();
  }

  if (draftMode) {
    for (const kind of ['id', 'supporting']) {
      const savedList = savedListFor(kind);
      if (!savedList) continue;
      savedList.addEventListener('keydown', event => {
        if (event.key !== 'Enter' || !event.target.matches('[data-draft-name]')) return;
        event.preventDefault();
        event.target.closest('[data-draft-document]')?.querySelector('[data-rename-draft]')?.click();
      });
      savedList.addEventListener('click', async event => {
        const rename = event.target.closest('[data-rename-draft]');
        if (rename) {
          const row = rename.closest('[data-draft-document]');
          const input = row?.querySelector('[data-draft-name]');
          const id = Number(row?.dataset.draftId);
          if (!row || !input || !Number.isSafeInteger(id) || id < 1 || pendingUploads > 0) return;
          const name = input.value.trim();
          if (!name) {
            input.setCustomValidity('Enter a file name.');
            input.reportValidity();
            return;
          }
          input.setCustomValidity('');
          const data = new FormData();
          data.set('csrf_token', csrf);
          data.set('id', String(id));
          data.set('name', name);
          rename.disabled = true;
          try {
            const result = await postDraft('register-draft-rename', data);
            renderDrafts(result.documents);
            setFeedback('Supporting document name updated.');
          } catch (error) {
            rename.disabled = false;
            setFeedback(error.message || 'Could not update the file name.', true);
          }
          return;
        }
        const button = event.target.closest('[data-remove-draft]');
        const row = button?.closest('[data-draft-document]');
        if (!button || !row || !csrf || pendingUploads > 0) return;
        const id = Number(row.dataset.draftId);
        if (!Number.isSafeInteger(id) || id < 1) return;
        const data = new FormData();
        data.set('csrf_token', csrf);
        data.set('id', String(id));
        button.disabled = true;
        try {
          const result = await postDraft('register-draft-delete', data);
          renderDrafts(result.documents);
          setFeedback('Saved attachment removed.');
        } catch (error) {
          button.disabled = false;
          setFeedback(error.message || 'Could not remove attachment.', true);
        }
      });
    }
  }

  for (const kind of ['id', 'supporting']) {
    addButtons[kind]?.addEventListener('click', () => addRow(kind));
    containers[kind]?.addEventListener('click', event => {
      const remove = event.target.closest('[data-remove-attachment]');
      if (!remove) return;
      const row = remove.closest('[data-attachment-row]');
      if (!row || rows(kind).length <= 1 || row.dataset.uploading === '1') return;
      clearWaitingForIdType(row);
      row.remove();
      setFeedback('');
      refresh();
    });
    containers[kind]?.addEventListener('change', event => {
      if (!event.target.matches('[data-attachment-file], [data-id-type], [data-supporting-category]')) return;
      setFeedback('');
      refresh();
      if (draftMode) {
        const row = event.target.closest('[data-attachment-row]');
        if (row) {
          if (event.target.matches('[data-attachment-file]')) clearWaitingForIdType(row);
          // The category may be selected after a file; do not discard that file.
          // Each staging request persists exactly the category currently shown.
          void stageSelectedFiles(kind, row);
        }
      }
    });
  }

  function requirementsMet(saved) {
    const selected = form?.querySelector('[data-registration-organization-type]')?.value || '';
    const required = {
      individual:[],
      corporation:['certificate_registration','secretary_certification'],
      government:['head_authorization']
    }[selected];
    if (!required) {
      setFeedback('Select an Applicant / Organization Type.',true);
      revealProblem(form?.querySelector('[data-registration-organization-type]'));
      return false;
    }
    const missing=required.filter(key=>!saved.has(key));
    if (missing.length) {
      const labels={
        certificate_registration:'Certificate of Registration',
        secretary_certification:'Secretary’s Certification',
        head_authorization:'Authorization from the Head of Agency'
      };
      setFeedback('Please upload the required documents: '+missing.map(x=>labels[x]).join('; ')+'.',true);
      revealProblem(containers.supporting?.querySelector('[data-supporting-category]')
        || containers.supporting);
      return false;
    }
    return true;
  }

  form?.addEventListener('submit', event => {
    refresh();
    if (draftMode && savedListFor('id') && savedListFor('supporting')) {
      if (pendingUploads > 0) {
        event.preventDefault();
        setFeedback('An attachment is still uploading. Submit after it is saved.', true);
        revealProblem(root.querySelector('[data-uploading="1"]') || feedback);
        return;
      }
      const pendingFile = [...root.querySelectorAll('[data-attachment-file]')]
        .find(input => input.files?.length);
      const hasUnstaged = [...root.querySelectorAll('[data-attachment-file]')]
        .some(input => input.files?.length);
      if (hasUnstaged) {
        event.preventDefault();
        setFeedback('An attachment is selected but not saved. Choose its ID Type and retry.', true);
        revealProblem(pendingFile);
        return;
      }
      if (requireIds && savedCount('id') === 0) {
        event.preventDefault();
        setFeedback('Save at least one Valid ID with its ID Type before submitting.', true);
        const idType = containers.id?.querySelector('[data-id-type]');
        revealProblem(idType?.value
          ? containers.id?.querySelector('[data-attachment-file]') : idType || containers.id);
        return;
      }
      if (requireIds && !requirementsMet(new Set(
        [...savedListFor('supporting').querySelectorAll('[data-draft-requirement]')]
          .map(row=>row.dataset.draftRequirement)
      ))) {
        event.preventDefault();
        return;
      }
      return; // Other fields retain HTML/native and server-side validation.
    }

    const idCount = rows('id').filter(row =>
      Boolean(row.querySelector('[data-attachment-file]')?.files?.length)).length;
    if (requireIds && idCount === 0) {
      event.preventDefault();
      setFeedback('Please upload at least one Valid ID and select its ID Type.', true);
      revealProblem(containers.id?.querySelector('[data-id-type]') || containers.id);
      return;
    }
    if (requireIds && !requirementsMet(new Set(
      rows('supporting').filter(row=>row.querySelector('[data-attachment-file]')?.files?.length)
        .map(row=>row.querySelector('[data-supporting-category]')?.value)
    ))) {
      event.preventDefault();
      return;
    }
    for (const input of root.querySelectorAll('[data-attachment-file], [data-id-type], [data-supporting-category]')) {
      if (!input.checkValidity()) {
        event.preventDefault();
        setFeedback('Please complete the ID Type selections and check uploaded files.', true);
        revealProblem(input);
        input.reportValidity();
        return;
      }
    }
  });

  refresh();
})();
