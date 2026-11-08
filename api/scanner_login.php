<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/scanner_api.php';
require_once __DIR__ . '/../includes/config.php';

scanner_api_cors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
$pdo = get_pdo();
$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
$limit = $pdo->prepare("SELECT COUNT(*) FROM scanner_audit_events WHERE event_type='login' AND outcome='rejected' AND remote_ip=? AND created_at >= " . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now','-15 minutes')" : 'DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)'));
$limit->execute([$ip]);
if ((int)$limit->fetchColumn() >= 10) {
    scanner_api_login_event($pdo, 'rejected', 429, null, ['reason' => 'rate_limited']);
    json_response(['error' => 'Too many sign-in attempts. Try again in 15 minutes.'], 429);
}
$body = json_decode(file_get_contents('php://input'), true);
$username = trim((string)(is_array($body) ? ($body['username'] ?? '') : ''));
$password = (string)(is_array($body) ? ($body['password'] ?? '') : '');
$stmt = $pdo->prepare('SELECT id, username, last_name, first_name, middle_name, password_hash, role, is_active, must_change_password FROM users WHERE username=? LIMIT 1');
$stmt->execute([$username]);
$user = $stmt->fetch();
if (!$user || !$user['is_active'] || $user['role'] !== 'teacher' || !password_verify($password, (string)$user['password_hash'])) {
    scanner_api_login_event($pdo, 'rejected', 401, $user && $user['role'] === 'teacher' ? $user : null, ['username' => substr($username, 0, 50), 'reason' => 'invalid_credentials_or_role']);
    json_response(['error' => 'Invalid teacher username or password.'], 401);
}
if (!empty($user['must_change_password'])) {
    scanner_api_login_event($pdo, 'rejected', 403, $user, ['username' => $username, 'reason' => 'password_change_required']);
    json_response(['error' => 'Change your MPS password before signing into the scanner.'], 403);
}
$token = 'mps_' . bin2hex(random_bytes(32));
$expiry = time() + 8 * 60 * 60;
$insert = $pdo->prepare('INSERT INTO scanner_api_tokens (teacher_id, token_hash, expires_at_epoch) VALUES (?, ?, ?)');
$insert->execute([(int)$user['id'], hash('sha256', $token), $expiry]);
$tokenId = (int)$pdo->lastInsertId();
scanner_api_login_event($pdo, 'accepted', 200, $user, ['expires_at_epoch' => $expiry]);
record_scanner_audit($pdo, ['event_type' => 'session', 'outcome' => 'issued', 'response_status' => 200, 'actor_id' => (int)$user['id'], 'actor_username' => $user['username'], 'actor_display' => scanner_actor_display($user), 'scanner_token_id' => $tokenId]);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
header('Cache-Control: no-store, private');
json_response(['token' => $token, 'teacher_id' => (int)$user['id'], 'teacher_display' => scanner_actor_display($user), 'expires_at' => gmdate('c', $expiry), 'api_base_url' => $scheme . '://' . $host . rtrim(BASE_URL, '/')]);
