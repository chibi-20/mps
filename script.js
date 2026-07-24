/* ============================================================
   MPS & Item Analysis System — Client-side Logic
   Mirrors PHP band/threshold constants; authoritative values
   are always recomputed server-side on save & dashboard load.
   ============================================================ */

'use strict';

// ============================================================
// Brand palette (Chart.js needs literal color strings — single
// source of truth here instead of hex scattered per chart)
// ============================================================
const THEME = { maroon: '#6E1423', maroonDark: '#4A0E18', gold: '#C79A3A', goldLight: '#E6C767' };

// ============================================================
// Shared helpers
// ============================================================

function pctColor(pct) {
    if (pct <  50) return 'pct-red';
    if (pct <  75) return 'pct-yellow';
    return 'pct-green';
}

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
        background: type === 'success' ? '#2d6a4f' : '#c0392b',
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
let mpsSecCases = {};         // {sec_id: Σf}    — updated by recomputeMps; shared with item table
let mpsSecFx    = {};         // {sec_id: Σf(x)} — used as cross-check in Item Analysis

function toggleNewForm(show) {
    document.getElementById('newAssessmentPanel').style.display = show ? 'block' : 'none';
    document.getElementById('emptyState').style.display = show ? 'none' : 'block';
    document.getElementById('assessmentDetail').style.display = 'none';
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
    document.getElementById('btnNewAssessment')?.addEventListener('click', () => toggleNewForm(true));

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

    // Remove the card from the sidebar
    document.querySelector(`.assessment-item[data-id="${id}"]`)?.remove();

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
                <div class="table-scroll" style="max-height:130px;margin-top:.4rem">
                    <table class="data-table" style="font-size:.8rem">
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
    // Mark active in sidebar
    document.querySelectorAll('.assessment-item').forEach(el => {
        el.classList.toggle('active', +el.dataset.id === +id);
    });

    document.getElementById('newAssessmentPanel').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('assessmentDetail').style.display = 'block';
    document.getElementById('mpsTable').innerHTML = '<tr><td>Loading…</td></tr>';
    document.getElementById('itemTable').innerHTML = '<tr><td>Loading…</td></tr>';

    const data = await apiGet(`api/get_assessment_data.php?id=${id}`);
    if (data.error) { showToast(data.error, 'error'); return; }

    currentAssessment = data;
    currentAssessmentId = id;

    // Seed mpsSecCases / mpsSecFx from saved DB data.
    // recomputeMps() will override these with live input values for unlocked assessments.
    const sfInit = data.score_frequencies;
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
    chip.className = 'status-chip status-' + a.status;

    // Export link
    document.getElementById('btnExport').href = `${BASE_URL}api/export_excel.php?assessment_id=${id}`;

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
        th.colSpan = 2; th.textContent = s.name;
        r1.appendChild(th);
    });
    const totTH = document.createElement('th');
    totTH.colSpan = 2; totTH.textContent = 'TOTAL';
    r1.appendChild(totTH);

    const r2 = thead2.insertRow();
    sections.forEach(() => { addTH(r2,'f'); addTH(r2,'f(x)'); });
    addTH(r2,'f'); addTH(r2,'f(x)');

    // Body: rows from totalItems down to 0
    const tbody = tbl.createTBody();
    for (let score = totalItems; score >= 0; score--) {
        const tr = tbody.insertRow();
        tr.dataset.score = score;
        const td0 = tr.insertCell(); td0.textContent = score; td0.className = 'row-label';

        let rowTotalF = 0;
        sections.forEach(sec => {
            const savedF = sf[sec.id]?.[score] ?? 0;
            const cellF  = tr.insertCell();
            if (locked) {
                cellF.textContent = savedF || '';
            } else {
                const inp = document.createElement('input');
                inp.type = 'number'; inp.min = 0; inp.max = 9999;
                inp.value = savedF || '';
                inp.dataset.score  = score;
                inp.dataset.secId  = sec.id;
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
            tr.insertCell().className = `sum-${lbl.split(' ')[0].toLowerCase()}-${sec.id}`;
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
            const c1 = tr.insertCell(); c1.className = `band-${bk}-${sec.id}`;
            const c2 = tr.insertCell(); c2.className = `bandpct-${bk}-${sec.id}`;
        });
        tr.insertCell().className = 'total-col';
        tr.insertCell().className = 'total-col';
    });

    // NPWRM row
    const ntr = tbody.insertRow();
    ntr.className = 'summary-row';
    const ntd = ntr.insertCell(); ntd.textContent = `NPWRM (≥${MASTERY_THRESHOLD}%)`; ntd.className = 'row-label';
    sections.forEach(sec => {
        ntr.insertCell().className = `npwrm-${sec.id}`;
        ntr.insertCell();
    });
    ntr.insertCell().className = 'total-col';
    ntr.insertCell().className = 'total-col';

    recomputeMps();
}

function addTH(row, text, span) {
    const th = document.createElement('th');
    th.textContent = text;
    if (span) th.colSpan = span;
    row.appendChild(th);
    return th;
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

    sections.forEach(sec => {
        let cases = 0, sumFx = 0, npwrm = 0;
        const bands = {}; BAND_KEYS.forEach(k => bands[k] = 0);

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

        setCell(`.npwrm-${sec.id}`, cases > 0 ? npwrm : '');

        grandCases  += cases;
        grandFx     += sumFx;
        grandNpwrm  += npwrm;
        BAND_KEYS.forEach(k => grandBands[k] += bands[k]);
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
    sections.forEach(s => { const th = document.createElement('th'); th.colSpan=2; th.textContent=s.name; r1.appendChild(th); });
    const totTH2 = document.createElement('th'); totTH2.colSpan=2; totTH2.textContent='TOTAL'; r1.appendChild(totTH2);
    const r2 = thead.insertRow();
    sections.forEach(() => { addTH(r2,'f'); addTH(r2,'%'); });
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

        sections.forEach(sec => {
            const savedF = icc[sec.id]?.[item] ?? 0;
            const cases  = mpsSecCases[sec.id] || 0;
            const cellF  = tr.insertCell();
            if (locked) {
                cellF.textContent = savedF || '';
            } else {
                const inp = document.createElement('input');
                inp.type = 'number'; inp.min = 0; inp.max = cases || 9999;
                inp.value = savedF || '';
                inp.dataset.item  = item;
                inp.dataset.secId = sec.id;
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
        totalCell.className = `item-total-${sec.id} total-col`;
        const checkCell = totRow.insertCell();
        checkCell.className = `item-check-${sec.id} total-col`;
    });
    // Grand total cells
    totRow.insertCell().className = 'total-col';
    totRow.insertCell().className = 'total-col';

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
    if (!ctx) return;
    if (miniChart) miniChart.destroy();
    miniChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'MPS %',
                data: values,
                backgroundColor: values.map(v => v >= 75 ? '#2d9148' : '#e55934'),
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

async function refreshDashboard() {
    const params = new URLSearchParams({
        sy:         document.getElementById('f_sy')?.value         || '',
        term:       document.getElementById('f_term')?.value       || '',
        grade:      document.getElementById('f_grade')?.value      || '',
        subject:    document.getElementById('f_subject')?.value    || '',
        section:    document.getElementById('f_section')?.value    || '',
        assessment: document.getElementById('f_assessment')?.value || '',
    });
    const data = await apiGet(`api/get_dashboard_data.php?${params}`);
    if (!data || data.error) { showToast('Failed to load dashboard data.', 'error'); return; }

    renderKpis(data.kpis);
    renderChart('chartMpsSubject',   buildMpsSubjectChart(data));
    renderChart('chartMpsGrade',     buildMpsGradeChart(data));
    renderChart('chartMastery',      buildMasteryChart(data));
    renderChart('chartNpwrm',        buildNpwrmChart(data));
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
            labels: d.map(r => r.subject_name + ' G' + r.grade_level),
            datasets: [{
                label: 'MPS %',
                data:  d.map(r => +r.mps),
                backgroundColor: d.map(r => +r.mps >= 75 ? '#52b788' : THEME.maroon),
            }],
        },
        options: {
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
                backgroundColor: d.map(r => +r.mps >= 75 ? '#52b788' : THEME.maroon),
            }],
        },
        options: {
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

function buildMasteryChart(data) {
    const d = data.mastery_distribution || [];
    const colors = { M:'#1a7a4a', CAM:'#52b788', MTM:'#95d5b2', AVR:'#ffd166', LM:'#ef8c44', VLM:'#e55934', ANM:'#9d0208' };
    return {
        type: 'bar',
        data: {
            labels: d.map(r => r.section_name),
            datasets: BAND_KEYS.map(bk => ({
                label: bk,
                data:  d.map(r => +(r.bands?.[bk] || 0)),
                backgroundColor: colors[bk],
            })),
        },
        options: {
            plugins: { legend: { position: 'bottom' } },
            scales: { x: { stacked:true }, y: { stacked:true, min:0, max:100 } },
        },
    };
}

function buildNpwrmChart(data) {
    const d = data.npwrm_per_section || [];
    return {
        type: 'bar',
        data: {
            labels: d.map(r => r.section_name),
            datasets: [{
                label: 'NPWRM',
                data:  d.map(r => +r.npwrm),
                backgroundColor: THEME.maroon,
            }],
        },
        options: { scales: { y: { beginAtZero: true } } },
    };
}

function buildLeastMasteredChart(data) {
    const d = (data.least_mastered_items || []).slice(0, 20);
    return {
        type: 'bar',
        data: {
            labels: d.map(r => 'Item ' + r.item_no),
            datasets: [{ label: '% Correct', data: d.map(r => +r.pct), backgroundColor: '#e55934' }],
        },
        options: {
            indexAxis: 'y',
            scales: { x: { min:0, max:100 } },
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
                borderColor: THEME.maroon, fill: false, tension: 0.3,
            }],
        },
        options: { scales: { y: { min:0, max:100 } } },
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
async function loadSubmissions() {
    const data = await apiGet('api/get_submissions.php');
    if (!data || data.error) return;
    const tbody = document.getElementById('complianceTbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    (data.submissions || []).forEach(row => {
        const tr = tbody.insertRow();
        tr.innerHTML = `
            <td class="text-left">${escHtml(row.teacher_name)}</td>
            <td class="text-left">${escHtml(row.title)}</td>
            <td>${escHtml(row.subject_name)}</td>
            <td>Term ${row.term_no}</td>
            <td><span class="status-chip status-${row.status}">${row.status}</span></td>
            <td>
                ${row.status === 'submitted' ? `
                <button class="btn btn-sm btn-success" onclick="approveAssessment(${row.id})">Approve</button>
                <button class="btn btn-sm btn-warning" onclick="openReturnModal(${row.id})">Return</button>
                ` : ''}
                <button class="btn btn-sm btn-danger"
                        data-aid="${row.id}"
                        data-title="${escHtml(row.title)}"
                        data-teacher="${escHtml(row.teacher_name)}"
                        onclick="adminDeleteAssessment(this)">Delete</button>
            </td>`;
    });
}

async function approveAssessment(id) {
    if (!confirm('Approve this assessment?')) return;
    const r = await apiPost('api/approve_assessment.php', { assessment_id: id, action: 'approve' });
    if (r.error) { showToast(r.error,'error'); return; }
    showToast('Assessment approved.');
    loadSubmissions();
}

async function adminDeleteAssessment(btn) {
    const id      = +btn.dataset.aid;
    const title   = btn.dataset.title;
    const teacher = btn.dataset.teacher;
    if (!confirm(`Delete "${title}" by ${teacher}?\n\nThis permanently removes all its MPS and Item Analysis data and cannot be undone.`)) return;
    const r = await apiPost('api/admin_delete_assessment.php', { assessment_id: id });
    if (r.error) { showToast(r.error, 'error'); return; }
    btn.closest('tr')?.remove();
    showToast('Assessment deleted.');
    refreshDashboard();
}

function openReturnModal(id) {
    document.getElementById('returnAsmtId').value = id;
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
    renderTeacherTable('pendingTbody', data.pending, true);
    renderTeacherTable('activeTbody',  data.active,  false);
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
        tr.innerHTML = `
            <td class="text-left">${escHtml(r.display_name)}</td>
            <td>${escHtml(r.username)}</td>
            <td>${escHtml(r.grade_levels)}</td>
            <td class="text-left">${escHtml(r.subjects)}</td>
            <td>${escHtml(r.created_at || '')}</td>
            <td>
                ${isPending
                  ? `<button class="btn btn-sm btn-success" onclick="manageTeacher(${r.id},'approve')">Approve</button>`
                  : `<button class="btn btn-sm btn-danger"  onclick="manageTeacher(${r.id},'deactivate')">Deactivate</button>`}
            </td>`;
    });
}

async function manageTeacher(id, action) {
    if (!confirm(`${action === 'approve' ? 'Approve' : 'Deactivate'} this teacher?`)) return;
    const r = await apiPost('api/manage_teacher.php', { teacher_id: id, action });
    if (r.error) { showToast(r.error,'error'); return; }
    showToast(action === 'approve' ? 'Teacher approved.' : 'Teacher deactivated.');
    loadTeachers();
}

function adminTab(btn, panel) {
    document.querySelectorAll('.admin-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.admin-panel').forEach(p => p.style.display='none');
    document.getElementById('panel-'+panel).style.display = 'block';
    if (panel === 'submissions') loadSubmissions();
    if (panel === 'teachers')    loadTeachers();
    if (panel === 'assignments') initAssignmentsPanel();
    if (panel === 'assessments') loadAdminAssessments();
}

// ============================================================
// ADMIN: SECTION ASSIGNMENTS PANEL
// ============================================================

async function initAssignmentsPanel() {
    const sel = document.getElementById('assignTeacher');
    if (!sel || sel.options.length > 1) return; // already populated
    const data = await apiGet('api/get_teachers.php');
    if (!data?.active) return;
    data.active.forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = t.display_name;
        sel.appendChild(opt);
    });
}

async function adminLoadTeacherSubjects(teacherId) {
    const subjSel = document.getElementById('assignSubject');
    const wrap    = document.getElementById('assignSectionWrap');
    subjSel.innerHTML = '<option value="">— select subject —</option>';
    subjSel.disabled  = true;
    wrap.style.display = 'none';

    if (!teacherId) return;

    const data = await apiGet(`api/get_teacher_subjects.php?teacher_id=${encodeURIComponent(teacherId)}`);
    if (data.error || !data.subjects?.length) {
        subjSel.innerHTML = '<option value="">No subjects registered</option>';
        return;
    }

    data.subjects.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = `${s.name} (Grade ${s.grade_level})`;
        subjSel.appendChild(opt);
    });
    subjSel.disabled = false;
}

async function adminLoadAssignmentSections() {
    const teacherId  = document.getElementById('assignTeacher')?.value;
    const subjectId  = document.getElementById('assignSubject')?.value;
    const wrap       = document.getElementById('assignSectionWrap');
    const container  = document.getElementById('assignSectionChecklist');
    const hint       = document.getElementById('assignSectionHint');
    wrap.style.display = 'none';

    if (!teacherId || !subjectId) return;

    container.innerHTML = '<p class="text-muted" style="margin:0">Loading…</p>';
    wrap.style.display  = 'block';

    const data = await apiGet(
        `api/get_assignment_sections.php?teacher_id=${encodeURIComponent(teacherId)}&subject_id=${encodeURIComponent(subjectId)}`
    );

    if (data.error) {
        container.innerHTML = `<p class="alert alert-warning" style="margin:0">${escHtml(data.error)}</p>`;
        return;
    }
    if (!data.sections?.length) {
        container.innerHTML = `<p class="alert alert-warning" style="margin:0">No sections for Grade ${data.grade}.</p>`;
        return;
    }

    const assigned = new Set((data.assigned || []).map(Number));
    let html = '';
    data.sections.forEach(sec => {
        const chk = assigned.has(+sec.id) ? 'checked' : '';
        html += `<label class="check-item">` +
                `<input type="checkbox" name="assign_sec" value="${sec.id}" ${chk}>` +
                `${escHtml(sec.name)}</label>`;
    });
    container.innerHTML = html;

    const updateHint = () => {
        const n = container.querySelectorAll('input:checked').length;
        const t = container.querySelectorAll('input').length;
        if (hint) hint.textContent = `— ${n} of ${t} selected`;
    };
    updateHint();
    container.querySelectorAll('input').forEach(cb => cb.addEventListener('change', updateHint));
}

async function adminSaveAssignments() {
    const teacherId  = document.getElementById('assignTeacher')?.value;
    const subjectId  = document.getElementById('assignSubject')?.value;
    const msg        = document.getElementById('assignSaveMsg');
    if (!teacherId || !subjectId) return;

    const section_ids = [...document.querySelectorAll('#assignSectionChecklist input:checked')]
        .map(cb => +cb.value);

    const result = await apiPost('api/assign_sections.php', { teacher_id: +teacherId, subject_id: +subjectId, section_ids });
    if (result.error) {
        if (msg) { msg.textContent = result.error; msg.style.color = 'var(--error, #c0392b)'; }
        showToast(result.error, 'error');
        return;
    }
    if (msg) { msg.textContent = `Saved — ${result.assigned} section(s) assigned.`; msg.style.color = 'var(--success, #2d6a4f)'; }
    showToast(`${result.assigned} section(s) assigned.`);
}

function escHtml(s) {
    if (!s) return '';
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ---- FILTER DEPENDENCY: populate section + assessment dropdowns ----
async function updateFilterDependents() {
    const sy      = document.getElementById('f_sy')?.value || '';
    const grade   = document.getElementById('f_grade')?.value || '';
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
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('panel-analytics')) {
        // f_sy / f_grade / f_subject have NO inline onchange — handled here so that
        // updateFilterDependents() always runs before refreshDashboard(), keeping the
        // Sections dropdown in sync with the selected Grade before the API call fires.
        ['f_sy', 'f_grade', 'f_subject'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', async () => {
                await updateFilterDependents();
                refreshDashboard();
            });
        });
        // Initial load
        updateFilterDependents().then(() => refreshDashboard());
    }

    // Teacher dashboard: "+ New" button opens the select-assessment panel
    const btnNew = document.getElementById('btnNewAssessment');
    if (btnNew) {
        btnNew.addEventListener('click', () => openSelectAsmtPanel());
    }
});

// ============================================================
// TEACHER: SELECT SHARED ASSESSMENT
// ============================================================

let _sharedAsmts = [];   // cached from API

async function openSelectAsmtPanel() {
    document.getElementById('assessmentDetail').style.display = 'none';
    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('newAssessmentPanel').style.display = 'block';

    clearAsmtSelection();
    const list = document.getElementById('selectAsmtList');
    list.innerHTML = '<p class="text-muted">Loading…</p>';

    const data = await apiGet('api/get_shared_assessments.php');
    if (data.error) { list.innerHTML = `<p class="alert alert-warning">${escHtml(data.error)}</p>`; return; }

    _sharedAsmts = data.assessments || [];
    if (!_sharedAsmts.length) {
        list.innerHTML = '<p class="text-muted">No assessments available yet. Ask your administrator to create assessments for your subjects.</p>';
        return;
    }
    list.innerHTML = _sharedAsmts.map(a => `
        <div class="select-asmt-card ${a.already_encoding ? 'active' : ''}" id="saCard${a.id}" onclick="selectSharedAsmt(${a.id})">
            <div class="select-asmt-card-title">${escHtml(a.title)}</div>
            <div class="select-asmt-card-meta">
                ${escHtml(a.subject_name)} G${a.grade_level} &middot; Term ${a.term_no} &middot;
                ${a.total_items} items &middot; SY ${escHtml(a.sy_name)}
            </div>
            ${a.already_encoding ? '<div class="select-asmt-card-badge">✓ Already encoding</div>' : ''}
        </div>`).join('');
}

async function selectSharedAsmt(id) {
    selectedSharedAsmtId = id;
    document.querySelectorAll('.select-asmt-card').forEach(c => c.classList.remove('active'));
    document.getElementById('saCard' + id)?.classList.add('active');

    const asmt = _sharedAsmts.find(a => a.id == id);
    if (!asmt) return;

    document.getElementById('selectAsmtName').textContent = asmt.title;

    // Load teacher's sections for this assessment's subject
    const checklist = document.getElementById('selectSectionChecklist');
    checklist.innerHTML = '<p class="text-muted">Loading sections…</p>';
    document.getElementById('selectAsmtSections').style.display = 'block';

    const data = await apiGet(`api/get_sections.php?subject_id=${asmt.subject_id}`);
    if (data.error || !data.sections?.length) {
        checklist.innerHTML = '<p class="alert alert-warning">No sections found for your assignment. Contact admin.</p>';
        return;
    }

    const preChecked = new Set(data.checked || []);
    checklist.innerHTML = data.sections.map(s =>
        `<label class="check-item"><input type="checkbox" name="sel_sec" value="${s.id}"${preChecked.has(s.id) ? ' checked' : ''}>${escHtml(s.name)}</label>`
    ).join('');
}

function clearAsmtSelection() {
    selectedSharedAsmtId = null;
    document.getElementById('selectAsmtSections').style.display = 'none';
    document.getElementById('selectSectionChecklist').innerHTML = '';
}

async function startEncoding() {
    if (!selectedSharedAsmtId) return;
    const checked = [...document.querySelectorAll('#selectSectionChecklist input:checked')].map(cb => +cb.value);
    if (!checked.length) { showToast('Select at least one section.', 'error'); return; }

    const r = await apiPost('api/start_encoding.php', {
        assessment_id: selectedSharedAsmtId,
        section_ids:   checked,
    });
    if (r.error) { showToast(r.error, 'error'); return; }

    showToast(`Started encoding — ${r.sections} section(s) ready.`);
    setTimeout(() => {
        loadAssessment(selectedSharedAsmtId);
        document.getElementById('newAssessmentPanel').style.display = 'none';
    }, 600);
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

    if (!subjectId) {
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
                <button class="btn btn-sm btn-outline" onclick="adminEditCompetency(${c.id},'${escHtml(c.code || '')}','${escHtml(c.description).replace(/'/g,"\\'")}')">Edit</button>
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
        (data.terms || []).map(t => `<option value="${t.id}">Term ${t.term_no} — ${escHtml(t.name)}</option>`).join('');
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
        <table class="data-table" style="font-size:.9rem">
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

// ============================================================
// ADMIN: COMPETENCY ANALYTICS CHART + DRILL-DOWN TABLE
// ============================================================

function renderCompetencySection(data) {
    const rows    = data.least_mastered_competencies || [];
    const cardEl  = document.getElementById('compChartCard');
    const drillEl = document.getElementById('compDrillTable');
    if (!cardEl) return;

    if (!rows.length) { cardEl.style.display = 'none'; return; }

    cardEl.style.display = '';

    // Chart: top 10 least mastered
    const chartData = rows.slice(0, 10);
    const labels  = chartData.map(r => r.code || r.description.substring(0, 30) + '…');
    const values  = chartData.map(r => r.pct);
    const colors  = values.map(v => v < 50 ? '#e55934' : v < 75 ? '#f0a500' : '#2d6a4f');

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
            indexAxis: 'y',
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items) => {
                            const row = chartData[items[0].dataIndex];
                            return (row.code ? row.code + ' — ' : '') + row.description;
                        },
                        label: (item) => ` ${item.raw.toFixed(2)}%`,
                    },
                },
            },
            scales: {
                x: {
                    min: 0, max: 100,
                    ticks: { callback: v => v + '%' },
                },
            },
            annotation: {
                annotations: [{
                    type: 'line', xMin: 75, xMax: 75, borderColor: '#e07b00',
                    borderWidth: 2, borderDash: [4, 4],
                    label: { content: '75% target', display: true, position: 'start', font: { size: 10 } },
                }],
            },
        },
    });

    // Drill-down table
    drillEl.innerHTML = `
        <table class="data-table comp-drill-table">
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
                `Term ${t.term_no} — ${t.term_name} (SY ${t.sy_name})`,
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
        return;
    }

    _adminAssessments = data.assessments || [];

    if (!_adminAssessments.length) {
        document.getElementById('asmtListEmpty').style.display = '';
        return;
    }

    const tbody = document.getElementById('asmtListTbody');
    const typeLabel = { summative: 'Summative', term_exam: 'Term Exam' };

    tbody.innerHTML = _adminAssessments.map(a => `
        <tr id="asmtRow${a.id}">
            <td>${escHtml(a.title)}</td>
            <td>${typeLabel[a.type] || escHtml(a.type)}</td>
            <td>${escHtml(a.subject_name)} <small class="text-muted">G${a.grade_level}</small></td>
            <td>Term ${a.term_no}</td>
            <td style="text-align:right">${a.total_items}</td>
            <td>${a.date_given || '—'}</td>
            <td style="text-align:right">${a.sections_encoded}</td>
            <td style="text-align:right">${a.teachers_encoded}</td>
            <td><span class="status-chip status-${a.status}">${escHtml(a.status)}</span></td>
            <td class="asmt-actions-cell">
                <button class="btn btn-sm btn-outline" onclick="openEditAsmtModal(${a.id})">Edit</button>
                <button class="btn btn-sm btn-danger"  onclick="openDeleteAsmtModal(${a.id})">Delete</button>
            </td>
        </tr>`).join('');

    document.getElementById('asmtListWrap').style.display = '';
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
    document.getElementById('asmtRow' + _deleteAsmtId)?.remove();
    closeDeleteAsmtModal();
    refreshDashboard();
}

function closeDeleteAsmtModal() {
    document.getElementById('deleteAsmtModal').style.display = 'none';
    _deleteAsmtId = null;
}
