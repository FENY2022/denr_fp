(() => {
  'use strict';
  const html = document.documentElement;
  const safeStorage = (() => { try { return window.localStorage; } catch (_) { return null; } })();
  const sizeControl = document.getElementById('textSizeSelect');
  const sizeKey = 'fpoms.ui.textSize';
  const allowed = ['small', 'medium', 'large', 'xl', 'xxl', 'xxxl'];
  const applyTextSize = (size) => {
    const value = allowed.includes(size) ? size : 'medium';
    html.dataset.textSize = value;
    if (sizeControl) sizeControl.value = value;
    safeStorage?.setItem(sizeKey, value);
  };
  applyTextSize(safeStorage?.getItem(sizeKey) || 'medium');
  sizeControl?.addEventListener('change', () => applyTextSize(sizeControl.value));

  const collapseButton = document.querySelector('[data-sidebar-toggle]');
  const collapsedKey = 'fpoms.sidebar.collapsed';
  const setCollapsed = (collapsed) => {
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    if (collapseButton) {
      collapseButton.setAttribute('aria-expanded', String(!collapsed));
      collapseButton.setAttribute('aria-label', collapsed ? 'Expand navigation sidebar' : 'Collapse navigation sidebar');
      collapseButton.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }
    safeStorage?.setItem(collapsedKey, collapsed ? '1' : '0');
  };
  if (collapseButton) {
    setCollapsed(safeStorage?.getItem(collapsedKey) === '1');
    collapseButton.addEventListener('click', () => setCollapsed(!document.body.classList.contains('sidebar-collapsed')));
  }
  const sidebarScroll = document.querySelector('#appSidebar .sidebar-scroll');
  const scrollKey = 'fpoms.sidebar.scroll';
  if (sidebarScroll && safeStorage) {
    const previous = Number(safeStorage.getItem(scrollKey) || 0);
    if (Number.isFinite(previous) && previous >= 0) sidebarScroll.scrollTop = previous;
    sidebarScroll.addEventListener('scroll', () => safeStorage.setItem(scrollKey, String(sidebarScroll.scrollTop)), {passive:true});
  }

  if ('serviceWorker' in navigator && (window.isSecureContext || location.hostname === 'localhost')) {
    window.addEventListener('load', () => navigator.serviceWorker.register('service-worker.js', {scope:'./'}).catch(() => {}));
  }
  const installButton = document.getElementById('installAppButton');
  const installHelp = document.getElementById('installAppHelp');
  let installEvent = null;
  const help = (message) => { if (installHelp) installHelp.textContent = message; };
  const markInstalled = () => {
    if (installButton) { installButton.disabled = true; installButton.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Installed'; }
    help('FP-OMS is installed. Find it on your desktop, Start menu, or home screen.');
  };
  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();installEvent = event;
    if (installButton) installButton.disabled = false;
  });
  window.addEventListener('appinstalled', () => { installEvent = null; markInstalled(); });
  if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) markInstalled();
  installButton?.addEventListener('click', async () => {
    if (installEvent) {
      const event = installEvent; installEvent = null;
      try { await event.prompt(); const answer = await event.userChoice;
        help(answer?.outcome === 'accepted' ? 'Installation requested. Follow your browser instructions.' : 'Installation dismissed. You can try again from the browser menu.');
      } catch (_) {help('Use your browser menu and choose Install app or Add to Home Screen.');}
      return;
    }
    const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
    help(ios ? 'On iPhone/iPad, tap Share and choose Add to Home Screen.' : 'Open your browser menu and select Install app or Add to Home Screen. Chrome and Edge usually require HTTPS (or localhost).');
  });
})();
