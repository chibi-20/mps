<?php
/**
 * Admin: approve or return a submitted assessment.
 * Legacy (is_shared=0) assessments are reviewed as a whole via assessments.status.
 * Shared (is_shared=1) assessments are reviewed per-teacher via
 * teacher_assessment_encodings — multiple teachers can encode the same
 * assessment, so a teacher_id is required to know which encoding to act on.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$sess = require_login('admin');
verify_csrf();

$uid = (int)$sess['user_id'];
$raw = json_decode(file_get_contents('php://input'), true) ?? [];

$assessment_id = validate_int($raw['assessment_id'] ?? null, 1);
$teacher_id    = validate_int($raw['teacher_id'] ?? null, 1);
$action        = $raw['action'] ?? '';
$remarks       = validate_string($raw['remarks'] ?? '', 1000);

if (!$assessment_id || !in_array($action, ['approve','return'], true)) {
    json_response(['error' => 'Invalid request.'], 400);
}
if ($action === 'return' && !$remarks) {
    json_response(['error' => 'Remarks are required when returning an assessment.'], 422);
}

$pdo  = get_pdo();
$stmt = $pdo->prepare("SELECT id, status, is_shared FROM assessments WHERE id = ?");
$stmt->execute([$assessment_id]);
$asmt = $stmt->fetch();
if (!$asmt) json_response(['error' => 'Assessment not found.'], 404);

$newStatus = $action === 'approve' ? 'approved' : 'returned';

if ($asmt['is_shared']) {
    if (!$teacher_id) json_response(['error' => 'Missing teacher_id.'], 400);

    $taeStmt = $pdo->prepare(
        "SELECT status FROM teacher_assessment_encodings WHERE assessment_id = ? AND teacher_id = ?"
    );
    $taeStmt->execute([$assessment_id, $teacher_id]);
    $tae = $taeStmt->fetch();
    if (!$tae) json_response(['error' => 'Encoding not found.'], 404);
    if ($tae['status'] !== 'submitted') {
        json_response(['error' => 'Only submitted encodings can be reviewed.'], 409);
    }

    $pdo->prepare(
        "UPDATE teacher_assessment_encodings SET status=?, remarks=?, updated_at=NOW()
         WHERE assessment_id=? AND teacher_id=?"
    )->execute([$newStatus, $action === 'return' ? $remarks : null, $assessment_id, $teacher_id]);
} else {
    if ($asmt['status'] !== 'submitted') {
        json_response(['error' => 'Only submitted assessments can be reviewed.'], 409);
    }

    $pdo->prepare(
        "UPDATE assessments SET status=?, reviewed_by=?, remarks=?, updated_at=NOW() WHERE id=?"
    )->execute([$newStatus, $uid, $action === 'return' ? $remarks : null, $assessment_id]);
}

json_response(['success' => true, 'status' => $newStatus]);
