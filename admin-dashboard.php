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

$subjects  = $pdo->query("SELECT DISTINCT name FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
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
    <button class="admin-tab active" data-panel="analytics"    onclick="adminTab(this,'analytics')">Analytics</button>
    <button class="admin-tab" data-panel="submissions"         onclick="adminTab(this,'submissions')">Submissions</button>
    <button class="admin-tab" data-panel="teachers"            onclick="adminTab(this,'teachers')">Teacher Accounts</button>
    <button class="admin-tab" data-panel="assignments"         onclick="adminTab(this,'assignments')">Section Assignments</button>
</div>

<!-- ============================================================
     PANEL: ANALYTICS
============================================================ -->
<div id="panel-analytics" class="admin-panel">

    <!-- Filters -->
    <div class="filter-bar card">
        <strong>Filters:</strong>
        <select id="f_sy" onchange="refreshDashboard()">
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
        <select id="f_grade" onchange="refreshDashboard()">
            <option value="">All Grades</option>
            <?php foreach ($gradeLevels as $gl): ?>
            <option value="<?= $gl ?>">Grade <?= $gl ?></option>
            <?php endforeach; ?>
        </select>
        <select id="f_subject" onchange="refreshDashboard()">
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
            <h4 class="card-title">MPS per Section
                <small class="text-muted">(target line = 75%)</small>
            </h4>
            <canvas id="chartMpsSection"></canvas>
        </div>
        <div class="card chart-card">
            <h4 class="card-title">MPS per Subject / Grade</h4>
            <canvas id="chartMpsSubject"></canvas>
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

<script>
const BASE_URL        = <?= json_encode(BASE_URL) ?>;
const CSRF_TOKEN      = <?= json_encode($csrf) ?>;
const MASTERY_BANDS   = <?= json_encode(array_map(fn($b) => ['label'=>$b['label'],'min'=>$b['min'],'max'=>$b['max']], MASTERY_BANDS)) ?>;
const BAND_KEYS       = <?= json_encode(array_keys(MASTERY_BANDS)) ?>;
const BAND_COLORS     = {
    M:'#1a7a4a', CAM:'#52b788', MTM:'#95d5b2',
    AVR:'#ffd166', LM:'#ef8c44', VLM:'#e55934', ANM:'#9d0208'
};
</script>
<script src="<?= BASE_URL ?>script.js"></script>
</body>
</html>
