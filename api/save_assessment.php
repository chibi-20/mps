<?php
/**
 * Saves score_frequencies + item_correct_counts in one atomic transaction.
 * Also handles draft → submitted status transition.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$sess = require_login('teacher');
verify_csrf();

$uid = (int)$sess['user_id'];
$raw = json_decode(file_get_contents('php://input'), true) ?? [];

$assessment_id = validate_int($raw['assessment_id'] ?? null, 1);
$action        = $raw['action'] ?? 'draft';   // 'draft' | 'submit'
$sfData        = $raw['score_frequencies']   ?? [];
$iccData       = $raw['item_correct_counts'] ?? [];

if (!$assessment_id) json_response(['error' => 'Missing assessment_id.'], 400);
if (!in_array($action, ['draft','submit'], true)) json_response(['error' => 'Invalid action.'], 400);

$pdo = get_pdo();

// Ownership + status check
$stmt = $pdo->prepare("SELECT teacher_id, status, total_items FROM assessments WHERE id = ?");
$stmt->execute([$assessment_id]);
$asmt = $stmt->fetch();
if (!$asmt || (int)$asmt['teacher_id'] !== $uid) json_response(['error' => 'Not found or access denied.'], 403);
if (in_array($asmt['status'], ['submitted','approved'], true)) {
    json_response(['error' => 'This assessment is locked and cannot be edited.'], 409);
}

$totalItems = (int)$asmt['total_items'];

// Allowed sections are those linked to this assessment in assessment_sections
$validSecs = $pdo->prepare(
    "SELECT section_id FROM assessment_sections WHERE assessment_id = ?"
);
$validSecs->execute([$assessment_id]);
$allowedSecIds = array_column($validSecs->fetchAll(), 'section_id');

$pdo->beginTransaction();
try {
    // Upsert score_frequencies
    $sfStmt = $pdo->prepare(
        "INSERT INTO score_frequencies (assessment_id, section_id, score, frequency)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE frequency = VALUES(frequency)"
    );
    // Zero-out stmt (for removed entries)
    $sfZeroStmt = $pdo->prepare(
        "UPDATE score_frequencies SET frequency = 0
         WHERE assessment_id = ? AND section_id = ? AND score = ?"
    );

    foreach ($sfData as $secId => $scores) {
        $secId = (int)$secId;
        if (!in_array($secId, $allowedSecIds, true)) continue;
        foreach ($scores as $score => $freq) {
            $score = (int)$score;
            $freq  = max(0, (int)$freq);
            if ($score < 0 || $score > $totalItems) continue;
            $sfStmt->execute([$assessment_id, $secId, $score, $freq]);
        }
    }

    // Upsert item_correct_counts
    $iccStmt = $pdo->prepare(
        "INSERT INTO item_correct_counts (assessment_id, section_id, item_no, correct_count)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE correct_count = VALUES(correct_count)"
    );

    foreach ($iccData as $secId => $items) {
        $secId = (int)$secId;
        if (!in_array($secId, $allowedSecIds, true)) continue;
        foreach ($items as $itemNo => $count) {
            $itemNo = (int)$itemNo;
            $count  = max(0, (int)$count);
            if ($itemNo < 1 || $itemNo > $totalItems) continue;
            $iccStmt->execute([$assessment_id, $secId, $itemNo, $count]);
        }
    }

    // Update assessment status
    $newStatus = ($action === 'submit') ? 'submitted' : 'draft';
    $pdo->prepare("UPDATE assessments SET status=?, updated_at=NOW() WHERE id=?")
        ->execute([$newStatus, $assessment_id]);

    $pdo->commit();
    json_response(['success' => true, 'status' => $newStatus]);

} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Save failed: ' . $e->getMessage()], 500);
}
