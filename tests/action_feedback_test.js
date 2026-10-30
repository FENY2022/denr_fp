'use strict';
// DOM-free regression for the globally shared FP-OMS action loading handler.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(
  __dirname, '../public/assets/action-feedback.js'), 'utf8');

class Element {
  constructor(tag='BUTTON', attributes={}) {
    this.tagName = tag;
    this.attributes = {...attributes};
    this.dataset = {};
    this.children = [];
    this.disabled = false;
    this.value = attributes.value || '';
    this.textContent = attributes.text || '';
    this.listeners = {};
    this.classNames = new Set();
    this.classList = {
      add: (...names)=>names.forEach(n=>this.classNames.add(n)),
      remove: (...names)=>names.forEach(n=>this.classNames.delete(n)),
      contains: name=>this.classNames.has(name)
    };
  }
  setAttribute(name,value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name] ?? null; }
  hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes,name); }
  removeAttribute(name) { delete this.attributes[name]; }
  matches(selector) {
    if (selector.includes('.btn-close')) return this.classNames.has('btn-close');
    if (selector.includes('data-sidebar-toggle')) return this.hasAttribute('data-sidebar-toggle');
    if (selector.includes('button[type="submit"]')) {
      return this.tagName === 'BUTTON' && this.getAttribute('type') === 'submit';
    }
    if (selector.includes('input[type="submit"]')) {
      return this.tagName === 'INPUT' && this.getAttribute('type') === 'submit';
    }
    return false;
  }
  closest(selector) {
    if (selector === 'form') return this.parentForm || null;
    if (this.tagName === 'A') return selector.includes('a.') ? this : null;
    if (this.tagName === 'BUTTON') return selector.includes('button') ? this : null;
    if (this.tagName === 'INPUT') return selector.includes('input') ? this : null;
    return null;
  }
  append(...elements) { for (const child of elements) { child.parent=this; this.children.push(child); } }
  remove() {
    if (!this.parent) return;
    this.parent.children = this.parent.children.filter(child=>child!==this);
  }
  querySelector() { return this.submitButton || null; }
}
class Form extends Element {
  constructor(submitButton=null) {
    super('FORM');
    this.submitButton=submitButton;
    if (submitButton) submitButton.parentForm=this;
  }
}

const listeners={};
const timers=new Map();
let timerId=0;
const document={
  createElement(tag) { return new Element(tag.toUpperCase()); },
  addEventListener(type,fn,capture=false) {
    (listeners[type] ||= []).push({fn,capture});
  }
};
const windowListeners={};
const window={
  addEventListener(type,fn) { (windowListeners[type] ||= []).push(fn); }
};
vm.runInNewContext(source,{
  window,document,HTMLFormElement:Form,
  setTimeout(fn,ms) { const id=++timerId; timers.set(id,{fn,ms}); return id; },
  clearTimeout(id) { timers.delete(id); }
},{timeout:1500});

function event(target,params={}) {
  return {
    target,submitter:params.submitter||null,
    defaultPrevented:!!params.defaultPrevented,
    button:0,metaKey:false,ctrlKey:false,shiftKey:false,altKey:false,
    preventDefault() { this.defaultPrevented=true; },
    stopImmediatePropagation() { this.stopped=true; }
  };
}
function fire(type,e) {
  for(const {fn,capture} of (listeners[type]||[]).filter(x=>x.capture)) {
    fn(e); if(e.stopped)return;
  }
  for(const {fn,capture} of (listeners[type]||[]).filter(x=>!x.capture)) {
    fn(e); if(e.stopped)return;
  }
}
function runTimers() {
  const tasks=[...timers.values()];
  timers.clear();
  for(const item of tasks) item.fn();
}
const button=new Element('BUTTON',{type:'button',text:'Remove File'});
button.classList.add('btn');
fire('click',event(button));
assert.equal(button.getAttribute('aria-busy'),'true');
assert.equal(button.classList.contains('fpoms-action-locked'),true);
assert.equal(button.children.length,1);
assert.equal(button.children[0].children[1].textContent,'Removing…');
const duplicate=event(button);
fire('click',duplicate);
assert.equal(duplicate.defaultPrevented,true,'double click blocked');
assert.equal(duplicate.stopped,true,'duplicate does not reach action handler');
runTimers();
assert.equal(button.getAttribute('aria-busy'),null,'timer restores aria busy');
assert.equal(button.children.length,0,'spinner removed after completion');

const close=new Element('BUTTON',{type:'button',text:'Close'});
close.classList.add('btn-close');
fire('click',event(close));
assert.equal(close.classList.contains('fpoms-action-icon-only'),true);
runTimers();

const submit=new Element('BUTTON',{type:'submit',text:'Submit to LPDD-FUS'});
submit.classList.add('btn');
const form=new Form(submit);
fire('click',event(submit));
assert.equal(submit.getAttribute('aria-busy'),null,'do not lock submit on click before validation');
fire('submit',event(form,{submitter:submit}));
assert.equal(form.dataset.fpomsSubmitting,'1','accepted form submit enters busy state');
assert.equal(submit.getAttribute('aria-busy'),'true');
assert.equal(submit.children[0].children[1].textContent,'Submitting…');
const again=event(form,{submitter:submit});
fire('submit',again);
assert.equal(again.defaultPrevented,true,'second accepted submit blocked');
runTimers();
assert.equal(form.dataset.fpomsSubmitting,'0','timeout releases form if server hangs');
assert.equal(submit.getAttribute('aria-busy'),null);

const cancelled=new Element('BUTTON',{type:'submit',text:'Confirm Registration'});
const modalForm=new Form(cancelled);
fire('submit',event(modalForm,{submitter:cancelled,defaultPrevented:true}));
assert.notEqual(modalForm.dataset.fpomsSubmitting,'1','intercepted modal submit is not locked');
assert.equal(cancelled.children[0].children[1].textContent,'Preparing…');
runTimers();

const link=new Element('A',{href:'review?id=28',text:'Review'});
link.classList.add('btn');
fire('click',event(link));
assert.equal(link.getAttribute('aria-busy'),'true');
assert.equal([...timers.values()][0].ms,12000,'navigation has safety timeout');
runTimers();

const preview=new Element('A',{href:'download_reg?id=20',text:'Preview'});
preview.classList.add('btn');
preview.setAttribute('data-document-preview','');
fire('click',event(preview,{defaultPrevented:true}));
assert.equal([...timers.values()][0].ms,750,'intercepted preview never stays locked');
runTimers();

const ajax=new Element('BUTTON',{type:'button',text:'Clear Form'});
fire('click',event(ajax));
window.FPOMSActionFeedback.stop(ajax);
window.FPOMSActionFeedback.start(ajax,'Clearing…',{lock:true,duration:15000});
assert.equal([...timers.values()][0].ms,15000);
window.FPOMSActionFeedback.stop(ajax);
assert.equal(ajax.classList.contains('fpoms-action-locked'),false);

const input=new Element('INPUT',{type:'button',value:'Search'});
fire('click',event(input));
assert.equal(input.value,'Searching…','input buttons display loading without child elements');
runTimers();
assert.equal(input.value,'Search','input button value restored');

windowListeners.pageshow[0]({});
assert.equal(timers.size,0);
console.log('PASS: FP-OMS button spinner, action labels, submissions, double-clicks, navigation, modals and reset.');
