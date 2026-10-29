(() => {
  'use strict';
  const form = document.getElementById('applicant-registration-form')
    || document.querySelector('form[action*="registration-update"]');
  if (!form) return;
  const type = form.querySelector('[data-registration-organization-type]');
  const info = form.querySelector('[data-registration-requirements-info]');
  const supporting = form.querySelector('#supporting-document-rows');
  if (!type || !info || !supporting) return;

  const required = {
    individual:[],
    corporation:['certificate_registration','secretary_certification'],
    government:['head_authorization']
  };
  const descriptions = {
    certificate_registration:'Certified true copy of Certificate of Registration from the registering agency.',
    secretary_certification:'Secretary’s Certification authorizing the representative to transact with DENR on behalf of the company.',
    head_authorization:'Authorization to apply for the FP from the Head of Agency.'
  };
  const titles = {
    certificate_registration:'A. Certificate of Registration',
    secretary_certification:'B. Secretary’s Certification',
    head_authorization:'Authorization from Head of Agency'
  };

  function savedKinds() {
    return new Set([...form.querySelectorAll(
      '#registration-supporting-draft-list [data-draft-requirement]')]
      .map(node => node.dataset.draftRequirement));
  }
  function chooseForNewRow(row) {
    const select = row?.querySelector('[data-supporting-category]');
    if (!select || select.value !== 'other') return;
    const desired = required[type.value] || [];
    const already = savedKinds();
    for (const item of supporting.querySelectorAll('[data-supporting-category]')) {
      if (item === select) continue;
      if (item.value && item.closest('[data-attachment-row]')?.querySelector('[data-attachment-file]')?.files?.length) {
        already.add(item.value);
      }
    }
    select.value = desired.find(value => !already.has(value)) || 'other';
  }

  function render() {
    const typeValue = type.value;
    const needed = required[typeValue];
    info.replaceChildren();
    if (!needed) {
      info.textContent = 'Select an Applicant / Organization Type to see the required supporting documents.';
      return;
    }
    const heading = document.createElement('strong');
    heading.textContent = needed.length ? 'Required Supporting Documents' : 'Supporting Documents (Optional)';
    info.append(heading);
    if (!needed.length) {
      const p=document.createElement('p');
      p.className='small mb-0 mt-1';
      p.textContent='Valid Identification Cards remain required. Other supporting documents may be uploaded when applicable.';
      info.append(p);
      return;
    }
    const list=document.createElement('ol');
    list.className='small mb-0 mt-2';
    list.setAttribute('aria-label','Requirements for the selected organization type');
    const saved=savedKinds();
    for (const key of needed) {
      const item=document.createElement('li');
      const label=document.createElement('strong');
      label.textContent=titles[key]+(saved.has(key) ? ' — Saved' : ' — Required');
      item.append(label,document.createTextNode(': '+descriptions[key]));
      list.append(item);
    }
    info.append(list);
    const hint=document.createElement('p');
    hint.className='small mb-0 mt-2';
    hint.textContent='Select the matching Document Category for every supporting file. Multiple uploads are permitted for each category, up to 10 supporting files total.';
    info.append(hint);
  }
  type.addEventListener('change',()=>{
    render();
    const first=supporting.querySelector('[data-attachment-row]');
    if (first && !first.querySelector('[data-attachment-file]')?.files?.length) {
      const select=first.querySelector('[data-supporting-category]');
      if (select) select.value=(required[type.value]||[])[0]||'other';
    }
  });
  form.querySelector('[data-add-supporting]')?.addEventListener('click',()=>{
    chooseForNewRow(supporting.lastElementChild);
  });
  supporting.addEventListener('change',render);
  form.addEventListener('registration:drafts-updated',render);
  form.querySelector('#registration-supporting-draft-list')?.addEventListener('click',()=>{
    // Saved list updates asynchronously; the event only re-renders the static checklist.
    window.requestAnimationFrame(render);
  });
  render();
  if ((required[type.value]||[]).length && supporting.querySelectorAll('[data-attachment-row]').length===1) {
    chooseForNewRow(supporting.firstElementChild);
  }
})();
