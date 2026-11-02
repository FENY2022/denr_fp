'use strict';
(function () {
  const node = document.getElementById('fpoms-pusher-config');
  const status = document.getElementById('realtime-browser-status');
  const setStatus = function (value, tone) {
    if (!status) return;
    status.textContent = value;
    status.className = tone ? 'text-' + tone : 'text-secondary';
  };
  if (!node) {
    setStatus('Disabled — enable and save settings first.', 'secondary');
    return;
  }
  if (typeof Pusher === 'undefined') {
    setStatus('Pusher JavaScript unavailable. Check script loading/CSP.', 'danger');
    return;
  }
  let cfg;
  try { cfg = JSON.parse(node.textContent); } catch (_) {
    setStatus('Invalid browser configuration.', 'danger');
    return;
  }
  if (!cfg.key || !cfg.channel || !cfg.authEndpoint || !cfg.csrf) {
    setStatus('Missing browser configuration.', 'danger');
    return;
  }
  const pusher = new Pusher(cfg.key, {
    cluster: cfg.cluster,
    channelAuthorization: {
      transport: 'ajax',
      endpoint: cfg.authEndpoint,
      params: { csrf_token: cfg.csrf }
    }
  });
  setStatus('Connecting to Pusher...', 'secondary');
  pusher.connection.bind('connected', function () {
    setStatus('Connected; authorizing private channel...', 'secondary');
  });
  pusher.connection.bind('disconnected', function () {
    setStatus('Disconnected. Reconnecting...', 'warning');
  });
  pusher.connection.bind('error', function () {
    setStatus('Connection error. Check firewall/CSP/network.', 'danger');
  });
  const channel = pusher.subscribe(cfg.channel);
  channel.bind('pusher:subscription_succeeded', function () {
    setStatus('Connected and subscribed (private channel).', 'success');
  });
  channel.bind('pusher:subscription_error', function () {
    setStatus('Private channel authorization failed. Check session/CSRF.', 'danger');
  });
  channel.bind('fpoms-connection-test', function () {
    setStatus('Test event received in this browser.', 'success');
  });

  // Only the trusted application's session-protected endpoint supplies HTML.
  // Private Pusher events carry no account, applicant or document contents.
  let refreshing = false;
  channel.bind('notification-created', function () {
    if (refreshing || !cfg.notificationsEndpoint) return;
    refreshing = true;
    fetch(cfg.notificationsEndpoint, {
      method: 'GET', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      if (!response.ok) throw new Error('Notification refresh failed');
      return response.json();
    }).then(function (payload) {
      if (!payload || typeof payload.html !== 'string') return;
      const parsed = new DOMParser().parseFromString(payload.html, 'text/html');
      const updated = parsed.querySelector('.notification-dropdown');
      const existing = document.querySelector('.notification-dropdown');
      if (updated && existing) existing.replaceWith(document.importNode(updated, true));
      document.dispatchEvent(new CustomEvent('fpoms:notification-received'));
    }).catch(function () {
      // A temporary refresh error cannot lose the stored notification.
      setStatus('Notification received; refresh the page to view it.', 'warning');
    }).finally(function () {
      refreshing = false;
    });
  });
  window.addEventListener('pagehide', function () {
    pusher.disconnect();
  }, { once: true });
}());
