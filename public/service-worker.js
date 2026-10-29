'use strict';
// Cache public static assets only. Authenticated pages, uploads, API responses,
// form posts and user documents must never enter the service worker cache.
const CACHE_NAME = 'fp-oms-static-v23';
const ASSETS = ['./assets/style.css?v=21', './assets/landing.css', './assets/app-shell.js?v=20', './assets/fpoms-icon-192.png?v=23', './assets/fpoms-icon-512.png?v=23', './manifest.webmanifest?v=23'];
self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS)).catch(() => undefined));
  self.skipWaiting();
});
self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k.startsWith('fp-oms-static-') && k !== CACHE_NAME).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET' || request.mode === 'navigate' || request.destination === 'document') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  const pathname = url.pathname;
  if (!(pathname.endsWith('/assets/style.css') || pathname.endsWith('/assets/landing.css') || pathname.endsWith('/assets/app-shell.js') || pathname.endsWith('/assets/fpoms-icon-192.png') || pathname.endsWith('/assets/fpoms-icon-512.png') || pathname.endsWith('/manifest.webmanifest'))) return;
  event.respondWith(caches.match(request).then(cached => {
    const network = fetch(request).then(response => {
      if (response.ok) {const copy = response.clone(); caches.open(CACHE_NAME).then(cache => cache.put(request, copy)).catch(() => {});}
      return response;
    }).catch(() => cached);
    return cached || network;
  }));
});
