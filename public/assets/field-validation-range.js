/** FP-OMS two-month airline-style range selector; native date inputs remain fallback. */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-fp-flight-calendar]').forEach(root => {
    const from = root.querySelector('[data-fp-flight-from]');
    const to = root.querySelector('[data-fp-flight-to]');
    const popover = root.querySelector('[data-fp-flight-popover]');
    if (!from || !to || !popover) return;
    const fromTime=root.querySelector('input[name="field_validation_from_time"]');
    const toTime=root.querySelector('input[name="field_validation_to_time"]');
    const status=document.createElement('div');
    status.className='small mt-2';
    status.setAttribute('role','status');
    status.setAttribute('aria-live','polite');
    root.append(status);
    function validate() {
      if (!fromTime || !toTime) return;
      let message='';
      const start=from.value && fromTime.value ? from.value+'T'+fromTime.value : '';
      const finish=to.value && toTime.value ? to.value+'T'+toTime.value : '';
      const now=new Date();
      if(start && new Date(start).getTime()<=now.getTime()){
        message='From date and time must be in the future.';
      } else if(start && finish && finish<=start) {
        message='To date and time must be later than From date and time.';
      }
      toTime.setCustomValidity(message);
      status.textContent=message;
      status.classList.toggle('text-danger',!!message);
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
      validate();
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
    [fromTime,toTime].filter(Boolean).forEach(input=>input.addEventListener('change',validate));
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
