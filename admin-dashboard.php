<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$sess = require_login('admin');
$pdo  = get_pdo();
$csrf = csrf_token();

// Dropdown data for filters
$schoolYears = $pdo->query("SELECT id, name FROM school_years ORDER BY id DESC")->fetchAll();
$activeSY    = $pdo->query("SELECT id, name FROM school_years WHERE is_active=1 LIMIT 1")->fetch();
$activeSYId  = $activeSY ? (int)$activeSY['id'] : 0;

if ($activeSYId) {
    $ts = $pdo->prepare("SELECT id, term_no, name FROM terms WHERE school_year_id=? ORDER BY term_no");
    $ts->execute([$activeSYId]);
    $terms = $ts->fetchAll();
} else {
    $terms = [];
}

$subjects    = $pdo->query("SELECT DISTINCT name FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$subjectsAll = $pdo->query("SELECT id, name, grade_level FROM subjects ORDER BY grade_level, name")->fetchAll();
$gradeLevels = [9, 10];  // grades with sections
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard – MPS System</title>
<link rel="icon" href="<?= BASE_URL ?>assets/logo.png">
<link rel="stylesheet" href="<?= BASE_URL ?>styles.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>

<nav class="topnav">
    <div class="topnav-brand">
        <img src="<?= BASE_URL ?>assets/logo.png" alt="Jacobo Z. Gonzales Memorial National High School" class="school-logo">
        <span class="topnav-title">MPS &amp; Item Analysis System</span>
    </div>
    <div class="topnav-user">
        <span class="user-chip admin-chip">Admin</span>
        <span><?= h($sess['display']) ?></span>
        <a href="<?= BASE_URL ?>api/logout.php" class="btn btn-sm btn-outline">Sign Out</a>
    </div>
</nav>

<!-- Tab Navigation -->
<div class="admin-tabs">
    <button class="admin-tab active" data-panel="analytics"     onclick="adminTab(this,'analytics')">Analytics</button>
    <button class="admin-tab" data-panel="submissions"          onclick="adminTab(this,'submissions')">Submissions</button>
    <button class="admin-tab" data-panel="competencies"         onclick="adminTab(this,'competencies')">Learning Competencies</button>
    <button class="admin-tab" data-panel="teachers"             onclick="adminTab(this,'teachers')">Teacher Accounts</button>
    <button class="admin-tab" data-panel="assignments"          onclick="adminTab(this,'assignments')">Section Assignments</button>
    <button class="btn btn-import" style="margin-left:auto;align-self:center;margin-right:.75rem"
            onclick="openCreateAsmtModal()">+ Create Assessment</button>
</div>

<!-- ============================================================
     PANEL: ANALYTICS
============================================================ -->
<div id="panel-analytics" class="admin-panel">

    <!-- Filters -->
    <div class="filter-bar card">
        <strong>Filters:</strong>
        <select id="f_sy">
            <?php foreach ($schoolYears as $sy): ?>
            <option value="<?= $sy['id'] ?>" <?= $sy['id'] == $activeSYId ? 'selected' : '' ?>>
                SY <?= h($sy['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select id="f_term" onchange="refreshDashboard()">
            <option value="">All Terms</option>
            <?php foreach ($terms as $t): ?>
            <option value="<?= $t['id'] ?>">Term <?= $t['term_no'] ?></option>
            <?php endforeach; ?>
        </select>
        <select id="f_grade">
            <option value="">All Grades</option>
            <?php foreach ($gradeLevels as $gl): ?>
            <option value="<?= $gl ?>">Grade <?= $gl ?></option>
            <?php endforeach; ?>
        </select>
        <select id="f_subject">
            <option value="">All Subjects</option>
            <?php foreach ($subjects as $sn): ?>
            <option value="<?= h($sn) ?>"><?= h($sn) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="f_section" onchange="refreshDashboard()">
            <option value="">All Sections</option>
        </select>
        <select id="f_assessment" onchange="refreshDashboard()">
            <option value="">All Assessments</option>
        </select>
        <button class="btn btn-sm btn-outline" onclick="refreshDashboard()">Refresh</button>
    </div>

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Overall MPS</div>
            <div class="kpi-value" id="kpi_mps">—</div>
            <div class="kpi-sub">Target: 75%</div>
            <div class="kpi-bar"><div class="kpi-bar-fill" id="kpi_mps_bar"></div><div class="kpi-bar-target"></div></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Total Examinees</div>
            <div class="kpi-value" id="kpi_examinees">—</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Submitted Assessments</div>
            <div class="kpi-value" id="kpi_submitted">—</div>
        </div>
        <div class="kpi-card kpi-card--alert">
            <div class="kpi-label">Items Below 50%</div>
            <div class="kpi-value" id="kpi_below50">—</div>
            <div class="kpi-sub">Needs attention</div>
        </div>
    </div>

    <!-- Charts Row 1 -->
    <div class="chart-grid chart-grid-2">
        <div class="card chart-card">
            <h4 class="card-title">MPS per Subject
                <small class="text-muted">(target line = 75%)</small>
            </h4>
            <canvas id="chartMpsSubject"></canvas>
        </div>
        <div class="card chart-card">
            <h4 class="card-title">MPS per Grade Level
                <small class="text-muted">(target line = 75%)</small>
            </h4>
            <canvas id="chartMpsGrade"></canvas>
        </div>
    </div>

    <!-- Charts Row 2 -->
    <div class="chart-grid chart-grid-2">
        <div class="card chart-card">
            <h4 class="card-title">Mastery Level Distribution per Section</h4>
            <canvas id="chartMastery"></canvas>
        </div>
        <div class="card chart-card">
            <h4 class="card-title">NPWRM per Section</h4>
            <canvas id="chartNpwrm"></canvas>
        </div>
    </div>

    <!-- Charts Row 3 -->
    <div class="chart-grid chart-grid-2">
        <div class="card chart-card">
            <h4 class="card-title">Least-Mastered Items <small class="text-muted">(lowest % correct)</small></h4>
            <canvas id="chartLeastMastered"></canvas>
        </div>
        <div class="card chart-card">
            <h4 class="card-title">MPS Trend Across Assessments</h4>
            <canvas id="chartMpsTrend"></canvas>
        </div>
    </div>

    <!-- Least-Mastered Competencies -->
    <div class="card" id="compChartCard" style="display:none">
        <h4 class="card-title">Least-Mastered Learning Competencies
            <small class="text-muted">(items with competency mapping only)</small>
        </h4>
        <canvas id="chartCompetency" height="220"></canvas>
        <div id="compDrillTable" class="table-scroll" style="margin-top:1rem"></div>
    </div>

    <!-- Item % Heatmap (HTML table) -->
    <div class="card">
        <h4 class="card-title">Item %-Correct Heatmap
            <small class="text-muted">— Red &lt;50% · Yellow 50–74% · Green ≥75%</small>
        </h4>
        <div class="table-scroll">
            <table id="heatmapTable" class="data-table heatmap-table"></table>
        </div>
    </div>
</div>

<!-- ============================================================
     PANEL: SUBMISSIONS
============================================================ -->
<div id="panel-submissions" class="admin-panel" style="display:none">
    <div class="card">
        <h3 class="card-title">Submission Compliance</h3>
        <div class="table-scroll">
            <table id="complianceTable" class="data-table">
                <thead><tr><th>Teacher</th><th>Assessment</th><th>Subject</th><th>Term</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody id="complianceTbody"></tbody>
            </table>
        </div>
    </div>

    <!-- Return Remarks Modal -->
    <div id="returnModal" class="modal-overlay" style="display:none">
        <div class="modal-box">
            <h3>Return with Remarks</h3>
            <form id="frmReturn">
                <input type="hidden" id="returnAsmtId" name="assessment_id">
                <div class="form-group">
                    <label>Remarks / Feedback</label>
                    <textarea name="remarks" rows="4" required placeholder="Describe what needs to be corrected..."></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-warning">Return Assessment</button>
                    <button type="button" class="btn btn-outline" onclick="closeReturnModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================
     PANEL: TEACHER ACCOUNTS
============================================================ -->
<div id="panel-teachers" class="admin-panel" style="display:none">
    <div class="card">
        <h3 class="card-title">Pending Approval</h3>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Username</th><th>Grade Levels</th><th>Subjects</th><th>Registered</th><th>Actions</th></tr></thead>
                <tbody id="pendingTbody"></tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <h3 class="card-title">Active Teachers</h3>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Username</th><th>Grade Levels</th><th>Subjects</th><th>Actions</th></tr></thead>
                <tbody id="activeTbody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================
     PANEL: SECTION ASSIGNMENTS
     Admin can bulk-set which sections a teacher is responsible
     for per subject. These become the pre-checked defaults when
     the teacher creates a new assessment for that subject.
============================================================ -->
<div id="panel-assignments" class="admin-panel" style="display:none">
    <div class="card">
        <h3 class="card-title">Section Assignments</h3>
        <p class="text-muted" style="margin-bottom:1rem">
            Set the default sections for a teacher's subject load. These appear pre-checked
            when the teacher opens "Create New Assessment" for that subject.
        </p>

        <div class="form-row" style="align-items:flex-end;gap:1rem;margin-bottom:1rem">
            <div class="form-group" style="margin:0">
                <label>Teacher</label>
                <select id="assignTeacher" onchange="adminLoadTeacherSubjects(this.value)">
                    <option value="">— select teacher —</option>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label>Subject</label>
                <select id="assignSubject" disabled onchange="adminLoadAssignmentSections()">
                    <option value="">— select subject —</option>
                </select>
            </div>
        </div>

        <div id="assignSectionWrap" style="display:none">
            <label style="display:block;font-weight:600;margin-bottom:.5rem">
                Sections
                <small class="text-muted" id="assignSectionHint"></small>
            </label>
            <div id="assignSectionChecklist" class="checklist checklist--grid"></div>
            <div style="margin-top:1rem">
                <button class="btn btn-primary" onclick="adminSaveAssignments()">Save Assignments</button>
                <span id="assignSaveMsg" style="margin-left:1rem;font-size:.9rem"></span>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     PANEL: LEARNING COMPETENCIES
============================================================ -->
<div id="panel-competencies" class="admin-panel" style="display:none">
    <div class="card">
        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem">
            <h3 class="card-title" style="margin:0">Learning Competencies</h3>
            <select id="compSubjectFilter" onchange="loadCompetencies()" style="min-width:180px">
                <option value="">— Select Subject —</option>
                <?php foreach ($subjectsAll as $s): ?>
                <option value="<?= $s['id'] ?>" data-grade="<?= $s['grade_level'] ?>">
                    <?= h($s['name']) ?> (G<?= $s['grade_level'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <select id="compTermFilter" onchange="loadCompetencies()" style="min-width:140px">
                <option value="">All Terms</option>
                <?php foreach ($terms as $t): ?>
                <option value="<?= $t['id'] ?>">Term <?= $t['term_no'] ?> — <?= h($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline" onclick="openCompCsvModal()">&#x2B06; Bulk Import CSV</button>
        </div>

        <div id="compEmptyMsg" class="text-muted" style="padding:.5rem 0">
            Select a subject above to view or add competencies.
        </div>

        <div id="compTableWrap" style="display:none">
            <div class="table-scroll">
                <table class="data-table" id="compTable">
                    <thead><tr><th style="width:120px">Code</th><th>Description</th><th style="width:90px">Actions</th></tr></thead>
                    <tbody id="compTbody"></tbody>
                </table>
            </div>
            <div class="comp-add-row">
                <h4 style="margin:1rem 0 .5rem;font-size:.9rem;color:var(--maroon)">Add Competency</h4>
                <div class="form-row" style="align-items:flex-end">
                    <div class="form-group" style="max-width:160px">
                        <label>Code <small class="text-muted">(optional)</small></label>
                        <input type="text" id="newCompCode" placeholder="e.g. AP10-KIH-Ia-1" maxlength="60">
                    </div>
                    <div class="form-group" style="flex:3">
                        <label>Description <span class="req">*</span></label>
                        <input type="text" id="newCompDesc" placeholder="Learning competency description" maxlength="500">
                    </div>
                    <div class="form-group" style="flex:none">
                        <button class="btn btn-primary btn-sm" onclick="adminAddCompetency()">Add</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL: BULK CSV IMPORT (competencies)
============================================================ -->
<div id="compCsvModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeCompCsvModal()">
    <div class="modal-box modal-box--wide">
        <h3>Bulk Import Competencies via CSV</h3>
        <p class="text-muted" style="font-size:.85rem;margin-bottom:.75rem">
            One competency per line. Two columns (comma-separated): <code>Code, Description</code>.
            Code is optional — paste a single column for description-only import.
        </p>
        <textarea id="compCsvText" rows="10" placeholder="AP10-KIH-Ia-1, The student explains the political causes of the First World War&#10;AP10-KIH-Ia-2, The student analyzes the role of imperialism in WW1" style="width:100%;font-family:monospace;font-size:.82rem"></textarea>
        <div id="compCsvResult" style="margin-top:.5rem;font-size:.85rem"></div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn btn-primary" onclick="doCompCsvImport()">Import</button>
            <button class="btn btn-outline" onclick="closeCompCsvModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL: CREATE ASSESSMENT (4-step)
============================================================ -->
<div id="createAsmtModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeCreateAsmtModal()">
    <div class="modal-box modal-box--wide">

        <!-- Step indicator -->
        <div class="step-indicator">
            <div class="step-dot" id="stepDot1">1</div>
            <div class="step-line"></div>
            <div class="step-dot" id="stepDot2">2</div>
            <div class="step-line"></div>
            <div class="step-dot" id="stepDot3">3</div>
            <div class="step-line"></div>
            <div class="step-dot" id="stepDot4">4</div>
        </div>
        <div class="step-label-row">
            <span>Type</span><span>Scope</span><span>Competencies</span><span>Review</span>
        </div>

        <!-- Step 1: Type -->
        <div id="createStep1">
            <h3 style="margin-bottom:1rem;color:var(--maroon-dark)">Step 1 — Assessment Type</h3>
            <div class="type-card-row">
                <div class="type-card" id="typeCardSummative" onclick="selectAsmtType('summative',25)">
                    <div class="type-card-icon">📝</div>
                    <div class="type-card-name">Summative Test</div>
                    <div class="type-card-meta">Default: 25 items</div>
                </div>
                <div class="type-card" id="typeCardTerm_exam" onclick="selectAsmtType('term_exam',50)">
                    <div class="type-card-icon">📋</div>
                    <div class="type-card-name">Term Exam</div>
                    <div class="type-card-meta">Default: 50 items</div>
                </div>
            </div>
            <div class="form-group" style="max-width:200px;margin-top:1rem">
                <label>Total Items <small class="text-muted">(override if needed)</small></label>
                <input type="number" id="asmtTotalItems" min="1" max="200" value="25">
            </div>
        </div>

        <!-- Step 2: Scope -->
        <div id="createStep2" style="display:none">
            <h3 style="margin-bottom:1rem;color:var(--maroon-dark)">Step 2 — Scope</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>School Year <span class="req">*</span></label>
                    <select id="asmtSY" onchange="loadTermsForModal()">
                        <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= $sy['id'] ?>" <?= $sy['id'] == $activeSYId ? 'selected' : '' ?>>
                            SY <?= h($sy['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Term <span class="req">*</span></label>
                    <select id="asmtTerm">
                        <option value="">— select —</option>
                        <?php foreach ($terms as $t): ?>
                        <option value="<?= $t['id'] ?>">Term <?= $t['term_no'] ?> — <?= h($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Subject <span class="req">*</span></label>
                <select id="asmtSubject">
                    <option value="">— select subject —</option>
                    <?php foreach ($subjectsAll as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= h($s['name']) ?> (Grade <?= $s['grade_level'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group" style="flex:3">
                    <label>Assessment Title <span class="req">*</span></label>
                    <input type="text" id="asmtTitle" placeholder="e.g. 1st Summative Test" maxlength="200">
                </div>
                <div class="form-group">
                    <label>Date Given</label>
                    <input type="date" id="asmtDate">
                </div>
            </div>
        </div>

        <!-- Step 3: Competency Mapping -->
        <div id="createStep3" style="display:none">
            <h3 style="margin-bottom:.5rem;color:var(--maroon-dark)">Step 3 — Competency Mapping</h3>
            <p class="text-muted" style="font-size:.84rem;margin-bottom:1rem">
                Map each item to the learning competency it measures. Items may be left unassigned.
            </p>

            <!-- Bulk assign tool -->
            <div class="bulk-assign-bar">
                <label>Bulk assign:</label>
                <select id="bulkCompSel" style="flex:2"></select>
                <span style="white-space:nowrap">to items</span>
                <input type="number" id="bulkFrom" min="1" style="width:60px" placeholder="1">
                <span>–</span>
                <input type="number" id="bulkTo"   min="1" style="width:60px" placeholder="5">
                <button class="btn btn-sm btn-outline" onclick="applyBulkAssign()">Apply</button>
            </div>

            <div id="compMappingGrid" style="max-height:340px;overflow-y:auto;margin-top:.75rem"></div>
            <div id="compMappingSummary" class="comp-summary" style="margin-top:.75rem"></div>
        </div>

        <!-- Step 4: Review -->
        <div id="createStep4" style="display:none">
            <h3 style="margin-bottom:1rem;color:var(--maroon-dark)">Step 4 — Review &amp; Create</h3>
            <div id="asmtReviewContent"></div>
        </div>

        <!-- Navigation buttons -->
        <div class="form-actions" style="margin-top:1.5rem;border-top:1px solid var(--c-border);padding-top:1rem">
            <button id="btnCreatePrev" class="btn btn-outline" onclick="createAsmtPrev()" style="display:none">← Back</button>
            <span id="createAsmtErr" class="text-muted" style="font-size:.85rem;flex:1"></span>
            <button id="btnCreateNext" class="btn btn-primary" onclick="createAsmtNext()">Next →</button>
            <button id="btnCreateDone" class="btn btn-submit-final" onclick="doCreateAssessment()" style="display:none">&#x2713; Create Assessment</button>
            <button class="btn btn-outline" onclick="closeCreateAsmtModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
const BASE_URL        = <?= json_encode(BASE_URL) ?>;
const CSRF_TOKEN      = <?= json_encode($csrf) ?>;
const MASTERY_BANDS   = <?= json_encode(array_map(fn($b) => ['label'=>$b['label'],'min'=>$b['min'],'max'=>$b['max']], MASTERY_BANDS)) ?>;
const BAND_KEYS       = <?= json_encode(array_keys(MASTERY_BANDS)) ?>;
const BAND_COLORS     = {
    M:'#1a7a4a', CAM:'#52b788', MTM:'#95d5b2',
    AVR:'#ffd166', LM:'#ef8c44', VLM:'#e55934', ANM:'#9d0208'
};
const SUBJECTS_ALL    = <?= json_encode($subjectsAll) ?>;
</script>
<script src="<?= BASE_URL ?>script.js"></script>
</body>
</html>
