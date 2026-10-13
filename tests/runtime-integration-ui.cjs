'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor(id = '') {
        this.id=id; this.value=''; this.disabled=false; this.hidden=false; this.dataset={};
        this.attributes={}; this.handlers={}; this.children=[]; this.textContent=''; this.classes=new Set();
        this.classList={add:n=>this.classes.add(n),remove:n=>this.classes.delete(n),toggle:(n,on)=>on?this.classes.add(n):this.classes.delete(n),contains:n=>this.classes.has(n)};
        this.parentElement={querySelector:()=>null,contains:target=>target===this};
    }
    setAttribute(k,v){this.attributes[k]=String(v)} removeAttribute(k){delete this.attributes[k]}
    addEventListener(k,fn){(this.handlers[k] ||= []).push(fn)}
    setCustomValidity(v){this.validityMessage=v}
    replaceChildren(...items){this.children=items}
    appendChild(item){this.children.push(item);return item}
    querySelectorAll(selector){return selector==='[role="option"]'?this.children.filter(item=>item.attributes.role==='option'):this.children}
    querySelector(){return null}
    closest(){return null}
    focus(){document.activeElement=this;for(const fn of this.handlers.focus||[])fn()}
    scrollIntoView(){}
}
const events=[];
const document={activeElement:null,body:new Element('body'),createElement:()=>new Element(),getElementById:()=>null,querySelectorAll:()=>[],addEventListener(){},dispatchEvent:event=>{events.push(event);return true}};
const context={window:{innerWidth:1280,location:{href:'http://localhost/'},addEventListener(){}},document,CustomEvent:class{constructor(type,init){this.type=type;this.detail=init?.detail}},setTimeout,clearTimeout,Intl,Map,Set,console,URL,fetch};
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/curriculum-common.js','utf8'),context);
const ui=context.window.CurriculumUI;

(async()=>{
    const count=new Element('versionCount');
    ui.setCountState(count,'loading');
    assert.equal(count.textContent,'Loading...');
    assert.equal(count.dataset.loadState,'loading');
    ui.setCountState(count,'error');
    assert.equal(count.textContent,'Not loaded','failed request is never presented as zero records');
    ui.setCount(count,0);
    assert.equal(count.textContent,'0 records','successful empty data remains a distinct state');

    const levels=['program','curriculum','version'].map(name=>({input:new Element(name+'Input'),valueInput:new Element(name+'Id'),list:new Element(name+'List'),search:async()=>[],getId:item=>item.id,format:item=>({label:item.label})}));
    const cascade=ui.createCascadingAutocomplete({levels,restoreSavedContext:false});
    cascade.setContext([{id:7,label:'Synthetic program'},{id:9,label:'Synthetic curriculum'},{id:12,label:'Synthetic revision'}]);
    assert.equal(cascade.selectors.map(item=>item.getSelectedId()).join(','),'7,9,12');
    assert.equal(levels[1].input.disabled,false,'authoritative context enables curriculum control');
    assert.equal(levels[2].input.disabled,false,'authoritative context enables version control');
    cascade.selectors[0].setSelected({id:8,label:'Other synthetic program'});
    assert.equal(cascade.selectors[1].getSelectedId(),'','changing parent clears stale curriculum ID');
    assert.equal(cascade.selectors[2].getSelectedId(),'','changing parent clears stale version ID');

    let attempts=0;
    const input=new Element('search'),list=new Element('options');
    const search=ui.createAutocomplete({input,valueInput:new Element('id'),list,minimumLength:1,search:async()=>{attempts++;const error=new Error('Schema compatibility issue.');error.status=503;error.code='schema_mismatch';throw error}});
    input.value='working';search.refresh();await new Promise(resolve=>setTimeout(resolve,0));
    assert.equal(list.children[0].textContent,'Schema compatibility issue.','backend failure remains an error state');
    const retry=list.children.find(item=>item.textContent==='Retry search');
    assert.ok(retry,'failed search offers a retry action');
    retry.handlers.click[0]();await new Promise(resolve=>setTimeout(resolve,0));
    assert.equal(attempts,2,'retry repeats the requested search');

    const builder=fs.readFileSync('modules/curriculum/assets/curriculum-builder.js','utf8');
    assert.match(builder,/setCountState\([^;]*'error'\)/);
    assert.match(builder,/Retry loading versions/);
    const mapping=fs.readFileSync('modules/curriculum/assets/subject-mapping-final.js','utf8');
    assert.match(mapping,/mappingContextCascade\.setContext\(\[program,curriculum,version\]\)/);
    assert.match(mapping,/const request=\+\+state\.cloRequest[\s\S]*?request!==state\.cloRequest/);
    const dashboard=fs.readFileSync('js/curriculum-dashboard.js','utf8');
    assert.match(dashboard,/request!==state\.metricsRequest[\s\S]*?data\.context\.curriculum_id[\s\S]*?data\.context\.program_id/);
    assert.match(dashboard,/request!==state\.metricsRequest[\s\S]*?data\.context\.curriculum_id[\s\S]*?data\.context\.program_id/);
    assert.ok(events.some(event=>event.type==='csms:curriculum-context-selection'));
    console.log('PASS: truthful count states, enabled and invalidated version context, retryable backend errors, and dashboard/mapping stale-context guards');
})().catch(error=>{console.error(error);process.exitCode=1});