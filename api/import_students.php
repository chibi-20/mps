<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$session = require_login();
$isAdmin = ($session['role'] ?? '') === 'admin';
if (!$isAdmin && ($session['role'] ?? '') !== 'teacher') json_response(['error'=>'Access denied.'],403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST required.'],405);
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) json_response(['error'=>'Invalid security token. Refresh and retry.'],403);
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) json_response(['error'=>'Choose a readable CSV file.'],400);
$yearId = validate_int($_POST['school_year_id'] ?? null, 1);
$gradeId = validate_int($_POST['grade_level_id'] ?? null, 1);
$sectionId = validate_int($_POST['section_id'] ?? null, 1);
$pdo=get_pdo();
if (!$isAdmin && (!$sectionId || !$yearId)) json_response(['error'=>'Choose a section before importing students.'],400);
if (($gradeId===null)!==($sectionId===null)) json_response(['error'=>'Select both grade and section, or leave both unassigned.'],400);
if ($gradeId!==null) {
    $check=$pdo->prepare('SELECT grade_level_id FROM sections WHERE id=? AND school_year_id=?'); $check->execute([$sectionId,$yearId]);
    if (!$isAdmin) {
        $sectionGradeId=$check->fetchColumn();
        if (!$sectionGradeId) json_response(['error'=>'Selected section does not match the school year.'],400);
        $gradeId=(int)$sectionGradeId;
        $check=$pdo->prepare('SELECT 1 FROM teacher_assignments WHERE teacher_id=? AND section_id=? AND school_year_id=?'); $check->execute([(int)$session['user_id'],$sectionId,$yearId]);
        if (!$check->fetchColumn()) json_response(['error'=>'You are not assigned to the selected section.'],403);
    } else $check->execute([$sectionId,$yearId]);
    if (!$check->fetchColumn()) json_response(['error'=>'Selected section does not match grade and school year.'],400);
}
$handle=fopen($_FILES['file']['tmp_name'],'rb');
if (!$handle) json_response(['error'=>'Could not read the uploaded file.'],400);
$header=fgetcsv($handle);
if (!$header) json_response(['error'=>'CSV file is empty.'],400);
$header=array_map(static fn($value)=>strtolower(trim(preg_replace('/^\xEF\xBB\xBF/','',(string)$value))),$header);
$index=[]; foreach ($header as $i=>$column) $index[$column]=$i;
if (!isset($index['lrn'],$index['name'],$index['sex'])) { fclose($handle); json_response(['error'=>'CSV must have LRN, NAME, and SEX columns.'],400); }
$upsert=$pdo->prepare('INSERT INTO students (lrn,name,sex) VALUES (?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),sex=VALUES(sex)');
if (is_sqlite()) $upsert=$pdo->prepare('INSERT INTO students (lrn,name,sex) VALUES (?,?,?) ON CONFLICT(lrn) DO UPDATE SET name=excluded.name,sex=excluded.sex');
$find=$pdo->prepare('SELECT id FROM students WHERE lrn=?');
$enroll=$pdo->prepare('INSERT INTO student_enrollments (student_id,grade_level_id,section_id,school_year_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE grade_level_id=VALUES(grade_level_id),section_id=VALUES(section_id)');
if (is_sqlite()) $enroll=$pdo->prepare('INSERT INTO student_enrollments (student_id,grade_level_id,section_id,school_year_id) VALUES (?,?,?,?) ON CONFLICT(student_id,school_year_id) DO UPDATE SET grade_level_id=excluded.grade_level_id,section_id=excluded.section_id');
$rowNum=1; $count=0; $errors=[];
try {
    $pdo->beginTransaction();
    while (($row=fgetcsv($handle))!==false) {
        $rowNum++; if (count($row)===1 && trim((string)$row[0])==='') continue;
        $lrn=trim((string)($row[$index['lrn']]??'')); $name=trim((string)($row[$index['name']]??'')); $sex=ucfirst(strtolower(trim((string)($row[$index['sex']]??''))));
        if (!preg_match('/^\d{1,20}$/',$lrn) || $name==='' || mb_strlen($name)>200 || !in_array($sex,['Male','Female'],true)) { $errors[]="Row {$rowNum}: LRN, name, or sex is invalid."; continue; }
        $upsert->execute([$lrn,$name,$sex]); $find->execute([$lrn]); $studentId=(int)$find->fetchColumn();
        if ($gradeId!==null) $enroll->execute([$studentId,$gradeId,$sectionId,$yearId]);
        $count++;
    }
    fclose($handle); $pdo->commit(); json_response(['ok'=>true,'imported'=>$count,'errors'=>array_slice($errors,0,50),'error_count'=>count($errors)]);
} catch (Throwable $error) { if (is_resource($handle)) fclose($handle); if($pdo->inTransaction())$pdo->rollBack(); json_response(['error'=>'Import failed. No rows were saved.'],400); }
