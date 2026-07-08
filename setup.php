<?php
/**
 * ONE-TIME SETUP SCRIPT
 * Run this ONCE in a browser after importing schema.sql.
 * It seeds all reference data and demo users.
 * DELETE OR RENAME this file after running.
 */
require_once __DIR__ . '/includes/db.php';

$pdo = get_pdo();

// Guard: abort if admin already exists
$check = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
if ((int)$check > 0) {
    die('<b>Setup already complete.</b> Delete setup.php from the server.');
}

$pdo->beginTransaction();

try {
    // --------------------------------------------------------
    // USERS
    // --------------------------------------------------------
    $adminHash   = password_hash('admin123',   PASSWORD_DEFAULT);
    $teacherHash = password_hash('teacher123', PASSWORD_DEFAULT);

    $pdo->prepare(
        "INSERT INTO users (last_name, first_name, middle_name, username, password_hash, role, department, is_active)
         VALUES (?,?,?,?,?,?,?,?)"
    )->execute(['Administrator', 'System', null, 'admin', $adminHash, 'admin', 'Administration', 1]);

    $pdo->prepare(
        "INSERT INTO users (last_name, first_name, middle_name, username, password_hash, role, department, is_active)
         VALUES (?,?,?,?,?,?,?,?)"
    )->execute(['Canturia', 'Jay Mar', 'V', 'jmcanturia', $teacherHash, 'teacher', 'Social Studies', 1]);

    $adminId   = 1;
    $teacherId = 2;

    // --------------------------------------------------------
    // TEACHER PROFILE: grade levels + subjects
    // --------------------------------------------------------
    $pdo->prepare("INSERT INTO user_grade_levels (user_id, grade_level) VALUES (?,?)")
        ->execute([$teacherId, 10]);

    $pdo->prepare("INSERT INTO user_subjects (user_id, subject_name) VALUES (?,?)")
        ->execute([$teacherId, 'Araling Panlipunan']);

    // --------------------------------------------------------
    // SCHOOL YEAR & TERMS
    // --------------------------------------------------------
    $pdo->prepare("INSERT INTO school_years (name, is_active) VALUES (?,?)")
        ->execute(['2025-2026', 1]);
    $syId = (int)$pdo->lastInsertId();

    $terms = [
        [1, 'First Term  (Aug – Nov 2025)'],
        [2, 'Second Term (Dec 2025 – Mar 2026)'],
        [3, 'Third Term  (Apr – Jun 2026)'],
    ];
    $termStmt = $pdo->prepare("INSERT INTO terms (school_year_id, term_no, name) VALUES (?,?,?)");
    foreach ($terms as [$no, $name]) {
        $termStmt->execute([$syId, $no, $name]);
    }
    // term_id 2 = Second Term (where our sample assessment lives)

    // --------------------------------------------------------
    // SUBJECTS  (all 12 × grades 7-10 = 48 rows)
    // --------------------------------------------------------
    $subjectNames = [
        'Filipino', 'English', 'Math', 'Science',
        'Araling Panlipunan', 'ESP', 'TLE', 'MAPEH',
        'Research', 'SPJ', 'SPS', 'SPA',
    ];
    $subjStmt = $pdo->prepare("INSERT INTO subjects (name, grade_level) VALUES (?,?)");
    foreach ([7, 8, 9, 10] as $gl) {
        foreach ($subjectNames as $sn) {
            $subjStmt->execute([$sn, $gl]);
        }
    }
    // AP Grade 10 = subject_id 41
    // Grade 7 ids 1-12, Grade 8 ids 13-24, Grade 9 ids 25-36, Grade 10 ids 37-48
    // AP is 5th subject → grade10 offset 4 → id = 36 + 5 = 41
    $apGrade10SubjId = 41;

    // --------------------------------------------------------
    // SECTIONS  (Grade 9: 30  |  Grade 10: 30)
    // --------------------------------------------------------
    $grade9Sections = [
        'ABUHAB-BAGING','ADLABONG','BANKALANAN','BIRIBA','CAMPANERO',
        'CARANDA','AMAPOLA','DUMALIO','ENDIBA','ESTRELLA',
        'FLOR DE LUNA','FLORANJILLA','GATBO','GULASIMAN','HAGIMIT',
        'HILAGAK','IDSA','IKMO','JACARANDA','JATOBA',
        'KABLING-PARANG','KALANTAS','LANDRINA','LIRIO','MALARUHAT',
        'MATALAGDAW','NAMI','NITO-NITOAN','OLIBA','ORINGEN',
    ];
    $grade10Sections = [
        'Napoleon Abueva','Larry Alcala','Federico Alcuaz','Fernando Amorsolo',
        'Benedicto Cabrera','Eduardo Castrillo','Roberto Chabet','Francisco Coching',
        'Victorio Edades','Cesar Fernandez','Carlos Francisco','Felix Resurreccion Hidalgo',
        'Abdulmari Imao','Jose Joya','Ang Kiukok','Cesar Legaspi',
        'Nestor Leynes','Leandro Locsin','Juan Luna','Arturo Luz',
        'Anita Magsaysay-Ho','Mauro Malang','Vicente Manansala','David Medalla',
        'Juan Nakpil','Jeremias Navarro','Hernando Ocampo','Ramon Orlina',
        'Cenon Rivera','Ildefonso Santos',
    ];

    $secStmt = $pdo->prepare("INSERT INTO sections (school_year_id, grade_level, name) VALUES (?,?,?)");
    foreach ($grade9Sections as $sn) {
        $secStmt->execute([$syId, 9, $sn]);
    }
    foreach ($grade10Sections as $sn) {
        $secStmt->execute([$syId, 10, $sn]);
    }
    // Grade 9:  section ids  1-30
    // Grade 10: section ids 31-60
    // Chabet=37, Coching=38, Edades=39, Legaspi=46, Leynes=47, Locsin=48, Santos=60

    // --------------------------------------------------------
    // TEACHER ASSIGNMENTS  (CANTURIA → AP10 → 7 sections)
    // --------------------------------------------------------
    $taSecIds = [37, 38, 39, 46, 47, 48, 60]; // Chabet, Coching, Edades, Legaspi, Leynes, Locsin, Santos
    $taStmt = $pdo->prepare(
        "INSERT INTO teacher_assignments (teacher_id, subject_id, section_id, school_year_id)
         VALUES (?,?,?,?)"
    );
    foreach ($taSecIds as $sid) {
        $taStmt->execute([$teacherId, $apGrade10SubjId, $sid, $syId]);
    }

    // --------------------------------------------------------
    // SAMPLE ASSESSMENT  (Second Term, Periodic, 40 items)
    // --------------------------------------------------------
    $pdo->prepare(
        "INSERT INTO assessments
            (teacher_id, subject_id, term_id, type, title, total_items, date_given, status, reviewed_by)
         VALUES (?,?,?,?,?,?,?,?,?)"
    )->execute([
        $teacherId, $apGrade10SubjId, 2,
        'periodic', 'First Periodic Examination', 40,
        '2026-01-20', 'approved', $adminId,
    ]);
    $assessmentId = (int)$pdo->lastInsertId();

    // --------------------------------------------------------
    // SCORE FREQUENCIES  (7 sections, total_items=40)
    // --------------------------------------------------------
    $sectionSizes = [
        37 => 38,  // Chabet
        38 => 40,  // Coching
        39 => 42,  // Edades
        46 => 44,  // Legaspi
        47 => 41,  // Leynes
        48 => 39,  // Locsin
        60 => 43,  // Santos
    ];

    // Frequency distributions: score => frequency
    $distributions = [
        37 => [32=>1,31=>2,30=>3,29=>3,28=>4,27=>5,26=>5,25=>4,24=>3,23=>3,22=>2,21=>1,20=>1,19=>1],
        38 => [30=>1,29=>2,28=>3,27=>4,26=>5,25=>6,24=>5,23=>4,22=>3,21=>2,20=>2,19=>1,18=>1,17=>1],
        39 => [35=>1,34=>2,33=>3,32=>4,31=>5,30=>6,29=>5,28=>4,27=>3,26=>2,25=>2,24=>1,23=>1,22=>2,21=>1],
        46 => [34=>1,33=>2,32=>3,31=>4,30=>5,29=>6,28=>5,27=>5,26=>4,25=>3,24=>2,23=>2,22=>1,21=>1],
        47 => [33=>1,32=>2,31=>3,30=>4,29=>5,28=>5,27=>5,26=>4,25=>3,24=>3,23=>2,22=>1,21=>1,20=>1,19=>1],
        48 => [31=>1,30=>2,29=>3,28=>4,27=>5,26=>5,25=>5,24=>4,23=>3,22=>2,21=>2,20=>1,19=>1,18=>1],
        60 => [35=>1,34=>2,33=>3,32=>4,31=>5,30=>6,29=>5,28=>4,27=>3,26=>3,25=>2,24=>2,23=>1,22=>1,21=>1],
    ];

    $sfStmt = $pdo->prepare(
        "INSERT INTO score_frequencies (assessment_id, section_id, score, frequency)
         VALUES (?,?,?,?)"
    );
    foreach ($distributions as $secId => $dist) {
        foreach ($dist as $score => $freq) {
            $sfStmt->execute([$assessmentId, $secId, $score, $freq]);
        }
    }

    // --------------------------------------------------------
    // ITEM CORRECT COUNTS  (40 items × 7 sections)
    // --------------------------------------------------------
    $iccStmt = $pdo->prepare(
        "INSERT INTO item_correct_counts (assessment_id, section_id, item_no, correct_count)
         VALUES (?,?,?,?)"
    );

    foreach ($sectionSizes as $secId => $n) {
        for ($item = 1; $item <= 40; $item++) {
            $count = seed_correct_count($item, $n);
            $iccStmt->execute([$assessmentId, $secId, $item, $count]);
        }
    }

    // --------------------------------------------------------
    // ASSESSMENT SECTIONS  (link the 7 sections to assessment 1)
    // --------------------------------------------------------
    $asStmt = $pdo->prepare(
        "INSERT INTO assessment_sections (assessment_id, section_id) VALUES (?,?)"
    );
    foreach ($taSecIds as $sid) {
        $asStmt->execute([$assessmentId, $sid]);
    }

    $pdo->commit();

    echo '<h2 style="font-family:sans-serif;color:#2d6a4f">Setup complete!</h2>';
    echo '<p style="font-family:sans-serif">Seed data inserted successfully. <strong>Delete or rename setup.php now.</strong></p>';
    echo '<ul style="font-family:sans-serif">';
    echo '<li>Admin login: <code>admin</code> / <code>admin123</code></li>';
    echo '<li>Teacher login: <code>jmcanturia</code> / <code>teacher123</code></li>';
    echo '<li><a href="' . BASE_URL . 'index.php">Go to Login</a></li>';
    echo '</ul>';

} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo '<pre style="color:red">Setup failed: ' . htmlspecialchars($e->getMessage()) . '</pre>';
}

// ============================================================
// Helper: deterministic item correct-count generator
// ============================================================
function seed_correct_count(int $item, int $n): int
{
    // Easy items at ends, hard in middle-upper range
    if ($item <= 5 || $item >= 36) {
        $base = 0.76;
    } elseif ($item <= 25) {
        $base = 0.61;
    } else {
        $base = 0.47;
    }
    // Deterministic variation ±6 pp based on item number
    $variation = (($item * 7 + 3) % 13 - 6) / 100;
    $pct = max(0.18, min(0.93, $base + $variation));
    return (int)round($n * $pct);
}
