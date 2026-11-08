<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');
verify_csrf();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }

$raw = json_decode(file_get_contents('php://input'), true) ?? [];
$last = validate_string($raw['last_name'] ?? '', 100);
$first = validate_string($raw['first_name'] ?? '', 100);
$middle = validate_string($raw['middle_name'] ?? '', 100);
$username = validate_string($raw['username'] ?? '', 50);
$password = (string)($raw['password'] ?? '');
$grades = array_values(array_unique(array_filter(array_map('intval', (array)($raw['grade_levels'] ?? [])),
    fn($grade) => in_array($grade, AVAILABLE_GRADE_LEVELS, true))));
$subjects = array_values(array_unique(array_filter((array)($raw['subjects'] ?? []),
    fn($subject) => is_string($subject) && in_array($subject, AVAILABLE_SUBJECTS, true))));
$requestedAssignments = is_array($raw['section_assignments'] ?? null) ? $raw['section_assignments'] : [];

if ($last === '' || $first === '' || $username === '' || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
    json_response(['error' => 'Enter a first name, last name, and valid username (3–50 letters, numbers, dots, underscores, or hyphens).'], 422);
}
if (mb_strlen($password) < 8) json_response(['error' => 'Temporary password must be at least 8 characters.'], 422);
if (!$grades || !$subjects) json_response(['error' => 'Select at least one grade level and one subject.'], 422);

$pdo = get_pdo();
$assignments = [];
$seenAssignments = [];
$subjectById = $pdo->prepare('SELECT name, grade_level FROM subjects WHERE id=?');
$schoolYearExists = $pdo->prepare('SELECT 1 FROM school_years WHERE id=?');
foreach ($requestedAssignments as $assignment) {
    if (!is_array($assignment)) json_response(['error' => 'Invalid section assignment.'], 422);
    $subjectId = validate_int($assignment['subject_id'] ?? null, 1);
    $yearId = validate_int($assignment['school_year_id'] ?? null, 1);
    $sectionIds = array_values(array_unique(array_filter(array_map('intval', (array)($assignment['section_ids'] ?? [])), fn($id) => $id > 0)));
    if (!$subjectId || !$yearId) json_response(['error' => 'Each section assignment needs a subject and school year.'], 422);
    $assignmentKey = $subjectId . ':' . $yearId;
    if (isset($seenAssignments[$assignmentKey])) json_response(['error' => 'Duplicate subject section assignment.'], 422);
    $seenAssignments[$assignmentKey] = true;
    $subjectById->execute([$subjectId]);
    $subject = $subjectById->fetch();
    if (!$subject || !in_array($subject['name'], $subjects, true) || !in_array((int)$subject['grade_level'], $grades, true)) {
        json_response(['error' => 'Section assignment subject and grade must be selected in the teacher profile.'], 422);
    }
    $schoolYearExists->execute([$yearId]);
    if (!$schoolYearExists->fetchColumn()) json_response(['error' => 'Section assignment school year not found.'], 422);
    if ($sectionIds) {
        $ph = implode(',', array_fill(0, count($sectionIds), '?'));
        $sectionCheck = $pdo->prepare("SELECT id FROM sections WHERE id IN ({$ph}) AND grade_level=? AND school_year_id=?");
        $sectionCheck->execute([...$sectionIds, (int)$subject['grade_level'], $yearId]);
        if (count($sectionCheck->fetchAll()) !== count($sectionIds)) {
            json_response(['error' => 'One or more selected sections do not match the subject grade and school year.'], 422);
        }
    }
    $assignments[] = ['subject_id' => $subjectId, 'school_year_id' => $yearId, 'section_ids' => $sectionIds];
}
$check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$check->execute([$username]);
if ($check->fetch()) json_response(['error' => 'That username is already taken.'], 409);

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO users (last_name, first_name, middle_name, username, password_hash, role, is_active, must_change_password)
        VALUES (?,?,?,?,?,'teacher',1,1)")->execute([$last, $first, $middle !== '' ? $middle : null, $username, password_hash($password, PASSWORD_DEFAULT)]);
    $teacherId = (int)$pdo->lastInsertId();
    $gradeInsert = $pdo->prepare('INSERT INTO user_grade_levels (user_id, grade_level) VALUES (?,?)');
    foreach ($grades as $grade) $gradeInsert->execute([$teacherId, $grade]);
    $subjectInsert = $pdo->prepare('INSERT INTO user_subjects (user_id, subject_name) VALUES (?,?)');
    foreach ($subjects as $subject) $subjectInsert->execute([$teacherId, $subject]);
    $assignmentInsert = $pdo->prepare('INSERT INTO teacher_assignments (teacher_id,subject_id,section_id,school_year_id) VALUES (?,?,?,?)');
    foreach ($assignments as $assignment) {
        foreach ($assignment['section_ids'] as $sectionId) {
            $assignmentInsert->execute([$teacherId, $assignment['subject_id'], $sectionId, $assignment['school_year_id']]);
        }
    }
    $pdo->commit();
    json_response(['success' => true, 'teacher_id' => $teacherId, 'assigned_sections' => array_sum(array_map(fn($assignment) => count($assignment['section_ids']), $assignments))]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Could not create teacher account.'], 500);
}
