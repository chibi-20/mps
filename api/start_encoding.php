<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
$sess = require_login('teacher');
verify_csrf();

$uid  = (int)$sess['user_id'];
$raw  = json_decode(file_get_contents('php://input'), true) ?? [];

$assessmentId = validate_int($raw['assessment_id'] ?? null, 1);
$sectionIds   = array_values(array_unique(array_filter(
    array_map('intval', (array)($raw['section_ids'] ?? [])),
    fn($id) => $id > 0
)));

if (!$assessmentId)   json_response(['error' => 'Missing assessment_id.'], 400);
if (empty($sectionIds)) json_response(['error' => 'Select at least one section.'], 422);

$pdo = get_pdo();

// Verify assessment is a shared/published template
$aStmt = $pdo->prepare("SELECT id, subject_id, is_shared, status FROM assessments WHERE id = ?");
$aStmt->execute([$assessmentId]);
$asmt = $aStmt->fetch();
if (!$asmt || !$asmt['is_shared'] || $asmt['status'] !== 'approved') {
    json_response(['error' => 'Assessment not found or not available for encoding.'], 404);
}

// Get grade level for this assessment's subject
$gradeStmt = $pdo->prepare("SELECT grade_level FROM subjects WHERE id = ?");
$gradeStmt->execute([$asmt['subject_id']]);
$grade = (int)$gradeStmt->fetchColumn();

$activeSY = $pdo->query("SELECT id FROM school_years WHERE is_active=1 LIMIT 1")->fetchColumn();
if (!$activeSY) json_response(['error' => 'No active school year configured.'], 422);

// Validate: sections must be assigned to this teacher in teacher_assignments
$ph    = implode(',', array_fill(0, count($sectionIds), '?'));
$cStmt = $pdo->prepare(
    "SELECT sec.id FROM sections sec
     JOIN teacher_assignments ta ON ta.section_id = sec.id
     WHERE sec.id IN ({$ph}) AND sec.grade_level = ? AND ta.teacher_id = ? AND ta.school_year_id = ?"
);
$cStmt->execute([...$sectionIds, $grade, $uid, $activeSY]);
$validSecIds = array_column($cStmt->fetchAll(), 'id');

if (empty($validSecIds)) {
    json_response(['error' => 'None of the selected sections are assigned to you for this subject\'s grade.'], 403);
}

$pdo->beginTransaction();
try {
    // Insert or touch tae row
    $pdo->prepare(
        "INSERT INTO teacher_assessment_encodings (assessment_id, teacher_id, status)
         VALUES (?,?,'draft')
         ON DUPLICATE KEY UPDATE updated_at = NOW()"
    )->execute([$assessmentId, $uid]);

    // Add sections to assessment_sections
    $asStmt = $pdo->prepare("INSERT IGNORE INTO assessment_sections (assessment_id, section_id) VALUES (?,?)");
    foreach ($validSecIds as $sid) {
        $asStmt->execute([$assessmentId, (int)$sid]);
    }

    $pdo->commit();
    json_response(['success' => true, 'assessment_id' => $assessmentId, 'sections' => count($validSecIds)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Failed to start encoding: ' . $e->getMessage()], 500);
}
