'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

// No browser, database or dependencies: stub Bootstrap and modal events.
const code = fs.readFileSync(__dirname+'/../public/assets/permit-pdf-choice.js','utf8');
const buttons = {};
function button(name) {
  const callbacks = {};
  const node = {
    textContent:'',
    addEventListener(event,fn){ callbacks[event]=fn; },
    click(){callbacks.click?.();}
  };
  buttons[name]=node;
  return node;
}
const label = {textContent:''}, info={textContent:''};
const no = button('no'), yes=button('yes'), cancel=button('cancel'), close=button('close');
const callbacks = {};
const element = {
  querySelector(selector) {
    return ({
      '[data-pdf-choice-filename]':label,
      '[data-pdf-choice-mode-note]':info,
      '[data-pdf-choice-no]':no,
      '[data-pdf-choice-yes]':yes
    })[selector] || null;
  },
  querySelectorAll(selector){return selector==='[data-pdf-choice-cancel]'?[cancel,close]:[];},
  addEventListener(event,fn){callbacks[event]=fn;}
};
const modal = {
  show(){this.visible=true;},
  hide(){this.visible=false;callbacks['hidden.bs.modal']?.();}
};
let edits = 0;
const edited = {name:'final.pdf',type:'application/pdf'};
const window = {
  bootstrap:{Modal:{getOrCreateInstance(){return modal;}}},
  FPOMSOrganizePdf:async file=>{edits++;return edited;}
};
vm.runInNewContext(code,{window,document:{getElementById(){return element;}},Promise,Error});
const pdf = {name:'original.pdf',type:'application/pdf'};
async function check() {
  let pending=window.FPOMSChoosePdfUpload(pdf,5242880,'saved');
  assert.equal(label.textContent,pdf.name);
  assert.equal(no.textContent,'No, Upload Original PDF');
  assert.equal(edits,0,'PDF editor must not start before user confirms');
  no.click();
  assert.equal(await pending,pdf,'No returns unchanged File');
  assert.equal(edits,0,'No must not run editor');

  pending=window.FPOMSChoosePdfUpload(pdf,5242880,'saved');
  yes.click();
  assert.equal(await pending,edited,'Yes routes to editor');
  assert.equal(edits,1);

  pending=window.FPOMSChoosePdfUpload(pdf,5242880,'saved');
  close.click();
  assert.equal(await pending,null,'X / Cancel skips upload');

  pending=window.FPOMSChoosePdfUpload(pdf,5242880,'direct');
  assert.equal(no.textContent,'No, Use Original PDF');
  assert.match(info.textContent,/final form|submit the application/i);
  no.click();
  assert.equal(await pending,pdf,'fallback No preserves original for later submission');

  pending=window.FPOMSChoosePdfUpload(pdf,5242880,'saved');
  await assert.rejects(
    window.FPOMSChoosePdfUpload(pdf,5242880,'saved'),
    /Finish the current PDF choice/
  );
  cancel.click();
  assert.equal(await pending,null,'Cancel skips upload');

  await assert.rejects(
    window.FPOMSChoosePdfUpload({name:'not-pdf.png',type:'image/png'},5242880),
    /Only PDF/
  );
  console.log('FP-OMS PDF choice Yes/No/Cancel tests passed.');
}
check().catch(error=>{console.error(error);process.exitCode=1;});
