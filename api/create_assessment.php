<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$sess = require_login('teacher');
verify_csrf();

$raw = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($raw)) $raw = $_POST;

$uid         = (int)$sess['user_id'];
$subject_id  = validate_int($raw['subject_id']  ?? null, 1);
$term_id     = validate_int($raw['term_id']     ?? null, 1);
$type        = $raw['type'] ?? '';
$title       = validate_string($raw['title']       ?? '', 200);
$total_items = validate_int($raw['total_items']    ?? null, 1, 200);
$date_given  = $raw['date_given'] ?? null;
$section_ids = array_values(array_unique(array_filter(
    array_map('intval', (array)($raw['section_ids'] ?? [])),
    fn($id) => $id > 0
)));

if (!$subject_id || !$term_id || !$type || !$title || !$total_items) {
    json_response(['error' => 'All required fields must be filled in.'], 422);
}
if (empty($section_ids)) {
    json_response(['error' => 'Select at least one section.'], 422);
}
if (!in_array($type, ['summative','periodic','term_exam'], true)) {
    json_response(['error' => 'Invalid assessment type.'], 422);
}

$pdo = get_pdo();

// Verify teacher is registered for this subject + get grade_level
$check = $pdo->prepare(
    "SELECT s.grade_level
     FROM subjects s
     JOIN user_subjects us ON LOWER(TRIM(s.name)) = LOWER(TRIM(us.subject_name))
     WHERE s.id = ? AND us.user_id = ?
     LIMIT 1"
);
$check->execute([$subject_id, $uid]);
$subjRow = $check->fetch();
if (!$subjRow) {
    json_response(['error' => 'You are not registered for that subject.'], 403);
}
$grade = (int)$subjRow['grade_level'];

// Validate all submitted section_ids belong to this subject's grade
$placeholders = implode(',', array_fill(0, count($section_ids), '?'));
$secChk = $pdo->prepare(
    "SELECT id FROM sections WHERE id IN ({$placeholders}) AND grade_level = ?"
);
$secChk->execute([...$section_ids, $grade]);
$validSecIds = array_column($secChk->fetchAll(), 'id');
if (count($validSecIds) !== count($section_ids)) {
    json_response(['error' => 'One or more sections do not belong to this grade level.'], 422);
}

// Clean date
$dateVal = null;
if ($date_given && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_given)) {
    $dateVal = $date_given;
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "INSERT INTO assessments
            (teacher_id, subject_id, term_id, type, title, total_items, date_given, status)
         VALUES (?,?,?,?,?,?,?,'draft')"
    )->execute([$uid, $subject_id, $term_id, $type, $title, $total_items, $dateVal]);
    $id = (int)$pdo->lastInsertId();

    $asStmt = $pdo->prepare("INSERT INTO assessment_sections (assessment_id, section_id) VALUES (?,?)");
    foreach ($validSecIds as $sid) {
        $asStmt->execute([$id, (int)$sid]);
    }

    $pdo->commit();
    json_response(['success' => true, 'assessment_id' => $id]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Failed to create assessment: ' . $e->getMessage()], 500);
}
