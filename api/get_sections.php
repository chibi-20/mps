<?php
/**
 * Returns sections available for a given subject_id, scoped to the teacher's
 * grade level. Also returns pre-checked section IDs (admin-set defaults from
 * teacher_assignments, falling back to the most recent assessment's sections).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$sess       = require_login('teacher');
$uid        = (int)$sess['user_id'];
$subject_id = validate_int($_GET['subject_id'] ?? null, 1);
if (!$subject_id) json_response(['error' => 'Missing subject_id.'], 400);

$pdo = get_pdo();

// Verify teacher is registered for this subject AND get its grade_level
$stmt = $pdo->prepare(
    "SELECT s.grade_level
     FROM subjects s
     JOIN user_subjects us ON LOWER(TRIM(s.name)) = LOWER(TRIM(us.subject_name))
     WHERE s.id = ? AND us.user_id = ?
     LIMIT 1"
);
$stmt->execute([$subject_id, $uid]);
$row = $stmt->fetch();
if (!$row) json_response(['error' => 'Subject not found or not in your registration.'], 403);
$grade = (int)$row['grade_level'];

// Active school year
$sy   = $pdo->query("SELECT id FROM school_years WHERE is_active=1 LIMIT 1")->fetch();
$syId = $sy ? (int)$sy['id'] : 0;

// All sections for this grade + school year
$secStmt = $pdo->prepare(
    "SELECT id, name FROM sections
     WHERE grade_level = ? AND school_year_id = ?
     ORDER BY name"
);
$secStmt->execute([$grade, $syId]);
$sections = $secStmt->fetchAll();

// Pre-checked: admin-assigned defaults (teacher_assignments)
$checked = [];
if ($syId) {
    $taStmt = $pdo->prepare(
        "SELECT section_id FROM teacher_assignments
         WHERE teacher_id = ? AND subject_id = ? AND school_year_id = ?"
    );
    $taStmt->execute([$uid, $subject_id, $syId]);
    $checked = array_column($taStmt->fetchAll(), 'section_id');
}

// Fall back: most recent assessment's section set for this teacher+subject
if (empty($checked)) {
    $lastStmt = $pdo->prepare(
        "SELECT id FROM assessments
         WHERE teacher_id = ? AND subject_id = ?
         ORDER BY created_at DESC LIMIT 1"
    );
    $lastStmt->execute([$uid, $subject_id]);
    $lastId = $lastStmt->fetchColumn();
    if ($lastId) {
        $asStmt = $pdo->prepare("SELECT section_id FROM assessment_sections WHERE assessment_id = ?");
        $asStmt->execute([$lastId]);
        $checked = array_column($asStmt->fetchAll(), 'section_id');
    }
}

json_response([
    'grade'    => $grade,
    'sections' => $sections,
    'checked'  => array_values(array_map('intval', $checked)),
]);
