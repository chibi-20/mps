<?php
/**
 * Submission Compliance list for the admin dashboard.
 * Combines legacy assessments (teacher-owned, assessments.status) with
 * shared assessments (admin-created, per-teacher status in
 * teacher_assessment_encodings) — a shared assessment has no
 * assessments.teacher_id, so it must be pulled from teacher_assessment_encodings
 * or it silently disappears from this list.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');
$pdo = get_pdo();

$stmt = $pdo->query(
    "SELECT a.id, a.title, a.status, a.type, u.last_name, u.first_name, u.middle_name,
            s.name AS subject_name, t.term_no, 0 AS is_shared, NULL AS teacher_id,
            a.updated_at
     FROM assessments a
     JOIN subjects s ON s.id = a.subject_id
     JOIN terms t ON t.id = a.term_id
     JOIN users u ON u.id = a.teacher_id
     WHERE a.is_shared = 0

     UNION ALL

     SELECT a.id, a.title, tae.status, a.type, u.last_name, u.first_name, u.middle_name,
            s.name AS subject_name, t.term_no, 1 AS is_shared, tae.teacher_id,
            tae.updated_at
     FROM teacher_assessment_encodings tae
     JOIN assessments a ON a.id = tae.assessment_id
     JOIN subjects s ON s.id = a.subject_id
     JOIN terms t ON t.id = a.term_id
     JOIN users u ON u.id = tae.teacher_id
     WHERE a.is_shared = 1

     ORDER BY updated_at DESC"
);
$rows = $stmt->fetchAll();

$submissions = array_map(function($r) {
    $r['teacher_name'] = display_name($r);
    $r['is_shared']    = (int)$r['is_shared'];
    $r['teacher_id']   = $r['teacher_id'] !== null ? (int)$r['teacher_id'] : null;
    return $r;
}, $rows);

json_response(['submissions' => $submissions]);
