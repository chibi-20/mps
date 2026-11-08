/* ============================================================
   MPS & Item Analysis System — Client-side Logic
   Mirrors PHP band/threshold constants; authoritative values
   are always recomputed server-side on save & dashboard load.
   ============================================================ */

'use strict';

// ============================================================
// AdminLTE palette (Chart.js needs literal color strings)
// ============================================================
const THEME = { maroon: '#1d4ed8', maroonDark: '#1e40af', gold: '#f59e0b', goldLight: '#fbbf24' };
function masteryColor(pct) {
    if (+pct < 50) return '#ef4444';
    if (+pct < 75) return '#f59e0b';
    return '#10b981';
}

// ============================================================
// Shared helpers
// ============================================================

function pctColor(pct) {
    if (pct <  50) return 'pct-red';
    if (pct <  75) return 'pct-yellow';
    return 'pct-green';
}

// Score-band classifier — mirrors PHP get_score_band()
function scoreBand(pct) {
    if (pct >= 98) return '98-100';
    if (pct >= 95) return '95-97';
    if (pct >= 90) return '90-94';
    if (pct >= 85) return '85-89';
    if (pct >= 80) return '80-84';
    if (pct >= 75) return '75-79';
    return 'Below 75';
}

const SCORE_BAND_ORDER  = ['98-100','95-97','90-94','85-89','80-84','75-79','Below 75'];
const SCORE_BAND_COLORS = {
    '98-100':   '#065f46',
    '95-97':    '#047857',
    '90-94':    '#059669',
    '85-89':    '#10b981',
    '80-84':    '#34d399',
    '75-79':    '#6ee7b7',
    'Below 75': '#ef4444',
};

function bandForScore(score, totalItems) {
    if (totalItems <= 0) return 'ANM';
    const pct = score / totalItems * 100;
    for (const [key, band] of Object.entries(MASTERY_BANDS)) {
        if (pct >= band.min && pct <= band.max) return key;
    }
    return 'ANM';
}

function fmtNum(n, decimals = 2) {
    if (!isFinite(n) || isNaN(n)) return '—';
    return Number(n).toFixed(decimals);
}

async function apiPost(url, payload) {
    const res = await fetch(BASE_URL + url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': CSRF_TOKEN,
        },
        body: JSON.stringify(payload),
    });
    return res.json();
}

async function apiGet(url) {
    const res = await fetch(BASE_URL + url);
    return res.json();
}

function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = 'toast toast-' + type;
    t.textContent = msg;
    Object.assign(t.style, {
        position:'fixed', bottom:'24px', right:'24px', zIndex:9999,
        padding:'.75rem 1.25rem', borderRadius:'8px',
        background: type === 'success' ? '#10b981' : '#ef4444',
        color:'#fff', fontWeight:600, fontSize:'.9rem',
        boxShadow:'0 4px 16px rgba(0,0,0,.2)',
    });
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

// ============================================================
// TEACHER DASHBOARD
// ============================================================

let currentAssessment = null; // full assessment object loaded from API
let currentAssessmentId = null;
let miniChart = null;
const assessmentHeaderObservers = new WeakMap();
let mpsSecCases = {};         // {sec_id: Σf}    — updated by recomputeMps; shared with item table
let mpsSecFx    = {};         // {sec_id: Σf(x)} — used as cross-check in Item Analysis

function toggleNewForm(show) {
    const panel = document.getElementById('newAssessmentPanel');
    if (panel) panel.style.display = show ? 'block' : 'none';
    const emptyState = document.getElementById('emptyState');
    if (emptyState) emptyState.style.display = show ? 'none' : 'block';
    const detail = document.getElementById('assessmentDetail');
    if (detail) detail.style.display = 'none';
}

// ============================================================
// Section checklist loader (teacher: create assessment form)
// ============================================================

async function loadSectionChecklist(subjectId) {
    const wrap      = document.getElementById('sectionChecklistWrap');
    const container = document.getElementById('sectionChecklist');
    const hint      = document.getElementById('sectionCountHint');
    if (!wrap) return;

    if (!subjectId) {
        wrap.style.display = 'none';
        container.innerHTML = '';
        return;
    }

    wrap.style.display = 'block';
    container.innerHTML = '<p class="text-muted" style="margin:0">Loading sections…</p>';
    if (hint) hint.textContent = '';

    const data = await apiGet(`api/get_sections.php?subject_id=${encodeURIComponent(subjectId)}`);

    if (data.error) {
        container.innerHTML = `<p class="alert alert-warning" style="margin:0">${data.error}</p>`;
        return;
    }

    if (!data.sections || data.sections.length === 0) {
        container.innerHTML = `<p class="alert alert-warning" style="margin:0">` +
            `No sections available for Grade ${data.grade} — contact admin to add sections.</p>`;
        return;
    }

    const checked = new Set((data.checked || []).map(Number));
    let html = '<div class="checklist checklist--grid">';
    data.sections.forEach(sec => {
        const isChecked = checked.has(+sec.id) ? 'checked' : '';
        const label = sec.name.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        html += `<label class="check-item">` +
                `<input type="checkbox" name="section_ids" value="${sec.id}" ${isChecked}>` +
                `${label}</label>`;
    });
    html += '</div>';
    container.innerHTML = html;

    updateSectionHint();
    container.querySelectorAll('input[type=checkbox]').forEach(cb => {
        cb.addEventListener('change', updateSectionHint);
    });
}

function updateSectionHint() {
    const hint    = document.getElementById('sectionCountHint');
    const checked = document.querySelectorAll('#sectionChecklist input[type=checkbox]:checked').length;
    const total   = document.querySelectorAll('#sectionChecklist input[type=checkbox]').length;
    if (hint && total > 0) hint.textContent = `— ${checked} of ${total} selected`;
}

// Attach new-assessment form
const frmNew = document.getElementById('frmNewAssessment');
if (frmNew) {
    document.getElementById('btnNewAssessmentForm')?.addEventListener('click', () => toggleNewForm(true));

    // Load sections when subject changes
    const selSubject = document.getElementById('selSubject');
    if (selSubject) {
        selSubject.addEventListener('change', () => loadSectionChecklist(selSubject.value));
    }

    frmNew.addEventListener('submit', async (e) => {
        e.preventDefault();

        // Collect regular fields
        const fd = new FormData(frmNew);
        const payload = {};
        for (const [key, value] of fd.entries()) {
            if (key !== 'section_ids') payload[key] = value;
        }

        // Collect multi-value section_ids checkboxes explicitly
        payload.section_ids = fd.getAll('section_ids').map(Number);

        if (!payload.section_ids.length) {
            showToast('Select at least one section.', 'error');
            return;
        }

        const result = await apiPost('api/create_assessment.php', payload);
        if (result.error) { showToast(result.error, 'error'); return; }
        showToast('Assessment created!');
        setTimeout(() => location.reload(), 800);
    });
}

async function deleteAssessment(id, title, event) {
    event.stopPropagation();
    if (!confirm(`Delete "${title}" and all its encoded data?\n\nThis cannot be undone.`)) return;

    const result = await apiPost('api/delete_assessment.php', { assessment_id: id });
    if (result.error) { showToast(result.error, 'error'); return; }

    // Remove the card from the My Assessments page.
    document.querySelector(`.teacher-assessment-row[data-id="${id}"]`)?.remove();
    const assessmentList = document.getElementById('assessmentList');
    if (assessmentList && !assessmentList.querySelector('.teacher-assessment-row')) {
        const tbody = assessmentList.querySelector('tbody');
        if (tbody) tbody.innerHTML = '<tr class="teacher-assessment-empty"><td colspan="8" class="text-center text-muted py-4">No assessments yet. Click <strong>+ New Assessment</strong> to start.</td></tr>';
    }

    // If this assessment's grid was open, reset the main area
    if (currentAssessmentId === id) {
        currentAssessment   = null;
        currentAssessmentId = null;
        mpsSecCases = {};
        mpsSecFx    = {};
        document.getElementById('assessmentDetail').style.display = 'none';
        document.getElementById('emptyState').style.display = 'block';
    }

    showToast('Draft deleted.');
}

// ============================================================
// ZIPGRADE CSV IMPORT
// ============================================================

function openImportModal() {
    if (!currentAssessment) return;
    const { sections } = currentAssessment;

    // Populate section dropdown from the currently loaded assessment
    const sel = document.getElementById('importSection');
    sel.innerHTML = '<option value="">— select section —</option>';
    sections.forEach(sec => {
        const opt = document.createElement('option');
        opt.value       = sec.id;
        opt.textContent = sec.name;
        sel.appendChild(opt);
    });
    if (sections.length === 1) sel.value = sections[0].id;

    document.getElementById('importAsmtId').value           = currentAssessmentId;
    document.getElementById('importFile').value             = '';
    document.getElementById('importPreviewArea').style.display = 'none';
    document.getElementById('importPreviewArea').innerHTML  = '';
    document.getElementById('btnDoPreview').disabled        = false;
    document.getElementById('btnDoPreview').textContent     = 'Preview';
    document.getElementById('btnDoConfirm').style.display   = 'none';

    document.getElementById('importModal').style.display = 'flex';
}

function closeImportModal() {
    document.getElementById('importModal').style.display = 'none';
}

async function doImportPreview() {
    const form = document.getElementById('frmImport');
    if (!form.reportValidity()) return;

    const fd = new FormData(form);
    fd.set('action', 'preview');
    fd.set('csrf_token', CSRF_TOKEN);

    const btn = document.getElementById('btnDoPreview');
    btn.disabled    = true;
    btn.textContent = 'Analyzing…';

    let data;
    try {
        const res = await fetch(BASE_URL + 'api/import_zipgrade.php', { method: 'POST', body: fd });
        data = await res.json();
    } catch {
        showToast('Network error — could not reach the server.', 'error');
        btn.disabled = false; btn.textContent = 'Preview';
        return;
    }

    btn.disabled = false; btn.textContent = 'Preview';

    if (data.error) { showToast(data.error, 'error'); return; }
    renderImportPreview(data.preview);
}

function renderImportPreview(p) {
    const area = document.getElementById('importPreviewArea');

    // Item count mismatch warning
    const warnHtml = p.item_count_warning
        ? `<div class="alert alert-warning" style="margin-bottom:.75rem;font-size:.84rem">${escHtml(p.item_count_warning)}</div>`
        : '';

    // Stat tiles
    const mpsCls = p.mps >= 75 ? 'stat-green' : p.mps >= 50 ? 'stat-yellow' : 'stat-red';
    const flgCls = p.flagged_rows > 0 ? 'stat-yellow' : 'stat-green';
    const statsHtml = `
        <div class="import-stats">
            <div class="import-stat">
                <span class="stat-val">${p.total_students}</span>
                <span class="stat-lbl">Students</span>
            </div>
            <div class="import-stat">
                <span class="stat-val">${p.min_score}–${p.max_score}</span>
                <span class="stat-lbl">Score Range</span>
            </div>
            <div class="import-stat">
                <span class="stat-val">${p.mean_score}</span>
                <span class="stat-lbl">Mean Score</span>
            </div>
            <div class="import-stat ${mpsCls}">
                <span class="stat-val">${p.mps}%</span>
                <span class="stat-lbl">MPS</span>
            </div>
            <div class="import-stat ${flgCls}">
                <span class="stat-val">${p.flagged_rows > 0 ? p.flagged_rows + ' ⚠' : '✓ 0'}</span>
                <span class="stat-lbl">Flagged</span>
            </div>
            ${p.skipped_rows > 0 ? `<div class="import-stat"><span class="stat-val">${p.skipped_rows}</span><span class="stat-lbl">Skipped (blank)</span></div>` : ''}
        </div>`;

    // Flagged row details table
    let flaggedHtml = '';
    if (p.flagged_rows > 0) {
        const rows = p.flagged_details.map(r =>
            `<tr><td>Line ${r.line}</td><td>${r.score}</td><td>${escHtml(r.flags.join('; '))}</td></tr>`
        ).join('');
        flaggedHtml = `
            <div class="import-flagged">
                <strong>⚠ ${p.flagged_rows} flagged row(s) — included in import</strong>
                <div class="table-scroll table-responsive" style="max-height:130px;margin-top:.4rem">
                    <table class="data-table table align-middle" style="font-size:.8rem">
                        <thead><tr><th>CSV Line</th><th>Score</th><th>Issue</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </div>`;
    }

    area.innerHTML = warnHtml + statsHtml + flaggedHtml +
        `<p class="import-confirm-hint">
            Review the stats above. Click <strong>Confirm Import</strong> to write this data
            to the selected section — existing data for that section will be replaced.
        </p>`;

    area.style.display = 'block';
    document.getElementById('btnDoConfirm').style.display = '';
}

async function doImportConfirm() {
    const form = document.getElementById('frmImport');
    if (!form.reportValidity()) return;

    const fd = new FormData(form);
    fd.set('action', 'confirm');
    fd.set('csrf_token', CSRF_TOKEN);

    const btn = document.getElementById('btnDoConfirm');
    btn.disabled    = true;
    btn.textContent = 'Importing…';

    let data;
    try {
        const res = await fetch(BASE_URL + 'api/import_zipgrade.php', { method: 'POST', body: fd });
        data = await res.json();
    } catch {
        showToast('Network error during import.', 'error');
        btn.disabled = false; btn.textContent = 'Confirm Import';
        return;
    }

    btn.disabled = false; btn.textContent = 'Confirm Import';

    if (data.error) { showToast(data.error, 'error'); return; }

    closeImportModal();
    const flagNote = data.flagged > 0 ? ` (${data.flagged} row(s) flagged)` : '';
    showToast(`Imported ${data.students} student${data.students !== 1 ? 's' : ''}. MPS: ${data.mps}%${flagNote}`);
    loadAssessment(currentAssessmentId);   // reload grids with fresh DB data
}

// ---- Submit confirmation modal ----

function openSubmitModal() {
    if (!currentAssessment) return;
    const { sections } = currentAssessment;
    const emptySections = sections.filter(s => !(mpsSecCases[s.id] > 0));
    const warn = document.getElementById('submitEmptyWarning');
    const list = document.getElementById('submitEmptySectionList');
    if (emptySections.length > 0) {
        list.innerHTML = emptySections.map(s => `<li>${escHtml(s.name)}</li>`).join('');
        warn.style.display = '';
    } else {
        warn.style.display = 'none';
    }
    document.getElementById('submitModal').style.display = '';
}

function closeSubmitModal() {
    document.getElementById('submitModal').style.display = 'none';
}

async function confirmSubmit() {
    closeSubmitModal();
    await saveData('submit');
}

function updateSubmitState() {
    const btn = document.getElementById('btnSubmit');
    if (!btn || !currentAssessment) return;
    const locked = ['submitted', 'approved'].includes(currentAssessment.assessment.status);
    if (locked) { btn.disabled = true; return; }
    btn.disabled = !Object.values(mpsSecCases).some(v => v > 0);
}

async function loadAssessment(id) {
    const selectorPanel = document.getElementById('newAssessmentPanel');
    if (selectorPanel) selectorPanel.style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('assessmentDetail').style.display = 'block';
    document.getElementById('mpsTable').innerHTML = '<tbody><tr><td colspan="100">Loading assessment data…</td></tr></tbody>';
    document.getElementById('itemTable').innerHTML = '<tbody><tr><td colspan="100">Loading assessment data…</td></tr></tbody>';

    try {
        const data = await apiGet(`api/get_assessment_data.php?id=${encodeURIComponent(id)}`);
        if (data.error) throw new Error(data.error);
        if (!data.assessment || !Array.isArray(data.sections)) {
            throw new Error('The server returned incomplete assessment data. Please refresh and try again.');
        }

        currentAssessment = data;
        currentAssessmentId = Number(id);

        // Seed mpsSecCases / mpsSecFx from saved DB data.
        // recomputeMps() will override these with live input values for unlocked assessments.
        const sfInit = data.score_frequencies || {};
        mpsSecCases = {};
        mpsSecFx = {};
        data.sections.forEach(sec => {
            let cases = 0, fx = 0;
            Object.entries(sfInit[sec.id] || {}).forEach(([score, freq]) => {
                cases += +freq;
                fx    += +freq * +score;
            });
            mpsSecCases[sec.id] = cases;
            mpsSecFx[sec.id]    = fx;
        });

        // Update header
        const a = data.assessment;
        document.getElementById('detailTitle').textContent = a.title;
        document.getElementById('detailMeta').textContent =
            `${a.subject_name} · Grade ${a.grade_level} · Term ${a.term_no} · ${a.total_items} items · ${a.date_given || 'no date'}`;
        const chip = document.getElementById('detailStatus');
        chip.textContent = a.status.charAt(0).toUpperCase() + a.status.slice(1);
        chip.className = 'status-chip badge status-' + a.status;

        // Export link
        document.getElementById('btnExport').href = `${BASE_URL}api/export_excel.php?assessment_id=${encodeURIComponent(id)}`;

        // Remarks (returned)
        const ra = document.getElementById('returnedAlert');
        if (a.status === 'returned' && a.remarks) {
            ra.style.display = 'block';
            ra.textContent = 'Returned by admin: ' + a.remarks;
        } else { ra.style.display = 'none'; }

        // Lock buttons if submitted/approved (Submit state is managed by updateSubmitState())
        const locked = (a.status === 'submitted' || a.status === 'approved');
        document.getElementById('btnSaveDraft').disabled = locked;
        const btnImport = document.getElementById('btnImportZipgrade');
        if (btnImport) btnImport.style.display = locked ? 'none' : '';

        buildMpsTable(data);
        buildItemTable(data);
        buildMiniChart(data);
    } catch (err) {
        currentAssessment = null;
        const message = err instanceof Error ? err.message : 'An unexpected error occurred.';
        ['mpsTable', 'itemTable'].forEach(id => {
            const table = document.getElementById(id);
            table.innerHTML = '';
            const body = table.createTBody();
            const row = body.insertRow();
            const cell = row.insertCell();
            cell.colSpan = 100;
            cell.className = 'text-danger';
            cell.textContent = `Could not load assessment data: ${message}`;
        });
        showToast(`Could not load assessment: ${message}`, 'error');
    }
}

function backToMyAssessments() {
    document.getElementById('assessmentDetail').style.display = 'none';
    const selectorPanel = document.getElementById('newAssessmentPanel');
    if (selectorPanel) selectorPanel.style.display = 'none';
    document.getElementById('emptyState').style.display = 'block';
    currentAssessment = null;
    currentAssessmentId = null;
    mpsSecCases = {};
    mpsSecFx = {};
    if (miniChart) {
        miniChart.destroy();
        miniChart = null;
    }
}

function freezeAssessmentHeaderRows(table) {
    const rows = table?.tHead?.rows;
    if (!rows || rows.length < 2) return;

    assessmentHeaderObservers.get(table)?.disconnect();
    const updateOffsets = () => {
        const firstRowHeight = rows[0].getBoundingClientRect().height;
        Array.from(rows[0].cells).forEach(cell => {
            cell.style.position = 'sticky';
            cell.style.top = '0px';
            cell.style.zIndex = '4';
        });
        Array.from(rows[1].cells).forEach(cell => {
            cell.style.position = 'sticky';
            cell.style.top = `${firstRowHeight}px`;
            cell.style.zIndex = '3';
        });
    };
    updateOffsets();

    if (typeof ResizeObserver !== 'undefined') {
        const observer = new ResizeObserver(updateOffsets);
        observer.observe(rows[0]);
        assessmentHeaderObservers.set(table, observer);
    }
}

// ---- MPS TABLE ----
function buildMpsTable(data) {
    const { assessment: a, sections, score_frequencies: sf } = data;
    const totalItems = +a.total_items;
    const locked = (a.status === 'submitted' || a.status === 'approved');

    const tbl = document.getElementById('mpsTable');
    tbl.innerHTML = '';

    // Header row 1: section names
    const thead = tbl.createTHead();
    const hr1 = thead.insertRow();
    addTH(hr1, 'Score', 2);
    sections.forEach(s => addTH(hr1, s.name, 2));
    addTH(hr1, 'TOTAL', 2);

    // Header row 2: f / f(x) per section
    const hr2 = thead.insertRow();
    sections.forEach(() => {
        addTH(hr2, 'f');
        addTH(hr2, 'f(x)');
    });
    addTH(hr2, 'f');
    addTH(hr2, 'f(x)');

    // Fix span on first header cell
    hr1.cells[0].rowSpan = 1;
    // Actually re-do cleanly:
    tbl.innerHTML = '';
    const thead2 = tbl.createTHead();
    const r1 = thead2.insertRow();
    const scTH = document.createElement('th');
    scTH.textContent = 'Score'; scTH.rowSpan = 2;
    r1.appendChild(scTH);
    sections.forEach(s => {
        const th = document.createElement('th');
        th.colSpan = 2; th.textContent = s.name; th.classList.add('section-group-start');
        r1.appendChild(th);
    });
    const totTH = document.createElement('th');
    totTH.colSpan = 2; totTH.textContent = 'TOTAL';
    r1.appendChild(totTH);

    const r2 = thead2.insertRow();
    sections.forEach(() => {
        addTH(r2, 'f').classList.add('section-group-start');
        addTH(r2, 'f(x)');
    });
    addTH(r2,'f'); addTH(r2,'f(x)');

    // Body: rows from totalItems down to 0
    const tbody = tbl.createTBody();
    for (let score = totalItems; score >= 0; score--) {
        const tr = tbody.insertRow();
        tr.dataset.score = score;
        const td0 = tr.insertCell(); td0.textContent = score; td0.className = 'row-label';

        let rowTotalF = 0;
        sections.forEach((sec, secIdx) => {
            const savedF = sf[sec.id]?.[score] ?? 0;
            const cellF  = tr.insertCell();
            cellF.classList.add('section-group-start');
            if (locked) {
                cellF.textContent = savedF || '';
            } else {
                const inp = document.createElement('input');
                inp.type = 'number'; inp.min = 0; inp.max = 9999;
                inp.value = savedF || '';
                inp.className = 'grid-cell';
                inp.dataset.score  = score;
                inp.dataset.secId  = sec.id;
                inp.dataset.row    = totalItems - score;
                inp.dataset.col    = secIdx;
                inp.addEventListener('input', onMpsInput);
                cellF.appendChild(inp);
            }
            const cellFx = tr.insertCell();
            cellFx.className = 'fx-cell';
            const fx = savedF ? savedF * score : 0;
            cellFx.textContent = fx || '';
            if (savedF) rowTotalF += savedF;
        });
        const tfCell  = tr.insertCell(); tfCell.className = 'total-col tf-cell';
        tfCell.textContent = rowTotalF || '';
        const tfxCell = tr.insertCell(); tfxCell.className = 'total-col tfx-cell';
        tfxCell.textContent = rowTotalF ? rowTotalF * score : '';
    }

    // Summary rows
    const summaryLabels = ['CASES','MEAN','MPS (%)'];
    summaryLabels.forEach(lbl => {
        const tr = tbody.insertRow();
        tr.className = 'summary-row';
        const td = tr.insertCell(); td.textContent = lbl; td.className = 'row-label';
        sections.forEach(sec => {
            const fCell = tr.insertCell();
            fCell.className = `sum-${lbl.split(' ')[0].toLowerCase()}-${sec.id} section-group-start`;
            const fxPH = tr.insertCell();
            // Σf(x) shown beside CASES in the f(x) column
            if (lbl === 'CASES') fxPH.className = `sum-fx-${sec.id}`;
        });
        tr.insertCell().className = 'total-col';
        tr.insertCell().className = 'total-col';
    });

    // Band rows
    BAND_KEYS.forEach(bk => {
        const tr = tbody.insertRow();
        tr.className = 'band-row';
        const td = tr.insertCell();
        td.textContent = `${bk} — ${MASTERY_BANDS[bk].label} (${MASTERY_BANDS[bk].min}–${MASTERY_BANDS[bk].max}%)`;
        td.colSpan = 1; td.className = 'row-label';
        sections.forEach(sec => {
            const c1 = tr.insertCell(); c1.className = `band-${bk}-${sec.id} section-group-start`;
            const c2 = tr.insertCell(); c2.className = `bandpct-${bk}-${sec.id}`;
        });
        tr.insertCell().className = 'total-col';
        tr.insertCell().className = 'total-col';
    });

    // Proficiency Level rows (DepEd descriptor rating scale)
    PL_KEYS.forEach(pk => {
        const tr = tbody.insertRow();
        tr.className = 'band-row';
        const td = tr.insertCell();
        td.textContent = `${PROFICIENCY_LEVELS[pk].label} (${PROFICIENCY_LEVELS[pk].min}–${PROFICIENCY_LEVELS[pk].max}%)`;
        td.colSpan = 1; td.className = 'row-label';
        sections.forEach(sec => {
            const c1 = tr.insertCell(); c1.className = `pl-${pk}-${sec.id} section-group-start`;
            const c2 = tr.insertCell(); c2.className = `plpct-${pk}-${sec.id}`;
        });
        tr.insertCell().className = 'total-col';
        tr.insertCell().className = 'total-col';
    });

    // NPWRM row
    const ntr = tbody.insertRow();
    ntr.className = 'summary-row';
    const ntd = ntr.insertCell(); ntd.textContent = `NPWRM (≥${MASTERY_THRESHOLD}%)`; ntd.className = 'row-label';
    sections.forEach(sec => {
        ntr.insertCell().className = `npwrm-${sec.id} section-group-start`;
        ntr.insertCell();
    });
    ntr.insertCell().className = 'total-col';
    ntr.insertCell().className = 'total-col';

    initGridPasteNav(tbl);
    freezeAssessmentHeaderRows(tbl);
    recomputeMps();
}

function addTH(row, text, span) {
    const th = document.createElement('th');
    th.textContent = text;
    if (span) th.colSpan = span;
    row.appendChild(th);
    return th;
}

// ============================================================
// Grid paste + arrow-key navigation (Excel-style), shared by the
// MPS and Item Analysis score-entry tables. Each editable cell carries
// data-row/data-col (stable per table: row = score/item position, col =
// section index) so a target cell can be found directly without walking
// the DOM. Scoped per-table via closest('table') so the two grids never
// bleed into each other.
// ============================================================
function initGridPasteNav(tbl) {
    if (!tbl) return;
    tbl.querySelectorAll('input.grid-cell').forEach(input => {
        if (input.dataset.gridWired) return; // don't double-attach on rebuild
        input.dataset.gridWired = '1';
        input.addEventListener('paste', onGridPaste);
        input.addEventListener('keydown', onGridKeydown);
    });
}

function gridCellAt(tbl, row, col) {
    return tbl.querySelector(`input.grid-cell[data-row="${row}"][data-col="${col}"]`);
}

function onGridPaste(e) {
    const clipboard = e.clipboardData || window.clipboardData;
    const text = clipboard ? clipboard.getData('text') : '';
    if (!text) return;

    // Excel copies a multi-cell selection as tab/newline-delimited text; a lone value has
    // neither, so let the browser's normal single-cell paste handle that case untouched.
    if (text.indexOf('\t') === -1 && text.indexOf('\n') === -1) return;
    e.preventDefault();

    const input = e.target;
    const tbl = input.closest('table');
    if (!tbl) return;

    const rows = text.replace(/\r/g, '').split('\n');
    if (rows.length && rows[rows.length - 1] === '') rows.pop(); // trailing newline from Excel

    const startRow = +input.dataset.row;
    const startCol = +input.dataset.col;

    rows.forEach((rowText, rOffset) => {
        rowText.split('\t').forEach((rawValue, cOffset) => {
            const target = gridCellAt(tbl, startRow + rOffset, startCol + cOffset);
            if (!target || target.disabled) return;
            target.value = rawValue.trim();
            target.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
}

// type="number" doesn't support selection APIs consistently across browsers — treat
// "position unknown" as "at the edge" so the arrow-key jump is never silently blocked.
function gridCaretAtStart(input) {
    try {
        const s = input.selectionStart, e = input.selectionEnd;
        if (s === null || e === null) return true;
        return s === 0 && e === 0;
    } catch (err) { return true; }
}

function gridCaretAtEnd(input) {
    try {
        const s = input.selectionStart, e = input.selectionEnd;
        if (s === null || e === null) return true;
        return s === input.value.length && e === input.value.length;
    } catch (err) { return true; }
}

function onGridKeydown(e) {
    const input = e.target;
    const tbl = input.closest('table');
    if (!tbl) return;
    const row = +input.dataset.row;
    const col = +input.dataset.col;
    let target = null;

    if (e.key === 'ArrowUp') {
        target = gridCellAt(tbl, row - 1, col);
    } else if (e.key === 'ArrowDown') {
        target = gridCellAt(tbl, row + 1, col);
    } else if (e.key === 'ArrowLeft') {
        if (!gridCaretAtStart(input)) return;
        target = gridCellAt(tbl, row, col - 1);
    } else if (e.key === 'ArrowRight') {
        if (!gridCaretAtEnd(input)) return;
        target = gridCellAt(tbl, row, col + 1);
    } else {
        return;
    }

    if (target && !target.disabled) {
        e.preventDefault();
        target.focus();
        target.select();
    }
}

function onMpsInput(e) {
    recomputeMps();
}

function recomputeMps() {
    if (!currentAssessment) return;
    const { sections, assessment } = currentAssessment;
    const totalItems = +assessment.total_items;
    const tbl = document.getElementById('mpsTable');

    let grandCases = 0, grandFx = 0, grandNpwrm = 0;
    const grandBands = {};
    BAND_KEYS.forEach(k => grandBands[k] = 0);
    const grandPL = {};
    PL_KEYS.forEach(k => grandPL[k] = 0);

    sections.forEach(sec => {
        let cases = 0, sumFx = 0, npwrm = 0;
        const bands = {}; BAND_KEYS.forEach(k => bands[k] = 0);
        const pls   = {}; PL_KEYS.forEach(k => pls[k] = 0);

        // Iterate all score rows
        tbl.querySelectorAll(`tr[data-score]`).forEach(tr => {
            const score = +tr.dataset.score;
            const inp   = tr.querySelector(`input[data-sec-id="${sec.id}"]`);
            const f     = inp ? Math.max(0, +inp.value || 0) : 0;
            const fx    = f * score;

            // Update f(x) cell
            const cells = tr.cells;
            // Find cells for this section
            const secIdx = Array.from(currentAssessment.sections).findIndex(s => +s.id === +sec.id);
            const fCell  = secIdx * 2 + 1; // 0=score label, then 2 cells per section
            const fxCell = fCell + 1;
            if (inp) {
                cells[fxCell] && (cells[fxCell].textContent = f > 0 ? fx : '');
            }

            cases  += f;
            sumFx  += fx;

            if (totalItems > 0) {
                const pct  = score / totalItems * 100;
                const band = getBand(pct);
                bands[band] += f;
                const pl = getPL(pct);
                pls[pl] += f;
                if (pct >= MASTERY_THRESHOLD) npwrm += f;
            }
        });

        // Update total F/FX column per row — skip for now (complex index), just update summaries
        const mean = cases > 0 ? sumFx / cases : 0;
        const mps  = (cases > 0 && totalItems > 0) ? mean / totalItems * 100 : 0;

        // Update summary cells
        setCell(`.sum-cases-${sec.id}`, cases || '');
        setCell(`.sum-fx-${sec.id}`,    sumFx > 0 ? sumFx : '');  // Σf(x) beside CASES
        setCell(`.sum-mean-${sec.id}`, cases > 0 ? fmtNum(mean) : '—');
        setCell(`.sum-mps-${sec.id}`, cases > 0 ? fmtNum(mps) : '—');

        // Keep global maps current for Item Analysis cross-check
        mpsSecCases[sec.id] = cases;
        mpsSecFx[sec.id]    = sumFx;

        BAND_KEYS.forEach(bk => {
            setCell(`.band-${bk}-${sec.id}`, bands[bk] > 0 ? bands[bk] : '');
            const prop = cases > 0 ? (bands[bk] / cases * 100).toFixed(1) + '%' : '—';
            setCell(`.bandpct-${bk}-${sec.id}`, cases > 0 ? prop : '—');
        });

        PL_KEYS.forEach(pk => {
            setCell(`.pl-${pk}-${sec.id}`, pls[pk] > 0 ? pls[pk] : '');
            const prop = cases > 0 ? (pls[pk] / cases * 100).toFixed(1) + '%' : '—';
            setCell(`.plpct-${pk}-${sec.id}`, cases > 0 ? prop : '—');
        });

        setCell(`.npwrm-${sec.id}`, cases > 0 ? npwrm : '');

        grandCases  += cases;
        grandFx     += sumFx;
        grandNpwrm  += npwrm;
        BAND_KEYS.forEach(k => grandBands[k] += bands[k]);
        PL_KEYS.forEach(k => grandPL[k] += pls[k]);
    });

    // Update row totals F/FX columns
    tbl.querySelectorAll('tr[data-score]').forEach(tr => {
        const score = +tr.dataset.score;
        let totalF = 0, totalFx = 0;
        tr.querySelectorAll('input').forEach(inp => {
            const f = Math.max(0, +inp.value || 0);
            totalF  += f;
            totalFx += f * score;
        });
        const lastCells = tr.cells;
        const len = lastCells.length;
        lastCells[len-2].textContent = totalF  || '';
        lastCells[len-1].textContent = totalFx || '';
    });

    // Refresh Item Analysis cross-check whenever MPS changes
    recomputeItemTotals();
    updateSubmitState();
}

function setCell(selector, val) {
    const el = document.querySelector(selector);
    if (el) el.textContent = val;
}

function getBand(pct) {
    for (const [key, band] of Object.entries(MASTERY_BANDS)) {
        if (pct >= band.min && pct <= band.max) return key;
    }
    return 'ANM';
}

function getPL(pct) {
    for (const [key, level] of Object.entries(PROFICIENCY_LEVELS)) {
        if (pct >= level.min && pct <= level.max) return key;
    }
    return 'DNME';
}

// ---- ITEM ANALYSIS TABLE ----
function buildItemTable(data) {
    const { assessment: a, sections, item_correct_counts: icc } = data;
    const compMap    = data.competency_map || {};
    const hasCompMap = Object.keys(compMap).length > 0;
    const totalItems = +a.total_items;
    const locked = (a.status === 'submitted' || a.status === 'approved');

    const tbl = document.getElementById('itemTable');
    tbl.innerHTML = '';

    const thead = tbl.createTHead();
    const r1 = thead.insertRow();
    const itTH = document.createElement('th'); itTH.textContent = 'Item'; itTH.rowSpan = 2; r1.appendChild(itTH);
    if (hasCompMap) {
        const cTH = document.createElement('th');
        cTH.textContent = 'Competency'; cTH.rowSpan = 2;
        cTH.style.cssText = 'max-width:140px;font-size:.78rem';
        r1.appendChild(cTH);
    }
    sections.forEach(s => { const th = document.createElement('th'); th.colSpan=2; th.textContent=s.name; th.classList.add('section-group-start'); r1.appendChild(th); });
    const totTH2 = document.createElement('th'); totTH2.colSpan=2; totTH2.textContent='TOTAL'; r1.appendChild(totTH2);
    const r2 = thead.insertRow();
    sections.forEach(() => {
        addTH(r2, 'f').classList.add('section-group-start');
        addTH(r2, '%');
    });
    addTH(r2,'f'); addTH(r2,'%');

    const tbody = tbl.createTBody();

    for (let item = 1; item <= totalItems; item++) {
        const tr = tbody.insertRow();
        tr.dataset.item = item;
        const td0 = tr.insertCell(); td0.textContent = item; td0.className = 'row-label';
        if (hasCompMap) {
            const comp = compMap[item];
            const tdC  = tr.insertCell();
            tdC.className = 'comp-col';
            if (comp) {
                const label = comp.code || comp.description.substring(0, 35) + (comp.description.length > 35 ? '…' : '');
                tdC.textContent = label;
                tdC.title = (comp.code ? comp.code + ' — ' : '') + comp.description;
            } else {
                tdC.textContent = '—';
                tdC.style.color = 'var(--c-border)';
            }
        }

        sections.forEach((sec, secIdx) => {
            const savedF = icc[sec.id]?.[item] ?? 0;
            const cases  = mpsSecCases[sec.id] || 0;
            const cellF  = tr.insertCell();
            cellF.classList.add('section-group-start');
            if (locked) {
                cellF.textContent = savedF || '';
            } else {
                const inp = document.createElement('input');
                inp.type = 'number'; inp.min = 0; inp.max = cases || 9999;
                inp.value = savedF || '';
                inp.className = 'grid-cell';
                inp.dataset.item  = item;
                inp.dataset.secId = sec.id;
                inp.dataset.row   = item - 1;
                inp.dataset.col   = secIdx;
                inp.addEventListener('input', () => onItemInput(tr, data));
                cellF.appendChild(inp);
            }
            const pct = cases > 0 ? savedF / cases * 100 : 0;
            const pctCell = tr.insertCell();
            pctCell.className = `pct-cell pct-cell-${sec.id}`;
            updatePctCell(pctCell, pct, cases > 0);
        });

        // Total columns
        let totF = 0, totCases = 0;
        sections.forEach(sec => {
            totF     += icc[sec.id]?.[item] ?? 0;
            totCases += mpsSecCases[sec.id] || 0;
        });
        const totPct = totCases > 0 ? totF / totCases * 100 : 0;
        const tfCell = tr.insertCell(); tfCell.className = 'total-col'; tfCell.textContent = totF || '';
        const tpCell = tr.insertCell(); tpCell.className = 'total-col';
        updatePctCell(tpCell, totPct, totCases > 0);
    }

    // TOTAL row — sum of item correct counts per section (cross-check vs MPS Σf(x))
    const totRow = tbody.insertRow();
    totRow.className = 'summary-row';
    const lblCell = totRow.insertCell(); lblCell.textContent = 'TOTAL'; lblCell.className = 'row-label';
    sections.forEach(sec => {
        const totalCell = totRow.insertCell();
        totalCell.className = `item-total-${sec.id} total-col section-group-start`;
        const checkCell = totRow.insertCell();
        checkCell.className = `item-check-${sec.id} total-col`;
    });
    // Grand total cells
    totRow.insertCell().className = 'total-col';
    totRow.insertCell().className = 'total-col';

    initGridPasteNav(tbl);
    freezeAssessmentHeaderRows(tbl);
    recomputeItemTotals();
}

function onItemInput(tr, data) {
    const sections = data.sections;

    sections.forEach(sec => {
        const inp     = tr.querySelector(`input[data-sec-id="${sec.id}"]`);
        const cases   = mpsSecCases[sec.id] || 0;
        const pctCell = tr.querySelector(`.pct-cell-${sec.id}`);
        if (inp && pctCell) {
            const f   = Math.max(0, +inp.value || 0);
            const pct = cases > 0 ? f / cases * 100 : 0;
            updatePctCell(pctCell, pct, cases > 0);
        }
    });

    // Update total columns for this row
    let totF = 0, totCases = 0;
    sections.forEach(sec => {
        const inp = tr.querySelector(`input[data-sec-id="${sec.id}"]`);
        totF     += inp ? Math.max(0, +inp.value || 0) : 0;
        totCases += mpsSecCases[sec.id] || 0;
    });
    const totPct = totCases > 0 ? totF / totCases * 100 : 0;
    const cells  = tr.cells;
    cells[cells.length - 2].textContent = totF || '';
    const tpCell = cells[cells.length - 1];
    updatePctCell(tpCell, totPct, totCases > 0);

    recomputeItemTotals();
}

function updatePctCell(cell, pct, hasData) {
    cell.className = 'total-col ' + (hasData ? pctColor(pct) : '');
    cell.textContent = hasData ? fmtNum(pct, 1) + '%' : '—';
}

function recomputeItemTotals() {
    if (!currentAssessment) return;
    const { sections, assessment, item_correct_counts: icc } = currentAssessment;
    const locked = assessment.status === 'submitted' || assessment.status === 'approved';
    const tbl = document.getElementById('itemTable');
    if (!tbl) return;

    sections.forEach(sec => {
        let total = 0;
        if (locked) {
            if (icc[sec.id]) Object.values(icc[sec.id]).forEach(c => { total += +c; });
        } else {
            tbl.querySelectorAll(`input[data-sec-id="${sec.id}"]`).forEach(inp => {
                total += Math.max(0, +inp.value || 0);
            });
        }
        const fx        = mpsSecFx[sec.id] ?? 0;
        const totalCell = document.querySelector(`.item-total-${sec.id}`);
        const checkCell = document.querySelector(`.item-check-${sec.id}`);
        if (!totalCell) return;
        totalCell.textContent = total > 0 ? total : '';
        if (checkCell) {
            if (fx === 0 && total === 0) {
                checkCell.textContent = '—';
                checkCell.className   = `item-check-${sec.id} total-col`;
                checkCell.title       = '';
            } else if (total === fx) {
                checkCell.textContent = '✓';
                checkCell.className   = `item-check-${sec.id} total-col pct-green`;
                checkCell.title       = 'Item Analysis total matches MPS Σf(x)';
            } else {
                checkCell.textContent = `≠ ${fx}`;
                checkCell.className   = `item-check-${sec.id} total-col pct-red`;
                checkCell.title       = `Item total (${total}) ≠ MPS Σf(x) (${fx}). Recheck frequency entries.`;
            }
        }
    });
}

function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    document.getElementById('tab-mps').style.display  = tab === 'mps'  ? 'block' : 'none';
    document.getElementById('tab-item').style.display = tab === 'item' ? 'block' : 'none';
    freezeAssessmentHeaderRows(document.getElementById(tab === 'mps' ? 'mpsTable' : 'itemTable'));
}

// ---- MINI CHART ----
function buildMiniChart(data) {
    const { sections, assessment: a, score_frequencies: sf } = data;
    const totalItems = +a.total_items;
    const labels = [], values = [];

    sections.forEach(sec => {
        let cases = 0, sumFx = 0;
        if (sf[sec.id]) {
            Object.entries(sf[sec.id]).forEach(([score, freq]) => {
                cases  += +freq;
                sumFx  += +freq * +score;
            });
        }
        const mps = (cases > 0 && totalItems > 0) ? sumFx / cases / totalItems * 100 : 0;
        labels.push(sec.name.split(' ').pop()); // last word as short label
        values.push(+mps.toFixed(2));
    });

    const ctx = document.getElementById('miniMpsChart');
    if (!ctx || typeof Chart === 'undefined') return;
    if (miniChart) miniChart.destroy();
    miniChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'MPS %',
                data: values,
                backgroundColor: values.map(masteryColor),
            }],
        },
        options: {
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    min: 0, max: 100,
                    grid: { color: '#e0e7ef' },
                },
            },
            annotation: { annotations: [{ type:'line', yMin:75, yMax:75, borderColor: THEME.gold, borderWidth:2 }] },
        },
    });
}

// ---- SAVE ----
async function saveData(action) {
    if (!currentAssessment) return;
    const { assessment: a, sections } = currentAssessment;
    const id = currentAssessmentId;

    // Collect MPS data
    const scoreData = {};
    sections.forEach(sec => {
        scoreData[sec.id] = {};
        document.querySelectorAll(`#mpsTable input[data-sec-id="${sec.id}"]`).forEach(inp => {
            const score = +inp.dataset.score;
            const f     = Math.max(0, +inp.value || 0);
            if (f > 0) scoreData[sec.id][score] = f;
        });
    });

    // Collect Item Analysis data
    const itemData = {};
    sections.forEach(sec => {
        itemData[sec.id] = {};
        document.querySelectorAll(`#itemTable input[data-sec-id="${sec.id}"]`).forEach(inp => {
            const item = +inp.dataset.item;
            const f    = Math.max(0, +inp.value || 0);
            if (f > 0) itemData[sec.id][item] = f;
        });
    });

    const result = await apiPost('api/save_assessment.php', {
        assessment_id: id,
        action,
        score_frequencies: scoreData,
        item_correct_counts: itemData,
    });

    if (result.error) {
        showToast(result.error, 'error');
    } else {
        showToast(action === 'submit' ? 'Assessment submitted!' : 'Draft saved.');
        setTimeout(() => location.reload(), 800);
    }
}

// ============================================================
// ADMIN DASHBOARD
// ============================================================

const charts = {};

function showDashboardEmptyState() {
    const empty   = document.getElementById('dashboardEmptyState');
    const content = document.getElementById('dashboardContent');
    const btn     = document.getElementById('btnRefresh');
    const btnExp  = document.getElementById('btnExportSubject');
    if (empty)   empty.style.display   = '';
    if (content) content.style.display = 'none';
    if (btn)     btn.disabled          = true;
    if (btnExp)  btnExp.disabled       = true;
}

function showDashboardContent() {
    const empty   = document.getElementById('dashboardEmptyState');
    const content = document.getElementById('dashboardContent');
    const btn     = document.getElementById('btnRefresh');
    const btnExp  = document.getElementById('btnExportSubject');
    if (empty)   empty.style.display   = 'none';
    if (content) content.style.display = '';
    if (btn)     btn.disabled          = false;
    if (btnExp)  btnExp.disabled       = false;
}

function exportSubjectReport() {
    const subject = document.getElementById('f_subject')?.value || '';
    if (!subject) return;
    const p = new URLSearchParams({ subject });
    const sy      = document.getElementById('f_sy')?.value      || '';
    const term    = document.getElementById('f_term')?.value    || '';
    const grade   = document.getElementById('f_grade')?.value   || '';
    const section = document.getElementById('f_section')?.value || '';
    if (sy)      p.set('sy',      sy);
    if (term)    p.set('term',    term);
    if (grade)   p.set('grade',   grade);
    if (section) p.set('section', section);
    window.location.href = BASE_URL + 'api/export_subject_report.php?' + p.toString();
}

async function refreshDashboard() {
    const subjectName = document.getElementById('f_subject')?.value || '';
    if (!subjectName) { showDashboardEmptyState(); return; }

    const params = new URLSearchParams({
        sy:         document.getElementById('f_sy')?.value         || '',
        term:       document.getElementById('f_term')?.value       || '',
        grade:      document.getElementById('f_grade')?.value      || '',
        subject:    subjectName,
        section:    document.getElementById('f_section')?.value    || '',
        assessment: document.getElementById('f_assessment')?.value || '',
    });
    const data = await apiGet(`api/get_dashboard_data.php?${params}`);
    if (!data || data.error) { showToast('Failed to load dashboard data.', 'error'); return; }

    showDashboardContent();
    renderKpis(data.kpis);
    renderChart('chartMpsSubject',   buildMpsSubjectChart(data));
    renderChart('chartMpsGrade',     buildMpsGradeChart(data));
    renderBandDistPies(data);
    renderPLDistTable(data);
    renderChart('chartLeastMastered',buildLeastMasteredChart(data));
    renderChart('chartMpsTrend',     buildMpsTrendChart(data));
    renderHeatmap(data.item_heatmap);
    renderCompetencySection(data);
}

function renderKpis(kpis) {
    if (!kpis) return;
    setText('kpi_mps',       fmtNum(kpis.overall_mps) + '%');
    setText('kpi_examinees', kpis.total_examinees ?? '—');
    setText('kpi_submitted', kpis.submitted_count  ?? '—');
    setText('kpi_below50',   kpis.below50_items    ?? '—');
    const bar = document.getElementById('kpi_mps_bar');
    if (bar) bar.style.width = Math.min(100, kpis.overall_mps || 0) + '%';
}

function setText(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
}

function renderChart(id, cfg) {
    if (charts[id]) charts[id].destroy();
    const ctx = document.getElementById(id);
    if (!ctx) return;
    charts[id] = new Chart(ctx, cfg);
}

function buildMpsSubjectChart(data) {
    const d = data.mps_per_subject || [];
    return {
        type: 'bar',
        data: {
            labels: d.map(r => r.subject_name),
            datasets: [{
                label: 'MPS %',
                data:  d.map(r => +r.mps),
                backgroundColor: d.map(r => masteryColor(r.mps)),
            }],
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                annotation: {
                    annotations: { line75: { type:'line', yMin:75, yMax:75, borderColor: THEME.gold, borderWidth:2, label:{content:'Target 75%', display:true} } }
                },
            },
            scales: { y: { min:0, max:100 } },
        },
    };
}

function buildMpsGradeChart(data) {
    const d = data.mps_per_grade || [];
    return {
        type: 'bar',
        data: {
            labels: d.map(r => 'Grade ' + r.grade_level),
            datasets: [{
                label: 'MPS %',
                data:  d.map(r => +r.mps),
                backgroundColor: d.map(r => masteryColor(r.mps)),
            }],
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                annotation: {
                    annotations: { line75: { type:'line', yMin:75, yMax:75, borderColor: THEME.gold, borderWidth:2, label:{content:'Target 75%', display:true} } }
                },
            },
            scales: { y: { min:0, max:100 } },
        },
    };
}

function renderBandDistPies(data) {
    const piesEl   = document.getElementById('bandDistPies');
    const legendEl = document.getElementById('bandDistLegend');
    if (!piesEl) return;

    // Destroy previous pie instances
    Object.keys(charts).filter(k => k.startsWith('bandPie_')).forEach(k => {
        charts[k].destroy();
        delete charts[k];
    });
    piesEl.innerHTML = '';

    const grades = (data.band_distribution || []).filter(g =>
        SCORE_BAND_ORDER.some(b => (g.bands[b] || 0) > 0)
    );

    if (!grades.length) {
        piesEl.innerHTML = '<p class="text-muted" style="padding:.5rem">No data.</p>';
        if (legendEl) legendEl.innerHTML = '';
        return;
    }

    grades.forEach(gradeRow => {
        const total = SCORE_BAND_ORDER.reduce((s, b) => s + (gradeRow.bands[b] || 0), 0);
        if (!total) return;

        const wrap = document.createElement('div');
        wrap.style.cssText = 'display:flex;flex-direction:column;align-items:center;gap:.35rem';

        const gradeLabel = document.createElement('p');
        gradeLabel.textContent = 'Grade ' + gradeRow.grade_level;
        gradeLabel.style.cssText = 'margin:0;font-weight:600;font-size:.875rem;color:var(--maroon-dark)';

        const canvasWrap = document.createElement('div');
        canvasWrap.style.cssText = 'position:relative;width:210px;height:210px';
        const canvas = document.createElement('canvas');
        canvasWrap.appendChild(canvas);

        wrap.appendChild(gradeLabel);
        wrap.appendChild(canvasWrap);
        piesEl.appendChild(wrap);

        const key = 'bandPie_' + gradeRow.grade_level;
        charts[key] = new Chart(canvas, {
            type: 'pie',
            data: {
                labels: SCORE_BAND_ORDER,
                datasets: [{
                    data: SCORE_BAND_ORDER.map(b => gradeRow.bands[b] || 0),
                    backgroundColor: SCORE_BAND_ORDER.map(b => SCORE_BAND_COLORS[b]),
                    borderWidth: 2,
                    borderColor: '#fff',
                }],
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: item => {
                                const count = item.raw;
                                const pct   = total > 0 ? (count / total * 100).toFixed(1) : '0.0';
                                return ` ${item.label}: ${count} student${count !== 1 ? 's' : ''} (${pct}%)`;
                            },
                        },
                    },
                },
            },
        });
    });

    // Shared legend (one row, all 7 bands)
    if (legendEl) {
        legendEl.innerHTML = `
            <div style="display:flex;flex-wrap:wrap;gap:.4rem 1.1rem;justify-content:center;font-size:.8rem;color:var(--c-text)">
                ${SCORE_BAND_ORDER.map(b => `
                    <span style="display:inline-flex;align-items:center;gap:.3rem">
                        <span style="display:inline-block;width:12px;height:12px;border-radius:2px;
                              background:${SCORE_BAND_COLORS[b]};flex-shrink:0;border:1px solid rgba(0,0,0,.1)"></span>
                        ${b}
                    </span>`).join('')}
            </div>`;
    }
}

// Proficiency Level (DepEd descriptor scale) distribution per grade —
// a table rather than pies, since this is meant to answer "how many
// learners scored in this range", not just show a proportion.
function renderPLDistTable(data) {
    const tbl = document.getElementById('plDistTable');
    if (!tbl) return;

    const grades = data.pl_distribution || [];
    const hint   = document.getElementById('plEnrollmentHint');
    const termChosen = !!document.getElementById('f_term')?.value;
    if (hint) hint.style.display = termChosen ? 'none' : '';

    if (!grades.length) {
        tbl.innerHTML = '<tbody><tr><td class="text-muted" style="padding:.5rem 0;border:none">No data.</td></tr></tbody>';
        return;
    }

    let html = '<thead><tr><th>Grade</th>';
    PL_KEYS.forEach(pk => {
        const lvl = PROFICIENCY_LEVELS[pk];
        html += `<th>${escHtml(lvl.label)}<br><small class="text-muted">(${lvl.min}–${lvl.max}%)</small></th>`;
    });
    html += '<th>Examinees</th><th>Annual Enrollment</th><th>Monthly Enrollment</th><th>Did Not Take</th><th>Actions</th></tr></thead><tbody>';

    grades.forEach(g => {
        const total = g.examinees ?? PL_KEYS.reduce((s, pk) => s + (g.levels[pk] || 0), 0);
        html += `<tr><td class="text-left">Grade ${g.grade_level}</td>`;
        PL_KEYS.forEach(pk => {
            const cnt = g.levels[pk] || 0;
            const pct = total > 0 ? (cnt / total * 100).toFixed(1) + '%' : '—';
            html += `<td>${cnt || 0}${cnt ? ` <span class="text-muted">(${pct})</span>` : ''}</td>`;
        });
        const enr = g.enrollment;
        html += `<td class="total-col">${total}</td>`;
        html += `<td>${enr ? enr.annual_total : '<span class="text-muted">—</span>'}</td>`;
        html += `<td>${enr ? enr.monthly_total : '<span class="text-muted">—</span>'}</td>`;
        html += `<td>${g.did_not_take !== null && g.did_not_take !== undefined ? g.did_not_take : '<span class="text-muted">—</span>'}</td>`;
        html += `<td><button type="button" class="btn btn-sm btn-outline-secondary" onclick="openEnrollmentModal(${g.grade_level})">Edit Enrollment</button></td></tr>`;
    });
    html += '</tbody>';
    tbl.innerHTML = html;
}

// ---- Enrollment entry (feeds "Did Not Take the Test" above) ----
async function openEnrollmentModal(grade) {
    const syId   = document.getElementById('f_sy')?.value;
    const termId = document.getElementById('f_term')?.value;
    if (!syId || !termId) {
        showToast('Select a specific School Year and Term first.', 'error');
        return;
    }

    document.getElementById('enrSyId').value   = syId;
    document.getElementById('enrGrade').value  = grade;
    document.getElementById('enrTermId').value = termId;
    document.getElementById('enrModalGrade').textContent = grade;

    const termSel   = document.getElementById('f_term');
    const termLabel = termSel?.options[termSel.selectedIndex]?.textContent || '';
    document.getElementById('enrModalTermLabel').textContent = termLabel;

    const data = await apiGet(`api/get_enrollment.php?sy=${syId}&grade=${grade}&term=${termId}`);
    if (data.error) { showToast(data.error, 'error'); return; }

    document.getElementById('enrAnnualMale').value    = data.annual_male;
    document.getElementById('enrAnnualFemale').value  = data.annual_female;
    document.getElementById('enrMonthlyMale').value   = data.monthly_male;
    document.getElementById('enrMonthlyFemale').value = data.monthly_female;

    document.getElementById('enrollmentModal').style.display = 'flex';
}

function closeEnrollmentModal() {
    document.getElementById('enrollmentModal').style.display = 'none';
}

const frmEnrollment = document.getElementById('frmEnrollment');
if (frmEnrollment) {
    frmEnrollment.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(frmEnrollment);
        const r = await apiPost('api/save_enrollment.php', {
            school_year_id: fd.get('school_year_id'),
            grade_level:    fd.get('grade_level'),
            term_id:        fd.get('term_id'),
            annual_male:    fd.get('annual_male'),
            annual_female:  fd.get('annual_female'),
            monthly_male:   fd.get('monthly_male'),
            monthly_female: fd.get('monthly_female'),
        });
        if (r.error) { showToast(r.error, 'error'); return; }
        showToast('Enrollment saved.');
        closeEnrollmentModal();
        refreshDashboard();
    });
}

function buildLeastMasteredChart(data) {
    const d = (data.least_mastered_items || []).slice(0, 20);
    return {
        type: 'bar',
        data: {
            labels: d.map(r => 'Item ' + r.item_no),
            datasets: [{ label: '% Correct', data: d.map(r => +r.pct), backgroundColor: d.map(r => masteryColor(r.pct)) }],
        },
        options: {
            maintainAspectRatio: false,
            indexAxis: 'y',
            scales: { x: { min:0, max:100, ticks: { callback: v => v + '%' } } },
            plugins: { legend: { display:false } },
        },
    };
}

function buildMpsTrendChart(data) {
    const d = data.mps_trend || [];
    return {
        type: 'line',
        data: {
            labels: d.map(r => r.title + ' (' + (r.date_given || '—') + ')'),
            datasets: [{
                label: 'Overall MPS %',
                data:  d.map(r => +r.mps),
                borderColor: THEME.maroon, backgroundColor: '#dbeafe', fill: false, tension: 0.3,
            }],
        },
        options: {
            maintainAspectRatio: false,
            scales: { y: { min:0, max:100 } },
        },
    };
}

function renderHeatmap(heatmap) {
    const tbl = document.getElementById('heatmapTable');
    if (!tbl || !heatmap) return;
    tbl.innerHTML = '';
    const { sections, items, data } = heatmap;
    if (!sections || !sections.length) { tbl.innerHTML = '<tr><td>No data</td></tr>'; return; }

    const thead = tbl.createTHead();
    const hr = thead.insertRow();
    addTH(hr, 'Item');
    sections.forEach(s => addTH(hr, s.split(' ').pop()));

    const tbody = tbl.createTBody();
    (items || []).forEach((item, i) => {
        const tr = tbody.insertRow();
        const td = tr.insertCell(); td.textContent = 'Item ' + item; td.className = 'row-label';
        (sections || []).forEach((_, j) => {
            const pct = data?.[i]?.[j] ?? 0;
            const cell = tr.insertCell();
            cell.textContent = fmtNum(pct, 1) + '%';
            cell.className = pctColor(pct);
        });
    });
}

// ---- SUBMISSIONS ----
const ASMT_TYPE_LABELS = { summative: 'Summative Test', term_exam: 'Term Exam', periodic: 'Periodic Test' };

async function loadSubmissions() {
    const data = await apiGet('api/get_submissions.php');
    if (!data || data.error) return;
    const submissionRows = data.submissions || [];
    const submissionCounts = submissionRows.reduce((counts, row) => {
        counts[row.status] = (counts[row.status] || 0) + 1;
        return counts;
    }, {});
    const setMetric = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = String(value); };
    setMetric('submissionMetricTotal', submissionRows.length);
    setMetric('submissionMetricApproved', submissionCounts.approved || 0);
    setMetric('submissionMetricReview', submissionCounts.submitted || 0);
    setMetric('submissionMetricAttention', (submissionCounts.draft || 0) + (submissionCounts.returned || 0));
    const tbody = document.getElementById('complianceTbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    submissionRows.forEach(row => {
        const tr = tbody.insertRow();
        tr.dataset.status = row.status;
        tr.dataset.term   = row.term_no;
        tr.dataset.type   = row.type;
        tr.innerHTML = `
            <td class="text-left">${escHtml(row.teacher_name)}</td>
            <td class="text-left">${escHtml(row.title)}</td>
            <td>${escHtml(row.subject_name)}</td>
            <td>Term ${row.term_no}</td>
            <td>${escHtml(ASMT_TYPE_LABELS[row.type] || row.type)}</td>
            <td><span class="status-chip badge status-${row.status}">${row.status}</span></td>
            <td>
                ${row.status === 'submitted' ? `
                <button class="btn btn-sm btn-success" onclick="approveAssessment(${row.id},${row.teacher_id ?? 'null'})">Approve</button>
                <button class="btn btn-sm btn-warning" onclick="openReturnModal(${row.id},${row.teacher_id ?? 'null'})">Return</button>
                ` : ''}
                <button class="btn btn-sm btn-danger"
                        data-aid="${row.id}"
                        data-title="${escHtml(row.title)}"
                        data-teacher="${escHtml(row.teacher_name)}"
                        data-shared="${row.is_shared}"
                        data-teacher-id="${row.teacher_id ?? ''}"
                        onclick="adminDeleteAssessment(this)">Delete</button>
            </td>`;
    });
    populateComplianceFacetFilters(data.submissions || []);
    filterComplianceTable();
}

// Rebuilds the Term/Type dropdown options from whatever's actually in the
// loaded submissions (rather than a hardcoded list), preserving the
// admin's current picks across a reload where possible.
function populateComplianceFacetFilters(submissions) {
    const termSel = document.getElementById('subCompTermFilter');
    const typeSel = document.getElementById('subCompTypeFilter');
    if (!termSel || !typeSel) return;

    const prevTerm = termSel.value;
    const prevType = typeSel.value;

    const terms = [...new Set(submissions.map(r => r.term_no))].sort((a, b) => a - b);
    termSel.innerHTML = '<option value="">All Terms</option>'
        + terms.map(t => `<option value="${t}">Term ${t}</option>`).join('');
    if (terms.map(String).includes(prevTerm)) termSel.value = prevTerm;

    const types = [...new Set(submissions.map(r => r.type))];
    typeSel.innerHTML = '<option value="">All Types</option>'
        + types.map(t => `<option value="${escHtml(t)}">${escHtml(ASMT_TYPE_LABELS[t] || t)}</option>`).join('');
    if (types.includes(prevType)) typeSel.value = prevType;
}

// ---- Submission Compliance: search + status/term/type filters ----
function filterComplianceTable() {
    const q      = (document.getElementById('subCompSearch')?.value || '').toLowerCase().trim();
    const status = document.getElementById('subCompStatusFilter')?.value || '';
    const term   = document.getElementById('subCompTermFilter')?.value || '';
    const type   = document.getElementById('subCompTypeFilter')?.value || '';
    const rows   = document.querySelectorAll('#complianceTbody tr');
    let shown = 0;

    rows.forEach(tr => {
        const matchesText   = !q || tr.textContent.toLowerCase().includes(q);
        const matchesStatus = !status || tr.dataset.status === status;
        const matchesTerm   = !term   || tr.dataset.term === term;
        const matchesType   = !type   || tr.dataset.type === type;
        const visible = matchesText && matchesStatus && matchesTerm && matchesType;
        tr.style.display = visible ? '' : 'none';
        if (visible) shown++;
    });

    const countEl = document.getElementById('subCompCount');
    if (countEl) countEl.textContent = rows.length ? `${shown} of ${rows.length} shown` : '';
    window.MpsTablePagination?.refresh();
}

async function approveAssessment(id, teacherId) {
    if (!confirm('Approve this assessment?')) return;
    const r = await apiPost('api/approve_assessment.php', { assessment_id: id, teacher_id: teacherId, action: 'approve' });
    if (r.error) { showToast(r.error,'error'); return; }
    showToast('Assessment approved.');
    loadSubmissions();
}

async function adminDeleteAssessment(btn) {
    const id        = +btn.dataset.aid;
    const title     = btn.dataset.title;
    const teacher   = btn.dataset.teacher;
    const isShared  = btn.dataset.shared === '1';
    const teacherId = btn.dataset.teacherId ? +btn.dataset.teacherId : null;

    const msg = isShared
        ? `Delete ${teacher}'s submission for "${title}"?\n\nThis only removes their sections' MPS and Item Analysis data. The assessment stays available for other teachers.`
        : `Delete "${title}" by ${teacher}?\n\nThis permanently removes all its MPS and Item Analysis data and cannot be undone.`;
    if (!confirm(msg)) return;

    const r = await apiPost('api/admin_delete_encoding.php', { assessment_id: id, teacher_id: teacherId });
    if (r.error) { showToast(r.error, 'error'); return; }
    btn.closest('tr')?.remove();
    showToast(r.whole_assessment_deleted ? 'Assessment deleted.' : 'Submission deleted.');
    filterComplianceTable();
    refreshDashboard();
}

function openReturnModal(id, teacherId) {
    document.getElementById('returnAsmtId').value = id;
    document.getElementById('returnAsmtTeacherId').value = teacherId ?? '';
    document.getElementById('returnModal').style.display = 'flex';
}

function closeReturnModal() {
    document.getElementById('returnModal').style.display = 'none';
}

const frmReturn = document.getElementById('frmReturn');
if (frmReturn) {
    frmReturn.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(frmReturn);
        const r  = await apiPost('api/approve_assessment.php', {
            assessment_id: fd.get('assessment_id'),
            teacher_id: fd.get('teacher_id') || null,
            action: 'return',
            remarks: fd.get('remarks'),
        });
        if (r.error) { showToast(r.error,'error'); return; }
        showToast('Assessment returned with remarks.');
        closeReturnModal();
        loadSubmissions();
    });
}

// ---- TEACHER ACCOUNTS ----
async function loadTeachers() {
    const data = await apiGet('api/get_teachers.php');
    if (!data) return;
    const allTeachers = [...(data.pending || []), ...(data.active || [])];
    const subjectCount = new Set(allTeachers.flatMap(teacher =>
        String(teacher.subjects || '').split(',').map(subject => subject.trim()).filter(subject => subject && subject !== '—')
    )).size;
    const setMetric = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = String(value); };
    setMetric('teacherMetricActive', (data.active || []).length);
    setMetric('teacherMetricPending', (data.pending || []).length);
    setMetric('teacherMetricResets', (data.pending_resets || []).length);
    setMetric('teacherMetricSubjects', subjectCount);
    renderTeacherTable('pendingTbody', data.pending, true);
    renderTeacherTable('activeTbody',  data.active,  false);
    renderPendingResets(data.pending_resets || []);
}

function renderTeacherTable(tbodyId, rows, isPending) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;
    tbody.innerHTML = '';
    if (!rows || !rows.length) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted">None</td></tr>`;
        return;
    }
    rows.forEach(r => {
        const tr = tbody.insertRow();
        const pwdBadge = (!isPending && r.must_change_password)
            ? ` <span class="badge-shared" title="Temporary password set — awaiting teacher change" style="background:#d97706;color:#fff;font-size:.7rem">pwd reset</span>`
            : '';
        tr.innerHTML = `
            <td class="text-left">${escHtml(r.display_name)}${pwdBadge}</td>
            <td><code>${escHtml(r.username)}</code></td>
            <td>${escHtml(r.grade_levels)}</td>
            <td class="text-left">${escHtml(r.subjects)}</td>
            ${isPending ? `<td>${escHtml(r.created_at || '')}</td>` : ''}
            <td style="white-space:nowrap">
                <button class="btn btn-sm btn-outline-primary" onclick="openTeacherProfile(${r.id})" style="margin-right:.3rem">Profile</button>
                <button class="btn btn-sm btn-outline-secondary" onclick="openEditTeacherModal(${r.id})" style="margin-right:.3rem">Edit</button>
                ${isPending
                  ? `<button class="btn btn-sm btn-success" onclick="manageTeacher(${r.id},'approve')">Approve</button>`
                  : `<button class="btn btn-sm btn-outline-secondary" onclick="openTeacherAssignments(${r.id})" style="margin-right:.3rem">Sections</button>
                     <button class="btn btn-sm btn-outline-secondary"
                         data-tid="${r.id}" data-tname="${escHtml(r.display_name)}" data-tusr="${escHtml(r.username)}"
                         onclick="openResetModal(+this.dataset.tid,this.dataset.tname,this.dataset.tusr)"
                         style="margin-right:.3rem">Reset Pwd</button>
                     <button class="btn btn-sm btn-danger"  onclick="manageTeacher(${r.id},'deactivate')">Deactivate</button>`}
            </td>`;
    });
}

function filterTeacherTable() {
    const q = (document.getElementById('teacherSearch')?.value || '').toLowerCase();
    document.querySelectorAll('#activeTbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
    window.MpsTablePagination?.refresh();
}

// ---- Reset Password Modal ----
let _resetTargetId = null;

function openResetModal(id, name, username) {
    _resetTargetId = id;
    document.getElementById('resetTeacherName').textContent     = name;
    document.getElementById('resetTeacherUsername').textContent = username;
    document.getElementById('resetConfirmState').style.display  = '';
    document.getElementById('resetResultState').style.display   = 'none';
    document.getElementById('btnDoReset').disabled              = false;
    document.getElementById('btnDoReset').textContent           = 'Generate Password';
    document.getElementById('resetPwdModal').style.display      = 'flex';
}

function closeResetModal() {
    document.getElementById('resetPwdModal').style.display = 'none';
    _resetTargetId = null;
}

async function confirmReset() {
    if (!_resetTargetId) return;
    const btn = document.getElementById('btnDoReset');
    btn.disabled    = true;
    btn.textContent = 'Resetting…';
    let r;
    try {
        r = await apiPost('api/admin_reset_password.php', { user_id: _resetTargetId });
    } catch (err) {
        btn.disabled    = false;
        btn.textContent = 'Reset Password';
        showToast('Network error — could not reach the server.', 'error');
        return;
    }
    btn.disabled    = false;
    btn.textContent = 'Reset Password';
    if (r?.error) { showToast(r.error, 'error'); return; }
    document.getElementById('resetResultName').textContent      = r.teacher_name;
    document.getElementById('resetResultUsername').textContent  = r.username;
    document.getElementById('resetTempPwd').textContent         = r.temp_password;
    document.getElementById('resetCopyMsg').style.display       = 'none';
    document.getElementById('resetConfirmState').style.display  = 'none';
    document.getElementById('resetResultState').style.display   = '';
    loadTeachers();
}

function copyTempPwd() {
    const pwd = document.getElementById('resetTempPwd')?.textContent || '';
    if (!pwd) return;
    navigator.clipboard.writeText(pwd).then(() => {
        const msg = document.getElementById('resetCopyMsg');
        if (msg) { msg.style.display = ''; setTimeout(() => msg.style.display = 'none', 2500); }
    });
}

function renderPendingResets(rows) {
    const card  = document.getElementById('pendingResetCard');
    const tbody = document.getElementById('pendingResetTbody');
    if (!card || !tbody) return;
    if (!rows.length) { card.style.display = 'none'; return; }
    card.style.display = '';
    tbody.innerHTML = rows.map(r => `
        <tr>
            <td class="text-left">
                <strong>${escHtml(r.display_name)}</strong><br>
                <small class="text-muted">${escHtml(r.username_submitted)}</small>
            </td>
            <td>${escHtml(r.requested_at || '')}</td>
            <td>
                ${r.user_id
                    ? `<button class="btn btn-sm btn-outline-secondary"
                           data-tid="${r.user_id}" data-tname="${escHtml(r.display_name)}" data-tusr="${escHtml(r.username_submitted)}"
                           onclick="openResetModal(+this.dataset.tid,this.dataset.tname,this.dataset.tusr)">Reset Now</button>`
                    : `<span class="text-muted" style="font-size:.875rem">Username not found</span>`}
            </td>
        </tr>`).join('');
}

async function manageTeacher(id, action) {
    if (!confirm(`${action === 'approve' ? 'Approve' : 'Deactivate'} this teacher?`)) return;
    const r = await apiPost('api/manage_teacher.php', { teacher_id: id, action });
    if (r.error) { showToast(r.error,'error'); return; }
    showToast(action === 'approve' ? 'Teacher approved.' : 'Teacher deactivated.');
    loadTeachers();
}

// ---- Edit Teacher (grade levels / subjects) ----
let _editTeacherId = null;
const _sectionEditorSelections = new Map();

function sectionEditorKey(prefix, yearId, subjectId) {
    return `${prefix}:${yearId}:${subjectId}`;
}

function rememberSectionEditorSelections(prefix) {
    const root = document.getElementById(prefix + 'SectionAssignments');
    if (!root) return;
    root.querySelectorAll('[data-section-assignment-group]').forEach(group => {
        const key = sectionEditorKey(prefix, group.dataset.yearId, group.dataset.subjectId);
        _sectionEditorSelections.set(key, [...group.querySelectorAll('input[type="checkbox"]:checked')].map(input => +input.value));
    });
}

function renderTeacherSectionAssignments(prefix, grades, subjects, yearId, initialAssignments = []) {
    rememberSectionEditorSelections(prefix);
    const root = document.getElementById(prefix + 'SectionAssignments');
    if (!root) return;
    const gradeSet = new Set(grades.map(Number));
    const subjectSet = new Set(subjects.map(subject => String(subject).trim().toLowerCase()));
    const combinations = SUBJECTS_ALL.filter(subject => gradeSet.has(+subject.grade_level)
        && subjectSet.has(String(subject.name).trim().toLowerCase()));
    if (!combinations.length) {
        root.innerHTML = '<span class="text-muted">Select at least one grade and subject to choose sections.</span>';
        return;
    }
    const year = +yearId;
    root.innerHTML = combinations.map(subject => {
        const sections = TEACHER_SECTION_CATALOG.filter(section => +section.school_year_id === year
            && +section.grade_level === +subject.grade_level);
        const key = sectionEditorKey(prefix, year, subject.id);
        const initialIds = initialAssignments.filter(item => +item.school_year_id === year && +item.subject_id === +subject.id)
            .map(item => +item.section_id);
        const selectedIds = _sectionEditorSelections.has(key) ? _sectionEditorSelections.get(key) : initialIds;
        const selected = new Set(selectedIds.map(Number));
        return `<section class="teacher-section-assignment" data-section-assignment-group data-subject-id="${subject.id}" data-year-id="${year}" style="margin:.35rem 0 .8rem">
            <strong>${escHtml(subject.name)} · Grade ${subject.grade_level}</strong>
            <div class="checklist checklist--grid" style="margin-top:.35rem">${sections.length
                ? sections.map(section => `<label class="check-item"><input type="checkbox" data-subject-id="${subject.id}" data-year-id="${year}" value="${section.id}"${selected.has(+section.id) ? ' checked' : ''}>${escHtml(section.name)}</label>`).join('')
                : '<span class="text-muted">No sections are configured for this grade and school year.</span>'}</div>
        </section>`;
    }).join('');
}

function readTeacherSectionAssignments(prefix) {
    const root = document.getElementById(prefix + 'SectionAssignments');
    if (!root) return [];
    return [...root.querySelectorAll('[data-section-assignment-group]')].map(group => ({
        subject_id: +group.dataset.subjectId,
        school_year_id: +group.dataset.yearId,
        section_ids: [...group.querySelectorAll('input[type="checkbox"]:checked')].map(input => +input.value),
    }));
}

function selectedValues(selector) {
    return [...document.querySelectorAll(selector + ':checked')].map(input => input.value);
}

function renderEditTeacherSectionAssignments() {
    if (!_editTeacherId) return;
    renderTeacherSectionAssignments('editTeacher',
        selectedValues('input[name="edit_grade_levels"]').map(Number),
        selectedValues('input[name="edit_subjects"]'),
        document.getElementById('editTeacherAssignmentYear').value,
        window._editTeacherSectionRows || []);
}

async function openEditTeacherModal(id) {
    const data = await apiGet(`api/get_teacher_profile.php?teacher_id=${id}`);
    if (data.error) { showToast(data.error, 'error'); return; }

    _editTeacherId = id;
    document.getElementById('editTeacherName').textContent     = data.display_name;
    document.getElementById('editTeacherUsername').textContent = data.username;

    const gradeWrap = document.getElementById('editGradeChecklist');
    gradeWrap.innerHTML = AVAILABLE_GRADE_LEVELS_LIST.map(gl => `
        <label class="check-item">
            <input type="checkbox" name="edit_grade_levels" value="${gl}" onchange="renderEditTeacherSectionAssignments()"${data.grade_levels.includes(gl) ? ' checked' : ''}>
            Grade ${gl}
        </label>`).join('');

    const subjWrap = document.getElementById('editSubjectChecklist');
    subjWrap.innerHTML = AVAILABLE_SUBJECTS_LIST.map(sn => `
        <label class="check-item">
            <input type="checkbox" name="edit_subjects" value="${escHtml(sn)}" onchange="renderEditTeacherSectionAssignments()"${data.subjects.includes(sn) ? ' checked' : ''}>
            ${escHtml(sn)}
        </label>`).join('');

    window._editTeacherSectionRows = data.section_assignments || [];
    _sectionEditorSelections.clear();
    document.getElementById('editTeacherAssignmentYear').value = String(ACTIVE_SCHOOL_YEAR_ID);
    renderEditTeacherSectionAssignments();
    document.getElementById('editTeacherModal').style.display = 'flex';
}

async function openTeacherProfile(id) {
    const data = await apiGet(`api/get_teacher_profile.php?teacher_id=${id}`);
    if (!data || data.error) { showToast(data?.error || 'Could not load teacher profile.', 'error'); return; }
    document.getElementById('teacherProfileName').textContent = data.display_name;
    document.getElementById('teacherProfileUsername').textContent = data.username;
    const sectionWrap = document.getElementById('teacherProfileSections');
    const assignments = data.section_assignments || [];
    if (!assignments.length) {
        sectionWrap.innerHTML = '<span class="text-muted">No sections assigned.</span>';
    } else {
        const byYear = new Map();
        assignments.forEach(item => {
            const key = item.school_year || 'School year';
            if (!byYear.has(key)) byYear.set(key, []);
            byYear.get(key).push(item);
        });
        sectionWrap.innerHTML = [...byYear.entries()].map(([year, items]) => `
            <div style="margin-bottom:.65rem"><strong>${escHtml(year)}</strong>
                <ul style="margin:.25rem 0 0;padding-left:1.25rem">${items.map(item => `<li>Grade ${Number(item.grade_level)} · ${escHtml(item.section_name)} <span class="text-muted">— ${escHtml(item.subject_name)}</span></li>`).join('')}</ul>
            </div>`).join('');
    }
    document.getElementById('teacherProfileModal').style.display = 'flex';
}

function closeTeacherProfileModal() {
    document.getElementById('teacherProfileModal').style.display = 'none';
}

let _assignmentTeacherId = null;
let _teacherAssignmentSubjects = [];

async function openTeacherAssignments(id) {
    _assignmentTeacherId = id;
    document.getElementById('teacherAssignmentsName').textContent = 'Loading teacher…';
    document.getElementById('teacherAssignmentsError').textContent = '';
    document.getElementById('teacherAssignmentChecklist').innerHTML = '<span class="text-muted">Loading subjects…</span>';
    document.getElementById('teacherAssignmentSubject').innerHTML = '<option value="">Loading…</option>';
    document.getElementById('teacherAssignmentsModal').style.display = 'flex';

    const profile = await apiGet(`api/get_teacher_profile.php?teacher_id=${id}`);
    if (profile.error) {
        document.getElementById('teacherAssignmentsName').textContent = '';
        document.getElementById('teacherAssignmentChecklist').innerHTML = `<span class="text-muted">${escHtml(profile.error)}</span>`;
        return;
    }
    document.getElementById('teacherAssignmentsName').textContent = profile.display_name;
    const data = await apiGet(`api/get_teacher_subjects.php?teacher_id=${id}`);
    if (data.error) {
        document.getElementById('teacherAssignmentSubject').innerHTML = '<option value="">No subjects</option>';
        document.getElementById('teacherAssignmentChecklist').innerHTML = `<span class="text-muted">${escHtml(data.error)}</span>`;
        return;
    }
    _teacherAssignmentSubjects = data.subjects || [];
    const subjectSelect = document.getElementById('teacherAssignmentSubject');
    subjectSelect.innerHTML = _teacherAssignmentSubjects.length
        ? _teacherAssignmentSubjects.map(subject => `<option value="${subject.id}">${escHtml(subject.name)} · Grade ${subject.grade_level}</option>`).join('')
        : '<option value="">No subjects configured for this teacher</option>';
    if (_teacherAssignmentSubjects.length) await loadTeacherAssignmentSections();
    else document.getElementById('teacherAssignmentChecklist').innerHTML = '<span class="text-muted">Add grade levels and subjects to this teacher profile first.</span>';
}

async function loadTeacherAssignmentSections() {
    const subjectId = +document.getElementById('teacherAssignmentSubject')?.value;
    const schoolYearId = +document.getElementById('teacherAssignmentYear')?.value;
    const checklist = document.getElementById('teacherAssignmentChecklist');
    if (!_assignmentTeacherId || !subjectId || !schoolYearId) {
        checklist.innerHTML = '<span class="text-muted">Choose a subject and school year.</span>';
        return;
    }
    checklist.innerHTML = '<span class="text-muted">Loading sections…</span>';
    const data = await apiGet(`api/get_assignment_sections.php?teacher_id=${_assignmentTeacherId}&subject_id=${subjectId}&school_year_id=${schoolYearId}`);
    if (data.error) {
        checklist.innerHTML = `<span class="text-muted">${escHtml(data.error)}</span>`;
        return;
    }
    const assigned = new Set(data.assigned || []);
    checklist.innerHTML = data.sections?.length
        ? data.sections.map(section => `<label class="check-item"><input type="checkbox" name="teacher_assigned_section" value="${section.id}"${assigned.has(+section.id) ? ' checked' : ''}>${escHtml(section.name)}</label>`).join('')
        : '<span class="text-muted">No sections are configured for this subject’s grade and school year.</span>';
}

function closeTeacherAssignments() {
    document.getElementById('teacherAssignmentsModal').style.display = 'none';
    _assignmentTeacherId = null;
}

async function saveTeacherAssignments() {
    if (!_assignmentTeacherId) return;
    const button = document.getElementById('btnSaveTeacherAssignments');
    const section_ids = [...document.querySelectorAll('input[name="teacher_assigned_section"]:checked')].map(cb => +cb.value);
    button.disabled = true;
    button.textContent = 'Saving…';
    const result = await apiPost('api/assign_sections.php', {
        teacher_id: _assignmentTeacherId,
        subject_id: +document.getElementById('teacherAssignmentSubject').value,
        school_year_id: +document.getElementById('teacherAssignmentYear').value,
        section_ids,
    });
    button.disabled = false;
    button.textContent = 'Save Section Mapping';
    if (result.error) {
        document.getElementById('teacherAssignmentsError').textContent = result.error;
        return;
    }
    showToast(`Saved ${result.assigned} section assignment${result.assigned === 1 ? '' : 's'}.`);
    closeTeacherAssignments();
}

function closeEditTeacherModal() {
    document.getElementById('editTeacherModal').style.display = 'none';
    _editTeacherId = null;
}

async function saveTeacherEdit() {
    if (!_editTeacherId) return;
    const grade_levels = [...document.querySelectorAll('input[name="edit_grade_levels"]:checked')].map(cb => +cb.value);
    const subjects      = [...document.querySelectorAll('input[name="edit_subjects"]:checked')].map(cb => cb.value);
    if (!grade_levels.length) { showToast('Select at least one grade level.', 'error'); return; }
    if (!subjects.length)     { showToast('Select at least one subject.', 'error'); return; }

    const btn = document.getElementById('btnSaveTeacherEdit');
    btn.disabled = true;
    btn.textContent = 'Saving…';
    const r = await apiPost('api/update_teacher_profile.php', {
        teacher_id: _editTeacherId, grade_levels, subjects,
        school_year_id: +document.getElementById('editTeacherAssignmentYear').value,
        section_assignments: readTeacherSectionAssignments('editTeacher'),
    });
    btn.disabled = false;
    btn.textContent = 'Save Changes';

    if (r.error) { showToast(r.error, 'error'); return; }
    showToast('Teacher updated.');
    closeEditTeacherModal();
    loadTeachers();
}

function adminTab(btn, panel) {
    document.querySelectorAll('.admin-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.admin-panel').forEach(p => p.style.display='none');
    document.getElementById('panel-'+panel).style.display = 'flex';
    const panelHeadings = {
        analytics: 'Executive Academic Overview',
        submissions: 'Submissions Compliance & Tracking',
        competencies: 'DepEd Learning Competencies (MELCs)',
        assessments: 'Assessments & Examination Repository',
        teachers: 'Teacher Accounts & Faculty Roster',
        settings: 'System Configuration & Master Data',
    };
    const heading = panelHeadings[panel];
    if (heading) {
        const title = document.getElementById('mpsPageTitle');
        if (title) title.textContent = heading;
    }
    const createAssessmentButton = document.getElementById('headerCreateAssessment');
    if (createAssessmentButton) createAssessmentButton.style.display = panel === 'assessments' ? '' : 'none';
    if (panel === 'submissions') loadSubmissions();
    if (panel === 'teachers')    loadTeachers();
    if (panel === 'assessments') loadAdminAssessments();
}

function openCreateTeacherModal() {
    document.getElementById('createTeacherError').textContent = '';
    ['newTeacherLast','newTeacherFirst','newTeacherMiddle','newTeacherUsername','newTeacherPassword'].forEach(id => {
        document.getElementById(id).value = '';
    });
    document.querySelectorAll('input[name="new_teacher_grade"], input[name="new_teacher_subject"]').forEach(input => input.checked = false);
    document.getElementById('newTeacherAssignmentYear').value = String(ACTIVE_SCHOOL_YEAR_ID);
    _sectionEditorSelections.clear();
    renderCreateTeacherSectionAssignments();
    document.getElementById('createTeacherModal').style.display = '';
}
function closeCreateTeacherModal() {
    document.getElementById('createTeacherModal').style.display = 'none';
}
function renderCreateTeacherSectionAssignments() {
    renderTeacherSectionAssignments('newTeacher',
        selectedValues('input[name="new_teacher_grade"]').map(Number),
        selectedValues('input[name="new_teacher_subject"]'),
        document.getElementById('newTeacherAssignmentYear').value);
}
async function createTeacherAccount() {
    const error = document.getElementById('createTeacherError');
    const button = document.getElementById('btnCreateTeacher');
    const payload = {
        last_name: document.getElementById('newTeacherLast').value.trim(),
        first_name: document.getElementById('newTeacherFirst').value.trim(),
        middle_name: document.getElementById('newTeacherMiddle').value.trim(),
        username: document.getElementById('newTeacherUsername').value.trim(),
        password: document.getElementById('newTeacherPassword').value,
        grade_levels: [...document.querySelectorAll('input[name="new_teacher_grade"]:checked')].map(input => +input.value),
        subjects: [...document.querySelectorAll('input[name="new_teacher_subject"]:checked')].map(input => input.value),
        section_assignments: readTeacherSectionAssignments('newTeacher'),
    };
    error.textContent = '';
    button.disabled = true;
    button.textContent = 'Creating…';
    const result = await apiPost('api/admin_create_teacher.php', payload);
    button.disabled = false;
    button.textContent = 'Create Account';
    if (result.error) { error.textContent = result.error; return; }
    closeCreateTeacherModal();
    showToast('Teacher account created. The teacher must change the temporary password at first sign-in.');
    loadTeachers();
}

function escHtml(s) {
    if (!s) return '';
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ---- FILTER DEPENDENCY: populate section + assessment dropdowns ----
async function updateFilterDependents() {
    const sy      = document.getElementById('f_sy')?.value      || '';
    const grade   = document.getElementById('f_grade')?.value   || '';
    const subject = document.getElementById('f_subject')?.value || '';
    const params  = new URLSearchParams({ sy, grade, subject });
    const data    = await apiGet(`api/get_filter_options.php?${params}`);
    if (!data) return;

    const secSel  = document.getElementById('f_section');
    const asmtSel = document.getElementById('f_assessment');
    if (secSel) {
        const cur = secSel.value;
        secSel.innerHTML = '<option value="">All Sections</option>';
        (data.sections || []).forEach(s => {
            const o = new Option(s.name, s.id);
            if (String(s.id) === String(cur)) o.selected = true;
            secSel.appendChild(o);
        });
    }
    if (asmtSel) {
        const cur = asmtSel.value;
        asmtSel.innerHTML = '<option value="">All Assessments</option>';
        (data.assessments || []).forEach(a => {
            const o = new Option(a.title, a.id);
            if (String(a.id) === String(cur)) o.selected = true;
            asmtSel.appendChild(o);
        });
    }
}

// ============================================================
// AUTO-INIT
// ============================================================
function applyBootstrapFormClasses(root) {
    const controls = 'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="button"]), select, textarea';
    if (root instanceof Element && root.matches(controls)) root.classList.add(root.tagName === 'SELECT' ? 'form-select' : 'form-control');
    if (root instanceof Element && root.matches('input[type="checkbox"], input[type="radio"]')) root.classList.add('form-check-input');
    root.querySelectorAll?.(controls).forEach(el => el.classList.add(el.tagName === 'SELECT' ? 'form-select' : 'form-control'));
    root.querySelectorAll?.('input[type="checkbox"], input[type="radio"]').forEach(el => el.classList.add('form-check-input'));
}

document.addEventListener('DOMContentLoaded', () => {
    applyBootstrapFormClasses(document);
    const bootstrapControlsObserver = new MutationObserver(records => {
        records.forEach(record => record.addedNodes.forEach(node => {
            if (node.nodeType === Node.ELEMENT_NODE) applyBootstrapFormClasses(node);
        }));
    });
    bootstrapControlsObserver.observe(document.body, { childList: true, subtree: true });

    if (document.getElementById('panel-analytics')) {
        // f_sy / f_grade / f_subject have NO inline onchange — handled here so that
        // updateFilterDependents() always runs before refreshDashboard(), keeping the
        // Section/Assessment dropdowns in sync before the API call fires.
        ['f_sy', 'f_grade', 'f_subject'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', async () => {
                await updateFilterDependents();
                refreshDashboard(); // no-ops and shows empty state if no subject selected
            });
        });
        // On initial load: populate dependent dropdowns, then show empty state (no auto-fetch).
        updateFilterDependents().then(() => showDashboardEmptyState());
    }

});

// ============================================================
// TEACHER: START OR CONTINUE SHARED ASSESSMENT
// ============================================================

async function startSharedAssessment(id, subjectId, button) {
    const errorEl = button?.closest('td')?.querySelector('.assessment-start-error');
    if (button) {
        button.disabled = true;
        button.dataset.actionTitle = button.title;
        button.title = 'Starting…';
        button.setAttribute('aria-label', 'Starting…');
    }
    if (errorEl) errorEl.textContent = '';
    try {
        const data = await apiGet(`api/get_sections.php?subject_id=${subjectId}&assessment_id=${id}`);
        if (data.error) throw new Error(data.error);
        if (!data.sections?.length) {
            throw new Error('No sections are assigned to you for this subject and school year. Ask your administrator to assign your sections.');
        }

        // Reuse this assessment's existing section set when continuing. For a
        // first start, get_sections returns the teacher's assigned sections as
        // the default checked set.
        const available = new Set(data.sections.map(section => Number(section.id)));
        let sectionIds = (data.checked || []).map(Number).filter(sectionId => available.has(sectionId));
        if (!sectionIds.length) sectionIds = [...available];

        const result = await apiPost('api/start_encoding.php', {
            assessment_id: Number(id),
            section_ids: sectionIds,
            confirm_remove_with_data: false,
        });
        if (result.error) throw new Error(result.error);

        const status = document.getElementById(`assessmentStatus${id}`);
        if (status) {
            status.textContent = 'Draft';
            status.className = 'status-chip badge status-draft';
        }
        const row = button?.closest('tr');
        row?.querySelectorAll('.teacher-assessment-open').forEach(link => { link.onclick = () => loadAssessment(Number(id)); });
        const rowAction = row?.querySelector('.assessment-row-action');
        if (rowAction) {
            rowAction.disabled = false;
            rowAction.title = 'Open Assessment';
            rowAction.setAttribute('aria-label', 'Open Assessment');
            rowAction.dataset.actionTitle = 'Open Assessment';
            const icon = rowAction.querySelector('i');
            if (icon) icon.className = 'bi bi-box-arrow-up-right';
            rowAction.classList.remove('assessment-row-action');
            rowAction.onclick = () => loadAssessment(Number(id));
        }
        await loadAssessment(Number(id));
    } catch (error) {
        const message = error instanceof Error ? error.message : 'Unable to start this assessment.';
        if (errorEl) errorEl.textContent = message;
        else showToast(message, 'error');
    } finally {
        if (button) {
            button.disabled = false;
            if (button.dataset.actionTitle) {
                button.title = button.dataset.actionTitle;
                button.setAttribute('aria-label', button.dataset.actionTitle);
            }
        }
    }
}

// ============================================================
// ADMIN: LEARNING COMPETENCIES MANAGEMENT
// ============================================================

async function loadCompetencies() {
    const subjectId = document.getElementById('compSubjectFilter')?.value;
    const termId    = document.getElementById('compTermFilter')?.value;
    const wrap      = document.getElementById('compTableWrap');
    const emptyMsg  = document.getElementById('compEmptyMsg');
    const tbody     = document.getElementById('compTbody');
    const setMetric = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value; };
    const subjectOption = document.getElementById('compSubjectFilter')?.selectedOptions?.[0];
    const termOption = document.getElementById('compTermFilter')?.selectedOptions?.[0];
    setMetric('competencyMetricSubject', subjectOption?.value ? subjectOption.textContent.trim() : 'Select subject');
    setMetric('competencyMetricTerm', termId ? (termOption?.textContent.trim() || 'Selected term') : 'All terms');

    if (!subjectId) {
        setMetric('competencyMetricCount', '—');
        wrap.style.display = 'none';
        emptyMsg.style.display = '';
        emptyMsg.textContent = 'Select a subject above to view or add competencies.';
        return;
    }

    wrap.style.display = 'none';
    emptyMsg.style.display = '';
    emptyMsg.textContent = 'Loading…';

    const params = new URLSearchParams({ subject_id: subjectId });
    if (termId) params.set('term_id', termId);
    const data = await apiGet(`api/get_competencies.php?${params}`);
    if (data.error) { emptyMsg.textContent = data.error; return; }

    const rows = data.competencies || [];
    setMetric('competencyMetricCount', String(rows.length));
    if (!rows.length) {
        emptyMsg.style.display = '';
        emptyMsg.textContent   = 'No competencies found for this filter. Add one below.';
        wrap.style.display = 'block';
        tbody.innerHTML = '<tr><td colspan="3" class="text-muted" style="text-align:center">No competencies yet.</td></tr>';
        updateCompAddState();
        return;
    }

    emptyMsg.style.display = 'none';
    wrap.style.display = 'block';
    tbody.innerHTML = rows.map(c => `
        <tr id="compRow${c.id}">
            <td><span id="ccode${c.id}">${escHtml(c.code || '—')}</span></td>
            <td><span id="cdesc${c.id}">${escHtml(c.description)}</span></td>
            <td style="text-align:right">
                <button class="btn btn-sm btn-outline-secondary" onclick="adminEditCompetency(${c.id},'${escHtml(c.code || '')}','${escHtml(c.description).replace(/'/g,"\\'")}')">Edit</button>
                <button class="btn btn-sm btn-danger"  onclick="adminDeleteCompetency(${c.id})">Del</button>
            </td>
        </tr>`).join('');
    updateCompAddState();
}

function updateCompAddState() {
    const subjectId = document.getElementById('compSubjectFilter')?.value;
    const termId    = document.getElementById('compTermFilter')?.value;
    const addRow    = document.getElementById('compAddRow');
    const addHint   = document.getElementById('compAddHint');
    const csvBtn    = document.getElementById('btnCompCsv');

    const canAdd = !!(subjectId && termId);

    if (addRow)  addRow.style.display  = canAdd ? '' : 'none';
    if (addHint) addHint.style.display = (subjectId && !termId) ? '' : 'none';
    if (csvBtn) {
        csvBtn.disabled = !canAdd;
        csvBtn.title    = canAdd ? '' : 'Select a subject and a specific term first';
    }
}

async function adminAddCompetency() {
    const subjectId = document.getElementById('compSubjectFilter')?.value;
    const termId    = document.getElementById('compTermFilter')?.value;
    const code      = document.getElementById('newCompCode').value.trim();
    const desc      = document.getElementById('newCompDesc').value.trim();
    if (!subjectId) { showToast('Select a subject first.', 'error'); return; }
    if (!termId)    { showToast('Select a specific Term first.', 'error'); return; }
    if (!desc)      { showToast('Description is required.', 'error'); return; }

    const r = await apiPost('api/save_competency.php', {
        action: 'insert', subject_id: +subjectId,
        term_id: +termId, code, description: desc,
    });
    if (r.error) { showToast(r.error, 'error'); return; }
    document.getElementById('newCompCode').value = '';
    document.getElementById('newCompDesc').value = '';
    showToast('Competency added.');
    loadCompetencies();
}

function adminEditCompetency(id, code, desc) {
    const newCode = prompt('Code (optional):', code === '—' ? '' : code);
    if (newCode === null) return;
    const newDesc = prompt('Description:', desc);
    if (!newDesc?.trim()) return;

    const subjectId = document.getElementById('compSubjectFilter')?.value;
    const termId    = document.getElementById('compTermFilter')?.value;
    apiPost('api/save_competency.php', {
        action: 'update', id, subject_id: +subjectId,
        term_id: termId ? +termId : null,
        code: newCode.trim(), description: newDesc.trim(),
    }).then(r => {
        if (r.error) { showToast(r.error, 'error'); return; }
        showToast('Competency updated.');
        loadCompetencies();
    });
}

async function adminDeleteCompetency(id) {
    if (!confirm('Delete this competency? This cannot be undone if it is not mapped to any assessment.')) return;
    const r = await apiPost('api/save_competency.php', { action: 'delete', id });
    if (r.error) { showToast(r.error, 'error'); return; }
    showToast('Deleted.');
    loadCompetencies();
}

function openCompCsvModal() {
    const subjectId = document.getElementById('compSubjectFilter')?.value;
    const termId    = document.getElementById('compTermFilter')?.value;
    if (!subjectId) { showToast('Select a subject first.', 'error'); return; }
    if (!termId)    { showToast('Select a specific Term before importing — imported rows need a term to be saved to.', 'error'); return; }
    document.getElementById('compCsvText').value        = '';
    document.getElementById('compCsvResult').textContent = '';
    document.getElementById('compCsvModal').style.display = '';
}
function closeCompCsvModal() { document.getElementById('compCsvModal').style.display = 'none'; }

async function doCompCsvImport() {
    const subjectId = document.getElementById('compSubjectFilter')?.value;
    const termId    = document.getElementById('compTermFilter')?.value;
    const text      = document.getElementById('compCsvText').value.trim();
    const resultEl  = document.getElementById('compCsvResult');
    if (!text) { resultEl.textContent = 'Paste CSV text first.'; return; }

    const fd = new FormData();
    fd.set('csrf_token',  CSRF_TOKEN);
    fd.set('subject_id',  subjectId);
    if (termId) fd.set('term_id', termId);
    fd.set('csv_text', text);

    let data;
    try {
        const res = await fetch(BASE_URL + 'api/import_competencies_csv.php', { method: 'POST', body: fd });
        data = await res.json();
    } catch { resultEl.textContent = 'Network error.'; return; }

    if (data.error) { resultEl.style.color = 'var(--c-danger)'; resultEl.textContent = data.error; return; }
    resultEl.style.color = 'var(--c-success)';
    resultEl.textContent = `✓ Imported ${data.inserted} competencies.` +
        (data.parse_errors ? ` (${data.parse_errors} lines skipped)` : '');
    loadCompetencies();
}

// ============================================================
// ADMIN: CREATE ASSESSMENT MODAL (4-step)
// ============================================================

let _createStep       = 1;
let _createAsmtType   = '';
let _createCompetencies = [];  // competencies loaded for step 3
let _itemCompMap      = {};    // item_no → competency_id
let _duplicateItemCompMap = {};

function openCreateAsmtModal() {
    _createStep     = 1;
    _createAsmtType = '';
    _itemCompMap    = {};
    document.getElementById('createAsmtModal').style.display = '';
    renderCreateStep(1);
}
function closeCreateAsmtModal() { document.getElementById('createAsmtModal').style.display = 'none'; }

function renderCreateStep(n) {
    _createStep = n;
    [1,2,3,4].forEach(i => {
        document.getElementById('createStep' + i).style.display = i === n ? '' : 'none';
        const dot = document.getElementById('stepDot' + i);
        dot.className = 'step-dot' + (i < n ? ' done' : i === n ? ' active' : '');
    });
    document.getElementById('btnCreatePrev').style.display = n > 1 ? '' : 'none';
    document.getElementById('btnCreateNext').style.display = n < 4 ? '' : 'none';
    document.getElementById('btnCreateDone').style.display = n === 4 ? '' : 'none';
    document.getElementById('createAsmtErr').textContent  = '';

    if (n === 4) renderReviewStep();
}

function selectAsmtType(type, defaultItems) {
    _createAsmtType = type;
    document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('typeCard' + type.charAt(0).toUpperCase() + type.slice(1))?.classList.add('selected');
    document.getElementById('asmtTotalItems').value = defaultItems;
}

async function loadTermsForModal() {
    const syId  = document.getElementById('asmtSY')?.value;
    const sel   = document.getElementById('asmtTerm');
    sel.innerHTML = '<option value="">Loading…</option>';
    if (!syId) return;
    const data = await apiGet(`api/get_terms.php?sy_id=${syId}`);
    sel.innerHTML = '<option value="">— select —</option>' +
        (data.terms || []).map(t => `<option value="${t.id}">${escHtml(t.label || 'Term ' + t.term_no)}</option>`).join('');
}

async function createAsmtNext() {
    const errEl = document.getElementById('createAsmtErr');
    errEl.textContent = '';

    if (_createStep === 1) {
        if (!_createAsmtType) { errEl.textContent = 'Please select an assessment type.'; return; }
        const ti = +document.getElementById('asmtTotalItems').value;
        if (!ti || ti < 1 || ti > 200) { errEl.textContent = 'Total items must be between 1 and 200.'; return; }
        renderCreateStep(2);

    } else if (_createStep === 2) {
        const term    = document.getElementById('asmtTerm').value;
        const subject = document.getElementById('asmtSubject').value;
        const title   = document.getElementById('asmtTitle').value.trim();
        if (!term || !subject || !title) { errEl.textContent = 'School Year, Term, Subject, and Title are required.'; return; }
        renderCreateStep(3);
        await loadCompetenciesForMapping();

    } else if (_createStep === 3) {
        // Collect current mapping
        _itemCompMap = {};
        document.querySelectorAll('#compMappingGrid select[data-item]').forEach(sel => {
            if (sel.value) _itemCompMap[+sel.dataset.item] = +sel.value;
        });
        renderCreateStep(4);
    }
}

function createAsmtPrev() {
    if (_createStep > 1) renderCreateStep(_createStep - 1);
}

async function loadCompetenciesForMapping() {
    const subjectId = document.getElementById('asmtSubject').value;
    const termId    = document.getElementById('asmtTerm').value;
    const totalItems = +document.getElementById('asmtTotalItems').value;
    const grid      = document.getElementById('compMappingGrid');
    const bulkSel   = document.getElementById('bulkCompSel');

    grid.innerHTML = '<p class="text-muted">Loading competencies…</p>';
    bulkSel.innerHTML = '<option value="">— select competency —</option>';

    const params = new URLSearchParams({ subject_id: subjectId, term_id: termId });
    const data   = await apiGet(`api/get_competencies.php?${params}`);
    _createCompetencies = data.competencies || [];

    if (!_createCompetencies.length) {
        grid.innerHTML = `<div class="alert alert-warning">No competencies found for this subject and term.
            Add them in the <strong>Learning Competencies</strong> tab first, then come back.</div>`;
        bulkSel.innerHTML = '<option value="">No competencies available</option>';
        return;
    }

    // Populate bulk select
    _createCompetencies.forEach(c => {
        const o = new Option((c.code ? c.code + ' — ' : '') + c.description.substring(0,60), c.id);
        bulkSel.appendChild(o);
    });
    document.getElementById('bulkTo').value = totalItems;

    // Render per-item grid
    const optionsHtml = `<option value="">— unassigned —</option>` +
        _createCompetencies.map(c =>
            `<option value="${c.id}">${escHtml((c.code ? c.code + ' — ' : '') + c.description.substring(0,60))}</option>`
        ).join('');

    let rows = '';
    for (let i = 1; i <= totalItems; i++) {
        const savedComp = _itemCompMap[i] || '';
        rows += `<tr><td>${i}</td><td>
            <select data-item="${i}" onchange="updateItemCompSummary()" style="width:100%">
                ${optionsHtml.replace(`value="${savedComp}"`, `value="${savedComp}" selected`)}
            </select></td></tr>`;
    }
    grid.innerHTML = `<table class="comp-map-table">
        <thead><tr><th>#</th><th>Learning Competency</th></tr></thead>
        <tbody>${rows}</tbody></table>`;

    updateItemCompSummary();
}

function applyBulkAssign() {
    const compId = document.getElementById('bulkCompSel').value;
    const from   = +document.getElementById('bulkFrom').value;
    const to     = +document.getElementById('bulkTo').value;
    if (!compId || !from || !to || from > to) {
        showToast('Select a competency and a valid item range.', 'error'); return;
    }
    document.querySelectorAll('#compMappingGrid select[data-item]').forEach(sel => {
        const item = +sel.dataset.item;
        if (item >= from && item <= to) sel.value = compId;
    });
    updateItemCompSummary();
}

function updateItemCompSummary() {
    const summary  = document.getElementById('compMappingSummary');
    const counts   = {};  // competency_id → {label, items[]}
    let unassigned = 0;
    const totalItems = +document.getElementById('asmtTotalItems').value;

    document.querySelectorAll('#compMappingGrid select[data-item]').forEach(sel => {
        const item = +sel.dataset.item;
        const cid  = sel.value;
        if (!cid) { unassigned++; return; }
        if (!counts[cid]) {
            const comp = _createCompetencies.find(c => String(c.id) === cid);
            counts[cid] = { label: comp ? (comp.code || comp.description.substring(0,40)) : cid, items: [] };
        }
        counts[cid].items.push(item);
    });

    const rows = Object.values(counts).map(c =>
        `<div class="comp-summary-row">
            <span class="comp-summary-badge">${escHtml(c.label)}</span>
            <span class="text-muted">items ${c.items.join(',')} <em>(${c.items.length})</em></span>
        </div>`
    ).join('');

    const warnHtml = unassigned > 0
        ? `<div class="comp-unassigned-warn">⚠ ${unassigned} of ${totalItems} item${unassigned > 1 ? 's' : ''} unassigned</div>`
        : `<div style="color:var(--c-success);font-size:.8rem">✓ All items mapped</div>`;

    summary.innerHTML = rows + warnHtml;
}

function renderReviewStep() {
    const type       = _createAsmtType;
    const totalItems = document.getElementById('asmtTotalItems').value;
    const subject    = document.getElementById('asmtSubject');
    const subjectTxt = subject.options[subject.selectedIndex]?.text || '—';
    const term       = document.getElementById('asmtTerm');
    const termTxt    = term.options[term.selectedIndex]?.text || '—';
    const title      = document.getElementById('asmtTitle').value;
    const date       = document.getElementById('asmtDate').value;
    const mapped     = Object.keys(_itemCompMap).length;

    const typeLabel  = { summative: 'Summative Test', term_exam: 'Term Exam', periodic: 'Periodic' }[type] || type;

    document.getElementById('asmtReviewContent').innerHTML = `
        <table class="data-table table align-middle" style="font-size:.9rem">
            <tr><th>Type</th><td>${escHtml(typeLabel)}</td></tr>
            <tr><th>Total Items</th><td>${escHtml(totalItems)}</td></tr>
            <tr><th>Subject</th><td>${escHtml(subjectTxt)}</td></tr>
            <tr><th>Term</th><td>${escHtml(termTxt)}</td></tr>
            <tr><th>Title</th><td>${escHtml(title)}</td></tr>
            <tr><th>Date Given</th><td>${date || '— not set —'}</td></tr>
            <tr><th>Competency Coverage</th><td>
                ${mapped} of ${totalItems} item${totalItems > 1 ? 's' : ''} mapped
                ${mapped < totalItems ? `<span class="comp-unassigned-warn"> (${totalItems - mapped} unassigned)</span>` : ' ✓'}
            </td></tr>
        </table>`;
}

async function doCreateAssessment() {
    const errEl = document.getElementById('createAsmtErr');
    errEl.textContent = '';

    const payload = {
        type:                _createAsmtType,
        total_items:         +document.getElementById('asmtTotalItems').value,
        subject_id:          +document.getElementById('asmtSubject').value,
        term_id:             +document.getElementById('asmtTerm').value,
        title:               document.getElementById('asmtTitle').value.trim(),
        date_given:          document.getElementById('asmtDate').value || null,
        item_competency_map: _itemCompMap,
    };

    const btn = document.getElementById('btnCreateDone');
    btn.disabled = true; btn.textContent = 'Creating…';

    const r = await apiPost('api/create_admin_assessment.php', payload);
    btn.disabled = false; btn.textContent = '✓ Create Assessment';

    if (r.error) { errEl.textContent = r.error; return; }
    showToast(`Assessment created (ID #${r.assessment_id}). Teachers can now select it.`);
    closeCreateAsmtModal();
}

async function openDuplicateAsmtModal(id) {
    const error = document.getElementById('duplicateAsmtError');
    error.textContent = '';
    document.getElementById('duplicateSourceLabel').textContent = 'Loading source assessment…';
    document.getElementById('duplicateAsmtModal').style.display = '';
    const data = await apiGet(`api/get_admin_assessment.php?id=${id}`);
    if (data.error) { error.textContent = data.error; return; }
    const a = data.assessment;
    _duplicateItemCompMap = {};
    Object.entries(data.competency_map || {}).forEach(([item, value]) => {
        _duplicateItemCompMap[+item] = +value.competency_id;
    });
    document.getElementById('duplicateSourceLabel').textContent = `Copying “${a.title}” · ${a.subject_name} · Term ${a.term_no}`;
    document.getElementById('dupType').value = a.type;
    document.getElementById('dupItems').value = a.total_items;
    document.getElementById('dupTitle').value = `${a.title} (Copy)`;
    document.getElementById('dupDate').value = a.date_given || '';
    const subject = document.getElementById('dupSubject');
    subject.innerHTML = SUBJECTS_ALL.map(s => `<option value="${s.id}">${escHtml(s.name)} (Grade ${s.grade_level})</option>`).join('');
    subject.value = a.subject_id;
    const term = document.getElementById('dupTerm');
    term.innerHTML = ALL_TERMS.map(t => `<option value="${t.id}">${escHtml(t.label)}</option>`).join('');
    term.value = a.term_id;
}

function closeDuplicateAsmtModal() {
    document.getElementById('duplicateAsmtModal').style.display = 'none';
}

async function saveDuplicatedAssessment() {
    const error = document.getElementById('duplicateAsmtError');
    const button = document.getElementById('btnDuplicateAsmt');
    const totalItems = +document.getElementById('dupItems').value;
    const itemMap = {};
    Object.entries(_duplicateItemCompMap).forEach(([item, competency]) => {
        if (+item <= totalItems) itemMap[item] = competency;
    });
    const payload = {
        type: document.getElementById('dupType').value,
        total_items: totalItems,
        subject_id: +document.getElementById('dupSubject').value,
        term_id: +document.getElementById('dupTerm').value,
        title: document.getElementById('dupTitle').value.trim(),
        date_given: document.getElementById('dupDate').value || null,
        item_competency_map: itemMap,
    };
    error.textContent = '';
    button.disabled = true;
    button.textContent = 'Creating…';
    const result = await apiPost('api/create_admin_assessment.php', payload);
    button.disabled = false;
    button.textContent = 'Create Duplicate';
    if (result.error) { error.textContent = result.error; return; }
    closeDuplicateAsmtModal();
    showToast(`Assessment duplicated (ID #${result.assessment_id}).`);
    loadAdminAssessments();
}

// ============================================================
// ADMIN: COMPETENCY ANALYTICS CHART + DRILL-DOWN TABLE
// ============================================================

// Inline Chart.js plugin — draws % labels outside each bar in the competency chart
const _compDatalabelPlugin = {
    id: 'compDatalabels',
    afterDatasetsDraw(chart) {
        const { ctx } = chart;
        chart.data.datasets.forEach((ds, i) => {
            chart.getDatasetMeta(i).data.forEach((bar, j) => {
                const val = ds.data[j];
                if (val === null || val === undefined) return;
                const text = val.toFixed(1) + '%';
                ctx.save();
                ctx.font = 'bold 11px system-ui,sans-serif';
                ctx.fillStyle = '#333';
                ctx.textAlign  = 'left';
                ctx.textBaseline = 'middle';
                ctx.fillText(text, bar.x + 5, bar.y);
                ctx.restore();
            });
        });
    },
};

function renderCompetencySection(data) {
    const rows    = data.least_mastered_competencies || [];
    const cardEl  = document.getElementById('compChartCard');
    const drillEl = document.getElementById('compDrillTable');
    if (!cardEl) return;

    if (!rows.length) { cardEl.style.display = 'none'; return; }

    cardEl.style.display = '';

    // Update subtitle note
    const noteEl = document.getElementById('compChartNote');
    if (noteEl) {
        const extra = rows.length > 10 ? ` — showing 10 lowest of ${rows.length}` : '';
        noteEl.textContent = `(items with competency mapping only${extra})`;
    }

    // Chart: top 10 least mastered
    const chartData = rows.slice(0, 10);
    const labels  = chartData.map(r => r.code || r.description.substring(0, 30) + '…');
    const values  = chartData.map(r => +r.pct);
    const colors  = values.map(masteryColor);

    renderChart('chartCompetency', {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: '% Correct',
                data: values,
                backgroundColor: colors,
                borderRadius: 4,
            }],
        },
        options: {
            maintainAspectRatio: false,
            indexAxis: 'y',
            responsive: true,
            layout: { padding: { right: 52 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: items => {
                            const row = chartData[items[0].dataIndex];
                            return (row.code ? row.code + ' — ' : '') + row.description;
                        },
                        label: item => {
                            const row = chartData[item.dataIndex];
                            return [
                                ` % Correct: ${(+row.pct).toFixed(2)}%`,
                                ` Items mapped: ${row.item_count ?? '?'}`,
                                ` Sections: ${row.section_count}`,
                            ];
                        },
                    },
                },
            },
            scales: {
                x: {
                    min: 0, max: 100,
                    grid: { display: true },
                    ticks: { callback: v => v + '%' },
                },
                y: { grid: { display: false } },
            },
        },
        plugins: [_compDatalabelPlugin],
    });

    // Drill-down table
    drillEl.innerHTML = `
        <table class="data-table table align-middle comp-drill-table">
            <colgroup>
                <col class="col-code">
                <col class="col-desc">
                <col class="col-pct">
                <col class="col-sec">
                <col class="col-num">
                <col class="col-num">
            </colgroup>
            <thead><tr>
                <th>Code</th>
                <th>Description</th>
                <th>% Correct</th>
                <th>Sections</th>
                <th>f Correct</th>
                <th>f Total</th>
            </tr></thead>
            <tbody>
            ${rows.map(r => {
                const cls = r.pct < 50 ? 'pct-red' : r.pct < 75 ? 'pct-yellow' : 'pct-green';
                return `<tr>
                    <td>${escHtml(r.code || '—')}</td>
                    <td>${escHtml(r.description)}</td>
                    <td class="${cls}">${r.pct.toFixed(1)}%</td>
                    <td>${r.section_count}</td>
                    <td>${r.total_correct}</td>
                    <td>${r.total_possible}</td>
                </tr>`;
            }).join('')}
            </tbody>
        </table>`;
}

// ============================================================
// ADMIN: ASSESSMENTS TAB
// ============================================================

let _adminAssessments = [];   // cache for delete blast radius lookup
let _adminAssessmentsTable = null;
let _editAsmtId       = null;
let _editHasData      = false;
let _editTotalItems   = 0;
let _editCompetencies = [];
let _editItemCompMap  = {};
let _deleteAsmtId     = null;

// ---- Filter: update term dropdown when SY changes ----
function loadAsmtTermFilter() {
    const syId  = +document.getElementById('af_sy').value;
    const sel   = document.getElementById('af_term');
    const prev  = sel.value;
    sel.innerHTML = '<option value="">All Terms</option>';
    (typeof ALL_TERMS !== 'undefined' ? ALL_TERMS : [])
        .filter(t => !syId || +t.sy_id === syId)
        .forEach(t => {
            const o = new Option(
                t.label || `Term ${t.term_no} — SY ${t.sy_name}`,
                t.id
            );
            if (+t.id === +prev) o.selected = true;
            sel.appendChild(o);
        });
}

// ---- Load & render list ----
async function loadAdminAssessments() {
    const params = new URLSearchParams();
    const v = id => document.getElementById(id)?.value;
    if (v('af_sy'))      params.set('sy_id',      v('af_sy'));
    if (v('af_term'))    params.set('term_id',     v('af_term'));
    if (v('af_subject')) params.set('subject_id',  v('af_subject'));
    if (v('af_grade'))   params.set('grade',       v('af_grade'));
    if (v('af_type'))    params.set('type',        v('af_type'));
    const srch = v('af_search')?.trim();
    if (srch) params.set('search', srch);

    document.getElementById('asmtListLoading').style.display = '';
    document.getElementById('asmtListLoading').textContent   = 'Loading…';
    document.getElementById('asmtListWrap').style.display    = 'none';
    document.getElementById('asmtListEmpty').style.display   = 'none';

    const data = await apiGet('api/get_admin_assessments.php?' + params);
    document.getElementById('asmtListLoading').style.display = 'none';

    if (data.error) {
        document.getElementById('asmtListEmpty').style.display = '';
        document.getElementById('asmtListEmpty').textContent   = data.error;
        _adminAssessmentsTable?.setData([]);
        updateAssessmentMetrics([]);
        return;
    }

    _adminAssessments = data.assessments || [];
    updateAssessmentMetrics(_adminAssessments);

    if (!_adminAssessments.length) {
        document.getElementById('asmtListEmpty').style.display = '';
        return;
    }

    document.getElementById('asmtListWrap').style.display = '';
    const tableData = _adminAssessments.map(a => ({
        ...a,
        type_label: ({ summative: 'Summative', periodic: 'Periodic', term_exam: 'Term Exam' })[a.type] || a.type,
        subject_label: `${a.subject_name} G${a.grade_level}`,
        term_label: `Term ${a.term_no}`,
        date_label: a.date_given || '—',
        status_html: `<span class="status-chip badge status-${escHtml(a.status)}">${escHtml(a.status)}</span>`,
        actions_html: `<div class="tabulator-actions"><a class="btn btn-sm btn-outline-primary" href="${BASE_URL}api/download_answer_sheet.php?assessment_id=${encodeURIComponent(+a.id)}">Answer Sheet</a><button class="btn btn-sm btn-outline-secondary" onclick="openDuplicateAsmtModal(${+a.id})">Duplicate</button><button class="btn btn-sm btn-outline-secondary" onclick="openEditAsmtModal(${+a.id})">Edit</button><button class="btn btn-sm btn-danger" onclick="openDeleteAsmtModal(${+a.id})">Delete</button></div>`,
    }));

    if (!_adminAssessmentsTable) {
        _adminAssessmentsTable = new Tabulator('#asmtListTable', {
            data: tableData,
            layout: 'fitColumns',
            responsiveLayout: 'collapse',
            responsiveLayoutCollapseStartOpen: false,
            pagination: 'local',
            paginationSize: 15,
            paginationSizeSelector: [10, 15, 25, 50],
            paginationCounter: 'rows',
            placeholder: 'No assessments match these filters.',
            columns: [
                { formatter: 'responsiveCollapse', width: 34, minWidth: 34, hozAlign: 'center', headerSort: false, resizable: false, responsive: 0 },
                { title: 'Title', field: 'title', minWidth: 150, widthGrow: 2, responsive: 0 },
                { title: 'Type', field: 'type_label', minWidth: 96, responsive: 4 },
                { title: 'Subject', field: 'subject_label', minWidth: 135, responsive: 2 },
                { title: 'Term', field: 'term_label', minWidth: 78, responsive: 5 },
                { title: 'Items', field: 'total_items', minWidth: 58, hozAlign: 'right', responsive: 6 },
                { title: 'Date Given', field: 'date_label', minWidth: 100, responsive: 7 },
                { title: 'Sections', field: 'sections_encoded', minWidth: 72, hozAlign: 'right', responsive: 8 },
                { title: 'Teachers', field: 'teachers_encoded', minWidth: 76, hozAlign: 'right', responsive: 9 },
                { title: 'Status', field: 'status_html', formatter: 'html', minWidth: 88, responsive: 3 },
                { title: 'Actions', field: 'actions_html', formatter: 'html', minWidth: 150, widthGrow: 1, responsive: 1, headerSort: false },
            ],
        });
    } else {
        _adminAssessmentsTable.setData(tableData);
    }
}

function updateAssessmentMetrics(assessments) {
    const total = (key) => assessments.reduce((sum, row) => sum + (Number(row[key]) || 0), 0);
    const values = {
        assessmentMetricCount: assessments.length,
        assessmentMetricItems: total('total_items'),
        assessmentMetricSections: total('sections_encoded'),
        assessmentMetricTeachers: total('teachers_encoded'),
    };
    Object.entries(values).forEach(([id, value]) => {
        const el = document.getElementById(id);
        if (el) el.textContent = String(value);
    });
}

// ============================================================
// ADMIN: EDIT ASSESSMENT MODAL
// ============================================================

async function openEditAsmtModal(id) {
    _editAsmtId = id;
    document.getElementById('editAsmtErr').textContent = '';
    document.getElementById('editCompMappingGrid').innerHTML = '<p class="text-muted">Loading…</p>';
    document.getElementById('editCompMappingSummary').innerHTML = '';
    document.getElementById('editAsmtModal').style.display = '';

    const data = await apiGet(`api/get_admin_assessment.php?id=${id}`);
    if (data.error) {
        document.getElementById('editAsmtErr').textContent = data.error;
        return;
    }

    const a = data.assessment;
    _editHasData    = data.has_data;
    _editTotalItems = +a.total_items;
    _editItemCompMap = {};
    for (const [k, v] of Object.entries(data.competency_map || {})) {
        _editItemCompMap[+k] = v.competency_id;
    }

    // Prefill safe fields
    document.getElementById('editTitle').value = a.title;
    document.getElementById('editDate').value  = a.date_given || '';

    const termSel = document.getElementById('editTerm');
    termSel.value = a.term_id;

    // Destructive fields
    const typeSel  = document.getElementById('editType');
    const itemsInp = document.getElementById('editTotalItems');
    typeSel.value  = a.type;
    itemsInp.value = a.total_items;

    const lockNotice = document.getElementById('editDataLockNotice');
    if (_editHasData) {
        typeSel.disabled  = true;
        itemsInp.disabled = true;
        lockNotice.style.display = '';
        lockNotice.innerHTML =
            `<strong>🔒 ${data.sections_encoded} section(s) have already encoded data.</strong>
             Changing the item count would invalidate their MPS and Item Analysis.
             Delete the encoded data first, or create a new assessment.`;
    } else {
        typeSel.disabled  = false;
        itemsInp.disabled = false;
        lockNotice.style.display = 'none';
    }

    // Load competency mapping grid
    await buildEditMappingGrid(a.subject_id, a.term_id, _editTotalItems);
}

async function buildEditMappingGrid(subjectId, termId, totalItems) {
    const grid    = document.getElementById('editCompMappingGrid');
    const bulkSel = document.getElementById('editBulkCompSel');

    grid.innerHTML    = '<p class="text-muted">Loading competencies…</p>';
    bulkSel.innerHTML = '<option value="">— select competency —</option>';
    document.getElementById('editBulkTo').value = totalItems;

    const data = await apiGet(`api/get_competencies.php?subject_id=${subjectId}&term_id=${termId}`);
    _editCompetencies = data.competencies || [];

    if (!_editCompetencies.length) {
        grid.innerHTML = `<div class="alert alert-warning">No competencies for this subject/term.
            Add them in the Learning Competencies tab first.</div>`;
        return;
    }

    _editCompetencies.forEach(c => {
        const o = new Option((c.code ? c.code + ' — ' : '') + c.description.substring(0, 60), c.id);
        bulkSel.appendChild(o);
    });

    const optHtml = `<option value="">— unassigned —</option>` +
        _editCompetencies.map(c =>
            `<option value="${c.id}">${escHtml((c.code ? c.code + ' — ' : '') + c.description.substring(0, 60))}</option>`
        ).join('');

    let rows = '';
    for (let i = 1; i <= totalItems; i++) {
        const saved = _editItemCompMap[i] || '';
        rows += `<tr><td>${i}</td><td>
            <select data-item="${i}" onchange="updateEditItemCompSummary()" style="width:100%">
                ${optHtml.replace(`value="${saved}"`, `value="${saved}" selected`)}
            </select></td></tr>`;
    }
    grid.innerHTML = `<table class="comp-map-table">
        <thead><tr><th>#</th><th>Learning Competency</th></tr></thead>
        <tbody>${rows}</tbody></table>`;

    updateEditItemCompSummary();
}

function applyEditBulkAssign() {
    const compId = document.getElementById('editBulkCompSel').value;
    const from   = +document.getElementById('editBulkFrom').value;
    const to     = +document.getElementById('editBulkTo').value;
    if (!compId || !from || !to || from > to) {
        showToast('Select a competency and a valid item range.', 'error'); return;
    }
    document.querySelectorAll('#editCompMappingGrid select[data-item]').forEach(sel => {
        const item = +sel.dataset.item;
        if (item >= from && item <= to) sel.value = compId;
    });
    updateEditItemCompSummary();
}

function updateEditItemCompSummary() {
    const summary    = document.getElementById('editCompMappingSummary');
    const counts     = {};
    let   unassigned = 0;

    document.querySelectorAll('#editCompMappingGrid select[data-item]').forEach(sel => {
        const cid = sel.value;
        if (!cid) { unassigned++; return; }
        if (!counts[cid]) {
            const comp = _editCompetencies.find(c => String(c.id) === cid);
            counts[cid] = { label: comp ? (comp.code || comp.description.substring(0, 40)) : cid, items: [] };
        }
        counts[cid].items.push(+sel.dataset.item);
    });

    const rows = Object.values(counts).map(c =>
        `<div class="comp-summary-row">
            <span class="comp-summary-badge">${escHtml(c.label)}</span>
            <span class="text-muted">items ${c.items.join(',')} <em>(${c.items.length})</em></span>
        </div>`
    ).join('');

    const warn = unassigned > 0
        ? `<div class="comp-unassigned-warn">⚠ ${unassigned} item${unassigned > 1 ? 's' : ''} unassigned</div>`
        : `<div style="color:var(--c-success);font-size:.8rem">✓ All items mapped</div>`;

    summary.innerHTML = rows + warn;
}

async function saveEditAsmt() {
    const errEl = document.getElementById('editAsmtErr');
    errEl.textContent = '';

    const title = document.getElementById('editTitle').value.trim();
    const termId = document.getElementById('editTerm').value;
    if (!title)  { errEl.textContent = 'Title is required.'; return; }
    if (!termId) { errEl.textContent = 'Term is required.'; return; }

    // Collect current mapping
    const itemCompMap = {};
    document.querySelectorAll('#editCompMappingGrid select[data-item]').forEach(sel => {
        if (sel.value) itemCompMap[+sel.dataset.item] = +sel.value;
    });

    const payload = {
        assessment_id:      _editAsmtId,
        title,
        term_id:            +termId,
        date_given:         document.getElementById('editDate').value || null,
        item_competency_map: itemCompMap,
    };

    // Include destructive fields only when not locked
    if (!_editHasData) {
        payload.type        = document.getElementById('editType').value;
        payload.total_items = +document.getElementById('editTotalItems').value;
    }

    const btn = document.getElementById('btnSaveEdit');
    btn.disabled = true; btn.textContent = 'Saving…';

    const r = await apiPost('api/admin_update_assessment.php', payload);
    btn.disabled = false; btn.textContent = 'Save Changes';

    if (r.error) { errEl.textContent = r.error; return; }

    showToast('Assessment updated.');
    closeEditAsmtModal();
    loadAdminAssessments();
}

function closeEditAsmtModal() {
    document.getElementById('editAsmtModal').style.display = 'none';
    document.getElementById('editType').disabled      = false;
    document.getElementById('editTotalItems').disabled = false;
}

// ============================================================
// ADMIN: DELETE ASSESSMENT MODAL (with blast radius)
// ============================================================

async function openDeleteAsmtModal(id) {
    _deleteAsmtId = null;
    document.getElementById('deleteAsmtErr').textContent = '';
    document.getElementById('deleteAsmtInfo').innerHTML  = '<p class="text-muted">Loading…</p>';
    document.getElementById('deleteAsmtConfirmBox').style.display = 'none';
    document.getElementById('deleteConfirmInput').value  = '';
    document.getElementById('btnConfirmDelete').disabled = false;
    document.getElementById('deleteAsmtModal').style.display = '';

    const r = await apiPost('api/admin_delete_assessment.php', { assessment_id: id, action: 'info' });
    if (r.error) {
        document.getElementById('deleteAsmtInfo').innerHTML = `<p class="alert alert-warning">${escHtml(r.error)}</p>`;
        return;
    }

    _deleteAsmtId = id;
    const br      = r.blast_radius;
    const hasData = br.has_data;

    let html = `<p>Delete <strong>${escHtml(r.title)}</strong>?</p>`;
    if (hasData) {
        html += `<div class="blast-radius-box">
            <strong>⚠ Permanent data loss:</strong><br>
            Encoded data from <strong>${br.sections} section(s)</strong> and
            <strong>${br.teachers} teacher(s)</strong>
            (${br.students} student score${br.students !== 1 ? 's' : ''}) will be permanently erased.
        </div>`;
        document.getElementById('btnConfirmDelete').disabled = true;
        document.getElementById('deleteAsmtConfirmBox').style.display = '';
    } else {
        html += `<p class="text-muted" style="margin-top:.5rem">No encoded data. This assessment has no MPS or Item Analysis data yet — safe to delete.</p>`;
    }
    document.getElementById('deleteAsmtInfo').innerHTML = html;
}

async function confirmDeleteAsmtById() {
    if (!_deleteAsmtId) return;
    const errEl = document.getElementById('deleteAsmtErr');
    errEl.textContent = '';

    const btn = document.getElementById('btnConfirmDelete');
    btn.disabled = true; btn.textContent = 'Deleting…';

    const r = await apiPost('api/admin_delete_assessment.php', {
        assessment_id: _deleteAsmtId,
        action: 'delete',
    });

    btn.disabled = false; btn.textContent = '🗑 Delete';

    if (r.error) { errEl.textContent = r.error; return; }

    showToast('Assessment deleted.');
    loadAdminAssessments();
    closeDeleteAsmtModal();
    refreshDashboard();
}

function closeDeleteAsmtModal() {
    document.getElementById('deleteAsmtModal').style.display = 'none';
    _deleteAsmtId = null;
}
