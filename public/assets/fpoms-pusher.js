'use strict';
(function () {
  const node = document.getElementById('fpoms-pusher-config');
  const status = document.getElementById('realtime-browser-status');
  const testForm = document.getElementById('realtime-test-form');
  const testResult = document.getElementById('realtime-test-result');
  const setStatus = function (value, tone) {
    if (!status) return;
    status.textContent = value;
    status.className = tone ? 'text-' + tone : 'text-secondary';
  };
  const setTestResult = function (value, tone) {
    if (!testResult) return;
    testResult.textContent = value;
    testResult.className = 'small mt-2 ' + (tone ? 'text-' + tone : 'text-secondary');
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

  let subscribed = false;
  let testEventReceived = false;
  let testReceiptTimer = null;
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
    subscribed = false;
    setStatus('Disconnected. Reconnecting...', 'warning');
  });
  pusher.connection.bind('error', function () {
    subscribed = false;
    setStatus('Connection error. Check firewall/CSP/network.', 'danger');
  });

  const channel = pusher.subscribe(cfg.channel);
  channel.bind('pusher:subscription_succeeded', function () {
    subscribed = true;
    setStatus('Connected and subscribed (private channel).', 'success');
  });
  channel.bind('pusher:subscription_error', function () {
    subscribed = false;
    setStatus('Private channel authorization failed. Check session/CSRF.', 'danger');
  });
  channel.bind('fpoms-connection-test', function () {
    testEventReceived = true;
    if (testReceiptTimer) {
      window.clearTimeout(testReceiptTimer);
      testReceiptTimer = null;
    }
    setStatus('Test event received in this browser.', 'success');
    setTestResult('Browser received the Pusher test event successfully.', 'success');
  });

  if (testForm) {
    testForm.addEventListener('submit', function (event) {
      if (!subscribed) {
        event.preventDefault();
        setTestResult('Wait until Browser subscription shows Connected and subscribed, then test again.', 'warning');
        return;
      }
      event.preventDefault();
      testEventReceived = false;
      if (testReceiptTimer) window.clearTimeout(testReceiptTimer);
      const button = testForm.querySelector('button[type="submit"]');
      if (button) button.disabled = true;
      setTestResult('Sending signed Pusher test event...', 'secondary');

      fetch(testForm.action, {
        method: 'POST',
        body: new FormData(testForm),
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      }).then(function (response) {
        if (!response.ok) throw new Error('Pusher test request failed');
        return response.json();
      }).then(function (payload) {
        if (!payload || typeof payload.message !== 'string') {
          throw new Error('Invalid Pusher test response');
        }
        setTestResult(payload.message, payload.success ? 'success' : 'danger');
        if (payload.success && !testEventReceived) {
          testReceiptTimer = window.setTimeout(function () {
            if (!testEventReceived) {
              setTestResult('Pusher API accepted the event, but this browser did not receive it. Check private-channel authorization, WebSocket/CSP, App Key and Cluster.', 'warning');
            }
          }, 4000);
        }
      }).catch(function () {
        setTestResult('Connection test failed before a valid response was received.', 'danger');
      }).finally(function () {
        if (button) button.disabled = false;
      });
    });
  }

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

  channel.bind('chat-message', function (payload) {
    document.dispatchEvent(new CustomEvent('fpoms:chat-message', {
      detail: payload && typeof payload === 'object' ? payload : {}
    }));
  });

  channel.bind('chat-typing', function (payload) {
    document.dispatchEvent(new CustomEvent('fpoms:chat-typing', {
      detail: payload && typeof payload === 'object' ? payload : {}
    }));
  });

  window.addEventListener('pagehide', function () {
    pusher.disconnect();
  }, { once: true });
}());
