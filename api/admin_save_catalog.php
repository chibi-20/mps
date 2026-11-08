<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'POST required.'], 405);
$raw = json_decode(file_get_contents('php://input'), true) ?? [];
$providedToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $raw['csrf_token'] ?? '');
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $providedToken)) json_response(['error' => 'Invalid security token. Refresh the page and try again.'], 403);
$type = $raw['type'] ?? '';
$id = validate_int($raw['id'] ?? null, 1);
$pdo = get_pdo();

try {
    if ($type === 'year') {
        $name = trim((string)($raw['name'] ?? ''));
        if (!preg_match('/^\d{4}[-–]\d{4}$/', $name)) json_response(['error' => 'Enter a school year like 2026-2027.'], 400);
        $active = !empty($raw['active']) ? 1 : 0;
        $pdo->beginTransaction();
        if ($active) $pdo->exec('UPDATE school_years SET is_active=0');
        if ($id) $pdo->prepare('UPDATE school_years SET name=?, is_active=? WHERE id=?')->execute([$name, $active, $id]);
        else $pdo->prepare('INSERT INTO school_years (name,is_active) VALUES (?,?)')->execute([$name, $active]);
        $pdo->commit();
    } elseif ($type === 'grade') {
        $level = validate_int($raw['level'] ?? null, 1, 20);
        if (!$level) json_response(['error' => 'Enter a grade from 1 to 20.'], 400);
        if ($id) $pdo->prepare('UPDATE grade_levels SET level=? WHERE id=?')->execute([$level, $id]);
        else $pdo->prepare('INSERT INTO grade_levels (level) VALUES (?)')->execute([$level]);
    } elseif ($type === 'subject') {
        $name = trim((string)($raw['name'] ?? ''));
        $gradeId = validate_int($raw['grade_level_id'] ?? null, 1);
        if ($name === '' || mb_strlen($name) > 100 || !$gradeId) json_response(['error' => 'Enter a subject name and grade.'], 400);
        $grade = $pdo->prepare('SELECT level FROM grade_levels WHERE id=?'); $grade->execute([$gradeId]);
        $level = $grade->fetchColumn();
        if ($level === false) json_response(['error' => 'Selected grade was not found.'], 400);
        if ($id) $pdo->prepare('UPDATE subjects SET name=?, grade_level=?, grade_level_id=? WHERE id=?')->execute([$name, $level, $gradeId, $id]);
        else $pdo->prepare('INSERT INTO subjects (name,grade_level,grade_level_id) VALUES (?,?,?)')->execute([$name, $level, $gradeId]);
    } elseif ($type === 'section') {
        $name = trim((string)($raw['name'] ?? ''));
        $gradeId = validate_int($raw['grade_level_id'] ?? null, 1);
        $yearId = validate_int($raw['school_year_id'] ?? null, 1);
        if ($name === '' || mb_strlen($name) > 120 || !$gradeId || !$yearId) json_response(['error' => 'Enter a section name, grade, and school year.'], 400);
        $grade = $pdo->prepare('SELECT level FROM grade_levels WHERE id=?'); $grade->execute([$gradeId]);
        $level = $grade->fetchColumn();
        if ($level === false) json_response(['error' => 'Selected grade was not found.'], 400);
        if ($id) $pdo->prepare('UPDATE sections SET name=?, grade_level=?, grade_level_id=?, school_year_id=? WHERE id=?')->execute([$name, $level, $gradeId, $yearId, $id]);
        else $pdo->prepare('INSERT INTO sections (name,grade_level,grade_level_id,school_year_id) VALUES (?,?,?,?)')->execute([$name, $level, $gradeId, $yearId]);
    } else {
        json_response(['error' => 'Unknown catalog type.'], 400);
    }
} catch (PDOException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['error' => str_contains(strtolower($error->getMessage()), 'unique') ? 'A record with that value already exists.' : 'Could not save the record. Check that it is not in use.'], 400);
}

json_response(['ok' => true]);
