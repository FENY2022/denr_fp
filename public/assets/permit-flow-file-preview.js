(() => {
  'use strict';
  const frame=document.getElementById('flowFileFrame');
  document.getElementById('flowFileBack')?.addEventListener('click',()=>{
    if(history.length>1) history.back(); else window.close();
  });
  document.getElementById('flowFilePrint')?.addEventListener('click',()=>{
    if(!frame) return;
    try {
      frame.contentWindow?.focus();
      frame.contentWindow?.print();
    } catch (_) {
      window.open(frame.src,'_blank','noopener');
    }
  });
})();