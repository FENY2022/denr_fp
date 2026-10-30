(() => {
  'use strict';
  const counter = document.querySelector('[data-step2-revert-countdown]');
  const trigger = document.querySelector('[data-step2-revert-trigger]');
  const confirm = document.querySelector('[data-step2-revert-submit]');
  const expired = document.querySelector('[data-step2-revert-expired]');
  const remarks = document.querySelector('#step2_revert_remarks');
  const suggestions = document.querySelector('[data-step2-revert-suggestions]');
  if (!counter || !trigger || !confirm) return;
  if (remarks && suggestions) {
    const options = [...suggestions.querySelectorAll('[role="option"]')];
    let active = -1;
    const visibleOptions = () => options.filter(option => !option.classList.contains('d-none'));
    const setActive = index => {
      const visible = visibleOptions();
      active = index === -1 || !visible.length ? -1 : (index + visible.length) % visible.length;
      options.forEach(option => {
        const selected = option === visible[active];
        option.classList.toggle('active',selected);
        option.setAttribute('aria-selected',selected ? 'true' : 'false');
      });
    };
    const filterSuggestions = () => {
      const query = remarks.value.trim().toLowerCase();
      let matches = 0;
      options.forEach(option => {
        const match = query === '' || option.textContent.toLowerCase().includes(query);
        option.classList.toggle('d-none',!match);
        if (match) matches++;
      });
      suggestions.classList.toggle('d-none',matches === 0);
      remarks.setAttribute('aria-expanded',matches > 0 ? 'true' : 'false');
      setActive(-1);
    };
    const choose = option => {
      remarks.value = option.textContent.trim();
      suggestions.classList.add('d-none');
      remarks.setAttribute('aria-expanded','false');
      remarks.focus();
    };
    remarks.addEventListener('focus',filterSuggestions);
    remarks.addEventListener('input',filterSuggestions);
    remarks.addEventListener('keydown',event => {
      if (suggestions.classList.contains('d-none')) return;
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const direction = event.key === 'ArrowDown' ? 1 : -1;
        const start = direction === 1 ? 0 : visibleOptions().length - 1;
        setActive(active === -1 ? start : active + direction);
      } else if (event.key === 'Enter' && active >= 0) {
        event.preventDefault();
        choose(visibleOptions()[active]);
      } else if (event.key === 'Escape') {
        suggestions.classList.add('d-none');
        remarks.setAttribute('aria-expanded','false');
      }
    });
    suggestions.addEventListener('mousedown',event => event.preventDefault());
    suggestions.addEventListener('click',event => {
      const option = event.target.closest('[role="option"]');
      if (option) choose(option);
    });
    remarks.addEventListener('blur',() => {
      suggestions.classList.add('d-none');
      remarks.setAttribute('aria-expanded','false');
    });
  }
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
