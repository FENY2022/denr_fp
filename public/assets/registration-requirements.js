(() => {
  'use strict';
  const form = document.getElementById('applicant-registration-form')
    || document.querySelector('form[action*="registration-update"]');
  if (!form) return;
  const type = form.querySelector('[data-registration-organization-type]');
  const info = form.querySelector('[data-registration-requirements-info]');
  const supporting = form.querySelector('#supporting-document-rows');
  const guide = form.querySelector('[data-registration-entity-guide]');
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

  function updateAlwaysVisibleGuide() {
    if (!guide) return;
    const selected = type.value;
    for (const card of guide.querySelectorAll('[data-registration-requirement-card]')) {
      const active = card.dataset.registrationRequirementCard === selected;
      card.classList.toggle('border-success',active);
      card.classList.toggle('bg-success-subtle',active);
      card.querySelector('[data-entity-guide-badge]')?.classList.toggle('d-none',!active);
    }
    const status = guide.querySelector('[data-registration-entity-guide-status]');
    if (status) {
      status.textContent = selected === 'corporation'
        ? 'Corporation / Cooperative / Association selected: both documents (a) and (b) are required.'
        : selected === 'government'
        ? 'Government Agency / GOCC selected: authorization from the Head of Agency is required.'
        : selected === 'individual'
        ? 'Individual / Sole Proprietorship selected: existing registration and Valid ID requirements apply.'
        : 'Select your Applicant / Organization Type above to highlight the requirements that apply to you.';
    }
  }
  function render() {
    updateAlwaysVisibleGuide();
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
    hint.textContent='Choose the matching Document Category explicitly before uploading each file. The system will preserve that selected category in the LPDD-FUS review. Multiple uploads are allowed per category, up to 10 supporting files total.';
    info.append(hint);
  }
  type.addEventListener('change', render);
  supporting.addEventListener('change',render);
  form.addEventListener('registration:drafts-updated',render);
  // Browser-restored or sessionStorage-restored selection should also highlight.
  form.addEventListener('input',event => {
    if (event.target === type) render();
  });
  window.addEventListener('pageshow',render);
  form.querySelector('#registration-supporting-draft-list')?.addEventListener('click',()=>{
    // Saved list updates asynchronously; the event only re-renders the static checklist.
    window.requestAnimationFrame(render);
  });
  // Applicant must explicitly choose a document category for each newly uploaded file.
  render();
})();
