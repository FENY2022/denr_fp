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
    const groupContainer=root.querySelector('[data-fp-area-photo-groups]');
    const feedback=root.querySelector('[data-fp-area-photo-feedback]');
    const count=root.querySelector('[data-fp-area-photo-count]');
    const addGroup=root.querySelector('[data-fp-add-area-photo-documentation]');
    const maxBytes=Number(root.dataset.maxBytes || 5242880);
    const maxGroups=Math.max(1,Math.min(10,Number(root.dataset.maxDocumentationGroups || 10)));
    const mapElement=(form || document).querySelector(
      editable ? '[data-fp-location-map="edit"]' : '[data-fp-location-map="readonly"]'
    );
    const latInput=editable ? form?.querySelector('[name="project_latitude"]') : null;
    const lonInput=editable ? form?.querySelector('[name="project_longitude"]') : null;
    let mapApi=mapElement?.fpomsLocationMap || null;
    const markers=new Map();
    const visibleGroups=new Set(
      [...root.querySelectorAll('[data-area-photo-group]')]
        .map(node=>Number(node.dataset.areaPhotoGroup || 1))
        .filter(group=>Number.isInteger(group) && group>=1 && group<=maxGroups)
    );
    if (!visibleGroups.size && editable) visibleGroups.add(1);
    let photosState=[];
    let busy=false;

    function message(text,error=false) {
      if (!feedback) return;
      feedback.textContent=text;
      feedback.classList.toggle('text-danger',error);
      feedback.classList.toggle('text-secondary',!error);
      feedback.classList.toggle('text-success',!error && text!=='');
    }

    function inputsDisabled(disabled) {
      root.querySelectorAll('[data-fp-area-photo-input]').forEach(input=>{
        input.disabled=disabled || photosState.length>=20;
      });
      if (addGroup) {
        addGroup.disabled=disabled || visibleGroups.size>=maxGroups || photosState.length>=20;
      }
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
        documentation_group:Number(card.dataset.documentationGroup || 1),
        original_name:card.dataset.photoName || 'Area photo',
        latitude:card.dataset.latitude || '',
        longitude:card.dataset.longitude || '',
        preview_url:card.dataset.previewUrl || '',
        revision:Number(card.dataset.revision || 0)
      };
    }

    function popupNode(photo) {
      const box=document.createElement('div');
      box.className='fp-area-photo-popup';
      const image=document.createElement('img');
      image.src=String(photo.preview_url || '');
      image.alt='Area photo preview';
      image.className='img-fluid rounded mb-2';
      image.loading='lazy';
      const title=document.createElement('strong');
      title.className='d-block';
      title.textContent=String(photo.original_name || 'Area photo');
      const group=document.createElement('span');
      group.className='small d-block text-secondary';
      group.textContent='Area Photo Documentation '+String(photo.documentation_group || 1);
      const coords=document.createElement('span');
      coords.className='small text-secondary';
      coords.textContent=fmt(photo.latitude)+', '+fmt(photo.longitude);
      box.append(image,title,group,coords);
      return box;
    }

    function addMarker(photo) {
      if (!leaflet || !mapApi?.map || !validPoint(photo.latitude,photo.longitude)) return;
      const id=Number(photo.id);
      const old=markers.get(id);
      if (old) mapApi.map.removeLayer(old);
      const marker=leaflet.marker(
        [Number(photo.latitude),Number(photo.longitude)],
        {draggable:editable,title:String(photo.original_name || 'Area photo')}
      ).addTo(mapApi.map);
      marker.bindPopup(popupNode(photo),{maxWidth:260});
      if (editable) {
        marker.on('dragend',async ()=>{
          if (busy) {
            marker.setLatLng([Number(photo.latitude),Number(photo.longitude)]);
            return;
          }
          const previous={lat:Number(photo.latitude),lon:Number(photo.longitude)};
          const point=marker.getLatLng();
          busy=true;
          inputsDisabled(true);
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
            inputsDisabled(false);
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

    function photoCard(photo) {
      const item=document.createElement('div');
      item.className='card mb-2';
      item.dataset.areaPhotoId=String(photo.id);
      item.dataset.documentationGroup=String(photo.documentation_group || 1);
      item.dataset.photoName=String(photo.original_name || 'Area photo');
      item.dataset.latitude=String(photo.latitude || '');
      item.dataset.longitude=String(photo.longitude || '');
      item.dataset.previewUrl=String(photo.preview_url || '');
      item.dataset.revision=String(photo.revision || 0);

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
      const groupLabel=document.createElement('div');
      groupLabel.className='small text-secondary';
      groupLabel.textContent='Documentation '+String(photo.documentation_group || 1);
      const coords=document.createElement('div');
      coords.className='small text-secondary';
      coords.dataset.areaPhotoCoords='';
      coords.textContent=validPoint(photo.latitude,photo.longitude)
        ? 'GPS: '+fmt(photo.latitude)+', '+fmt(photo.longitude)
        : 'GPS location unavailable';
      info.append(name,groupLabel,coords);

      if (Number(photo.revision || 0)>0) {
        const revision=document.createElement('span');
        revision.className='badge text-bg-light mt-1';
        revision.textContent='Revision '+String(photo.revision);
        info.append(revision);
      }

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

    function groupBlock(group,photos) {
      const section=document.createElement('section');
      section.className='border rounded-3 p-3 mb-3 bg-body';
      section.dataset.areaPhotoGroup=String(group);

      const top=document.createElement('div');
      top.className='d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2';
      const heading=document.createElement('h4');
      heading.className='h6 mb-0';
      heading.innerHTML='<i class="bi bi-camera-fill text-success me-1" aria-hidden="true"></i>'
        +'Area Photo Documentation '+String(group);
      const badge=document.createElement('span');
      badge.className='badge text-bg-light border';
      badge.textContent=String(photos.length)+' photo'+(photos.length===1?'':'s');
      top.append(heading,badge);
      section.append(top);

      if (editable) {
        const label=document.createElement('label');
        label.className='form-label';
        label.htmlFor='fp_area_photos_'+String(group);
        label.textContent='Upload / Take Area Photos';
        const input=document.createElement('input');
        input.className='form-control';
        input.type='file';
        input.id='fp_area_photos_'+String(group);
        input.dataset.fpAreaPhotoInput='';
        input.dataset.documentationGroup=String(group);
        input.accept='image/jpeg,image/png,.jpg,.jpeg,.png';
        input.multiple=true;
        input.disabled=busy || photosState.length>=20;
        const help=document.createElement('div');
        help.className='form-text';
        help.textContent='JPG/JPEG/PNG only. Maximum '
          +(maxBytes/1048576).toFixed(1)+' MB each. New photos initially use the current project pin; '
          +'drag each photo marker if its actual point is different.';
        section.append(label,input,help);
      }

      const list=document.createElement('div');
      list.className='mt-3';
      list.dataset.fpAreaPhotoList='';
      for (const photo of photos) list.append(photoCard(photo));
      section.append(list);

      const empty=document.createElement('p');
      empty.className='small text-secondary mb-0';
      empty.dataset.fpAreaPhotoEmpty='';
      empty.hidden=photos.length>0;
      empty.textContent='No area photos uploaded in this documentation yet.';
      section.append(empty);
      return section;
    }

    function render(photos) {
      photosState=(photos || []).map(photo=>({
        ...photo,
        documentation_group:Math.max(1,Math.min(maxGroups,Number(photo.documentation_group || 1)))
      }));
      for (const photo of photosState) visibleGroups.add(photo.documentation_group);
      if (!editable) {
        visibleGroups.clear();
        for (const photo of photosState) visibleGroups.add(photo.documentation_group);
      }
      if (editable && !visibleGroups.size) visibleGroups.add(1);

      const byGroup=new Map();
      for (const group of [...visibleGroups].sort((a,b)=>a-b)) byGroup.set(group,[]);
      for (const photo of photosState) {
        if (!byGroup.has(photo.documentation_group)) byGroup.set(photo.documentation_group,[]);
        byGroup.get(photo.documentation_group).push(photo);
      }

      groupContainer?.replaceChildren();
      for (const [group,items] of [...byGroup.entries()].sort((a,b)=>a[0]-b[0])) {
        groupContainer?.append(groupBlock(group,items));
      }

      if (count) count.textContent=String(photosState.length);
      const ids=new Set();
      for (const photo of photosState) {
        ids.add(Number(photo.id));
        addMarker(photo);
      }
      removeMarkersNotIn(ids);
      inputsDisabled(busy);
    }

    function currentPhotos() {
      return [...root.querySelectorAll('[data-area-photo-id]')].map(photoDataFromCard);
    }

    function attachMap() {
      mapApi=mapElement?.fpomsLocationMap || null;
      if (!mapApi) return false;
      for (const photo of photosState) addMarker(photo);
      return true;
    }

    photosState=currentPhotos();
    if (!attachMap()) {
      document.addEventListener('fpoms:location-map-ready',event=>{
        if (event.detail?.element!==mapElement) return;
        mapApi=event.detail.api;
        for (const photo of photosState) addMarker(photo);
      });
    }

    if (!editable) {
      render(photosState);
      return;
    }

    form?.addEventListener('submit',event=>{
      if (!busy) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      message('Finish the current area-photo upload or map update before submitting.',true);
    },true);

    addGroup?.addEventListener('click',()=>{
      if (busy || visibleGroups.size>=maxGroups) return;
      let next=1;
      while (visibleGroups.has(next) && next<=maxGroups) next++;
      if (next>maxGroups) {
        message('Maximum '+String(maxGroups)+' Area Photo Documentation blocks reached.',true);
        return;
      }
      visibleGroups.add(next);
      render(photosState);
      root.querySelector('[data-area-photo-group="'+String(next)+'"]')?.scrollIntoView({
        behavior:'smooth',block:'nearest'
      });
      message('Area Photo Documentation '+String(next)+' added.');
    });

    root.addEventListener('change',async event=>{
      const input=event.target.closest('[data-fp-area-photo-input]');
      if (!input || busy) return;
      const selected=[...(input.files || [])];
      input.value='';
      if (!selected.length) return;
      const group=Math.max(1,Math.min(maxGroups,Number(input.dataset.documentationGroup || 1)));
      const point=projectPoint();
      if (!point) {
        message('Pin the project site first using Current Location, a map click, or manual coordinates.',true);
        return;
      }
      const room=Math.max(0,20-photosState.length);
      if (!room) {
        message('Maximum 20 area photos reached for this application draft.',true);
        return;
      }
      busy=true;
      inputsDisabled(true);
      let skipped=Math.max(0,selected.length-room);
      for (const file of selected.slice(0,room)) {
        if (file.size<1 || file.size>maxBytes
            || !/^image\/(jpeg|png)$/i.test(file.type)
            || !/\.(jpe?g|png)$/i.test(file.name)) {
          skipped++;
          continue;
        }
        const data=new FormData();
        data.set('csrf_token',csrf);
        data.set('application_id',scope);
        data.set('documentation_group',String(group));
        data.set('latitude',fmt(point.lat));
        data.set('longitude',fmt(point.lon));
        data.set('area_photo',file);
        try {
          message('Uploading and encrypting '+file.name+' to Area Photo Documentation '+String(group)+'…');
          const result=await post('permit-area-photo-upload',data);
          render(result.photos || []);
        } catch(error) {
          message(error.message || 'Area photo upload failed.',true);
        }
      }
      busy=false;
      render(photosState);
      if (skipped) {
        message('Some images were skipped due to the 20-photo total, file type, or size limit.',true);
      } else {
        message('Area photo(s) saved under Area Photo Documentation '+String(group)+'.');
      }
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
        if (busy) return;
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
          inputsDisabled(true);
          const result=await post('permit-area-photo-update',data);
          render(result.photos || []);
          message('Photo marker moved to the current project pin.');
        } catch(error) {
          message(error.message || 'Photo marker could not be updated.',true);
        } finally {
          busy=false;
          inputsDisabled(false);
        }
        return;
      }

      const remove=event.target.closest('[data-area-photo-delete]');
      if (!remove || busy) return;
      if (!window.confirm('Remove this saved area photo from its documentation block?')) return;
      const data=new FormData();
      data.set('csrf_token',csrf);
      data.set('application_id',scope);
      data.set('id',remove.dataset.areaPhotoDelete || '');
      try {
        busy=true;
        inputsDisabled(true);
        const result=await post('permit-area-photo-delete',data);
        render(result.photos || []);
        message('Area photo removed.');
      } catch(error) {
        message(error.message || 'Area photo could not be removed.',true);
      } finally {
        busy=false;
        inputsDisabled(false);
      }
    });

    render(photosState);
  });
})();
