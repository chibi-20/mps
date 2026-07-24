<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$sess = require_login('teacher');
$uid  = (int)$sess['user_id'];
$pdo  = get_pdo();

// Teacher's subject IDs
$subjStmt = $pdo->prepare(
    "SELECT s.id FROM subjects s
     JOIN user_subjects us ON LOWER(TRIM(s.name)) = LOWER(TRIM(us.subject_name))
     WHERE us.user_id = ?"
);
$subjStmt->execute([$uid]);
$subjectIds = array_column($subjStmt->fetchAll(), 'id');

if (empty($subjectIds)) {
    json_response(['assessments' => []]);
}

// Assessments the teacher is already encoding
$taeStmt = $pdo->prepare("SELECT assessment_id FROM teacher_assessment_encodings WHERE teacher_id = ?");
$taeStmt->execute([$uid]);
$alreadyEncoding = array_column($taeStmt->fetchAll(), 'assessment_id', 'assessment_id');

$in    = implode(',', array_fill(0, count($subjectIds), '?'));
$aStmt = $pdo->prepare(
    "SELECT a.id, a.title, a.type, a.total_items, a.date_given,
            s.id AS subject_id, s.name AS subject_name, s.grade_level,
            t.id AS term_id, t.term_no, t.name AS term_name,
            sy.name AS sy_name
     FROM assessments a
     JOIN subjects s ON s.id = a.subject_id
     JOIN terms t ON t.id = a.term_id
     JOIN school_years sy ON sy.id = t.school_year_id
     WHERE a.is_shared = 1
       AND a.status = 'approved'
       AND a.subject_id IN ({$in})
     ORDER BY sy.id DESC, t.term_no, a.date_given, a.title"
);
$aStmt->execute($subjectIds);
$assessments = $aStmt->fetchAll();

foreach ($assessments as &$a) {
    $a['already_encoding'] = isset($alreadyEncoding[$a['id']]);
}
unset($a);

json_response(['assessments' => $assessments]);
