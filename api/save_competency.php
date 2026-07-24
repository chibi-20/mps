<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
require_login('admin');
verify_csrf();

$raw    = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $raw['action'] ?? '';
$pdo    = get_pdo();

if ($action === 'insert' || $action === 'update') {
    $subjectId   = validate_int($raw['subject_id'] ?? null, 1);
    $termId      = validate_int($raw['term_id']    ?? null, 1);
    $code        = mb_substr(trim($raw['code']        ?? ''), 0, 60);
    $description = mb_substr(trim($raw['description'] ?? ''), 0, 500);

    if (!$subjectId)        json_response(['error' => 'Missing subject_id.'], 400);
    if ($description === '') json_response(['error' => 'Description is required.'], 422);

    if ($action === 'insert') {
        $pdo->prepare(
            "INSERT INTO competencies (subject_id, term_id, code, description) VALUES (?,?,?,?)"
        )->execute([$subjectId, $termId ?: null, $code ?: null, $description]);
        json_response(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    $id = validate_int($raw['id'] ?? null, 1);
    if (!$id) json_response(['error' => 'Missing id.'], 400);
    $pdo->prepare(
        "UPDATE competencies SET subject_id=?, term_id=?, code=?, description=? WHERE id=?"
    )->execute([$subjectId, $termId ?: null, $code ?: null, $description, $id]);
    json_response(['success' => true]);

} elseif ($action === 'delete') {
    $id = validate_int($raw['id'] ?? null, 1);
    if (!$id) json_response(['error' => 'Missing id.'], 400);

    // Block delete if used by an assessment
    $inUse = $pdo->prepare("SELECT COUNT(*) FROM assessment_item_competencies WHERE competency_id = ?");
    $inUse->execute([$id]);
    if ((int)$inUse->fetchColumn() > 0) {
        json_response(['error' => 'This competency is mapped to one or more assessments and cannot be deleted.'], 409);
    }

    $pdo->prepare("DELETE FROM competencies WHERE id = ?")->execute([$id]);
    json_response(['success' => true]);

} else {
    json_response(['error' => 'Invalid action.'], 400);
}
