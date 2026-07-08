<?php
/**
 * Admin: bulk-set teacher_assignments for one teacher+subject+school_year.
 * Replaces the existing rows entirely (delete + re-insert).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$sess = require_login('admin');
verify_csrf();

$raw        = json_decode(file_get_contents('php://input'), true) ?? [];
$teacher_id = validate_int($raw['teacher_id'] ?? null, 1);
$subject_id = validate_int($raw['subject_id'] ?? null, 1);
$section_ids = array_values(array_unique(array_filter(
    array_map('intval', (array)($raw['section_ids'] ?? [])),
    fn($id) => $id > 0
)));

if (!$teacher_id || !$subject_id) {
    json_response(['error' => 'teacher_id and subject_id are required.'], 422);
}

$pdo = get_pdo();

// Verify subject exists + get grade_level
$subjStmt = $pdo->prepare("SELECT grade_level FROM subjects WHERE id = ?");
$subjStmt->execute([$subject_id]);
$subjRow = $subjStmt->fetch();
if (!$subjRow) json_response(['error' => 'Subject not found.'], 404);
$grade = (int)$subjRow['grade_level'];

// Active school year
$sy = $pdo->query("SELECT id FROM school_years WHERE is_active=1 LIMIT 1")->fetch();
$syId = $sy ? (int)$sy['id'] : 0;
if (!$syId) json_response(['error' => 'No active school year found.'], 422);

// Validate section_ids belong to the correct grade
$allowedIds = [];
if (!empty($section_ids)) {
    $placeholders = implode(',', array_fill(0, count($section_ids), '?'));
    $secChk = $pdo->prepare(
        "SELECT id FROM sections WHERE id IN ({$placeholders}) AND grade_level = ? AND school_year_id = ?"
    );
    $secChk->execute([...$section_ids, $grade, $syId]);
    $allowedIds = array_column($secChk->fetchAll(), 'id');
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "DELETE FROM teacher_assignments WHERE teacher_id=? AND subject_id=? AND school_year_id=?"
    )->execute([$teacher_id, $subject_id, $syId]);

    $insStmt = $pdo->prepare(
        "INSERT INTO teacher_assignments (teacher_id, subject_id, section_id, school_year_id) VALUES (?,?,?,?)"
    );
    foreach ($allowedIds as $sid) {
        $insStmt->execute([$teacher_id, $subject_id, (int)$sid, $syId]);
    }

    $pdo->commit();
    json_response(['success' => true, 'assigned' => count($allowedIds)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Save failed: ' . $e->getMessage()], 500);
}
