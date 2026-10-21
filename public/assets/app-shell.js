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

  // FP-OMS global page loader: shared by landing, public, and authenticated pages.
  const loader = document.createElement('div');
  loader.id = 'fpomsPageLoader';
  loader.className = 'page-loader';
  loader.setAttribute('aria-hidden', 'true');
  loader.innerHTML = '<div class="page-loader-panel" role="status" aria-live="polite">'
    + '<img class="page-loader-logo" src="assets/fpoms-icon.svg" alt="">'
    + '<div class="page-loader-brand">DENR FP-OMS</div>'
    + '<div class="page-loader-subtitle">Feasibility Permit Online Management System</div>'
    + '<span class="page-loader-spinner" aria-hidden="true"></span>'
    + '<div class="page-loader-message">Loading DENR FP-OMS...</div>'
    + '<div class="page-loader-help">Please wait</div></div>';
  document.body.appendChild(loader);

  let loaderVisible = false;
  const showPageLoader = () => {
    loaderVisible = true;
    loader.classList.add('is-visible');
    loader.setAttribute('aria-hidden', 'false');
    document.body.classList.add('page-loading');
  };
  const hidePageLoader = () => {
    loaderVisible = false;
    loader.classList.remove('is-visible');
    loader.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('page-loading');
  };

  showPageLoader();
  const finishInitialLoad = () => requestAnimationFrame(() => requestAnimationFrame(hidePageLoader));
  if (document.readyState === 'complete') finishInitialLoad();
  else window.addEventListener('load', finishInitialLoad, {once:true});

  document.addEventListener('click', event => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('a[href]');
    if (!link || link.target === '_blank' || link.hasAttribute('download')) return;
    const rawHref = link.getAttribute('href') || '';
    if (!rawHref || rawHref.startsWith('#') || rawHref.startsWith('javascript:')) return;
    let target;
    try { target = new URL(link.href, window.location.href); } catch (_) { return; }
    if (target.origin !== window.location.origin) return;
    if (target.pathname === window.location.pathname && target.search === window.location.search && target.hash) return;
    const path = target.pathname.toLowerCase();
    if (path.includes('/download-reg') || path.includes('/download_reg')) return;
    showPageLoader();
  }, true);

  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || (form.target && form.target !== '_self')) return;
    showPageLoader();
    setTimeout(() => { if (event.defaultPrevented && loaderVisible) hidePageLoader(); }, 0);
  }, true);

  window.addEventListener('beforeunload', showPageLoader);
  window.addEventListener('pageshow', event => {
    if (event.persisted) hidePageLoader();
  });


  const generateAdminPassword = document.getElementById('generateAdminPassword');
  const adminPassword = document.getElementById('password');
  const adminPasswordConfirm = document.getElementById('confirm_password');
  const generatedPasswordNotice = document.getElementById('generatedPasswordNotice');
  generateAdminPassword?.addEventListener('click', () => {
    if (!adminPassword || !adminPasswordConfirm || !generatedPasswordNotice || !window.crypto?.getRandomValues) return;
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    const bytes = new Uint32Array(18);
    window.crypto.getRandomValues(bytes);
    let value = '';
    for (const item of bytes) value += alphabet[item % alphabet.length];
    adminPassword.value = value;
    adminPasswordConfirm.value = value;
    generatedPasswordNotice.textContent = 'A secure temporary password was generated and filled into both password fields. Copy it now and share it only through an approved secure channel.';
    generatedPasswordNotice.classList.remove('d-none');
    adminPassword.focus();
    adminPassword.select();
  });

})();
