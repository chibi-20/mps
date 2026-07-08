<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');
$pdo = get_pdo();

$stmt = $pdo->query(
    "SELECT u.id, u.last_name, u.first_name, u.middle_name, u.username, u.is_active, u.created_at,
            GROUP_CONCAT(DISTINCT ugl.grade_level ORDER BY ugl.grade_level SEPARATOR ', ') AS grade_levels,
            GROUP_CONCAT(DISTINCT us.subject_name  ORDER BY us.subject_name  SEPARATOR ', ') AS subjects
     FROM users u
     LEFT JOIN user_grade_levels ugl ON ugl.user_id = u.id
     LEFT JOIN user_subjects us ON us.user_id = u.id
     WHERE u.role = 'teacher'
     GROUP BY u.id
     ORDER BY u.is_active DESC, u.last_name"
);
$rows = $stmt->fetchAll();

$pending = []; $active = [];
foreach ($rows as $r) {
    $r['display_name'] = display_name($r);
    $r['grade_levels'] = $r['grade_levels'] ? 'G' . str_replace(', ', ', G', $r['grade_levels']) : '—';
    $r['subjects']     = $r['subjects'] ?: '—';
    if ((int)$r['is_active'] === 0) $pending[] = $r;
    else                             $active[]  = $r;
}

json_response(['pending' => $pending, 'active' => $active]);
