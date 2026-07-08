<?php
/**
 * Delete a DRAFT assessment and all its child data.
 * POST only. Teacher must own the assessment.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$sess = require_login('teacher');
verify_csrf();

$raw           = json_decode(file_get_contents('php://input'), true) ?? [];
$assessment_id = validate_int($raw['assessment_id'] ?? null, 1);
$uid           = (int)$sess['user_id'];

if (!$assessment_id) {
    json_response(['error' => 'Missing assessment_id.'], 400);
}

$pdo = get_pdo();

// Verify ownership — never trust the client's id alone
$stmt = $pdo->prepare("SELECT id, teacher_id, status FROM assessments WHERE id = ? AND teacher_id = ?");
$stmt->execute([$assessment_id, $uid]);
$asmt = $stmt->fetch();

if (!$asmt) {
    json_response(['error' => 'Assessment not found or access denied.'], 403);
}
if ($asmt['status'] !== 'draft') {
    json_response(['error' => 'Only draft assessments can be deleted. Submitted or approved records go through admin.'], 409);
}

$pdo->beginTransaction();
try {
    // Delete children first (also covers databases where FK CASCADE is not set)
    $pdo->prepare("DELETE FROM score_frequencies   WHERE assessment_id = ?")->execute([$assessment_id]);
    $pdo->prepare("DELETE FROM item_correct_counts WHERE assessment_id = ?")->execute([$assessment_id]);
    $pdo->prepare("DELETE FROM assessment_sections WHERE assessment_id = ?")->execute([$assessment_id]);
    // Belt-and-suspenders: repeat the ownership + status check in the SQL itself
    $deleted = $pdo->prepare(
        "DELETE FROM assessments WHERE id = ? AND teacher_id = ? AND status = 'draft'"
    );
    $deleted->execute([$assessment_id, $uid]);
    if ($deleted->rowCount() !== 1) {
        throw new RuntimeException('Assessment row not deleted — ownership or status mismatch.');
    }
    $pdo->commit();
    json_response(['success' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Delete failed: ' . $e->getMessage()], 500);
}
