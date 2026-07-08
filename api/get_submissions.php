<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');
$pdo = get_pdo();

$stmt = $pdo->query(
    "SELECT a.id, a.title, a.status, a.remarks, a.updated_at,
            s.name AS subject_name, s.grade_level,
            t.term_no,
            u.last_name, u.first_name, u.middle_name
     FROM assessments a
     JOIN subjects s ON s.id = a.subject_id
     JOIN terms t ON t.id = a.term_id
     JOIN users u ON u.id = a.teacher_id
     ORDER BY a.updated_at DESC"
);
$rows = $stmt->fetchAll();

$submissions = array_map(function($r) {
    $r['teacher_name'] = display_name($r);
    return $r;
}, $rows);

json_response(['submissions' => $submissions]);
