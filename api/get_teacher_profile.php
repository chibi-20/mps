<?php
/**
 * Admin: returns a teacher's raw grade levels and subject names
 * (as stored in user_grade_levels / user_subjects) for the Edit Teacher modal.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');
$teacher_id = validate_int($_GET['teacher_id'] ?? null, 1);
if (!$teacher_id) json_response(['error' => 'Missing teacher_id.'], 400);

$pdo = get_pdo();

$stmt = $pdo->prepare("SELECT id, last_name, first_name, middle_name, username FROM users WHERE id = ? AND role = 'teacher'");
$stmt->execute([$teacher_id]);
$teacher = $stmt->fetch();
if (!$teacher) json_response(['error' => 'Teacher not found.'], 404);

$glStmt = $pdo->prepare("SELECT grade_level FROM user_grade_levels WHERE user_id = ? ORDER BY grade_level");
$glStmt->execute([$teacher_id]);
$gradeLevels = array_map('intval', array_column($glStmt->fetchAll(), 'grade_level'));

$usStmt = $pdo->prepare("SELECT subject_name FROM user_subjects WHERE user_id = ? ORDER BY subject_name");
$usStmt->execute([$teacher_id]);
$subjects = array_column($usStmt->fetchAll(), 'subject_name');

json_response([
    'display_name' => display_name($teacher),
    'username'      => $teacher['username'],
    'grade_levels'  => $gradeLevels,
    'subjects'      => $subjects,
]);
