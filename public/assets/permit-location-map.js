(() => {
  'use strict';
  const elements = [...document.querySelectorAll('[data-fp-location-map]')];
  if (!elements.length) return;
  const leaflet = window.L;
  if (!leaflet) {
    elements.forEach(element => {
      const notice = element.closest('[data-fp-location-wrapper]')?.querySelector('[data-fp-location-status]');
      if (notice) notice.textContent = 'Map unavailable. Coordinates can still be entered manually.';
    });
    return;
  }
  const isNumber = value => value !== '' && Number.isFinite(Number(value));
  const bounds = (lat,lon) => lat >= -90 && lat <= 90 && lon >= -180 && lon <= 180;
  const fmt = n => Number(n).toFixed(7);
  elements.forEach(element => {
    const wrapper = element.closest('[data-fp-location-wrapper]');
    if (!wrapper) return;
    const readonly = element.dataset.fpLocationMap === 'readonly';
    const latInput = readonly ? null : wrapper.querySelector('[name="project_latitude"]');
    const lonInput = readonly ? null : wrapper.querySelector('[name="project_longitude"]');
    const message = wrapper.querySelector('[data-fp-location-status]');
    const latitude = Number(element.dataset.latitude);
    const longitude = Number(element.dataset.longitude);
    const hasInitial = isNumber(element.dataset.latitude ?? '')
      && isNumber(element.dataset.longitude ?? '') && bounds(latitude,longitude);
    const center = hasInitial ? [latitude,longitude] : [8.9500000,125.5400000];
    const map = leaflet.map(element,{
      zoomControl:true, scrollWheelZoom:false,
      doubleClickZoom:true
    }).setView(center,hasInitial ? 15 : 8);
    wrapper.closest('[data-step2-form-section]')
      ?.addEventListener('step2:section-shown',()=>map.invalidateSize());
    leaflet.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
      attribution:'&copy; OpenStreetMap contributors',
      maxZoom:19
    }).addTo(map);
    const markerIcon = leaflet.divIcon({
      className:'fp-location-pin-icon',
      html:'<span aria-hidden="true">📍</span>',
      iconSize:[30,38],iconAnchor:[15,35]
    });
    let marker = null;
    function setStatus(text,isError=false) {
      if (!message) return;
      message.textContent=text;
      message.classList.toggle('text-danger',isError);
      message.classList.toggle('text-secondary',!isError);
    }
    function position(lat,lon,updateInputs=false) {
      if (!bounds(lat,lon)) {setStatus('Latitude or longitude is out of range.',true);return;}
      if (!marker) {
        marker=leaflet.marker([lat,lon],{draggable:!readonly,icon:markerIcon}).addTo(map);
        if (!readonly) marker.on('dragend',()=>{
          const point=marker.getLatLng();
          position(point.lat,point.lng,true);
        });
      } else marker.setLatLng([lat,lon]);
      map.panTo([lat,lon]);
      if (updateInputs && latInput && lonInput) {
        latInput.value=fmt(lat);
        lonInput.value=fmt(lon);
        latInput.dispatchEvent(new Event('input',{bubbles:true}));
        lonInput.dispatchEvent(new Event('input',{bubbles:true}));
      }
      setStatus('Pinned location: '+fmt(lat)+', '+fmt(lon));
    }
    if (hasInitial) position(latitude,longitude);
    if (readonly) return;
    map.on('click',event=>position(event.latlng.lat,event.latlng.lng,true));
    function syncFromFields() {
      const lat=latInput.value.trim(),lon=lonInput.value.trim();
      if (!lat && !lon) {
        if (marker) { map.removeLayer(marker);marker=null; }
        setStatus('No pin selected. Click the map, or enter coordinates.');
      } else if (isNumber(lat)&&isNumber(lon)&&bounds(Number(lat),Number(lon))) {
        position(Number(lat),Number(lon));
      } else {
        setStatus('Enter both valid coordinates: Latitude −90 to 90; Longitude −180 to 180.',true);
      }
    }
    latInput.addEventListener('change',syncFromFields);
    lonInput.addEventListener('change',syncFromFields);
    wrapper.querySelector('[data-fp-location-clear]')?.addEventListener('click',()=>{
      latInput.value='';
      lonInput.value='';
      syncFromFields();
      latInput.dispatchEvent(new Event('input',{bubbles:true}));
      lonInput.dispatchEvent(new Event('input',{bubbles:true}));
    });
    const gps=wrapper.querySelector('[data-fp-location-gps]');
    gps?.addEventListener('click',()=>{
      if (!navigator.geolocation) {
        setStatus('Location access unavailable. Place a pin manually.',true);
        return;
      }
      gps.disabled=true;
      setStatus('Requesting permission to use your current position…');
      navigator.geolocation.getCurrentPosition(result=>{
        gps.disabled=false;
        const point=result.coords;
        position(point.latitude,point.longitude,true);
        map.setZoom(16);
        setStatus('Current device position selected. Verify this is the actual project site.');
      },error=>{
        gps.disabled=false;
        setStatus('Could not obtain your location ('+error.message+'). Pin the project manually.',true);
      },{enableHighAccuracy:true,timeout:12000,maximumAge:0});
    });
  });
})();
