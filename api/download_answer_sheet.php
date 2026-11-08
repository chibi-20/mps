<?php
/** Download one scanner-ready answer sheet for every enrolled assessment student. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/answer_sheet_pdf.php';

$session = require_login();
$role = $session['role'] ?? '';
if (!in_array($role, ['admin', 'teacher'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
$id = validate_int($_GET['assessment_id'] ?? null, 1);
if (!$id) {
    http_response_code(400);
    exit('A valid assessment is required.');
}

$pdo = get_pdo();
$assessmentSql = "SELECT a.id, a.teacher_id, a.subject_id, a.title, a.total_items,
                         COALESCE(a.is_shared, 0) AS is_shared,
                         s.name AS subject_name, s.grade_level, t.term_no,
                         t.school_year_id, sy.name AS sy_name
                  FROM assessments a
                  JOIN subjects s ON s.id = a.subject_id
                  JOIN terms t ON t.id = a.term_id
                  JOIN school_years sy ON sy.id = t.school_year_id
                  WHERE a.id = ?";
$params = [$id];
if ($role === 'admin') {
    $assessmentSql .= " AND COALESCE(a.is_shared, 0) = 1";
} else {
    $assessmentSql .= " AND (
        (COALESCE(a.is_shared, 0) = 0 AND a.teacher_id = ?)
        OR (a.is_shared = 1 AND a.status = 'approved'
            AND EXISTS (
                SELECT 1 FROM user_subjects us
                WHERE us.user_id = ? AND LOWER(TRIM(us.subject_name)) = LOWER(TRIM(s.name))
            )
            AND EXISTS (
                SELECT 1 FROM teacher_assignments ta
                WHERE ta.teacher_id = ? AND ta.subject_id = a.subject_id
                  AND ta.school_year_id = t.school_year_id
            )
        )
    )";
    $params = [$id, (int)$session['user_id'], (int)$session['user_id'], (int)$session['user_id']];
}
$assessmentStmt = $pdo->prepare($assessmentSql);
$assessmentStmt->execute($params);
$assessment = $assessmentStmt->fetch(PDO::FETCH_ASSOC);
if (!$assessment) {
    http_response_code(404);
    exit('Assessment not found.');
}
if ((int)$assessment['total_items'] > 50) {
    http_response_code(422);
    exit('The current answer sheet scanner supports assessments with up to 50 questions.');
}

$sectionParams = [$id];
if ($role === 'teacher' && (int)$assessment['is_shared'] === 0) {
    $sectionSql = "SELECT DISTINCT sec.id FROM assessment_sections ase
                   JOIN sections sec ON sec.id = ase.section_id
                   WHERE ase.assessment_id = ?";
} elseif ($role === 'teacher') {
    // Before a shared assessment is started, the teacher has not selected its
    // sections yet, so use their assigned sections. Once started, honor the
    // assessment-specific section selection.
    $sectionSql = "SELECT DISTINCT sec.id FROM sections sec
                   JOIN teacher_assignments ta ON ta.section_id = sec.id
                       AND ta.subject_id = ? AND ta.school_year_id = ?
                       AND ta.teacher_id = ?
                   WHERE sec.grade_level = ? AND sec.school_year_id = ?
                     AND (
                         NOT EXISTS (SELECT 1 FROM teacher_assessment_encodings
                                     WHERE assessment_id = ? AND teacher_id = ?)
                         OR EXISTS (SELECT 1 FROM assessment_sections ase
                                    WHERE ase.assessment_id = ? AND ase.section_id = sec.id)
                     )";
    $sectionParams = [
        (int)$assessment['subject_id'], (int)$assessment['school_year_id'], (int)$session['user_id'],
        (int)$assessment['grade_level'], (int)$assessment['school_year_id'],
        $id, (int)$session['user_id'], $id,
    ];
} else {
    // Admin-created assessments can be shared before individual teachers have
    // attached sections. Use explicit sections when present, otherwise all
    // teacher-assigned sections for this subject and school year.
    $sectionSql = "SELECT DISTINCT sec.id FROM sections sec
                   WHERE sec.grade_level = ? AND sec.school_year_id = ?
                     AND (
                         EXISTS (SELECT 1 FROM assessment_sections ase
                                 WHERE ase.assessment_id = ? AND ase.section_id = sec.id)
                         OR (
                             NOT EXISTS (SELECT 1 FROM assessment_sections ase WHERE ase.assessment_id = ?)
                             AND EXISTS (SELECT 1 FROM teacher_assignments ta
                                         WHERE ta.section_id = sec.id AND ta.subject_id = ?
                                           AND ta.school_year_id = ?)
                         )
                     )";
    $sectionParams = [
        (int)$assessment['grade_level'], (int)$assessment['school_year_id'],
        $id, $id, (int)$assessment['subject_id'], (int)$assessment['school_year_id'],
    ];
}
$sectionStmt = $pdo->prepare($sectionSql);
$sectionStmt->execute($sectionParams);
$sectionIds = array_map('intval', array_column($sectionStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
if (!$sectionIds) {
    http_response_code(422);
    exit('No sections are assigned to this assessment.');
}

$placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
$studentStmt = $pdo->prepare("SELECT st.lrn, st.name, sec.id AS section_id, sec.name AS section_name,
                                     sec.grade_level
                              FROM students st
                              JOIN student_enrollments e ON e.student_id = st.id
                                  AND e.school_year_id = ?
                              JOIN sections sec ON sec.id = e.section_id
                              WHERE sec.id IN ({$placeholders})
                              ORDER BY sec.name, st.name, st.lrn");
$studentStmt->execute([(int)$assessment['school_year_id'], ...$sectionIds]);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
if (!$students) {
    http_response_code(422);
    exit('No enrolled students were found in the sections assigned to this assessment.');
}

$subjectPlaceholders = implode(',', array_fill(0, count($sectionIds), '?'));
$assignedSubjectsSql = "SELECT DISTINCT ta.section_id, s.id AS subject_id, s.name AS subject_name
                        FROM teacher_assignments ta
                        JOIN subjects s ON s.id = ta.subject_id
                        WHERE ta.section_id IN ({$subjectPlaceholders})
                          AND ta.school_year_id = ?";
$assignedSubjectParams = [...$sectionIds, (int)$assessment['school_year_id']];
if ($role === 'teacher') {
    $assignedSubjectsSql .= ' AND ta.teacher_id = ?';
    $assignedSubjectParams[] = (int)$session['user_id'];
}
$assignedSubjectsSql .= ' ORDER BY ta.section_id, s.id';
$assignedSubjectsStmt = $pdo->prepare($assignedSubjectsSql);
$assignedSubjectsStmt->execute($assignedSubjectParams);
$subjectsBySection = [];
foreach ($assignedSubjectsStmt->fetchAll(PDO::FETCH_ASSOC) as $subject) {
    $subjectsBySection[(int)$subject['section_id']][] = [
        'subject_id' => (int)$subject['subject_id'],
        'subject_name' => $subject['subject_name'],
    ];
}
$selectedSubject = [
    'subject_id' => (int)$assessment['subject_id'],
    'subject_name' => $assessment['subject_name'],
];
foreach ($students as &$student) {
    $sectionId = (int)$student['section_id'];
    $sectionSubjects = $subjectsBySection[$sectionId] ?? [];
    if (!array_filter($sectionSubjects, static fn(array $subject): bool => $subject['subject_id'] === $selectedSubject['subject_id'])) {
        $sectionSubjects[] = $selectedSubject;
    }
    $displayOrder = ['math' => 0, 'mathematics' => 0, 'english' => 1, 'science' => 2, 'filipino' => 3, 'araling panlipunan' => 4];
    usort($sectionSubjects, static function (array $left, array $right) use ($displayOrder, $selectedSubject): int {
        if ($left['subject_id'] === $selectedSubject['subject_id']) return -1;
        if ($right['subject_id'] === $selectedSubject['subject_id']) return 1;
        $leftOrder = $displayOrder[strtolower(trim($left['subject_name']))] ?? 100;
        $rightOrder = $displayOrder[strtolower(trim($right['subject_name']))] ?? 100;
        return [$leftOrder, $left['subject_id']] <=> [$rightOrder, $right['subject_id']];
    });
    // Keep the assessment being downloaded, followed by the preferred subject
    // order above, and fit the first four subjects into the four printed boxes.
    $student['subjects'] = array_slice($sectionSubjects, 0, 4);
}
unset($student);

$pdf = generate_student_answer_sheets_pdf($assessment, $students);
$slug = preg_replace('/[^a-z0-9_-]+/i', '_', (string)$assessment['title']) ?: 'assessment';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $slug . '_Answer_Sheets.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
