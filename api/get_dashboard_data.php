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
    "SELECT a.id, a.total_items, a.title, a.date_given,
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
        'mps_per_section'              => [],
        'mps_per_grade'                => [],
        'mps_per_subject'              => [],
        'mastery_distribution'         => [],
        'least_mastered_items'         => [],
        'least_mastered_competencies'  => [],
        'item_heatmap'                 => ['sections'=>[],'items'=>[],'data'=>[]],
        'mps_trend'                    => [],
        'npwrm_per_section'            => [],
        'kpis'                         => ['overall_mps'=>0,'total_examinees'=>0,'submitted_count'=>0,'below50_items'=>0],
    ]);
}

$asmtIds    = array_column($assessments, 'id');
$totalItems = (int)($assessments[0]['total_items'] ?? 40);

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
$sfRows = $sfStmt->fetchAll();

// Index by section: section_id → {assessment_id, section_name, rows[]}
$secData = [];
foreach ($sfRows as $r) {
    $sid = (int)$r['section_id'];
    if (!isset($secData[$sid])) {
        $secData[$sid] = ['name' => $r['section_name'], 'assessments' => []];
    }
    $aId = (int)$r['assessment_id'];
    if (!isset($secData[$sid]['assessments'][$aId])) {
        // find total_items for this assessment
        $ti = (int)(array_values(array_filter($assessments, fn($a) => (int)$a['id'] === $aId))[0]['total_items'] ?? 40);
        $secData[$sid]['assessments'][$aId] = ['total_items' => $ti, 'rows' => []];
    }
    $secData[$sid]['assessments'][$aId]['rows'][] = [
        'score' => (int)$r['score'], 'frequency' => (int)$r['frequency'],
    ];
}

// ---- Compute MPS per Section (aggregate across all assessments in filter) ----
$mpsPerSection      = [];
$masteryDistribution= [];
$npwrmPerSection    = [];
$totalExaminees     = 0;
$grandCases = 0; $grandFx = 0;

foreach ($secData as $sid => $sec) {
    $cases = 0; $sumFx = 0; $npwrm = 0;
    $bands = array_fill_keys(array_keys(MASTERY_BANDS), 0);

    foreach ($sec['assessments'] as $aId => $ad) {
        $ti = $ad['total_items'];
        foreach ($ad['rows'] as $row) {
            $f = $row['frequency']; $score = $row['score'];
            $cases  += $f; $sumFx += $f * $score;
            $pct  = $ti > 0 ? $score / $ti * 100 : 0;
            $band = get_mastery_band($pct);
            $bands[$band] += $f;
            if ($pct >= MASTERY_THRESHOLD) $npwrm += $f;
        }
    }

    $ti   = (int)(reset($sec['assessments'])['total_items'] ?? 40);
    $mean = $cases > 0 ? $sumFx / $cases : 0;
    $mps  = ($cases > 0 && $ti > 0) ? $mean / $ti * 100 : 0;

    $totalExaminees += $cases;
    $grandCases     += $cases;
    $grandFx        += $sumFx;

    $bandPct = [];
    foreach ($bands as $k => $cnt) {
        $bandPct[$k] = $cases > 0 ? round($cnt / $cases * 100, 2) : 0;
    }

    $mpsPerSection[] = [
        'section_id'   => $sid,
        'section_name' => $sec['name'],
        'mps'          => round($mps, 2),
        'cases'        => $cases,
    ];
    $masteryDistribution[] = [
        'section_name' => $sec['name'],
        'bands'        => $bandPct,
    ];
    $npwrmPerSection[] = [
        'section_name' => $sec['name'],
        'npwrm'        => $npwrm,
        'cases'        => $cases,
    ];
}

// Sort by section name
usort($mpsPerSection, fn($a,$b) => strcmp($a['section_name'], $b['section_name']));

// ---- MPS per Subject / Grade ----
$subjectMap = [];
foreach ($sfRows as $r) {
    $aId = (int)$r['assessment_id'];
    $asmt = array_values(array_filter($assessments, fn($a) => (int)$a['id'] === $aId))[0] ?? null;
    if (!$asmt) continue;
    $key = $asmt['subject_name'] . '|' . $asmt['grade_level'];
    if (!isset($subjectMap[$key])) {
        $subjectMap[$key] = ['subject_name'=>$asmt['subject_name'],'grade_level'=>$asmt['grade_level'],'cases'=>0,'fx'=>0,'ti'=>(int)$asmt['total_items']];
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
        'grade_level'  => $entry['grade_level'],
        'mps'          => round($mps, 2),
    ];
}

// ---- MPS per Grade Level ----
$gradeMap = [];
foreach ($sfRows as $r) {
    $aId  = (int)$r['assessment_id'];
    $asmt = array_values(array_filter($assessments, fn($a) => (int)$a['id'] === $aId))[0] ?? null;
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
$iccRows = $iccStmt->fetchAll();

// Cases per section (reuse from secData)
$casesBySec = [];
foreach ($secData as $sid => $sec) {
    $c = 0;
    foreach ($sec['assessments'] as $ad) {
        foreach ($ad['rows'] as $row) $c += $row['frequency'];
    }
    $casesBySec[$sid] = $c;
}

// Item totals
$itemTotals = [];   // item_no → [total_correct, total_cases]
$itemBySec  = [];   // section_id → item_no → correct_count
foreach ($iccRows as $r) {
    $sid = (int)$r['section_id'];
    $ino = (int)$r['item_no'];
    $cnt = (int)$r['correct_count'];
    $itemBySec[$sid][$ino] = ($itemBySec[$sid][$ino] ?? 0) + $cnt;
    $itemTotals[$ino]['correct'] = ($itemTotals[$ino]['correct'] ?? 0) + $cnt;
    $itemTotals[$ino]['cases']   = ($itemTotals[$ino]['cases']   ?? 0) + ($casesBySec[$sid] ?? 0);
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

$submittedCount = count($assessments);

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

// Build (assessment_id, item_no) → competency_id lookup
$itemCompLookup = [];  // [aId][item_no] = competency_id
$compInfo       = [];  // competency_id → {code, description}
foreach ($aicRows as $r) {
    $itemCompLookup[$r['assessment_id']][$r['item_no']] = (int)$r['competency_id'];
    $compInfo[$r['competency_id']] = ['code' => $r['code'], 'description' => $r['description']];
}

// Aggregate correct counts and cases per competency
$compStats = [];  // competency_id → {total_correct, total_possible, section_ids}
foreach ($iccRows as $r) {
    $aId  = (int)$r['assessment_id'];
    $ino  = (int)$r['item_no'];
    $sid  = (int)$r['section_id'];
    $comp = $itemCompLookup[$aId][$ino] ?? null;
    if ($comp === null) continue;

    if (!isset($compStats[$comp])) {
        $compStats[$comp] = ['total_correct' => 0, 'total_possible' => 0, 'section_ids' => []];
    }
    $compStats[$comp]['total_correct']    += (int)$r['correct_count'];
    $compStats[$comp]['total_possible']   += $casesBySec[$sid] ?? 0;
    $compStats[$comp]['section_ids'][$sid] = true;
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
    ];
}
usort($leastMasteredCompetencies, fn($a, $b) => $a['pct'] <=> $b['pct']);

json_response([
    'mps_per_section'              => $mpsPerSection,
    'mps_per_grade'                => $mpsPerGrade,
    'mps_per_subject'              => $mpsPerSubject,
    'mastery_distribution'         => $masteryDistribution,
    'least_mastered_items'         => $leastMastered,
    'least_mastered_competencies'  => $leastMasteredCompetencies,
    'item_heatmap'                 => [
        'sections' => $heatSecNames,
        'items'    => $heatItems,
        'data'     => $heatData,
    ],
    'mps_trend'                    => $mpsTrend,
    'npwrm_per_section'            => $npwrmPerSection,
    'kpis'                         => [
        'overall_mps'     => round($overallMps, 2),
        'total_examinees' => $totalExaminees,
        'submitted_count' => $submittedCount,
        'below50_items'   => $below50,
    ],
]);
