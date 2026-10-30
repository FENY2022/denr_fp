'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const script = fs.readFileSync(__dirname + '/../public/assets/permit-case-modals.js', 'utf8');

function makePage(selection) {
  class Element {}
  class ModalNode extends Element {
    constructor(id) {
      super();
      this.id = id;
      this.handlers = {};
      this.visible = false;
      this.classList = { contains: className => className === 'show' && this.visible };
    }
    addEventListener(type, fn) {
      (this.handlers[type] ??= []).push(fn);
    }
    dispatch(type, event = {}) {
      for (const fn of [...(this.handlers[type] || [])]) fn(event);
    }
    contains(link) {
      return link === documentLink;
    }
  }
  const files = new ModalNode('fp-files-modal');
  const audit = new ModalNode('fp-audit-trail-modal');
  const preview = new ModalNode('registration-document-modal');
  const counters = { files: 0, audit: 0, preview: 0 };
  const lookup = {
    'fp-files-modal': files,
    'fp-audit-trail-modal': audit,
    'registration-document-modal': preview
  };
  const documentLink = new Element();
  documentLink.closest = selector =>
    selector === 'a[data-document-preview]' ? documentLink : null;
  documentLink.click = () => {
    counters.preview++;
    preview.visible = true;
  };
  const modal = { getOrCreateInstance(node) {
    return {
      show() {
        node.visible = true;
        if (node === files) counters.files++;
        if (node === audit) counters.audit++;
      },
      hide() {
        node.visible = false;
        node.dispatch('hidden.bs.modal');
      }
    };
  }};
  vm.runInNewContext(script, {
    Element,
    URLSearchParams,
    window: { bootstrap: { Modal: modal }, location: { search: '?view=' + selection } },
    document: { getElementById: id => lookup[id] ?? null }
  }, { filename: 'permit-case-modals.js' });
  return { files, audit, preview, documentLink, counters };
}

const audit = makePage('audit');
assert.equal(audit.audit.visible, true, 'audit direct link shows only audit modal');
assert.equal(audit.files.visible, false, 'audit direct link does not show files');
const files = makePage('files');
assert.equal(files.files.visible, true, 'files direct link shows file list');
assert.equal(files.audit.visible, false, 'files direct link does not show audit');
let prevented = false, intercepted = false;
files.files.dispatch('click', {
  target: files.documentLink,
  preventDefault() { prevented = true; },
  stopImmediatePropagation() { intercepted = true; }
});
assert.equal(prevented, true, 'file click handled without navigation');
assert.equal(intercepted, true, 'file click never opens stacked modals');
assert.equal(files.files.visible, false, 'file list closes before preview');
assert.equal(files.counters.preview, 1, 'existing viewer receives one link click');
files.preview.dispatch('hidden.bs.modal');
assert.equal(files.files.visible, true, 'closing preview returns to files list');
assert.equal(makePage('bogus').counters.files, 0, 'unknown view ignored');
assert.equal(makePage('bogus').counters.audit, 0, 'unknown view does not show audit');

const registrationScript = fs.readFileSync(
  __dirname + '/../public/assets/registration-action-audit.js', 'utf8'
);
function registrationAuditOpens(view) {
  let opened = 0;
  const audit = { id: 'registration-action-audit-modal' };
  vm.runInNewContext(registrationScript, {
    URLSearchParams,
    document: { getElementById(id) {
      return id === 'registration-action-audit-modal' ? audit : null;
    }},
    window: {
      location: { search: '?view=' + view },
      bootstrap: { Modal: { getOrCreateInstance(element) {
        assert.equal(element, audit, 'only registration history modal may open');
        return { show() { opened++; } };
      } }}
    }
  }, {filename:'registration-action-audit.js'});
  return opened;
}
assert.equal(registrationAuditOpens('audit'), 1,
  'registration View Audit Trail deep link opens review audit modal');
assert.equal(registrationAuditOpens('files'), 0,
  'registration View Files never opens review audit modal');
assert.equal(registrationAuditOpens('other'), 0,
  'unexpected view query does not open audit');

console.log('Permit files / audit modal navigation checks passed');
