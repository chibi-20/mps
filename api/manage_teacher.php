<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$sess = require_login('admin');
verify_csrf();

$raw        = json_decode(file_get_contents('php://input'), true) ?? [];
$teacher_id = validate_int($raw['teacher_id'] ?? null, 1);
$action     = $raw['action'] ?? '';

if (!$teacher_id || !in_array($action, ['approve','deactivate'], true)) {
    json_response(['error' => 'Invalid request.'], 400);
}

$pdo  = get_pdo();
$stmt = $pdo->prepare("SELECT id, role, is_active FROM users WHERE id = ?");
$stmt->execute([$teacher_id]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'teacher') {
    json_response(['error' => 'Teacher not found.'], 404);
}

$newActive = $action === 'approve' ? 1 : 0;
$pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")
    ->execute([$newActive, $teacher_id]);

json_response(['success' => true, 'is_active' => $newActive]);
