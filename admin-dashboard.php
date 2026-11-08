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
    $ts = $pdo->prepare("SELECT id, term_no, name, start_date, end_date FROM terms WHERE school_year_id=? ORDER BY term_no");
    $ts->execute([$activeSYId]);
    $terms = $ts->fetchAll();
} else {
    $terms = [];
}

$subjects    = $pdo->query("SELECT DISTINCT name FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$subjectsAll = $pdo->query("SELECT s.id, s.name, s.grade_level, s.grade_level_id FROM subjects s ORDER BY s.grade_level, s.name")->fetchAll();
$gradeLevels = $pdo->query(
    "SELECT level FROM grade_levels ORDER BY level"
)->fetchAll(PDO::FETCH_COLUMN);
$settingsYears = $pdo->query("SELECT id, name, is_active FROM school_years ORDER BY id DESC")->fetchAll();
$settingsGrades = $pdo->query("SELECT id, level FROM grade_levels ORDER BY level")->fetchAll();
$settingsSections = $pdo->query("SELECT s.id, s.name, s.grade_level, s.grade_level_id, s.school_year_id, sy.name AS school_year FROM sections s JOIN school_years sy ON sy.id=s.school_year_id ORDER BY sy.id DESC, s.grade_level, s.name")->fetchAll();

// All terms across all school years (for edit modal + assessments filter)
$allTerms = $pdo->query(
    "SELECT t.id, t.term_no, t.name AS term_name, t.start_date, t.end_date,
            sy.id AS sy_id, sy.name AS sy_name
     FROM terms t JOIN school_years sy ON sy.id = t.school_year_id
     ORDER BY sy.id DESC, t.term_no"
)->fetchAll();
foreach ($allTerms as &$_t) {
    $_t['label'] = termLabel($_t) . ' — SY ' . $_t['sy_name'];
}
unset($_t);
?>
<?php
$pageTitle = 'Admin Dashboard';
$activeNavPage = 'dashboard';
$layoutBodyClass = 'admin-dashboard layout-fixed sidebar-expand-lg bg-body-tertiary';
$layoutHeadAssets = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.6.0/dist/css/tabulator_bootstrap5.min.css"><script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>';
require __DIR__ . '/includes/master_layout_start.php';
?>

<div class="mps-page-heading">
    <div>
        <h1 id="mpsPageTitle">Executive Academic Overview</h1>
    </div>
    <button id="headerCreateAssessment" class="btn btn-primary" style="display:none" onclick="openCreateAsmtModal()"><i class="bi bi-plus-lg"></i> Create Assessment</button>
</div>

<!-- ============================================================
     PANEL: SETTINGS / REFERENCE TABLES
============================================================ -->
<div id="panel-settings" class="admin-panel" style="display:none">
    <div class="mps-metric-grid mps-settings-metrics">
        <div class="mps-metric-card"><span>School Years</span><strong><?= count($settingsYears) ?></strong><small>Academic cycles</small></div>
        <div class="mps-metric-card"><span>Grade Levels</span><strong><?= count($settingsGrades) ?></strong><small>Configured grades</small></div>
        <div class="mps-metric-card"><span>Sections</span><strong><?= count($settingsSections) ?></strong><small>Class allocations</small></div>
        <div class="mps-metric-card"><span>Subjects</span><strong><?= count($subjectsAll) ?></strong><small>Learning areas</small></div>
    </div>
    <div class="mps-settings-grid">
    <div class="card">
        <div class="settings-head"><div><h3 class="card-title" style="margin:0">School Years</h3><p class="text-muted" style="margin:.25rem 0 0">Use the ID when encoding year barcodes.</p></div><button class="btn btn-primary btn-sm" onclick="openCatalogForm('year')">+ Add Year</button></div>
        <div class="table-scroll table-responsive"><table class="data-table table align-middle"><thead><tr><th>ID</th><th>School Year</th><th>Active</th><th>Actions</th></tr></thead><tbody><?php foreach ($settingsYears as $row): ?><tr><td><?= (int)$row['id'] ?></td><td><?= h($row['name']) ?></td><td><?= $row['is_active'] ? 'Yes' : 'No' ?></td><td><button class="btn btn-sm btn-outline-secondary" onclick='openCatalogForm("year",<?= (int)$row['id'] ?>,<?= json_encode($row['name']) ?>,<?= (int)$row['is_active'] ?>)'>Edit</button></td></tr><?php endforeach; ?></tbody></table></div>
    </div>
    <div class="card">
        <div class="settings-head"><div><h3 class="card-title" style="margin:0">Grades</h3><p class="text-muted" style="margin:.25rem 0 0">Grade IDs are stable lookup values for scanning.</p></div><button class="btn btn-primary btn-sm" onclick="openCatalogForm('grade')">+ Add Grade</button></div>
        <div class="table-scroll table-responsive"><table class="data-table table align-middle"><thead><tr><th>ID</th><th>Grade Level</th><th>Actions</th></tr></thead><tbody><?php foreach ($settingsGrades as $row): ?><tr><td><?= (int)$row['id'] ?></td><td>Grade <?= (int)$row['level'] ?></td><td><button class="btn btn-sm btn-outline-secondary" onclick="openCatalogForm('grade',<?= (int)$row['id'] ?>,<?= (int)$row['level'] ?>)">Edit</button></td></tr><?php endforeach; ?></tbody></table></div>
    </div>
    <div class="card">
        <div class="settings-head"><div><h3 class="card-title" style="margin:0">Sections</h3><p class="text-muted" style="margin:.25rem 0 0">Section IDs are used on student answer sheet barcodes.</p></div><button class="btn btn-primary btn-sm" onclick="openCatalogForm('section')">+ Add Section</button></div>
        <div class="table-scroll table-responsive"><table class="data-table table align-middle"><thead><tr><th>ID</th><th>Section</th><th>Grade</th><th>School Year</th><th>Actions</th></tr></thead><tbody><?php foreach ($settingsSections as $row): ?><tr><td><?= (int)$row['id'] ?></td><td><?= h($row['name']) ?></td><td><?= (int)$row['grade_level'] ?></td><td><?= h($row['school_year']) ?></td><td><button class="btn btn-sm btn-outline-secondary" onclick='openCatalogForm("section",<?= (int)$row['id'] ?>,<?= json_encode($row['name']) ?>,<?= (int)$row['grade_level_id'] ?>,<?= (int)$row['school_year_id'] ?>)'>Edit</button></td></tr><?php endforeach; ?></tbody></table></div>
    </div>
    <div class="card">
        <div class="settings-head"><div><h3 class="card-title" style="margin:0">Subjects</h3><p class="text-muted" style="margin:.25rem 0 0">Subject IDs identify subjects in assessments and scanned forms.</p></div><button class="btn btn-primary btn-sm" onclick="openCatalogForm('subject')">+ Add Subject</button></div>
        <div class="table-scroll table-responsive"><table class="data-table table align-middle"><thead><tr><th>ID</th><th>Subject</th><th>Grade</th><th>Actions</th></tr></thead><tbody><?php foreach ($subjectsAll as $row): ?><tr><td><?= (int)$row['id'] ?></td><td><?= h($row['name']) ?></td><td>Grade <?= (int)$row['grade_level'] ?></td><td><button class="btn btn-sm btn-outline-secondary" onclick='openCatalogForm("subject",<?= (int)$row['id'] ?>,<?= json_encode($row['name']) ?>,<?= (int)$row['grade_level_id'] ?>)'>Edit</button></td></tr><?php endforeach; ?></tbody></table></div>
    </div>
    </div>
</div>

<div id="catalogModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeCatalogForm()">
    <div class="modal-box">
        <h3 id="catalogTitle" style="color:var(--maroon-dark);margin-bottom:1rem">Edit</h3>
        <input type="hidden" id="catalogType"><input type="hidden" id="catalogId">
        <div class="form-group" id="catalogNameWrap"><label id="catalogNameLabel">Name</label><input id="catalogName" maxlength="120"></div>
        <div class="form-group" id="catalogLevelWrap" style="display:none"><label>Grade Level</label><input id="catalogLevel" type="number" min="1" max="20"></div>
        <div class="form-group" id="catalogGradeWrap" style="display:none"><label>Grade</label><select id="catalogGrade"><?php foreach ($settingsGrades as $grade): ?><option value="<?= (int)$grade['id'] ?>">Grade <?= (int)$grade['level'] ?> (ID <?= (int)$grade['id'] ?>)</option><?php endforeach; ?></select></div>
        <div class="form-group" id="catalogYearWrap" style="display:none"><label>School Year</label><select id="catalogYear"><?php foreach ($settingsYears as $year): ?><option value="<?= (int)$year['id'] ?>"><?= h($year['name']) ?></option><?php endforeach; ?></select></div>
        <label id="catalogActiveWrap" style="display:none"><input id="catalogActive" type="checkbox"> Set as active school year</label>
        <p id="catalogError" class="text-muted" role="alert"></p>
        <div class="form-actions"><button class="btn btn-primary" onclick="saveCatalogRecord()">Save</button><button class="btn btn-outline-secondary" onclick="closeCatalogForm()">Cancel</button></div>
    </div>
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
            <option value="<?= $t['id'] ?>"><?= h(termLabel($t)) ?></option>
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
        <button id="btnRefresh" class="btn btn-sm btn-outline-secondary" onclick="refreshDashboard()" disabled>Refresh</button>
        <button id="btnExportSubject" class="btn btn-sm btn-outline-secondary" onclick="exportSubjectReport()" disabled
                title="Select a subject to enable export">Export Subject Report</button>
    </div>

    <!-- Empty state: shown until a Subject is selected -->
    <div id="dashboardEmptyState" class="card" style="text-align:center;padding:3rem 1rem;margin-top:.5rem">
        <div style="font-size:2.5rem;margin-bottom:.75rem">📊</div>
        <p style="font-size:1.05rem;font-weight:600;color:var(--maroon-dark);margin:0 0 .4rem">Select a Subject to view analytics</p>
        <p style="font-size:.875rem;color:var(--c-muted);margin:0">Choose a subject from the filter above to load MPS and Item Analysis data.</p>
    </div>

    <!-- Dashboard content: hidden until a Subject is selected -->
    <div id="dashboardContent" style="display:none">

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="card kpi-card">
            <div class="kpi-label">Overall MPS</div>
            <div class="kpi-value" id="kpi_mps">—</div>
            <div class="kpi-sub">Target: 75%</div>
            <div class="kpi-bar"><div class="kpi-bar-fill" id="kpi_mps_bar"></div><div class="kpi-bar-target"></div></div>
        </div>
        <div class="card kpi-card">
            <div class="kpi-label">Total Examinees</div>
            <div class="kpi-value" id="kpi_examinees">—</div>
        </div>
        <div class="card kpi-card">
            <div class="kpi-label">Submitted Assessments</div>
            <div class="kpi-value" id="kpi_submitted">—</div>
        </div>
        <div class="card kpi-card kpi-card--alert">
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
            <div style="position:relative;height:260px"><canvas id="chartMpsSubject"></canvas></div>
        </div>
        <div class="card chart-card">
            <h4 class="card-title">MPS per Grade Level
                <small class="text-muted">(target line = 75%)</small>
            </h4>
            <div style="position:relative;height:260px"><canvas id="chartMpsGrade"></canvas></div>
        </div>
    </div>

    <!-- Score Distribution per Grade Level — one small pie per grade -->
    <div class="card" style="margin-top:.75rem">
        <h4 class="card-title">Score Distribution per Grade Level
            <small class="text-muted">(7 performance bands — below 75% highlighted in red)</small>
        </h4>
        <div id="bandDistPies" style="display:flex;flex-wrap:wrap;gap:1.5rem;justify-content:center;align-items:flex-start;padding:.5rem 0"></div>
        <div id="bandDistLegend" style="margin-top:.75rem"></div>
    </div>

    <!-- Proficiency Level Distribution per Grade Level — DepEd descriptor scale -->
    <div class="card" style="margin-top:.75rem">
        <h4 class="card-title">Proficiency Level per Grade Level
            <small class="text-muted">(DepEd descriptor scale — count of learners per band, this subject)</small>
        </h4>
        <p id="plEnrollmentHint" class="text-muted" style="font-size:.82rem;margin:-.25rem 0 .5rem">
            Select a specific Term (not "All Terms") to enter enrollment and see how many learners did not take the test.
        </p>
        <div class="table-scroll table-responsive">
            <table id="plDistTable" class="data-table table align-middle"></table>
        </div>
    </div>

    <!-- Charts Row 3 -->
    <div class="chart-grid chart-grid-2">
        <div class="card chart-card">
            <h4 class="card-title">Least-Mastered Items <small class="text-muted">(lowest % correct)</small></h4>
            <div style="position:relative;height:300px"><canvas id="chartLeastMastered"></canvas></div>
        </div>
        <div class="card chart-card">
            <h4 class="card-title">MPS Trend Across Assessments</h4>
            <div style="position:relative;height:260px"><canvas id="chartMpsTrend"></canvas></div>
        </div>
    </div>

    <!-- Least-Mastered Competencies -->
    <div class="card" id="compChartCard" style="display:none">
        <h4 class="card-title">Least-Mastered Learning Competencies
            <small class="text-muted" id="compChartNote">(items with competency mapping only)</small>
        </h4>
        <div style="position:relative;height:300px"><canvas id="chartCompetency"></canvas></div>
        <div id="compDrillTable" class="table-scroll table-responsive" style="margin-top:1rem"></div>
    </div>

    <!-- Item % Heatmap (HTML table) -->
    <div class="card">
        <h4 class="card-title">Item %-Correct Heatmap
            <small class="text-muted">— Red &lt;50% · Yellow 50–74% · Green ≥75%</small>
        </h4>
        <div class="table-scroll table-responsive">
            <table id="heatmapTable" class="data-table table align-middle heatmap-table"></table>
        </div>
    </div>

    </div><!-- /#dashboardContent -->

    <!-- Enrollment Entry Modal -->
    <div id="enrollmentModal" class="modal-overlay" style="display:none">
        <div class="modal-box">
            <h3>Enrollment — Grade <span id="enrModalGrade"></span></h3>
            <p class="text-muted" style="font-size:.85rem;margin-top:-.5rem">
                <span id="enrModalTermLabel"></span> · manually entered, matches the DepEd Consolidated Proficiency report
            </p>
            <form id="frmEnrollment">
                <input type="hidden" id="enrSyId" name="school_year_id">
                <input type="hidden" id="enrGrade" name="grade_level">
                <input type="hidden" id="enrTermId" name="term_id">
                <div class="form-row">
                    <div class="form-group">
                        <label>Annual Enrollment — Male</label>
                        <input type="number" id="enrAnnualMale" name="annual_male" min="0" value="0">
                    </div>
                    <div class="form-group">
                        <label>Annual Enrollment — Female</label>
                        <input type="number" id="enrAnnualFemale" name="annual_female" min="0" value="0">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Monthly Enrollment — Male</label>
                        <input type="number" id="enrMonthlyMale" name="monthly_male" min="0" value="0">
                    </div>
                    <div class="form-group">
                        <label>Monthly Enrollment — Female</label>
                        <input type="number" id="enrMonthlyFemale" name="monthly_female" min="0" value="0">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save Enrollment</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="closeEnrollmentModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div><!-- /#panel-analytics -->

<!-- ============================================================
     PANEL: SUBMISSIONS
============================================================ -->
<div id="panel-submissions" class="admin-panel" style="display:none">
    <div class="mps-metric-grid" aria-live="polite">
        <div class="mps-metric-card"><span>Total Submissions</span><strong id="submissionMetricTotal">—</strong><small>Across all assessments</small></div>
        <div class="mps-metric-card mps-metric-success"><span>Approved</span><strong id="submissionMetricApproved">—</strong><small>Reviewed and complete</small></div>
        <div class="mps-metric-card mps-metric-warning"><span>Awaiting Review</span><strong id="submissionMetricReview">—</strong><small>Submitted by teachers</small></div>
        <div class="mps-metric-card mps-metric-critical"><span>Needs Attention</span><strong id="submissionMetricAttention">—</strong><small>Draft or returned</small></div>
    </div>
    <div class="card">
        <div style="margin-bottom:.75rem">
            <h3 class="card-title" style="margin:0 0 .75rem">Submission Compliance</h3>
            <div class="filter-grid">
            <input type="text" id="subCompSearch" placeholder="Search teacher, assessment, or subject&hellip;"
                   oninput="filterComplianceTable()">
            <select id="subCompStatusFilter" onchange="filterComplianceTable()">
                <option value="">All Statuses</option>
                <option value="draft">Draft</option>
                <option value="submitted">Submitted</option>
                <option value="approved">Approved</option>
                <option value="returned">Returned</option>
            </select>
            <select id="subCompTermFilter" onchange="filterComplianceTable()">
                <option value="">All Terms</option>
            </select>
            <select id="subCompTypeFilter" onchange="filterComplianceTable()">
                <option value="">All Types</option>
            </select>
            </div>
            <span id="subCompCount" class="text-muted" style="font-size:.85rem"></span>
        </div>
        <div class="table-scroll table-responsive">
            <table id="complianceTable" class="data-table table align-middle">
                <thead><tr><th>Teacher</th><th>Assessment</th><th>Subject</th><th>Term</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
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
                <input type="hidden" id="returnAsmtTeacherId" name="teacher_id">
                <div class="form-group">
                    <label>Remarks / Feedback</label>
                    <textarea name="remarks" rows="4" required placeholder="Describe what needs to be corrected..."></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-warning">Return Assessment</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="closeReturnModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================
     PANEL: TEACHER ACCOUNTS
============================================================ -->
<div id="panel-teachers" class="admin-panel" style="display:none">
    <div class="mps-metric-grid" aria-live="polite">
        <div class="mps-metric-card"><span>Active Faculty</span><strong id="teacherMetricActive">—</strong><small>Active teacher accounts</small></div>
        <div class="mps-metric-card mps-metric-warning"><span>Pending Approval</span><strong id="teacherMetricPending">—</strong><small>Awaiting administrator review</small></div>
        <div class="mps-metric-card mps-metric-critical"><span>Password Requests</span><strong id="teacherMetricResets">—</strong><small>Pending reset requests</small></div>
        <div class="mps-metric-card mps-metric-success"><span>Subject Areas</span><strong id="teacherMetricSubjects">—</strong><small>Distinct assigned subjects</small></div>
    </div>

    <!-- Pending reset requests — shown by JS when non-empty -->
    <div class="card" id="pendingResetCard" style="display:none;border:1.5px solid var(--c-warning)">
        <h3 class="card-title" style="color:var(--maroon-dark)">&#9888; Pending Password Reset Requests</h3>
        <p style="font-size:.875rem;color:var(--c-muted);margin-bottom:.75rem">
            Teachers who have requested a reset via the login page. Use "Reset Now" to generate a temp password and relay it to the teacher.
        </p>
        <div class="table-scroll table-responsive">
            <table class="data-table table align-middle">
                <thead><tr><th>Name / Username Submitted</th><th>Requested</th><th>Action</th></tr></thead>
                <tbody id="pendingResetTbody"></tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title">Pending Approval</h3>
        <div class="table-scroll table-responsive">
            <table class="data-table table align-middle">
                <thead><tr><th>Name</th><th>Username</th><th>Grade Levels</th><th>Subjects</th><th>Registered</th><th>Actions</th></tr></thead>
                <tbody id="pendingTbody"></tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:.75rem">
            <h3 class="card-title" style="margin:0">Active Teachers</h3>
            <button class="btn btn-primary" style="margin-left:auto" onclick="openCreateTeacherModal()">+ Add Teacher</button>
            <input type="text" id="teacherSearch" placeholder="Search name or username&hellip;"
                   oninput="filterTeacherTable()"
                   style="flex:1;min-width:160px;max-width:280px">
        </div>
        <div class="table-scroll table-responsive">
            <table class="data-table table align-middle">
                <thead><tr><th>Name</th><th>Username</th><th>Grade Levels</th><th>Subjects</th><th>Actions</th></tr></thead>
                <tbody id="activeTbody"></tbody>
            </table>
        </div>
    </div>
</div>

<div id="createTeacherModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeCreateTeacherModal()">
    <div class="modal-box modal-box--wide">
        <h3 style="color:var(--maroon-dark);margin-bottom:1rem">Add Teacher Account</h3>
        <div class="form-row">
            <div class="form-group"><label>Last Name <span class="req">*</span></label><input id="newTeacherLast" maxlength="100"></div>
            <div class="form-group"><label>First Name <span class="req">*</span></label><input id="newTeacherFirst" maxlength="100"></div>
            <div class="form-group"><label>Middle Name</label><input id="newTeacherMiddle" maxlength="100"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Username <span class="req">*</span></label><input id="newTeacherUsername" maxlength="50" autocomplete="off"></div>
            <div class="form-group"><label>Temporary Password <span class="req">*</span> <small>(min. 8 chars)</small></label><input id="newTeacherPassword" type="password" autocomplete="new-password"></div>
        </div>
        <div class="form-group"><label>Grade Level(s) <span class="req">*</span></label><div class="checklist checklist--grid"><?php foreach (AVAILABLE_GRADE_LEVELS as $gl): ?><label class="check-item"><input type="checkbox" name="new_teacher_grade" value="<?= (int)$gl ?>" onchange="renderCreateTeacherSectionAssignments()"> Grade <?= (int)$gl ?></label><?php endforeach; ?></div></div>
        <div class="form-group"><label>Subject(s) <span class="req">*</span></label><div class="checklist checklist--grid"><?php foreach (AVAILABLE_SUBJECTS as $subj): ?><label class="check-item"><input type="checkbox" name="new_teacher_subject" value="<?= h($subj) ?>" onchange="renderCreateTeacherSectionAssignments()"> <?= h($subj) ?></label><?php endforeach; ?></div></div>
        <div class="form-group"><label for="newTeacherAssignmentYear">School Year for Section Assignments</label><select id="newTeacherAssignmentYear" onchange="renderCreateTeacherSectionAssignments()"><?php foreach ($settingsYears as $year): ?><option value="<?= (int)$year['id'] ?>" <?= !empty($year['is_active']) ? 'selected' : '' ?>><?= h($year['name']) ?><?= !empty($year['is_active']) ? ' (Active)' : '' ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Section(s) by Subject</label><div id="newTeacherSectionAssignments" class="checklist" style="max-height:240px;overflow:auto"><span class="text-muted">Select grades and subjects first.</span></div></div>
        <p class="text-muted" style="font-size:.85rem">The account will be active immediately and the teacher will be asked to change this password at first sign-in.</p>
        <p id="createTeacherError" class="text-muted" role="alert"></p>
        <div class="form-actions"><button class="btn btn-primary" id="btnCreateTeacher" onclick="createTeacherAccount()">Create Account</button><button class="btn btn-outline-secondary" onclick="closeCreateTeacherModal()">Cancel</button></div>
    </div>
</div>

<!-- ============================================================
     PANEL: LEARNING COMPETENCIES
============================================================ -->
<div id="panel-competencies" class="admin-panel" style="display:none">
    <div class="mps-metric-grid mps-competency-metrics">
        <div class="mps-metric-card"><span>Competencies Loaded</span><strong id="competencyMetricCount">—</strong><small>For the selected subject and term</small></div>
        <div class="mps-metric-card"><span>Subject Scope</span><strong id="competencyMetricSubject">Select subject</strong><small>Choose a learning area below</small></div>
        <div class="mps-metric-card"><span>Term Scope</span><strong id="competencyMetricTerm">All terms</strong><small>Choose a term to add entries</small></div>
    </div>
    <div class="card">
        <div class="comp-filter-row">
            <h3 class="card-title" style="margin:0">Learning Competencies</h3>
            <div class="comp-filter-selects">
            <select id="compSubjectFilter" onchange="loadCompetencies()">
                <option value="">— Select Subject —</option>
                <?php foreach ($subjectsAll as $s): ?>
                <option value="<?= $s['id'] ?>" data-grade="<?= $s['grade_level'] ?>">
                    <?= h($s['name']) ?> (G<?= $s['grade_level'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <select id="compTermFilter" onchange="loadCompetencies(); updateCompAddState();">
                <option value="">All Terms</option>
                <?php foreach ($terms as $t): ?>
                <option value="<?= $t['id'] ?>"><?= h(termLabel($t)) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-secondary" id="btnCompCsv" onclick="openCompCsvModal()" disabled
                    title="Select a subject and term first">&#x2B06; Bulk Import CSV</button>
            </div><!-- /.comp-filter-selects -->
        </div><!-- /.comp-filter-row -->

        <div id="compEmptyMsg" class="text-muted" style="padding:.5rem 0">
            Select a subject above to view or add competencies.
        </div>

        <div id="compTableWrap" style="display:none">
            <div class="table-scroll table-responsive">
                <table class="data-table table align-middle" id="compTable">
                    <colgroup>
                        <col style="width:130px">
                        <col>
                        <col style="width:100px">
                    </colgroup>
                    <thead><tr><th>Code</th><th>Description</th><th style="text-align:right">Actions</th></tr></thead>
                    <tbody id="compTbody"></tbody>
                </table>
            </div>

            <!-- shown when subject is selected but no specific term chosen -->
            <p id="compAddHint" class="comp-add-hint" style="display:none">
                ← Select a specific <strong>Term</strong> above to add competencies.
            </p>

            <div class="comp-add-row" id="compAddRow" style="display:none">
                <h4 style="margin:1rem 0 .5rem;font-size:.9rem;color:var(--maroon)">Add Competency</h4>
                <div class="form-row comp-add-form-row">
                    <div class="form-group" style="flex:0 0 190px">
                        <label>Code <small class="text-muted">(optional)</small></label>
                        <input type="text" id="newCompCode" placeholder="e.g. AP10-KIH-Ia-1" maxlength="60">
                    </div>
                    <div class="form-group" style="flex:1;min-width:200px">
                        <label>Description <span class="req">*</span></label>
                        <input type="text" id="newCompDesc" placeholder="Learning competency description" maxlength="500">
                    </div>
                    <div class="form-group" style="flex:none;align-self:flex-end">
                        <button class="btn btn-primary btn-sm" onclick="adminAddCompetency()">Add</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     PANEL: ASSESSMENTS
============================================================ -->
<div id="panel-assessments" class="admin-panel" style="display:none">

    <div class="mps-metric-grid" aria-live="polite">
        <div class="mps-metric-card"><span>Assessments</span><strong id="assessmentMetricCount">—</strong><small>Matching current filters</small></div>
        <div class="mps-metric-card"><span>Total Items</span><strong id="assessmentMetricItems">—</strong><small>Across matching assessments</small></div>
        <div class="mps-metric-card mps-metric-success"><span>Sections Encoded</span><strong id="assessmentMetricSections">—</strong><small>Across matching assessments</small></div>
        <div class="mps-metric-card"><span>Teachers Encoding</span><strong id="assessmentMetricTeachers">—</strong><small>Across matching assessments</small></div>
    </div>

    <!-- Filter bar -->
    <div class="card filter-bar">
        <strong>Filters:</strong>
        <select id="af_sy" onchange="loadAsmtTermFilter()">
            <option value="">All SYs</option>
            <?php foreach ($schoolYears as $sy): ?>
            <option value="<?= $sy['id'] ?>" <?= $sy['id'] == $activeSYId ? 'selected' : '' ?>>
                SY <?= h($sy['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select id="af_term">
            <option value="">All Terms</option>
            <?php foreach ($allTerms as $t): ?>
            <option value="<?= $t['id'] ?>"><?= h($t['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="af_subject">
            <option value="">All Subjects</option>
            <?php foreach ($subjectsAll as $s): ?>
            <option value="<?= $s['id'] ?>"><?= h($s['name']) ?> G<?= $s['grade_level'] ?></option>
            <?php endforeach; ?>
        </select>
        <select id="af_grade">
            <option value="">All Grades</option>
            <?php foreach ($gradeLevels as $gl): ?>
            <option value="<?= $gl ?>">Grade <?= $gl ?></option>
            <?php endforeach; ?>
        </select>
        <select id="af_type">
            <option value="">All Types</option>
            <option value="summative">Summative</option>
            <option value="term_exam">Term Exam</option>
        </select>
        <input type="text" id="af_search" placeholder="Search title…"
               onkeydown="if(event.key==='Enter')loadAdminAssessments()">
        <button class="btn btn-sm btn-outline-secondary" onclick="loadAdminAssessments()">Search</button>
    </div>

    <!-- Table -->
    <div class="card">
        <div id="asmtListEmpty" class="text-muted" style="display:none;padding:.5rem 0">
            No admin-created assessments match this filter.
        </div>
        <div id="asmtListWrap" style="display:none">
            <div id="asmtListTable" class="assessment-tabulator" aria-label="Assessments"></div>
        </div>
        <div id="asmtListLoading" class="text-muted" style="padding:.5rem 0">
            Click <strong>Search</strong> or switch to this tab to load assessments.
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL: EDIT ASSESSMENT
============================================================ -->
<div id="editAsmtModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeEditAsmtModal()">
    <div class="modal-box modal-box--wide modal-box--tall">

        <!-- Pinned header -->
        <div class="modal-tall-header">
            <h3 style="color:var(--maroon-dark)">Edit Assessment</h3>
        </div>

        <!-- Scrollable body -->
        <div class="modal-tall-body">

            <!-- Safe fields -->
            <div class="form-row">
                <div class="form-group" style="flex:3">
                    <label>Title <span class="req">*</span></label>
                    <input type="text" id="editTitle" maxlength="200">
                </div>
                <div class="form-group">
                    <label>Date Given</label>
                    <input type="date" id="editDate">
                </div>
            </div>
            <div class="form-group" style="max-width:320px">
                <label>Term <span class="req">*</span></label>
                <select id="editTerm">
                    <option value="">— select —</option>
                    <?php foreach ($allTerms as $t): ?>
                    <option value="<?= $t['id'] ?>"><?= h($t['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Conditionally locked destructive fields -->
            <div class="form-row" style="margin-top:.25rem">
                <div class="form-group" style="max-width:200px">
                    <label>Type</label>
                    <select id="editType">
                        <option value="summative">Summative Test</option>
                        <option value="term_exam">Term Exam</option>
                    </select>
                </div>
                <div class="form-group" style="max-width:160px">
                    <label>Total Items</label>
                    <input type="number" id="editTotalItems" min="1" max="200">
                </div>
            </div>
            <div id="editDataLockNotice" class="edit-lock-notice" style="display:none"></div>

            <!-- Competency mapping -->
            <div style="margin-top:1.25rem;border-top:1px solid var(--c-border);padding-top:1rem">
                <h4 style="margin:0 0 .5rem;font-size:.9rem;color:var(--maroon)">Competency Mapping</h4>
                <p class="text-muted" style="font-size:.82rem;margin-bottom:.75rem">
                    Map each item to its learning competency. Safe to edit at any time — does not affect encoded scores.
                </p>
                <div class="bulk-assign-bar">
                    <label>Bulk assign:</label>
                    <select id="editBulkCompSel" style="flex:2"></select>
                    <span style="white-space:nowrap">to items</span>
                    <input type="number" id="editBulkFrom" min="1" style="width:60px" placeholder="1">
                    <span>–</span>
                    <input type="number" id="editBulkTo" min="1" style="width:60px">
                    <button class="btn btn-sm btn-outline-secondary" onclick="applyEditBulkAssign()">Apply</button>
                </div>
                <div id="editCompMappingGrid" style="overflow-y:auto;margin-top:.75rem">
                    <p class="text-muted">Loading…</p>
                </div>
                <div id="editCompMappingSummary" class="comp-summary" style="margin-top:.5rem"></div>
            </div>

        </div><!-- /.modal-tall-body -->

        <!-- Pinned footer -->
        <div class="modal-tall-footer">
            <button id="btnSaveEdit" class="btn btn-primary" onclick="saveEditAsmt()">Save Changes</button>
            <span id="editAsmtErr" style="font-size:.84rem;color:var(--c-danger);flex:1"></span>
            <button class="btn btn-outline-secondary" onclick="closeEditAsmtModal()">Cancel</button>
        </div>

    </div>
</div>

<!-- ============================================================
     MODAL: DELETE ASSESSMENT (with blast radius)
============================================================ -->
<div id="deleteAsmtModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeDeleteAsmtModal()">
    <div class="modal-box">
        <h3 style="color:var(--maroon-dark);margin-bottom:1rem">Delete Assessment?</h3>
        <div id="deleteAsmtInfo">
            <p class="text-muted">Loading…</p>
        </div>
        <div id="deleteAsmtConfirmBox" style="display:none;margin-top:1rem">
            <p style="font-size:.88rem">Type <strong>DELETE</strong> to confirm permanent deletion:</p>
            <input type="text" id="deleteConfirmInput" placeholder="Type DELETE"
                   style="width:100%;margin-top:.35rem"
                   oninput="document.getElementById('btnConfirmDelete').disabled = this.value !== 'DELETE'">
        </div>
        <div class="form-actions" style="margin-top:1.25rem">
            <button id="btnConfirmDelete" class="btn btn-danger" onclick="confirmDeleteAsmtById()">
                &#x1F5D1; Delete
            </button>
            <span id="deleteAsmtErr" style="font-size:.84rem;color:var(--c-danger);flex:1"></span>
            <button class="btn btn-outline-secondary" onclick="closeDeleteAsmtModal()">Cancel</button>
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
            <button class="btn btn-outline-secondary" onclick="closeCompCsvModal()">Cancel</button>
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
                <button class="btn btn-sm btn-outline-secondary" onclick="applyBulkAssign()">Apply</button>
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
            <button id="btnCreatePrev" class="btn btn-outline-secondary" onclick="createAsmtPrev()" style="display:none">← Back</button>
            <span id="createAsmtErr" class="text-muted" style="font-size:.85rem;flex:1"></span>
            <button id="btnCreateNext" class="btn btn-primary" onclick="createAsmtNext()">Next →</button>
            <button id="btnCreateDone" class="btn btn-primary" onclick="doCreateAssessment()" style="display:none">&#x2713; Create Assessment</button>
            <button class="btn btn-outline-secondary" onclick="closeCreateAsmtModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
const BASE_URL        = <?= json_encode(BASE_URL) ?>;
const CSRF_TOKEN      = <?= json_encode($csrf) ?>;
const CATALOG_GRADES = <?= json_encode($settingsGrades) ?>;
const CATALOG_YEARS = <?= json_encode($settingsYears) ?>;
const MASTERY_BANDS   = <?= json_encode(array_map(fn($b) => ['label'=>$b['label'],'min'=>$b['min'],'max'=>$b['max']], MASTERY_BANDS)) ?>;
const BAND_KEYS       = <?= json_encode(array_keys(MASTERY_BANDS)) ?>;
const BAND_COLORS     = {
    M:'#1a7a4a', CAM:'#52b788', MTM:'#95d5b2',
    AVR:'#ffd166', LM:'#ef8c44', VLM:'#e55934', ANM:'#9d0208'
};
const PROFICIENCY_LEVELS = <?= json_encode(array_map(fn($b) => ['label'=>$b['label'],'min'=>$b['min'],'max'=>$b['max']], PROFICIENCY_LEVELS)) ?>;
const PL_KEYS             = <?= json_encode(array_keys(PROFICIENCY_LEVELS)) ?>;
const PL_COLORS           = {
    O:'#1a7a4a', VS:'#52b788', S:'#ffd166', FS:'#ef8c44', DNME:'#9d0208'
};
const SUBJECTS_ALL    = <?= json_encode($subjectsAll) ?>;
const ALL_TERMS       = <?= json_encode($allTerms) ?>;
const AVAILABLE_SUBJECTS_LIST     = <?= json_encode(AVAILABLE_SUBJECTS) ?>;
const AVAILABLE_GRADE_LEVELS_LIST = <?= json_encode(AVAILABLE_GRADE_LEVELS) ?>;
const TEACHER_SECTION_CATALOG = <?= json_encode($settingsSections) ?>;
const SCHOOL_YEARS_CATALOG = <?= json_encode($settingsYears) ?>;
const ACTIVE_SCHOOL_YEAR_ID = <?= (int)$activeSYId ?>;
function openCatalogForm(type, id = 0, value = '', gradeId = 0, yearId = 0) {
    document.getElementById('catalogType').value = type;
    document.getElementById('catalogId').value = id;
    document.getElementById('catalogError').textContent = '';
    document.getElementById('catalogTitle').textContent = `${id ? 'Edit' : 'Add'} ${type === 'year' ? 'School Year' : type === 'grade' ? 'Grade' : type === 'section' ? 'Section' : 'Subject'}`;
    document.getElementById('catalogNameWrap').style.display = ['year','section','subject'].includes(type) ? '' : 'none';
    document.getElementById('catalogNameLabel').textContent = type === 'year' ? 'School Year (e.g. 2026-2027)' : type === 'section' ? 'Section Name' : 'Subject Name';
    document.getElementById('catalogName').value = type === 'grade' ? '' : value;
    document.getElementById('catalogLevelWrap').style.display = type === 'grade' ? '' : 'none';
    document.getElementById('catalogLevel').value = type === 'grade' ? value : '';
    document.getElementById('catalogGradeWrap').style.display = ['section','subject'].includes(type) ? '' : 'none';
    document.getElementById('catalogYearWrap').style.display = type === 'section' ? '' : 'none';
    document.getElementById('catalogActiveWrap').style.display = type === 'year' ? '' : 'none';
    document.getElementById('catalogActive').checked = type === 'year' && !!gradeId;
    const gradeSelect = document.getElementById('catalogGrade');
    if (gradeId) gradeSelect.value = gradeId;
    const yearSelect = document.getElementById('catalogYear');
    if (yearId) yearSelect.value = yearId;
    document.getElementById('catalogModal').style.display = '';
}
function closeCatalogForm() { document.getElementById('catalogModal').style.display = 'none'; }
async function saveCatalogRecord() {
    const type = document.getElementById('catalogType').value;
    const payload = { type, id: +document.getElementById('catalogId').value, name: document.getElementById('catalogName').value.trim() };
    if (type === 'grade') payload.level = +document.getElementById('catalogLevel').value;
    if (['section','subject'].includes(type)) payload.grade_level_id = +document.getElementById('catalogGrade').value;
    if (type === 'section') payload.school_year_id = +document.getElementById('catalogYear').value;
    if (type === 'year') payload.active = document.getElementById('catalogActive').checked;
    const result = await apiPost('api/admin_save_catalog.php', payload);
    if (result.error) { document.getElementById('catalogError').textContent = result.error; return; }
    location.reload();
}
</script>
<div id="duplicateAsmtModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeDuplicateAsmtModal()">
    <div class="modal-box modal-box--wide">
        <h3 style="color:var(--maroon-dark);margin-bottom:.25rem">Duplicate Assessment</h3>
        <p class="text-muted" id="duplicateSourceLabel" style="margin-bottom:1rem"></p>
        <div class="form-row">
            <div class="form-group"><label>Assessment Type</label><select id="dupType"><option value="summative">Summative Test</option><option value="periodic">Periodic</option><option value="term_exam">Term Exam</option></select></div>
            <div class="form-group"><label>Total Items</label><input id="dupItems" type="number" min="1" max="200"></div>
        </div>
        <div class="form-group"><label>Subject</label><select id="dupSubject"></select></div>
        <div class="form-group"><label>Term</label><select id="dupTerm"></select></div>
        <div class="form-row">
            <div class="form-group" style="flex:3"><label>Title <span class="req">*</span></label><input id="dupTitle" maxlength="200"></div>
            <div class="form-group"><label>Date Given</label><input id="dupDate" type="date"></div>
        </div>
        <p class="text-muted" style="font-size:.85rem">Competency mappings from the original will be copied when they match the selected subject and item count.</p>
        <p id="duplicateAsmtError" class="text-muted" role="alert"></p>
        <div class="form-actions"><button id="btnDuplicateAsmt" class="btn btn-primary" onclick="saveDuplicatedAssessment()">Create Duplicate</button><button class="btn btn-outline-secondary" onclick="closeDuplicateAsmtModal()">Cancel</button></div>
    </div>
</div>
<!-- ============================================================
     MODAL: RESET TEACHER PASSWORD
============================================================ -->
<div id="resetPwdModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeResetModal()">
    <div class="modal-box" style="max-width:480px">
        <h3 style="color:var(--maroon-dark);margin-bottom:1rem">Reset Teacher Password</h3>

        <!-- Step 1: confirm -->
        <div id="resetConfirmState">
            <p>Reset password for <strong id="resetTeacherName"></strong>?</p>
            <p style="font-size:.875rem;color:var(--c-muted);margin-top:.35rem">
                Username: <code id="resetTeacherUsername"></code>
            </p>
            <div style="background:rgba(255,193,7,.12);border:2px solid #ffc107;border-radius:.5rem;padding:.75rem 1rem;margin-top:.9rem">
                <p style="margin:0 0 .35rem;font-weight:600;color:var(--maroon-dark)">&#9888; Warning</p>
                <ul style="margin:0;padding-left:1.2rem;font-size:.875rem;line-height:1.7">
                    <li>The teacher's password will be reset to the default: <strong>ilovejacobo</strong></li>
                    <li>The teacher <strong>must change their password</strong> on their next login.</li>
                    <li>Make sure to <strong>inform the teacher</strong> of this reset.</li>
                </ul>
            </div>
            <div style="display:flex;gap:.75rem;margin-top:1.5rem;justify-content:flex-end">
                <button class="btn btn-outline-secondary" onclick="closeResetModal()">Cancel</button>
                <button class="btn btn-primary" id="btnDoReset" onclick="confirmReset()">Generate Password</button>
            </div>
        </div>

        <!-- Step 2: show temp password once -->
        <div id="resetResultState" style="display:none">
            <p>Temporary password for <strong id="resetResultName"></strong>:</p>
            <div style="background:rgba(13,110,253,.08);border:2px solid #0d6efd;border-radius:.5rem;padding:1rem 1.5rem;text-align:center;margin:1rem 0">
                <code id="resetTempPwd" style="font-size:1.6rem;letter-spacing:.14em;color:var(--maroon-dark);font-weight:700;user-select:all"></code>
            </div>
            <p style="font-size:.8rem;color:var(--c-muted)">
                Username: <strong><code id="resetResultUsername"></code></strong><br>
                <span style="color:var(--c-danger)">&#9888; The teacher must change this password on their next login. Inform them of the reset.</span>
            </p>
            <div style="display:flex;gap:.75rem;margin-top:1.25rem;justify-content:flex-end;align-items:center">
                <span id="resetCopyMsg" style="color:green;font-size:.875rem;display:none">&#10003; Copied!</span>
                <button class="btn btn-outline-secondary" onclick="copyTempPwd()">Copy Password</button>
                <button class="btn btn-primary" onclick="closeResetModal()">Done</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL: EDIT TEACHER (grade levels / subjects)
============================================================ -->
<div id="editTeacherModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeEditTeacherModal()">
    <div class="modal-box" style="max-width:520px">
        <h3 style="color:var(--maroon-dark);margin-bottom:.25rem">Edit Teacher</h3>
        <p style="font-size:.875rem;color:var(--c-muted);margin-bottom:1rem">
            <strong id="editTeacherName"></strong> &middot; <code id="editTeacherUsername"></code>
        </p>

        <div class="form-group">
            <label>Grade Level(s) Taught <span class="req">*</span></label>
            <div class="checklist" id="editGradeChecklist"></div>
        </div>
        <div class="form-group" style="margin-top:.9rem">
            <label>Subject(s) Taught <span class="req">*</span></label>
            <div class="checklist checklist--grid" id="editSubjectChecklist"></div>
        </div>
        <div class="form-group" style="margin-top:.9rem">
            <label for="editTeacherAssignmentYear">School Year for Section Assignments</label>
            <select id="editTeacherAssignmentYear" onchange="renderEditTeacherSectionAssignments()"><?php foreach ($settingsYears as $year): ?><option value="<?= (int)$year['id'] ?>" <?= !empty($year['is_active']) ? 'selected' : '' ?>><?= h($year['name']) ?><?= !empty($year['is_active']) ? ' (Active)' : '' ?></option><?php endforeach; ?></select>
        </div>
        <div class="form-group"><label>Section(s) by Subject</label><div id="editTeacherSectionAssignments" class="checklist" style="max-height:240px;overflow:auto"><span class="text-muted">Loading assignments…</span></div></div>

        <div style="display:flex;gap:.75rem;margin-top:1.5rem;justify-content:flex-end">
            <button class="btn btn-outline-secondary" onclick="closeEditTeacherModal()">Cancel</button>
            <button class="btn btn-primary" id="btnSaveTeacherEdit" onclick="saveTeacherEdit()">Save Changes</button>
        </div>
    </div>
</div>

<div id="teacherProfileModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeTeacherProfileModal()">
    <div class="modal-box" style="max-width:620px">
        <h3 style="color:var(--maroon-dark);margin-bottom:.25rem">Teacher Profile</h3>
        <p style="font-size:.875rem;color:var(--c-muted);margin-bottom:1rem"><strong id="teacherProfileName"></strong> · <code id="teacherProfileUsername"></code></p>
        <div class="form-group"><label>Assigned Sections</label><div id="teacherProfileSections" class="text-muted" style="font-size:.9rem">Loading assignments…</div></div>
        <div style="display:flex;justify-content:flex-end;margin-top:1.25rem"><button class="btn btn-outline-secondary" onclick="closeTeacherProfileModal()">Close</button></div>
    </div>
</div>

<div id="teacherAssignmentsModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeTeacherAssignments()">
    <div class="modal-box" style="max-width:620px">
        <h3 style="color:var(--maroon-dark);margin-bottom:.25rem">Assign Sections</h3>
        <p style="font-size:.875rem;color:var(--c-muted);margin-bottom:1rem"><strong id="teacherAssignmentsName"></strong></p>
        <div class="form-row">
            <div class="form-group"><label for="teacherAssignmentYear">School Year</label><select id="teacherAssignmentYear" onchange="loadTeacherAssignmentSections()"><?php foreach ($settingsYears as $year): ?><option value="<?= (int)$year['id'] ?>" <?= !empty($year['is_active']) ? 'selected' : '' ?>><?= h($year['name']) ?><?= !empty($year['is_active']) ? ' (Active)' : '' ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="teacherAssignmentSubject">Subject and Grade</label><select id="teacherAssignmentSubject" onchange="loadTeacherAssignmentSections()"><option value="">Loading…</option></select></div>
        </div>
        <p class="text-muted" style="font-size:.85rem">Assigned sections control this teacher’s student imports and answer sheet submissions.</p>
        <div id="teacherAssignmentChecklist" class="checklist checklist--grid" style="max-height:260px;overflow:auto"><span class="text-muted">Choose a subject to load sections.</span></div>
        <p id="teacherAssignmentsError" class="text-muted" role="alert"></p>
        <div class="form-actions"><button class="btn btn-primary" id="btnSaveTeacherAssignments" onclick="saveTeacherAssignments()">Save Section Mapping</button><button class="btn btn-outline-secondary" onclick="closeTeacherAssignments()">Cancel</button></div>
    </div>
</div>

<?php
$layoutFooterScripts = '<script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.6.0/dist/js/tabulator.min.js"></script><script src="' . asset_url('chartjs-adminlte-theme.js') . '"></script><script src="' . asset_url('script.js') . '"></script><script src="' . asset_url('table-pagination.js') . '"></script><script>const requestedAdminPanel=new URLSearchParams(window.location.search).get("panel");if(requestedAdminPanel){const button=[...document.querySelectorAll(".admin-tab[data-panel]")].find(item=>item.dataset.panel===requestedAdminPanel);if(button)button.click();}</script>';
require __DIR__ . '/includes/master_layout_end.php';
