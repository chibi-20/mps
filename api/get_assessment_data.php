<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$sess = require_login();
$uid  = (int)$sess['user_id'];
$role = $sess['role'];
$id   = validate_int($_GET['id'] ?? null, 1);
if (!$id) json_response(['error' => 'Missing assessment id.'], 400);

$pdo  = get_pdo();

// Fetch assessment (role-gated)
$stmt = $pdo->prepare(
    "SELECT a.id, a.teacher_id, a.subject_id, a.term_id, a.type, a.title,
            a.total_items, a.date_given, a.status, a.remarks,
            s.name AS subject_name, s.grade_level,
            t.term_no, t.name AS term_name
     FROM assessments a
     JOIN subjects s ON s.id = a.subject_id
     JOIN terms t ON t.id = a.term_id
     WHERE a.id = ?"
);
$stmt->execute([$id]);
$assessment = $stmt->fetch();

if (!$assessment) json_response(['error' => 'Assessment not found.'], 404);

// Teachers can only view their own
if ($role === 'teacher' && (int)$assessment['teacher_id'] !== $uid) {
    json_response(['error' => 'Access denied.'], 403);
}

// Sections chosen for this specific assessment
$secStmt = $pdo->prepare(
    "SELECT sec.id, sec.name, sec.grade_level
     FROM assessment_sections as_
     JOIN sections sec ON sec.id = as_.section_id
     WHERE as_.assessment_id = ?
     ORDER BY sec.name"
);
$secStmt->execute([$id]);
$sections = $secStmt->fetchAll();

// Score frequencies indexed [section_id][score] = frequency
$sfStmt = $pdo->prepare(
    "SELECT section_id, score, frequency
     FROM score_frequencies
     WHERE assessment_id = ? AND frequency > 0"
);
$sfStmt->execute([$id]);
$sfRaw = $sfStmt->fetchAll();
$sf = [];
foreach ($sfRaw as $r) {
    $sf[(int)$r['section_id']][(int)$r['score']] = (int)$r['frequency'];
}

// Item correct counts indexed [section_id][item_no] = correct_count
$iccStmt = $pdo->prepare(
    "SELECT section_id, item_no, correct_count
     FROM item_correct_counts
     WHERE assessment_id = ?"
);
$iccStmt->execute([$id]);
$iccRaw = $iccStmt->fetchAll();
$icc = [];
foreach ($iccRaw as $r) {
    $icc[(int)$r['section_id']][(int)$r['item_no']] = (int)$r['correct_count'];
}

json_response([
    'assessment'          => $assessment,
    'sections'            => $sections,
    'score_frequencies'   => $sf,
    'item_correct_counts' => $icc,
]);
