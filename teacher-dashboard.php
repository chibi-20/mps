<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$sess = require_login('teacher');
$uid = (int) $sess['user_id'];
$pdo = get_pdo();

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
    $dbgCount = (int) $dbgStmt->fetchColumn();
    error_log("MPS: Subject dropdown empty for user_id={$uid}. "
        . "user_subjects rows={$dbgCount}. "
        . "If rows>0, the subjects table may be empty/unseeded. "
        . "If rows=0, this teacher's registration did not insert user_subjects.");
}

// Load active school year + terms
$sy = $pdo->query("SELECT id, name FROM school_years WHERE is_active=1 LIMIT 1")->fetch();
$syId = $sy ? (int) $sy['id'] : 0;
$terms = $syId ? $pdo->prepare("SELECT id, term_no, name FROM terms WHERE school_year_id=? ORDER BY term_no")
    ->execute([$syId]) || [] : [];
if ($syId) {
    $tStmt = $pdo->prepare("SELECT id, term_no, name FROM terms WHERE school_year_id=? ORDER BY term_no");
    $tStmt->execute([$syId]);
    $terms = $tStmt->fetchAll();
} else {
    $terms = [];
}

// Legacy assessments (teacher-created)
$aStmt = $pdo->prepare(
    "SELECT a.id, a.title, a.type, a.total_items, a.date_given, a.status, a.remarks,
            a.subject_id, s.name AS subject_name, s.grade_level,
            t.term_no, t.name AS term_name, 0 AS is_shared,
            (SELECT COUNT(*) FROM assessment_sections WHERE assessment_id = a.id) AS section_count,
            a.updated_at AS sort_ts
     FROM assessments a
     JOIN subjects s ON s.id = a.subject_id
     JOIN terms t ON t.id = a.term_id
     WHERE a.teacher_id = ? AND COALESCE(a.is_shared, 0) = 0
     ORDER BY a.updated_at DESC"
);
$aStmt->execute([$uid]);
$legacyAssessments = $aStmt->fetchAll();

// Approved shared assessments the teacher can start or continue
$sharedStmt = $pdo->prepare(
    "SELECT a.id, a.title, a.type, a.total_items, a.date_given,
            COALESCE(tae.status, 'ready') AS status, tae.remarks,
            a.subject_id, s.name AS subject_name, s.grade_level,
            t.term_no, t.name AS term_name, 1 AS is_shared,
            CASE WHEN tae.id IS NULL THEN
                (SELECT COUNT(*) FROM teacher_assignments ta
                 WHERE ta.teacher_id = ? AND ta.subject_id = a.subject_id AND ta.school_year_id = ?)
            ELSE
                (SELECT COUNT(DISTINCT ase.section_id)
                 FROM assessment_sections ase
                 JOIN teacher_assignments ta ON ta.section_id = ase.section_id
                    AND ta.subject_id = a.subject_id AND ta.teacher_id = ? AND ta.school_year_id = ?
                 WHERE ase.assessment_id = a.id)
            END AS section_count,
            COALESCE(tae.updated_at, a.updated_at) AS sort_ts
     FROM assessments a
     JOIN subjects s ON s.id = a.subject_id
     JOIN terms t ON t.id = a.term_id
     LEFT JOIN teacher_assessment_encodings tae ON tae.assessment_id = a.id AND tae.teacher_id = ?
     WHERE a.is_shared = 1 AND a.status = 'approved'
       AND EXISTS (
           SELECT 1 FROM user_subjects us
           WHERE us.user_id = ? AND LOWER(TRIM(us.subject_name)) = LOWER(TRIM(s.name))
       )
       AND EXISTS (
           SELECT 1 FROM teacher_assignments ta
           WHERE ta.teacher_id = ? AND ta.subject_id = a.subject_id AND ta.school_year_id = ?
       )
     ORDER BY sort_ts DESC"
);
$sharedStmt->execute([$uid, $syId, $uid, $syId, $uid, $uid, $uid, $syId]);
$sharedAssessments = $sharedStmt->fetchAll();

$myAssessments = [...$legacyAssessments, ...$sharedAssessments];
usort($myAssessments, fn($a, $b) => strcmp($b['sort_ts'] ?? '', $a['sort_ts'] ?? ''));

$csrf = csrf_token();
?>
<?php
$pageTitle = 'Teacher Dashboard';
$activeNavPage = 'dashboard';
$layoutBodyClass = 'teacher-dashboard layout-fixed sidebar-expand-lg bg-body-tertiary';
$layoutHeadAssets = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>';
require __DIR__ . '/includes/master_layout_start.php';
?>

<!-- Assessment Detail -->
<div id="assessmentDetail" style="display:none">

    <!-- Assessment header -->
    <div class="card assessment-header mb-3">
        <div class="assessment-header-title d-flex align-items-center w-100 justify-content-between">
            <div>
                <h2 id="detailTitle" class="card-title"></h2>
                <p id="detailMeta" class="text-muted"></p>
            </div>

            <div>
                <button id="btnBackToAssessments" class="btn btn-outline-secondary" type="button"
                    onclick="backToMyAssessments()">&larr; Back to My Assessments</button>

            </div>
        </div>
        <div class="assessment-actions d-flex justify-content-end w-100">
            <span id="detailStatus" class="status-chip badge"></span>
            <div class="action-working-group">
                <button id="btnSaveDraft" class="btn btn-sm btn-secondary" onclick="saveData('draft')">Save
                    Draft</button>
                <a id="btnExport" href="#" target="_blank" class="btn btn-sm btn-success">Export Excel</a>
            </div>
            <div class="action-divider"></div>
            <div class="submit-group">
                <button id="btnSubmit" class="btn btn-sm btn-primary" onclick="openSubmitModal()"><i class="fa fa-lock"></i> Submit

                <span class="submit-hint">Locks all data</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Remarks (returned) -->
    <div id="returnedAlert" class="alert alert-warning" style="display:none"></div>

    <!-- Mini chart -->
    <div class="card mini-chart-card mb-3">
        <h4 class="card-title">MPS per Section</h4>
        <canvas id="miniMpsChart" height="80"></canvas>
    </div>

    <!-- Tabs -->
    <div class="tab-bar nav nav-tabs">
        <button class="tab-btn nav-link active" data-tab="mps" onclick="switchTab('mps')">
            MPS (Frequency of Scores)
        </button>
        <button class="tab-btn nav-link" data-tab="item" onclick="switchTab('item')">
            Item Analysis
        </button>
    </div>

    <!-- MPS Tab -->
    <div id="tab-mps" class="tab-panel card">
        <div class="table-scroll table-responsive assessment-grid-scroll">
            <table id="mpsTable" class="data-table table align-middle mps-table" data-pagination="false"></table>
        </div>
    </div>

    <!-- Item Analysis Tab -->
    <div id="tab-item" class="tab-panel card" style="display:none">
        <div class="table-scroll table-responsive assessment-grid-scroll">
            <table id="itemTable" class="data-table table align-middle item-table" data-pagination="false"></table>
        </div>
    </div>
</div>

<!-- Empty state -->
<div id="emptyState" class="">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
        <div class="text-start">
            <h1 class="h3 mb-1">My Assessments</h1>
        </div>
    </div>
    <div id="assessmentList" class="card teacher-assessment-table-card">
        <div class="table-responsive">
            <table class="table data-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>ACTIONS</th>
                        <th>ASSESSMENT</th>
                        <th>SUBJECT</th>
                        <th>GRADE</th>
                        <th>TERM</th>
                        <th>ITEMS</th>
                        <th>SECTIONS</th>
                        <th>STATUS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($myAssessments as $a): ?>
                        <tr class="teacher-assessment-row <?= ($a['status'] ?? '') === 'returned' ? 'returned' : '' ?>"
                            data-id="<?= (int) $a['id'] ?>">
                            <?php $isReadyShared = !empty($a['is_shared']) && ($a['status'] ?? '') === 'ready'; ?>
                            <td><div class="d-flex align-items-center gap-1">
                                    <a class="btn btn-sm btn-outline-secondary action-icon" title="Download Answer Sheet" aria-label="Download Answer Sheet" href="<?= h(BASE_URL) ?>api/download_answer_sheet.php?assessment_id=<?= (int) $a['id'] ?>"><i class="bi bi-download" aria-hidden="true"></i></a>
                                    <?php if (!empty($a['is_shared']) && !in_array(($a['status'] ?? ''), ['submitted', 'approved'], true)): ?>
                                        <?php $encodingTitle = $isReadyShared ? 'Start Encoding' : 'Continue Encoding'; ?>
                                        <button class="btn btn-sm btn-outline-primary assessment-row-action action-icon" title="<?= h($encodingTitle) ?>" aria-label="<?= h($encodingTitle) ?>"
                                            onclick="startSharedAssessment(<?= (int) $a['id'] ?>, <?= (int) $a['subject_id'] ?>, this)"><i class="bi bi-pencil-square" aria-hidden="true"></i></button>
                                    <?php elseif (($a['status'] ?? '') === 'draft' && empty($a['is_shared'])): ?>
                                        <button class="btn btn-sm btn-outline-danger action-icon" title="Delete Assessment" aria-label="Delete Assessment"
                                            onclick="deleteAssessment(<?= (int) $a['id'] ?>, <?= h(json_encode($a['title'])) ?>, event)"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-primary action-icon" title="Open Assessment" aria-label="Open Assessment"
                                            onclick="loadAssessment(<?= (int) $a['id'] ?>)"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></button>
                                    <?php endif; ?>
                                </div><small class="assessment-start-error d-block text-danger mt-1" role="alert"></small></td>
                            <td><?php if ($isReadyShared): ?><span class="teacher-assessment-title"><?= h($a['title']) ?></span><?php else: ?><button type="button" class="teacher-assessment-open"
                                    onclick="loadAssessment(<?= (int) $a['id'] ?>)"><?= h($a['title']) ?></button><?php endif; ?><?php if (!empty($a['is_shared'])): ?>
                                    <div><span class="badge-shared">Admin</span></div>
                                <?php endif; ?>
                                <?php if (($a['status'] ?? '') === 'returned' && !empty($a['remarks'])): ?><small
                                        class="d-block text-danger mt-1">Remarks: <?= h($a['remarks']) ?></small><?php endif; ?>
                            </td>
                            <td><?= h($a['subject_name']) ?></td>
                            <td>Grade <?= (int) $a['grade_level'] ?></td>
                            <td>Term <?= (int) $a['term_no'] ?></td>
                            <td><?= (int) $a['total_items'] ?></td>
                            <td><?= (int) $a['section_count'] ?></td>
                            <td><span id="assessmentStatus<?= (int) $a['id'] ?>"
                                    class="status-chip badge status-<?= h($a['status']) ?>"><?= $a['status'] === 'ready' ? 'Ready to start' : ucfirst(h($a['status'])) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($myAssessments)): ?>
                        <tr class="teacher-assessment-empty">
                            <td colspan="8" class="text-center text-muted py-4">No assessments available for your assigned subjects. Ask your administrator to create one.</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$layoutFooterScripts = '<script>
const BASE_URL = ' . json_encode(BASE_URL) . ';
const CSRF_TOKEN = ' . json_encode($csrf) . ';
const MASTERY_THRESHOLD = ' . (int) MASTERY_THRESHOLD . ';
const MASTERY_BANDS = ' . json_encode(array_map(fn($b) => ['label' => $b['label'], 'min' => $b['min'], 'max' => $b['max']], MASTERY_BANDS)) . ';
const BAND_KEYS = ' . json_encode(array_keys(MASTERY_BANDS)) . ';
const PROFICIENCY_LEVELS = ' . json_encode(array_map(fn($b) => ['label' => $b['label'], 'min' => $b['min'], 'max' => $b['max']], PROFICIENCY_LEVELS)) . ';
const PL_KEYS = ' . json_encode(array_keys(PROFICIENCY_LEVELS)) . ';
</script><script src="' . asset_url('chartjs-adminlte-theme.js') . '"></script><script src="' . asset_url('script.js') . '"></script><script src="' . asset_url('table-pagination.js') . '"></script>';
require __DIR__ . '/includes/master_layout_end.php';
