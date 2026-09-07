<?php
/**
 * Returns the manually-entered enrollment headcounts for one
 * (school year, grade level, term), or all-zero defaults if nothing has
 * been entered yet. Admin-only -- these figures feed "Number of Learners
 * Who Did Not Take the Test" on the Analytics dashboard.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');

$syId   = validate_int($_GET['sy']    ?? null, 1);
$grade  = validate_int($_GET['grade'] ?? null, 1);
$termId = validate_int($_GET['term']  ?? null, 1);

if (!$syId || !$grade || !$termId) {
    json_response(['error' => 'sy, grade, and term are required.'], 400);
}

$pdo  = get_pdo();
$stmt = $pdo->prepare(
    "SELECT annual_male, annual_female, monthly_male, monthly_female, updated_at
     FROM grade_enrollment
     WHERE school_year_id = ? AND grade_level = ? AND term_id = ?"
);
$stmt->execute([$syId, $grade, $termId]);
$row = $stmt->fetch();

json_response([
    'annual_male'    => $row ? (int)$row['annual_male']    : 0,
    'annual_female'  => $row ? (int)$row['annual_female']  : 0,
    'monthly_male'   => $row ? (int)$row['monthly_male']   : 0,
    'monthly_female' => $row ? (int)$row['monthly_female'] : 0,
    'updated_at'      => $row['updated_at'] ?? null,
]);
