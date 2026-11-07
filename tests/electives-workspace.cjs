'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Element {
 constructor(id){Object.assign(this,{id,value:'',hidden:false,disabled:false,innerHTML:'',textContent:'',handlers:{},dataset:{},attributes:{}});}
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
 fetchJson:async(api,args)=>{requests.push(args);if(pending)return await pending;return response;},postForm:async(api,data)=>{writes.push(data);return{success:true};}};
class Data{constructor(f){this.values={};if(f)Object.entries(f.elements).forEach(([k,e])=>this.set(k,e.value));}set(k,v){this.values[k]=v;}append(k,v){(this.values[k]??=[]).push(v);}}
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/electives-manager.js','utf8'),{document:{getElementById:el,querySelector:()=>el('tabs')},window:{CurriculumUI:ui,CurriculumPlacementView:{yearLabel:v=>v==='3rd'?'3rd Year':v}},FormData:Data,URLSearchParams,console});
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const s=(id,year='3rd Year')=>({subject_id:id,subject_status:'active',subject_code:'S'+id,subject_title:id===1?'<script>unsafe</script>':'Subject '+id,units:3,year_level:year,semester:'2nd Semester'});
const base={groups:[{id:10,group_name:'<b>Group</b>',status:'active',year_level:'3rd Year',semester:'2nd Semester',required_selections:1,members:[s(1),s(2)]}],placements:[s(1),s(2),s(3,'4th Year')],years:['3rd Year','4th Year'],terms:['2nd Semester'],prerequisites:[{subject_id:2,subject_code:'S1',subject_title:'Prior'}],review:{counts:{empty:0,selection:0,duplicate:0,mismatch:0,unmapped:0,inactive:0},issues:[]}};
(async()=>{
 assert.equal(el('groupsPanel').hidden,true);assert.match(el('electiveState').innerHTML,/Select a Program/);
 for(const [i,key]of ['program','curriculum','version'].entries()){assert.equal(cascade.levels[i].minimumLength,0);el(key+'Id').value=String(i+1);}
 response=base;selected=true;cascade.onChange();await tick();
 assert.equal(requests[0].program_id,'1');assert.equal(requests[0].curriculum_version_id,'3');assert.equal(el('createElectiveGroupButton').disabled,false);
 assert.match(el('groupCards').innerHTML,/&lt;b>Group/);assert.doesNotMatch(el('groupCards').innerHTML,/<script>/);assert.match(el('reviewIssues').innerHTML,/No elective configuration issues detected/);
 el('reviewTab').handlers.click();assert.equal(el('reviewPanel').hidden,false);assert.equal(el('groupsPanel').hidden,true);
 el('groupsTab').handlers.click();el('groupSearch').value='not present';el('groupSearch').handlers.input();assert.match(el('groupCards').innerHTML,/No groups match these filters/);assert.match(el('groupCards').innerHTML,/data-clear-filters/);assert.match(el('groupSummary').innerHTML,/<strong>0<\/strong> groups/);
 root.handlers.click({target:{closest:selector=>selector==='[data-clear-filters]'?{}:null}});assert.equal(el('groupSearch').value,'');assert.match(el('groupSummary').innerHTML,/<strong>1<\/strong> group /);assert.match(el('groupSummary').innerHTML,/<strong>2<\/strong> pool subjects/);
 el('groupSearch').value='subject 2';el('groupSearch').handlers.input();assert.match(el('groupCards').innerHTML,/&lt;b>Group/,'search also matches pool subjects');
 el('groupSearch').value='zzz';el('groupSearch').handlers.input();assert.match(el('groupCards').innerHTML,/No groups match/);el('groupSearch').handlers.keydown({key:'Escape'});assert.equal(el('groupSearch').value,'');assert.match(el('groupCards').innerHTML,/elective-grid/);
 assert.equal(el('statusFilter').value,'');el('groupSearch').value='';el('groupSearch').handlers.input();
 const click=(kind,id)=>root.handlers.click({target:{closest:selector=>['[data-create-group]','[data-clear-filters]'].includes(selector)?null:({dataset:{[kind]:String(id)}})}});
 click('view',10);assert.match(el('viewGroupBody').innerHTML,/Prerequisite: S1/);assert.equal((el('viewGroupBody').innerHTML.match(/prerequisite_configuration.php/g)||[]).length,1);assert.match(el('viewGroupBody').innerHTML,/Manage in Pre-requisite Configuration/);
 el('createElectiveGroupButton').handlers.click();assert.equal(el('groupFormTitle').textContent,'Create Elective Group');
 form.elements.year_level.value='3rd Year';form.elements.semester.value='2nd Semester';form.elements.group_name.value='New';form.elements.status.value='active';
 const autocomplete=selectors[0];assert.equal((await autocomplete.search('S1')).length,1);autocomplete.onSelect(s(1));assert.equal((await autocomplete.search('S1')).length,0);
 assert.equal((await autocomplete.search('S3')).length,0,'Incompatible placement excluded');
 base.placements.push({...s(4),subject_status:'inactive'});assert.equal((await autocomplete.search('S4')).length,0,'Inactive excluded');
 form.elements.semester.value='';assert.equal((await autocomplete.search('')).length,0,'Placement required');form.elements.semester.value='2nd Semester';
 autocomplete.onSelect(s(1));assert.equal((el('selectedSubjects').innerHTML.match(/data-remove=/g)||[]).length,1);
 autocomplete.onSelect(s(3,'4th Year'));assert.equal(el('placementWarning').hidden,false);assert.match(el('mappingLink').href,/subject_mapping.php\?program_id=1&curriculum_id=2&curriculum_version_id=3&version_id=3&subject_id=3/);
 el('selectedSubjects').handlers.click({target:{closest:()=>({dataset:{remove:'3'}})}});assert.equal(el('placementWarning').hidden,true);
 form.elements.required_selections.value='2';await form.handlers.submit({preventDefault(){}});assert.match(form.error,/at least two/);assert.equal(writes.length,0);
 autocomplete.onSelect(s(2));form.elements.required_selections.value='2';form.elements.required_selections.handlers.input();assert.equal(el('allRequiredWarning').hidden,false);
 form.elements.required_selections.value='1';await form.handlers.submit({preventDefault(){}});assert.equal(writes[0].values.action,'create');assert.deepEqual(writes[0].values['subject_ids[]'],[1,2]);assert.equal(writes[0].values.program_id,'1');
 click('edit',10);assert.equal(form.elements.group_name.value,'<b>Group</b>');assert.equal(form.elements.required_selections.value,1);
 click('archive',10);assert.equal(writes.length,1);assert.equal(el('archiveGroupModal').hidden,false);await el('confirmArchive').handlers.click();assert.equal(writes[1].values.action,'archive');assert.equal(writes[1].values.id,10);
 // A stale response cannot resurrect cards after a context reset.
 let release;pending=new Promise(resolve=>{release=resolve;});cascade.onChange();selected=false;el('curriculumId').value='';el('versionId').value='';cascade.onChange();release(base);await tick();pending=null;
 assert.equal(el('createElectiveGroupButton').disabled,true);assert.equal(el('groupsPanel').hidden,true);assert.equal(el('groupCards').innerHTML,'');
 response={...base,review:{counts:{...base.review.counts,mismatch:1},issues:[{type:'mismatch',group_id:10,group_name:'Group',subject_id:2,message:'Different placement'}]}};
 selected=true;el('curriculumId').value='2';el('versionId').value='3';cascade.onChange();await tick();assert.match(el('reviewIssues').innerHTML,/Review required/);assert.match(el('reviewIssues').innerHTML,/Open Subject Mapping/);assert.doesNotMatch(el('reviewIssues').innerHTML,/No elective group issues/);
 console.log('PASS: electives mock-DOM context reset/stale response, filters, escaping, review counts, create/view/edit/archive confirmation, membership uniqueness, mismatch navigation, prerequisite links and selection validation. Browser rendering remains unverified.');
})().catch(e=>{console.error(e);process.exitCode=1;});
