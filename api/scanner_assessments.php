<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/scanner_api.php';

scanner_api_cors();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_response(['error' => 'Method not allowed.'], 405);
$pdo = get_pdo();
$identity = scanner_api_teacher($pdo);
$pdo->prepare('UPDATE scanner_api_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$identity['id']]);
$sql = "SELECT a.id AS assessment_id, a.title, a.total_items, a.status, s.id AS subject_id, s.name AS subject_name,
               ass.section_id, sec.name AS section_name,
               (SELECT COUNT(*) FROM assessment_answer_keys k WHERE k.assessment_id = a.id) AS answer_key_items
        FROM assessments a
        JOIN subjects s ON s.id = a.subject_id
        JOIN assessment_sections ass ON ass.assessment_id = a.id
        JOIN sections sec ON sec.id = ass.section_id
        JOIN terms t ON t.id = a.term_id
        JOIN teacher_assignments ta ON ta.teacher_id = ? AND ta.subject_id = a.subject_id
          AND ta.section_id = ass.section_id AND ta.school_year_id = t.school_year_id
        LEFT JOIN teacher_assessment_encodings tae ON tae.assessment_id = a.id AND tae.teacher_id = ?
        WHERE ((COALESCE(a.is_shared, 0) = 0 AND a.teacher_id = ?) OR (COALESCE(a.is_shared, 0) = 1 AND tae.id IS NOT NULL AND tae.status NOT IN ('submitted','approved')))
          AND a.status NOT IN ('submitted','approved')
        ORDER BY s.name, sec.name, a.title";
$stmt = $pdo->prepare($sql);
$stmt->execute([(int)$identity['teacher_id'], (int)$identity['teacher_id'], (int)$identity['teacher_id']]);
$rows = $stmt->fetchAll();
foreach ($rows as &$row) $row['answer_key_ready'] = (int)$row['answer_key_items'] === (int)$row['total_items'];
unset($row);
json_response(['assessments' => $rows]);
