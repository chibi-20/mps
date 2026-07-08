<?php
/**
 * Admin: returns all sections for a subject's grade_level + which are currently
 * assigned to a teacher (teacher_assignments). Used by Section Assignments panel.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');
$teacher_id = validate_int($_GET['teacher_id'] ?? null, 1);
$subject_id = validate_int($_GET['subject_id'] ?? null, 1);
if (!$teacher_id || !$subject_id) json_response(['error' => 'teacher_id and subject_id required.'], 400);

$pdo = get_pdo();

$subjStmt = $pdo->prepare("SELECT grade_level FROM subjects WHERE id = ?");
$subjStmt->execute([$subject_id]);
$subjRow = $subjStmt->fetch();
if (!$subjRow) json_response(['error' => 'Subject not found.'], 404);
$grade = (int)$subjRow['grade_level'];

$sy   = $pdo->query("SELECT id FROM school_years WHERE is_active=1 LIMIT 1")->fetch();
$syId = $sy ? (int)$sy['id'] : 0;

$secStmt = $pdo->prepare(
    "SELECT id, name FROM sections WHERE grade_level = ? AND school_year_id = ? ORDER BY name"
);
$secStmt->execute([$grade, $syId]);
$sections = $secStmt->fetchAll();

$assigned = [];
if ($syId) {
    $taStmt = $pdo->prepare(
        "SELECT section_id FROM teacher_assignments WHERE teacher_id=? AND subject_id=? AND school_year_id=?"
    );
    $taStmt->execute([$teacher_id, $subject_id, $syId]);
    $assigned = array_column($taStmt->fetchAll(), 'section_id');
}

json_response([
    'grade'    => $grade,
    'sections' => $sections,
    'assigned' => array_values(array_map('intval', $assigned)),
]);
