(() => {
  'use strict';
  const root = document.getElementById('step2-draft-uploader');
  if (!root) return;
  const form = root.closest('form');
  const input = root.querySelector('#application_files');
  const categoryInput = root.querySelector('[data-step2-category-select]');
  const categoryFields = root.querySelector('[data-step2-direct-categories]');
  if (!categoryInput) return;
  const validCategories = new Set(
    [...categoryInput.options].map(opt=>opt.value).filter(Boolean));
  const categoryLabel = name =>
    [...categoryInput.options].find(opt=>opt.value===name)?.textContent
      || 'Other / Earlier Supporting Document';
  const categoryIsValid = value => validCategories.has(value);
  const requirementLists = new Map(
    [...document.querySelectorAll('[data-step2-requirement-files]')]
      .map(element=>[element.dataset.step2RequirementFiles,element]));
  document.querySelectorAll('[data-step2-pick-requirement]').forEach(button=>{
    button.addEventListener('click',()=>{
      const category=button.dataset.step2PickRequirement || '';
      if (!categoryIsValid(category)) return;
      categoryInput.value=category;
      categoryInput.dispatchEvent(new Event('change',{bubbles:true}));
      if (!input.disabled) input.click();
    });
  });
  const list = root.querySelector('[data-step2-items]');
  const queue = root.querySelector('[data-step2-queue]');
  const count = root.querySelector('[data-step2-count]');
  const empty = root.querySelector('[data-step2-empty]');
  const feedback = root.querySelector('[data-step2-feedback]');
  const submit = form.querySelector('button[type="submit"]');
  const csrf = form.querySelector('input[name="csrf_token"]')?.value || '';
  const scope = root.dataset.applicationId || '0';
  const maxBytes = Number(root.dataset.maxBytes || 5242880);
  const persistent = root.dataset.persistent === '1';
  const draftKey = form.dataset.draftKey || '';
  const fields = ['project_title','project_location','purpose',
    'project_latitude','project_longitude'];
  const saveStatus = form.querySelector('[data-step2-autosave-status]');
  const warning = root.querySelector('[data-step2-js-warning]');
  let busy = false;
  let finalizing = false;
  let submitApproved = false;
  let saveTimer = null;
  let saveChain = Promise.resolve();

  let draftStorage = null;
  try { draftStorage = window.sessionStorage; } catch (_) {}
  function readFields() {
    const values = {};
    for (const field of fields) values[field] = form.elements.namedItem(field)?.value || '';
    return values;
  }
  function saveMessage(value,error=false) {
    if (!saveStatus) return;
    saveStatus.textContent = value;
    saveStatus.classList.toggle('text-danger',error);
    saveStatus.classList.toggle('text-success',!error);
  }
  function notice(value,error=false) {
    feedback.textContent = value;
    feedback.classList.toggle('text-danger',error);
    feedback.classList.toggle('text-secondary',!error);
  }
  function saveFormDraft() {
    if (persistent || !draftStorage || !draftKey) return;
    try { draftStorage.setItem(draftKey,JSON.stringify(readFields())); } catch (_) {}
  }
  function restoreFormDraft() {
    if (persistent || !draftStorage || !draftKey) return;
    let values;
    try { values = JSON.parse(draftStorage.getItem(draftKey)||'null'); } catch (_) {return;}
    if (!values || typeof values !== 'object') return;
    for (const name of fields) {
      const field = form.elements.namedItem(name);
      if (field && !field.value && typeof values[name] === 'string') field.value = values[name];
    }
  }
  function clearFormDraft() {
    try { draftStorage?.removeItem(draftKey); } catch (_) {}
  }
  function post(endpoint,payload,onProgress) {
    return new Promise((resolve,reject) => {
      const request = new XMLHttpRequest();
      request.open('POST',endpoint,true);
      request.responseType = 'json';
      request.upload.addEventListener('progress',event => {
        if (onProgress && event.lengthComputable) {
          onProgress(Math.round(event.loaded*100/event.total));
        }
      });
      request.addEventListener('load',() => {
        const result = request.response;
        if (request.status>=200 && request.status<300 && result && !result.error) resolve(result);
        else reject(new Error(result?.error || 'Request failed. Please refresh and try again.'));
      });
      request.addEventListener('error',() => reject(new Error('Network error. Please try again.')));
      request.send(payload);
    });
  }
  function payload() {
    const data = new FormData();
    data.set('csrf_token',csrf);
    data.set('application_id',scope);
    return data;
  }
  function queueServerSave() {
    if (!persistent) return Promise.resolve();
    const values = readFields();
    const data = payload();
    for (const [key,value] of Object.entries(values)) data.set(key,value);
    saveMessage('Saving FP draft…');
    saveChain = saveChain.catch(()=>{}).then(async () => {
      const result = await post('permit-draft-save',data);
      if (!result.saved) throw new Error('Draft was not saved.');
      saveMessage('Draft saved securely to your applicant account.');
      return result;
    });
    return saveChain;
  }
  if (persistent) {
    const schedule = () => {
      if (finalizing) return;
      clearTimeout(saveTimer);
      saveTimer = setTimeout(()=>queueServerSave().catch(error =>
        saveMessage(error.message || 'Draft save failed.',true)),450);
    };
    form.addEventListener('input',schedule);
    form.addEventListener('change',schedule);
  } else {
    restoreFormDraft();
    saveFormDraft();
    form.addEventListener('input',saveFormDraft);
    form.addEventListener('change',saveFormDraft);
  }

  // Keep the draft until the server actually commits the application.
  function validateSubmission() {
    if (busy) { notice('Finish saving/uploading files before submitting.',true); return false; }
    if (root.dataset.step2Mode === 'saved' && scope === '0' && savedCount() === 0) {
      notice('Upload at least one supporting document before submission.',true);
      return false;
    }
    if (root.dataset.step2Mode === 'direct') {
      if (scope === '0' && selected.length === 0) {
        notice('Select at least one supporting document.',true); return false;
      }
      if (selected.length>10 || selected.some(entry=>!validFile(entry.file)
          || !categoryIsValid(entry.category))) {
        notice('Selected files are invalid. Maximum 10 PDF/JPG/PNG documents.',true); return false;
      }
    }
    return true;
  }
  form.addEventListener('submit',event => {
    if (!validateSubmission()) {
      event.preventDefault();
      form.dispatchEvent(new CustomEvent('step2:submission-invalid',{
        detail:{target:root}
      }));
      return;
    }
    if (!persistent || submitApproved) return;
    event.preventDefault();
    if (finalizing) return;
    finalizing = true;
    clearTimeout(saveTimer);
    submit.disabled = true;
    queueServerSave().then(()=>{
      submitApproved = true;
      submit.disabled = false;
      form.requestSubmit(submit);
    }).catch(error=>{
      finalizing = false;
      submit.disabled = false;
      saveMessage('Submit stopped; draft save failed: '+(error.message||'try again'),true);
    });
  });

  document.querySelector('[data-step2-confirm-clear]')?.addEventListener('click',async event => {
    if (!persistent || busy || finalizing) return;
    const button = event.currentTarget;
    window.FPOMSActionFeedback?.stop(button);
    window.FPOMSActionFeedback?.start(button,'Clearing…',{lock:true,duration:15000});
    button.disabled = true;
    clearTimeout(saveTimer);
    try {
      // Complete previous autosaves before the deliberate destructive action.
      await saveChain.catch(()=>{});
      const result = await post('permit-draft-clear',payload());
      if (!result.cleared) throw new Error('Server did not confirm clearing your draft.');
      clearFormDraft();
      window.location.reload();
    } catch (error) {
      window.FPOMSActionFeedback?.stop(button);
      button.disabled = false;
      saveMessage('Draft was not cleared: '+(error.message || 'try again'),true);
      window.bootstrap?.Modal.getInstance(document.getElementById('step2-clear-modal'))?.hide();
    }
  });

  const validFile = file => file.size > 0 && file.size <= maxBytes
    && /\.(pdf|jpe?g|png)$/i.test(file.name);
  let selected = [];

  function savedCount() {
    return form.querySelectorAll('[data-step2-draft-id]').length;
  }
  function updateRequirementLists() {
    for (const requirementList of requirementLists.values()) {
      const hasFiles = Boolean(requirementList.querySelector('.selected-file-card'));
      const placeholder = requirementList.querySelector('[data-step2-requirement-empty]');
      if (placeholder) placeholder.hidden = hasFiles;
    }
  }
  function placeFileCard(item,category) {
    item.dataset.step2RequirementType = category;
    const requirementList = requirementLists.get(category);
    (requirementList || list).append(item);
    updateRequirementLists();
  }
  function clearFileCards() {
    list.querySelectorAll('.selected-file-card').forEach(item=>item.remove());
    for (const requirementList of requirementLists.values()) {
      requirementList.querySelectorAll('.selected-file-card').forEach(item=>item.remove());
    }
    updateRequirementLists();
  }
  function refresh() {
    const saved = root.dataset.step2Mode === 'saved';
    const n = saved ? savedCount() : selected.length;
    count.textContent = String(n);
    empty.hidden = n>0 || queue.children.length>0;
    input.disabled = busy || finalizing || n>=10;
    submit.disabled = busy || finalizing;
    updateRequirementLists();
  }
  function fileCard(name,id=null,mime='',category='other') {
    const item = document.createElement('li');
    item.className = 'selected-file-card ' + (id===null ? 'is-uploading' : 'is-saved');
    if (id!==null) item.dataset.step2DraftId = String(id);
    item.dataset.step2RequirementType = category;
    const icon = document.createElement('span');
    icon.className = 'selected-file-icon';
    icon.setAttribute('aria-hidden','true');
    icon.innerHTML = '<i class="bi bi-file-earmark-text-fill"></i>';
    const info = document.createElement('div');
    info.className = 'selected-file-info';
    const filename = document.createElement('strong');
    filename.className = 'selected-file-name';
    filename.textContent = name;
    const status = document.createElement('span');
    status.className = 'selected-file-status';
    status.textContent = id===null ? 'Preparing secure upload…'
      : 'Saved — ready for submission · ' + categoryLabel(category);
    info.append(filename,status);
    item.append(icon,info);
    if (id!==null && persistent && mime==='application/pdf') {
      const edit = document.createElement('button');
      edit.type = 'button';
      edit.className = 'btn btn-sm btn-outline-primary me-1';
      edit.dataset.step2Rearrange = '';
      edit.title = 'Rearrange PDF pages again';
      edit.setAttribute('aria-label','Rearrange pages again in '+name);
      edit.innerHTML = '<i class="bi bi-files me-1" aria-hidden="true"></i>Rearrange Pages';
      item.append(edit);
    }
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'selected-file-remove';
    remove.title = 'Remove file';
    remove.setAttribute('aria-label','Remove saved attachment: '+name);
    remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
    if (id===null) remove.disabled = true;
    else remove.dataset.step2Remove = '';
    const progress = document.createElement('progress');
    progress.className = 'selected-file-progress';
    progress.max = 100;
    progress.value = id===null?2:100;
    progress.setAttribute('aria-label','Upload progress');
    item.append(remove,progress);
    return {item,status,progress};
  }
  function render(docs) {
    clearFileCards();
    for (const doc of docs||[]) {
      const category = String(doc.requirement_type||'other');
      placeFileCard(fileCard(String(doc.original_name),doc.id,
        String(doc.mime_type||''),category).item,category);
    }
    refresh();
  }

  [...list.querySelectorAll('.selected-file-card')].forEach(item=>
    placeFileCard(item,item.dataset.step2RequirementType||'other'));

  // Without migration 015/016, preserve the existing normal form upload and
  // label chosen files as unsaved. Never call them permanently retained.
  if (root.dataset.step2Mode === 'direct') {
    function syncInput() {
      const transfer = new DataTransfer();
      for (const entry of selected) transfer.items.add(entry.file);
      input.files = transfer.files;
      if (categoryFields) {
        categoryFields.replaceChildren();
        for (const entry of selected) {
          const hidden=document.createElement('input');
          hidden.type='hidden';
          hidden.name='application_file_categories[]';
          hidden.value=entry.category;
          categoryFields.append(hidden);
        }
      }
    }
    function showLocal() {
      clearFileCards();
      selected.forEach((entry,index)=>{
        const row = fileCard(entry.file.name,null,'',entry.category);
        row.item.className = 'selected-file-card is-pending';
        row.status.textContent = 'Selected · ' + categoryLabel(entry.category)
          + ' · uploads on final submission';
        row.progress.value = 0;
        const remove = row.item.querySelector('.selected-file-remove');
        remove.disabled = false;
        remove.dataset.step2LocalIndex = String(index);
        placeFileCard(row.item,entry.category);
      });
      refresh();
    }
    input.addEventListener('change',async ()=>{
      if (busy) return;
      const picked = Array.from(input.files||[]);
      const category=categoryInput.value;
      input.value = '';
      if (!categoryIsValid(category)) {
        notice('Choose a documentary requirement before selecting files.',true);
        return;
      }
      busy = true; refresh();
      let rejected = 0;
      for (const original of picked) {
        if (!validFile(original) || selected.length>=10) {rejected++;continue;}
        let file = original;
        if (/\.pdf$/i.test(original.name)) {
          try {
            if (typeof window.FPOMSChoosePdfUpload !== 'function') {
              throw new Error('PDF upload confirmation did not load.');
            }
            file = await window.FPOMSChoosePdfUpload(original,maxBytes,'direct');
          } catch (error) { notice(error.message,true); rejected++;continue; }
        }
        if (file && validFile(file)) selected.push({file,category});
      }
      try {syncInput();}
      catch(error) {
        selected = [];
        notice('Use a current Edge or Chrome browser to manage the chosen files.',true);
      }
      busy = false; showLocal();
      if (rejected) notice('Some files were skipped because of file count/type/size limits.',true);
      else notice('Files are not saved yet; they upload on final submission.');
    });
    form.addEventListener('click',event=>{
      const button = event.target.closest('[data-step2-local-index]');
      if (!button || busy) return;
      const index = Number(button.dataset.step2LocalIndex);
      const copy = selected;
      selected = selected.filter((_,i)=>i!==index);
      try {syncInput();showLocal();notice('File removed.');}
      catch(error) {selected=copy;notice('Could not remove file. Choose files again.',true);}
    });
    showLocal();
    if (warning) warning.hidden = true;
    return;
  }

  // With migration 015, files upload securely on selection. With migration 016
  // they survive logouts/refreshes and PDFs can be edited again.
  input.addEventListener('change',async ()=>{
    if (busy) return;
    const picked = Array.from(input.files||[]);
    const category=categoryInput.value;
    input.value = '';
    if (!categoryIsValid(category)) {
      notice('Select the documentary requirement before uploading files.',true);
      return;
    }
    const room = Math.max(0,10-savedCount());
    if (picked.length>room) notice('Only '+room+' more file(s) can be saved.',true);
    busy = true;refresh();
    for (const original of picked.slice(0,room)) {
      if (!validFile(original)) {notice('Invalid file size or format.',true);continue;}
      let file = original;
      if (/\.pdf$/i.test(file.name)) {
        try {
          if (typeof window.FPOMSChoosePdfUpload !== 'function') {
            throw new Error('PDF upload confirmation did not load.');
          }
          file = await window.FPOMSChoosePdfUpload(original,maxBytes,'saved');
        } catch(error) {notice(error.message,true);continue;}
      }
      if (!file) continue;
      if (!validFile(file)) {notice('Organized PDF is too large.',true);continue;}
      const card = fileCard(file.name,null,'',category);
      queue.append(card.item);refresh();
      const data = payload();
      data.set('document_category',category);
      data.set('attachment',file);
      try {
        const result = await post('permit-draft-upload',data,pct=>{
          card.progress.value = Math.max(2,pct);
          card.status.textContent = pct<100 ? 'Uploading ' + categoryLabel(category)
            + '… '+pct+'%' : 'Verifying saved upload…';
        });
        render(result.documents);
        notice(persistent ? 'Document saved to your applicant draft.'
          : 'Saved in the temporary browser session.');
      } catch(error) {
        notice(error.message || 'Upload failed; file was not saved.',true);
      } finally {card.item.remove();refresh();}
    }
    busy = false;refresh();
  });

  form.addEventListener('click',async event=>{
    const edit = event.target.closest('[data-step2-rearrange]');
    const row = event.target.closest('[data-step2-draft-id]');
    if (!row || busy) return;
    const id = row.dataset.step2DraftId;
    if (edit && persistent) {
      busy = true;refresh();
      try {
        const query = new URLSearchParams({application_id:scope,id});
        const response = await fetch('permit-draft-file?'+query.toString(),{
          credentials:'same-origin',cache:'no-store'
        });
        if (!response.ok) throw new Error('Saved PDF could not be loaded.');
        const blob = await response.blob();
        const name = row.querySelector('.selected-file-name')?.textContent || 'document.pdf';
        const file = new File([blob],name,{type:'application/pdf'});
        if (!window.FPOMSOrganizePdf) throw new Error('PDF organizer unavailable.');
        const changed = await window.FPOMSOrganizePdf(file,maxBytes,'saved');
        if (changed) {
          const data = payload();
          data.set('id',id);
          data.set('attachment',changed);
          const result = await post('permit-draft-replace',data);
          render(result.documents);
          notice('Rearranged PDF saved successfully. Old version replaced safely.');
        }
      } catch(error) {
        notice((error.message||'PDF edit failed.')+' Your saved original is retained.',true);
      } finally {busy=false;refresh();}
      return;
    }
    if (!event.target.closest('[data-step2-remove]')) return;
    busy = true;refresh();
    const data = payload();data.set('id',id);
    try {
      const result = await post('permit-draft-delete',data);
      render(result.documents);notice('Saved document removed.');
    } catch(error) {notice(error.message||'Removal failed.',true);}
    finally {busy=false;refresh();}
  });
  refresh();
  if (warning) warning.hidden = true;
})();
