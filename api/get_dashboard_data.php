<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$sess = require_login('admin');
$pdo  = get_pdo();

$syId         = validate_int($_GET['sy']         ?? null, 1);
$termId       = validate_int($_GET['term']       ?? null, 1);
$gradeFilter  = validate_int($_GET['grade']      ?? null, 1);
$subjectName  = validate_string($_GET['subject'] ?? '', 100) ?: null;
$sectionId    = validate_int($_GET['section']    ?? null, 1);
$assessmentId = validate_int($_GET['assessment'] ?? null, 1);

// ---- Base query: get qualifying assessments ----
$where  = ["a.status IN ('submitted','approved')"];
$params = [];

if ($syId) {
    $where[] = 't.school_year_id = ?'; $params[] = $syId;
}
if ($termId) {
    $where[] = 'a.term_id = ?'; $params[] = $termId;
}
if ($gradeFilter) {
    $where[] = 's.grade_level = ?'; $params[] = $gradeFilter;
}
if ($subjectName) {
    $where[] = 's.name = ?'; $params[] = $subjectName;
}
if ($assessmentId) {
    $where[] = 'a.id = ?'; $params[] = $assessmentId;
}

$whereSQL = implode(' AND ', $where);

$asmtStmt = $pdo->prepare(
    "SELECT a.id, a.total_items, a.title, a.date_given, a.is_shared,
            s.name AS subject_name, s.grade_level
     FROM assessments a
     JOIN subjects s ON s.id = a.subject_id
     JOIN terms t ON t.id = a.term_id
     WHERE {$whereSQL}
     ORDER BY a.date_given, a.id"
);
$asmtStmt->execute($params);
$assessments = $asmtStmt->fetchAll();

if (empty($assessments)) {
    json_response([
        'mps_per_grade'                => [],
        'mps_per_subject'              => [],
        'band_distribution'            => [],
        'pl_distribution'              => [],
        'least_mastered_items'         => [],
        'least_mastered_competencies'  => [],
        'item_heatmap'                 => ['sections'=>[],'items'=>[],'data'=>[]],
        'mps_trend'                    => [],
        'kpis'                         => ['overall_mps'=>0,'total_examinees'=>0,'submitted_count'=>0,'below50_items'=>0],
    ]);
}

// Fast assessment lookup by id
$asmtById = [];
foreach ($assessments as $a) $asmtById[(int)$a['id']] = $a;

$asmtIds    = array_column($assessments, 'id');
$totalItems = (int)($assessments[0]['total_items'] ?? 40);

// Sections whose data is actually submitted/approved (see get_qualifying_sections()
// docblock) — everything below is filtered through this so drafts never leak in.
$qualifying = get_qualifying_sections($pdo, $asmtIds);

// ---- Load raw score frequencies ----
$in = implode(',', array_fill(0, count($asmtIds), '?'));
$sfStmt = $pdo->prepare(
    "SELECT sf.assessment_id, sf.section_id, sf.score, sf.frequency,
            sec.name AS section_name
     FROM score_frequencies sf
     JOIN sections sec ON sec.id = sf.section_id
     WHERE sf.assessment_id IN ({$in}) AND sf.frequency > 0"
    . ($sectionId ? " AND sf.section_id = ?" : "")
);
$sfParams = $asmtIds;
if ($sectionId) $sfParams[] = $sectionId;
$sfStmt->execute($sfParams);
$sfRows = array_values(array_filter(
    $sfStmt->fetchAll(),
    fn($r) => isset($qualifying[(int)$r['assessment_id']][(int)$r['section_id']])
));

// Cases per (assessment_id, section_id) — accurate denominator for per-row computations
$casesByAsmtSec = [];
foreach ($sfRows as $r) {
    $aId = (int)$r['assessment_id'];
    $sid = (int)$r['section_id'];
    $casesByAsmtSec[$aId][$sid] = ($casesByAsmtSec[$aId][$sid] ?? 0) + (int)$r['frequency'];
}

// Index by section for heatmap section names
$secData = [];
foreach ($sfRows as $r) {
    $sid = (int)$r['section_id'];
    if (!isset($secData[$sid])) {
        $secData[$sid] = ['name' => $r['section_name'], 'assessments' => []];
    }
    $aId = (int)$r['assessment_id'];
    if (!isset($secData[$sid]['assessments'][$aId])) {
        $ti = (int)($asmtById[$aId]['total_items'] ?? 40);
        $secData[$sid]['assessments'][$aId] = ['total_items' => $ti, 'rows' => []];
    }
    $secData[$sid]['assessments'][$aId]['rows'][] = [
        'score' => (int)$r['score'], 'frequency' => (int)$r['frequency'],
    ];
}

// Cases per section (all assessments combined) — used for heatmap
$casesBySec = [];
foreach ($secData as $sid => $sec) {
    $c = 0;
    foreach ($sec['assessments'] as $ad) {
        foreach ($ad['rows'] as $row) $c += $row['frequency'];
    }
    $casesBySec[$sid] = $c;
}

// ---- Grand totals for KPIs ----
$grandCases = 0; $grandFx = 0;
foreach ($sfRows as $r) {
    $grandCases += (int)$r['frequency'];
    $grandFx    += (int)$r['frequency'] * (int)$r['score'];
}
$totalExaminees = $grandCases;

// ---- MPS per Subject ----
$subjectMap = [];
foreach ($sfRows as $r) {
    $aId  = (int)$r['assessment_id'];
    $asmt = $asmtById[$aId] ?? null;
    if (!$asmt) continue;
    $key = $asmt['subject_name'];
    if (!isset($subjectMap[$key])) {
        $subjectMap[$key] = ['subject_name'=>$asmt['subject_name'],'cases'=>0,'fx'=>0,'ti'=>(int)$asmt['total_items']];
    }
    $subjectMap[$key]['cases'] += (int)$r['frequency'];
    $subjectMap[$key]['fx']    += (int)$r['frequency'] * (int)$r['score'];
}
$mpsPerSubject = [];
foreach ($subjectMap as $entry) {
    $mean = $entry['cases'] > 0 ? $entry['fx'] / $entry['cases'] : 0;
    $mps  = ($entry['cases'] > 0 && $entry['ti'] > 0) ? $mean / $entry['ti'] * 100 : 0;
    $mpsPerSubject[] = [
        'subject_name' => $entry['subject_name'],
        'mps'          => round($mps, 2),
    ];
}

// ---- MPS per Grade Level ----
$gradeMap = [];
foreach ($sfRows as $r) {
    $aId  = (int)$r['assessment_id'];
    $asmt = $asmtById[$aId] ?? null;
    if (!$asmt) continue;
    $grade = (int)$asmt['grade_level'];
    if (!isset($gradeMap[$grade])) {
        $gradeMap[$grade] = ['cases' => 0, 'fx' => 0, 'ti' => (int)$asmt['total_items']];
    }
    $gradeMap[$grade]['cases'] += (int)$r['frequency'];
    $gradeMap[$grade]['fx']    += (int)$r['frequency'] * (int)$r['score'];
}
$mpsPerGrade = [];
foreach ($gradeMap as $grade => $d) {
    $mean = $d['cases'] > 0 ? $d['fx'] / $d['cases'] : 0;
    $mps  = ($d['cases'] > 0 && $d['ti'] > 0) ? $mean / $d['ti'] * 100 : 0;
    $mpsPerGrade[] = ['grade_level' => $grade, 'mps' => round($mps, 2), 'cases' => $d['cases']];
}
usort($mpsPerGrade, fn($a, $b) => $a['grade_level'] <=> $b['grade_level']);

// ---- Score Band Distribution per Grade Level ----
$SCORE_BANDS = ['98-100','95-97','90-94','85-89','80-84','75-79','Below 75'];
$bandByGrade = [];
foreach ($sfRows as $r) {
    $aId  = (int)$r['assessment_id'];
    $asmt = $asmtById[$aId] ?? null;
    if (!$asmt) continue;
    $grade = (int)$asmt['grade_level'];
    $ti    = (int)$asmt['total_items'];
    $pct   = $ti > 0 ? (int)$r['score'] / $ti * 100 : 0;
    $band  = get_score_band($pct);
    $freq  = (int)$r['frequency'];
    if (!isset($bandByGrade[$grade])) {
        $bandByGrade[$grade] = array_fill_keys($SCORE_BANDS, 0);
    }
    $bandByGrade[$grade][$band] += $freq;
}
ksort($bandByGrade);
$bandDistribution = [];
foreach ($bandByGrade as $grade => $bands) {
    $bandDistribution[] = ['grade_level' => $grade, 'bands' => $bands];
}

// ---- Proficiency Level Distribution per Grade Level (DepEd descriptor scale) ----
$PL_KEYS = array_keys(PROFICIENCY_LEVELS);
$plByGrade = [];
foreach ($sfRows as $r) {
    $aId  = (int)$r['assessment_id'];
    $asmt = $asmtById[$aId] ?? null;
    if (!$asmt) continue;
    $grade = (int)$asmt['grade_level'];
    $ti    = (int)$asmt['total_items'];
    $pct   = $ti > 0 ? (int)$r['score'] / $ti * 100 : 0;
    $level = get_proficiency_level($pct);
    $freq  = (int)$r['frequency'];
    if (!isset($plByGrade[$grade])) {
        $plByGrade[$grade] = array_fill_keys($PL_KEYS, 0);
    }
    $plByGrade[$grade][$level] += $freq;
}
ksort($plByGrade);
$plDistribution = [];
foreach ($plByGrade as $grade => $levels) {
    $plDistribution[] = ['grade_level' => $grade, 'levels' => $levels];
}

// ---- Enrollment + "Did Not Take the Test" per grade -----------------------
// Enrollment is manually entered per (school year, grade, term) -- only
// computable when the admin has narrowed to one specific SY + term, since
// a single headcount can't be meaningfully compared across multiple terms.
if ($syId && $termId && !empty($plDistribution)) {
    $grades = array_column($plDistribution, 'grade_level');
    $eph    = implode(',', array_fill(0, count($grades), '?'));
    $eStmt  = $pdo->prepare(
        "SELECT grade_level, annual_male, annual_female, monthly_male, monthly_female
         FROM grade_enrollment
         WHERE school_year_id = ? AND term_id = ? AND grade_level IN ({$eph})"
    );
    $eStmt->execute([$syId, $termId, ...$grades]);
    $enrollmentByGrade = [];
    foreach ($eStmt->fetchAll() as $r) {
        $enrollmentByGrade[(int)$r['grade_level']] = [
            'annual_male'    => (int)$r['annual_male'],
            'annual_female'  => (int)$r['annual_female'],
            'annual_total'   => (int)$r['annual_male']  + (int)$r['annual_female'],
            'monthly_male'   => (int)$r['monthly_male'],
            'monthly_female' => (int)$r['monthly_female'],
            'monthly_total'  => (int)$r['monthly_male'] + (int)$r['monthly_female'],
        ];
    }
    foreach ($plDistribution as &$g) {
        $examinees          = array_sum($g['levels']);
        $enr                = $enrollmentByGrade[$g['grade_level']] ?? null;
        $g['examinees']     = $examinees;
        $g['enrollment']    = $enr;
        $g['did_not_take']  = $enr ? max(0, $enr['monthly_total'] - $examinees) : null;
    }
    unset($g);
} else {
    foreach ($plDistribution as &$g) {
        $g['examinees']    = array_sum($g['levels']);
        $g['enrollment']   = null;
        $g['did_not_take'] = null;
    }
    unset($g);
}

// ---- Item Analysis ----
$iccStmt = $pdo->prepare(
    "SELECT icc.assessment_id, icc.section_id, icc.item_no, icc.correct_count,
            sec.name AS section_name
     FROM item_correct_counts icc
     JOIN sections sec ON sec.id = icc.section_id
     WHERE icc.assessment_id IN ({$in})"
    . ($sectionId ? " AND icc.section_id = ?" : "")
);
$iccStmt->execute($sfParams);
$iccRows = array_values(array_filter(
    $iccStmt->fetchAll(),
    fn($r) => isset($qualifying[(int)$r['assessment_id']][(int)$r['section_id']])
));

// Item totals — use casesByAsmtSec for accurate per-row denominator
$itemTotals = [];
$itemBySec  = [];
foreach ($iccRows as $r) {
    $aId = (int)$r['assessment_id'];
    $sid = (int)$r['section_id'];
    $ino = (int)$r['item_no'];
    $cnt = (int)$r['correct_count'];
    $itemBySec[$sid][$ino] = ($itemBySec[$sid][$ino] ?? 0) + $cnt;
    $itemTotals[$ino]['correct'] = ($itemTotals[$ino]['correct'] ?? 0) + $cnt;
    $itemTotals[$ino]['cases']   = ($itemTotals[$ino]['cases']   ?? 0) + ($casesByAsmtSec[$aId][$sid] ?? 0);
}

$maxItem = !empty($iccRows) ? max(array_column($iccRows, 'item_no')) : 0;
$leastMastered = [];
for ($i = 1; $i <= $maxItem; $i++) {
    $correct = $itemTotals[$i]['correct'] ?? 0;
    $tcases  = $itemTotals[$i]['cases']   ?? 0;
    $pct     = $tcases > 0 ? round($correct / $tcases * 100, 2) : 0;
    $leastMastered[] = ['item_no' => $i, 'pct' => $pct];
}
usort($leastMastered, fn($a,$b) => $a['pct'] <=> $b['pct']);
$leastMastered = array_slice($leastMastered, 0, 20);

// ---- Heatmap ----
$heatSections = array_keys($itemBySec);
$heatSecNames = array_map(fn($sid) => $secData[$sid]['name'] ?? 'Sec '.$sid, $heatSections);
$heatItems    = range(1, $maxItem ?: 1);
$heatData     = [];
foreach ($heatItems as $i => $ino) {
    $row = [];
    foreach ($heatSections as $sid) {
        $correct = $itemBySec[$sid][$ino] ?? 0;
        $cases   = $casesBySec[$sid] ?? 0;
        $row[] = $cases > 0 ? round($correct / $cases * 100, 2) : 0;
    }
    $heatData[] = $row;
}

// ---- MPS Trend ----
$mpsTrend = [];
foreach ($assessments as $asmt) {
    $aId = (int)$asmt['id'];
    $ti  = (int)$asmt['total_items'];
    $cases = 0; $fx = 0;
    foreach ($sfRows as $r) {
        if ((int)$r['assessment_id'] !== $aId) continue;
        $cases += (int)$r['frequency'];
        $fx    += (int)$r['frequency'] * (int)$r['score'];
    }
    $mean = $cases > 0 ? $fx / $cases : 0;
    $mps  = ($cases > 0 && $ti > 0) ? $mean / $ti * 100 : 0;
    $mpsTrend[] = [
        'title'      => $asmt['title'],
        'date_given' => $asmt['date_given'],
        'mps'        => round($mps, 2),
    ];
}

// ---- KPIs ----
$overallMean = $grandCases > 0 ? $grandFx / $grandCases : 0;
$overallMps  = ($grandCases > 0 && $totalItems > 0) ? $overallMean / $totalItems * 100 : 0;

// "Submitted Assessments" = actual number of submissions, not assessment
// templates. A legacy assessment is 1 teacher = 1 submission, so counting
// assessment rows works there -- but a shared assessment is one row that
// many teachers each submit their own sections under (one row per teacher
// in teacher_assessment_encodings), so e.g. 15 teachers submitting the same
// Grade 10 MAPEH test previously still showed "1".
$submittedCount = 0;
$sharedAsmtIds  = [];
foreach ($assessments as $a) {
    if ((int)$a['is_shared'] === 0) {
        $submittedCount++;
    } else {
        $sharedAsmtIds[] = (int)$a['id'];
    }
}
if (!empty($sharedAsmtIds)) {
    $in2 = implode(',', array_fill(0, count($sharedAsmtIds), '?'));
    $subCntStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM teacher_assessment_encodings
         WHERE assessment_id IN ({$in2}) AND status IN ('submitted','approved')"
    );
    $subCntStmt->execute($sharedAsmtIds);
    $submittedCount += (int)$subCntStmt->fetchColumn();
}

$below50 = 0;
for ($i = 1; $i <= $maxItem; $i++) {
    $correct = $itemTotals[$i]['correct'] ?? 0;
    $tcases  = $itemTotals[$i]['cases']   ?? 0;
    $pct     = $tcases > 0 ? $correct / $tcases * 100 : 0;
    if ($tcases > 0 && $pct < 50) $below50++;
}

// ---- Competency Analysis ----
$aicStmt = $pdo->prepare(
    "SELECT aic.assessment_id, aic.item_no, aic.competency_id, c.code, c.description
     FROM assessment_item_competencies aic
     JOIN competencies c ON c.id = aic.competency_id
     WHERE aic.assessment_id IN ({$in})"
);
$aicStmt->execute($asmtIds);
$aicRows = $aicStmt->fetchAll();

$itemCompLookup = [];
$compInfo       = [];
foreach ($aicRows as $r) {
    $itemCompLookup[$r['assessment_id']][$r['item_no']] = (int)$r['competency_id'];
    $compInfo[$r['competency_id']] = ['code' => $r['code'], 'description' => $r['description']];
}

// Aggregate per competency — use casesByAsmtSec for accurate denominators
$compStats = [];
foreach ($iccRows as $r) {
    $aId  = (int)$r['assessment_id'];
    $ino  = (int)$r['item_no'];
    $sid  = (int)$r['section_id'];
    $comp = $itemCompLookup[$aId][$ino] ?? null;
    if ($comp === null) continue;

    if (!isset($compStats[$comp])) {
        $compStats[$comp] = ['total_correct' => 0, 'total_possible' => 0, 'section_ids' => [], 'items' => []];
    }
    $compStats[$comp]['total_correct']    += (int)$r['correct_count'];
    $compStats[$comp]['total_possible']   += $casesByAsmtSec[$aId][$sid] ?? 0;
    $compStats[$comp]['section_ids'][$sid] = true;
    $compStats[$comp]['items'][$ino]       = true;
}

$leastMasteredCompetencies = [];
foreach ($compStats as $compId => $cs) {
    $pctComp = $cs['total_possible'] > 0
        ? round($cs['total_correct'] / $cs['total_possible'] * 100, 2)
        : 0;
    $info = $compInfo[$compId] ?? ['code' => '', 'description' => 'Unknown'];
    $leastMasteredCompetencies[] = [
        'competency_id'  => (int)$compId,
        'code'           => $info['code'] ?? '',
        'description'    => $info['description'],
        'pct'            => $pctComp,
        'total_correct'  => $cs['total_correct'],
        'total_possible' => $cs['total_possible'],
        'section_count'  => count($cs['section_ids']),
        'item_count'     => count($cs['items']),
    ];
}
usort($leastMasteredCompetencies, fn($a, $b) => $a['pct'] <=> $b['pct']);

json_response([
    'mps_per_grade'                => $mpsPerGrade,
    'mps_per_subject'              => $mpsPerSubject,
    'band_distribution'            => $bandDistribution,
    'pl_distribution'              => $plDistribution,
    'least_mastered_items'         => $leastMastered,
    'least_mastered_competencies'  => $leastMasteredCompetencies,
    'item_heatmap'                 => [
        'sections' => $heatSecNames,
        'items'    => $heatItems,
        'data'     => $heatData,
    ],
    'mps_trend'                    => $mpsTrend,
    'kpis'                         => [
        'overall_mps'     => round($overallMps, 2),
        'total_examinees' => $totalExaminees,
        'submitted_count' => $submittedCount,
        'below50_items'   => $below50,
    ],
]);
