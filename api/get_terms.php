<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');
$pdo  = get_pdo();
$syId = validate_int($_GET['sy_id'] ?? null, 1);
if (!$syId) json_response(['terms' => []]);
$stmt = $pdo->prepare("SELECT id, term_no, name, start_date, end_date FROM terms WHERE school_year_id = ? ORDER BY term_no");
$stmt->execute([$syId]);
$terms = $stmt->fetchAll();
foreach ($terms as &$t) {
    $t['label'] = termLabel($t);
}
unset($t);
json_response(['terms' => $terms]);
