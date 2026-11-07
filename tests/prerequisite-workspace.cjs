'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Element {
 constructor(id){Object.assign(this,{id,value:'',hidden:false,disabled:false,checked:false,textContent:'',dataset:{},style:{},handlers:{},attributes:{},clientWidth:900,clientHeight:520,scrollTop:0,scrollLeft:0,tabIndex:0});}
 addEventListener(name,fn){(this.handlers[name] ||= []).push(fn);}
 async emit(name,event={}){for(const fn of this.handlers[name]||[])await fn({target:this,preventDefault(){},...event});}
 setAttribute(k,v){this.attributes[k]=v;} hasAttribute(k){return k==='data-add-focused'&&this.dataset.addFocused!==undefined;}
 getAttribute(k){return this.attributes[k];} focus(){} reset(){} scrollIntoView(){}
 closest(){return this;} matches(s){return s==='[data-row]'&&this.article;}
 querySelector(s){return s==='svg' && this.innerHTML?.includes('<svg') ? {} : null;}
 click(){return docClick({target:this});}
}
const elements=new Map(),el=id=>{if(!elements.has(id))elements.set(id,new Element(id));return elements.get(id);};
const tabs=['rules','chain','review'].map(t=>{const e=el('tab-'+t);e.dataset.tab=t;return e;});
const root=el('prerequisiteWorkspace');root.dataset={requirementApi:'req',programApi:'program',curriculumApi:'curriculum',versionApi:'version',canManageRequirements:'1'};
const p=(id,extra={})=>({period_position:1,subject_id:id,subject_code:'CC'+id,subject_title:'Subject '+id,year_level:'1st Year',semester:'1st Semester',...extra});
const e=(id,t,r)=>({id,subject_id:t,required_subject_id:r});
let placements=Array.from({length:105},(_,i)=>p(i+1)),edges=[e(1,3,1),e(2,3,2),e(3,4,3)],review={cycles:[],duplicates:[],sequencing:[],missing:[]};
const programs=[{id:1,program_code:'BSIT',program_name:'College IT',program_type:'College'},{id:2,program_code:'SHS',program_name:'Senior High',program_type:'SHS'}],curriculum={id:3,academic_program_id:1,curriculum_code:'DEMO'},version={id:4,curriculum_id:3,version_label:'TEST'};
const requests=[],posts=[],autocomplete={},modals={},errors=[],toasts=[];let cascade,docClick;
const ui={...require('./search-ranking.cjs'),escapeHtml:v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;'),debounce:fn=>fn,showToast:(m,t)=>toasts.push([m,t]),clearFormError:()=>{},showFormError:(f,m)=>errors.push(m),
 createModal:element=>element?(modals[element.id]={open(){element.hidden=false;},close(){element.hidden=true;}}):null,
 createAutocomplete(options){const c={...options,clear(notify){options.input.value='';options.valueInput.value='';if(notify!==false)options.onClear?.();},setSelected(p,notify){options.valueInput.value=String(options.getId(p));options.input.value=options.format(p).label;if(notify!==false)options.onSelect?.(p);}};autocomplete[options.input.id]=c;return c;},
 createCascadingAutocomplete(config){cascade=config;const controls=config.levels.map(o=>this.createAutocomplete(o));return{get:i=>controls[i]};},
 async fetchJson(url,q){requests.push([url,q]);if(q.action==='preview_sequence')return {validation:{valid:!q.required_ids.split(',').some(id=>Number(id)===2 && Number(q.subject_id)===1),conflicts:[{message:'A prerequisite must be placed in an earlier academic period.'}]}};if(url==='req')return{placements:placements.map(p=>({...p})),relationships:edges.map(e=>({...e})),review};if(url==='program')return{programs,program:programs[0]};if(url==='curriculum')return{curricula:[curriculum],curriculum};if(url==='version')return{versions:[version],version};throw Error(url);},
 async postForm(url,data){posts.push(Object.fromEntries(data));posts.at(-1).required=data.getAll('required_subject_ids[]');posts.at(-1).expected=data.getAll('expected_ids[]');return{message:'Saved'};}
};
const document={getElementById:el,querySelector:s=>el(s),querySelectorAll:s=>s==='[data-tab]'?tabs:[],addEventListener:(event,fn)=>{if(event==='click')docClick=fn;}};
const win={CurriculumUI:ui};vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/placement-groups.js','utf8'),{window:win,console});
vm.runInNewContext(fs.readFileSync('modules/curriculum/assets/prerequisite-configuration.js','utf8'),{window:win,document,location:{search:''},URLSearchParams,FormData,requestAnimationFrame:fn=>fn(),setTimeout,console});
const settle=()=>new Promise(resolve=>setImmediate(resolve));
const click=async dataset=>{const b=new Element('button');b.dataset=dataset;await docClick({target:b});await settle();};
async function context(program=programs[0]){[program,curriculum,version].forEach((p,i)=>{cascade.levels[i].valueInput.value=String(p.id);});cascade.onChange(0,program);cascade.onChange(1,curriculum);cascade.onChange(2,version);await settle();}
(async()=>{
 await settle();assert.equal(requests.length,0,'No unrelated records loaded without context');
 await context();assert.equal((el('ruleCards').innerHTML.match(/class="csms-row-click"/g)||[]).length,105);
 assert.equal((el('ruleCards').innerHTML.match(/data-row="3"/g)||[]).length,1,'One row per target subject');assert(el('ruleCards').innerHTML.includes('1st Year \u00b7 1st Semester'),'Grouped by year and semester');
 assert(el('ruleCards').innerHTML.includes('CC1, CC2'),'Requires column lists the required codes');assert(el('ruleSummary').innerHTML.replace(/<[^>]+>/g,'').includes('105 subjects \u00b7 2 with rules \u00b7 103 without'));
 el('stateFilter').value='with';await el('stateFilter').emit('change');assert.equal((el('ruleCards').innerHTML.match(/class="csms-row-click"/g)||[]).length,2);
 el('ruleSearch').value='Subject 3';await el('ruleSearch').emit('input');assert.equal((el('ruleCards').innerHTML.match(/class="csms-row-click"/g)||[]).length,1);
 el('yearFilter').value='2nd Year';await el('yearFilter').emit('change');assert(el('ruleCards').innerHTML.includes('No subjects match'));
 el('yearFilter').value='';el('ruleSearch').value='';el('stateFilter').value='without';await el('stateFilter').emit('change');assert.equal((el('ruleCards').innerHTML.match(/class="csms-row-click"/g)||[]).length,103);
 el('semesterFilter').value='2nd Semester';await el('semesterFilter').emit('change');assert(el('ruleCards').innerHTML.includes('No subjects match'));el('semesterFilter').value='';
 assert.equal((await autocomplete.focusSearch.search('Subject')).length,105,'All matching candidates reach the shared ranking and suggestion limit');
 await autocomplete.curriculumSearch.search('DEMO');assert.equal(requests.at(-1)[1].academic_program_id,'1');assert.equal(requests.at(-1)[1].per_page,50);
 await autocomplete.versionSearch.search('TEST');assert.equal(requests.at(-1)[1].curriculum_id,'3');
 await click({details:'3'});assert(el('detailsBody').innerHTML.includes('CC1'));assert(el('detailsBody').innerHTML.includes('CC2'));assert(el('detailsBody').innerHTML.includes('CC4'));
 await click({tab:'chain'});assert.equal(el('versionId').value,'4');assert.equal(el('panel-rules').hidden,true);
 autocomplete.focusSearch.setSelected(placements[3]);assert.equal((el('chainCanvas').innerHTML.match(/class="pr-chain-node/g)||[]).length,4);assert.equal((el('chainCanvas').innerHTML.match(/marker-end=/g)||[]).length,3,'Only three stored edges, including branch');
 assert.equal((el('chainCanvas').innerHTML.match(/class="pr-chain-chip"/g)||[]).length,3,'One label chip per arrow, outside the SVG so it cannot sit on the line');assert(!el('chainCanvas').innerHTML.includes('<text'),'No SVG text on top of arrows');
 const colsX=[...el('chainCanvas').innerHTML.matchAll(/data-row="(\d+)"[^>]*style="left:(\d+)px/g)].reduce((m,x)=>({...m,[x[1]]:Number(x[2])}),{});assert(colsX[1]<colsX[3]&&colsX[3]<colsX[4],'Requires mode: prerequisites left of the subject, focus rightmost');
 await click({dir:'unlocks'});assert(el('chainCanvas').innerHTML.includes('No subjects depend on this subject.'),'Unlocks of a last-term subject is empty');await click({dir:'requires'});
 await el('resetChain').emit('click');assert.equal(el('focusId').value,'4');assert.equal(el('versionId').value,'4');assert.equal(el('chainCanvas').style.transform,'');
 autocomplete.focusSearch.setSelected(placements[0]); assert(el('chainCanvas').innerHTML.includes('No prerequisite chain configured'));
 await click({dir:'unlocks'});assert.equal((el('chainCanvas').innerHTML.match(/class="pr-chain-node/g)||[]).length,3,'Unlocks of CC1: full downstream chain CC1, CC3, CC4');assert.equal((el('chainCanvas').innerHTML.match(/marker-end=/g)||[]).length,2);
 const upX=[...el('chainCanvas').innerHTML.matchAll(/data-row="(\d+)"[^>]*style="left:(\d+)px/g)].reduce((m,x)=>({...m,[x[1]]:Number(x[2])}),{});assert(upX[1]<upX[3]&&upX[3]<upX[4],'Unlocks mode: focus leftmost, downstream columns to the right');assert.equal(el('chainCanvas').innerHTML.includes('data-row="3"'),true,'Node click uses the shared row handler (edit dialog, details when read-only)');
 await click({dir:'requires'});
 await click({tab:'review'});assert(el('reviewIssues').innerHTML.includes('No prerequisite conflicts detected.'));
 await el('addRequirementButton').emit('click');assert.equal(el('editorModal').hidden,false);
 autocomplete.targetSearch.setSelected(placements[3]);autocomplete.requiredSearch.setSelected(placements[0]);assert(el('relationshipPreview').innerHTML.includes('REQUIRED BEFORE'));
 await el('ruleForm').emit('submit');assert.equal(posts.at(-1).action,'create');assert.equal(posts.at(-1).subject_id,'4');assert.equal(posts.at(-1).required_subject_id,'1');
 await click({edit:'3'});assert.equal(el('targetField').hidden,true);autocomplete.requiredSearch.setSelected(placements[4]);await el('appendRequired').emit('click');await el('ruleForm').emit('submit');assert.deepEqual(posts.at(-1).required,['1','2','5']);assert.deepEqual(posts.at(-1).expected,['1','2']);
 const n=posts.length;await click({remove:'3'});assert.equal(posts.length,n,'Remove requires confirmation');assert(el('removeMessage').innerHTML.includes('CC1'));assert.equal(el('removeModal').hidden,false);await el('confirmRemove').emit('click');assert.equal(posts.at(-1).action,'update_rule');assert.deepEqual(posts.at(-1).required,[]);
 // Slice 3: cycles are still reported in both directions; long codes stay inside their cards.
 const savedEdges=edges;edges=[e(1,1,2),e(2,2,1)];placements=[p(1),p(2)];await context();autocomplete.focusSearch.setSelected(placements[0]);assert(el('chainCanvas').innerHTML.includes('circular dependency'));await click({dir:'unlocks'});assert(el('chainCanvas').innerHTML.includes('circular dependency'));await click({dir:'requires'});
 placements=[p(1,{subject_code:'CCS1102/CC102/LONGCODE-EXTRA'}),p(2)];edges=[e(1,2,1)];await context();autocomplete.focusSearch.setSelected(placements[1]);assert(el('chainCanvas').innerHTML.includes('CCS1102/CC102/LONGCODE-EXTRA'));assert(el('chainCanvas').innerHTML.includes('title="CCS1102/CC102/LONGCODE-EXTRA'),'Full code available as a tooltip when the card truncates it');edges=savedEdges;placements=Array.from({length:105},(_,i)=>p(i+1));await context();
 // Slice 2: row click, menu, warning modal, earlier-term picker.
 await click({row:'3'});assert.equal(el('editorModal').hidden,false,'Row click opens the edit dialog');el('editorModal').hidden=true;
 await click({menu:'3'});assert.equal(el('prRowMenu').hidden,false);assert(el('prRowMenu').innerHTML.includes('Edit rule')&&el('prRowMenu').innerHTML.includes('Remove rule')&&el('prRowMenu').innerHTML.includes('View in chain'));
 await click({menuAction:'chain',subject:'3'});assert.equal(el('prRowMenu').hidden,true);assert.equal(el('panel-chain').hidden,false);assert.equal(el('focusId').value,'3');await click({tab:'rules'});
 placements=[p(1),p(2,{year_level:'2nd Year',period_position:3}),p(3,{year_level:'2nd Year',period_position:3}),p(4,{semester:null,period_position:null})];edges=[];review={cycles:[],duplicates:[],sequencing:[{...e(1,2,3),message:'CC3 is placed in the same term as CC2.'}],missing:[]};
 await context();assert(el('ruleCards').innerHTML.includes('data-warn="2"'),'Warning icon on the conflicted subject');assert(!el('ruleCards').innerHTML.includes('data-warn="1"'));
 await click({warn:'2'});assert.equal(el('warnModal').hidden,false);assert(el('warnBody').innerHTML.includes('same term as CC2'));assert(el('warnTitle').textContent.includes('1 issue'));el('warnModal').hidden=true;
 await click({edit:'2'});assert.deepEqual((await autocomplete.requiredSearch.search('CC')).map(x=>x.subject_code),['CC1'],'Only earlier-term subjects: not same-term, not unverifiable, not itself');assert.equal(el('requiredHint').hidden,true);
 await click({edit:'1'});assert.equal((await autocomplete.requiredSearch.search('CC')).length,0);assert.equal(el('requiredHint').hidden,false);assert(el('requiredHint').textContent.includes('No earlier-term subjects'));
 await click({edit:'4'});assert(el('requiredHint').textContent.includes('incomplete'));el('editorModal').hidden=true;
 review={};
 placements=[p(1,{year_level:'Grade 11'}),p(2,{year_level:'Grade 12'}),p(3,{year_level:'Grade 11',semester:null})];edges=[];
 await context(programs[1]);assert(el('yearFilter').innerHTML.includes('All Grades'));assert(el('yearFilter').innerHTML.includes('Grade 12'));
 await el('addRequirementButton').emit('click');autocomplete.targetSearch.setSelected(placements[0]);autocomplete.requiredSearch.setSelected(placements[1]);await settle();assert.equal(el('sequenceWarning').hidden,false);assert.equal(el('saveRule').disabled,true);
 autocomplete.requiredSearch.setSelected(placements[2]);assert.equal(el('editorMapping').hidden,false);
 review={cycles:[[1,2,1]],duplicates:[e(2,2,1)],sequencing:[e(3,1,2)],missing:[e(4,1,3)]};await context(programs[1]);assert(!el('reviewIssues').innerHTML.includes('No structural'));for(const label of ['Circular Dependencies','Duplicate Rules','Sequencing Conflicts','Missing Placement'])assert(el('reviewCounts').innerHTML.includes(label));assert(el('reviewIssues').innerHTML.includes('CC1 → CC2 → CC1'));assert(el('reviewIssues').innerHTML.includes('Fix Placement'));
 placements=[p(1,{subject_title:'<script>bad</script>'})];edges=[];review={};el('stateFilter').value='';await context();assert(el('ruleCards').innerHTML.includes('&lt;script>'));assert(!el('ruleCards').innerHTML.includes('<script>'));
 console.log('PASS: prerequisite UI scenarios: cascade scoping, 105 subjects, combined filters, grouped rules, capped search, details, branching chain, stored edges, dependents, fit/reset, add/edit/remove confirmation, SHS, warnings, review counts, escaping.');
})().catch(e=>{console.error(e);process.exitCode=1;});
