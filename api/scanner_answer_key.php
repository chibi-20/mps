<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$session = require_login('teacher');
$pdo = get_pdo();
$teacherId = (int)$session['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    $assessmentId = validate_int($_GET['assessment_id'] ?? null, 1);
    if (!$assessmentId) json_response(['error' => 'assessment_id is required.'], 400);
    $stmt = $pdo->prepare("SELECT a.total_items FROM assessments a LEFT JOIN teacher_assessment_encodings tae ON tae.assessment_id=a.id AND tae.teacher_id=? WHERE a.id=? AND ((COALESCE(a.is_shared,0)=0 AND a.teacher_id=?) OR (COALESCE(a.is_shared,0)=1 AND tae.id IS NOT NULL))");
    $stmt->execute([$teacherId, $assessmentId, $teacherId]);
    $assessment = $stmt->fetch();
    if (!$assessment) json_response(['error' => 'Assessment not found or access denied.'], 404);
    $keys = $pdo->prepare('SELECT item_no, correct_choice FROM assessment_answer_keys WHERE assessment_id=? ORDER BY item_no');
    $keys->execute([$assessmentId]);
    json_response(['total_items' => (int)$assessment['total_items'], 'answer_key' => $keys->fetchAll()]);
}
if ($method !== 'PUT' && $method !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
verify_csrf();
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) json_response(['error' => 'JSON body required.'], 400);
$assessmentId = validate_int($body['assessment_id'] ?? null, 1);
$stmt = $pdo->prepare("SELECT a.total_items, a.status, tae.status AS encoding_status FROM assessments a LEFT JOIN teacher_assessment_encodings tae ON tae.assessment_id=a.id AND tae.teacher_id=? WHERE a.id=? AND ((COALESCE(a.is_shared,0)=0 AND a.teacher_id=?) OR (COALESCE(a.is_shared,0)=1 AND tae.id IS NOT NULL))");
$stmt->execute([$teacherId, $assessmentId, $teacherId]);
$assessment = $stmt->fetch();
if (!$assessment) json_response(['error' => 'Assessment not found or access denied.'], 404);
if (in_array($assessment['status'], ['submitted','approved'], true) || in_array($assessment['encoding_status'] ?? '', ['submitted','approved'], true)) json_response(['error' => 'Assessment is locked.'], 409);
$key = $body['answer_key'] ?? null;
$total = (int)$assessment['total_items'];
if (!is_array($key) || count($key) !== $total) json_response(['error' => "Provide exactly {$total} answer choices."], 422);
$choices = [];
foreach ($key as $index => $choice) {
    $item = is_array($choice) ? (int)($choice['item_no'] ?? 0) : $index + 1;
    $value = strtoupper(trim((string)(is_array($choice) ? ($choice['correct_choice'] ?? '') : $choice)));
    if ($item < 1 || $item > $total || !in_array($value, ['A','B','C','D'], true) || isset($choices[$item])) json_response(['error' => 'Answer key entries must uniquely cover items 1..N with A-D choices.'], 422);
    $choices[$item] = $value;
}
ksort($choices);
if (array_keys($choices) !== range(1, $total)) json_response(['error' => 'Answer key must cover every item from 1 through N.'], 422);
$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM assessment_answer_keys WHERE assessment_id=?')->execute([$assessmentId]);
    $insert = $pdo->prepare('INSERT INTO assessment_answer_keys (assessment_id,item_no,correct_choice) VALUES (?,?,?)');
    foreach ($choices as $item => $choice) $insert->execute([$assessmentId, $item, $choice]);

    // If the key is corrected after scanning, recalculate those results and
    // section aggregates from the preserved student answers in this transaction.
    $scans = $pdo->prepare('SELECT id, section_id FROM scanner_submissions WHERE assessment_id=?');
    $scans->execute([$assessmentId]);
    $sectionIds = [];
    $answerRows = $pdo->prepare('SELECT item_no, selected_choice FROM scanner_submission_answers WHERE submission_id=?');
    $updateAnswer = $pdo->prepare('UPDATE scanner_submission_answers SET is_correct=? WHERE submission_id=? AND item_no=?');
    $updateScore = $pdo->prepare('UPDATE scanner_submissions SET score=? WHERE id=?');
    foreach ($scans->fetchAll() as $scan) {
        $scanId = (int)$scan['id'];
        $sectionIds[(int)$scan['section_id']] = true;
        $answerRows->execute([$scanId]);
        $score = 0;
        foreach ($answerRows->fetchAll() as $answer) {
            $correct = ($answer['selected_choice'] !== null && $answer['selected_choice'] === ($choices[(int)$answer['item_no']] ?? null));
            if ($correct) $score++;
            $updateAnswer->execute([(int)$correct, $scanId, $answer['item_no']]);
        }
        $updateScore->execute([$score, $scanId]);
    }
    foreach (array_keys($sectionIds) as $sectionId) {
        $pdo->prepare('DELETE FROM score_frequencies WHERE assessment_id=? AND section_id=?')->execute([$assessmentId, $sectionId]);
        $freqs = $pdo->prepare('SELECT score, COUNT(*) AS frequency FROM scanner_submissions WHERE assessment_id=? AND section_id=? GROUP BY score');
        $freqs->execute([$assessmentId, $sectionId]);
        $freqInsert = $pdo->prepare('INSERT INTO score_frequencies (assessment_id,section_id,score,frequency) VALUES (?,?,?,?)');
        foreach ($freqs->fetchAll() as $row) $freqInsert->execute([$assessmentId, $sectionId, $row['score'], $row['frequency']]);
        $pdo->prepare('DELETE FROM item_correct_counts WHERE assessment_id=? AND section_id=?')->execute([$assessmentId, $sectionId]);
        $items = $pdo->prepare('SELECT a.item_no, SUM(a.is_correct) AS correct_count FROM scanner_submission_answers a JOIN scanner_submissions s ON s.id=a.submission_id WHERE s.assessment_id=? AND s.section_id=? GROUP BY a.item_no');
        $items->execute([$assessmentId, $sectionId]);
        $itemInsert = $pdo->prepare('INSERT INTO item_correct_counts (assessment_id,section_id,item_no,correct_count) VALUES (?,?,?,?)');
        foreach ($items->fetchAll() as $row) $itemInsert->execute([$assessmentId, $sectionId, $row['item_no'], $row['correct_count']]);
    }
    $pdo->commit();
    json_response(['success' => true, 'assessment_id' => $assessmentId, 'items' => $total]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['error' => 'Could not save answer key.'], 500);
}
