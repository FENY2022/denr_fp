(() => {
  'use strict';

  const roots=[...document.querySelectorAll('[data-fp-area-photos]')];
  if (!roots.length) return;

  const leaflet=window.L;
  const fmt=value=>Number(value).toFixed(7);
  const validPoint=(lat,lon)=>Number.isFinite(Number(lat)) && Number.isFinite(Number(lon))
    && Number(lat)>=-90 && Number(lat)<=90 && Number(lon)>=-180 && Number(lon)<=180;

  function post(url,data) {
    return fetch(url,{
      method:'POST',
      credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},
      body:data
    }).then(async response=>{
      let payload=null;
      try { payload=await response.json(); } catch (_) {}
      if (!response.ok || !payload || payload.error) {
        throw new Error(payload?.error || 'Request failed. Refresh the page and try again.');
      }
      return payload;
    });
  }

  roots.forEach(root=>{
    const editable=root.dataset.editable==='1';
    const form=editable ? root.closest('form') : null;
    const scope=root.dataset.applicationId || '0';
    const csrf=form?.querySelector('input[name="csrf_token"]')?.value || '';
    const input=root.querySelector('[data-fp-area-photo-input]');
    const list=root.querySelector('[data-fp-area-photo-list]');
    const empty=root.querySelector('[data-fp-area-photo-empty]');
    const feedback=root.querySelector('[data-fp-area-photo-feedback]');
    const count=root.querySelector('[data-fp-area-photo-count]');
    const maxBytes=Number(root.dataset.maxBytes || 5242880);
    const mapElement=(form || document).querySelector(
      editable ? '[data-fp-location-map="edit"]' : '[data-fp-location-map="readonly"]'
    );
    const latInput=editable ? form?.querySelector('[name="project_latitude"]') : null;
    const lonInput=editable ? form?.querySelector('[name="project_longitude"]') : null;
    let mapApi=mapElement?.fpomsLocationMap || null;
    const markers=new Map();
    let busy=false;

    function message(text,error=false) {
      if (!feedback) return;
      feedback.textContent=text;
      feedback.classList.toggle('text-danger',error);
      feedback.classList.toggle('text-secondary',!error);
      feedback.classList.toggle('text-success',!error && text!=='');
    }

    function projectPoint() {
      if (!editable) return null;
      const lat=latInput?.value.trim() || '';
      const lon=lonInput?.value.trim() || '';
      return validPoint(lat,lon) ? {lat:Number(lat),lon:Number(lon)} : null;
    }

    function photoDataFromCard(card) {
      return {
        id:Number(card.dataset.areaPhotoId || 0),
        name:card.dataset.photoName || 'Area photo',
        latitude:card.dataset.latitude || '',
        longitude:card.dataset.longitude || '',
        preview_url:card.dataset.previewUrl || ''
      };
    }

    function popupNode(photo) {
      const box=document.createElement('div');
      box.className='fp-area-photo-popup';
      const image=document.createElement('img');
      image.src=photo.preview_url;
      image.alt='Area photo preview';
      image.className='img-fluid rounded mb-2';
      image.loading='lazy';
      const title=document.createElement('strong');
      title.className='d-block';
      title.textContent=photo.name;
      const coords=document.createElement('span');
      coords.className='small text-secondary';
      coords.textContent=fmt(photo.latitude)+', '+fmt(photo.longitude);
      box.append(image,title,coords);
      return box;
    }

    function addMarker(photo) {
      if (!leaflet || !mapApi?.map || !validPoint(photo.latitude,photo.longitude)) return;
      const id=Number(photo.id);
      const old=markers.get(id);
      if (old) mapApi.map.removeLayer(old);
      const marker=leaflet.marker(
        [Number(photo.latitude),Number(photo.longitude)],
        {draggable:editable,title:photo.name}
      ).addTo(mapApi.map);
      marker.bindPopup(popupNode(photo),{maxWidth:260});
      if (editable) {
        marker.on('dragend',async ()=>{
          if (busy) {
            marker.setLatLng([Number(photo.latitude),Number(photo.longitude)]);
            return;
          }
          const previous={
            lat:Number(photo.latitude),lon:Number(photo.longitude)
          };
          const point=marker.getLatLng();
          busy=true;
          if (input) input.disabled=true;
          marker.dragging?.disable();
          try {
            const data=new FormData();
            data.set('csrf_token',csrf);
            data.set('application_id',scope);
            data.set('id',String(id));
            data.set('latitude',fmt(point.lat));
            data.set('longitude',fmt(point.lng));
            const result=await post('permit-area-photo-update',data);
            render(result.photos || []);
            message('Photo marker location saved.');
          } catch(error) {
            marker.setLatLng([previous.lat,previous.lon]);
            message(error.message || 'Photo marker could not be saved.',true);
          } finally {
            busy=false;
            render(currentPhotos());
          }
        });
      }
      markers.set(id,marker);
    }

    function removeMarkersNotIn(ids) {
      for (const [id,marker] of markers) {
        if (ids.has(id)) continue;
        mapApi?.map?.removeLayer(marker);
        markers.delete(id);
      }
    }

    function card(photo) {
      const item=document.createElement('div');
      item.className='card mb-2';
      item.dataset.areaPhotoId=String(photo.id);
      item.dataset.photoName=String(photo.original_name || 'Area photo');
      item.dataset.latitude=String(photo.latitude || '');
      item.dataset.longitude=String(photo.longitude || '');
      item.dataset.previewUrl=String(photo.preview_url || '');

      const body=document.createElement('div');
      body.className='card-body p-2';
      const row=document.createElement('div');
      row.className='d-flex gap-2 align-items-start';

      const image=document.createElement('img');
      image.src=String(photo.preview_url || '');
      image.alt='Area photo preview';
      image.loading='lazy';
      image.width=92;
      image.height=72;
      image.className='rounded border object-fit-cover flex-shrink-0';

      const info=document.createElement('div');
      info.className='flex-grow-1 min-width-0';
      const name=document.createElement('div');
      name.className='fw-semibold text-break';
      name.textContent=String(photo.original_name || 'Area photo');
      const coords=document.createElement('div');
      coords.className='small text-secondary';
      coords.dataset.areaPhotoCoords='';
      coords.textContent=validPoint(photo.latitude,photo.longitude)
        ? 'GPS: '+fmt(photo.latitude)+', '+fmt(photo.longitude)
        : 'GPS location unavailable';
      info.append(name,coords);

      if (editable) {
        const hint=document.createElement('div');
        hint.className='small text-secondary mt-1';
        hint.textContent='Drag this photo marker on the map to tag a different point.';
        info.append(hint);
      }

      const actions=document.createElement('div');
      actions.className='d-flex flex-wrap gap-1 mt-2';

      const locate=document.createElement('button');
      locate.type='button';
      locate.className='btn btn-sm btn-outline-primary';
      locate.dataset.areaPhotoLocate=String(photo.id);
      locate.innerHTML='<i class="bi bi-geo-alt me-1" aria-hidden="true"></i>Show on Map';
      actions.append(locate);

      if (editable) {
        const useProject=document.createElement('button');
        useProject.type='button';
        useProject.className='btn btn-sm btn-outline-success';
        useProject.dataset.areaPhotoUseProject=String(photo.id);
        useProject.innerHTML='<i class="bi bi-crosshair me-1" aria-hidden="true"></i>Use Project Pin';
        const remove=document.createElement('button');
        remove.type='button';
        remove.className='btn btn-sm btn-outline-danger';
        remove.dataset.areaPhotoDelete=String(photo.id);
        remove.innerHTML='<i class="bi bi-trash3 me-1" aria-hidden="true"></i>Remove';
        actions.append(useProject,remove);
      }

      info.append(actions);
      row.append(image,info);
      body.append(row);
      item.append(body);
      return item;
    }

    function render(photos) {
      if (!list) return;
      list.replaceChildren();
      const ids=new Set();
      for (const photo of photos) {
        const id=Number(photo.id || 0);
        if (!id) continue;
        ids.add(id);
        const item=card(photo);
        list.append(item);
        addMarker(photo);
      }
      removeMarkersNotIn(ids);
      if (empty) empty.hidden=ids.size>0;
      if (count) count.textContent=String(ids.size);
      if (input) input.disabled=busy || ids.size>=20;
    }

    function currentPhotos() {
      return [...root.querySelectorAll('[data-area-photo-id]')].map(photoDataFromCard);
    }

    function attachMap() {
      mapApi=mapElement?.fpomsLocationMap || null;
      if (!mapApi) return false;
      for (const photo of currentPhotos()) addMarker(photo);
      return true;
    }

    if (!attachMap()) {
      document.addEventListener('fpoms:location-map-ready',event=>{
        if (event.detail?.element!==mapElement) return;
        mapApi=event.detail.api;
        for (const photo of currentPhotos()) addMarker(photo);
      });
    }

    if (!editable) {
      render(currentPhotos());
      return;
    }

    form?.addEventListener('submit',event=>{
      if (!busy) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      message('Finish the current area-photo upload or map update before submitting.',true);
    },true);

    input?.addEventListener('change',async ()=>{
      if (busy) return;
      const selected=[...(input.files || [])];
      input.value='';
      if (!selected.length) return;
      const point=projectPoint();
      if (!point) {
        message('Pin the project site first using Current Location, a map click, or manual coordinates.',true);
        return;
      }
      busy=true;
      input.disabled=true;
      let skipped=0;
      for (const file of selected) {
        if (file.size<1 || file.size>maxBytes
            || !/^image\/(jpeg|png)$/i.test(file.type)
            || !/\.(jpe?g|png)$/i.test(file.name)) {
          skipped++;
          continue;
        }
        const data=new FormData();
        data.set('csrf_token',csrf);
        data.set('application_id',scope);
        data.set('latitude',fmt(point.lat));
        data.set('longitude',fmt(point.lon));
        data.set('area_photo',file);
        try {
          message('Uploading and encrypting '+file.name+'…');
          const result=await post('permit-area-photo-upload',data);
          render(result.photos || []);
        } catch(error) {
          message(error.message || 'Area photo upload failed.',true);
        }
      }
      busy=false;
      render(currentPhotos());
      if (skipped) message('Some images were skipped. Use JPG/JPEG/PNG within the configured upload size.',true);
      else message('Area photo(s) saved. Drag each photo marker if it was taken at another point.');
    });

    root.addEventListener('click',async event=>{
      const locate=event.target.closest('[data-area-photo-locate]');
      if (locate) {
        const id=Number(locate.dataset.areaPhotoLocate || 0);
        const marker=markers.get(id);
        if (marker && mapApi?.map) {
          mapApi.map.setView(marker.getLatLng(),Math.max(16,mapApi.map.getZoom()));
          marker.openPopup();
        }
        return;
      }

      const useProject=event.target.closest('[data-area-photo-use-project]');
      if (useProject) {
        const point=projectPoint();
        if (!point) {
          message('Select the main project pin first.',true);
          return;
        }
        const data=new FormData();
        data.set('csrf_token',csrf);
        data.set('application_id',scope);
        data.set('id',useProject.dataset.areaPhotoUseProject || '');
        data.set('latitude',fmt(point.lat));
        data.set('longitude',fmt(point.lon));
        try {
          busy=true;
          useProject.disabled=true;
          if (input) input.disabled=true;
          const result=await post('permit-area-photo-update',data);
          render(result.photos || []);
          message('Photo marker moved to the current project pin.');
        } catch(error) {
          message(error.message || 'Photo marker could not be updated.',true);
        } finally {
          busy=false;
          render(currentPhotos());
        }
        return;
      }

      const remove=event.target.closest('[data-area-photo-delete]');
      if (!remove) return;
      if (!window.confirm('Remove this saved area photo from the editable draft?')) return;
      const data=new FormData();
      data.set('csrf_token',csrf);
      data.set('application_id',scope);
      data.set('id',remove.dataset.areaPhotoDelete || '');
      try {
        busy=true;
        remove.disabled=true;
        if (input) input.disabled=true;
        const result=await post('permit-area-photo-delete',data);
        render(result.photos || []);
        message('Area photo removed from the draft.');
      } catch(error) {
        message(error.message || 'Area photo could not be removed.',true);
      } finally {
        busy=false;
        render(currentPhotos());
      }
    });

    render(currentPhotos());
  });
})();
