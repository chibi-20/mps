<?php
/**
 * Saves score_frequencies + item_correct_counts in one atomic transaction.
 * Handles both legacy (teacher_id) and shared (teacher_assessment_encodings) assessments.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$sess = require_login('teacher');
verify_csrf();

$uid = (int)$sess['user_id'];
$raw = json_decode(file_get_contents('php://input'), true) ?? [];

$assessment_id = validate_int($raw['assessment_id'] ?? null, 1);
$action        = $raw['action'] ?? 'draft';
$sfData        = $raw['score_frequencies']    ?? [];
$iccData       = $raw['item_correct_counts']  ?? [];

if (!$assessment_id) json_response(['error' => 'Missing assessment_id.'], 400);
if (!in_array($action, ['draft', 'submit'], true)) json_response(['error' => 'Invalid action.'], 400);

$pdo = get_pdo();

$stmt = $pdo->prepare("SELECT teacher_id, status, total_items, is_shared FROM assessments WHERE id = ?");
$stmt->execute([$assessment_id]);
$asmt = $stmt->fetch();
if (!$asmt) json_response(['error' => 'Assessment not found.'], 404);

$isShared   = (bool)($asmt['is_shared'] ?? false);
$totalItems = (int)$asmt['total_items'];

if ($isShared) {
    $taeChk = $pdo->prepare(
        "SELECT status FROM teacher_assessment_encodings WHERE assessment_id = ? AND teacher_id = ?"
    );
    $taeChk->execute([$assessment_id, $uid]);
    $tae = $taeChk->fetch();
    if (!$tae) json_response(['error' => 'You have not started encoding this assessment.'], 403);
    if (in_array($tae['status'], ['submitted', 'approved'], true)) {
        json_response(['error' => 'Your encoding for this assessment is locked.'], 409);
    }
} else {
    if ((int)$asmt['teacher_id'] !== $uid) json_response(['error' => 'Not found or access denied.'], 403);
    if (in_array($asmt['status'], ['submitted', 'approved'], true)) {
        json_response(['error' => 'This assessment is locked and cannot be edited.'], 409);
    }
}

// Allowed sections: for shared, only this teacher's assignments; for legacy, all assessment_sections
if ($isShared) {
    $activeSY = $pdo->query("SELECT id FROM school_years WHERE is_active=1 LIMIT 1")->fetchColumn();
    $vStmt    = $pdo->prepare(
        "SELECT as_.section_id
         FROM assessment_sections as_
         JOIN teacher_assignments ta ON ta.section_id = as_.section_id
         WHERE as_.assessment_id = ? AND ta.teacher_id = ? AND ta.school_year_id = ?"
    );
    $vStmt->execute([$assessment_id, $uid, $activeSY ?: 0]);
} else {
    $vStmt = $pdo->prepare("SELECT section_id FROM assessment_sections WHERE assessment_id = ?");
    $vStmt->execute([$assessment_id]);
}
$allowedSecIds = array_column($vStmt->fetchAll(), 'section_id');

$pdo->beginTransaction();
try {
    $sfStmt = $pdo->prepare(
        "INSERT INTO score_frequencies (assessment_id, section_id, score, frequency)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE frequency = VALUES(frequency)"
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

    $newStatus = ($action === 'submit') ? 'submitted' : 'draft';

    if ($isShared) {
        $pdo->prepare(
            "UPDATE teacher_assessment_encodings SET status=?, updated_at=NOW() WHERE assessment_id=? AND teacher_id=?"
        )->execute([$newStatus, $assessment_id, $uid]);
    } else {
        $pdo->prepare("UPDATE assessments SET status=?, updated_at=NOW() WHERE id=?")
            ->execute([$newStatus, $assessment_id]);
    }

    $pdo->commit();
    json_response(['success' => true, 'status' => $newStatus]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Save failed: ' . $e->getMessage()], 500);
}
