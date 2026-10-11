'use strict';
// Execute the shipped Builder handlers with deterministic DOM/network doubles.
// This verifies state and requests; it does not claim browser rendering coverage.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('modules/curriculum/assets/curriculum-builder.js','utf8');
const button = {disabled:true};
const form = {
    elements: {source_version_id:{value:''},curriculum_id:{value:''},version_label:{value:'User revision'}},
    reset() {}, querySelector:()=>button, reportValidity:()=>true,
    addEventListener(name,fn) { this[name]=fn; }
};
const details={hidden:true}, scope={textContent:''}, availability={hidden:false,textContent:''};
const elements={cloneReferenceDetails:details,cloneReferenceForm:form,cloneReferenceScope:scope,cloneReferenceAvailability:availability};
const state={selectedVersionId:7}, permissions={cloneReference:true};
const requests=[], navigations=[], errors=[];
let preview=async()=>({preview:{counts:{placements:4,requirements:3,groups:1,components:2},notice:'Institutional adoption/approval not verified.'}});
const ui={clearFormError(){},showFormError(_form,error){errors.push(error);},showToast(){},
    setFormSubmitting(_form,busy){button.disabled=busy;},
    fetchJson:async(endpoint,data)=>{requests.push(['GET',endpoint,data]);return preview();},
    postForm:async(endpoint,data)=>{requests.push(['POST',endpoint,Object.fromEntries(data.values)]);return {version:{id:91,version_label:'User revision',status:'draft'}};}
};
class FakeFormData {
    constructor(f){this.values=new Map(Object.entries(f.elements).map(([k,v])=>[k,v.value]));}
    set(k,v){this.values.set(k,v);}
}
const context={document:{getElementById:id=>elements[id]},state,permissions,ui,endpoints:{versions:'/version_actions.php'},
    FormData:FakeFormData,loadVersions:async(...args)=>navigations.push(['list',...args]),
    openVersionSubjects:async(...args)=>navigations.push(['workspace',...args])};
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('    function sourceKindLabel('),source.indexOf('    function currentAssessmentLabel(')),context);
vm.runInContext(source.slice(source.indexOf('    async function loadCloneScope('),source.indexOf('    async function populateReferenceChoices(')),context);
const record={id:7,curriculum_id:12,version_role:'reference',version_label:'Actual reference label',program_code:'TEST',program_name:'Synthetic program',references:[
    {reference_purpose:'school_curriculum',source_kind_snapshot:'ched_sample_curriculum',reference_title_snapshot:'Stored <document> title',reference_code_snapshot:'Stored code',reference_url_snapshot:'https://example.invalid/synthetic#section-test'}
]};
(async()=>{
    await context.loadCloneScope(record);
    assert.equal(details.hidden,false);assert.equal(button.disabled,false);
    assert.equal(form.elements.source_version_id.value,7);assert.equal(form.elements.curriculum_id.value,12);
    assert.match(scope.textContent,/CHED sample curriculum: Stored <document> title \[Stored code\]/);
    assert.match(scope.textContent,/4 placements, 3 requirements, 1 groups, 2 components/);
    assert.match(scope.textContent,/Institutional adoption\/approval not verified/);
    assert.match(scope.textContent,/https:\/\/example.invalid\/synthetic#section-test/);
    assert.match(scope.textContent,/Recorded effective date: Not recorded/);
    await form.submit({preventDefault(){},currentTarget:form});
    const posted=requests.find(r=>r[0]==='POST')[2];
    assert.equal(posted.action,'clone_reference');assert.equal(posted.source_version_id,7);
    assert.deepEqual(navigations,[['list',1,91,false],['workspace',91,'User revision','draft']]);
    const count=requests.length;state.selectedVersionId=8;
    await form.submit({preventDefault(){},currentTarget:form});
    assert.equal(requests.length,count);assert.match(errors.at(-1),/selection changed/);
    await context.loadCloneScope(record);assert.equal(requests.length,count,'old async result must not request or replace scope');
    state.selectedVersionId=7;
    preview=async()=>{throw new Error('Reference source is withdrawn');};
    await context.loadCloneScope(record);
    assert.equal(button.disabled,true);assert.match(scope.textContent,/Cannot create a revision: Reference source is withdrawn/);
    await context.loadCloneScope({...record,version_role:'proposed'});assert.equal(details.hidden,true);
    assert.equal(availability.hidden,false);assert.match(availability.textContent,/not a protected reference/);
    permissions.cloneReference=false;const calls=requests.length;
    await context.loadCloneScope(record);assert.equal(requests.length,calls);
    permissions.cloneReference=true;
    let release;preview=()=>new Promise(resolve=>{release=resolve;});
    const waiting=context.loadCloneScope(record);await Promise.resolve();state.selectedVersionId=9;
    release({preview:{counts:{placements:99},notice:'old response'}});await waiting;
    assert.equal(button.disabled,true);assert.doesNotMatch(scope.textContent,/99 placements/);
    console.log('PASS: Task 4 Builder preview, stored source labels and locator, permission gating, empty state, failed/stale preview, selected-reference guard, POST and workspace navigation.');
})().catch(error=>{console.error(error);process.exitCode=1;});
