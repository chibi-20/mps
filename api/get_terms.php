<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');
$pdo  = get_pdo();
$syId = validate_int($_GET['sy_id'] ?? null, 1);
if (!$syId) json_response(['terms' => []]);
$stmt = $pdo->prepare("SELECT id, term_no, name FROM terms WHERE school_year_id = ? ORDER BY term_no");
$stmt->execute([$syId]);
json_response(['terms' => $stmt->fetchAll()]);
