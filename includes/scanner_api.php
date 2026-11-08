<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

function scanner_api_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $configured = array_filter(array_map('trim', explode(',', getenv('MPS_SCANNER_CORS_ORIGINS') ?: '')));
    if ($origin !== '' && in_array($origin, $configured, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        if ($origin === '' || !in_array($origin, $configured, true)) json_response(['error' => 'Origin is not allowed.'], 403);
        http_response_code(204);
        exit;
    }
}

function scanner_api_teacher(PDO $pdo): array
{
    $identity = scanner_api_find_teacher($pdo);
    if (!$identity) json_response(['error' => 'Invalid or revoked scanner token.'], 401);
    return $identity;
}

function scanner_api_find_teacher(PDO $pdo): ?array
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(mps_[a-f0-9]{64})$/', $authHeader, $match)) return null;
    $stmt = $pdo->prepare('SELECT t.id, t.teacher_id, u.username, u.last_name, u.first_name, u.middle_name FROM scanner_api_tokens t JOIN users u ON u.id=t.teacher_id WHERE t.token_hash=? AND t.revoked_at IS NULL AND t.expires_at_epoch > ? AND u.role=\'teacher\' AND u.is_active=1');
    $stmt->execute([hash('sha256', $match[1]), time()]);
    $identity = $stmt->fetch();
    return $identity ?: null;
}

function scanner_api_login_event(PDO $pdo, string $outcome, int $status, ?array $user, array $payload = []): void
{
    record_scanner_audit($pdo, [
        'event_type' => 'login', 'outcome' => $outcome, 'response_status' => $status,
        'actor_id' => $user['id'] ?? null, 'actor_username' => $user['username'] ?? ($payload['username'] ?? null),
        'actor_display' => $user ? scanner_actor_display($user) : null, 'payload' => $payload,
    ]);
}

function record_scanner_audit(PDO $pdo, array $event): void
{
    $payload = $event['payload'] ?? null;
    $payloadJson = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $stmt = $pdo->prepare('INSERT INTO scanner_audit_events
        (event_type,outcome,response_status,actor_id,actor_username,actor_display,scanner_token_id,submission_id,assessment_id,assessment_title,subject_name,section_id,section_name,student_identifier,score,total_items,payload_json,payload_sha256,remote_ip,user_agent)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $event['event_type'] ?? 'scan', $event['outcome'] ?? 'rejected', $event['response_status'] ?? 500,
        $event['actor_id'] ?? null, $event['actor_username'] ?? null, $event['actor_display'] ?? null,
        $event['scanner_token_id'] ?? null, $event['submission_id'] ?? null, $event['assessment_id'] ?? null,
        $event['assessment_title'] ?? null, $event['subject_name'] ?? null, $event['section_id'] ?? null,
        $event['section_name'] ?? null, $event['student_identifier'] ?? null, $event['score'] ?? null,
        $event['total_items'] ?? null, $payloadJson, $payloadJson === null ? null : hash('sha256', $payloadJson),
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
    ]);
}

function scanner_actor_display(array $identity): string
{
    $name = trim((string)($identity['last_name'] ?? '') . ', ' . (string)($identity['first_name'] ?? ''));
    $middle = trim((string)($identity['middle_name'] ?? ''));
    if ($middle !== '') $name .= ' ' . strtoupper(mb_substr($middle, 0, 1)) . '.';
    return trim($name, ', ');
}
