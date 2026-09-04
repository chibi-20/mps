<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
$sess = require_login('teacher');
verify_csrf();

$uid  = (int)$sess['user_id'];
$raw  = json_decode(file_get_contents('php://input'), true) ?? [];

$assessmentId  = validate_int($raw['assessment_id'] ?? null, 1);
$sectionIds    = array_values(array_unique(array_filter(
    array_map('intval', (array)($raw['section_ids'] ?? [])),
    fn($id) => $id > 0
)));
$confirmRemove = !empty($raw['confirm_remove_with_data']);

if (!$assessmentId)   json_response(['error' => 'Missing assessment_id.'], 400);
if (empty($sectionIds)) json_response(['error' => 'Select at least one section.'], 422);

$pdo = get_pdo();

// Verify assessment is a shared/published template
$aStmt = $pdo->prepare("SELECT id, subject_id, is_shared, status FROM assessments WHERE id = ?");
$aStmt->execute([$assessmentId]);
$asmt = $aStmt->fetch();
if (!$asmt || !$asmt['is_shared'] || $asmt['status'] !== 'approved') {
    json_response(['error' => 'Assessment not found or not available for encoding.'], 404);
}
$subjectId = (int)$asmt['subject_id'];

// Get grade level for this assessment's subject
$gradeStmt = $pdo->prepare("SELECT grade_level FROM subjects WHERE id = ?");
$gradeStmt->execute([$subjectId]);
$grade = (int)$gradeStmt->fetchColumn();

$activeSY = $pdo->query("SELECT id FROM school_years WHERE is_active=1 LIMIT 1")->fetchColumn();
if (!$activeSY) json_response(['error' => 'No active school year configured.'], 422);

// Requested sections just need to belong to this subject's grade + active school
// year -- a teacher can pick up any section here, not only ones an admin
// pre-assigned during registration.
$ph      = implode(',', array_fill(0, count($sectionIds), '?'));
$secStmt = $pdo->prepare(
    "SELECT id FROM sections WHERE id IN ({$ph}) AND grade_level = ? AND school_year_id = ?"
);
$secStmt->execute([...$sectionIds, $grade, $activeSY]);
$requested = array_map('intval', array_column($secStmt->fetchAll(), 'id'));

if (empty($requested)) {
    json_response(['error' => "None of the selected sections belong to this subject's grade level."], 422);
}

// Sections this teacher currently has active on THIS assessment specifically
// (not their whole standing subject assignment -- just what's attached here).
$curStmt = $pdo->prepare(
    "SELECT asec.section_id
     FROM assessment_sections asec
     JOIN teacher_assignments ta
       ON ta.section_id = asec.section_id AND ta.subject_id = ? AND ta.school_year_id = ?
     WHERE asec.assessment_id = ? AND ta.teacher_id = ?"
);
$curStmt->execute([$subjectId, $activeSY, $assessmentId, $uid]);
$current = array_map('intval', array_column($curStmt->fetchAll(), 'section_id'));

$toAdd    = array_values(array_diff($requested, $current));
$toRemove = array_values(array_diff($current, $requested));

// Sections another teacher is also actively encoding under this same subject
// can't be safely dropped from the shared assessment -- their data can't be
// attributed to just one of them.
$heldByOthers = [];
if (!empty($toRemove)) {
    $rph = implode(',', array_fill(0, count($toRemove), '?'));
    $guardStmt = $pdo->prepare(
        "SELECT DISTINCT ta2.section_id
         FROM teacher_assignments ta2
         JOIN teacher_assessment_encodings tae2
           ON tae2.teacher_id = ta2.teacher_id AND tae2.assessment_id = ?
         WHERE ta2.section_id IN ({$rph}) AND ta2.subject_id = ? AND ta2.school_year_id = ? AND ta2.teacher_id != ?"
    );
    $guardStmt->execute([$assessmentId, ...$toRemove, $subjectId, $activeSY, $uid]);
    $heldByOthers = array_map('intval', array_column($guardStmt->fetchAll(), 'section_id'));
}
$ownRemoveCandidates = array_values(array_diff($toRemove, $heldByOthers));

// Of those, sections that already have real encoded data for THIS assessment
// need explicit confirmation before their data is deleted.
$hasData = [];
if (!empty($ownRemoveCandidates)) {
    $cph = implode(',', array_fill(0, count($ownRemoveCandidates), '?'));
    $dataStmt = $pdo->prepare(
        "SELECT section_id FROM score_frequencies   WHERE assessment_id = ? AND section_id IN ({$cph})
         UNION
         SELECT section_id FROM item_correct_counts WHERE assessment_id = ? AND section_id IN ({$cph})"
    );
    $dataStmt->execute([$assessmentId, ...$ownRemoveCandidates, $assessmentId, ...$ownRemoveCandidates]);
    $hasData = array_map('intval', array_column($dataStmt->fetchAll(), 'section_id'));
}

$blockedForData = $confirmRemove ? [] : $hasData;
$actualRemove   = array_values(array_diff($ownRemoveCandidates, $blockedForData));

$pdo->beginTransaction();
try {
    // Insert or touch tae row
    $pdo->prepare(
        "INSERT INTO teacher_assessment_encodings (assessment_id, teacher_id, status)
         VALUES (?,?,'draft')
         ON DUPLICATE KEY UPDATE updated_at = NOW()"
    )->execute([$assessmentId, $uid]);

    // Add: make sure the teacher is on record as assigned to the section
    // (needed for every downstream ownership check), then attach it here.
    if (!empty($toAdd)) {
        $taIns = $pdo->prepare(
            "INSERT IGNORE INTO teacher_assignments (teacher_id, subject_id, section_id, school_year_id) VALUES (?,?,?,?)"
        );
        $asIns = $pdo->prepare("INSERT IGNORE INTO assessment_sections (assessment_id, section_id) VALUES (?,?)");
        foreach ($toAdd as $sid) {
            $taIns->execute([$uid, $subjectId, $sid, $activeSY]);
            $asIns->execute([$assessmentId, $sid]);
        }
    }

    // Remove: scoped to THIS assessment only -- the teacher's standing
    // subject assignment (teacher_assignments) is left untouched so other
    // assessments/terms for the same subject are unaffected.
    if (!empty($actualRemove)) {
        $sph = implode(',', array_fill(0, count($actualRemove), '?'));
        $pdo->prepare("DELETE FROM score_frequencies WHERE assessment_id = ? AND section_id IN ({$sph})")
            ->execute([$assessmentId, ...$actualRemove]);
        $pdo->prepare("DELETE FROM item_correct_counts WHERE assessment_id = ? AND section_id IN ({$sph})")
            ->execute([$assessmentId, ...$actualRemove]);
        $pdo->prepare("DELETE FROM assessment_sections WHERE assessment_id = ? AND section_id IN ({$sph})")
            ->execute([$assessmentId, ...$actualRemove]);
    }

    $pdo->commit();

    // Final active section count for this teacher's encoding of this assessment
    $finalStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT asec.section_id)
         FROM assessment_sections asec
         JOIN teacher_assignments ta ON ta.section_id = asec.section_id AND ta.subject_id = ?
         WHERE asec.assessment_id = ? AND ta.teacher_id = ? AND ta.school_year_id = ?"
    );
    $finalStmt->execute([$subjectId, $assessmentId, $uid, $activeSY]);
    $finalCount = (int)$finalStmt->fetchColumn();

    // heldByOthers can NEVER be removed from this screen (regardless of
    // confirm_remove_with_data) -- keep it a separate, clearly-labeled
    // category so the frontend never re-offers a "Continue?" that would
    // silently do nothing a second time.
    $sharedInfo = [];
    if (!empty($heldByOthers)) {
        $shph  = implode(',', array_fill(0, count($heldByOthers), '?'));
        $shStmt = $pdo->prepare("SELECT id, name FROM sections WHERE id IN ({$shph}) ORDER BY name");
        $shStmt->execute($heldByOthers);
        $sharedInfo = $shStmt->fetchAll();
    }

    $dataInfo = [];
    if (!empty($blockedForData)) {
        $dph   = implode(',', array_fill(0, count($blockedForData), '?'));
        $dStmt = $pdo->prepare("SELECT id, name FROM sections WHERE id IN ({$dph}) ORDER BY name");
        $dStmt->execute($blockedForData);
        $dataInfo = $dStmt->fetchAll();
    }

    json_response([
        'success'        => true,
        'assessment_id'  => $assessmentId,
        'sections'       => $finalCount,
        'added'          => count($toAdd),
        'removed'        => count($actualRemove),
        'blocked_shared' => $sharedInfo, // co-assigned to another teacher's active encoding -- never removable here
        'blocked_data'   => $dataInfo,   // this teacher's own data -- removable once confirmed
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Failed to update sections: ' . $e->getMessage()], 500);
}
