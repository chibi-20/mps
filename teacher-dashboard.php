<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$sess = require_login('teacher');
$uid  = (int)$sess['user_id'];
$pdo  = get_pdo();

// ------------------------------------------------------------------
// Load teacher's registered subjects from user_subjects → subjects.
// Joined on name (case-insensitive, trimmed) because user_subjects
// stores the string value selected at registration, not a foreign key.
// teacher_assignments is NOT used here — new teachers have no assignments
// yet but should still be able to create assessments.
// ------------------------------------------------------------------
$subjects_stmt = $pdo->prepare(
    "SELECT DISTINCT s.id, s.name, s.grade_level
     FROM user_subjects us
     JOIN subjects s ON LOWER(TRIM(s.name)) = LOWER(TRIM(us.subject_name))
     WHERE us.user_id = ?
     ORDER BY s.grade_level, s.name"
);
$subjects_stmt->execute([$uid]);
$mySubjects = $subjects_stmt->fetchAll();

if (empty($mySubjects)) {
    $dbgStmt = $pdo->prepare("SELECT COUNT(*) FROM user_subjects WHERE user_id=?");
    $dbgStmt->execute([$uid]);
    $dbgCount = (int)$dbgStmt->fetchColumn();
    error_log("MPS: Subject dropdown empty for user_id={$uid}. "
        . "user_subjects rows={$dbgCount}. "
        . "If rows>0, the subjects table may be empty/unseeded. "
        . "If rows=0, this teacher's registration did not insert user_subjects.");
}

// Load active school year + terms
$sy   = $pdo->query("SELECT id, name FROM school_years WHERE is_active=1 LIMIT 1")->fetch();
$syId = $sy ? (int)$sy['id'] : 0;
$terms = $syId ? $pdo->prepare("SELECT id, term_no, name FROM terms WHERE school_year_id=? ORDER BY term_no")
                     ->execute([$syId]) || [] : [];
if ($syId) {
    $tStmt = $pdo->prepare("SELECT id, term_no, name FROM terms WHERE school_year_id=? ORDER BY term_no");
    $tStmt->execute([$syId]);
    $terms = $tStmt->fetchAll();
} else {
    $terms = [];
}

// Load assessments for this teacher (include section count for sidebar display)
$aStmt = $pdo->prepare(
    "SELECT a.id, a.title, a.type, a.total_items, a.date_given, a.status, a.remarks,
            a.subject_id, s.name AS subject_name, s.grade_level,
            t.term_no, t.name AS term_name,
            (SELECT COUNT(*) FROM assessment_sections WHERE assessment_id = a.id) AS section_count
     FROM assessments a
     JOIN subjects s ON s.id = a.subject_id
     JOIN terms t ON t.id = a.term_id
     WHERE a.teacher_id = ?
     ORDER BY a.updated_at DESC"
);
$aStmt->execute([$uid]);
$myAssessments = $aStmt->fetchAll();

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Dashboard – MPS System</title>
<link rel="icon" href="<?= BASE_URL ?>assets/logo.png">
<link rel="stylesheet" href="<?= BASE_URL ?>styles.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>

<!-- ============================================================
     TOP NAV
============================================================ -->
<nav class="topnav">
    <div class="topnav-brand">
        <img src="<?= BASE_URL ?>assets/logo.png" alt="Jacobo Z. Gonzales Memorial National High School" class="school-logo">
        <span class="topnav-title">MPS &amp; Item Analysis System</span>
    </div>
    <div class="topnav-user">
        <span class="user-chip teacher-chip">Teacher</span>
        <span><?= h($sess['display']) ?></span>
        <a href="<?= BASE_URL ?>api/logout.php" class="btn btn-sm btn-outline">Sign Out</a>
    </div>
</nav>

<div class="app-layout">

<!-- ============================================================
     LEFT SIDEBAR: ASSESSMENT LIST
============================================================ -->
<aside class="sidebar">
    <div class="sidebar-header">
        <h3>My Assessments</h3>
        <button class="btn btn-sm btn-primary" id="btnNewAssessment">+ New</button>
    </div>

    <div id="assessmentList" class="assessment-list">
    <?php foreach ($myAssessments as $a): ?>
        <div class="assessment-item <?= $a['status'] === 'returned' ? 'returned' : '' ?>"
             data-id="<?= $a['id'] ?>"
             onclick="loadAssessment(<?= $a['id'] ?>)">
            <div class="ai-title"><?= h($a['title']) ?></div>
            <div class="ai-meta">
                <?= h($a['subject_name']) ?> G<?= $a['grade_level'] ?> &middot;
                Term <?= $a['term_no'] ?> &middot; <?= $a['total_items'] ?> items
            </div>
            <span class="status-chip status-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span>
            <?php if ($a['status'] === 'returned' && $a['remarks']): ?>
            <div class="ai-remarks">Remarks: <?= h($a['remarks']) ?></div>
            <?php endif; ?>
            <div class="ai-footer">
                <span class="ai-sec-count">
                    <?= $a['section_count'] ?> section<?= $a['section_count'] == 1 ? '' : 's' ?>
                </span>
                <?php if ($a['status'] === 'draft'): ?>
                <button class="btn-del-draft"
                        onclick="deleteAssessment(<?= $a['id'] ?>, <?= h(json_encode($a['title'])) ?>, event)"
                        title="Delete this draft">&#x1F5D1; Delete</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($myAssessments)): ?>
        <p class="empty-msg">No assessments yet. Click <strong>+ New</strong> to start.</p>
    <?php endif; ?>
    </div>
</aside>

<!-- ============================================================
     MAIN CONTENT AREA
============================================================ -->
<main class="main-content">

    <!-- New Assessment Form (hidden by default) -->
    <div id="newAssessmentPanel" class="card" style="display:none">
        <h3 class="card-title">Create New Assessment</h3>
        <form id="frmNewAssessment">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>Subject</label>
                    <?php if (empty($mySubjects)): ?>
                    <div class="alert alert-warning" style="margin:0;padding:0.5rem 0.75rem;font-size:0.85rem">
                        No subjects assigned — contact admin or re-register with subjects checked.
                    </div>
                    <select name="subject_id" id="selSubject" required disabled>
                        <option value="">— no subjects —</option>
                    </select>
                    <?php else: ?>
                    <select name="subject_id" id="selSubject" required>
                        <option value="">— select subject —</option>
                        <?php foreach ($mySubjects as $s): ?>
                        <option value="<?= $s['id'] ?>">
                            <?= h($s['name']) ?> (Grade <?= $s['grade_level'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Term</label>
                    <select name="term_id" required>
                        <option value="">— select —</option>
                        <?php foreach ($terms as $t): ?>
                        <option value="<?= $t['id'] ?>">Term <?= $t['term_no'] ?> — <?= h($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="type" required>
                        <option value="summative">Summative</option>
                        <option value="periodic" selected>Periodic</option>
                        <option value="term_exam">Term Exam</option>
                    </select>
                </div>
            </div>

            <!-- Section checklist — populated dynamically when subject is selected -->
            <div id="sectionChecklistWrap" class="form-group" style="display:none">
                <label>Sections <span class="req">*</span>
                    <small class="text-muted" id="sectionCountHint"></small>
                </label>
                <div id="sectionChecklist">
                    <p class="text-muted" style="margin:0">Select a subject above to load sections.</p>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="flex:2">
                    <label>Assessment Title</label>
                    <input type="text" name="title" placeholder="e.g. First Periodic Examination" required>
                </div>
                <div class="form-group">
                    <label>Total Items</label>
                    <input type="number" name="total_items" min="1" max="200" required>
                </div>
                <div class="form-group">
                    <label>Date Given</label>
                    <input type="date" name="date_given">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Assessment</button>
                <button type="button" class="btn btn-outline" onclick="toggleNewForm(false)">Cancel</button>
            </div>
        </form>
    </div>

    <!-- Assessment Detail -->
    <div id="assessmentDetail" style="display:none">

        <!-- Assessment header -->
        <div class="card assessment-header">
            <div>
                <h2 id="detailTitle" class="card-title"></h2>
                <p id="detailMeta" class="text-muted"></p>
            </div>
            <div class="assessment-actions">
                <span id="detailStatus" class="status-chip"></span>
                <div class="action-working-group">
                    <button id="btnImportZipgrade" class="btn btn-import" onclick="openImportModal()">&#x2B06; Import ZipGrade</button>
                    <button id="btnSaveDraft" class="btn btn-outline" onclick="saveData('draft')">Save Draft</button>
                    <a id="btnExport" href="#" target="_blank" class="btn btn-outline">Export Excel</a>
                </div>
                <div class="action-divider"></div>
                <div class="submit-group">
                    <button id="btnSubmit" class="btn btn-submit-final" onclick="openSubmitModal()">&#x1F512; Submit</button>
                    <span class="submit-hint">Locks all data</span>
                </div>
            </div>
        </div>

        <!-- Remarks (returned) -->
        <div id="returnedAlert" class="alert alert-warning" style="display:none"></div>

        <!-- Mini chart -->
        <div class="card mini-chart-card">
            <h4 class="card-title">MPS per Section</h4>
            <canvas id="miniMpsChart" height="80"></canvas>
        </div>

        <!-- Tabs -->
        <div class="tab-bar">
            <button class="tab-btn active" data-tab="mps" onclick="switchTab('mps')">
                MPS (Frequency of Scores)
            </button>
            <button class="tab-btn" data-tab="item" onclick="switchTab('item')">
                Item Analysis
            </button>
        </div>

        <!-- MPS Tab -->
        <div id="tab-mps" class="tab-panel card">
            <div class="table-scroll">
                <table id="mpsTable" class="data-table mps-table"></table>
            </div>
        </div>

        <!-- Item Analysis Tab -->
        <div id="tab-item" class="tab-panel card" style="display:none">
            <div class="table-scroll">
                <table id="itemTable" class="data-table item-table"></table>
            </div>
        </div>
    </div>

    <!-- Empty state -->
    <div id="emptyState" class="empty-state">
        <div class="empty-icon">📋</div>
        <p>Select an assessment from the sidebar, or create a new one.</p>
    </div>

</main>
</div><!-- /.app-layout -->

<!-- ============================================================
     SUBMIT CONFIRMATION MODAL
============================================================ -->
<div id="submitModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeSubmitModal()">
    <div class="modal-box">
        <h3 class="submit-modal-title">Submit this assessment?</h3>
        <p>Once submitted, all MPS and Item Analysis data will be <strong>locked</strong>. You will not be able to make any changes until an administrator returns it for correction.</p>
        <div id="submitEmptyWarning" class="submit-empty-warning" style="display:none">
            <strong>Warning — sections with no data entered:</strong>
            <ul id="submitEmptySectionList"></ul>
            <p>These sections will remain empty after submission. Proceed anyway?</p>
        </div>
        <div class="form-actions" style="margin-top:1.5rem">
            <button class="btn btn-outline" onclick="closeSubmitModal()">Cancel</button>
            <button class="btn btn-submit-final" onclick="confirmSubmit()">&#x1F512; Yes, Submit</button>
        </div>
    </div>
</div>

<!-- ============================================================
     ZIPGRADE IMPORT MODAL
============================================================ -->
<div id="importModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeImportModal()">
    <div class="modal-box modal-box--wide">
        <h3>Import ZipGrade CSV</h3>
        <p class="text-muted" style="margin-bottom:1rem;font-size:.88rem">
            Upload a ZipGrade CSV export for <strong>one section</strong>.
            Preview runs first — no data is written until you confirm.
        </p>

        <form id="frmImport">
            <input type="hidden" name="assessment_id" id="importAsmtId">
            <div class="form-row" style="gap:1rem;margin-bottom:1rem">
                <div class="form-group" style="margin:0;flex:1">
                    <label>Section <span class="req">*</span></label>
                    <select name="section_id" id="importSection" required>
                        <option value="">— select section —</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;flex:2">
                    <label>ZipGrade CSV File <span class="req">*</span></label>
                    <input type="file" name="csv_file" id="importFile" accept=".csv" required
                           style="padding:.35rem .5rem">
                </div>
            </div>
        </form>

        <!-- Preview results area (shown after clicking Preview) -->
        <div id="importPreviewArea" style="display:none"></div>

        <div class="form-actions" style="margin-top:1.25rem">
            <button id="btnDoPreview"  class="btn btn-primary"  onclick="doImportPreview()">Preview</button>
            <button id="btnDoConfirm" class="btn btn-success"  onclick="doImportConfirm()" style="display:none">Confirm Import</button>
            <button class="btn btn-outline" onclick="closeImportModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Data passed to JS -->
<script>
const BASE_URL           = <?= json_encode(BASE_URL) ?>;
const CSRF_TOKEN         = <?= json_encode($csrf) ?>;
const MASTERY_THRESHOLD  = <?= MASTERY_THRESHOLD ?>;
const MASTERY_BANDS      = <?= json_encode(array_map(fn($b) => ['label'=>$b['label'],'min'=>$b['min'],'max'=>$b['max']], MASTERY_BANDS)) ?>;
const BAND_KEYS          = <?= json_encode(array_keys(MASTERY_BANDS)) ?>;
const AVAILABLE_SECTIONS = {}; // populated per assessment
let currentAssessmentId  = null;
let miniChart            = null;
</script>
<script src="<?= BASE_URL ?>script.js"></script>
</body>
</html>
