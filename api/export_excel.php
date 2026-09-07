<?php
// Teacher/admin single-assessment MPS + Item Analysis export — ZipArchive-based
// XLSX via MiniXlsx, no external dependencies (no Composer/vendor needed).

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/MiniXlsx.php';

$sess          = require_login();
$uid           = (int)$sess['user_id'];
$role          = $sess['role'];
$assessment_id = validate_int($_GET['assessment_id'] ?? null, 1);
if (!$assessment_id) { http_response_code(400); exit('Missing assessment_id.'); }

$pdo = get_pdo();

// Fetch assessment
$stmt = $pdo->prepare(
    "SELECT a.*, s.name AS subject_name, s.grade_level,
            t.term_no, t.name AS term_name,
            u.last_name, u.first_name, u.middle_name
     FROM assessments a
     JOIN subjects s ON s.id = a.subject_id
     JOIN terms t ON t.id = a.term_id
     LEFT JOIN users u ON u.id = a.teacher_id
     WHERE a.id = ?"
);
$stmt->execute([$assessment_id]);
$asmt = $stmt->fetch();
if (!$asmt) { http_response_code(404); exit('Assessment not found.'); }
$isShared = (bool)($asmt['is_shared'] ?? false);
if ($role === 'teacher') {
    if ($isShared) {
        $taeCheck = $pdo->prepare("SELECT id FROM teacher_assessment_encodings WHERE assessment_id=? AND teacher_id=?");
        $taeCheck->execute([$assessment_id, $uid]);
        if (!$taeCheck->fetch()) { http_response_code(403); exit('Access denied.'); }
    } elseif ((int)$asmt['teacher_id'] !== $uid) {
        http_response_code(403); exit('Access denied.');
    }
}

$totalItems  = (int)$asmt['total_items'];
$teacherName = $asmt['last_name'] ? 'MR./MS. ' . display_name($asmt) : 'Admin-Created Assessment';

// Sections for this assessment (stored in assessment_sections at creation time)
$secStmt = $pdo->prepare(
    "SELECT sec.id, sec.name
     FROM assessment_sections as_
     JOIN sections sec ON sec.id = as_.section_id
     WHERE as_.assessment_id = ?
     ORDER BY sec.name"
);
$secStmt->execute([$assessment_id]);
$sections = $secStmt->fetchAll();
$secIds   = array_column($sections, 'id');

// Score frequencies
$sfStmt = $pdo->prepare("SELECT section_id, score, frequency FROM score_frequencies WHERE assessment_id=? AND frequency>0");
$sfStmt->execute([$assessment_id]);
$sf = [];
foreach ($sfStmt->fetchAll() as $r) { $sf[(int)$r['section_id']][(int)$r['score']] = (int)$r['frequency']; }

// Item correct counts
$iccStmt = $pdo->prepare("SELECT section_id, item_no, correct_count FROM item_correct_counts WHERE assessment_id=? ORDER BY item_no");
$iccStmt->execute([$assessment_id]);
$icc = [];
foreach ($iccStmt->fetchAll() as $r) { $icc[(int)$r['section_id']][(int)$r['item_no']] = (int)$r['correct_count']; }

// Per-section CASES and Σf(x)
$sectionCases = [];
$sectionFx    = [];
foreach ($secIds as $sid) {
    $c = 0; $fx = 0;
    foreach ($sf[$sid] ?? [] as $score => $freq) {
        $c  += $freq;
        $fx += $freq * $score;
    }
    $sectionCases[$sid] = $c;
    $sectionFx[$sid]    = $fx;
}

$numSecs     = count($sections);
$dataLastCol = 1 + $numSecs * 2 + 2; // Score/Item + (f,fx or f,%)×secs + Total pair

$xl = new MiniXlsx();

// ============================================================
// SHEET 1: MPS (Frequency of Scores)
// ============================================================
$si1 = $xl->addSheet('MPS');
$xl->colWidth($si1, 1, 10);
for ($c = 2; $c <= $dataLastCol; $c++) $xl->colWidth($si1, $c, 10);

$row = xlDepEdHeader($xl, $si1, $dataLastCol);
$row++;

$xl->cell($si1, $row, 1, 'Subject:', MiniXlsx::S_BOLD);
$xl->cell($si1, $row, 2, strtoupper($asmt['subject_name']) . ' (Grade ' . $asmt['grade_level'] . ')');
$row++;
$xl->cell($si1, $row, 1, 'Test Title:', MiniXlsx::S_BOLD);
$xl->cell($si1, $row, 2, strtoupper($asmt['title']));
$row++;
$xl->cell($si1, $row, 1, 'Teacher:', MiniXlsx::S_BOLD);
$xl->cell($si1, $row, 2, $teacherName);
$row++;
$xl->cell($si1, $row, 1, 'Term:', MiniXlsx::S_BOLD);
$xl->cell($si1, $row, 2, 'Term ' . $asmt['term_no'] . ' — ' . $asmt['term_name']);
$row += 2;

// Two-row table header
$hr1 = $row;
$col = 1;
$xl->cell($si1, $hr1, $col++, 'Score', MiniXlsx::S_HDR);
foreach ($sections as $sec) {
    $xl->cell($si1, $hr1, $col, $sec['name'], MiniXlsx::S_HDR);
    $xl->merge($si1, $hr1, $col, $hr1, $col + 1);
    $col += 2;
}
$xl->cell($si1, $hr1, $col, 'TOTAL', MiniXlsx::S_HDR);
$xl->merge($si1, $hr1, $col, $hr1, $col + 1);

$hr2 = $hr1 + 1;
$xl->merge($si1, $hr1, 1, $hr2, 1); // Score spans both header rows
$col = 2;
foreach ($sections as $_sec) {
    $xl->cell($si1, $hr2, $col++, 'f',    MiniXlsx::S_HDR);
    $xl->cell($si1, $hr2, $col++, 'f(x)', MiniXlsx::S_HDR);
}
$xl->cell($si1, $hr2, $col++, 'f',    MiniXlsx::S_HDR);
$xl->cell($si1, $hr2, $col,   'f(x)', MiniXlsx::S_HDR);
$row += 2;

// Data rows (scores totalItems down to 0)
$totals = array_fill(0, count($sections), ['f' => 0, 'fx' => 0]);
$grandF = 0; $grandFx = 0;

for ($score = $totalItems; $score >= 0; $score--) {
    $col = 1;
    $xl->cell($si1, $row, $col++, $score);
    $rowTotalF = 0; $rowTotalFx = 0;
    foreach ($sections as $i => $sec) {
        $f  = $sf[$sec['id']][$score] ?? 0;
        $fx = $f * $score;
        $xl->cell($si1, $row, $col++, $f  ?: null);
        $xl->cell($si1, $row, $col++, $fx ?: null);
        $totals[$i]['f']  += $f;
        $totals[$i]['fx'] += $fx;
        $rowTotalF  += $f;
        $rowTotalFx += $fx;
    }
    $xl->cell($si1, $row, $col++, $rowTotalF  ?: null);
    $xl->cell($si1, $row, $col,   $rowTotalFx ?: null);
    $grandF  += $rowTotalF;
    $grandFx += $rowTotalFx;
    $row++;
}

// CASES
$col = 1;
$xl->cell($si1, $row, $col++, 'CASES', MiniXlsx::S_BOLD);
foreach ($sections as $i => $sec) {
    $xl->cell($si1, $row, $col++, $totals[$i]['f']);
    $xl->cell($si1, $row, $col++, $totals[$i]['fx']);
}
$xl->cell($si1, $row, $col++, $grandF);
$xl->cell($si1, $row, $col,   $grandFx);
$row++;

// MEAN
$col = 1;
$xl->cell($si1, $row, $col++, 'MEAN', MiniXlsx::S_BOLD);
foreach ($sections as $i => $sec) {
    $cases = $totals[$i]['f'];
    $mean  = $cases > 0 ? round($totals[$i]['fx'] / $cases, 2) : '—';
    $xl->cell($si1, $row, $col++, $mean);
    $col++;
}
$grandMean = $grandF > 0 ? round($grandFx / $grandF, 2) : '—';
$xl->cell($si1, $row, $col, $grandMean);
$row++;

// MPS
$col = 1;
$xl->cell($si1, $row, $col++, 'MPS (%)', MiniXlsx::S_BOLD);
foreach ($sections as $i => $sec) {
    $cases = $totals[$i]['f'];
    $mean  = $cases > 0 ? $totals[$i]['fx'] / $cases : 0;
    $mps   = ($cases > 0 && $totalItems > 0) ? round($mean / $totalItems * 100, 2) : '—';
    $sty   = is_numeric($mps) ? mpsStyle((float)$mps) : 0;
    $xl->cell($si1, $row, $col++, $mps, $sty);
    $col++;
}
$grandMps = ($grandF > 0 && $totalItems > 0) ? round(($grandFx / $grandF) / $totalItems * 100, 2) : '—';
$xl->cell($si1, $row, $col, $grandMps, is_numeric($grandMps) ? mpsStyle((float)$grandMps) : 0);
$row++;

// Mastery Bands
foreach (MASTERY_BANDS as $bk => $band) {
    $col = 1;
    $xl->cell($si1, $row, $col++, $bk . ' — ' . $band['label'], MiniXlsx::S_BOLD);
    foreach ($sections as $i => $sec) {
        $cases = $totals[$i]['f'];
        $cnt = 0;
        foreach ($sf[$sec['id']] ?? [] as $score => $freq) {
            $pct2 = $totalItems > 0 ? $score / $totalItems * 100 : 0;
            if ($pct2 >= $band['min'] && $pct2 <= $band['max']) $cnt += $freq;
        }
        $prop = $cases > 0 ? round($cnt / $cases * 100, 1) . '%' : '—';
        $xl->cell($si1, $row, $col++, $cnt ?: null);
        $xl->cell($si1, $row, $col++, $prop);
    }
    $row++;
}

// Proficiency Levels (DepEd descriptor rating scale)
foreach (PROFICIENCY_LEVELS as $pk => $level) {
    $col = 1;
    $xl->cell($si1, $row, $col++, $level['label'] . ' (' . $level['min'] . '–' . $level['max'] . '%)', MiniXlsx::S_BOLD);
    foreach ($sections as $i => $sec) {
        $cases = $totals[$i]['f'];
        $cnt = 0;
        foreach ($sf[$sec['id']] ?? [] as $score => $freq) {
            $pct2 = $totalItems > 0 ? $score / $totalItems * 100 : 0;
            if ($pct2 >= $level['min'] && $pct2 <= $level['max']) $cnt += $freq;
        }
        $prop = $cases > 0 ? round($cnt / $cases * 100, 1) . '%' : '—';
        $xl->cell($si1, $row, $col++, $cnt ?: null);
        $xl->cell($si1, $row, $col++, $prop);
    }
    $row++;
}

// NPWRM
$col = 1;
$xl->cell($si1, $row, $col++, 'NPWRM (≥' . MASTERY_THRESHOLD . '%)', MiniXlsx::S_BOLD);
foreach ($sections as $i => $sec) {
    $npwrm = 0;
    foreach ($sf[$sec['id']] ?? [] as $score => $freq) {
        $pct3 = $totalItems > 0 ? $score / $totalItems * 100 : 0;
        if ($pct3 >= MASTERY_THRESHOLD) $npwrm += $freq;
    }
    $xl->cell($si1, $row, $col++, $npwrm ?: null);
    $col++;
}

// ============================================================
// SHEET 2: ITEM ANALYSIS
// ============================================================
$si2 = $xl->addSheet('ITEM ANALYSIS');
$xl->colWidth($si2, 1, 10);
for ($c = 2; $c <= $dataLastCol; $c++) $xl->colWidth($si2, $c, 10);

$row2 = xlDepEdHeader($xl, $si2, $dataLastCol);
$row2++;
$xl->cell($si2, $row2, 1, 'Subject:', MiniXlsx::S_BOLD);
$xl->cell($si2, $row2, 2, strtoupper($asmt['subject_name']) . ' (Grade ' . $asmt['grade_level'] . ')');
$row2++;
$xl->cell($si2, $row2, 1, 'Test Title:', MiniXlsx::S_BOLD);
$xl->cell($si2, $row2, 2, strtoupper($asmt['title']));
$row2++;
$xl->cell($si2, $row2, 1, 'Teacher:', MiniXlsx::S_BOLD);
$xl->cell($si2, $row2, 2, $teacherName);
$row2 += 2;

// Header
$hr1b = $row2;
$col = 1;
$xl->cell($si2, $hr1b, $col++, 'Item No.', MiniXlsx::S_HDR);
foreach ($sections as $sec) {
    $xl->cell($si2, $hr1b, $col, $sec['name'], MiniXlsx::S_HDR);
    $xl->merge($si2, $hr1b, $col, $hr1b, $col + 1);
    $col += 2;
}
$xl->cell($si2, $hr1b, $col, 'TOTAL', MiniXlsx::S_HDR);
$xl->merge($si2, $hr1b, $col, $hr1b, $col + 1);

$hr2b = $hr1b + 1;
$xl->merge($si2, $hr1b, 1, $hr2b, 1);
$col = 2;
foreach ($sections as $_sec) {
    $xl->cell($si2, $hr2b, $col++, 'f', MiniXlsx::S_HDR);
    $xl->cell($si2, $hr2b, $col++, '%', MiniXlsx::S_HDR);
}
$xl->cell($si2, $hr2b, $col++, 'f', MiniXlsx::S_HDR);
$xl->cell($si2, $hr2b, $col,   '%', MiniXlsx::S_HDR);
$row2 += 2;

// CASES row — denominator for the % columns
$casesRow = $row2;
$col = 1;
$xl->cell($si2, $casesRow, $col++, 'CASES', MiniXlsx::S_BOLD);
$grandCases = 0;
foreach ($sections as $sec) {
    $cases = $sectionCases[$sec['id']] ?? 0;
    $xl->cell($si2, $casesRow, $col++, $cases ?: null);
    $col++; // % column blank for CASES row
    $grandCases += $cases;
}
$xl->cell($si2, $casesRow, $col, $grandCases ?: null);
$row2++;

// Item rows
for ($item = 1; $item <= $totalItems; $item++) {
    $col = 1;
    $xl->cell($si2, $row2, $col++, $item);
    $totF = 0; $totCases = 0;
    foreach ($sections as $sec) {
        $f     = $icc[$sec['id']][$item] ?? 0;
        $cases = $sectionCases[$sec['id']] ?? 0;
        $pct   = $cases > 0 ? round($f / $cases * 100, 2) : null;
        $sty   = ($pct === null) ? 0 : mpsStyle($pct);
        $xl->cell($si2, $row2, $col++, $f ?: null);
        $xl->cell($si2, $row2, $col++, $pct ?? '—', $sty);
        $totF     += $f;
        $totCases += $cases;
    }
    $totPct = $totCases > 0 ? round($totF / $totCases * 100, 2) : null;
    $tSty   = ($totPct === null) ? 0 : mpsStyle($totPct);
    $xl->cell($si2, $row2, $col++, $totF ?: null);
    $xl->cell($si2, $row2, $col,   $totPct ?? '—', $tSty);
    $row2++;
}

// TOTAL row — sum of item correct counts per section, cross-checked against MPS Σf(x)
$col = 1;
$xl->cell($si2, $row2, $col++, 'TOTAL', MiniXlsx::S_BOLD);
$grandItemTotal = 0;
foreach ($sections as $sec) {
    $itemTotal = 0;
    foreach ($icc[$sec['id']] ?? [] as $cnt) $itemTotal += $cnt;
    $grandItemTotal += $itemTotal;
    $fx      = $sectionFx[$sec['id']] ?? 0;
    $mismatch = ($fx > 0 && $itemTotal !== $fx);
    $xl->cell($si2, $row2, $col, $itemTotal ?: null, $mismatch ? MiniXlsx::S_RED : 0);
    $col += 2; // skip % column in TOTAL row
}
$xl->cell($si2, $row2, $col, $grandItemTotal ?: null);

// ============================================================
// SHEET 3: COMPETENCY ANALYSIS
// ============================================================
$compMapStmt = $pdo->prepare(
    "SELECT aic.item_no, c.id AS competency_id, c.code, c.description
     FROM assessment_item_competencies aic
     JOIN competencies c ON c.id = aic.competency_id
     WHERE aic.assessment_id = ?
     ORDER BY aic.item_no"
);
$compMapStmt->execute([$assessment_id]);
$compMapRows = $compMapStmt->fetchAll();

if (!empty($compMapRows)) {
    $compLastCol = 3 + count($sections) + 1; // Code+Desc+Items + sections + Overall
    $si3 = $xl->addSheet('COMPETENCY ANALYSIS');
    $xl->colWidth($si3, 1, 12);
    $xl->colWidth($si3, 2, 45);
    $xl->colWidth($si3, 3, 14);
    for ($c = 4; $c <= $compLastCol; $c++) $xl->colWidth($si3, $c, 12);

    $row3 = xlDepEdHeader($xl, $si3, $compLastCol);
    $row3++;
    $xl->cell($si3, $row3, 1, 'Subject:', MiniXlsx::S_BOLD);
    $xl->cell($si3, $row3, 2, strtoupper($asmt['subject_name']) . ' (Grade ' . $asmt['grade_level'] . ')');
    $row3++;
    $xl->cell($si3, $row3, 1, 'Test Title:', MiniXlsx::S_BOLD);
    $xl->cell($si3, $row3, 2, strtoupper($asmt['title']));
    $row3 += 2;

    // Header
    $hrC = $row3;
    $col = 1;
    $xl->cell($si3, $hrC, $col++, 'Code', MiniXlsx::S_HDR);
    $xl->cell($si3, $hrC, $col++, 'Learning Competency', MiniXlsx::S_HDR);
    $xl->cell($si3, $hrC, $col++, 'Items', MiniXlsx::S_HDR);
    foreach ($sections as $sec) {
        $xl->cell($si3, $hrC, $col++, $sec['name'] . ' %', MiniXlsx::S_HDR);
    }
    $xl->cell($si3, $hrC, $col, 'Overall %', MiniXlsx::S_HDR);
    $row3++;

    // Group items by competency
    $byComp = []; // competency_id → {code, description, items[]}
    foreach ($compMapRows as $r) {
        $cid = (int)$r['competency_id'];
        if (!isset($byComp[$cid])) {
            $byComp[$cid] = ['code' => $r['code'] ?? '', 'description' => $r['description'], 'items' => []];
        }
        $byComp[$cid]['items'][] = (int)$r['item_no'];
    }

    foreach ($byComp as $cid => $cdata) {
        $col = 1;
        $items = $cdata['items'];
        sort($items);
        $itemsLabel = implode(',', $items);

        $secPcts  = [];
        $totCorr  = 0;
        $totCases = 0;
        foreach ($sections as $sec) {
            $secCorr  = 0;
            $secCases = $sectionCases[$sec['id']] ?? 0;
            foreach ($items as $ino) {
                $secCorr += $icc[$sec['id']][$ino] ?? 0;
            }
            $pctSec = $secCases > 0 ? round($secCorr / $secCases * 100, 2) : '—';
            $secPcts[] = $pctSec;
            if (is_numeric($pctSec)) { $totCorr += $secCorr; $totCases += $secCases; }
        }
        $overallPct = $totCases > 0 ? round($totCorr / $totCases * 100, 2) : '—';

        $xl->cell($si3, $row3, $col++, $cdata['code']);
        $xl->cell($si3, $row3, $col++, $cdata['description']);
        $xl->cell($si3, $row3, $col++, $itemsLabel);
        foreach ($secPcts as $sp) {
            $xl->cell($si3, $row3, $col++, $sp, is_numeric($sp) ? mpsStyle((float)$sp) : 0);
        }
        $xl->cell($si3, $row3, $col, $overallPct, is_numeric($overallPct) ? mpsStyle((float)$overallPct) : 0);
        $row3++;
    }
}

// ---- Output ----
$filename = preg_replace('/[^a-z0-9_-]/i', '_', $asmt['title']) . '_MPS_ItemAnalysis.xlsx';
$xl->output($filename);
