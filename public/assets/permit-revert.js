(() => {
  'use strict';
  const counter = document.querySelector('[data-step2-revert-countdown]');
  const trigger = document.querySelector('[data-step2-revert-trigger]');
  const confirm = document.querySelector('[data-step2-revert-submit]');
  const expired = document.querySelector('[data-step2-revert-expired]');
  if (!counter || !trigger || !confirm) return;
  let remaining = Math.max(0,Math.floor(Number(counter.dataset.seconds || 0)));
  const deadline = Date.now() + remaining * 1000;
  const fmt = value => {
    const minutes = Math.floor(value / 60);
    const seconds = value % 60;
    return String(minutes).padStart(2,'0') + ':' + String(seconds).padStart(2,'0');
  };
  function update() {
    // Absolute deadline prevents interval drift when the browser is inactive.
    remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
    counter.textContent = fmt(remaining);
    if (remaining === 0) {
      trigger.disabled = true;
      confirm.disabled = true;
      expired?.classList.remove('d-none');
    }
  }
  update();
  const interval = setInterval(update,1000);
  window.addEventListener('pagehide', () => clearInterval(interval), {once:true});
  window.addEventListener('pageshow', event => {
    // A restored bfcache may have out-of-date eligibility: never enable it.
    if (event.persisted) {
      trigger.disabled = true;
      confirm.disabled = true;
      expired?.classList.remove('d-none');
    }
  });
})();
