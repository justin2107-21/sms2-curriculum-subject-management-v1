'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),cp=require('node:child_process');
const harness=require('./outcomes-ui-fixture.cjs');
let checks=0;
const check=(condition,message)=>{assert.ok(condition,message);checks++;};
const url='http://localhost/modules/curriculum/pages/subject_mapping.php?program_id=1&curriculum_id=2&version_id=3&tab=outcomes';
(async()=>{
    const builderSource=fs.readFileSync('modules/curriculum/assets/curriculum-builder.js','utf8');
    const builderNodes=new Map(),getNode=id=>{if(!builderNodes.has(id))builderNodes.set(id,{});return builderNodes.get(id);};
    const builderState={selectedCurriculum:{id:2,academic_program_id:1,program_code:'BSIT',curriculum_code:'IT'}};
    const builder={document:{getElementById:getNode,querySelector:getNode},state:builderState,versionRows:{querySelector:()=>null},curriculumDetailModal:null,versionWorkspace:{},setButtonEnabled(){},setVersionContext(id,label,status){Object.assign(builderState,{selectedVersionId:id,selectedVersionLabel:label,selectedVersionStatus:status});},ui:{statusBadge:()=>''},URLSearchParams,loadReferenceWorkspace:async()=>{},loadPlacements:async()=>{}};
    vm.createContext(builder);
    vm.runInContext(builderSource.slice(builderSource.indexOf('    async function openVersionSubjects('),builderSource.indexOf('    function sourceKindLabel(')),builder);
    await builder.openVersionSubjects(3,'V1','draft');
    const linked=new URL(getNode('outcomeMappingLink').href,'http://localhost/');
    check(linked.searchParams.get('tab')==='outcomes','actual Builder handler emits explicit outcome tab');
    check(linked.searchParams.get('program_id')==='1'&&linked.searchParams.get('curriculum_id')==='2'&&linked.searchParams.get('version_id')==='3','actual Builder link carries exact context');
    // Reproduce the exact baseline Builder link behavior with the actual Task 9 controller.
    const baseline=cp.execFileSync('git',['show','7018129c07b7c449ed04d2235b72cf81fb7170eb:modules/curriculum/assets/subject-mapping-final.js'],{encoding:'utf8'});
    const broken=harness({source:baseline,url:url.replace('&tab=outcomes','')});await broken.settle();
    check(broken.el('mappingPanelOutcomes').hidden,'baseline reproduces Builder outcome link opening Map');
    for(const link of [url,url,url.replace('tab=outcomes','outcomes=1')]) {
        const h=harness({url:link});
        check(h.el('mappingPanelMap').hidden,'Map is hidden synchronously before requests settle');
        check(!h.el('mappingPanelOutcomes').hidden,'outcomes selected on direct load, reload and legacy link');
        await h.settle();
        check(h.el('mappingVersionFilter').value==='3','version context preserved');
        check(h.el('mappingProgramFilter').value==='1'&&h.el('mappingCurriculumFilter').value==='2','owners preserved');
        check(h.el('outcomeMatrix').innerHTML.includes('IT01'),'actual outcome data rendered');
    }
    const h=harness({url});await h.settle();
    function tab(id){h.el('mappingWorkspaceTabs').handlers.click({target:{closest:()=>({id})}});}
    tab('mappingTabTrace');check(h.context.window.location.search.includes('tab=trace'),'tab click persists URL');
    check(!h.el('mappingPanelTrace').hidden&&h.el('mappingPanelOutcomes').hidden,'tab visibility and selected state switch');
    const traceUrl=String(h.context.window.location);
    h.context.window.location=new URL(url);await h.windowEvents.popstate();await h.settle();
    check(!h.el('mappingPanelOutcomes').hidden&&h.el('mappingVersionFilter').value==='3','Back restores outcome tab and context');
    h.context.window.location=new URL(traceUrl);await h.windowEvents.popstate();await h.settle();
    check(!h.el('mappingPanelTrace').hidden,'Forward restores trace tab');
    const newTab=harness({url:traceUrl});await newTab.settle();check(!newTab.el('mappingPanelTrace').hidden,'new page instance preserves URL state');
    const invalidTab=harness({url:url.replace('tab=outcomes','tab=forged')});await invalidTab.settle();check(!invalidTab.el('mappingPanelMap').hidden,'unknown tab safely defaults to Map');
    const plain=harness({url:'http://localhost/subject_mapping.php'});await plain.settle();check(!plain.el('mappingPanelMap').hidden,'ordinary sidebar entry defaults to Map');
    for(const bad of [url.replace('program_id=1','program_id=9'),url.replace('curriculum_id=2','curriculum_id=9')]) {
        const invalid=harness({url:bad});await invalid.settle();
        check(invalid.el('outcomeMatrix').innerHTML.includes('inconsistent'),'forged ownership reports error in visible workspace');
        check(invalid.el('mappingVersionFilter').value==='','forged context never populates version');
        check(!invalid.calls.some(c=>c.endpoint==='outcomes'),'invalid context never requests outcomes');
    }
    for(const message of ['Curriculum version not found.','Permission denied.']) {
        const invalid=harness({url,fetch:(endpoint)=>{if(endpoint==='versions')throw new Error(message);}});await invalid.settle();
        check(invalid.el('outcomeMatrix').innerHTML.includes(message),'missing/unauthorized errors remain truthful');
    }
    tab('mappingTabOutcomes');await h.settle();
    h.el('outcomeSearch').value='nonexistent';h.el('outcomeSearch').handlers.input();check(h.el('outcomeMatrix').innerHTML.includes('No outcomes match'),'search filters real rendered list');
    h.el('outcomeSearch').value='IT01';h.el('outcomeSearch').handlers.input();check(h.el('outcomeMatrix').innerHTML.includes('IT01'),'code search restores matching row');
    h.el('outcomeCoverage').value='mapped';h.el('outcomeCoverage').handlers.change();check(!h.el('outcomeMatrix').innerHTML.includes('data-outcome-open'),'coverage filters correctly');
    h.el('outcomeCoverage').value='';h.el('outcomeCoverage').handlers.change();
    check(!h.el('outcomeMatrix').innerHTML.includes('<form'),'list has no expanded edit forms');
    h.click('outcomeMatrix','[data-outcome-open]',{outcomeOpen:1});check(h.el('outcomeDetail').innerHTML.includes('data-outcome-add="1"'),'Manage opens outcome-owned form');
    const add={dataset:{outcomeAdd:'1'},matches:selector=>selector==='[data-outcome-add]'};
    await h.el('outcomeDetail').handlers.submit({preventDefault(){},target:{closest:()=>add}});
    check(h.posted.at(-1).action==='add'&&h.posted.at(-1).outcome_id==='1'&&h.posted.at(-1).curriculum_version_id==='3','mapping form retains mutation action and context');
    check(h.toasts.at(-1).type==='success','successful mutation reports feedback');
    const assess={dataset:{outcomeAssess:'1'},matches:()=>false};
    await h.el('outcomeDetail').handlers.submit({preventDefault(){},submitter:{value:'complete'},target:{closest:()=>assess}});
    check(h.posted.at(-1).assessment_state==='complete','coverage submitter state preserved');
    h.ui.postForm=async()=>{throw new Error('Rejected by server');};
    await h.el('outcomeDetail').handlers.submit({preventDefault(){},target:{closest:()=>add}});
    check(add.error==='Rejected by server','failure stays in form, no fake success');
    const canceled=harness({url,confirm:false});await canceled.settle();await canceled.click('outcomeDetail','[data-outcome-remove]',{outcomeRemove:1});check(canceled.posted.length===0,'remove requires confirmation');
    const stale=harness({url});await stale.settle();stale.ui.fetchJson=async()=>{throw new Error('Outcome request failed');};
    stale.el('mappingWorkspaceTabs').handlers.click({target:{closest:()=>({id:'mappingTabOutcomes'})}});await stale.settle();
    stale.el('outcomeSearch').value='IT01';stale.el('outcomeSearch').handlers.input();
    check(stale.el('outcomeMatrix').innerHTML.includes('Outcome request failed')&&!stale.el('outcomeMatrix').innerHTML.includes('data-outcome-open'),'search never revives stale data after failure');
    const P=h.context.window.OutcomesPresentation;
    const outcome={id:1,outcome_code:'IT01',outcome_text:'<script>"official" & full long text</script>'.repeat(25),coverage_status:'not_assessed',mapping_count:1,document_title:'<source>',source_key:'key',document_code:'CMO',issuing_authority:'CHED',source_section:'6.3',printed_page:8,pdf_page_index:8,source_sha256:'a'.repeat(64)};
    const data={context:{editable:true},outcomes:[outcome],placements:[],mappings:[],limitations:{truth:'<unverified>'}};
    check(!P.outcomeList([outcome],false).includes('<script>')&&P.outcomeList([outcome],false).includes('&lt;script&gt;'),'official text escaped without truncation');
    check(P.sources(data).includes('a'.repeat(64))&&P.sources(data).includes('Printed page')&&P.sources(data).includes('<td>8</td>')&&P.sources(data).includes('&lt;source&gt;'),'source fingerprint pages and escaped title accessible');
    check(!P.outcomeDetail(data,1,false).includes('<form'),'view-only outcome has no mutations');
    data.context.editable=false;check(!P.outcomeDetail(data,1,true).includes('<form'),'lifecycle restriction overrides mapping permission');
    const clo={placement:{curriculum_version_id:3,subject_code:'S1',subject_title:'<subject>',version_label:'V1',version_role:'proposed',review_status:'not_started'},editable:true,disclaimer:'No attainment',clos:[{id:5,clo_code:'CLO1',clo_statement:'<statement>',origin:'inherited',review_state:'awaiting_review',record_state:'active'}],alignments:[{id:6,clo_id:5,outcome_code:'IT01',outcome_text:'<outcome>',rationale:'<rationale>',origin:'manual',record_state:'active'}],plans:[{id:7,clo_id:5,assessment_method:'',planned_task:'<task>',evidence_description:'<evidence>',performance_indicator:'',rubric_or_standard:'<rubric>',record_state:'active',plan_status:'incomplete'}],parent_mappings:[]};
    const full=P.cloDetail(clo,5,{manageClos:true,managePlans:true});
    check(!P.cloWorkspace(clo,{manageClos:true,managePlans:true}).includes('<form'),'compact CLO list does not expand mutation forms');
    for(const token of ['clo-edit-form','clo-plan-edit-form','clo-alignment-edit-form','clo_create','<statement>']) {if(token==='clo_create'||token==='<statement>')continue;check(full.includes(token),token+' available');}
    for(const token of ['&lt;statement&gt;','&lt;rationale&gt;','&lt;task&gt;','&lt;evidence&gt;','&lt;rubric&gt;','Awaiting academic review','Missing: assessment method, performance indicator'])check(full.includes(token),'organized escaped CLO field: '+token);
    check(!P.cloDetail(clo,5,{manageClos:false,managePlans:false}).includes('<form'),'read-only CLO has no mutation forms');
    const plansOnly=P.cloDetail(clo,5,{manageClos:false,managePlans:true});check(!plansOnly.includes('clo-edit-form')&&plansOnly.includes('clo-plan-edit-form'),'separate plan permission honored');
    const closOnly=P.cloDetail(clo,5,{manageClos:true,managePlans:false});check(closOnly.includes('clo-edit-form')&&!closOnly.includes('clo-plan-edit-form'),'separate CLO permission honored');
    const ch=harness({url,fetch:(endpoint)=>endpoint==='clos'?clo:undefined});await ch.settle();
    ch.el('cloPlacementSelect').value='101';await ch.el('cloPlacementSelect').handlers.change();
    check(ch.el('cloAssessmentContent').innerHTML.includes('&lt;statement&gt;'),'CLO controller renders actual selected placement response');
    for(const [cls,action] of [['.clo-create-form','clo_create'],['.clo-edit-form','clo_update'],['.clo-alignment-form','alignment_add'],['.clo-alignment-edit-form','alignment_update'],['.clo-plan-form','plan_add'],['.clo-plan-edit-form','plan_update']]) {
        const form={matches:selector=>selector===cls};await ch.el('cloDetail').handlers.submit({preventDefault(){},target:{closest:()=>form}});
        check(ch.posted.at(-1).action===action&&ch.posted.at(-1).placement_id==='101','CLO form dispatch '+action+' preserves placement');
    }
    let releaseClo;ch.ui.fetchJson=()=>new Promise(resolve=>{releaseClo=resolve;});
    const pending=ch.el('cloPlacementSelect').handlers.change();ch.el('cloPlacementSelect').value='102';releaseClo(clo);await pending;
    check(!ch.el('cloAssessmentContent').innerHTML.includes('&lt;statement&gt;'),'stale CLO result is ignored after selection changes');
    clo.editable=false;check(!P.cloDetail(clo,5,{manageClos:true,managePlans:true}).includes('<form'),'protected CLO version never exposes edits');
    clo.editable=true;clo.clos[0].record_state='withdrawn';const withdrawn=P.cloDetail(clo,5,{manageClos:true,managePlans:true});check(!withdrawn.includes('clo-edit-form')&&!withdrawn.includes('clo-plan-edit-form'),'withdrawn CLO descendants have no unusable edits');
    console.log('PASS: '+checks+' Task 10 controller/presentation checks (mock DOM; no browser visual claim).');
})().catch(e=>{console.error(e);process.exitCode=1;});
