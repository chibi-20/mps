<?php
/**
 * Import ZipGrade CSV export for one (assessment, section).
 *
 * POST multipart: assessment_id, section_id, action, csrf_token, csv_file.
 *   action=preview → { preview: { … } }   no DB writes
 *   action=confirm → { success: true, … }  atomically replaces section data
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$sess = require_login('teacher');
verify_csrf();
$uid = (int)$sess['user_id'];

$assessment_id = validate_int($_POST['assessment_id'] ?? null, 1);
$section_id    = validate_int($_POST['section_id']    ?? null, 1);
$action        = $_POST['action'] ?? 'preview';

if (!$assessment_id) json_response(['error' => 'Missing assessment_id.'], 400);
if (!$section_id)    json_response(['error' => 'Missing section_id.'],    400);
if (!in_array($action, ['preview', 'confirm'], true)) {
    json_response(['error' => 'Invalid action.'], 400);
}

$pdo = get_pdo();

// Ownership + status check (handles both legacy and admin-shared assessments)
$stmt = $pdo->prepare("SELECT teacher_id, status, total_items, COALESCE(is_shared,0) AS is_shared FROM assessments WHERE id = ?");
$stmt->execute([$assessment_id]);
$asmt = $stmt->fetch();
if (!$asmt) {
    json_response(['error' => 'Not found or access denied.'], 403);
}

$isShared = (int)$asmt['is_shared'];

if ($isShared) {
    // Shared assessment: verify the teacher has an encoding row and it isn't locked
    $taeChk = $pdo->prepare("SELECT status FROM teacher_assessment_encodings WHERE assessment_id = ? AND teacher_id = ?");
    $taeChk->execute([$assessment_id, $uid]);
    $tae = $taeChk->fetch();
    if (!$tae) {
        json_response(['error' => 'Not found or access denied.'], 403);
    }
    if (in_array($tae['status'], ['submitted', 'approved'], true)) {
        json_response(['error' => 'Assessment is locked and cannot be edited.'], 409);
    }
} else {
    // Legacy assessment: teacher must own it and it must not be locked
    if ((int)$asmt['teacher_id'] !== $uid) {
        json_response(['error' => 'Not found or access denied.'], 403);
    }
    if (in_array($asmt['status'], ['submitted', 'approved'], true)) {
        json_response(['error' => 'Assessment is locked and cannot be edited.'], 409);
    }
}

$totalItems = (int)$asmt['total_items'];

// Section must belong to this assessment
$chk = $pdo->prepare("SELECT 1 FROM assessment_sections WHERE assessment_id = ? AND section_id = ?");
$chk->execute([$assessment_id, $section_id]);
if (!$chk->fetch()) {
    json_response(['error' => 'Section does not belong to this assessment.'], 403);
}

// ---------------------------------------------------------------
// File validation
// ---------------------------------------------------------------
$file = $_FILES['csv_file'] ?? null;
if (!$file) {
    json_response(['error' => 'No file received.'], 400);
}
if ($file['error'] !== UPLOAD_ERR_OK) {
    $uploadMsg = [
        UPLOAD_ERR_INI_SIZE  => 'File exceeds the server upload-size limit.',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds the form upload-size limit.',
        UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
    ][$file['error']] ?? 'Upload error (code ' . $file['error'] . ').';
    json_response(['error' => $uploadMsg], 400);
}
if ($file['size'] > 5 * 1024 * 1024) {
    json_response(['error' => 'File too large (max 5 MB).'], 400);
}
if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
    json_response(['error' => 'File must have a .csv extension.'], 400);
}

// ---------------------------------------------------------------
// isCorrect() — single place to define what "correct" looks like.
// ZipGrade: 1 = correct, 0 = wrong, blank = unanswered (treat as wrong).
// Update this one helper if the export format ever changes.
// ---------------------------------------------------------------
function isCorrect(string $cell): bool
{
    return trim($cell) === '1';
}

// ---------------------------------------------------------------
// Read file, strip UTF-8 BOM, parse via fgetcsv()
// ---------------------------------------------------------------
$content = file_get_contents($file['tmp_name']);
if ($content === false) {
    json_response(['error' => 'Could not read uploaded file.'], 500);
}
// BOM = EF BB BF — strip it so "Quiz Name" parses as the first header token
if (str_starts_with($content, "\xEF\xBB\xBF")) {
    $content = substr($content, 3);
}

// fgetcsv() via in-memory stream handles embedded newlines and \r\n endings correctly
$stream     = fopen('php://memory', 'r+');
fwrite($stream, $content);
rewind($stream);

$allCsvRows = [];
while (($row = fgetcsv($stream, 0, ',')) !== false) {
    $allCsvRows[] = $row;
}
fclose($stream);

if (count($allCsvRows) < 2) {
    json_response(['error' => 'CSV has no data rows (only a header, or file is empty).'], 422);
}

// ---------------------------------------------------------------
// Map header → column index
// ---------------------------------------------------------------
$headerMap = [];
foreach ($allCsvRows[0] as $i => $col) {
    $headerMap[strtolower(trim($col))] = $i;
}

foreach (['num questions', 'num correct'] as $required) {
    if (!isset($headerMap[$required])) {
        json_response([
            'error' => "Missing required column \"{$required}\". "
                     . "Is this a ZipGrade CSV export? (Quiz Name, Class, Num Questions, Num Correct … Q1..Qn)",
        ], 422);
    }
}
$numQIdx    = $headerMap['num questions'];
$numCorrIdx = $headerMap['num correct'];

// Detect Q1..Qn columns in order; stop at the first gap
$qCols = [];   // [item_no (1-based) => csv_col_index]
for ($i = 1; ; $i++) {
    $key = 'q' . $i;
    if (!isset($headerMap[$key])) break;
    $qCols[$i] = $headerMap[$key];
}
$csvItemCount = count($qCols);

if ($csvItemCount === 0) {
    json_response(['error' => 'No Q1..Qn item columns found in the CSV header.'], 422);
}

$itemCountWarning = null;
if ($csvItemCount !== $totalItems) {
    $use = min($csvItemCount, $totalItems);
    $itemCountWarning =
        "CSV has {$csvItemCount} item column(s) (Q1–Q{$csvItemCount}) "
      . "but this assessment expects {$totalItems} item(s). "
      . ($csvItemCount > $totalItems
            ? "Extra CSV columns beyond Q{$totalItems} are ignored."
            : "Items Q" . ($csvItemCount + 1) . "–Q{$totalItems} default to 0.");
}

// ---------------------------------------------------------------
// Process each data row
// ---------------------------------------------------------------
$imported = [];   // rows that passed all checks
$flagged  = [];   // rows with validation issues (still imported — teacher reviews)
$skipped  = 0;    // completely blank rows (silently dropped)
$lineNo   = 1;    // header = line 1

foreach (array_slice($allCsvRows, 1) as $rawRow) {
    $lineNo++;

    $numCorrRaw = trim($rawRow[$numCorrIdx] ?? '');
    $numQRaw    = trim($rawRow[$numQIdx]    ?? '');

    // Silently skip rows where both key cells are blank (trailing empty CSV rows)
    if ($numCorrRaw === '' && $numQRaw === '') {
        $skipped++;
        continue;
    }

    $numCorrect = (int)$numCorrRaw;

    // Tally item values
    $qValues    = [];
    $qSum       = 0;
    $hasBadCell = false;

    foreach ($qCols as $itemNo => $colIdx) {
        if ($itemNo > $totalItems) break;   // ignore CSV columns beyond assessment total
        $cell = trim($rawRow[$colIdx] ?? '');
        if ($cell !== '' && $cell !== '0' && $cell !== '1') {
            $hasBadCell = true;
        }
        $val              = isCorrect($cell) ? 1 : 0;
        $qValues[$itemNo] = $val;
        $qSum            += $val;
    }

    $flags = [];
    if ($hasBadCell) {
        $flags[] = 'unexpected Q-cell value (not 0, 1, or blank)';
    }
    if ($qSum !== $numCorrect) {
        $flags[] = "Q-column sum ({$qSum}) ≠ Num Correct ({$numCorrect})";
    }

    $entry = [
        'line'        => $lineNo,
        'num_correct' => $numCorrect,
        'q_values'    => $qValues,
        'flags'       => $flags,
    ];

    if (!empty($flags)) {
        $flagged[] = $entry;
    } else {
        $imported[] = $entry;
    }
}

$allRows = array_merge($imported, $flagged);

if (empty($allRows)) {
    json_response(['error' => 'No usable data rows found (all rows were blank or skipped).'], 422);
}

// ---------------------------------------------------------------
// Aggregate: score frequencies + item correct counts
// Both use ALL rows (flagged ones included; teacher sees the flags)
// ---------------------------------------------------------------
$freqDist   = [];
$itemCounts = array_fill(1, $totalItems, 0);

foreach ($allRows as $r) {
    // Score frequency uses num_correct column
    $score = max(0, min((int)$r['num_correct'], $totalItems));
    $freqDist[$score] = ($freqDist[$score] ?? 0) + 1;

    // Item counts use Q-column values
    foreach ($r['q_values'] as $itemNo => $val) {
        if (isset($itemCounts[$itemNo])) {
            $itemCounts[$itemNo] += $val;
        }
    }
}

$totalStudents = count($allRows);
$rawScores     = array_column($allRows, 'num_correct');
$minScore      = (int)min($rawScores);
$maxScore      = (int)max($rawScores);
$meanScore     = array_sum($rawScores) / $totalStudents;
$mps           = $totalItems > 0 ? round($meanScore / $totalItems * 100, 2) : 0.0;

$preview = [
    'total_students'     => $totalStudents,
    'imported_rows'      => count($imported),
    'flagged_rows'       => count($flagged),
    'skipped_rows'       => $skipped,
    'min_score'          => $minScore,
    'max_score'          => $maxScore,
    'mean_score'         => round($meanScore, 2),
    'mps'                => $mps,
    'item_count_csv'     => $csvItemCount,
    'item_count_asmt'    => $totalItems,
    'item_count_warning' => $itemCountWarning,
    'flagged_details'    => array_map(fn($r) => [
        'line'  => $r['line'],
        'score' => $r['num_correct'],
        'flags' => $r['flags'],
    ], $flagged),
];

if ($action === 'preview') {
    json_response(['preview' => $preview]);
}

// ---------------------------------------------------------------
// CONFIRM — atomically replace this section's data
// ---------------------------------------------------------------
$pdo->beginTransaction();
try {
    // Delete existing data for (assessment, section) — clean re-import
    $pdo->prepare("DELETE FROM score_frequencies   WHERE assessment_id = ? AND section_id = ?")
        ->execute([$assessment_id, $section_id]);
    $pdo->prepare("DELETE FROM item_correct_counts WHERE assessment_id = ? AND section_id = ?")
        ->execute([$assessment_id, $section_id]);

    $sfStmt = $pdo->prepare(
        "INSERT INTO score_frequencies (assessment_id, section_id, score, frequency) VALUES (?,?,?,?)"
    );
    foreach ($freqDist as $score => $freq) {
        if ($freq > 0) {
            $sfStmt->execute([$assessment_id, $section_id, (int)$score, (int)$freq]);
        }
    }

    $iccStmt = $pdo->prepare(
        "INSERT INTO item_correct_counts (assessment_id, section_id, item_no, correct_count) VALUES (?,?,?,?)"
    );
    foreach ($itemCounts as $itemNo => $count) {
        $iccStmt->execute([$assessment_id, $section_id, (int)$itemNo, (int)$count]);
    }

    if ($isShared) {
        $pdo->prepare("UPDATE teacher_assessment_encodings SET updated_at = NOW() WHERE assessment_id = ? AND teacher_id = ?")
            ->execute([$assessment_id, $uid]);
    } else {
        $pdo->prepare("UPDATE assessments SET updated_at = NOW() WHERE id = ?")
            ->execute([$assessment_id]);
    }

    $pdo->commit();
    json_response([
        'success'  => true,
        'students' => $totalStudents,
        'flagged'  => count($flagged),
        'mps'      => $mps,
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => 'Import failed: ' . $e->getMessage()], 500);
}
