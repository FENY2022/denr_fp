'use strict';
// DOM-only stopwatch safety checks for 30-minute revert UI.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const source=fs.readFileSync(path.resolve(
  __dirname,'../public/assets/permit-revert.js'),'utf8');

function element() {
  const classes=new Set(['d-none']);
  return {
    dataset:{},disabled:false,textContent:'',
    classList:{add:x=>classes.add(x),remove:x=>classes.delete(x),
      contains:x=>classes.has(x)}
  };
}
function check(initial,advance) {
  const counter=element(),trigger=element(),confirm=element(),expired=element();
  counter.dataset.seconds=String(initial);
  const elements={
    '[data-step2-revert-countdown]':counter,
    '[data-step2-revert-trigger]':trigger,
    '[data-step2-revert-submit]':confirm,
    '[data-step2-revert-expired]':expired
  };
  let now=1_000_000, tick;
  const events={};
  vm.runInNewContext(source,{
    document:{querySelector:key=>elements[key]||null},
    window:{addEventListener:(key,fn)=>{events[key]=fn}},
    Date:{now:()=>now},
    setInterval:fn=>{tick=fn;return 1},
    clearInterval:()=>{}
  },{timeout:1000});
  now+=advance*1000;
  tick();
  return {counter,trigger,confirm,expired,events};
}
const initial=check(1800,0);
assert.equal(initial.counter.textContent,'30:00');
assert.equal(initial.trigger.disabled,false);
const within=check(1800,1799);
assert.equal(within.counter.textContent,'00:01');
assert.equal(within.confirm.disabled,false);
const expired=check(1800,1800);
assert.equal(expired.counter.textContent,'00:00');
assert.equal(expired.trigger.disabled,true);
assert.equal(expired.confirm.disabled,true);
assert.equal(expired.expired.classList.contains('d-none'),false);
const restored=check(1500,1);
restored.events.pageshow({persisted:true});
assert.equal(restored.trigger.disabled,true,'bfcache navigation does not re-enable stale eligibility');
console.log('PASS: Revert countdown, 30-minute expiration, button and bfcache safeguards.');
