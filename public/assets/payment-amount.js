(() => {
  'use strict';

  const formatAmount = (value, fixedDecimals = false) => {
    const cleaned = value.replace(/[^\d.]/g, '');
    if (!cleaned) return '';
    const [rawInteger = '', ...decimalParts] = cleaned.split('.');
    const integer = rawInteger.slice(0, 11).replace(/^0+(?=\d)/, '') || '0';
    let decimals = decimalParts.join('').slice(0, 2);

    if (fixedDecimals) decimals = decimals.padEnd(2, '0');

    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const hasDecimal = fixedDecimals || cleaned.includes('.');
    return grouped + (hasDecimal ? `.${decimals}` : '');
  };

  document.querySelectorAll('[data-payment-amount]').forEach(input => {
    if (input.value.trim()) input.value = formatAmount(input.value, true);

    input.addEventListener('input', () => {
      input.value = formatAmount(input.value);
    });

    input.addEventListener('blur', () => {
      if (input.value.trim()) input.value = formatAmount(input.value, true);
    });
  });
})();
