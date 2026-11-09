(function () {
    'use strict';
    const esc = value => window.CurriculumUI.escapeHtml(value == null ? 'Not recorded' : String(value));
    const names = {year_level:'Year level',semester:'Semester / term',sequence_order:'Sequence',is_required:'Required / optional',subject_code:'Code',subject_title:'Title',units:'Units',lecture_units:'Lecture units',laboratory_units:'Laboratory units',description:'Description',group_name:'Group',required_selections:'Required selections',status:'Technical status',members:'Members (shared subject identities)',subject_id:'Subject identity'};
    function values(record) {
        if (!record) return 'Not present';
        return '<dl class="history-details-grid">' + Object.entries(record).filter(([key]) => names[key]).map(([key,value]) =>
            '<div><dt>' + esc(names[key]) + '</dt><dd>' + esc(key === 'is_required' ? (Number(value) ? 'Required' : 'Optional') : (Array.isArray(value) ? value.join(', ') : value)) + '</dd></div>').join('') + '</dl>' +
            (record.details_available === false ? '<p>Historical subject details unavailable</p>' : '') +
            (record.provenance ? '<small>' + esc(record.provenance) + (record.captured_at ? ' · ' + esc(record.captured_at) : '') + '</small>' : '');
    }
    function row(record,index) {
        const label = record.before?.details_available ? record.before : record.after;
        return '<tr class="csms-row-click" tabindex="0" data-change-details="' + index + '" aria-haspopup="dialog"><td><strong class="csms-ctable__code">' + esc(label?.subject_code || 'Subject #' + record.subject_id) + '</strong><br>' + esc(label?.subject_title || '') +
            (record.warning ? '<p class="curriculum-notice">' + esc(record.warning) + '</p>' : '') + '</td><td>' + record.categories.map(c => '<span class="history-change-badge">' + esc(c[0].toUpperCase()+c.slice(1)) + '</span>').join(' ') +
            '</td><td>' + values(record.before) + '</td><td>' + values(record.after) + '</td></tr>';
    }
    function locator(value) {
        return /^https?:\/\//i.test(value || '') ? '<a target="_blank" rel="noopener noreferrer" href="' + esc(value) + '">' + esc(value) + '</a>' : esc(value || 'No locator recorded');
    }
    function evidence(data) {
        const context = v => esc(v.version_label) + ' · role: ' + esc(v.version_role) + ' · technical status: ' + esc(v.status) + ' · workflow: ' + esc(v.review_status);
        return '<h2>Reference &amp; revision</h2><p>Reference: ' + context(data.version_a) + '</p><p>Revision: ' + context(data.version_b) + '</p><p>' + esc(data.institutional_adoption_label) + '</p>' +
            '<p class="curriculum-notice">' + (data.partial ? 'Comparison coverage is partial.' : 'Supported comparison dimensions available.') + ' Current shared subject differences do not establish intentional revision-specific changes.</p>' +
            data.warnings.map(w => '<p>' + esc(w) + '</p>').join('') + data.references.map(r => {
                const assessment = ['document_identity','declared_origin','applicability'].map(k => {
                    const a=r.current_assessments[k];return k.replaceAll('_',' ') + ': ' + (a?.status || 'not_assessed') + (a?.status==='stale_for_current_metadata' ? ' — source changed; historical result: ' + a.historical_result : '');
                }).join(' · ');
                const identity = r.evidence_events.find(e => e.verification_subject==='document_identity');
                return '<article><h3>' + esc(r.reference_title_snapshot || 'Document title unavailable') + '</h3><p>Attachment-time identity: ' + esc(r.reference_code_snapshot || 'Code not recorded') + ' · ' + esc(r.issuing_body_snapshot) + ' · ' + esc(r.source_kind_snapshot.replaceAll('_',' ')) + ' · purpose: ' + esc(r.reference_purpose) + '</p>' +
                    '<p>Attached locator: ' + locator(r.reference_url_snapshot) + ' · recorded effective date: ' + esc(r.effective_date_snapshot) + '</p>' +
                    '<p>Current source: ' + esc(r.current_title) + ' · ' + esc(r.current_reference_code) + ' · ' + locator(r.current_source_url) + '</p>' +
                    '<p>Current assessments: ' + esc(assessment) + '</p>' + (identity ? '<p>Identity-event supporting locator (' + esc(identity.current_assessment_result) + '): ' + locator(identity.evidence_locator) + '</p>' : '') +
                    (!r.attachment_matches_current_source ? '<p class="curriculum-notice">Source changed since attachment; historical attachment evidence retained.</p>' : '') +
                    (r.verification_status==='inactive' ? '<p>Source inactive / withdrawn.</p>' : '') +
                    (r.source_kind_snapshot==='ched_sample_curriculum' ? '<p>CHED sample / demonstration reference; authenticity and applicability are separate assessments.</p>' : '') + '</article>';
            }).join('');
    }
    function structures(data) {
        // Labels resolve only exact stored shared subject identities, never guessed equivalencies.
        const subjects = data.subject_labels || {};
        const subject = id => subjects[id] || ('Subject #' + id);
        const edge = r => !r ? 'Not present' : (Array.isArray(r) ? r.map(edge).join('; ') : subject(r.subject_id) + ' requires ' + subject(r.required_subject_id) + ' (' + r.requirement_type + ')');
        const group = r => !r ? 'Not present' : values({...r,members:(r.members || []).map(subject)});
        const weight = r => !r ? 'Not present' : '<p>' + esc(subject(r.subject_id)) + ' · ' + esc(r.description) + '</p>' + Object.entries(r.components || {}).map(([name,c]) => '<p>' + esc(name) + ': ' + esc(c.weight_percentage) + '% · sequence ' + esc(c.sequence_order) + '</p>').join('');
        const clo = data.clo_assessment;
        const cloHtml = clo ? '<h2>Course learning outcomes &amp; planned assessment</h2><p>' + esc(clo.coverage) + (clo.complete ? '' : ' · Inherited-record lineage is incomplete.') + '</p>' + Object.entries(clo.changes).map(([key,changes]) => '<h3>' + esc(key.replaceAll('_',' ')) + '</h3>' + (changes.length ? '<div class="table-scroll"><table class="domain-table"><thead><tr><th>Change</th><th>Reference</th><th>Revision</th></tr></thead><tbody>' + changes.map(c=>'<tr><td>' + esc(c.change) + (Object.keys(c.differences).length ? '<br>' + Object.entries(c.differences).map(([field,d])=>esc(field.replaceAll('_',' '))+': '+esc(d.before)+' → '+esc(d.after)).join('<br>') : '') + '</td><td>' + (c.before ? esc(c.before.clo_code || c.before.outcome_code || c.before.assessment_method || JSON.stringify(c.before)) : 'Not present') + '</td><td>' + (c.after ? esc(c.after.clo_code || c.after.outcome_code || c.after.assessment_method || JSON.stringify(c.after)) : 'Not present') + '</td></tr>').join('') + '</tbody></table></div>' : '<p>No recorded changes.</p>')).join('') : '';
        return cloHtml + [['Requirements',data.requirements,r=>esc(edge(r))],['Electives',data.electives,group],['Grade weighting',data.grade_weighting,weight]].map(([title,dimension,format]) =>
            '<h2>' + title + '</h2><p>' + esc(dimension.matching) + (dimension.complete ? '' : ' · Coverage partial / unresolved.') + '</p>' + (dimension.changes.length ? '<div class="table-scroll"><table class="domain-table"><thead><tr><th>Change</th><th>Reference</th><th>Revision</th></tr></thead><tbody>' + dimension.changes.map(c=>'<tr><td>' + esc(c.change) + '</td><td>' + format(c.before) + '</td><td>' + format(c.after) + '</td></tr>').join('') + '</tbody></table></div>' : '<p>No differences in the supported recorded fields.</p>')).join('');
    }
    window.CurriculumHistoryComparisonUI={values,row,evidence,structures};
}());
