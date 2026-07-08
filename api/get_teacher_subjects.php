<?php
/**
 * Admin: returns a teacher's registered subjects (from user_subjects → subjects)
 * plus their current section assignments (teacher_assignments) for the active SY.
 * Used by the admin Section Assignments panel.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');
$teacher_id = validate_int($_GET['teacher_id'] ?? null, 1);
if (!$teacher_id) json_response(['error' => 'Missing teacher_id.'], 400);

$pdo = get_pdo();

$subjStmt = $pdo->prepare(
    "SELECT s.id, s.name, s.grade_level
     FROM user_subjects us
     JOIN subjects s ON LOWER(TRIM(s.name)) = LOWER(TRIM(us.subject_name))
     WHERE us.user_id = ?
     ORDER BY s.grade_level, s.name"
);
$subjStmt->execute([$teacher_id]);
$subjects = $subjStmt->fetchAll();

json_response(['subjects' => $subjects]);
