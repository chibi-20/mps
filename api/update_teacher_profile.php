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
$schoolYearId = validate_int($raw['school_year_id'] ?? null, 1);
$requestedAssignments = is_array($raw['section_assignments'] ?? null) ? $raw['section_assignments'] : null;

if (empty($gradeLevels)) json_response(['error' => 'Select at least one grade level.'], 400);
if (empty($subjects))    json_response(['error' => 'Select at least one subject.'], 400);
if (!$schoolYearId || $requestedAssignments === null) json_response(['error' => 'School year and section assignments are required.'], 400);

$pdo  = get_pdo();
$stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher'");
$stmt->execute([$teacher_id]);
if (!$stmt->fetch()) json_response(['error' => 'Teacher not found.'], 404);

$assignmentRows = [];
$seenAssignmentKeys = [];
$subjectById = $pdo->prepare('SELECT name, grade_level FROM subjects WHERE id=?');
$yearCheck = $pdo->prepare('SELECT 1 FROM school_years WHERE id=?');
$yearCheck->execute([$schoolYearId]);
if (!$yearCheck->fetchColumn()) json_response(['error' => 'School year not found.'], 422);
foreach ($requestedAssignments as $assignment) {
    if (!is_array($assignment)) json_response(['error' => 'Invalid section assignment.'], 422);
    $subjectId = validate_int($assignment['subject_id'] ?? null, 1);
    $assignmentYear = validate_int($assignment['school_year_id'] ?? null, 1);
    $sectionIds = array_values(array_unique(array_filter(array_map('intval', (array)($assignment['section_ids'] ?? [])), fn($id) => $id > 0)));
    if (!$subjectId || $assignmentYear !== $schoolYearId) json_response(['error' => 'Section assignments must use the selected school year.'], 422);
    $key = $subjectId . ':' . $assignmentYear;
    if (isset($seenAssignmentKeys[$key])) json_response(['error' => 'Duplicate subject section assignment.'], 422);
    $seenAssignmentKeys[$key] = true;
    $subjectById->execute([$subjectId]);
    $subject = $subjectById->fetch();
    $normalizedSubjects = array_map(static fn($name) => mb_strtolower(trim($name)), $subjects);
    if (!$subject
        || !in_array((int)$subject['grade_level'], $gradeLevels, true)
        || !in_array(mb_strtolower(trim($subject['name'])), $normalizedSubjects, true)) {
        json_response(['error' => 'Section assignment subject and grade must be selected in the teacher profile.'], 422);
    }
    if ($sectionIds) {
        $ph = implode(',', array_fill(0, count($sectionIds), '?'));
        $sectionCheck = $pdo->prepare("SELECT id FROM sections WHERE id IN ({$ph}) AND grade_level=? AND school_year_id=?");
        $sectionCheck->execute([...$sectionIds, (int)$subject['grade_level'], $assignmentYear]);
        if (count($sectionCheck->fetchAll()) !== count($sectionIds)) json_response(['error' => 'One or more sections do not match the subject grade and school year.'], 422);
    }
    $assignmentRows[] = ['subject_id' => $subjectId, 'school_year_id' => $assignmentYear, 'section_ids' => $sectionIds];
}

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

    // Keep section mappings only where the edited profile still covers the
    // assigned subject and grade.
    $mappingStmt = $pdo->prepare(
        "SELECT ta.id, sub.name, sub.grade_level
         FROM teacher_assignments ta JOIN subjects sub ON sub.id=ta.subject_id
         WHERE ta.teacher_id=?"
    );
    $mappingStmt->execute([$teacher_id]);
    $deleteMapping = $pdo->prepare('DELETE FROM teacher_assignments WHERE id=?');
    foreach ($mappingStmt->fetchAll() as $mapping) {
        $subjectAllowed = false;
        foreach ($subjects as $subject) {
            if (mb_strtolower(trim($subject)) === mb_strtolower(trim($mapping['name']))) {
                $subjectAllowed = true;
                break;
            }
        }
        if (!$subjectAllowed || !in_array((int)$mapping['grade_level'], $gradeLevels, true)) {
            $deleteMapping->execute([(int)$mapping['id']]);
        }
    }

    $deleteYearAssignment = $pdo->prepare('DELETE FROM teacher_assignments WHERE teacher_id=? AND subject_id=? AND school_year_id=?');
    $insertYearAssignment = $pdo->prepare('INSERT INTO teacher_assignments (teacher_id,subject_id,section_id,school_year_id) VALUES (?,?,?,?)');
    foreach ($assignmentRows as $assignment) {
        $deleteYearAssignment->execute([$teacher_id, $assignment['subject_id'], $assignment['school_year_id']]);
        foreach ($assignment['section_ids'] as $sectionId) {
            $insertYearAssignment->execute([$teacher_id, $assignment['subject_id'], $sectionId, $assignment['school_year_id']]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Update failed. Please try again.'], 500);
}

json_response(['success' => true]);
