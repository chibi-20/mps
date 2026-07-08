<?php
/**
 * Returns sections and assessments for the currently selected filters.
 * Used by admin dashboard to populate dependent dropdowns.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');
$pdo = get_pdo();

$syId    = validate_int($_GET['sy']      ?? null, 1);
$grade   = validate_int($_GET['grade']   ?? null, 1);
$subject = validate_string($_GET['subject'] ?? '', 100) ?: null;

// Sections
$secWhere  = ['1=1'];
$secParams = [];
if ($syId)  { $secWhere[] = 'sec.school_year_id = ?'; $secParams[] = $syId; }
if ($grade) { $secWhere[] = 'sec.grade_level = ?';    $secParams[] = $grade; }
$secSQL = "SELECT sec.id, sec.name FROM sections sec WHERE " . implode(' AND ', $secWhere) . " ORDER BY sec.name";
$secStmt = $pdo->prepare($secSQL);
$secStmt->execute($secParams);
$sections = $secStmt->fetchAll();

// Assessments
$aWhere  = ["a.status IN ('submitted','approved')"];
$aParams = [];
if ($syId)   { $aWhere[] = 't.school_year_id = ?'; $aParams[] = $syId; }
if ($grade)  { $aWhere[] = 's.grade_level = ?';    $aParams[] = $grade; }
if ($subject){ $aWhere[] = 's.name = ?';           $aParams[] = $subject; }
$aSQL = "SELECT a.id, a.title FROM assessments a
         JOIN subjects s ON s.id = a.subject_id
         JOIN terms t ON t.id = a.term_id
         WHERE " . implode(' AND ', $aWhere) . "
         ORDER BY a.date_given, a.title";
$aStmt = $pdo->prepare($aSQL);
$aStmt->execute($aParams);
$assessments = $aStmt->fetchAll();

json_response(['sections' => $sections, 'assessments' => $assessments]);
