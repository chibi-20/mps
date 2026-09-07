<?php
/**
 * Upserts the manually-entered enrollment headcounts for one
 * (school year, grade level, term). Admin-only.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
$sess = require_login('admin');
verify_csrf();

$uid = (int)$sess['user_id'];
$raw = json_decode(file_get_contents('php://input'), true) ?? [];

$syId   = validate_int($raw['school_year_id'] ?? null, 1);
$grade  = validate_int($raw['grade_level']    ?? null, 1);
$termId = validate_int($raw['term_id']        ?? null, 1);

if (!$syId || !$grade || !$termId) {
    json_response(['error' => 'school_year_id, grade_level, and term_id are required.'], 400);
}

$annualMale    = max(0, (int)($raw['annual_male']    ?? 0));
$annualFemale  = max(0, (int)($raw['annual_female']  ?? 0));
$monthlyMale   = max(0, (int)($raw['monthly_male']   ?? 0));
$monthlyFemale = max(0, (int)($raw['monthly_female'] ?? 0));

$pdo = get_pdo();

// term must actually belong to the given school year
$chk = $pdo->prepare("SELECT 1 FROM terms WHERE id = ? AND school_year_id = ?");
$chk->execute([$termId, $syId]);
if (!$chk->fetch()) json_response(['error' => 'That term does not belong to the given school year.'], 422);

$pdo->prepare(
    "INSERT INTO grade_enrollment
        (school_year_id, grade_level, term_id, annual_male, annual_female, monthly_male, monthly_female, updated_by)
     VALUES (?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
        annual_male = VALUES(annual_male), annual_female = VALUES(annual_female),
        monthly_male = VALUES(monthly_male), monthly_female = VALUES(monthly_female),
        updated_by = VALUES(updated_by), updated_at = NOW()"
)->execute([$syId, $grade, $termId, $annualMale, $annualFemale, $monthlyMale, $monthlyFemale, $uid]);

json_response(['success' => true]);
