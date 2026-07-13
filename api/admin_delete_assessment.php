<?php
/**
 * Admin-only: hard-delete an assessment of ANY status + all child data.
 * POST only. Admin role verified server-side.
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

if (!$assessment_id) {
    json_response(['error' => 'Missing assessment_id.'], 400);
}

$pdo = get_pdo();

// Confirm it exists before we try to delete
$chk = $pdo->prepare("SELECT id FROM assessments WHERE id = ?");
$chk->execute([$assessment_id]);
if (!$chk->fetch()) {
    json_response(['error' => 'Assessment not found.'], 404);
}

$pdo->beginTransaction();
try {
    // Children first (handles databases where FK CASCADE may not be set)
    $pdo->prepare("DELETE FROM score_frequencies   WHERE assessment_id = ?")->execute([$assessment_id]);
    $pdo->prepare("DELETE FROM item_correct_counts WHERE assessment_id = ?")->execute([$assessment_id]);
    $pdo->prepare("DELETE FROM assessment_sections WHERE assessment_id = ?")->execute([$assessment_id]);

    $del = $pdo->prepare("DELETE FROM assessments WHERE id = ?");
    $del->execute([$assessment_id]);
    if ($del->rowCount() !== 1) {
        throw new RuntimeException('Parent row not deleted.');
    }

    $pdo->commit();
    json_response(['success' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Delete failed: ' . $e->getMessage()], 500);
}
