(() => {
  'use strict';
  const modal=document.getElementById('notice-pdf-preview-modal');
  const frame=modal?.querySelector('[data-notice-preview-frame]');
  const title=modal?.querySelector('#notice-pdf-preview-title');
  const loading=modal?.querySelector('[data-notice-preview-loading]');
  const open=modal?.querySelector('[data-notice-preview-open]');
  if(!modal||!frame||!title||!loading||!open)return;

  const reset=()=>{
    frame.removeAttribute('src');
    open.setAttribute('href','#');
    loading.classList.remove('is-hidden');
  };

  modal.addEventListener('show.bs.modal',event=>{
    const trigger=event.relatedTarget?.closest?.('[data-notice-preview-url]');
    const value=trigger?.dataset.noticePreviewUrl||'';
    if(!value)return;
    const url=new URL(value,location.href);
    if(url.origin!==location.origin)return;
    title.textContent=trigger.dataset.noticePreviewTitle||'Generated PDF Preview';
    open.href=url.href;
    loading.classList.remove('is-hidden');
    frame.src=url.href;
  });
  frame.addEventListener('load',()=>loading.classList.add('is-hidden'));
  modal.addEventListener('hidden.bs.modal',reset);

  const signedId=new URLSearchParams(location.search).get('preview_signed');
  if(signedId&&/^\d+$/.test(signedId)&&window.bootstrap?.Modal){
    const trigger=document.querySelector(`[data-notice-preview-app="${signedId}"]`);
    if(trigger){
      window.bootstrap.Modal.getOrCreateInstance(modal).show(trigger);
      const cleanUrl=new URL(location.href);
      cleanUrl.searchParams.delete('preview_signed');
      history.replaceState(history.state,'',cleanUrl.href);
    }
  }
})();
