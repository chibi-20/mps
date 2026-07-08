<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Enforce login; optionally enforce a specific role.
 * Returns the $_SESSION array on success; redirects/exits on failure.
 */
function require_login(string $required_role = ''): array
{
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
    if ($required_role !== '' && $_SESSION['role'] !== $required_role) {
        http_response_code(403);
        exit('Access denied.');
    }
    return $_SESSION;
}

/**
 * Get-or-create the CSRF token for the current session.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token from POST body or X-CSRF-TOKEN header.
 * Exits with 403 JSON on failure.
 */
function verify_csrf(): void
{
    $token = $_POST['csrf_token']
          ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    $expected = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        exit(json_encode(['error' => 'Invalid CSRF token.']));
    }
}

/**
 * Send a JSON response and exit.
 */
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    exit(json_encode($data));
}

/**
 * Convenience: return current logged-in user data from session.
 */
function current_user(): array
{
    return [
        'id'         => $_SESSION['user_id']   ?? 0,
        'role'       => $_SESSION['role']       ?? '',
        'display'    => $_SESSION['display']    ?? '',
        'last_name'  => $_SESSION['last_name']  ?? '',
    ];
}
