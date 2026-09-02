<?php
/**
 * Admin: delete ONE teacher's submission from the Submissions Compliance
 * tab.
 *
 * A shared assessment (admin-created template) can have several teachers
 * each encoding their own sections, tracked one row per teacher in
 * teacher_assessment_encodings. This removes only the target teacher's
 * encoding plus the score_frequencies / item_correct_counts for the
 * sections assigned to them -- the assessment template and every other
 * teacher's data are left untouched.
 *
 * A legacy assessment (is_shared=0) is owned by exactly one teacher, so
 * there's nothing to partially delete -- deleting "their submission" means
 * deleting the assessment itself, same as admin_delete_assessment.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

require_login('admin');
verify_csrf();

$raw           = json_decode(file_get_contents('php://input'), true) ?? [];
$assessment_id = validate_int($raw['assessment_id'] ?? null, 1);
$teacher_id    = validate_int($raw['teacher_id']    ?? null, 1);

if (!$assessment_id) json_response(['error' => 'Missing assessment_id.'], 400);

$pdo  = get_pdo();
$stmt = $pdo->prepare("SELECT id, subject_id, term_id, is_shared FROM assessments WHERE id = ?");
$stmt->execute([$assessment_id]);
$asmt = $stmt->fetch();
if (!$asmt) json_response(['error' => 'Assessment not found.'], 404);

// ---- Legacy: one teacher owns the whole assessment -----------------------
if (!$asmt['is_shared']) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM score_frequencies            WHERE assessment_id = ?")->execute([$assessment_id]);
        $pdo->prepare("DELETE FROM item_correct_counts          WHERE assessment_id = ?")->execute([$assessment_id]);
        $pdo->prepare("DELETE FROM assessment_item_competencies WHERE assessment_id = ?")->execute([$assessment_id]);
        $pdo->prepare("DELETE FROM teacher_assessment_encodings WHERE assessment_id = ?")->execute([$assessment_id]);
        $pdo->prepare("DELETE FROM assessment_sections          WHERE assessment_id = ?")->execute([$assessment_id]);

        $del = $pdo->prepare("DELETE FROM assessments WHERE id = ?");
        $del->execute([$assessment_id]);
        if ($del->rowCount() !== 1) throw new RuntimeException('Parent row not deleted.');

        $pdo->commit();
        json_response(['success' => true, 'whole_assessment_deleted' => true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['error' => 'Delete failed: ' . $e->getMessage()], 500);
    }
}

// ---- Shared: scoped delete of just this teacher's data --------------------
if (!$teacher_id) json_response(['error' => 'Missing teacher_id.'], 400);

$taeChk = $pdo->prepare("SELECT 1 FROM teacher_assessment_encodings WHERE assessment_id = ? AND teacher_id = ?");
$taeChk->execute([$assessment_id, $teacher_id]);
if (!$taeChk->fetch()) json_response(['error' => 'Submission not found.'], 404);

$syStmt = $pdo->prepare("SELECT school_year_id FROM terms WHERE id = ?");
$syStmt->execute([$asmt['term_id']]);
$syId = (int)$syStmt->fetchColumn();

// Sections this teacher owns under this assessment (same scoping
// save_assessment.php uses when saving their data), excluding any section
// another teacher who ALSO has an encoding on this assessment is assigned
// to -- their data can't be safely attributed to just one of them.
$secStmt = $pdo->prepare(
    "SELECT asec.section_id
     FROM assessment_sections asec
     JOIN teacher_assignments ta
       ON ta.section_id = asec.section_id
      AND ta.teacher_id = ?
      AND ta.subject_id = ?
      AND ta.school_year_id = ?
     WHERE asec.assessment_id = ?
       AND NOT EXISTS (
           SELECT 1
           FROM teacher_assessment_encodings tae2
           JOIN teacher_assignments ta2
             ON ta2.teacher_id     = tae2.teacher_id
            AND ta2.section_id     = asec.section_id
            AND ta2.subject_id     = ?
            AND ta2.school_year_id = ?
           WHERE tae2.assessment_id = asec.assessment_id
             AND tae2.teacher_id   != ?
       )"
);
$secStmt->execute([$teacher_id, $asmt['subject_id'], $syId, $assessment_id, $asmt['subject_id'], $syId, $teacher_id]);
$secIds = array_map('intval', array_column($secStmt->fetchAll(), 'section_id'));

$pdo->beginTransaction();
try {
    if (!empty($secIds)) {
        $ph = implode(',', array_fill(0, count($secIds), '?'));
        $pdo->prepare("DELETE FROM score_frequencies WHERE assessment_id = ? AND section_id IN ({$ph})")
            ->execute([$assessment_id, ...$secIds]);
        $pdo->prepare("DELETE FROM item_correct_counts WHERE assessment_id = ? AND section_id IN ({$ph})")
            ->execute([$assessment_id, ...$secIds]);
        $pdo->prepare("DELETE FROM assessment_sections WHERE assessment_id = ? AND section_id IN ({$ph})")
            ->execute([$assessment_id, ...$secIds]);
    }
    $pdo->prepare("DELETE FROM teacher_assessment_encodings WHERE assessment_id = ? AND teacher_id = ?")
        ->execute([$assessment_id, $teacher_id]);

    $pdo->commit();
    json_response([
        'success'                  => true,
        'whole_assessment_deleted' => false,
        'sections_cleared'         => count($secIds),
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Delete failed: ' . $e->getMessage()], 500);
}
