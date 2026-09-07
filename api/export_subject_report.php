<?php
// School-wide subject performance report — ZipArchive-based XLSX, no external dependencies

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/MiniXlsx.php';

set_time_limit(120);


// ============================================================
// Auth + parameter validation
// ============================================================
$sess = require_login('admin');
$pdo  = get_pdo();

$syId        = validate_int($_GET['sy']      ?? null, 1);
$termId      = validate_int($_GET['term']    ?? null, 1);
$gradeFilter = validate_int($_GET['grade']   ?? null, 1);
$sectionId   = validate_int($_GET['section'] ?? null, 1);
$subjectName = validate_string($_GET['subject'] ?? '', 100) ?: null;

if (!$subjectName) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('A specific subject must be selected to generate this report.');
}

// School year display name
$syName = '';
if ($syId) {
    $r = $pdo->prepare('SELECT name FROM school_years WHERE id = ?');
    $r->execute([$syId]);
    $syName = (string)($r->fetchColumn() ?: '');
}

// ============================================================
// Load qualifying assessments
// ============================================================
$where  = ["a.status IN ('submitted','approved')", 's.name = ?'];
$params = [$subjectName];
if ($syId)        { $where[] = 't.school_year_id = ?'; $params[] = $syId; }
if ($termId)      { $where[] = 'a.term_id = ?';        $params[] = $termId; }
if ($gradeFilter) { $where[] = 's.grade_level = ?';    $params[] = $gradeFilter; }

$asmtStmt = $pdo->prepare(
    'SELECT a.id, a.total_items, a.title, a.date_given,
            s.name AS subject_name, s.grade_level,
            t.term_no, t.name AS term_name,
            sy.name AS sy_name
     FROM assessments a
     JOIN subjects s ON s.id = a.subject_id
     JOIN terms t    ON t.id = a.term_id
     JOIN school_years sy ON sy.id = t.school_year_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY a.date_given, a.id'
);
$asmtStmt->execute($params);
$assessments = $asmtStmt->fetchAll();

if (empty($assessments)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("No assessments found for \"{$subjectName}\" with the current filters. Nothing to export.");
}

$asmtById = [];
foreach ($assessments as $a) $asmtById[(int)$a['id']] = $a;
$asmtIds = array_column($assessments, 'id');
$in      = implode(',', array_fill(0, count($asmtIds), '?'));

$gradeLevel = $gradeFilter ?? (int)($assessments[0]['grade_level'] ?? 0);

// Collect distinct term labels for the cover page
$termNames = [];
foreach ($assessments as $a) $termNames['Term ' . $a['term_no']] = true;
$termsDisplay = implode(', ', array_keys($termNames)) ?: '—';
if ($termId && count($termNames) === 1) {
    $termsDisplay = array_key_first($termNames);
}

// ============================================================
// Batch data loading (single pass each — no N+1 queries)
// ============================================================

// Sections whose data is actually submitted/approved (see get_qualifying_sections()
// docblock) — score frequencies and item counts below are filtered through this
// so a teacher's un-submitted draft never ends up in the exported report.
$qualifying = get_qualifying_sections($pdo, $asmtIds);

// Sections assigned to each assessment
$asmtSecStmt = $pdo->prepare(
    'SELECT as_.assessment_id, sec.id AS section_id, sec.name AS section_name
     FROM assessment_sections as_
     JOIN sections sec ON sec.id = as_.section_id
     WHERE as_.assessment_id IN (' . $in . ')
     ORDER BY as_.assessment_id, sec.name'
);
$asmtSecStmt->execute($asmtIds);
$sectionsByAsmt = [];
foreach ($asmtSecStmt->fetchAll() as $r) {
    $sectionsByAsmt[(int)$r['assessment_id']][] = [
        'id'   => (int)$r['section_id'],
        'name' => $r['section_name'],
    ];
}

// Score frequencies
$sfExtra  = $sectionId ? ' AND sf.section_id = ?' : '';
$sfParams = $sectionId ? array_merge($asmtIds, [$sectionId]) : $asmtIds;
$sfStmt   = $pdo->prepare(
    'SELECT sf.assessment_id, sf.section_id, sf.score, sf.frequency
     FROM score_frequencies sf
     WHERE sf.assessment_id IN (' . $in . ') AND sf.frequency > 0' . $sfExtra
);
$sfStmt->execute($sfParams);

$sfByAsmt       = [];  // [aId][sId][score] = freq
$casesByAsmtSec = [];  // [aId][sId] = total cases
foreach ($sfStmt->fetchAll() as $r) {
    $aId  = (int)$r['assessment_id'];
    $sid  = (int)$r['section_id'];
    if (!isset($qualifying[$aId][$sid])) continue;
    $freq = (int)$r['frequency'];
    $sfByAsmt[$aId][$sid][(int)$r['score']] = $freq;
    $casesByAsmtSec[$aId][$sid] = ($casesByAsmtSec[$aId][$sid] ?? 0) + $freq;
}

// Item correct counts
$iccStmt = $pdo->prepare(
    'SELECT icc.assessment_id, icc.section_id, icc.item_no, icc.correct_count
     FROM item_correct_counts icc
     WHERE icc.assessment_id IN (' . $in . ')' . ($sectionId ? ' AND icc.section_id = ?' : '')
);
$iccStmt->execute($sfParams);

$iccByAsmt = [];  // [aId][sId][itemNo] = correct_count
foreach ($iccStmt->fetchAll() as $r) {
    $aId = (int)$r['assessment_id'];
    $sid = (int)$r['section_id'];
    if (!isset($qualifying[$aId][$sid])) continue;
    $iccByAsmt[$aId][$sid][(int)$r['item_no']] = (int)$r['correct_count'];
}

// Competency mappings
$aicStmt = $pdo->prepare(
    'SELECT aic.assessment_id, aic.item_no, aic.competency_id, c.code, c.description
     FROM assessment_item_competencies aic
     JOIN competencies c ON c.id = aic.competency_id
     WHERE aic.assessment_id IN (' . $in . ')'
);
$aicStmt->execute($asmtIds);

$itemCompLookup = [];  // [aId][ino] => compId
$compInfo       = [];  // [compId] => {code, description}
foreach ($aicStmt->fetchAll() as $r) {
    $itemCompLookup[(int)$r['assessment_id']][(int)$r['item_no']] = (int)$r['competency_id'];
    $compInfo[(int)$r['competency_id']] = ['code' => $r['code'] ?? '', 'description' => $r['description']];
}

// ============================================================
// Derived stats
// ============================================================

// Per-assessment MPS summary (for cover sheet table + weighted overall)
$asmtSummary      = [];
$totalWeightedMps = 0.0;
$totalCasesAll    = 0;

foreach ($assessments as $asmt) {
    $aId   = (int)$asmt['id'];
    $ti    = (int)$asmt['total_items'];
    $cases = 0;
    $fx    = 0;
    foreach ($sfByAsmt[$aId] ?? [] as $sid => $scores) {
        foreach ($scores as $score => $freq) { $cases += $freq; $fx += $freq * $score; }
    }
    $mps = ($cases > 0 && $ti > 0) ? round(($fx / $cases) / $ti * 100, 2) : 0.0;
    $asmtSummary[] = [
        'id'       => $aId,
        'title'    => $asmt['title'],
        'date'     => $asmt['date_given'],
        'sections' => count($sfByAsmt[$aId] ?? []),
        'mps'      => $mps,
        'cases'    => $cases,
    ];
    $totalWeightedMps += $mps * $cases;
    $totalCasesAll    += $cases;
}
$overallMps = $totalCasesAll > 0 ? round($totalWeightedMps / $totalCasesAll, 2) : 0.0;

// Cross-assessment competency stats (same accurate denominator as dashboard)
$compStats = [];
foreach ($iccByAsmt as $aId => $bySec) {
    foreach ($bySec as $sid => $byItem) {
        foreach ($byItem as $ino => $correct) {
            $comp = $itemCompLookup[$aId][$ino] ?? null;
            if ($comp === null) continue;
            if (!isset($compStats[$comp])) {
                $compStats[$comp] = ['correct' => 0, 'possible' => 0, 'items' => [], 'sections' => []];
            }
            $compStats[$comp]['correct']       += $correct;
            $compStats[$comp]['possible']      += $casesByAsmtSec[$aId][$sid] ?? 0;
            $compStats[$comp]['items'][$ino]    = true;
            $compStats[$comp]['sections'][$sid] = true;
        }
    }
}
$compList = [];
foreach ($compStats as $compId => $cs) {
    $pct = $cs['possible'] > 0 ? round($cs['correct'] / $cs['possible'] * 100, 2) : 0.0;
    $info = $compInfo[$compId] ?? ['code' => '', 'description' => 'Unknown'];
    $compList[] = [
        'code'          => $info['code'],
        'description'   => $info['description'],
        'pct'           => $pct,
        'item_count'    => count($cs['items']),
        'section_count' => count($cs['sections']),
    ];
}
usort($compList, fn($a, $b) => $a['pct'] <=> $b['pct']);

$adminName   = $sess['display'] ?? 'Administrator';
$gradeLine   = $gradeLevel ? 'Grade ' . $gradeLevel : 'All Grades';
$subjectLine = strtoupper($subjectName) . ' — ' . $gradeLine;

// ============================================================
// Build workbook
// ============================================================
$xl = new MiniXlsx();

// ===========================================================
// SHEET 1: SUMMARY / COVER
// ===========================================================
{
    $si = $xl->addSheet('SUMMARY');
    $NC = 5; // columns A–E

    $xl->colWidth($si, 1, 6);
    $xl->colWidth($si, 2, 38);
    $xl->colWidth($si, 3, 14);
    $xl->colWidth($si, 4, 16);
    $xl->colWidth($si, 5, 12);

    $row = xlDepEdHeader($xl, $si, $NC);
    $row++; // blank

    // Report title
    $xl->cell($si, $row, 1, 'SUBJECT PERFORMANCE REPORT', MiniXlsx::S_TITLE);
    $xl->merge($si, $row, 1, $row, $NC);
    $row++;
    $xl->cell($si, $row, 1, $subjectLine, MiniXlsx::S_BOLD_CTR);
    $xl->merge($si, $row, 1, $row, $NC);
    $row += 2;

    // Metadata block
    $meta = [
        'Subject'        => strtoupper($subjectName),
        'Grade Level'    => $gradeLine,
        'School Year'    => $syName ? 'SY ' . $syName : '—',
        'Term(s)'        => $termsDisplay,
        'Date Generated' => date('F j, Y'),
        'Prepared by'    => $adminName,
    ];
    foreach ($meta as $label => $val) {
        $xl->cell($si, $row, 1, $label . ':', MiniXlsx::S_BOLD);
        $xl->cell($si, $row, 2, $val);
        $xl->merge($si, $row, 2, $row, $NC);
        $row++;
    }
    $row++;

    // Assessments summary table
    $xl->cell($si, $row, 1, 'ASSESSMENTS INCLUDED', MiniXlsx::S_SUBHDR);
    $xl->merge($si, $row, 1, $row, $NC);
    $row++;

    $xl->cell($si, $row, 1, '#',                  MiniXlsx::S_HDR);
    $xl->cell($si, $row, 2, 'Assessment Title',   MiniXlsx::S_HDR);
    $xl->cell($si, $row, 3, 'Date Given',          MiniXlsx::S_HDR);
    $xl->cell($si, $row, 4, 'Sections w/ Data',    MiniXlsx::S_HDR);
    $xl->cell($si, $row, 5, 'MPS (%)',             MiniXlsx::S_HDR);
    $row++;

    foreach ($asmtSummary as $i => $a) {
        $xl->cell($si, $row, 1, $i + 1);
        $xl->cell($si, $row, 2, $a['title']);
        $xl->cell($si, $row, 3, $a['date'] ?: '—');
        $xl->cell($si, $row, 4, $a['sections']);
        $xl->cell($si, $row, 5, $a['mps'], mpsStyle($a['mps']));
        $row++;
    }
    $row++;

    // Overall MPS
    $xl->cell($si, $row, 1, 'OVERALL COMBINED MPS:', MiniXlsx::S_BOLD);
    $xl->merge($si, $row, 1, $row, 4);
    $xl->cell($si, $row, 5, $overallMps . '%', MiniXlsx::S_BOLD);
    $row += 2;

    // Top 5 least-mastered competencies
    if (!empty($compList)) {
        $xl->cell($si, $row, 1, 'TOP 5 LEAST-MASTERED LEARNING COMPETENCIES', MiniXlsx::S_SUBHDR);
        $xl->merge($si, $row, 1, $row, $NC);
        $row++;

        $xl->cell($si, $row, 1, 'Code',                MiniXlsx::S_HDR);
        $xl->cell($si, $row, 2, 'Learning Competency', MiniXlsx::S_HDR);
        $xl->merge($si, $row, 2, $row, 4);
        $xl->cell($si, $row, 5, '% Correct',           MiniXlsx::S_HDR);
        $row++;

        foreach (array_slice($compList, 0, 5) as $comp) {
            $xl->cell($si, $row, 1, $comp['code']);
            $xl->cell($si, $row, 2, $comp['description']);
            $xl->merge($si, $row, 2, $row, 4);
            $xl->cell($si, $row, 5, $comp['pct'], mpsStyle($comp['pct']));
            $row++;
        }
    }
}

// ===========================================================
// SHEET PAIRS: MPS + IA per assessment
// ===========================================================
foreach ($assessments as $asmt) {
    $aId      = (int)$asmt['id'];
    $ti       = (int)$asmt['total_items'];
    $sections = $sectionsByAsmt[$aId] ?? [];
    $sfData   = $sfByAsmt[$aId]   ?? [];
    $iccData  = $iccByAsmt[$aId]  ?? [];
    $numSecs  = count($sections);

    // Per-section case counts and Σf(x)
    $secCases  = [];
    $secTotals = [];  // [sid] => {f, fx}
    foreach ($sections as $sec) {
        $sid = $sec['id'];
        $c   = 0;
        $fx  = 0;
        foreach ($sfData[$sid] ?? [] as $score => $freq) {
            $c  += $freq;
            $fx += $freq * $score;
        }
        $secCases[$sid]  = $c;
        $secTotals[$sid] = ['f' => $c, 'fx' => $fx];
    }
    $grandF  = array_sum(array_column($secTotals, 'f'));
    $grandFx = array_sum(array_column($secTotals, 'fx'));

    // Sheet name: "MPS - <title>" / "IA - <title>" (sanitized, 31-char limit)
    $safeTitle   = preg_replace('/[^a-zA-Z0-9 \-_]/', '', $asmt['title']);
    $dataLastCol = 1 + $numSecs * 2 + 2; // Score + (f,fx)×n + Total f + Total fx

    // -------------------------------------------------------
    // MPS sheet
    // -------------------------------------------------------
    {
        $si = $xl->addSheet('MPS - ' . $safeTitle);
        $NC = $dataLastCol;

        $xl->colWidth($si, 1, 8);
        for ($c = 2; $c <= $NC; $c++) $xl->colWidth($si, $c, 10);

        $row = xlDepEdHeader($xl, $si, $NC);
        $row++;

        $xl->cell($si, $row, 1, 'Subject:', MiniXlsx::S_BOLD);
        $xl->cell($si, $row, 2, strtoupper($asmt['subject_name']) . ' (Grade ' . $asmt['grade_level'] . ')');
        $row++;
        $xl->cell($si, $row, 1, 'Test Title:', MiniXlsx::S_BOLD);
        $xl->cell($si, $row, 2, strtoupper($asmt['title']));
        $row++;
        $xl->cell($si, $row, 1, 'Term:', MiniXlsx::S_BOLD);
        $xl->cell($si, $row, 2, 'Term ' . $asmt['term_no'] . ' — ' . $asmt['term_name']);
        $row += 2;

        // Two-row table header
        $hr1 = $row;
        $col = 1;
        $xl->cell($si, $hr1, $col++, 'Score', MiniXlsx::S_HDR);
        foreach ($sections as $sec) {
            $xl->cell($si, $hr1, $col, $sec['name'], MiniXlsx::S_HDR);
            $xl->merge($si, $hr1, $col, $hr1, $col + 1);
            $col += 2;
        }
        $xl->cell($si, $hr1, $col, 'TOTAL', MiniXlsx::S_HDR);
        $xl->merge($si, $hr1, $col, $hr1, $col + 1);

        $hr2 = $hr1 + 1;
        $xl->merge($si, $hr1, 1, $hr2, 1); // Score spans both header rows
        $col = 2;
        foreach ($sections as $_) {
            $xl->cell($si, $hr2, $col++, 'f',    MiniXlsx::S_HDR);
            $xl->cell($si, $hr2, $col++, 'f(x)', MiniXlsx::S_HDR);
        }
        $xl->cell($si, $hr2, $col++, 'f',    MiniXlsx::S_HDR);
        $xl->cell($si, $hr2, $col,   'f(x)', MiniXlsx::S_HDR);
        $row += 2;

        // Score data rows (highest to 0)
        for ($score = $ti; $score >= 0; $score--) {
            $col   = 1;
            $rowF  = 0;
            $rowFx = 0;
            $xl->cell($si, $row, $col++, $score);
            foreach ($sections as $sec) {
                $f  = $sfData[$sec['id']][$score] ?? 0;
                $fx = $f * $score;
                $xl->cell($si, $row, $col++, $f  ?: null);
                $xl->cell($si, $row, $col++, $fx ?: null);
                $rowF  += $f;
                $rowFx += $fx;
            }
            $xl->cell($si, $row, $col++, $rowF  ?: null);
            $xl->cell($si, $row, $col,   $rowFx ?: null);
            $row++;
        }

        // Summary rows
        $col = 1;
        $xl->cell($si, $row, $col++, 'CASES', MiniXlsx::S_BOLD);
        foreach ($sections as $sec) {
            $xl->cell($si, $row, $col++, $secTotals[$sec['id']]['f']);
            $xl->cell($si, $row, $col++, $secTotals[$sec['id']]['fx']);
        }
        $xl->cell($si, $row, $col++, $grandF);
        $xl->cell($si, $row, $col,   $grandFx);
        $row++;

        $col = 1;
        $xl->cell($si, $row, $col++, 'MEAN', MiniXlsx::S_BOLD);
        foreach ($sections as $sec) {
            $sid  = $sec['id'];
            $c    = $secTotals[$sid]['f'];
            $mean = $c > 0 ? round($secTotals[$sid]['fx'] / $c, 2) : '—';
            $xl->cell($si, $row, $col++, $mean);
            $col++; // skip fx column
        }
        $gMean = $grandF > 0 ? round($grandFx / $grandF, 2) : '—';
        $xl->cell($si, $row, $col, $gMean);
        $row++;

        $col = 1;
        $xl->cell($si, $row, $col++, 'MPS (%)', MiniXlsx::S_BOLD);
        foreach ($sections as $sec) {
            $sid  = $sec['id'];
            $c    = $secTotals[$sid]['f'];
            $mean = $c > 0 ? $secTotals[$sid]['fx'] / $c : 0;
            $mps  = ($c > 0 && $ti > 0) ? round($mean / $ti * 100, 2) : '—';
            $sty  = is_numeric($mps) ? mpsStyle((float)$mps) : 0;
            $xl->cell($si, $row, $col++, $mps, $sty);
            $col++;
        }
        $gMps = ($grandF > 0 && $ti > 0) ? round(($grandFx / $grandF) / $ti * 100, 2) : '—';
        $xl->cell($si, $row, $col, $gMps, is_numeric($gMps) ? mpsStyle((float)$gMps) : 0);
        $row++;

        foreach (MASTERY_BANDS as $bk => $band) {
            $col = 1;
            $xl->cell($si, $row, $col++, $bk . ' — ' . $band['label'], MiniXlsx::S_BOLD);
            foreach ($sections as $sec) {
                $sid = $sec['id'];
                $c   = $secTotals[$sid]['f'];
                $cnt = 0;
                foreach ($sfData[$sid] ?? [] as $score => $freq) {
                    $p = $ti > 0 ? $score / $ti * 100 : 0;
                    if ($p >= $band['min'] && $p <= $band['max']) $cnt += $freq;
                }
                $xl->cell($si, $row, $col++, $cnt);
                $xl->cell($si, $row, $col++, $c > 0 ? round($cnt / $c * 100, 1) . '%' : '—');
            }
            $row++;
        }

        foreach (PROFICIENCY_LEVELS as $pk => $level) {
            $col = 1;
            $xl->cell($si, $row, $col++, $level['label'] . ' (' . $level['min'] . '–' . $level['max'] . '%)', MiniXlsx::S_BOLD);
            foreach ($sections as $sec) {
                $sid = $sec['id'];
                $c   = $secTotals[$sid]['f'];
                $cnt = 0;
                foreach ($sfData[$sid] ?? [] as $score => $freq) {
                    $p = $ti > 0 ? $score / $ti * 100 : 0;
                    if ($p >= $level['min'] && $p <= $level['max']) $cnt += $freq;
                }
                $xl->cell($si, $row, $col++, $cnt);
                $xl->cell($si, $row, $col++, $c > 0 ? round($cnt / $c * 100, 1) . '%' : '—');
            }
            $row++;
        }

        $col = 1;
        $xl->cell($si, $row, $col++, 'NPWRM (≥' . MASTERY_THRESHOLD . '%)', MiniXlsx::S_BOLD);
        foreach ($sections as $sec) {
            $npwrm = 0;
            foreach ($sfData[$sec['id']] ?? [] as $score => $freq) {
                if ($ti > 0 && $score / $ti * 100 >= MASTERY_THRESHOLD) $npwrm += $freq;
            }
            $xl->cell($si, $row, $col++, $npwrm);
            $col++;
        }
    }

    // -------------------------------------------------------
    // Item Analysis sheet
    // -------------------------------------------------------
    {
        $si = $xl->addSheet('IA - ' . $safeTitle);
        $NC = $dataLastCol;

        $xl->colWidth($si, 1, 10);
        for ($c = 2; $c <= $NC; $c++) $xl->colWidth($si, $c, 10);

        $row = xlDepEdHeader($xl, $si, $NC);
        $row++;

        $xl->cell($si, $row, 1, 'Subject:', MiniXlsx::S_BOLD);
        $xl->cell($si, $row, 2, strtoupper($asmt['subject_name']) . ' (Grade ' . $asmt['grade_level'] . ')');
        $row++;
        $xl->cell($si, $row, 1, 'Test Title:', MiniXlsx::S_BOLD);
        $xl->cell($si, $row, 2, strtoupper($asmt['title']));
        $row += 2;

        // Two-row header
        $hr1 = $row;
        $col = 1;
        $xl->cell($si, $hr1, $col++, 'Item No.', MiniXlsx::S_HDR);
        foreach ($sections as $sec) {
            $xl->cell($si, $hr1, $col, $sec['name'], MiniXlsx::S_HDR);
            $xl->merge($si, $hr1, $col, $hr1, $col + 1);
            $col += 2;
        }
        $xl->cell($si, $hr1, $col, 'TOTAL', MiniXlsx::S_HDR);
        $xl->merge($si, $hr1, $col, $hr1, $col + 1);

        $hr2 = $hr1 + 1;
        $xl->merge($si, $hr1, 1, $hr2, 1);
        $col = 2;
        foreach ($sections as $_) {
            $xl->cell($si, $hr2, $col++, 'f', MiniXlsx::S_HDR);
            $xl->cell($si, $hr2, $col++, '%', MiniXlsx::S_HDR);
        }
        $xl->cell($si, $hr2, $col++, 'f', MiniXlsx::S_HDR);
        $xl->cell($si, $hr2, $col,   '%', MiniXlsx::S_HDR);
        $row += 2;

        // CASES row (denominator for % values)
        $col    = 1;
        $gCases = 0;
        $xl->cell($si, $row, $col++, 'CASES', MiniXlsx::S_BOLD);
        foreach ($sections as $sec) {
            $c = $secCases[$sec['id']] ?? 0;
            $xl->cell($si, $row, $col++, $c ?: null);
            $col++; // blank % column
            $gCases += $c;
        }
        $xl->cell($si, $row, $col, $gCases ?: null);
        $row++;

        // Item rows
        for ($item = 1; $item <= $ti; $item++) {
            $col      = 1;
            $totF     = 0;
            $totCases = 0;
            $xl->cell($si, $row, $col++, $item);
            foreach ($sections as $sec) {
                $sid  = $sec['id'];
                $f    = $iccData[$sid][$item] ?? 0;
                $c    = $secCases[$sid] ?? 0;
                $pct  = $c > 0 ? round($f / $c * 100, 2) : null;
                $sty  = ($pct === null) ? 0 : mpsStyle($pct);
                $xl->cell($si, $row, $col++, $f ?: null);
                $xl->cell($si, $row, $col++, $pct, $sty);
                $totF     += $f;
                $totCases += $c;
            }
            $totPct = $totCases > 0 ? round($totF / $totCases * 100, 2) : null;
            $tSty   = ($totPct === null) ? 0 : mpsStyle($totPct);
            $xl->cell($si, $row, $col++, $totF ?: null);
            $xl->cell($si, $row, $col,   $totPct, $tSty);
            $row++;
        }
    }
}

// ===========================================================
// LAST SHEET: COMPETENCY SUMMARY
// ===========================================================
if (!empty($compList)) {
    $si = $xl->addSheet('COMPETENCY SUMMARY');
    $NC = 5;

    $xl->colWidth($si, 1, 15);
    $xl->colWidth($si, 2, 45);
    $xl->colWidth($si, 3, 12);
    $xl->colWidth($si, 4, 12);
    $xl->colWidth($si, 5, 12);

    $row = xlDepEdHeader($xl, $si, $NC);
    $row++;

    $xl->cell($si, $row, 1, 'COMPETENCY ANALYSIS — ' . $subjectLine, MiniXlsx::S_TITLE);
    $xl->merge($si, $row, 1, $row, $NC);
    $row += 2;

    $xl->cell($si, $row, 1, 'Code',                  MiniXlsx::S_HDR);
    $xl->cell($si, $row, 2, 'Learning Competency',   MiniXlsx::S_HDR);
    $xl->cell($si, $row, 3, '% Correct',             MiniXlsx::S_HDR);
    $xl->cell($si, $row, 4, 'Items Mapped',           MiniXlsx::S_HDR);
    $xl->cell($si, $row, 5, 'Sections',               MiniXlsx::S_HDR);
    $row++;

    foreach ($compList as $comp) {
        $xl->cell($si, $row, 1, $comp['code']);
        $xl->cell($si, $row, 2, $comp['description']);
        $xl->cell($si, $row, 3, $comp['pct'],          mpsStyle($comp['pct']));
        $xl->cell($si, $row, 4, $comp['item_count']);
        $xl->cell($si, $row, 5, $comp['section_count']);
        $row++;
    }
}

// ===========================================================
// Output
// ===========================================================
$slugSubject = preg_replace('/[^a-zA-Z0-9_-]/', '_', $subjectName);
$slugGrade   = $gradeLevel ? 'G' . $gradeLevel : 'AllGrades';
$slugSY      = preg_replace('/[^a-zA-Z0-9_-]/', '_', $syName ?: 'AllYears');
$filename    = "Subject_Report_{$slugSubject}_{$slugGrade}_{$slugSY}.xlsx";

$xl->output($filename);
