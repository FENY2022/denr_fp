(() => {
  'use strict';

  const root = document.querySelector('[data-fp-structured-location]');
  if (!root) return;

  const form = root.closest('form');
  const province = root.querySelector('[name="project_province_code"]');
  const city = root.querySelector('[name="project_city_municipality_code"]');
  const barangay = root.querySelector('[name="project_barangay_code"]');
  const street = root.querySelector('[name="project_street_purok"]');
  const projectLocation = root.querySelector('[name="project_location"]');
  const completeAddress = root.querySelector('[data-fp-complete-address]');
  const penroOutput = root.querySelector('[data-fp-penro]');
  const cenroOutput = root.querySelector('[data-fp-cenro]');
  const status = root.querySelector('[data-fp-address-status]');
  const assetUrl = root.dataset.psgcUrl || 'assets/caraga-psgc-2026-q2.json?v=1';

  const hidden = {
    provinceName: root.querySelector('[name="project_province_name"]'),
    cityName: root.querySelector('[name="project_city_municipality_name"]'),
    barangayName: root.querySelector('[name="project_barangay_name"]'),
    penro: root.querySelector('[name="concerned_penro"]'),
    cenro: root.querySelector('[name="concerned_cenro"]')
  };

  if (!form || !province || !city || !barangay || !street || !projectLocation) return;

  let dataset = null;
  const initial = {
    province: root.dataset.selectedProvince || '',
    city: root.dataset.selectedCity || '',
    barangay: root.dataset.selectedBarangay || ''
  };

  if ((!initial.province || !initial.city || !initial.barangay) && form.dataset.draftKey) {
    try {
      const saved = JSON.parse(sessionStorage.getItem(form.dataset.draftKey) || 'null');
      if (saved && typeof saved === 'object') {
        initial.province ||= String(saved.project_province_code || '');
        initial.city ||= String(saved.project_city_municipality_code || '');
        initial.barangay ||= String(saved.project_barangay_code || '');
        if (!street.value && typeof saved.project_street_purok === 'string') {
          street.value = saved.project_street_purok;
        }
      }
    } catch (_) {}
  }

  function setStatus(message, error = false) {
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('text-danger', error);
    status.classList.toggle('text-secondary', !error);
  }

  function replaceOptions(select, placeholder, options, selected = '') {
    const fragment = document.createDocumentFragment();
    const first = document.createElement('option');
    first.value = '';
    first.textContent = placeholder;
    fragment.append(first);
    for (const option of options) {
      const item = document.createElement('option');
      item.value = String(option.code || '');
      item.textContent = String(option.label || option.name || '');
      if (item.value === selected) item.selected = true;
      fragment.append(item);
    }
    select.replaceChildren(fragment);
    select.disabled = false;
  }

  function areaByCode(code) {
    return dataset?.areas?.find(area => String(area.code) === String(code)) || null;
  }

  function cityByCode(area, code) {
    return area?.cities?.find(item => String(item.code) === String(code)) || null;
  }

  function barangayByCode(cityItem, code) {
    return cityItem?.barangays?.find(item => Array.isArray(item) && String(item[0]) === String(code)) || null;
  }

  function updateDerived() {
    const area = areaByCode(province.value);
    const cityItem = cityByCode(area, city.value);
    const barangayItem = barangayByCode(cityItem, barangay.value);
    const office = cityItem ? dataset?.jurisdictions?.[String(cityItem.code)] : null;

    hidden.provinceName.value = area ? String(area.name || '') : '';
    hidden.cityName.value = cityItem ? String(cityItem.name || '') : '';
    hidden.barangayName.value = barangayItem ? String(barangayItem[1] || '') : '';
    hidden.penro.value = office ? String(office.penro || '') : '';
    hidden.cenro.value = office ? String(office.cenro || '') : '';

    const parts = [];
    const streetText = street.value.trim().replace(/\s+/g, ' ');
    if (streetText) parts.push(streetText);
    if (barangayItem) parts.push('Brgy. ' + String(barangayItem[1] || ''));
    if (cityItem) parts.push(String(cityItem.name || ''));
    if (area && String(area.kind || '') !== 'independent_city'
        && (!cityItem || String(area.name || '').toLowerCase() !== String(cityItem.name || '').toLowerCase())) {
      parts.push(String(area.name || ''));
    }
    const formatted = parts.join(', ');
    projectLocation.value = formatted;
    if (completeAddress) completeAddress.value = formatted;

    if (penroOutput) penroOutput.textContent = hidden.penro.value || 'Select a Municipality / City';
    if (cenroOutput) {
      cenroOutput.textContent = hidden.cenro.value
        || (hidden.penro.value ? 'Direct PENRO jurisdiction' : 'Select a Municipality / City');
    }

    for (const field of [
      projectLocation, hidden.provinceName, hidden.cityName,
      hidden.barangayName, hidden.penro, hidden.cenro
    ]) {
      field?.dispatchEvent(new Event('input', {bubbles: true}));
    }

    if (area && cityItem && barangayItem && streetText) {
      setStatus('Project address and DENR jurisdiction detected automatically.');
    } else {
      setStatus('Complete the Province/Independent City, Municipality/City, Barangay, and Street/Purok/Sitio.');
    }
  }

  function populateBarangays(selected = '') {
    const area = areaByCode(province.value);
    const cityItem = cityByCode(area, city.value);
    const options = (cityItem?.barangays || []).map(item => ({
      code: String(item[0] || ''), name: String(item[1] || '')
    }));
    replaceOptions(barangay, 'Select Barangay', options, selected);
    updateDerived();
  }

  function populateCities(selectedCity = '', selectedBarangay = '') {
    const area = areaByCode(province.value);
    const options = (area?.cities || []).map(item => ({
      code: String(item.code || ''), name: String(item.name || '')
    }));
    replaceOptions(city, 'Select Municipality / City', options, selectedCity);
    populateBarangays(selectedBarangay);
  }

  function populateAreas() {
    const options = (dataset?.areas || []).map(area => ({
      code: String(area.code || ''),
      label: String(area.name || '') + (String(area.kind || '') === 'independent_city'
        ? ' (Independent City)' : '')
    }));
    replaceOptions(province, 'Select Province / Independent City', options, initial.province);
    populateCities(initial.city, initial.barangay);
  }

  province.addEventListener('change', () => {
    initial.city = '';
    initial.barangay = '';
    populateCities();
    province.dispatchEvent(new Event('input', {bubbles: true}));
  });

  city.addEventListener('change', () => {
    initial.barangay = '';
    populateBarangays();
    city.dispatchEvent(new Event('input', {bubbles: true}));
  });

  barangay.addEventListener('change', () => {
    updateDerived();
    barangay.dispatchEvent(new Event('input', {bubbles: true}));
  });

  street.addEventListener('input', updateDerived);

  province.disabled = true;
  city.disabled = true;
  barangay.disabled = true;
  setStatus('Loading Caraga PSGC location list…');

  fetch(assetUrl, {credentials: 'same-origin', cache: 'no-store'})
    .then(response => {
      if (!response.ok) throw new Error('Location list could not be loaded.');
      return response.json();
    })
    .then(data => {
      if (!data || !Array.isArray(data.areas) || typeof data.jurisdictions !== 'object') {
        throw new Error('Location list is invalid.');
      }
      dataset = data;
      populateAreas();
    })
    .catch(error => {
      setStatus(error.message || 'Location list unavailable. Refresh the page.', true);
      province.disabled = true;
      city.disabled = true;
      barangay.disabled = true;
    });
})();
