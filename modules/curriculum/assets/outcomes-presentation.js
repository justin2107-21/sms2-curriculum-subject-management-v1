/* Presentation of existing academic responses using CSMS foundation components. */
(function (global) {
    'use strict';
    const ui = global.CurriculumUI;
    const esc = value => ui.escapeHtml(value == null ? '' : String(value));
    const list = value => Array.isArray(value) ? value : [];
    const labels = {not_assessed:'Not assessed', incomplete:'Incomplete', mapped:'Mapped', unmapped:'Unmapped'};
    const chipClass = {not_assessed:'csms-chip--draft', incomplete:'csms-chip--under_review', mapped:'csms-chip--approved', unmapped:'csms-chip--archived'};
    const statusChip = state => '<span class="csms-chip '+(chipClass[state]||'csms-chip--draft')+'">'+esc(labels[state]||state)+'</span>';
    const FILTERS = [['all','All'],['mapped','Mapped'],['incomplete','Incomplete'],['unmapped','Unmapped'],['not_assessed','Not assessed']];
    const badge = (state, label) => ui.statusBadge(state, label);
    const fact = (label, value) => '<div><dt>'+esc(label)+'</dt><dd>'+esc(value || 'Not recorded')+'</dd></div>';
    const facts = body => '<dl class="record-detail-grid">'+body+'</dl>';
    const section = (title, body) => '<section class="subject-description-section"><h3>'+esc(title)+'</h3>'+body+'</section>';
    const hidden = (name, value) => '<input type="hidden" name="'+name+'" value="'+Number(value)+'">';
    const field = (name, label, value='', max=12000, required=false, type='textarea') => '<label class="form-field"><span>'+esc(label)+(required?' *':'')+'</span>'+(type==='textarea'
        ? '<textarea name="'+name+'" rows="3" maxlength="'+max+'"'+(required?' required':'')+'>'+esc(value)+'</textarea>'
        : '<input name="'+name+'" type="'+type+'" value="'+esc(value)+'" maxlength="'+max+'"'+(type==='number'?' min="1" max="65535"':'')+(required?' required':'')+'>')+'</label>';
    const form = (cls, title, body, save, attrs='', open=false) => '<details class="reference-action-details"'+(open?' open':'')+'><summary>'+esc(title)+'</summary><form class="domain-form '+cls+'" '+attrs+'><div class="form-error" role="alert" hidden></div><div class="form-grid">'+body+'</div><div class="modal-actions"><button type="submit" class="button-primary">'+esc(save)+'</button></div></form></details>';
    const origin = row => row.origin==='inherited' ? 'Inherited' : 'Manual';
    const reviewBadges = row => badge(row.record_state==='withdrawn'?'archived':'inactive',row.record_state==='withdrawn'?'Withdrawn; history retained':origin(row))+' '+(row.review_state ? badge(row.review_state) : '');
    const withdraw = (kind, row, clo) => '<button type="button" class="button-danger" data-clo-action="'+kind+'_withdraw" data-id="'+Number(row.id)+'" data-clo="'+Number(clo)+'">Withdraw '+(kind==='plan'?'plan':kind==='clo'?'CLO':'alignment')+'</button>';
    const compactTable = (head, rows) => '<div class="table-scroll"><table class="domain-table academic-detail-table"><thead><tr>'+head.map(h=>'<th scope="col">'+esc(h)+'</th>').join('')+'</tr></thead><tbody>'+rows+'</tbody></table></div>';
    const missingFields = p => [!String(p.assessment_method||'').trim()?'assessment method':'',!String(p.planned_task||'').trim()&&!String(p.evidence_description||'').trim()?'task or evidence':'',!String(p.performance_indicator||'').trim()?'performance indicator':''].filter(Boolean);
    function filterOutcomes(outcomes, query, coverage) {
        const terms=String(query || '').trim().toLowerCase().split(/\s+/).filter(Boolean);
        return list(outcomes).filter(o=>(!coverage||o.coverage_status===coverage)&&terms.every(t=>(o.outcome_code+' '+o.outcome_text).toLowerCase().includes(t)));
    }
    function searchRows(outcomes, query) {
        const terms=String(query || '').trim().toLowerCase().split(/\s+/).filter(Boolean);
        return list(outcomes).filter(o=>terms.every(t=>(o.outcome_code+' '+o.outcome_text).toLowerCase().includes(t)));
    }
    /** Chip counts are taken over the rows that match the search, so the active chip's number always equals the rows in the table. */
    function chipCounts(outcomes, query) {
        const rows=searchRows(outcomes,query),counts={all:rows.length};
        FILTERS.slice(1).forEach(([key])=>{counts[key]=rows.filter(o=>o.coverage_status===key).length;});
        return counts;
    }
    function filterChips(counts, active) {
        return FILTERS.map(([key,label])=>'<button type="button" class="outcome-chip'+(key===active?' is-active':'')+'" data-outcome-filter="'+key+'" aria-pressed="'+(key===active?'true':'false')+'">'+esc(label)+' <span class="outcome-chip__n">'+Number(counts[key]||0)+'</span></button>').join('');
    }
    function allZero(outcomes) { return list(outcomes).length>0&&list(outcomes).every(o=>Number(o.mapping_count)===0); }
    function sources(data) {
        const groups=new Map();
        list(data.outcomes).forEach(o=>{
            const key=JSON.stringify([o.source_key,o.document_code,o.document_title,o.issuing_authority,o.source_sha256]);
            if(!groups.has(key))groups.set(key,[]);groups.get(key).push(o);
        });
        if(!groups.size)return '<p class="mapping-state">No recorded documentary source is available for this version.</p>';
        const notice=section('Important Academic Notice','<p><strong>Source:</strong> CHED CMO No. 25, s. 2015, Section 6.3. '+esc(data.limitations?.authenticity||'The supplied scan is fingerprinted; independent registry verification has not been established.')+' Current applicability has not been established. Institutional adoption/approval is not verified.</p><p><strong>Limitation:</strong> '+esc(data.limitations?.mapping||'Curriculum mapping, course outcome alignments, and assessment plans do not establish student outcome attainment, regulatory compliance, or institutional approval.')+'</p>');
        return notice+[...groups.values()].map(rows=>{
            const first=rows[0],unique=key=>[...new Set(rows.map(o=>o[key]))].join(', ');
            return section('Document Information',facts(fact('Issuing authority',first.issuing_authority)+fact('CMO number and series',first.document_code)+fact('Official recorded title',first.document_title)+fact('Source identifier',first.source_key)))
                +section('Source Reference',facts(fact('Relevant sections',unique('source_section'))+fact('Printed page references',unique('printed_page'))+fact('PDF page references',unique('pdf_page_index'))))
                +section('Document Integrity','<dl class="record-detail-grid single-fact-grid"><div><dt>Recorded SHA-256 fingerprint</dt><dd><code>'+esc(first.source_sha256)+'</code></dd></div></dl>')
                +section('Source Verification and Applicability',facts(fact('Evidence / identity assessment',data.limitations?.authenticity || 'The supplied scan is fingerprinted; independent registry verification has not been established.')+fact('Current applicability',data.limitations?.applicability || 'Current applicability has not been established.')+fact('Institutional adoption / approval',data.limitations?.institutional_adoption || 'Institutional adoption/approval not verified.')+fact('Academic limitation',data.limitations?.mapping || 'Curriculum mapping does not establish student outcome attainment, regulatory compliance, or institutional approval.')))
                +section('Detailed Outcome Locations',compactTable(['Outcome','Source section','Printed page','PDF page','Source order'],rows.map(o=>'<tr><th scope="row">'+esc(o.outcome_code)+'</th><td>'+esc(o.source_section)+'</td><td>'+esc(o.printed_page)+'</td><td>'+esc(o.pdf_page_index)+'</td><td>'+esc(o.source_order)+'</td></tr>').join('')));
        }).join('');
    }
    function outcomeTable(rows, selected) {
        if(!rows.length)return '<div class="csms-empty" role="status"><i class="fa-solid fa-filter" aria-hidden="true"></i><strong>No outcomes match</strong><span>Change the status filter or the search.</span></div>';
        return '<div class="csms-page-table"><table class="csms-ctable csms-ctable--outcomes"><thead><tr><th scope="col" class="csms-ctable__code">Code</th><th scope="col">Official outcome</th><th scope="col">Status</th><th scope="col" class="csms-ctable__num">Subjects linked</th><th scope="col" class="csms-ctable__action">Action</th></tr></thead><tbody>'
            +rows.map(o=>'<tr class="csms-row-click'+(Number(selected)===Number(o.id)?' is-selected':'')+'" tabindex="0" data-outcome-open="'+Number(o.id)+'" aria-haspopup="dialog" aria-label="View links of '+esc(o.outcome_code)+'">'
                +'<td class="csms-ctable__code"><span class="csms-code">'+esc(o.outcome_code)+'</span></td>'
                +'<td><span class="outcome-text" title="'+esc(o.outcome_text)+'">'+esc(o.outcome_text)+'</span></td>'
                +'<td>'+statusChip(o.coverage_status)+'</td>'
                +'<td class="csms-ctable__num">'+Number(o.mapping_count)+'</td>'
                +'<td class="csms-ctable__action"><button type="button" class="csms-link" data-outcome-open="'+Number(o.id)+'" aria-haspopup="dialog">View links</button></td></tr>').join('')
            +'</tbody></table></div>';
    }
    function outcomeDetail(data, id, canManage) {
        const o=list(data.outcomes).find(o=>Number(o.id)===Number(id));if(!o)return '';
        const editable=canManage&&Boolean(data.context.editable),links=list(data.mappings).filter(m=>Number(m.outcome_id)===Number(o.id));
        // Subjects are linked in the Curriculum Map tab (the one way to add a link); this dialog lists, removes and assesses.
        const mapButton=editable?'<button type="button" class="secondary-action-button" data-outcome-map="'+Number(o.id)+'"><i class="fa-solid fa-table-list" aria-hidden="true"></i> Map subjects in Curriculum Map</button>':'';
        const linked=links.length
            ?'<ul class="subject-usage-list">'+links.map(m=>'<li><div class="subject-usage-context"><strong>'+esc(m.subject_code+' / '+m.subject_title)+'</strong><span>'+esc((m.year_level||'Year not set')+' / '+(m.semester||'Term not set'))+'</span><span>'+esc(m.mapping_origin==='inherited'?'Inherited from mapping #'+m.inherited_from_mapping_id+'; not independently reassessed':'Manual')+'</span><p><strong>Why this subject supports this outcome:</strong> '+esc(m.rationale)+'</p></div>'+(editable?'<button type="button" class="button-danger" data-outcome-remove="'+Number(m.id)+'" aria-label="Remove mapping for '+esc(m.subject_code)+'">Remove link</button>':'')+'</li>').join('')+'</ul>'+(mapButton?'<p>'+mapButton+'</p>':'')
            :'<div class="csms-empty" role="status"><i class="fa-solid fa-link-slash" aria-hidden="true"></i><strong>No subjects linked yet</strong>'+mapButton+'</div>';
        let html=section('Graduate Outcome','<p>'+esc(o.outcome_text)+'</p>'+facts(fact('Source reference',o.document_code+' / '+o.source_section+' / printed p. '+o.printed_page+' / PDF p. '+o.pdf_page_index)))
            +section('Coverage Summary',facts('<div><dt>Recorded coverage</dt><dd>'+badge(o.coverage_status,labels[o.coverage_status])+'</dd></div>'+fact('Recorded subject links',String(o.mapping_count))+fact('Coverage note',o.coverage_rationale)))
            +section('Linked subjects',linked);
        if(!editable)return html+'<p class="mapping-state">'+(!data.context.editable?'Read only: this version is protected by its lifecycle state.':'Read only: graduate outcome mapping management permission is required.')+'</p>';
        html+=section('Assess coverage','<form class="domain-form outcome-assess-form" data-outcome-assess="'+Number(o.id)+'"><div class="form-error" role="alert" hidden></div>'+field('rationale','Coverage note',o.coverage_rationale,3000)+'<p class="placement-note">Complete coverage is labeled Mapped when links exist, or Unmapped when none exist. Mapping changes require review again.</p><div class="modal-actions"><button type="submit" class="button-muted" name="assessment_state" value="incomplete">Mark Coverage Incomplete</button><button type="submit" class="button-primary" name="assessment_state" value="complete">Mark Coverage Complete</button></div></form>');
        return html;
    }
    const cloFields=c=>field('clo_code','CLO code',c.clo_code,40,true,'text')+field('sequence_order','Sequence',c.sequence_order||'',5,true,'number')+field('clo_statement','Course learning outcome',c.clo_statement,12000,true);
    const planFields=p=>field('assessment_method','Assessment method',p.assessment_method,180,false,'text')+field('planned_task','Planned task',p.planned_task)+field('evidence_description','Planned evidence',p.evidence_description)+field('performance_indicator','Performance indicator / criterion',p.performance_indicator,6000)+field('rubric_or_standard','Rubric / standard (optional)',p.rubric_or_standard);
    function cloWorkspace(data, permission) {
        const p=data.placement,canClo=data.editable&&permission.manageClos,canPlan=data.editable&&permission.managePlans;
        let html='<div class="course-head"><div><h3>'+esc(p.subject_code+' / '+p.subject_title)+'</h3><p>'+esc([p.version_label,p.year_level,p.semester].filter(Boolean).join(' / '))+'</p></div><div class="course-head__actions">'+badge(data.editable&&(canClo||canPlan)?'editable':'read_only')+(canClo?'<button type="button" class="primary-action-button" data-clo-create><i class="fa-solid fa-plus" aria-hidden="true"></i> Create CLO</button>':'')+'</div></div>';
        if(!data.editable)html+='<p class="mapping-state">Read only: curriculum lifecycle protection.</p>';
        else if(!canClo||!canPlan)html+='<p class="mapping-state">'+(!canClo?'CLOs and alignments are read only: CLO management permission is required. ':'')+(!canPlan?'Assessment plans are read only: assessment plan management permission is required.':'')+'</p>';
        const rows=list(data.clos);
        if(!rows.length)return html+'<div class="csms-empty" role="status"><i class="fa-solid fa-list-check" aria-hidden="true"></i><strong>No course learning outcomes recorded for this placement</strong></div>';
        return html+compactTable(['CLO','Statement','Outcome alignments','Assessment plans','Origin / Review','Actions'],rows.map(c=>{
            const alignments=list(data.alignments).filter(a=>Number(a.clo_id)===Number(c.id));
            const plans=list(data.plans).filter(a=>Number(a.clo_id)===Number(c.id));
            const activePlans=plans.filter(p=>p.record_state==='active'),incomplete=activePlans.filter(p=>p.plan_status!=='planned').length;
            return '<tr><th scope="row"><span class="record-code">'+esc(c.clo_code)+'</span></th><td>'+esc(c.clo_statement)+'</td><td>'+esc(alignments.filter(a=>a.record_state==='active').map(a=>a.outcome_code).join(', ')||'None recorded')+'</td><td>'+activePlans.length+' active'+(incomplete?' / '+incomplete+' incomplete':'')+(plans.length>activePlans.length?' / '+(plans.length-activePlans.length)+' withdrawn':'')+'</td><td>'+reviewBadges(c)+'</td><td class="row-actions"><button type="button" class="view-button" data-clo-open="'+Number(c.id)+'" aria-haspopup="dialog" aria-controls="cloViewModal"><i class="fa-solid fa-eye" aria-hidden="true"></i> View</button>'+(canClo&&c.record_state==='active'?'<button type="button" class="row-action-button" data-clo-edit="'+Number(c.id)+'"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</button>':'')+'</td></tr>';
        }).join(''));
    }
    /** Next free CLO sequence of a placement (withdrawn CLOs keep their number, the server refuses a reused one). */
    function nextCloSequence(data) { return list(data&&data.clos).reduce((max,c)=>Math.max(max,Number(c.sequence_order)||0),0)+1; }
    function cloDetail(data, id, permission, edit=false) {
        const c=list(data.clos).find(c=>Number(c.id)===Number(id));if(!c)return '';
        const active=c.record_state==='active',canClo=data.editable&&permission.manageClos&&active,canPlan=data.editable&&permission.managePlans&&active;
        let html=section('Course Learning Outcome','<p>'+esc(c.clo_statement)+'</p>'+facts(fact('Record state',c.record_state==='withdrawn'?'Withdrawn; history retained':'Active')+fact('Origin',origin(c))+fact('Sequence',String(c.sequence_order))+fact('Academic review',ui.statusLabel(c.review_state))+fact('Inherited source CLO',c.source_clo_id?String(c.source_clo_id):'Not inherited')));
        if(canClo)html+=form('clo-edit-form','Edit CLO',hidden('clo_id',c.id)+cloFields(c),'Save CLO','',edit)+'<div class="academic-actions">'+withdraw('clo',c,c.id)+'</div>';
        const links=list(data.alignments).filter(a=>Number(a.clo_id)===Number(c.id));
        html+=section('Graduate Outcome Alignments',links.length?links.map(a=>'<div class="academic-related-record"><div class="section-heading"><strong>'+esc(a.outcome_code)+'</strong>'+reviewBadges(a)+'</div><p>'+esc(a.outcome_text)+'</p>'+facts(fact('Why it supports the outcome',a.rationale)+fact('Inherited source alignment',a.source_alignment_id?String(a.source_alignment_id):'Not inherited'))+(canClo&&a.record_state==='active'?form('clo-alignment-edit-form','Edit explanation',hidden('alignment_id',a.id)+field('rationale','Why does this course outcome support this graduate outcome?',a.rationale,3000,true),'Save rationale')+'<div class="academic-actions">'+withdraw('alignment',a,c.id)+'</div>':'')+'</div>').join(''):'<p>No graduate outcome alignment recorded.</p>');
        const options=list(data.parent_mappings).filter(p=>!links.some(a=>a.record_state==='active'&&Number(a.parent_mapping_id)===Number(p.mapping_id))).map(p=>'<option value="'+Number(p.mapping_id)+'">'+esc(p.outcome_code+' / '+p.outcome_text)+'</option>').join('');
        if(canClo)html+=options?form('clo-alignment-form','Add outcome alignment',hidden('clo_id',c.id)+'<label class="form-field"><span>Recorded graduate outcome mapping *</span><select name="parent_mapping_id" required><option value="">Choose mapped outcome</option>'+options+'</select></label>'+field('rationale','Why does this course outcome support this graduate outcome?','',3000,true),'Align CLO'):'<p class="placement-note">No additional graduate outcome mappings are available. Record a subject mapping if needed.</p>';
        const plans=list(data.plans).filter(p=>Number(p.clo_id)===Number(c.id));
        html+=section('Planned Assessments',plans.length?plans.map(p=>{
            const missing=missingFields(p);
            return '<div class="academic-related-record"><div class="section-heading">'+badge(p.plan_status==='planned'?'planned':'incomplete',p.plan_status==='planned'?'Planned':'Incomplete plan')+reviewBadges(p)+'</div>'+(missing.length?'<p class="mapping-state">Missing: '+esc(missing.join(', '))+'.</p>':'')+facts(fact('Assessment method',p.assessment_method)+fact('Planned task',p.planned_task)+fact('Planned evidence',p.evidence_description)+fact('Performance indicator',p.performance_indicator)+fact('Rubric / standard (optional)',p.rubric_or_standard)+fact('Inherited source plan',p.source_plan_id?String(p.source_plan_id):'Not inherited'))+(canPlan&&p.record_state==='active'?form('clo-plan-edit-form','Edit assessment plan',hidden('clo_id',c.id)+hidden('plan_id',p.id)+planFields(p),'Save assessment plan')+'<div class="academic-actions">'+withdraw('plan',p,c.id)+'</div>':'')+'</div>';
        }).join(''):'<p>No assessment plan recorded.</p>');
        if(canPlan)html+=form('clo-plan-form','Create assessment plan',hidden('clo_id',c.id)+planFields({}),'Record planned assessment');
        return html+section('Planning and Review Limitations','<p>'+esc(data.disclaimer)+'</p><p>Inherited records retain their origin and recorded academic review state; inheritance does not establish independent review or institutional approval.</p>');
    }
    global.OutcomesPresentation={filterOutcomes:(o,q,c)=>searchRows(o,q).filter(r=>!c||c==='all'||r.coverage_status===c),chipCounts,filterChips,allZero,sources,outcomeTable,outcomeDetail,cloWorkspace,cloDetail,nextCloSequence,statusChip};
}(typeof window==='undefined'?globalThis:window));
