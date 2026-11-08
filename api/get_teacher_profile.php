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

$sectionStmt = $pdo->prepare(
    "SELECT ta.school_year_id, sy.name AS school_year, ta.subject_id, sub.name AS subject_name,
            sec.id AS section_id, sec.name AS section_name, sec.grade_level
     FROM teacher_assignments ta
     JOIN sections sec ON sec.id = ta.section_id
     JOIN school_years sy ON sy.id = ta.school_year_id
     JOIN subjects sub ON sub.id = ta.subject_id
     WHERE ta.teacher_id = ?
     ORDER BY sy.id DESC, sub.grade_level, sub.name, sec.grade_level, sec.name"
);
$sectionStmt->execute([$teacher_id]);
$sectionAssignments = $sectionStmt->fetchAll();

json_response([
    'display_name' => display_name($teacher),
    'username'      => $teacher['username'],
    'grade_levels'  => $gradeLevels,
    'subjects'      => $subjects,
    'section_assignments' => $sectionAssignments,
]);
