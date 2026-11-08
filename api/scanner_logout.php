<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/scanner_api.php';
scanner_api_cors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
$pdo = get_pdo();
$identity = scanner_api_find_teacher($pdo);
if (!$identity) json_response(['success' => true]);
$pdo->prepare('UPDATE scanner_api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE id=? AND revoked_at IS NULL')->execute([(int)$identity['id']]);
record_scanner_audit($pdo, ['event_type' => 'session', 'outcome' => 'revoked', 'response_status' => 200, 'actor_id' => (int)$identity['teacher_id'], 'actor_username' => $identity['username'], 'actor_display' => scanner_actor_display($identity), 'scanner_token_id' => (int)$identity['id']]);
json_response(['success' => true]);
