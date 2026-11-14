/** FP-OMS two-month airline-style range selector; native date inputs remain fallback. */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-fp-flight-calendar]').forEach(root => {
    const from = root.querySelector('[data-fp-flight-from]');
    const to = root.querySelector('[data-fp-flight-to]');
    const popover = root.querySelector('[data-fp-flight-popover]');
    if (!from || !to || !popover) return;
    const dailyRoot=root.querySelector('[data-field-daily-times]');
    let saved={};
    try { saved=JSON.parse(dailyRoot?.dataset.existingTimes || '{}') || {}; } catch (_) {}
    function renderDaily() {
      if (!dailyRoot) return;
      dailyRoot.querySelectorAll('input').forEach(input => {
        const date=input.dataset.date, field=input.dataset.field;
        saved[date] ||= {}; saved[date][field]=input.value;
      });
      dailyRoot.replaceChildren();
      if (!from.value || !to.value) return;
      const start=new Date(from.value+'T00:00:00');
      const end=new Date(to.value+'T00:00:00');
      if (end<start || (end-start)/86400000>365) {
        dailyRoot.textContent='Select a date range of up to 366 days.'; return;
      }
      for (let day=new Date(start); day<=end; day.setDate(day.getDate()+1)) {
        const date=iso(day);
        const row=document.createElement('div'); row.className='row g-2 align-items-end mt-2';
        const heading=document.createElement('div'); heading.className='col-md-4 fw-semibold';
        heading.textContent=day.toLocaleDateString('en-PH',{month:'long',day:'numeric',year:'numeric'});
        row.append(heading);
        for (const [field,label,defaultValue] of [['start','From Time','08:00'],['end','To Time','17:00']]) {
          const column=document.createElement('div'); column.className='col-md-4';
          const caption=document.createElement('label'); caption.className='form-label';
          caption.textContent=label+' — '+date;
          const input=document.createElement('input'); input.type='time'; input.required=true;
          input.className='form-control'; input.name='field_validation_days['+date+']['+field+']';
          input.dataset.date=date; input.dataset.field=field;
          input.value=saved[date]?.[field] || defaultValue;
          caption.append(input); column.append(caption); row.append(column);
        }
        dailyRoot.append(row);
      }
    }
    let month = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    let choosingEnd = false;
    const iso = d => [d.getFullYear(), String(d.getMonth()+1).padStart(2,'0'), String(d.getDate()).padStart(2,'0')].join('-');
    const today = iso(new Date());
    const displayDate = value => new Date(value+'T00:00:00').toLocaleDateString('en-PH', {
      month:'short', day:'numeric', year:'numeric'
    });
    const displayWeekday = value => new Date(value+'T00:00:00').toLocaleDateString('en-PH', {
      weekday:'long'
    });
    const dayNumber = value => {
      const [year,monthNumber,day]=value.split('-').map(Number);
      return Date.UTC(year,monthNumber-1,day);
    };
    const durationDays = () => from.value && to.value
      ? Math.round((dayNumber(to.value)-dayNumber(from.value))/86400000)+1 : 0;
    function selectionText() {
      const days=durationDays();
      if(!days) return 'Select a From date and a To date';
      if(days===1) return displayDate(from.value)+' • '+displayWeekday(from.value)+' • 1 Day';
      return displayDate(from.value)+' - '+displayDate(to.value)+' • '+days+' Days';
    }
    function sync() {
      to.min = from.value || today;
      if (from.value && to.value && to.value < from.value) to.value = '';
      renderDaily();
    }
    function draw() {
      popover.replaceChildren();
      const navigation = document.createElement('div');
      navigation.className = 'd-flex justify-content-between align-items-center mb-3';
      for (const offset of [-1,1]) {
        const control=document.createElement('button');
        control.type='button';control.className='btn btn-outline-secondary btn-sm';
        control.textContent=offset<0?'‹ Previous':'Next ›';
        control.addEventListener('click',()=>{month=new Date(month.getFullYear(),month.getMonth()+offset,1);draw()});
        if(offset===1)navigation.append(control);
        else navigation.prepend(control);
      }
      const guidance=document.createElement('span');
      guidance.className='small text-secondary';
      guidance.textContent=choosingEnd?'Choose To date':(from.value&&to.value?'Dates selected':'Choose From date');
      navigation.insertBefore(guidance,navigation.lastChild);
      popover.append(navigation);
      const months=document.createElement('div');months.className='fp-flight-months';
      for(let m=0;m<2;m++){
        const current=new Date(month.getFullYear(),month.getMonth()+m,1);
        const section=document.createElement('section');
        const title=document.createElement('h5');title.className='h6 text-center mb-2';
        title.textContent=current.toLocaleDateString('en-PH',{month:'long',year:'numeric'});
        section.append(title);
        const days=document.createElement('div');days.className='fp-flight-days';
        for(const label of ['Su','Mo','Tu','We','Th','Fr','Sa']){
          const heading=document.createElement('span');
          heading.className='fp-flight-weekday';heading.textContent=label;days.append(heading);
        }
        for(let pad=0;pad<current.getDay();pad++)days.append(document.createElement('span'));
        const count=new Date(current.getFullYear(),current.getMonth()+1,0).getDate();
        for(let i=1;i<=count;i++){
          const date=iso(new Date(current.getFullYear(),current.getMonth(),i));
          const cell=document.createElement('button');cell.type='button';
          cell.className='fp-flight-day';cell.textContent=String(i);cell.setAttribute('aria-label',date);
          cell.disabled=date<today;
          if(date===from.value||date===to.value)cell.classList.add('selected');
          else if(from.value&&to.value&&date>from.value&&date<to.value)cell.classList.add('between');
          cell.addEventListener('click',()=>{
            const selectedEnd=choosingEnd&&!!from.value&&date>=from.value;
            if(!selectedEnd){from.value=date;to.value=date;choosingEnd=true}
            else{to.value=date;choosingEnd=false}
            sync();from.dispatchEvent(new Event('change',{bubbles:true}));
            to.dispatchEvent(new Event('change',{bubbles:true}));draw();
          });
          days.append(cell);
        }
        section.append(days);months.append(section);
      }
      popover.append(months);
      const footer=document.createElement('div');
      footer.className='mt-3 pt-3 border-top';
      const summary=document.createElement('strong');
      summary.className='small';summary.textContent=selectionText();footer.append(summary);
      popover.append(footer);
    }
    from.addEventListener('change',()=>{sync();draw()});
    to.addEventListener('change',()=>{sync();draw()});
    sync();draw();
  });

  const prerequisite=document.querySelector(
    '[data-cenro-memo-prerequisite][data-scroll-target]'
  );
  const targetId=prerequisite?.dataset.scrollTarget||'';
  const target=targetId?document.getElementById(targetId):null;
  if(prerequisite&&target){
    const scrollToTarget=()=>{
      target.classList.add('fp-schedule-prerequisite-highlight');
      target.scrollIntoView({
        behavior:window.matchMedia?.('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',
        block:'center'
      });
    };
    prerequisite.querySelector('[data-scroll-to-prerequisite]')
      ?.addEventListener('click',scrollToTarget);
    window.setTimeout(scrollToTarget,180);
  }
});
