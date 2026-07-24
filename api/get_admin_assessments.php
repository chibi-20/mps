<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login('admin');
$pdo = get_pdo();

$syId      = validate_int($_GET['sy_id']      ?? null, 1);
$termId    = validate_int($_GET['term_id']     ?? null, 1);
$subjectId = validate_int($_GET['subject_id']  ?? null, 1);
$grade     = validate_int($_GET['grade']       ?? null, 1);
$type      = in_array($_GET['type'] ?? '', ['summative','term_exam'], true) ? $_GET['type'] : '';
$search    = trim($_GET['search'] ?? '');

$where  = ['COALESCE(a.is_shared, 0) = 1'];
$params = [];

if ($syId)      { $where[] = 'sy.id = ?';        $params[] = $syId; }
if ($termId)    { $where[] = 't.id = ?';          $params[] = $termId; }
if ($subjectId) { $where[] = 'a.subject_id = ?';  $params[] = $subjectId; }
if ($grade)     { $where[] = 's.grade_level = ?'; $params[] = $grade; }
if ($type)      { $where[] = 'a.type = ?';        $params[] = $type; }
if ($search !== '') { $where[] = 'a.title LIKE ?'; $params[] = '%' . $search . '%'; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT a.id, a.title, a.type, a.total_items, a.date_given, a.status,
           a.subject_id, s.name AS subject_name, s.grade_level,
           a.term_id, t.term_no, t.name AS term_name,
           sy.id AS sy_id, sy.name AS sy_name,
           COALESCE(u.last_name,'')  AS creator_last,
           COALESCE(u.first_name,'') AS creator_first,
           COUNT(DISTINCT sf.section_id)  AS sections_encoded,
           COUNT(DISTINCT tae.teacher_id) AS teachers_encoded
    FROM assessments a
    JOIN subjects s    ON s.id  = a.subject_id
    JOIN terms t       ON t.id  = a.term_id
    JOIN school_years sy ON sy.id = t.school_year_id
    LEFT JOIN users u  ON u.id  = a.created_by_admin
    LEFT JOIN score_frequencies sf
           ON sf.assessment_id = a.id
    LEFT JOIN teacher_assessment_encodings tae
           ON tae.assessment_id = a.id
    {$whereSQL}
    GROUP BY a.id, s.id, t.id, sy.id, u.id
    ORDER BY a.date_given DESC, a.created_at DESC
");
$stmt->execute($params);

json_response(['assessments' => $stmt->fetchAll()]);
