<?php
/**
 * Admin: generate a temporary password for a teacher.
 * Returns the plaintext temp password ONCE in the JSON response (never stored).
 * Sets must_change_password = 1 so the teacher is forced to set their own on next login.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

require_login('admin');
verify_csrf();

$raw = json_decode(file_get_contents('php://input'), true) ?? [];
$uid = validate_int($raw['user_id'] ?? null, 1);
if (!$uid) json_response(['error' => 'Missing user_id.'], 400);

$pdo     = get_pdo();
$adminId = (int)$_SESSION['user_id'];

// Verify target is an active teacher (not admin, not pending)
$stmt = $pdo->prepare(
    "SELECT id, username, last_name, first_name, middle_name
     FROM users WHERE id = ? AND role = 'teacher' AND is_active = 1"
);
$stmt->execute([$uid]);
$teacher = $stmt->fetch();
if (!$teacher) json_response(['error' => 'Active teacher not found.'], 404);

$tempPwd = 'ilovejacobo';
$hash    = password_hash($tempPwd, PASSWORD_DEFAULT);

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?"
    )->execute([$hash, $uid]);

    // Close any open reset requests for this teacher (log who handled it)
    $pdo->prepare(
        "UPDATE password_reset_requests
         SET status = 'done', handled_by = ?, handled_at = NOW()
         WHERE user_id = ? AND status = 'pending'"
    )->execute([$adminId, $uid]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Reset failed: ' . $e->getMessage()], 500);
}

// Plaintext returned once — admin relays it to teacher; only the hash persists
json_response([
    'success'       => true,
    'temp_password' => $tempPwd,
    'teacher_name'  => display_name($teacher),
    'username'      => $teacher['username'],
]);
