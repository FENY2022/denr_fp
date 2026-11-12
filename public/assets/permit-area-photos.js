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
    let busy=false;
    let photosState=[];
    const groupState=new Map();
    const visibleGroups=new Set();
    const groupMaps=new Map();

    function message(text,error=false) {
      if (!feedback) return;
      feedback.textContent=text;
      feedback.classList.toggle('text-danger',error);
      feedback.classList.toggle('text-secondary',!error);
      feedback.classList.toggle('text-success',!error && text!=='');
    }

    function groupPoint(group) {
      const state=groupState.get(group);
      if (!state || !validPoint(state.latitude,state.longitude)) return null;
      return {lat:Number(state.latitude),lon:Number(state.longitude)};
    }

    function inputsDisabled(disabled) {
      root.querySelectorAll('[data-fp-area-photo-input]').forEach(input=>{
        const group=Number(input.dataset.documentationGroup || 1);
        input.disabled=disabled || photosState.length>=20 || !groupPoint(group);
      });
      root.querySelectorAll('[data-area-group-gps],'
        +'[data-area-group-latitude],[data-area-group-longitude]').forEach(control=>{
        control.disabled=disabled;
      });
      if (addGroup) {
        addGroup.disabled=disabled || visibleGroups.size>=maxGroups || photosState.length>=20;
      }
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

    function groupDataFromSection(section) {
      const pin=section.querySelector('[data-area-photo-group-pin]');
      return {
        documentation_group:Number(section.dataset.areaPhotoGroup || 1),
        latitude:pin?.dataset.latitude || '',
        longitude:pin?.dataset.longitude || ''
      };
    }

    function loadInitialState() {
      photosState=[...root.querySelectorAll('[data-area-photo-id]')].map(photoDataFromCard);
      for (const section of root.querySelectorAll('[data-area-photo-group]')) {
        const group=Number(section.dataset.areaPhotoGroup || 1);
        if (!Number.isInteger(group) || group<1 || group>maxGroups) continue;
        const state=groupDataFromSection(section);
        groupState.set(group,state);
        if (!editable || group===1) visibleGroups.add(group);
      }
      for (const photo of photosState) {
        const group=Math.max(1,Math.min(maxGroups,Number(photo.documentation_group || 1)));
        if (!groupState.has(group) && validPoint(photo.latitude,photo.longitude)) {
          groupState.set(group,{
            documentation_group:group,
            latitude:String(photo.latitude),
            longitude:String(photo.longitude)
          });
        }
        if (!editable) visibleGroups.add(group);
      }
      if (editable) visibleGroups.add(1);
    }

    function destroyMaps() {
      for (const api of groupMaps.values()) {
        try { api.map.remove(); } catch (_) {}
      }
      groupMaps.clear();
    }

    function markerPopup(photo) {
      const box=document.createElement('div');
      const image=document.createElement('img');
      image.src=String(photo.preview_url || '');
      image.alt='Area photo preview';
      image.className='img-fluid rounded mb-2';
      image.loading='lazy';
      const title=document.createElement('strong');
      title.className='d-block';
      title.textContent=String(photo.original_name || 'Area photo');
      const coords=document.createElement('span');
      coords.className='small text-secondary';
      coords.textContent=fmt(photo.latitude)+', '+fmt(photo.longitude);
      box.append(image,title,coords);
      return box;
    }

    function saveGroup(group,lat='',lon='') {
      const data=new FormData();
      data.set('csrf_token',csrf);
      data.set('application_id',scope);
      data.set('documentation_group',String(group));
      data.set('latitude',lat);
      data.set('longitude',lon);
      return post('permit-area-photo-group-save',data);
    }

    function applyPayload(payload) {
      photosState=(payload.photos || photosState).map(photo=>({
        ...photo,
        documentation_group:Math.max(1,Math.min(maxGroups,Number(photo.documentation_group || 1)))
      }));
      if (Array.isArray(payload.groups)) {
        for (const row of payload.groups) {
          const group=Math.max(1,Math.min(maxGroups,Number(row.documentation_group || 1)));
          visibleGroups.add(group);
          groupState.set(group,{
            documentation_group:group,
            latitude:String(row.latitude || ''),
            longitude:String(row.longitude || '')
          });
        }
      }
      for (const photo of photosState) visibleGroups.add(photo.documentation_group);
    }

    async function persistGroupPoint(group,lat,lon,successMessage) {
      if (busy) return;
      if (!validPoint(lat,lon)) {
        message('Enter a valid Latitude (−90 to 90) and Longitude (−180 to 180).',true);
        return;
      }
      busy=true;
      inputsDisabled(true);
      try {
        const payload=await saveGroup(group,fmt(lat),fmt(lon));
        applyPayload(payload);
        render();
        message(successMessage || ('Pinpoint Project Location '+group+' saved.'));
      } catch(error) {
        message(error.message || 'Documentation pin could not be saved.',true);
      } finally {
        busy=false;
        inputsDisabled(false);
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
        hint.textContent='Drag this photo marker on this documentation map to tag a different point.';
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
        const usePin=document.createElement('button');
        usePin.type='button';
        usePin.className='btn btn-sm btn-outline-success';
        usePin.dataset.areaPhotoUseGroup=String(photo.id);
        usePin.innerHTML='<i class="bi bi-crosshair me-1" aria-hidden="true"></i>Use Documentation Pin';
        const remove=document.createElement('button');
        remove.type='button';
        remove.className='btn btn-sm btn-outline-danger';
        remove.dataset.areaPhotoDelete=String(photo.id);
        remove.innerHTML='<i class="bi bi-trash3 me-1" aria-hidden="true"></i>Remove';
        actions.append(usePin,remove);
      }
      info.append(actions);
      row.append(image,info);
      body.append(row);
      item.append(body);
      return item;
    }

    function groupBlock(group,photos) {
      const state=groupState.get(group) || {
        documentation_group:group,latitude:'',longitude:''
      };
      const point=groupPoint(group);
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

      const pinBox=document.createElement('div');
      pinBox.className='border rounded-3 p-3 mb-3';
      pinBox.dataset.areaPhotoGroupPin='';
      pinBox.dataset.documentationGroup=String(group);
      pinBox.dataset.latitude=String(state.latitude || '');
      pinBox.dataset.longitude=String(state.longitude || '');

      const pinTop=document.createElement('div');
      pinTop.className='d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2';
      const pinText=document.createElement('div');
      const pinHeading=document.createElement('h5');
      pinHeading.className='h6 mb-1';
      pinHeading.innerHTML='<i class="bi bi-geo-alt-fill text-success me-1" aria-hidden="true"></i>'
        +'Pinpoint Project Location '+String(group);
      const pinHelp=document.createElement('p');
      pinHelp.className='small text-secondary mb-0';
      pinHelp.textContent='This pin belongs only to Area Photo Documentation '+String(group)
        +'. Photos uploaded here start at this point.';
      pinText.append(pinHeading,pinHelp);
      pinTop.append(pinText);

      if (editable) {
        const controls=document.createElement('div');
        controls.className='d-flex flex-wrap gap-1';
        const gps=document.createElement('button');
        gps.type='button';
        gps.className='btn btn-sm btn-outline-success';
        gps.dataset.areaGroupGps='';
        gps.innerHTML='<i class="bi bi-crosshair me-1" aria-hidden="true"></i>Use My Current Location';
        controls.append(gps);
        pinTop.append(controls);
      }
      pinBox.append(pinTop);

      if (editable) {
        const fields=document.createElement('div');
        fields.className='row g-2 mb-2';
        for (const [kind,label,value] of [
          ['latitude','Latitude',state.latitude || ''],
          ['longitude','Longitude',state.longitude || '']
        ]) {
          const col=document.createElement('div');
          col.className='col-md-6';
          const lab=document.createElement('label');
          lab.className='form-label small';
          lab.textContent=label;
          const input=document.createElement('input');
          input.className='form-control form-control-sm';
          input.type='text';
          input.inputMode='decimal';
          input.dataset[kind==='latitude'?'areaGroupLatitude':'areaGroupLongitude']='';
          input.value=String(value);
          input.placeholder=kind==='latitude'?'e.g. 8.9471234':'e.g. 125.5423456';
          col.append(lab,input);
          fields.append(col);
        }
        pinBox.append(fields);
      } else {
        const coords=document.createElement('p');
        coords.className='small mb-2';
        coords.innerHTML='Latitude: <strong>'+String(state.latitude || '')+'</strong> · Longitude: <strong>'
          +String(state.longitude || '')+'</strong>';
        pinBox.append(coords);
      }

      const map=document.createElement('div');
      map.className='fp-project-location-map';
      map.dataset.areaPhotoGroupMap='';
      map.dataset.documentationGroup=String(group);
      map.setAttribute('aria-label','Area Photo Documentation '+String(group)+' pinpoint map');
      pinBox.append(map);

      const status=document.createElement('p');
      status.className='small text-secondary mt-2 mb-0';
      status.dataset.areaGroupStatus='';
      status.setAttribute('role','status');
      status.textContent=point
        ? 'Pinned documentation location: '+fmt(point.lat)+', '+fmt(point.lon)
        : (editable
          ? 'No documentation pin selected. Use GPS, click this map, or enter coordinates.'
          : 'Documentation pin unavailable.');
      pinBox.append(status);
      section.append(pinBox);

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
        input.disabled=busy || photosState.length>=20 || !point;
        const help=document.createElement('div');
        help.className='form-text';
        help.textContent='Pinpoint Project Location '+String(group)+' first. JPG/JPEG/PNG only. Maximum '
          +(maxBytes/1048576).toFixed(1)+' MB each. New photos use this documentation pin; '
          +'individual photo markers may still be adjusted afterward.';
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

    function initGroupMap(section,group,photos) {
      if (!leaflet) {
        const status=section.querySelector('[data-area-group-status]');
        if (status) status.textContent='Map unavailable. Coordinates can still be entered manually.';
        return;
      }
      const mapElement=section.querySelector('[data-area-photo-group-map]');
      if (!mapElement) return;
      const point=groupPoint(group);
      const center=point ? [point.lat,point.lon] : [8.9500000,125.5400000];
      const map=leaflet.map(mapElement,{
        zoomControl:true,scrollWheelZoom:false,doubleClickZoom:true
      }).setView(center,point?16:8);
      leaflet.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
        attribution:'&copy; OpenStreetMap contributors',maxZoom:19
      }).addTo(map);

      const pinIcon=leaflet.divIcon({
        className:'fp-location-pin-icon',
        html:'<span aria-hidden="true">📍</span>',
        iconSize:[30,38],iconAnchor:[15,35]
      });
      let pin=null;
      if (point) {
        pin=leaflet.marker([point.lat,point.lon],{
          draggable:editable,icon:pinIcon,title:'Documentation '+String(group)+' pin'
        }).addTo(map);
      }

      const photoMarkers=new Map();
      for (const photo of photos) {
        if (!validPoint(photo.latitude,photo.longitude)) continue;
        const marker=leaflet.marker(
          [Number(photo.latitude),Number(photo.longitude)],
          {draggable:editable,title:String(photo.original_name || 'Area photo')}
        ).addTo(map);
        marker.bindPopup(markerPopup(photo),{maxWidth:260});
        if (editable) {
          marker.on('dragend',async ()=>{
            if (busy) {
              marker.setLatLng([Number(photo.latitude),Number(photo.longitude)]);
              return;
            }
            const previous={lat:Number(photo.latitude),lon:Number(photo.longitude)};
            const next=marker.getLatLng();
            busy=true;
            inputsDisabled(true);
            marker.dragging?.disable();
            try {
              const data=new FormData();
              data.set('csrf_token',csrf);
              data.set('application_id',scope);
              data.set('id',String(photo.id));
              data.set('latitude',fmt(next.lat));
              data.set('longitude',fmt(next.lng));
              const payload=await post('permit-area-photo-update',data);
              applyPayload(payload);
              render();
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
        photoMarkers.set(Number(photo.id),marker);
      }

      if (editable) {
        map.on('click',event=>{
          persistGroupPoint(
            group,event.latlng.lat,event.latlng.lng,
            'Pinpoint Project Location '+String(group)+' saved from the map.'
          );
        });
        if (pin) {
          pin.on('dragend',()=>{
            const next=pin.getLatLng();
            persistGroupPoint(
              group,next.lat,next.lng,
              'Pinpoint Project Location '+String(group)+' moved.'
            );
          });
        }
      }
      groupMaps.set(group,{map,pin,photoMarkers});
      requestAnimationFrame(()=>map.invalidateSize());
    }

    function render() {
      destroyMaps();

      if (!editable) {
        visibleGroups.clear();
        for (const group of groupState.keys()) visibleGroups.add(group);
        for (const photo of photosState) visibleGroups.add(photo.documentation_group);
      } else {
        visibleGroups.add(1);
      }

      const byGroup=new Map();
      for (const group of [...visibleGroups].sort((a,b)=>a-b)) byGroup.set(group,[]);
      for (const photo of photosState) {
        if (!byGroup.has(photo.documentation_group)) byGroup.set(photo.documentation_group,[]);
        byGroup.get(photo.documentation_group).push(photo);
      }

      groupContainer?.replaceChildren();
      for (const [group,items] of [...byGroup.entries()].sort((a,b)=>a[0]-b[0])) {
        const section=groupBlock(group,items);
        groupContainer?.append(section);
        initGroupMap(section,group,items);
      }

      if (count) count.textContent=String(photosState.length);
      inputsDisabled(busy);
    }

    loadInitialState();

    if (!editable) {
      render();
      return;
    }

    form?.addEventListener('submit',event=>{
      if (!busy) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      message('Finish the current documentation-pin/photo update before submitting.',true);
    },true);

    addGroup?.addEventListener('click',()=>{
      if (busy || visibleGroups.size>=maxGroups) return;
      let next=1;
      while (visibleGroups.has(next) && next<=maxGroups) next++;
      if (next>maxGroups) {
        message('Maximum '+String(maxGroups)+' Area Photo Documentation blocks reached.',true);
        return;
      }

      // Reveal the next saved group when one already exists. If it is a brand-new
      // group, keep it client-side until the user actually pins or uploads data.
      // This prevents a click from overwriting an existing saved pin with NULL.
      visibleGroups.add(next);
      if (!groupState.has(next)) {
        groupState.set(next,{
          documentation_group:next,
          latitude:'',
          longitude:''
        });
      }
      render();
      root.querySelector('[data-area-photo-group="'+String(next)+'"]')?.scrollIntoView({
        behavior:'smooth',block:'nearest'
      });
      const hasSavedData=photosState.some(photo=>Number(photo.documentation_group)===next)
        || Boolean(groupPoint(next));
      message(hasSavedData
        ? 'Area Photo Documentation '+String(next)+' revealed with its saved data.'
        : 'Area Photo Documentation '+String(next)
          +' added. Set its Pinpoint Project Location before uploading photos.');
    });

    root.addEventListener('change',async event=>{
      const input=event.target.closest('[data-fp-area-photo-input]');
      if (input && !busy) {
        const selected=[...(input.files || [])];
        input.value='';
        if (!selected.length) return;
        const group=Math.max(1,Math.min(maxGroups,Number(input.dataset.documentationGroup || 1)));
        if (!groupPoint(group)) {
          message('Pinpoint Project Location '+String(group)+' first, then upload its photos.',true);
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
          data.set('area_photo',file);
          try {
            message('Uploading and encrypting '+file.name+' to Area Photo Documentation '+String(group)+'…');
            const payload=await post('permit-area-photo-upload',data);
            applyPayload(payload);
            render();
          } catch(error) {
            message(error.message || 'Area photo upload failed.',true);
          }
        }
        busy=false;
        render();
        message(skipped
          ? 'Some images were skipped due to the 20-photo total, file type, or size limit.'
          : 'Area photo(s) saved under Area Photo Documentation '+String(group)+'.',
          skipped>0
        );
        return;
      }

      const latField=event.target.closest('[data-area-group-latitude]');
      const lonField=event.target.closest('[data-area-group-longitude]');
      if (!latField && !lonField) return;
      const section=event.target.closest('[data-area-photo-group]');
      if (!section) return;
      const group=Number(section.dataset.areaPhotoGroup || 1);
      const lat=section.querySelector('[data-area-group-latitude]')?.value.trim() || '';
      const lon=section.querySelector('[data-area-group-longitude]')?.value.trim() || '';
      if (!lat && !lon) return;
      await persistGroupPoint(
        group,lat,lon,'Pinpoint Project Location '+String(group)+' saved from coordinates.'
      );
    });

    root.addEventListener('click',async event=>{
      const gps=event.target.closest('[data-area-group-gps]');
      if (gps && !busy) {
        const section=gps.closest('[data-area-photo-group]');
        const group=Number(section?.dataset.areaPhotoGroup || 1);
        if (!navigator.geolocation) {
          message('Location access unavailable. Pin this documentation location manually.',true);
          return;
        }
        busy=true;
        inputsDisabled(true);
        message('Requesting permission to use your current position for Documentation '+String(group)+'…');
        navigator.geolocation.getCurrentPosition(async result=>{
          busy=false;
          inputsDisabled(false);
          await persistGroupPoint(
            group,result.coords.latitude,result.coords.longitude,
            'Current device position saved for Area Photo Documentation '+String(group)+'.'
          );
        },error=>{
          busy=false;
          inputsDisabled(false);
          message('Could not obtain your location ('+error.message+'). Pin the map manually.',true);
        },{enableHighAccuracy:true,timeout:12000,maximumAge:0});
        return;
      }

      const locate=event.target.closest('[data-area-photo-locate]');
      if (locate) {
        const id=Number(locate.dataset.areaPhotoLocate || 0);
        const photo=photosState.find(item=>Number(item.id)===id);
        if (!photo) return;
        const api=groupMaps.get(Number(photo.documentation_group || 1));
        const marker=api?.photoMarkers?.get(id);
        if (api?.map && marker) {
          api.map.setView(marker.getLatLng(),Math.max(16,api.map.getZoom()));
          marker.openPopup();
        }
        return;
      }

      const useGroup=event.target.closest('[data-area-photo-use-group]');
      if (useGroup && !busy) {
        const id=Number(useGroup.dataset.areaPhotoUseGroup || 0);
        const photo=photosState.find(item=>Number(item.id)===id);
        if (!photo) return;
        const point=groupPoint(Number(photo.documentation_group || 1));
        if (!point) {
          message('Pin this Area Photo Documentation first.',true);
          return;
        }
        const data=new FormData();
        data.set('csrf_token',csrf);
        data.set('application_id',scope);
        data.set('id',String(id));
        data.set('latitude',fmt(point.lat));
        data.set('longitude',fmt(point.lon));
        try {
          busy=true;
          inputsDisabled(true);
          const payload=await post('permit-area-photo-update',data);
          applyPayload(payload);
          render();
          message('Photo marker moved to its Documentation Pin.');
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
        const payload=await post('permit-area-photo-delete',data);
        applyPayload(payload);
        render();
        message('Area photo removed.');
      } catch(error) {
        message(error.message || 'Area photo could not be removed.',true);
      } finally {
        busy=false;
        inputsDisabled(false);
      }
    });

    render();
  });
})();
