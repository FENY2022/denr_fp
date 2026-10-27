(() => {
  'use strict';

  const root = document.getElementById('registration-attachments');
  if (!root) return;

  const form = root.closest('form');
  const draftMode = root.dataset.draftMode === '1';
  const savedList = draftMode ? document.getElementById('registration-draft-list') : null;
  const csrf = form?.querySelector('input[name="csrf_token"]')?.value || '';
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

  function rows(kind) {
    return [...(containers[kind]?.querySelectorAll('[data-attachment-row]') || [])];
  }

  function savedCount(kind) {
    if (!savedList) return 0;
    const docType = kind === 'id' ? 'valid_id' : 'supporting_document';
    return [...savedList.querySelectorAll('[data-draft-document]')]
      .filter(row => row.dataset.draftType === docType).length;
  }

  function setFeedback(message, invalid = false) {
    if (!feedback) return;
    feedback.textContent = message;
    feedback.classList.toggle('text-danger', invalid);
    feedback.classList.toggle('text-secondary', !invalid);
  }

  function checkFile(input) {
    const file = input.files?.[0];
    const status = input.closest('[data-attachment-row]')?.querySelector('[data-file-name]');
    input.setCustomValidity('');
    if (!file) {
      if (status) status.textContent = 'No file selected';
      return;
    }
    if (status) status.textContent = file.name + ' (' + (file.size / 1048576).toFixed(2) + ' MB)';
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

  function renderDrafts(documents) {
    if (!savedList || !Array.isArray(documents)) return;
    const list = savedList.querySelector('[data-draft-items]');
    const count = savedList.querySelector('[data-draft-count]');
    const empty = savedList.querySelector('[data-draft-empty]');
    if (!list) return;
    list.replaceChildren();
    for (const doc of documents) {
      const li = document.createElement('li');
      li.className = 'list-group-item d-flex align-items-center justify-content-between gap-2 flex-wrap';
      li.dataset.draftDocument = '';
      li.dataset.draftType = doc.document_type;
      li.dataset.draftId = String(doc.id);

      const span = document.createElement('span');
      const heading = document.createElement('strong');
      heading.textContent = doc.id_label || 'Attachment';
      const name = document.createElement('span');
      name.className = 'small text-secondary d-block';
      name.textContent = doc.original_name + ' · ' + (doc.size / 1048576).toFixed(2) + ' MB';
      span.append(heading, name);

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn btn-sm btn-outline-danger';
      remove.dataset.removeDraft = '';
      remove.textContent = 'Remove';
      remove.setAttribute('aria-label', 'Remove saved attachment');
      li.append(span, remove);
      list.append(li);
    }
    if (count) count.textContent = String(documents.length);
    if (empty) empty.hidden = documents.length > 0;
    refresh();
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

  async function stageFile(kind, row) {
    if (!draftMode || !savedList || !csrf || row.dataset.uploading === '1') return;
    const input = row.querySelector('[data-attachment-file]');
    const selected = input?.files?.[0];
    if (!input || !selected) return;
    checkFile(input);
    if (!input.checkValidity()) {
      input.reportValidity();
      return;
    }
    const selectedType = kind === 'id' ? (row.querySelector('[data-id-type]')?.value || '') : '';
    if (kind === 'id' && !selectedType) {
      setFeedback('Select the ID Type to save this ID attachment.', true);
      return;
    }
    if (savedCount(kind) >= limit[kind]) {
      setFeedback('Maximum saved files reached. Remove an attachment first.', true);
      return;
    }
    const data = new FormData();
    data.set('csrf_token', csrf);
    data.set('document_type', kind === 'id' ? 'valid_id' : 'supporting_document');
    data.set('id_type', selectedType);
    data.set('attachment', selected);
    row.dataset.uploading = '1';
    pendingUploads++;
    refresh();
    setFeedback('Uploading and securely saving ' + selected.name + '…');
    try {
      const response = await postDraft('register-draft-upload', data);
      input.value = '';
      const typeSelect = row.querySelector('[data-id-type]');
      if (typeSelect) typeSelect.value = '';
      renderDrafts(response.documents);
      setFeedback('Saved to private draft storage. The file remains after page refresh.');
    } catch (error) {
      setFeedback(error.message || 'Upload failed. Select the file again.', true);
    } finally {
      pendingUploads--;
      delete row.dataset.uploading;
      refresh();
    }
  }

  if (draftMode && savedList) {
    savedList.addEventListener('click', async event => {
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

  for (const kind of ['id', 'supporting']) {
    addButtons[kind]?.addEventListener('click', () => addRow(kind));
    containers[kind]?.addEventListener('click', event => {
      const remove = event.target.closest('[data-remove-attachment]');
      if (!remove) return;
      const row = remove.closest('[data-attachment-row]');
      if (!row || rows(kind).length <= 1 || row.dataset.uploading === '1') return;
      row.remove();
      setFeedback('');
      refresh();
    });
    containers[kind]?.addEventListener('change', event => {
      if (!event.target.matches('[data-attachment-file], [data-id-type]')) return;
      setFeedback('');
      refresh();
      if (draftMode) {
        const row = event.target.closest('[data-attachment-row]');
        if (row) void stageFile(kind, row);
      }
    });
  }

  form?.addEventListener('submit', event => {
    refresh();
    if (draftMode && savedList) {
      if (pendingUploads > 0) {
        event.preventDefault();
        setFeedback('An attachment is still uploading. Submit after it is saved.', true);
        return;
      }
      const pendingFile = root.querySelector('[data-attachment-file]');
      const hasUnstaged = [...root.querySelectorAll('[data-attachment-file]')]
        .some(input => input.files?.length);
      if (hasUnstaged) {
        event.preventDefault();
        setFeedback('An attachment is selected but not saved. Choose its ID Type and retry.', true);
        pendingFile?.focus();
        return;
      }
      if (requireIds && savedCount('id') === 0) {
        event.preventDefault();
        setFeedback('Save at least one Valid ID with its ID Type before submitting.', true);
        return;
      }
      return; // Other fields retain HTML/native and server-side validation.
    }

    const idCount = rows('id').filter(row =>
      Boolean(row.querySelector('[data-attachment-file]')?.files?.length)).length;
    if (requireIds && idCount === 0) {
      event.preventDefault();
      setFeedback('Please upload at least one Valid ID and select its ID Type.', true);
      return;
    }
    for (const input of root.querySelectorAll('[data-attachment-file], [data-id-type]')) {
      if (!input.checkValidity()) {
        event.preventDefault();
        setFeedback('Please complete the ID Type selections and check uploaded files.', true);
        input.reportValidity();
        return;
      }
    }
  });

  refresh();
})();
