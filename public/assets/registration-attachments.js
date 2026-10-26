(() => {
  'use strict';

  const root = document.getElementById('registration-attachments');
  if (!root) return;

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

  function rows(kind) {
    return [...(containers[kind]?.querySelectorAll('[data-attachment-row]') || [])];
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
      input.setCustomValidity('Each file must be nonempty and at most ' + (maxBytes / 1048576).toFixed(1) + ' MB.');
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

        if (kind === 'id') {
          const idType = row.querySelector('[data-id-type]');
          if (!idType || !file) return;
          const started = idType.value !== '' || Boolean(file.files?.length);
          idType.required = requireIds || started;
          file.required = requireIds || started;
        }
      });
      if (addButtons[kind]) addButtons[kind].disabled = items.length >= limit[kind];
    }
  }

  function addRow(kind) {
    const parent = containers[kind];
    const items = rows(kind);
    if (!parent || items.length >= limit[kind] || items.length === 0) return;
    const lastIndex = Math.max(...items.map(row => Number(row.dataset.rowIndex) || 0));
    const index = lastIndex + 1;
    // Clone only the static form controls; never carry over selected files.
    const row = items[0].cloneNode(true);
    row.dataset.rowIndex = String(index);
    row.querySelectorAll('[id]').forEach(field => {
      field.id = field.id.replace(/_\d+$/, '_' + index);
    });
    row.querySelectorAll('label[for]').forEach(label => {
      label.htmlFor = label.htmlFor.replace(/_\d+$/, '_' + index);
    });
    row.querySelectorAll('[name]').forEach(field => {
      field.name = field.name.replace(/\[\d+\]$/, '[' + index + ']');
      if ('value' in field) field.value = '';
      if (field instanceof HTMLInputElement && field.type === 'file') {
        field.setCustomValidity('');
      }
    });
    const status = row.querySelector('[data-file-name]');
    if (status) status.textContent = 'No file selected';
    parent.appendChild(row);
    refresh();
    const focus = row.querySelector('select, input');
    focus?.focus();
  }

  for (const kind of ['id', 'supporting']) {
    addButtons[kind]?.addEventListener('click', () => addRow(kind));
    containers[kind]?.addEventListener('click', event => {
      const remove = event.target.closest('[data-remove-attachment]');
      if (!remove) return;
      const row = remove.closest('[data-attachment-row]');
      if (!row || rows(kind).length <= 1) return;
      row.remove();
      setFeedback('');
      refresh();
    });
    containers[kind]?.addEventListener('change', event => {
      if (event.target.matches('[data-attachment-file], [data-id-type]')) {
        setFeedback('');
        refresh();
      }
    });
  }

  const form = root.closest('form');
  form?.addEventListener('submit', event => {
    refresh();
    const idCount = rows('id').filter(row => Boolean(row.querySelector('[data-attachment-file]')?.files?.length)).length;
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
