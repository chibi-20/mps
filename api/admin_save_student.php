<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'POST required.'], 405);
$raw = json_decode(file_get_contents('php://input'), true) ?? [];
$token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $raw['csrf_token'] ?? '');
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) json_response(['error' => 'Invalid security token. Refresh and retry.'], 403);
$id = validate_int($raw['id'] ?? null, 1);
$lrn = trim((string)($raw['lrn'] ?? ''));
$name = trim((string)($raw['name'] ?? ''));
$sex = trim((string)($raw['sex'] ?? ''));
$gradeId = validate_int($raw['grade_level_id'] ?? null, 1);
$sectionId = validate_int($raw['section_id'] ?? null, 1);
$yearId = validate_int($raw['school_year_id'] ?? null, 1);
if ($lrn === '' || strlen($lrn) > 20 || !preg_match('/^\d+$/', $lrn)) json_response(['error' => 'Enter a numeric LRN up to 20 digits.'], 400);
if ($name === '' || mb_strlen($name) > 200) json_response(['error' => 'Enter a student name up to 200 characters.'], 400);
if (!in_array($sex, ['Male', 'Female'], true)) json_response(['error' => 'Select Male or Female.'], 400);
if (($gradeId === null) !== ($sectionId === null)) json_response(['error' => 'Select both grade and section, or leave both unassigned.'], 400);
$pdo = get_pdo();
try {
    $pdo->beginTransaction();
    if ($id) {
        $stmt = $pdo->prepare('UPDATE students SET lrn=?, name=?, sex=? WHERE id=?'); $stmt->execute([$lrn, $name, $sex, $id]);
        if ($stmt->rowCount() === 0 && !$pdo->query('SELECT 1 FROM students WHERE id=' . (int)$id)->fetchColumn()) throw new DomainException('Student record was not found.');
        $studentId = $id;
    } else {
        $stmt = $pdo->prepare('INSERT INTO students (lrn,name,sex) VALUES (?,?,?)'); $stmt->execute([$lrn, $name, $sex]); $studentId = (int)$pdo->lastInsertId();
    }
    if ($gradeId !== null) {
        if (!$yearId) throw new DomainException('Select a school year.');
        $section = $pdo->prepare('SELECT grade_level_id, school_year_id FROM sections WHERE id=?'); $section->execute([$sectionId]); $sectionData = $section->fetch();
        if (!$sectionData || (int)$sectionData['grade_level_id'] !== $gradeId || (int)$sectionData['school_year_id'] !== $yearId) throw new DomainException('The selected section does not match the grade and school year.');
        $stmt = $pdo->prepare('INSERT INTO student_enrollments (student_id,grade_level_id,section_id,school_year_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE grade_level_id=VALUES(grade_level_id), section_id=VALUES(section_id)');
        if (is_sqlite()) $stmt = $pdo->prepare('INSERT INTO student_enrollments (student_id,grade_level_id,section_id,school_year_id) VALUES (?,?,?,?) ON CONFLICT(student_id,school_year_id) DO UPDATE SET grade_level_id=excluded.grade_level_id, section_id=excluded.section_id');
        $stmt->execute([$studentId, $gradeId, $sectionId, $yearId]);
    }
    $pdo->commit(); json_response(['ok'=>true,'id'=>$studentId]);
} catch (DomainException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack(); json_response(['error'=>$error->getMessage()], 400);
} catch (PDOException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['error'=>str_contains(strtolower($error->getMessage()), 'unique') ? 'That LRN already exists.' : 'Could not save this student record.'], 400);
}
