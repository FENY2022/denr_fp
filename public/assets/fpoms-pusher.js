'use strict';
(function () {
  const node = document.getElementById('fpoms-pusher-config');
  if (!node || typeof Pusher === 'undefined') return;
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (_) { return; }
  if (!cfg.key || !cfg.channel || !cfg.authEndpoint || !cfg.csrf) return;
  const pusher = new Pusher(cfg.key, {
    cluster: cfg.cluster,
    channelAuthorization: {
      transport: 'ajax',
      endpoint: cfg.authEndpoint,
      params: { csrf_token: cfg.csrf }
    }
  });
  const channel = pusher.subscribe(cfg.channel);
  let busy = false;
  channel.bind('notification-created', function () {
    if (busy) return;
    busy = true;
    // Refresh from the authoritative database; no private notification data
    // is sent over Pusher or injected into the DOM.
    window.location.reload();
  });
  window.addEventListener('pagehide', function () { pusher.disconnect(); }, { once: true });
}());
