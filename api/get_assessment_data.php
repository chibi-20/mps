<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$sess = require_login();
$uid  = (int)$sess['user_id'];
$role = $sess['role'];
$id   = validate_int($_GET['id'] ?? null, 1);
if (!$id) json_response(['error' => 'Missing assessment id.'], 400);

$pdo = get_pdo();

$stmt = $pdo->prepare(
    "SELECT a.id, a.teacher_id, a.subject_id, a.term_id, a.type, a.title,
            a.total_items, a.date_given, a.status, a.remarks,
            COALESCE(a.is_shared, 0) AS is_shared,
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

$isShared = (bool)$assessment['is_shared'];

if ($role === 'teacher') {
    if ($isShared) {
        $taeChk = $pdo->prepare(
            "SELECT status, remarks FROM teacher_assessment_encodings WHERE assessment_id = ? AND teacher_id = ?"
        );
        $taeChk->execute([$id, $uid]);
        $tae = $taeChk->fetch();
        if (!$tae) json_response(['error' => 'You have not started encoding this assessment.'], 403);
        // Override status/remarks with teacher's personal encoding status
        $assessment['status']  = $tae['status'];
        $assessment['remarks'] = $tae['remarks'];
    } else {
        if ((int)$assessment['teacher_id'] !== $uid) json_response(['error' => 'Access denied.'], 403);
    }
}

// For shared assessments, a teacher sees only their own sections
if ($role === 'teacher' && $isShared) {
    $activeSY = $pdo->query("SELECT id FROM school_years WHERE is_active=1 LIMIT 1")->fetchColumn();
    $secStmt  = $pdo->prepare(
        "SELECT sec.id, sec.name, sec.grade_level
         FROM assessment_sections as_
         JOIN sections sec ON sec.id = as_.section_id
         JOIN teacher_assignments ta ON ta.section_id = sec.id
         WHERE as_.assessment_id = ? AND ta.teacher_id = ? AND ta.school_year_id = ?
         ORDER BY sec.name"
    );
    $secStmt->execute([$id, $uid, $activeSY ?: 0]);
} else {
    $secStmt = $pdo->prepare(
        "SELECT sec.id, sec.name, sec.grade_level
         FROM assessment_sections as_
         JOIN sections sec ON sec.id = as_.section_id
         WHERE as_.assessment_id = ?
         ORDER BY sec.name"
    );
    $secStmt->execute([$id]);
}
$sections = $secStmt->fetchAll();

// Score frequencies
$sfStmt = $pdo->prepare(
    "SELECT section_id, score, frequency FROM score_frequencies WHERE assessment_id = ? AND frequency > 0"
);
$sfStmt->execute([$id]);
$sf = [];
foreach ($sfStmt->fetchAll() as $r) {
    $sf[(int)$r['section_id']][(int)$r['score']] = (int)$r['frequency'];
}

// Item correct counts
$iccStmt = $pdo->prepare("SELECT section_id, item_no, correct_count FROM item_correct_counts WHERE assessment_id = ?");
$iccStmt->execute([$id]);
$icc = [];
foreach ($iccStmt->fetchAll() as $r) {
    $icc[(int)$r['section_id']][(int)$r['item_no']] = (int)$r['correct_count'];
}

// Competency map: item_no → {competency_id, code, description}
$compStmt = $pdo->prepare(
    "SELECT aic.item_no, c.id AS competency_id, c.code, c.description
     FROM assessment_item_competencies aic
     JOIN competencies c ON c.id = aic.competency_id
     WHERE aic.assessment_id = ?
     ORDER BY aic.item_no"
);
$compStmt->execute([$id]);
$competencyMap = [];
foreach ($compStmt->fetchAll() as $r) {
    $competencyMap[(int)$r['item_no']] = [
        'competency_id' => (int)$r['competency_id'],
        'code'          => $r['code'] ?? '',
        'description'   => $r['description'],
    ];
}

json_response([
    'assessment'          => $assessment,
    'sections'            => $sections,
    'score_frequencies'   => $sf,
    'item_correct_counts' => $icc,
    'competency_map'      => $competencyMap,
]);
