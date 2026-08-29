<?php
/**
 * Admin: replaces a teacher's grade levels and subjects (user_grade_levels /
 * user_subjects) with the submitted set. Used by the Edit Teacher modal so
 * admins can grant a multi-grade / multi-subject teacher additional subjects
 * or grade levels after their account already exists — register.php only
 * ever writes these rows once, at self-registration.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

require_login('admin');
verify_csrf();

$raw        = json_decode(file_get_contents('php://input'), true) ?? [];
$teacher_id = validate_int($raw['teacher_id'] ?? null, 1);
if (!$teacher_id) json_response(['error' => 'Missing teacher_id.'], 400);

$gradeLevels = array_values(array_unique(array_filter(
    array_map('intval', (array)($raw['grade_levels'] ?? [])),
    fn($g) => in_array($g, AVAILABLE_GRADE_LEVELS, true)
)));
$subjects = array_values(array_unique(array_filter(
    (array)($raw['subjects'] ?? []),
    fn($s) => in_array($s, AVAILABLE_SUBJECTS, true)
)));

if (empty($gradeLevels)) json_response(['error' => 'Select at least one grade level.'], 400);
if (empty($subjects))    json_response(['error' => 'Select at least one subject.'], 400);

$pdo  = get_pdo();
$stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher'");
$stmt->execute([$teacher_id]);
if (!$stmt->fetch()) json_response(['error' => 'Teacher not found.'], 404);

$pdo->beginTransaction();
try {
    $pdo->prepare("DELETE FROM user_grade_levels WHERE user_id = ?")->execute([$teacher_id]);
    $pdo->prepare("DELETE FROM user_subjects WHERE user_id = ?")->execute([$teacher_id]);

    $glStmt = $pdo->prepare("INSERT INTO user_grade_levels (user_id, grade_level) VALUES (?,?)");
    foreach ($gradeLevels as $gl) {
        $glStmt->execute([$teacher_id, $gl]);
    }

    $usStmt = $pdo->prepare("INSERT INTO user_subjects (user_id, subject_name) VALUES (?,?)");
    foreach ($subjects as $subj) {
        $usStmt->execute([$teacher_id, $subj]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Update failed. Please try again.'], 500);
}

json_response(['success' => true]);
