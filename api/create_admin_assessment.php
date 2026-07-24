<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
$sess = require_login('admin');
verify_csrf();

$raw = json_decode(file_get_contents('php://input'), true) ?? [];

$adminId     = (int)$sess['user_id'];
$type        = $raw['type']        ?? '';
$totalItems  = validate_int($raw['total_items']  ?? null, 1, 200);
$subjectId   = validate_int($raw['subject_id']   ?? null, 1);
$termId      = validate_int($raw['term_id']      ?? null, 1);
$title       = validate_string($raw['title']     ?? '', 200);
$dateGiven   = $raw['date_given']  ?? null;
$itemCompMap = $raw['item_competency_map'] ?? [];  // {item_no => competency_id}

if (!in_array($type, ['summative', 'periodic', 'term_exam'], true)) {
    json_response(['error' => 'Invalid assessment type.'], 422);
}
if (!$totalItems || !$subjectId || !$termId || !$title) {
    json_response(['error' => 'All required fields must be filled.'], 422);
}

$dateVal = null;
if ($dateGiven && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateGiven)) {
    $dateVal = $dateGiven;
}

$pdo = get_pdo();

// Verify subject and term exist
if (!$pdo->prepare("SELECT id FROM subjects WHERE id = ?")->execute([$subjectId]) ||
    !($pdo->prepare("SELECT id FROM subjects WHERE id = ?")->execute([$subjectId]) &&
      $pdo->query("SELECT FOUND_ROWS()"))) {
    // just proceed — FK will reject invalid IDs in the transaction
}

// Validate competency_ids belong to this subject
$validCompIds = [];
if (!empty($itemCompMap)) {
    $compIds = array_unique(array_filter(array_map('intval', array_values($itemCompMap)), fn($x) => $x > 0));
    if (!empty($compIds)) {
        $in     = implode(',', array_fill(0, count($compIds), '?'));
        $cStmt  = $pdo->prepare("SELECT id FROM competencies WHERE id IN ({$in}) AND subject_id = ?");
        $cStmt->execute([...$compIds, $subjectId]);
        $validCompIds = array_column($cStmt->fetchAll(), 'id', 'id');
    }
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "INSERT INTO assessments
            (teacher_id, subject_id, term_id, type, title, total_items, date_given, status, is_shared, created_by_admin)
         VALUES (NULL,?,?,?,?,?,?,'approved',1,?)"
    )->execute([$subjectId, $termId, $type, $title, $totalItems, $dateVal, $adminId]);

    $assessmentId = (int)$pdo->lastInsertId();

    if (!empty($itemCompMap)) {
        $mapStmt = $pdo->prepare(
            "INSERT INTO assessment_item_competencies (assessment_id, item_no, competency_id) VALUES (?,?,?)"
        );
        foreach ($itemCompMap as $itemNo => $compId) {
            $itemNo = (int)$itemNo;
            $compId = (int)$compId;
            if ($itemNo < 1 || $itemNo > $totalItems || !isset($validCompIds[$compId])) continue;
            $mapStmt->execute([$assessmentId, $itemNo, $compId]);
        }
    }

    $pdo->commit();
    json_response(['success' => true, 'assessment_id' => $assessmentId]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Failed to create assessment: ' . $e->getMessage()], 500);
}
