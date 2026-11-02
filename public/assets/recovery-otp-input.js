'use strict';
(() => {
  document.querySelectorAll('[data-otp-boxes]').forEach((group) => {
    const digits = Array.from(group.querySelectorAll('[data-otp-digit]'));
    const value = group.querySelector('[data-otp-value]');
    const form = group.closest('form');
    if (digits.length !== 6 || !value || !form) return;

    const sync = () => {
      value.value = digits.map((input) => input.value).join('');
    };
    const fill = (text, start = 0) => {
      const numbers = text.replace(/\D/g, '').slice(0, 6 - start);
      numbers.split('').forEach((number, offset) => {
        digits[start + offset].value = number;
      });
      sync();
      digits[Math.min(start + numbers.length, 5)].focus();
    };

    digits.forEach((input, index) => {
      input.addEventListener('input', () => {
        const entered = input.value.replace(/\D/g, '');
        input.value = entered.slice(-1);
        sync();
        if (input.value && index < digits.length - 1) digits[index + 1].focus();
      });
      input.addEventListener('keydown', (event) => {
        if (event.key === 'Backspace' && !input.value && index > 0) {
          digits[index - 1].focus();
        }
        if (event.key === 'ArrowLeft' && index > 0) digits[index - 1].focus();
        if (event.key === 'ArrowRight' && index < digits.length - 1) digits[index + 1].focus();
      });
      input.addEventListener('paste', (event) => {
        event.preventDefault();
        fill(event.clipboardData.getData('text'), index);
      });
    });

    form.addEventListener('submit', sync);
  });
})();
