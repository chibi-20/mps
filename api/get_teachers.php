<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');
$pdo = get_pdo();

$stmt = $pdo->query(
    "SELECT u.id, u.last_name, u.first_name, u.middle_name, u.username, u.is_active,
            u.must_change_password, u.created_at,
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

$pending = [];
$active  = [];
foreach ($rows as $r) {
    $r['display_name']        = display_name($r);
    $r['grade_levels']        = $r['grade_levels'] ? 'G' . str_replace(', ', ', G', $r['grade_levels']) : '—';
    $r['subjects']            = $r['subjects'] ?: '—';
    $r['must_change_password'] = (int)$r['must_change_password'];
    if ((int)$r['is_active'] === 0) $pending[] = $r;
    else                             $active[]  = $r;
}

// Pending password reset requests for the admin's attention
$rrStmt = $pdo->query(
    "SELECT prr.id, prr.username_submitted, prr.requested_at,
            u.id AS user_id, u.last_name, u.first_name, u.middle_name, u.username
     FROM password_reset_requests prr
     LEFT JOIN users u ON u.id = prr.user_id
     WHERE prr.status = 'pending'
     ORDER BY prr.requested_at ASC"
);
$pendingResets = $rrStmt->fetchAll();
foreach ($pendingResets as &$pr) {
    $pr['display_name'] = $pr['user_id']
        ? display_name($pr)
        : '(' . $pr['username_submitted'] . ' — not found)';
}
unset($pr);

json_response(['pending' => $pending, 'active' => $active, 'pending_resets' => $pendingResets]);
