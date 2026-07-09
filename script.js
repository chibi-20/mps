/* ============================================================
   MPS & Item Analysis System — Client-side Logic
   Mirrors PHP band/threshold constants; authoritative values
   are always recomputed server-side on save & dashboard load.
   ============================================================ */

'use strict';

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

    // Lock buttons if submitted/approved
    const locked = (a.status === 'submitted' || a.status === 'approved');
    document.getElementById('btnSaveDraft').disabled = locked;
    document.getElementById('btnSubmit').disabled    = locked;
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
    const totalItems = +a.total_items;
    const locked = (a.status === 'submitted' || a.status === 'approved');

    const tbl = document.getElementById('itemTable');
    tbl.innerHTML = '';

    const thead = tbl.createTHead();
    const r1 = thead.insertRow();
    const itTH = document.createElement('th'); itTH.textContent = 'Item'; itTH.rowSpan = 2; r1.appendChild(itTH);
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
            annotation: { annotations: [{ type:'line', yMin:75, yMax:75, borderColor:'#f4a226', borderWidth:2 }] },
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
    renderChart('chartMpsSection',   buildMpsSectionChart(data));
    renderChart('chartMpsSubject',   buildMpsSubjectChart(data));
    renderChart('chartMastery',      buildMasteryChart(data));
    renderChart('chartNpwrm',        buildNpwrmChart(data));
    renderChart('chartLeastMastered',buildLeastMasteredChart(data));
    renderChart('chartMpsTrend',     buildMpsTrendChart(data));
    renderHeatmap(data.item_heatmap);
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

function buildMpsSectionChart(data) {
    const d = data.mps_per_section || [];
    return {
        type: 'bar',
        data: {
            labels: d.map(r => r.section_name),
            datasets: [{
                label: 'MPS %',
                data:  d.map(r => +r.mps),
                backgroundColor: d.map(r => +r.mps >= 75 ? '#52b788' : '#e55934'),
            }],
        },
        options: {
            plugins: {
                legend: { display: false },
                annotation: {
                    annotations: { line75: { type:'line', yMin:75, yMax:75, borderColor:'#f4a226', borderWidth:2, label:{content:'Target 75%', display:true} } }
                },
            },
            scales: { y: { min:0, max:100 } },
        },
    };
}

function buildMpsSubjectChart(data) {
    const d = data.mps_per_subject || [];
    return {
        type: 'bar',
        data: {
            labels: d.map(r => r.subject_name + ' G' + r.grade_level),
            datasets: [{ label: 'MPS %', data: d.map(r => +r.mps), backgroundColor: '#1d6fa3' }],
        },
        options: { scales: { y: { min:0, max:100 } } },
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
                backgroundColor: '#1d6fa3',
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
                borderColor: '#1d6fa3', fill: false, tension: 0.3,
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
        // Wire grade/subject filters to update dependents
        ['f_sy','f_grade','f_subject'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', async () => {
                await updateFilterDependents();
                refreshDashboard();
            });
        });
        updateFilterDependents().then(() => refreshDashboard());
    }
});
