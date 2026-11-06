(() => {
  'use strict';
  const frame=document.getElementById('flowFileFrame');
  const panel=document.getElementById('flowFilePrintPanel');
  const openPrintPanel=()=>{ panel?.classList.add('is-open'); panel?.setAttribute('aria-hidden','false'); };
  const closePrintPanel=()=>{ panel?.classList.remove('is-open'); panel?.setAttribute('aria-hidden','true'); };

  document.getElementById('flowFileBack')?.addEventListener('click',()=>{
    if(history.length>1) history.back(); else window.close();
  });

  document.getElementById('flowFilePrint')?.addEventListener('click',openPrintPanel);
  document.getElementById('flowFilePrintCancel')?.addEventListener('click',closePrintPanel);
  panel?.addEventListener('click',event=>{ if(event.target===panel) closePrintPanel(); });
  document.addEventListener('keydown',event=>{ if(event.key==='Escape') closePrintPanel(); });

  document.getElementById('flowFilePrintNow')?.addEventListener('click',()=>{
    if(!frame) return;
    closePrintPanel();
    try {
      frame.contentWindow?.focus();
      frame.contentWindow?.print();
    } catch (_) {
      window.open(frame.src,'_blank','noopener');
    }
  });
})();