(() => {
  'use strict';
  const shell=document.getElementById('noticePreviewShell');
  const page=document.getElementById('noticeA4Page');
  const pageBody=document.getElementById('noticePageBody');
  const content=document.getElementById('noticeA4Content');
  const printButton=document.getElementById('noticePrintButton');
  const backButton=document.getElementById('noticeBackButton');
  if(!shell||!page||!pageBody||!content)return;
  let resizeTimer=null;
  function fitContentToA4(){
    page.style.setProperty('--content-scale','1');
    requestAnimationFrame(()=>{
      const h=pageBody.clientHeight,w=pageBody.clientWidth;
      const nh=content.scrollHeight,nw=content.scrollWidth;
      let scale=Math.min(1,h>0?h/nh:1,w>0?w/nw:1);
      if(!Number.isFinite(scale)||scale<=0)scale=1;
      page.style.setProperty('--content-scale',scale.toFixed(4));
    });
  }
  function fitPreviewToViewport(){
    const vw=Math.max(240,document.documentElement.clientWidth||window.innerWidth||240);
    const gap=vw<=700?12:28;
    const available=Math.max(220,vw-gap);
    const physical=page.offsetWidth||1;
    let scale=Math.min(1,available/physical);
    if(!Number.isFinite(scale)||scale<=0)scale=1;
    page.style.setProperty('--preview-scale',scale.toFixed(4));
    shell.style.height=Math.ceil(page.offsetHeight*scale+(vw<=700?16:28))+'px';
    shell.style.minHeight=shell.style.height;
  }
  function refit(){fitContentToA4();requestAnimationFrame(fitPreviewToViewport)}
  printButton?.addEventListener('click',()=>window.print());
  backButton?.addEventListener('click',()=>{if(history.length>1)history.back();else window.close()});
  window.addEventListener('load',refit);
  window.addEventListener('resize',()=>{clearTimeout(resizeTimer);resizeTimer=setTimeout(refit,100)});
  window.addEventListener('beforeprint',fitContentToA4);
  window.addEventListener('afterprint',refit);
  if(document.fonts?.ready)document.fonts.ready.then(refit).catch(()=>{});
})();