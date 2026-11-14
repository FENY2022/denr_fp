/** FP-OMS two-month airline-style range selector; native date inputs remain fallback. */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-fp-flight-calendar]').forEach(root => {
    const from = root.querySelector('[data-fp-flight-from]');
    const to = root.querySelector('[data-fp-flight-to]');
    const button = root.querySelector('[data-fp-flight-open]');
    const popover = root.querySelector('[data-fp-flight-popover]');
    if (!from || !to || !button || !popover) return;
    let month = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    let choosingEnd = false;
    const iso = d => [d.getFullYear(), String(d.getMonth()+1).padStart(2,'0'), String(d.getDate()).padStart(2,'0')].join('-');
    const today = iso(new Date());
    function sync() {
      to.min = from.value || today;
      if (from.value && to.value && to.value < from.value) to.value = '';
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
      guidance.textContent=choosingEnd?'Choose To date':'Choose From date';
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
            if(!choosingEnd||!from.value||date<from.value){from.value=date;to.value='';choosingEnd=true}
            else{to.value=date;choosingEnd=false;popover.hidden=true}
            sync();from.dispatchEvent(new Event('change',{bubbles:true}));
            to.dispatchEvent(new Event('change',{bubbles:true}));draw();
          });
          days.append(cell);
        }
        section.append(days);months.append(section);
      }
      popover.append(months);
    }
    button.addEventListener('click',()=>{popover.hidden=!popover.hidden;if(!popover.hidden)draw()});
    from.addEventListener('change',()=>{sync();choosingEnd=!!from.value&&!to.value;if(!popover.hidden)draw()});
    to.addEventListener('change',()=>{sync();if(!popover.hidden)draw()});
    document.addEventListener('click',event=>{if(!root.contains(event.target))popover.hidden=true});
    sync();draw();
  });
});
