<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
require_login('admin');
verify_csrf();

$subjectId = validate_int($_POST['subject_id'] ?? null, 1);
$termId    = validate_int($_POST['term_id']    ?? null, 1);
$csvText   = trim($_POST['csv_text'] ?? '');

if (!$subjectId) json_response(['error' => 'Missing subject_id.'], 400);
if (!$termId)    json_response(['error' => 'A specific Term must be selected to import competencies.'], 422);
if ($csvText === '') json_response(['error' => 'CSV text is empty.'], 422);

$lines  = preg_split('/\r?\n/', $csvText);
$rows   = [];
$errors = [];

foreach ($lines as $i => $line) {
    $line = trim($line);
    if ($line === '') continue;
    $parts = str_getcsv($line);
    if (count($parts) >= 2) {
        $code = mb_substr(trim($parts[0]), 0, 60);
        $desc = mb_substr(trim($parts[1]), 0, 500);
    } else {
        $code = '';
        $desc = mb_substr(trim($parts[0] ?? ''), 0, 500);
    }
    if ($desc === '') { $errors[] = "Line " . ($i + 1) . ": empty description"; continue; }
    $rows[] = ['code' => $code ?: null, 'description' => $desc];
}

if (empty($rows)) {
    json_response(['error' => 'No valid rows. Errors: ' . implode('; ', $errors)], 422);
}

$pdo      = get_pdo();
$stmt     = $pdo->prepare("INSERT INTO competencies (subject_id, term_id, code, description) VALUES (?,?,?,?)");
$inserted = 0;
foreach ($rows as $row) {
    try {
        $stmt->execute([$subjectId, $termId ?: null, $row['code'], $row['description']]);
        $inserted++;
    } catch (Throwable) { /* skip duplicates/errors */ }
}

json_response(['success' => true, 'inserted' => $inserted, 'parse_errors' => count($errors)]);
