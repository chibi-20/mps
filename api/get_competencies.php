<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$pdo       = get_pdo();
$subjectId = validate_int($_GET['subject_id'] ?? null, 1);
$termId    = validate_int($_GET['term_id']    ?? null, 1);

if (!$subjectId) json_response(['error' => 'Missing subject_id.'], 400);

$where  = ['c.subject_id = ?'];
$params = [$subjectId];
if ($termId) { $where[] = 'c.term_id = ?'; $params[] = $termId; }

$stmt = $pdo->prepare(
    "SELECT c.id, c.code, c.description, c.term_id
     FROM competencies c
     WHERE " . implode(' AND ', $where) . "
     ORDER BY c.code, c.id"
);
$stmt->execute($params);
json_response(['competencies' => $stmt->fetchAll()]);
