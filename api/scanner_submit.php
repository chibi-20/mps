<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/scanner_api.php';
scanner_api_cors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
$pdo = get_pdo();
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$token = scanner_api_find_teacher($pdo);
$event = ['event_type' => 'scan', 'outcome' => 'rejected', 'response_status' => 401, 'payload' => is_array($body) ? $body : ['invalid_json' => true, 'body_sha256' => hash('sha256', $raw)]];
if ($token) {
    $event += ['actor_id' => (int)$token['teacher_id'], 'actor_username' => $token['username'], 'actor_display' => scanner_actor_display($token), 'scanner_token_id' => (int)$token['id']];
}
$reject = static function (string $message, int $status, array $context = []) use ($pdo, &$event): never {
    $event = array_merge($event, $context, ['outcome' => 'rejected', 'response_status' => $status]);
    record_scanner_audit($pdo, $event);
    json_response(['error' => $message], $status);
};
if (!$token) $reject('Valid teacher sign-in required.', 401);
if (!is_array($body)) $reject('JSON body required.', 400);
$idempotencyKey = strtolower(trim((string)($body['submission_id'] ?? '')));
$assessmentId = validate_int($body['assessment_id'] ?? null, 1);
$sectionId = validate_int($body['section_id'] ?? null, 1);
$studentId = trim((string)($body['student_id'] ?? ''));
$answers = $body['answers'] ?? null;
$context = ['submission_id' => $idempotencyKey ?: null, 'assessment_id' => $assessmentId, 'section_id' => $sectionId, 'student_identifier' => substr($studentId, 0, 100)];
$event = array_merge($event, $context);
if (!preg_match('/^[a-f0-9-]{36}$/', $idempotencyKey)) $reject('submission_id must be a UUID.', 422, $context);
if (!$assessmentId || !$sectionId || $studentId === '' || strlen($studentId) > 100 || !is_array($answers)) $reject('assessment_id, section_id, student_id, and answers are required.', 422, $context);
if (preg_match('/[\x00-\x1F]/', $studentId)) $reject('student_id contains invalid characters.', 422, $context);
$teacherId = (int)$token['teacher_id'];
$stmt = $pdo->prepare("SELECT a.total_items, a.status, a.title, a.subject_id, t.school_year_id,
        s.name AS subject_name, COALESCE(a.is_shared,0) AS is_shared, tae.status AS encoding_status
    FROM assessments a JOIN subjects s ON s.id=a.subject_id LEFT JOIN teacher_assessment_encodings tae ON tae.assessment_id=a.id AND tae.teacher_id=?
    JOIN terms t ON t.id=a.term_id
    WHERE a.id=? AND ((COALESCE(a.is_shared,0)=0 AND a.teacher_id=?) OR (COALESCE(a.is_shared,0)=1 AND tae.id IS NOT NULL))");
$stmt->execute([$teacherId, $assessmentId, $teacherId]);
$asmt = $stmt->fetch();
if (!$asmt) $reject('Assessment not found or access denied.', 403, $context);
$sectionStmt = $pdo->prepare('SELECT s.name FROM sections s JOIN assessment_sections x ON x.section_id=s.id WHERE x.assessment_id=? AND x.section_id=?');
$sectionStmt->execute([$assessmentId, $sectionId]);
$sectionName = $sectionStmt->fetchColumn();
$context += ['assessment_title' => $asmt['title'], 'subject_name' => $asmt['subject_name'], 'section_name' => $sectionName ?: null];
$event = array_merge($event, $context);
if (in_array($asmt['status'], ['submitted','approved'], true) || in_array($asmt['encoding_status'] ?? '', ['submitted','approved'], true)) $reject('Assessment is locked.', 409, $context);
if (!$sectionName) $reject('Section is not linked to this assessment.', 403, $context);
$assignmentStmt = $pdo->prepare('SELECT 1 FROM teacher_assignments WHERE teacher_id=? AND subject_id=? AND section_id=? AND school_year_id=?');
$assignmentStmt->execute([$teacherId, (int)$asmt['subject_id'], $sectionId, (int)$asmt['school_year_id']]);
if (!$assignmentStmt->fetchColumn()) $reject('You are not assigned to this section for the assessment subject and school year.', 403, $context);
$modeStmt = $pdo->prepare('SELECT mode FROM assessment_section_ingestion_modes WHERE assessment_id=? AND section_id=?');
$modeStmt->execute([$assessmentId, $sectionId]);
$ingestionMode = $modeStmt->fetchColumn();
if ($ingestionMode === 'zipgrade') $reject('This assessment section already uses ZipGrade CSV data. Choose one import method for the section or clear its previous results before switching.', 409, $context);
$total = (int)$asmt['total_items'];
if ($total > 50 || count($answers) !== $total) $reject("This scanner supports 1–50 items and requires all {$total} answer slots.", 422, $context + ['total_items' => $total]);
$normalized = [];
foreach ($answers as $index => $entry) {
    $item = is_array($entry) ? (int)($entry['item_no'] ?? 0) : $index + 1;
    $choice = strtoupper(trim((string)(is_array($entry) ? ($entry['choice'] ?? '') : $entry)));
    if ($choice === '' || $choice === 'BLANK') $choice = null;
    if ($item < 1 || $item > $total || ($choice !== null && !in_array($choice, ['A','B','C','D'], true)) || isset($normalized[$item])) $reject('Answers must uniquely cover items 1..N with A-D choices or blank values.', 422, $context + ['total_items' => $total]);
    $normalized[$item] = $choice;
}
ksort($normalized);
if (array_keys($normalized) !== range(1, $total)) $reject('Answers must cover every item from 1 through N.', 422, $context + ['total_items' => $total]);
$keyStmt = $pdo->prepare('SELECT item_no, correct_choice FROM assessment_answer_keys WHERE assessment_id=? ORDER BY item_no');
$keyStmt->execute([$assessmentId]);
$keyRows = $keyStmt->fetchAll();
if (count($keyRows) !== $total) $reject('Assessment answer key is incomplete; save the key in MPS before scanning.', 409, $context + ['total_items' => $total]);
$key = [];
foreach ($keyRows as $row) $key[(int)$row['item_no']] = $row['correct_choice'];
$score = 0; $correct = [];
foreach ($normalized as $item => $choice) { $correct[$item] = $choice !== null && $choice === ($key[$item] ?? null); if ($correct[$item]) $score++; }
$pdo->beginTransaction();
try {
    $duplicate = $pdo->prepare('SELECT id, assessment_id, section_id, student_identifier FROM scanner_submissions WHERE source_submission_id=?');
    $duplicate->execute([$idempotencyKey]);
    if ($existing = $duplicate->fetch()) {
        if ((int)$existing['assessment_id'] !== $assessmentId || (int)$existing['section_id'] !== $sectionId || $existing['student_identifier'] !== $studentId) {
            $pdo->rollBack(); $reject('submission_id was already used for a different scan.', 409, $context);
        }
        $context += ['score' => $score, 'total_items' => $total];
        record_scanner_audit($pdo, array_merge($event, $context, ['outcome' => 'duplicate', 'response_status' => 200]));
        $pdo->commit();
        json_response(['success' => true, 'duplicate' => true, 'score' => (int)$score, 'total_items' => $total]);
    }
    $existingStmt = $pdo->prepare('SELECT id FROM scanner_submissions WHERE assessment_id=? AND section_id=? AND student_identifier=?');
    $existingStmt->execute([$assessmentId, $sectionId, $studentId]);
    $existing = $existingStmt->fetchColumn();
    if ($existing) $pdo->prepare('DELETE FROM scanner_submissions WHERE id=?')->execute([$existing]);
    if ($ingestionMode === false) $pdo->prepare("INSERT INTO assessment_section_ingestion_modes (assessment_id,section_id,mode) VALUES (?,?,'scanner')")->execute([$assessmentId, $sectionId]);
    $insert = $pdo->prepare('INSERT INTO scanner_submissions (assessment_id,section_id,student_identifier,score,total_items,source_submission_id) VALUES (?,?,?,?,?,?)');
    $insert->execute([$assessmentId, $sectionId, $studentId, $score, $total, $idempotencyKey]);
    $submissionDbId = (int)$pdo->lastInsertId();
    $answerInsert = $pdo->prepare('INSERT INTO scanner_submission_answers (submission_id,item_no,selected_choice,is_correct) VALUES (?,?,?,?)');
    foreach ($normalized as $item => $choice) $answerInsert->execute([$submissionDbId, $item, $choice, (int)$correct[$item]]);
    $pdo->prepare('DELETE FROM score_frequencies WHERE assessment_id=? AND section_id=?')->execute([$assessmentId, $sectionId]);
    $frequencies = $pdo->prepare('SELECT score, COUNT(*) AS frequency FROM scanner_submissions WHERE assessment_id=? AND section_id=? GROUP BY score');
    $frequencies->execute([$assessmentId, $sectionId]);
    $freqInsert = $pdo->prepare('INSERT INTO score_frequencies (assessment_id,section_id,score,frequency) VALUES (?,?,?,?)');
    foreach ($frequencies->fetchAll() as $row) $freqInsert->execute([$assessmentId, $sectionId, $row['score'], $row['frequency']]);
    $pdo->prepare('DELETE FROM item_correct_counts WHERE assessment_id=? AND section_id=?')->execute([$assessmentId, $sectionId]);
    $items = $pdo->prepare('SELECT a.item_no, SUM(a.is_correct) AS correct_count FROM scanner_submission_answers a JOIN scanner_submissions s ON s.id=a.submission_id WHERE s.assessment_id=? AND s.section_id=? GROUP BY a.item_no');
    $items->execute([$assessmentId, $sectionId]);
    $itemInsert = $pdo->prepare('INSERT INTO item_correct_counts (assessment_id,section_id,item_no,correct_count) VALUES (?,?,?,?)');
    foreach ($items->fetchAll() as $row) $itemInsert->execute([$assessmentId, $sectionId, $row['item_no'], $row['correct_count']]);
    $pdo->prepare('UPDATE scanner_api_tokens SET last_used_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$token['id']]);
    if ((int)$asmt['is_shared']) $pdo->prepare('UPDATE teacher_assessment_encodings SET updated_at=CURRENT_TIMESTAMP WHERE assessment_id=? AND teacher_id=?')->execute([$assessmentId, $teacherId]);
    else $pdo->prepare('UPDATE assessments SET updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$assessmentId]);
    $context += ['score' => $score, 'total_items' => $total];
    record_scanner_audit($pdo, array_merge($event, $context, ['outcome' => 'accepted', 'response_status' => 200]));
    $pdo->commit();
    json_response(['success' => true, 'duplicate' => false, 'score' => $score, 'total_items' => $total]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Scanner submission failed: ' . $e->getMessage());
    $reject('Could not record scan.', 500, $context);
}
