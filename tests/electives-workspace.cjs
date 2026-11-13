'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Element {
 constructor(id){Object.assign(this,{id,value:'',hidden:false,disabled:false,innerHTML:'',textContent:'',handlers:{},dataset:{},attributes:{},style:{}});}
 querySelectorAll(){return [];}
 addEventListener(k,fn){this.handlers[k]=fn;} setAttribute(k,v){this.attributes[k]=v;} focus(){} reset(){Object.values(this.elements||{}).forEach(e=>e.value='');if(this.elements?.required_selections)this.elements.required_selections.value='1';}
 querySelector(){return el(this.id+'Submit');}
}
const elements=new Map(),el=id=>{if(!elements.has(id))elements.set(id,new Element(id));return elements.get(id);};
const root=el('electivesWorkspace');root.dataset={groupApi:'/electives',canManageElectives:'1'};
const form=el('groupForm');form.elements=Object.fromEntries(['group_name','year_level','semester','required_selections','status'].map(k=>[k,el(k)]));
const requests=[],writes=[],modals={},selectors=[];let cascade,selected=false,response,pending;
const esc=s=>String(s??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;');
const ui={...require('./search-ranking.cjs'),debounce:fn=>fn,escapeHtml:esc,formatUnits:v=>v,statusBadge:s=>esc(s),showToast(){},showFormError:(f,m)=>f.error=m,clearFormError:f=>f.error='',setFormSubmitting(){},
 createModal:element=>{if(!element)return null;return modals[element.id]={open(){element.hidden=false;},close(){element.hidden=true;}};},
 createCascadingAutocomplete:config=>{cascade=config;return{hasSelection:()=>selected};},createAutocomplete:config=>{selectors.push(config);return{clear(){}};},
 fetchJson:async(api,args)=>{requests.push(args);if(pending)return await pending;return response;},postForm:async(api,data)=>{if(globalThis.failNext){globalThis.failNext=false;throw new Error('Refused by server');}writes.push(data);return{success:true};}};
class Data{constructor(f){this.values={};if(f)Object.entries(f.elements).forEach(([k,e])=>this.set(k,e.value));}set(k,v){this.values[k]=v;}append(k,v){(this.values[k]??=[]).push(v);}}
const win={CurriculumUI:ui,CurriculumPlacementView:{yearLabel:v=>v==='3rd'?'3rd Year':v}};
vm.runInNewContext(fs.readFileSync('js/csms-menu.js','utf8'),{window:win,console});
vm.runInNewContext(fs.readFileSync('js/csms-review.js','utf8'),{window:win,console});
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/electives-manager.js','utf8'),{document:{getElementById:el,querySelector:()=>el('tabs')},window:win,FormData:Data,URLSearchParams,console});
const editor_close=()=>modals.groupFormModal.close();
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const s=(id,year='3rd Year')=>({subject_id:id,subject_status:'active',subject_code:'S'+id,subject_title:id===1?'<script>unsafe</script>':'Subject '+id,units:3,year_level:year,semester:'2nd Semester'});
const base={groups:[{id:10,group_name:'<b>Group</b>',status:'active',year_level:'3rd Year',semester:'2nd Semester',required_selections:1,members:[s(1),s(2)]}],placements:[s(1),s(2),s(3,'4th Year')],years:['3rd Year','4th Year'],terms:['2nd Semester'],prerequisites:[{subject_id:2,subject_code:'S1',subject_title:'Prior'}]};
(async()=>{
 assert.equal(el('groupsPanel').hidden,true);assert.match(el('electiveState').innerHTML,/Select a Program/);
 for(const [i,key]of ['program','curriculum','version'].entries()){assert.equal(cascade.levels[i].minimumLength,0);el(key+'Id').value=String(i+1);}
 response=base;selected=true;cascade.onChange();await tick();
 assert.equal(requests[0].program_id,'1');assert.equal(requests[0].curriculum_version_id,'3');assert.equal(el('createElectiveGroupButton').disabled,false);
 assert.match(el('groupCards').innerHTML,/&lt;b>Group/);assert.doesNotMatch(el('groupCards').innerHTML,/<script>/);assert.match(el('reviewResult').innerHTML,/No elective group issues detected/);
 el('reviewTab').handlers.click();assert.equal(el('reviewPanel').hidden,false);assert.equal(el('groupsPanel').hidden,true);
 el('groupsTab').handlers.click();el('groupSearch').value='not present';el('groupSearch').handlers.input();assert.match(el('groupCards').innerHTML,/No groups match these filters/);assert.match(el('groupCards').innerHTML,/data-clear-filters/);assert.match(el('groupSummary').innerHTML,/<strong>0<\/strong> groups/);
 root.handlers.click({target:{closest:selector=>selector==='[data-clear-filters]'?{}:null}});assert.equal(el('groupSearch').value,'');assert.match(el('groupSummary').innerHTML,/<strong>1<\/strong> group /);assert.match(el('groupSummary').innerHTML,/<strong>2<\/strong> pool subjects/);
 el('groupSearch').value='subject 2';el('groupSearch').handlers.input();assert.match(el('groupCards').innerHTML,/&lt;b>Group/,'search also matches pool subjects');
 el('groupSearch').value='zzz';el('groupSearch').handlers.input();assert.match(el('groupCards').innerHTML,/No groups match/);el('groupSearch').handlers.keydown({key:'Escape'});assert.equal(el('groupSearch').value,'');assert.match(el('groupCards').innerHTML,/csms-term/);
 assert.equal(el('statusFilter').value,'');el('groupSearch').value='';el('groupSearch').handlers.input();
 // Empty states: a version with no groups shows the honest empty state with the primary action; filters never do.
 {const keep=response;response={...base,groups:[]};cascade.onChange();await tick();
  const empty=el('groupCards').innerHTML;assert.match(empty,/No elective groups yet/);assert.match(empty,/An elective group lets a curriculum require N subjects from a pool in one term./);assert.match(empty,/data-create-group[^>]*>Create Elective Group</);assert.doesNotMatch(empty,/No groups match|data-clear-filters/);
  assert.match(el('reviewResult').innerHTML,/No elective groups to check/);assert.doesNotMatch(el('reviewResult').innerHTML,/No elective group issues detected/);
  root.handlers.click({target:{closest:selector=>selector==='[data-create-group]'?{}:null}});assert.equal(el('groupFormTitle').textContent,'Create Elective Group','the empty-state button opens the same dialog as the header button');
  el('groupSearch').value='zzz';el('groupSearch').handlers.input();assert.doesNotMatch(el('groupCards').innerHTML,/No groups match/,'no groups at all: never claim a filter hid them');assert.match(el('groupCards').innerHTML,/No elective groups yet/);
  el('groupSearch').value='';el('groupSearch').handlers.input();response=keep;cascade.onChange();await tick();assert.match(el('groupCards').innerHTML,/csms-term/);}
 const click=(kind,id)=>root.handlers.click({target:{closest:selector=>selector==='[data-view],[data-edit],[data-archive]'?{dataset:{[kind]:String(id)}}:null}});
 const hit=(selector,dataset,extra={})=>root.handlers.click({target:{closest:s=>s===selector?{dataset,setAttribute(){},focus(){},...extra}:null}});
 // Slice 2: grouped table, row click, row menu, detail dialog.
 {const html=el('groupCards').innerHTML;
  assert.equal((html.match(/class="csms-term"/g)||[]).length,1);assert.match(html,/3rd Year \u00b7 2nd Semester/);assert.match(html,/1 group</);assert.match(html,/Choose 1/);assert.match(html,/2 subjects \u00b7 6 units/);
  assert.equal((html.match(/class="csms-row-click"/g)||[]).length,1);assert.match(html,/csms-ctable__code">&lt;b>Group/);assert.doesNotMatch(html,/<th scope="col">Type<\/th>/,'No Type column until the workspace carries a type');assert.doesNotMatch(html,/data-warn=/);}
 hit('[data-row]',{row:'10'});assert.equal(el('viewGroupModal').hidden,false);assert.match(el('viewGroupTitle').textContent,/Group/);
 {const body=el('viewGroupBody').innerHTML;assert.match(body,/<th class="csms-ctable__code" scope="col">Code<\/th>/);assert.match(body,/Subject 2/);assert.match(body,/&lt;script>unsafe/);assert.doesNotMatch(body,/<script>/);assert.match(body,/curriculum_builder.php/);assert.doesNotMatch(body,/Prerequisite|subject_mapping/);assert.match(body,/csms-chip--active/);}
 el('viewGroupModal').hidden=true;
 hit('[data-menu]',{menu:'10'});assert.equal(el('electiveRowMenu').hidden,false);assert.match(el('electiveRowMenu').innerHTML,/View pool/);assert.match(el('electiveRowMenu').innerHTML,/Edit group/);assert.match(el('electiveRowMenu').innerHTML,/is-danger/);
 el('electiveRowMenu').handlers.keydown({key:'Escape',target:el('electiveRowMenu'),preventDefault(){}});assert.equal(el('electiveRowMenu').hidden,true,'Escape closes the menu');
 hit('[data-menu]',{menu:'10'});await el('electiveRowMenu').handlers.click({target:{dataset:{menuAction:'edit',group:'10'},closest:()=>({dataset:{menuAction:'edit',group:'10'}})}});
 assert.equal(el('electiveRowMenu').hidden,true,'Choosing an item closes the menu');assert.equal(el('groupFormTitle').textContent,'Edit Elective Group');editor_close();
 click('view',10);assert.match(el('viewGroupBody').innerHTML,/Subject 2/);
 el('createElectiveGroupButton').handlers.click();assert.equal(el('groupFormTitle').textContent,'Create Elective Group');
 form.elements.year_level.value='3rd Year';form.elements.semester.value='2nd Semester';form.elements.group_name.value='New';form.elements.status.value='active';
 const autocomplete=selectors[0];assert.equal((await autocomplete.search('S1')).length,1);autocomplete.onSelect(s(1));assert.equal((await autocomplete.search('S1')).length,0);
 assert.equal((await autocomplete.search('S3')).length,0,'Incompatible placement excluded');
 base.placements.push({...s(4),subject_status:'inactive'});assert.equal((await autocomplete.search('S4')).length,0,'Inactive excluded');
 form.elements.semester.value='';assert.equal((await autocomplete.search('')).length,0,'Placement required');form.elements.semester.value='2nd Semester';
 autocomplete.onSelect(s(1));assert.equal((el('selectedSubjects').innerHTML.match(/data-remove=/g)||[]).length,1);
 {const html=el('selectedSubjects').innerHTML;assert.match(html,/elective-chip/);assert.match(html,/<th class="csms-ctable__code" scope="col">Code<\/th>/);assert.match(html,/csms-ctable__num">3</);assert.match(html,/&lt;script>unsafe/);assert.doesNotMatch(html,/<script>/);assert.match(el('poolMessage').textContent,/at least one more/);assert.match(el('poolMessage').className,/is-warning/);}
 autocomplete.onSelect(s(3,'4th Year'));assert.equal(el('placementWarning').hidden,false);assert.match(el('mappingLink').href,/^curriculum_builder.php\?program_id=1&curriculum_id=2&curriculum_version_id=3&version_id=3$/);
 el('selectedSubjects').handlers.click({target:{closest:()=>({dataset:{remove:'3'}})}});assert.equal(el('placementWarning').hidden,true);
 form.elements.required_selections.value='2';await form.handlers.submit({preventDefault(){}});assert.match(form.error,/at least two/);assert.equal(writes.length,0);
 autocomplete.onSelect(s(2));form.elements.required_selections.value='2';form.elements.required_selections.handlers.input();assert.equal(el('allRequiredWarning').hidden,false);assert.doesNotMatch(el('poolMessage').className,/is-warning/);assert.match(el('poolMessage').textContent,/2 subjects · 6 units\. Students choose 2/);
 form.elements.required_selections.value='3';form.elements.required_selections.handlers.input();assert.match(el('poolMessage').textContent,/pool has 2 subjects but students must choose 3/);assert.match(el('poolMessage').className,/is-warning/);form.elements.required_selections.value='2';form.elements.required_selections.handlers.input();
 form.elements.required_selections.value='1';globalThis.failNext=true;await form.handlers.submit({preventDefault(){}});assert.equal(form.error,'Refused by server','Server refusal is shown inside the dialog');assert.equal(el('groupFormModal').hidden,false,'The dialog stays open');assert.equal(writes.length,0);
 await form.handlers.submit({preventDefault(){}});assert.equal(writes[0].values.action,'create');assert.deepEqual(writes[0].values['subject_ids[]'],[1,2]);assert.equal(writes[0].values.program_id,'1');
 click('edit',10);assert.equal(form.elements.group_name.value,'<b>Group</b>');assert.equal(form.elements.required_selections.value,1);
 click('archive',10);assert.equal(writes.length,1);assert.equal(el('archiveGroupModal').hidden,false);await el('confirmArchive').handlers.click();assert.equal(writes[1].values.action,'archive');assert.equal(writes[1].values.id,10);
 // A stale response cannot resurrect cards after a context reset.
 let release;pending=new Promise(resolve=>{release=resolve;});cascade.onChange();selected=false;el('curriculumId').value='';el('versionId').value='';cascade.onChange();release(base);await tick();pending=null;
 assert.equal(el('createElectiveGroupButton').disabled,true);assert.equal(el('groupsPanel').hidden,true);assert.equal(el('groupCards').innerHTML,'');
 const bad={...base,groups:[{...base.groups[0],members:[s(1),s(3,'4th Year')]},{id:12,group_name:'Dup',status:'active',year_level:'3rd Year',semester:'2nd Semester',required_selections:2,members:[s(1)]},{id:13,group_name:'Inact',status:'active',year_level:'3rd Year',semester:'2nd Semester',required_selections:1,members:[{...s(5),subject_status:'retired'},s(2)]},{id:14,group_name:'Empty',status:'active',year_level:null,semester:null,required_selections:1,members:[]},{id:15,group_name:'Gone',status:'archived',year_level:null,semester:null,required_selections:1,members:[]}]};
 response=bad;selected=true;el('curriculumId').value='2';el('versionId').value='3';cascade.onChange();await tick();
 {const html=el('reviewChecks').innerHTML,count=key=>Number(new RegExp('data-review-issue="'+key+'"[\\s\\S]*?csms-check__count[^>]*>(\\d+)<').exec(html)[1]);
  assert.match(html,/Group checks/);assert.equal(count('empty'),1,'archived groups are not checked');assert.equal(count('small'),1);assert.equal(count('shared'),1);assert.equal(count('inactive'),1);assert.equal(count('noplacement'),1);assert.equal(count('mismatch'),1);
  assert.match(html,/Same subject in two groups <span class="csms-chip csms-chip--warning">Warning/);assert.doesNotMatch(html,/Duplicate|Unmapped|Missing Context/);assert.equal(el('reviewResult').innerHTML,'');}
 el('reviewChecks').handlers.click({target:{closest:sel=>sel==='[data-review-issue]'?{dataset:{reviewIssue:'inactive'},getAttribute:()=>null}:null}});
 {const html=el('reviewChecks').innerHTML;assert.match(html,/Status: retired\./);assert.match(html,/Edit group/);}
 el('reviewChecks').handlers.click({target:{closest:sel=>sel==='[data-review-issue]'?{dataset:{reviewIssue:'mismatch'},getAttribute:()=>null}:null}});
 assert.match(el('reviewChecks').innerHTML,/curriculum_builder\.php/);assert.doesNotMatch(el('reviewChecks').innerHTML,/subject_mapping/);assert.match(el('reviewChecks').innerHTML,/placed in 4th Year \/ 2nd Semester/);
 // The warning icon opens that group's issues (the server's message), read in the dialog.
 assert.match(el('groupCards').innerHTML,/data-warn="10"/);hit('[data-warn]',{warn:'10'});assert.equal(el('warnGroupModal').hidden,false);assert.match(el('warnGroupTitle').textContent,/2 issues/);assert.match(el('warnGroupBody').innerHTML,/Pool subject placed in a different term/);assert.match(el('warnGroupBody').innerHTML,/Same subject in two groups/);
 // Type column appears only when the workspace carries a type; unset reads "Type not set".
 response={...base,groups:[{...base.groups[0],elective_type:'professional'},{...base.groups[0],id:11,group_name:'No term',year_level:null,semester:null,status:'inactive',elective_type:null,members:[]}]};cascade.onChange();await tick();
 {const html=el('groupCards').innerHTML;assert.match(html,/<th scope="col">Type<\/th>/);assert.match(html,/csms-chip--type">Professional Elective/);assert.match(html,/csms-chip--type-unset">Type not set/);assert.ok(html.indexOf('Year level not specified')>html.indexOf('3rd Year'),'A group with no term sorts last');assert.match(html,/csms-chip--inactive">Inactive/);assert.match(html,/0 subjects/);}assert.doesNotMatch(el('reviewIssues').innerHTML,/No elective group issues/);
 console.log('PASS: electives mock-DOM context reset/stale response, filters, escaping, review counts, grouped term tables, row click, shared row menu (Escape, item select), issues dialog, type column/chip, detail dialog pool table, create/edit/archive confirmation, membership uniqueness and selection validation. Browser rendering is checked separately.');
})().catch(e=>{console.error(e);process.exitCode=1;});
