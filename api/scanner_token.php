<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$session = require_login('teacher');
$pdo = get_pdo();
$teacherId = (int)$session['user_id'];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT id, label, created_at, last_used_at, revoked_at FROM scanner_api_tokens WHERE teacher_id=? ORDER BY id DESC');
    $stmt->execute([$teacherId]);
    json_response(['tokens' => $stmt->fetchAll()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
verify_csrf();
$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if (($body['action'] ?? 'create') === 'revoke') {
    $id = validate_int($body['token_id'] ?? null, 1);
    if (!$id) json_response(['error' => 'token_id is required.'], 400);
    $stmt = $pdo->prepare('UPDATE scanner_api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE id=? AND teacher_id=? AND revoked_at IS NULL');
    $stmt->execute([$id, $teacherId]);
    json_response(['success' => $stmt->rowCount() > 0]);
}
json_response(['error' => 'Tokens are issued automatically when a teacher signs in to the Scanner PWA.'], 410);
