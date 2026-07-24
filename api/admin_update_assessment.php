<?php
/**
 * Admin: update a shared assessment.
 *
 * Safe fields (always editable):  title, term_id, date_given
 * Destructive fields (locked when encoded data exists): type, total_items
 * Competency map:  always replaceable (doesn't touch count data)
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
if (!$assessment_id) json_response(['error' => 'Missing assessment_id.'], 400);

$pdo = get_pdo();

// Fetch current row — only admin-created assessments
$aStmt = $pdo->prepare(
    "SELECT id, type, total_items, subject_id FROM assessments WHERE id = ? AND COALESCE(is_shared,0) = 1"
);
$aStmt->execute([$assessment_id]);
$asmt = $aStmt->fetch();
if (!$asmt) json_response(['error' => 'Assessment not found.'], 404);

// Encoded-data guard
$hdStmt = $pdo->prepare(
    "SELECT (EXISTS(SELECT 1 FROM score_frequencies   WHERE assessment_id = ?)
          OR EXISTS(SELECT 1 FROM item_correct_counts WHERE assessment_id = ?)) AS has_data"
);
$hdStmt->execute([$assessment_id, $assessment_id]);
$hasData = (bool)$hdStmt->fetchColumn();

// ---- Safe fields -------------------------------------------------------
$title     = mb_substr(trim($raw['title']     ?? ''), 0, 200);
$termId    = validate_int($raw['term_id']     ?? null, 1);
$dateGiven = trim($raw['date_given'] ?? '');

if ($title === '') json_response(['error' => 'Title is required.'], 422);
if (!$termId)      json_response(['error' => 'Term is required.'],  422);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateGiven)) $dateGiven = null;

$termChk = $pdo->prepare("SELECT id FROM terms WHERE id = ?");
$termChk->execute([$termId]);
if (!$termChk->fetch()) json_response(['error' => 'Invalid term.'], 422);

// ---- Destructive fields ------------------------------------------------
$type       = $asmt['type'];
$totalItems = (int)$asmt['total_items'];

$clientType       = $raw['type']        ?? null;
$clientTotalItems = isset($raw['total_items']) ? (int)$raw['total_items'] : null;

if ($hasData) {
    // Reject any attempt to change locked fields
    if ($clientType !== null && $clientType !== $asmt['type']) {
        json_response(['error' => 'Cannot change Type while sections have encoded data. Delete encoded data first.'], 409);
    }
    if ($clientTotalItems !== null && $clientTotalItems !== (int)$asmt['total_items']) {
        json_response(['error' => 'Cannot change Total Items while sections have encoded data. Delete encoded data first.'], 409);
    }
} else {
    // No data yet — allow changes
    if ($clientType !== null && in_array($clientType, ['summative','term_exam'], true)) {
        $type = $clientType;
    }
    if ($clientTotalItems !== null && $clientTotalItems >= 1 && $clientTotalItems <= 200) {
        $totalItems = $clientTotalItems;
    }
}

// ---- Competency map (always safe to replace) ---------------------------
$itemCompMap = is_array($raw['item_competency_map'] ?? null)
    ? $raw['item_competency_map']
    : [];

// ---- Persist -----------------------------------------------------------
$pdo->beginTransaction();
try {
    $pdo->prepare(
        "UPDATE assessments SET title=?, term_id=?, type=?, total_items=?, date_given=?, updated_at=NOW() WHERE id=?"
    )->execute([$title, $termId, $type, $totalItems, $dateGiven, $assessment_id]);

    // Atomically replace competency map
    $pdo->prepare("DELETE FROM assessment_item_competencies WHERE assessment_id = ?")
        ->execute([$assessment_id]);

    if (!empty($itemCompMap)) {
        $mapStmt = $pdo->prepare(
            "INSERT INTO assessment_item_competencies (assessment_id, item_no, competency_id) VALUES (?,?,?)"
        );
        foreach ($itemCompMap as $itemNo => $compId) {
            $ino = (int)$itemNo;
            $cid = (int)$compId;
            if ($ino >= 1 && $ino <= $totalItems && $cid > 0) {
                $mapStmt->execute([$assessment_id, $ino, $cid]);
            }
        }
    }

    $pdo->commit();
    json_response(['success' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Update failed: ' . $e->getMessage()], 500);
}
