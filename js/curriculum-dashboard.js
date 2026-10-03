(function () {
  'use strict';

  const dashboard = document.getElementById('curriculumDashboard');
  if (!dashboard || dashboard.dataset.canViewCurriculum !== '1') return;

  async function getJson(endpoint, parameters) {
    const url = new URL(endpoint, window.location.href);
    Object.entries(parameters || {}).forEach(function ([key, value]) {
      if (value !== null && value !== undefined && value !== '') {
        url.searchParams.set(key, String(value));
      }
    });
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    let payload;
    try {
      payload = await response.json();
    } catch (error) {
      throw new Error('The server returned an unreadable response.');
    }
    if (!response.ok || !payload.success) {
      throw new Error(payload.message || 'Curriculum data could not be loaded.');
    }
    return payload;
  }

  function formatUnits(value) {
    const amount = Number(value);
    return Number.isFinite(amount) ? amount.toFixed(2).replace(/\.00$/, '') : '0';
  }

  function preferred(records) {
    return records.slice().sort(function (left, right) {
      const activeDifference = Number(String(right.status).toLowerCase() === 'active') -
        Number(String(left.status).toLowerCase() === 'active');
      if (activeDifference) return activeDifference;
      return Date.parse(right.updated_at || right.created_at || '') -
        Date.parse(left.updated_at || left.created_at || '');
    })[0] || null;
  }

  async function allAssignments(versionId) {
    let page = 1;
    let totalPages = 1;
    let expectedTotal = null;
    const records = [];
    const seen = new Set();
    while (page <= totalPages) {
      const payload = await getJson(dashboard.dataset.assignmentApi, {
        action: 'list', curriculum_version_id: versionId, page: page, per_page: 50
      });
      const pagination = payload.pagination;
      if (expectedTotal === null) expectedTotal = Number(pagination.total_records);
      if (Number(pagination.page) !== page || Number(pagination.total_records) !== expectedTotal) {
        throw new Error('Curriculum placements changed while the snapshot was loading.');
      }
      totalPages = Number(pagination.total_pages) || 1;
      payload.assignments.forEach(function (record) {
        if (seen.has(String(record.id))) throw new Error('Duplicate placement returned by the server.');
        seen.add(String(record.id));
        records.push(record);
      });
      page += 1;
    }
    if (records.length !== expectedTotal) {
      throw new Error('The complete curriculum placement list could not be loaded.');
    }
    return records;
  }

  async function loadSnapshot() {
    const loading = document.getElementById('snapshotLoading');
    const unavailable = document.getElementById('snapshotUnavailable');
    const details = document.getElementById('snapshotDetails');
    const openLink = document.getElementById('openSnapshotCurriculum');
    try {
      const curriculumPayload = await getJson(dashboard.dataset.curriculumApi, {
        action: 'list', page: 1, per_page: 50, search: '', status: ''
      });
      const curriculum = preferred(curriculumPayload.curricula || []);
      if (!curriculum) throw new Error('No curriculum records are available.');

      const versionPayload = await getJson(dashboard.dataset.versionApi, {
        action: 'list', curriculum_id: curriculum.id, page: 1, per_page: 50
      });
      const version = preferred(versionPayload.versions || []);
      if (!version) throw new Error('No curriculum versions are available for the selected curriculum.');

      const assignments = await allAssignments(version.id);
      const totalUnits = assignments.reduce(function (total, assignment) {
        const units = Number(assignment.units);
        return total + (Number.isFinite(units) ? Math.round(units * 100) : 0);
      }, 0);

      document.getElementById('snapshotProgram').textContent = curriculum.program_code || 'Academic Program';
      document.getElementById('snapshotCurriculum').textContent = curriculum.curriculum_name || curriculum.curriculum_code;
      document.getElementById('snapshotVersion').textContent = version.version_label;
      document.getElementById('snapshotSubjectCount').textContent = String(assignments.length);
      document.getElementById('snapshotUnits').textContent = formatUnits(totalUnits / 100);
      openLink.href = '../modules/curriculum/pages/curricula.php?curriculum_id=' + encodeURIComponent(curriculum.id);
      openLink.hidden = false;
      loading.hidden = true;
      unavailable.hidden = true;
      details.hidden = false;
      const totalUnitsCard = document.getElementById('dashboardTotalUnits');
      if (totalUnitsCard) totalUnitsCard.textContent = formatUnits(totalUnits / 100);
    } catch (error) {
      loading.hidden = true;
      details.hidden = true;
      unavailable.textContent = error.message || 'Current curriculum snapshot could not be loaded.';
      unavailable.hidden = false;
    }
  }

  loadSnapshot();
}());
