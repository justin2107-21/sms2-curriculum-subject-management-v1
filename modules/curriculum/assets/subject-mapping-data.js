(function () {
    'use strict';
    const blank = value => value == null || String(value).trim() === '';
    const compare = (a, b) => String(a || '').localeCompare(String(b || ''), undefined, { numeric: true, sensitivity: 'base' });
    function unitCents(rows) {
        return rows.reduce((total, row) => total + Math.round((Number(row.units) || 0) * 100), 0);
    }
    function review(rows) {
        const groups = new Map();
        rows.forEach(row => {
            const key = JSON.stringify([Number(row.curriculum_version_id), Number(row.subject_id),
                String(row.year_level || '').trim().toLowerCase(), String(row.semester || '').trim().toLowerCase()]);
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(row);
        });
        return {
            placement: rows.filter(row => blank(row.is_required) || !['0', '1'].includes(String(row.is_required))),
            duplicates: [...groups.values()].filter(group => group.length > 1).flat(),
            missingYear: rows.filter(row => blank(row.year_level)),
            missingSemester: rows.filter(row => blank(row.semester))
        };
    }
    // Outcome-coverage checks from the outcomes workspace (real mappings only): outcomes nobody supports, placements that support no outcome.
    function outcomeReview(outcomes, placements, mappings) {
        const usedOutcomes = new Set((mappings || []).map(m => Number(m.outcome_id)));
        const usedPlacements = new Set((mappings || []).map(m => Number(m.placement_id)));
        return {
            outcomesNoSubject: (outcomes || []).filter(o => !usedOutcomes.has(Number(o.id))),
            subjectsNoOutcome: (placements || []).filter(p => !usedPlacements.has(Number(p.placement_id)))
        };
    }
    function edges(rows, requirements) {
        const ids = new Set(rows.map(row => Number(row.subject_id)));
        const versions = new Set(rows.map(row => Number(row.curriculum_version_id)));
        const seen = new Set();
        return requirements.filter(row => {
            const key = row.required_subject_id + ':' + row.subject_id;
            if (row.requirement_type !== 'prerequisite' || !ids.has(Number(row.subject_id)) ||
                (row.curriculum_version_id != null && !versions.has(Number(row.curriculum_version_id))) ||
                !ids.has(Number(row.required_subject_id)) || seen.has(key)) return false;
            seen.add(key);
            return true;
        });
    }
    window.SubjectMappingData = { blank, compare, unitCents, review, outcomeReview, edges };
}());
