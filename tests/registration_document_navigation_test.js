'use strict';

// DB-free DOM regression for Previous / Next reviewer document preview.
// Run with: node tests/registration_document_navigation_test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const code = fs.readFileSync(
  path.resolve(__dirname, '../public/assets/registration-document-preview.js'), 'utf8');

function element(tag = 'DIV') {
  const activeClasses = new Set();
  const handlers = {};
  return {
    tagName: tag,
    dataset: {},
    children: [],
    disabled: false,
    textContent: '',
    href: '',
    src: '',
    classList: {
      add(value) { activeClasses.add(value); },
      remove(value) { activeClasses.delete(value); },
      contains(value) { return activeClasses.has(value); }
    },
    addEventListener(name, callback) { (handlers[name] ??= []).push(callback); },
    dispatch(name) {
      const event = {
        defaultPrevented: false,
        preventDefault() { this.defaultPrevented = true; },
        target: this
      };
      for (const handler of handlers[name] || []) handler(event);
      return event;
    },
    replaceChildren(...items) { this.children = [...items]; },
    setAttribute(name, value) { this[name] = value; },
    querySelector() { return null; },
    querySelectorAll() { return []; }
  };
}

function run(total) {
  const modal = element();
  const preview = element();
  const title = element();
  const download = element('A');
  const error = element();
  const previous = element('BUTTON');
  const next = element('BUTTON');
  const counter = element();
  const zoomButtons = ['out', 'reset', 'in'].map(action => {
    const button = element('BUTTON');
    button.dataset.documentZoom = action;
    return button;
  });
  const controls = {
    '[data-document-previous]': previous,
    '[data-document-next]': next,
    '[data-document-counter]': counter
  };
  modal.querySelector = sel => controls[sel] || null;
  modal.querySelectorAll = selector => selector === '[data-document-zoom]' ? zoomButtons : [];

  const links = Array.from({ length: total }, (_, index) => {
    const link = element('A');
    link.dataset.documentMime = index % 3 === 0 ? 'application/pdf'
      : (index % 3 === 1 ? 'image/png' : 'image/jpeg');
    link.dataset.documentName = 'Client document ' + (index + 1) + '.pdf';
    link.href = 'http://localhost/denr_fp/denr_fp/public/download_reg'
      + '?id=' + (index + 56) + '&preview=1';
    return link;
  });
  const byId = {
    'registration-document-modal': modal,
    'registration-document-preview': preview,
    'registration-document-modal-title': title,
    'registration-document-download': download,
    'registration-document-error': error
  };
  let shows = 0;
  const browserDocument = {
    getElementById(id) { return byId[id] || null; },
    querySelectorAll(sel) { return sel === '[data-document-preview]' ? links : []; },
    createElement(tag) { return element(tag.toUpperCase()); }
  };
  vm.runInNewContext(code, {
    document: browserDocument,
    window: { bootstrap: { Modal: { getOrCreateInstance() {
      return { show() { shows++; } };
    } } } },
    URL,
    location: { href: 'http://localhost/denr_fp/denr_fp/public/review?id=28' }
  }, { timeout: 1500 });

  return { modal, preview, title, download, previous, next, counter, links,
    zoomButtons, get shows() { return shows; } };
}

const ui = run(10);
assert.equal(ui.counter.textContent, '0 / 10', 'closed preview does not pick an arbitrary document');
assert.equal(ui.previous.disabled, true);
assert.equal(ui.next.disabled, true);

assert.equal(ui.links[2].dispatch('click').defaultPrevented, true);
assert.equal(ui.shows, 1, 'opening a file displays modal exactly once');
assert.equal(ui.counter.textContent, '3 / 10');
assert.equal(ui.title.textContent, 'Client document 3.pdf');
assert.equal(ui.previous.disabled, false);
assert.equal(ui.next.disabled, false);
assert.equal(ui.preview.children[0].tagName, 'IMG');
assert.equal(new URL(ui.download.href).searchParams.get('id'), '58');
assert.equal(new URL(ui.download.href).searchParams.has('preview'), false);

ui.next.dispatch('click');
assert.equal(ui.counter.textContent, '4 / 10', 'Next advances to another uploaded file');
assert.equal(ui.title.textContent, 'Client document 4.pdf', 'filename advances with document');
assert.equal(ui.preview.children[0].tagName, 'IFRAME', 'PDF and image navigations work');
assert.equal(new URL(ui.download.href).searchParams.get('id'), '59');
assert.equal(ui.shows, 1, 'navigating does not close/reopen modal');

ui.zoomButtons[2].dispatch('click');
assert.equal(ui.preview.children[0].classList.contains('registration-document-zoom-125'), true);
ui.next.dispatch('click');
assert.equal(ui.preview.children[0].classList.contains('registration-document-zoom-100'), true,
  'zoom resets for next document');
ui.previous.dispatch('click');
assert.equal(ui.counter.textContent, '4 / 10', 'Previous returns to prior document');
ui.links[9].dispatch('click');
assert.equal(ui.counter.textContent, '10 / 10');
assert.equal(ui.next.disabled, true, 'Next disabled on last file');
ui.next.dispatch('click');
assert.equal(ui.counter.textContent, '10 / 10', 'Next does not wrap');
ui.links[0].dispatch('click');
assert.equal(ui.counter.textContent, '1 / 10');
assert.equal(ui.previous.disabled, true, 'Previous disabled on first file');
ui.previous.dispatch('click');
assert.equal(ui.counter.textContent, '1 / 10', 'Previous does not wrap');
ui.modal.dispatch('hidden.bs.modal');
assert.equal(ui.counter.textContent, '0 / 10', 'counter resets when modal closes');
assert.equal(ui.preview.children.length, 0, 'prior PDF iframe removed on close');
assert.equal(ui.previous.disabled, true);
assert.equal(ui.next.disabled, true);

const one = run(1);
one.links[0].dispatch('click');
assert.equal(one.counter.textContent, '1 / 1');
assert.equal(one.previous.disabled, true);
assert.equal(one.next.disabled, true);
const empty = run(0);
assert.equal(empty.counter.textContent, '0 / 0');
assert.equal(empty.previous.disabled, true);
assert.equal(empty.next.disabled, true);

console.log('PASS: Document navigation, 3/10 counter, PDF/image switching, download links, zoom and boundaries.');
