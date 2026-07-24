<?php
/**
 * Admin: fetch a single admin-created assessment with its competency map
 * and a flag indicating whether encoded data exists (locks destructive fields).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');
$pdo = get_pdo();

$id = validate_int($_GET['id'] ?? null, 1);
if (!$id) json_response(['error' => 'Missing id.'], 400);

$aStmt = $pdo->prepare("
    SELECT a.id, a.title, a.type, a.total_items, a.date_given, a.status,
           a.subject_id, s.name AS subject_name, s.grade_level,
           a.term_id, t.term_no, t.name AS term_name,
           t.school_year_id AS sy_id, sy.name AS sy_name
    FROM assessments a
    JOIN subjects s    ON s.id  = a.subject_id
    JOIN terms t       ON t.id  = a.term_id
    JOIN school_years sy ON sy.id = t.school_year_id
    WHERE a.id = ? AND COALESCE(a.is_shared, 0) = 1
");
$aStmt->execute([$id]);
$asmt = $aStmt->fetch();
if (!$asmt) json_response(['error' => 'Assessment not found.'], 404);

// Is any data encoded? Determines which fields the client may edit.
$hdStmt = $pdo->prepare(
    "SELECT (EXISTS(SELECT 1 FROM score_frequencies    WHERE assessment_id = ?)
          OR EXISTS(SELECT 1 FROM item_correct_counts  WHERE assessment_id = ?)) AS has_data"
);
$hdStmt->execute([$id, $id]);
$hasData = (bool)$hdStmt->fetchColumn();

// Current sections encoded (for the lock notice)
$secStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT section_id) FROM score_frequencies WHERE assessment_id = ?"
);
$secStmt->execute([$id]);
$sectionsEncoded = (int)$secStmt->fetchColumn();

// Competency map: item_no → {competency_id, code, description}
$mapStmt = $pdo->prepare("
    SELECT aic.item_no, aic.competency_id, c.code, c.description
    FROM assessment_item_competencies aic
    JOIN competencies c ON c.id = aic.competency_id
    WHERE aic.assessment_id = ?
    ORDER BY aic.item_no
");
$mapStmt->execute([$id]);
$compMap = [];
foreach ($mapStmt->fetchAll() as $row) {
    $compMap[(int)$row['item_no']] = [
        'competency_id' => (int)$row['competency_id'],
        'code'          => $row['code'],
        'description'   => $row['description'],
    ];
}

json_response([
    'assessment'      => $asmt,
    'has_data'        => $hasData,
    'sections_encoded'=> $sectionsEncoded,
    'competency_map'  => $compMap,
]);
