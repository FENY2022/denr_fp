(() => {
  'use strict';

  const form = document.getElementById('applicant-registration-form');
  const modalElement = document.getElementById('registration-review-confirm-modal');
  if (!form || !modalElement) return;

  const reviewed = form.querySelector('[data-registration-reviewed]');
  const acknowledged = form.querySelector('[data-registration-email-acknowledged]');
  const emailCheck = modalElement.querySelector('[data-review-email-check]');
  const confirm = modalElement.querySelector('[data-review-confirm]');
  const errorArea = modalElement.querySelector('[data-review-error]');
  const area = modalElement.querySelector('[data-review-preview-area]');
  const previewTitle = modalElement.querySelector('[data-review-preview-title]');
  const previewMedia = modalElement.querySelector('[data-review-preview-media]');
  const submitButton = form.querySelector('[data-registration-review-open]');
  if (!reviewed || !acknowledged || !emailCheck || !confirm) return;

  let confirmed = false;
  const organizationNames = {
    individual: 'Individual / Sole Proprietorship',
    corporation: 'Corporation / Cooperative / Association',
    government: 'National Government Agency / GOCC / Government Instrumentality'
  };

  const fieldNames = [
    ['representative_name', 'Applicant Full Name'],
    ['representative_position', 'Position / Designation'],
    ['email', 'Email for Login'],
    ['company_name', 'Company / Agency / Organization Name'],
    ['contact_number', 'Contact Number'],
    ['company_address', 'Company / Agency Address']
  ];

  function fieldValue(name) {
    const field = form.elements.namedItem(name);
    return typeof field?.value === 'string' ? field.value.trim() : '';
  }

  function setText(selector, value) {
    const node = modalElement.querySelector(selector);
    if (node) node.textContent = value;
  }

  function clearPreview() {
    if (!area || !previewMedia) return;
    previewMedia.replaceChildren();
    if (previewTitle) previewTitle.textContent = '';
    area.classList.add('d-none');
  }

  function openPreview(fileId, name) {
    if (!Number.isSafeInteger(fileId) || fileId < 1 || !previewMedia) return;
    clearPreview();
    const view = document.createElement('iframe');
    view.className = 'registration-review-file-frame w-100 border rounded';
    view.title = 'Preview of ' + name;
    view.loading = 'lazy';
    view.referrerPolicy = 'no-referrer';
    view.src = 'register-draft-preview?id=' + encodeURIComponent(String(fileId));
    // Preview response is session-owned, same-origin and served with a sandboxed CSP.
    previewMedia.append(view);
    if (previewTitle) previewTitle.textContent = name;
    area.classList.remove('d-none');
    area.scrollIntoView({block:'nearest',behavior:'smooth'});
  }

  function currentSavedDocuments(kind) {
    const selector = kind === 'supporting'
      ? '#registration-supporting-draft-list'
      : '#registration-draft-list';
    const panel = form.querySelector(selector);
    if (!panel) return null; // The preview does not misrepresent a non-persistent upload.
    return [...panel.querySelectorAll('[data-draft-document]')].map(row => {
      const typedName = row.querySelector('[data-draft-name]');
      const name = typedName?.value?.trim()
        || row.querySelector('.selected-file-name')?.textContent?.trim()
        || 'Attachment';
      const status = row.querySelector('.selected-file-status')?.textContent?.trim() || '';
      const label = status.includes('·') ? status.split('·').pop().trim() : 'Attachment';
      return { id:Number(row.dataset.draftId), name, label, kind };
    });
  }

  function renderDocuments(selector, docs) {
    const list = modalElement.querySelector(selector);
    if (!list) return;
    list.replaceChildren();
    if (!docs?.length) {
      const item = document.createElement('li');
      item.className = 'list-group-item small text-secondary';
      item.textContent = 'No saved files found. Return to the form and save the required uploads.';
      list.append(item);
      return;
    }
    for (const doc of docs) {
      const item = document.createElement('li');
      item.className = 'list-group-item d-flex justify-content-between align-items-center gap-2 flex-wrap';
      const details = document.createElement('div');
      details.className = 'flex-grow-1 text-break';
      const name = document.createElement('strong');
      name.className = 'd-block small';
      name.textContent = doc.name;
      const category = document.createElement('span');
      category.className = 'small text-secondary';
      category.textContent = doc.label;
      details.append(name,category);
      item.append(details);
      if (Number.isSafeInteger(doc.id) && doc.id > 0) {
        const preview = document.createElement('button');
        preview.type = 'button';
        preview.className = 'btn btn-sm btn-outline-primary';
        preview.textContent = 'Preview';
        preview.setAttribute('aria-label','Preview ' + doc.name);
        preview.addEventListener('click', () => openPreview(doc.id,doc.name));
        item.append(preview);
      }
      list.append(item);
    }
  }

  function renderDetails() {
    setText('[data-review-organization]',
      organizationNames[fieldValue('organization_type')] || 'Not selected');
    const fields = modalElement.querySelector('[data-review-fields]');
    if (fields) {
      fields.replaceChildren();
      for (const [name,label] of fieldNames) {
        const key = document.createElement('dt');
        key.className = 'col-sm-5 text-secondary';
        key.textContent = label;
        const value = document.createElement('dd');
        value.className = 'col-sm-7 text-break';
        value.textContent = fieldValue(name) || 'Not provided';
        fields.append(key,value);
      }
    }
    setText('[data-review-email]',fieldValue('email'));
    renderDocuments('[data-review-supporting]',currentSavedDocuments('supporting'));
    renderDocuments('[data-review-ids]',currentSavedDocuments('id'));
    clearPreview();
    emailCheck.checked = false;
    confirm.disabled = true;
    if (errorArea) errorArea.classList.add('d-none');
  }

  function resetConfirmation() {
    confirmed = false;
    reviewed.value = '0';
    acknowledged.value = '0';
    confirm.disabled = true;
  }

  emailCheck.addEventListener('change', () => {
    if (!confirmed) confirm.disabled = !emailCheck.checked;
  });
  modalElement.querySelector('[data-review-preview-close]')?.addEventListener('click',clearPreview);

  // Added after the registration attachments script. Its submit listener
  // blocks pending uploads / absent mandatory files before this review runs.
  form.addEventListener('submit', event => {
    if (event.defaultPrevented) {
      if (confirmed) {
        resetConfirmation();
        if (errorArea) {
          errorArea.textContent = 'Please resolve any file upload or form validation errors before submitting.';
          errorArea.classList.remove('d-none');
        }
      }
      return;
    }
    if (confirmed && reviewed.value === 'confirmed'
        && acknowledged.value === '1' && emailCheck.checked) {
      // Only the second, explicitly confirmed submit may reach the server.
      confirm.disabled = true;
      submitButton.disabled = true;
      confirm.textContent = 'Submitting…';
      return;
    }
    event.preventDefault();
    resetConfirmation();
    if (!window.bootstrap?.Modal) {
      // Fail closed if the Bootstrap modal script could not be loaded.
      const feedback = form.querySelector('#attachment-feedback');
      if (feedback) feedback.textContent = 'Review confirmation is unavailable. Refresh the page and retry.';
      return;
    }
    const valid = form.reportValidity();
    if (!valid) return;
    renderDetails();
    window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
  });

  confirm.addEventListener('click', () => {
    if (confirmed || !emailCheck.checked) return;
    if (!form.reportValidity()) {
      if (errorArea) {
        errorArea.textContent = 'Please complete the required information before confirming.';
        errorArea.classList.remove('d-none');
      }
      return;
    }
    confirmed = true;
    reviewed.value = 'confirmed';
    acknowledged.value = '1';
    // requestSubmit preserves HTML5 validation and the existing upload checks.
    // A direct raw submission would skip those safeguards.
    form.requestSubmit(submitButton);
  });

  modalElement.addEventListener('hidden.bs.modal', () => {
    clearPreview();
    if (!confirmed) {
      resetConfirmation();
      emailCheck.checked = false;
    }
  });
})();
