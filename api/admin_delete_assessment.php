<?php
/**
 * Admin: hard-delete an assessment + all child data in a transaction.
 *
 * POST body (JSON):
 *   action        = "info"   → return blast-radius counts, no deletion
 *   action        = "delete" → perform cascading delete (default when omitted)
 *   assessment_id = int
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
$action        = $raw['action'] ?? 'delete';

if (!$assessment_id) json_response(['error' => 'Missing assessment_id.'], 400);
if (!in_array($action, ['info', 'delete'], true)) json_response(['error' => 'Invalid action.'], 400);

$pdo = get_pdo();

$aStmt = $pdo->prepare("SELECT id, title FROM assessments WHERE id = ?");
$aStmt->execute([$assessment_id]);
$asmt = $aStmt->fetch();
if (!$asmt) json_response(['error' => 'Assessment not found.'], 404);

// Blast radius
$sfStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT section_id) AS sections, COALESCE(SUM(frequency),0) AS students
     FROM score_frequencies WHERE assessment_id = ?"
);
$sfStmt->execute([$assessment_id]);
$sfRow = $sfStmt->fetch();

$taeStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT teacher_id) AS teachers FROM teacher_assessment_encodings WHERE assessment_id = ?"
);
$taeStmt->execute([$assessment_id]);
$taeRow = $taeStmt->fetch();

$blastRadius = [
    'sections' => (int)$sfRow['sections'],
    'students' => (int)$sfRow['students'],
    'teachers' => (int)$taeRow['teachers'],
    'has_data' => (int)$sfRow['sections'] > 0,
];

if ($action === 'info') {
    json_response(['title' => $asmt['title'], 'blast_radius' => $blastRadius]);
}

// ---- DELETE ------------------------------------------------------------
$pdo->beginTransaction();
try {
    // Children first, ordered to respect implicit FK chains
    $pdo->prepare("DELETE FROM score_frequencies            WHERE assessment_id = ?")->execute([$assessment_id]);
    $pdo->prepare("DELETE FROM item_correct_counts          WHERE assessment_id = ?")->execute([$assessment_id]);
    $pdo->prepare("DELETE FROM assessment_item_competencies WHERE assessment_id = ?")->execute([$assessment_id]);
    $pdo->prepare("DELETE FROM teacher_assessment_encodings WHERE assessment_id = ?")->execute([$assessment_id]);
    $pdo->prepare("DELETE FROM assessment_sections          WHERE assessment_id = ?")->execute([$assessment_id]);

    $del = $pdo->prepare("DELETE FROM assessments WHERE id = ?");
    $del->execute([$assessment_id]);
    if ($del->rowCount() !== 1) throw new RuntimeException('Parent row not deleted.');

    $pdo->commit();
    json_response(['success' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Delete failed: ' . $e->getMessage()], 500);
}
