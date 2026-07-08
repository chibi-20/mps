<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$sess = require_login('admin');
verify_csrf();

$uid = (int)$sess['user_id'];
$raw = json_decode(file_get_contents('php://input'), true) ?? [];

$assessment_id = validate_int($raw['assessment_id'] ?? null, 1);
$action        = $raw['action'] ?? '';
$remarks       = validate_string($raw['remarks'] ?? '', 1000);

if (!$assessment_id || !in_array($action, ['approve','return'], true)) {
    json_response(['error' => 'Invalid request.'], 400);
}
if ($action === 'return' && !$remarks) {
    json_response(['error' => 'Remarks are required when returning an assessment.'], 422);
}

$pdo  = get_pdo();
$stmt = $pdo->prepare("SELECT id, status FROM assessments WHERE id = ?");
$stmt->execute([$assessment_id]);
$asmt = $stmt->fetch();

if (!$asmt) json_response(['error' => 'Assessment not found.'], 404);
if ($asmt['status'] !== 'submitted') {
    json_response(['error' => 'Only submitted assessments can be reviewed.'], 409);
}

$newStatus = $action === 'approve' ? 'approved' : 'returned';
$pdo->prepare(
    "UPDATE assessments SET status=?, reviewed_by=?, remarks=?, updated_at=NOW() WHERE id=?"
)->execute([$newStatus, $uid, $action === 'return' ? $remarks : null, $assessment_id]);

json_response(['success' => true, 'status' => $newStatus]);
