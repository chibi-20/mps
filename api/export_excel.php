<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

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
     JOIN users u ON u.id = a.teacher_id
     WHERE a.id = ?"
);
$stmt->execute([$assessment_id]);
$asmt = $stmt->fetch();
if (!$asmt) { http_response_code(404); exit('Assessment not found.'); }
if ($role === 'teacher' && (int)$asmt['teacher_id'] !== $uid) { http_response_code(403); exit('Access denied.'); }

$totalItems  = (int)$asmt['total_items'];
$teacherName = 'MR./MS. ' . display_name($asmt);

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
$sfRaw = $sfStmt->fetchAll();
$sf = [];
foreach ($sfRaw as $r) { $sf[(int)$r['section_id']][(int)$r['score']] = (int)$r['frequency']; }

// Item correct counts
$iccStmt = $pdo->prepare("SELECT section_id, item_no, correct_count FROM item_correct_counts WHERE assessment_id=? ORDER BY item_no");
$iccStmt->execute([$assessment_id]);
$iccRaw = $iccStmt->fetchAll();
$icc = [];
foreach ($iccRaw as $r) { $icc[(int)$r['section_id']][(int)$r['item_no']] = (int)$r['correct_count']; }

// Per-section CASES and Σf(x)
$sectionCases = [];
$sectionFx    = [];
foreach ($secIds as $sid) {
    $c = 0; $fx = 0;
    if (isset($sf[$sid])) {
        foreach ($sf[$sid] as $score => $freq) {
            $c  += $freq;
            $fx += $freq * $score;
        }
    }
    $sectionCases[$sid] = $c;
    $sectionFx[$sid]    = $fx;
}

// ---- Build Spreadsheet ----
$ss  = new Spreadsheet();

// ============================================================
// HELPER: DepEd header block
// ============================================================
function writeDepEdHeader($sheet, int $lastCol): int
{
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastCol);
    $headers = [
        REPUBLIC, DEPED_HEADER, REGION, DIVISION, SCHOOL_NAME, SCHOOL_ADDRESS,
    ];
    $row = 1;
    foreach ($headers as $text) {
        $sheet->setCellValue("A{$row}", $text);
        $sheet->mergeCells("A{$row}:{$colLetter}{$row}");
        $sheet->getStyle("A{$row}")->getAlignment()
              ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$row}")->getFont()->setBold($row <= 2 || $row === 5);
        $sheet->getStyle("A{$row}")->getFont()->setSize($row === 5 ? 12 : 10);
        $row++;
    }
    return $row; // returns next empty row
}

// ============================================================
// SHEET 1: MPS (Frequency of Scores)
// ============================================================
$sheet1 = $ss->getActiveSheet();
$sheet1->setTitle('MPS');

$numSecs   = count($sections);
$dataLastCol = 1 + $numSecs * 2 + 2;   // Score + (f,fx)*secs + Total f + Total fx

$nextRow = writeDepEdHeader($sheet1, $dataLastCol);
$nextRow++; // blank

// Metadata rows
$sheet1->setCellValue("A{$nextRow}", 'Subject:');
$sheet1->setCellValue("B{$nextRow}", strtoupper($asmt['subject_name']) . ' (Grade ' . $asmt['grade_level'] . ')');
$nextRow++;
$sheet1->setCellValue("A{$nextRow}", 'Test Title:');
$sheet1->setCellValue("B{$nextRow}", strtoupper($asmt['title']));
$nextRow++;
$sheet1->setCellValue("A{$nextRow}", 'Teacher:');
$sheet1->setCellValue("B{$nextRow}", $teacherName);
$nextRow++;
$sheet1->setCellValue("A{$nextRow}", 'Term:');
$sheet1->setCellValue("B{$nextRow}", 'Term ' . $asmt['term_no'] . ' — ' . $asmt['term_name']);
$nextRow++;
$nextRow++;

// Table header row 1
$headerRow = $nextRow;
$col = 1;
$sheet1->setCellValueByColumnAndRow($col++, $headerRow, 'Score');
foreach ($sections as $sec) {
    $sheet1->setCellValueByColumnAndRow($col, $headerRow, $sec['name']);
    $sheet1->mergeCellsByColumnAndRow($col, $headerRow, $col+1, $headerRow);
    $col += 2;
}
$sheet1->setCellValueByColumnAndRow($col, $headerRow, 'TOTAL');
$sheet1->mergeCellsByColumnAndRow($col, $headerRow, $col+1, $headerRow);

// Table header row 2
$nextRow++;
$col = 1;
$sheet1->setCellValueByColumnAndRow($col++, $nextRow, '');  // Score col spans 2 rows
foreach ($sections as $sec) {
    $sheet1->setCellValueByColumnAndRow($col++, $nextRow, 'f');
    $sheet1->setCellValueByColumnAndRow($col++, $nextRow, 'f(x)');
}
$sheet1->setCellValueByColumnAndRow($col++, $nextRow, 'f');
$sheet1->setCellValueByColumnAndRow($col,   $nextRow, 'f(x)');

// Merge Score header across 2 rows
$sheet1->mergeCellsByColumnAndRow(1, $headerRow, 1, $nextRow);

// Style header rows
$headerRange = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(1)
    . $headerRow . ':'
    . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($dataLastCol)
    . $nextRow;
$sheet1->getStyle($headerRange)->getFont()->setBold(true);
$sheet1->getStyle($headerRange)->getFill()
       ->setFillType(Fill::FILL_SOLID)
       ->getStartColor()->setARGB('FFD6E4F0');
$sheet1->getStyle($headerRange)->getAlignment()
       ->setHorizontal(Alignment::HORIZONTAL_CENTER)
       ->setVertical(Alignment::VERTICAL_CENTER);

$nextRow++;
$dataStartRow = $nextRow;

// Data rows (scores totalItems down to 0)
$totals   = array_fill(0, count($sections), ['f'=>0,'fx'=>0]);
$grandF   = 0; $grandFx = 0;

for ($score = $totalItems; $score >= 0; $score--) {
    $col = 1;
    $sheet1->setCellValueByColumnAndRow($col++, $nextRow, $score);
    $rowTotalF = 0; $rowTotalFx = 0;
    foreach ($sections as $i => $sec) {
        $f  = $sf[$sec['id']][$score] ?? 0;
        $fx = $f * $score;
        $sheet1->setCellValueByColumnAndRow($col++, $nextRow, $f  ?: '');
        $sheet1->setCellValueByColumnAndRow($col++, $nextRow, $fx ?: '');
        $totals[$i]['f']  += $f;
        $totals[$i]['fx'] += $fx;
        $rowTotalF  += $f;
        $rowTotalFx += $fx;
    }
    $sheet1->setCellValueByColumnAndRow($col++, $nextRow, $rowTotalF  ?: '');
    $sheet1->setCellValueByColumnAndRow($col,   $nextRow, $rowTotalFx ?: '');
    $grandF  += $rowTotalF;
    $grandFx += $rowTotalFx;
    $nextRow++;
}

// Summary rows
$summaries = [];

// CASES — write Σf (cases) AND Σf(x) side by side
$col = 1;
$sheet1->setCellValueByColumnAndRow($col++, $nextRow, 'CASES');
foreach ($sections as $i => $sec) {
    $sheet1->setCellValueByColumnAndRow($col++, $nextRow, $totals[$i]['f']);
    $sheet1->setCellValueByColumnAndRow($col++, $nextRow, $totals[$i]['fx']);
}
$sheet1->setCellValueByColumnAndRow($col++, $nextRow, $grandF);
$sheet1->setCellValueByColumnAndRow($col++, $nextRow, $grandFx);
$nextRow++;

// MEAN
$col = 1;
$sheet1->setCellValueByColumnAndRow($col++, $nextRow, 'MEAN');
foreach ($sections as $i => $sec) {
    $cases = $totals[$i]['f'];
    $mean  = $cases > 0 ? round($totals[$i]['fx'] / $cases, 2) : '—';
    $sheet1->setCellValueByColumnAndRow($col++, $nextRow, $mean);
    $col++;
}
$grandMean = $grandF > 0 ? round($grandFx / $grandF, 2) : '—';
$sheet1->setCellValueByColumnAndRow($col++, $nextRow, $grandMean);
$col++;
$nextRow++;

// MPS
$col = 1;
$sheet1->setCellValueByColumnAndRow($col++, $nextRow, 'MPS (%)');
foreach ($sections as $i => $sec) {
    $cases = $totals[$i]['f'];
    $mean  = $cases > 0 ? $totals[$i]['fx'] / $cases : 0;
    $mps   = ($cases > 0 && $totalItems > 0) ? round($mean / $totalItems * 100, 2) : '—';
    $sheet1->setCellValueByColumnAndRow($col++, $nextRow, $mps);
    $col++;
}
$grandMps = ($grandF > 0 && $totalItems > 0) ? round(($grandFx / $grandF) / $totalItems * 100, 2) : '—';
$sheet1->setCellValueByColumnAndRow($col++, $nextRow, $grandMps);
$nextRow++;

// Mastery Bands
foreach (MASTERY_BANDS as $bk => $band) {
    $col = 1;
    $sheet1->setCellValueByColumnAndRow($col++, $nextRow, $bk . ' — ' . $band['label']);
    foreach ($sections as $i => $sec) {
        $cases = $totals[$i]['f'];
        $cnt = 0;
        if (isset($sf[$sec['id']])) {
            foreach ($sf[$sec['id']] as $score => $freq) {
                $pct2 = $totalItems > 0 ? $score / $totalItems * 100 : 0;
                if ($pct2 >= $band['min'] && $pct2 <= $band['max']) $cnt += $freq;
            }
        }
        $prop = $cases > 0 ? round($cnt / $cases * 100, 1) . '%' : '—';
        $sheet1->setCellValueByColumnAndRow($col++, $nextRow, $cnt);
        $sheet1->setCellValueByColumnAndRow($col++, $nextRow, $prop);
    }
    $nextRow++;
}

// NPWRM
$col = 1;
$sheet1->setCellValueByColumnAndRow($col++, $nextRow, 'NPWRM (≥' . MASTERY_THRESHOLD . '%)');
foreach ($sections as $i => $sec) {
    $npwrm = 0;
    if (isset($sf[$sec['id']])) {
        foreach ($sf[$sec['id']] as $score => $freq) {
            $pct3 = $totalItems > 0 ? $score / $totalItems * 100 : 0;
            if ($pct3 >= MASTERY_THRESHOLD) $npwrm += $freq;
        }
    }
    $sheet1->setCellValueByColumnAndRow($col++, $nextRow, $npwrm);
    $col++;
}

// Auto-size columns
foreach (range(1, $dataLastCol) as $ci) {
    $sheet1->getColumnDimensionByColumn($ci)->setAutoSize(true);
}

// ============================================================
// SHEET 2: ITEM ANALYSIS
// ============================================================
$sheet2 = $ss->createSheet();
$sheet2->setTitle('ITEM ANALYSIS');

$itemLastCol = 1 + $numSecs * 2 + 2;
$nextRow2    = writeDepEdHeader($sheet2, $itemLastCol);
$nextRow2++;
$sheet2->setCellValue("A{$nextRow2}", 'Subject:');
$sheet2->setCellValue("B{$nextRow2}", strtoupper($asmt['subject_name']) . ' (Grade ' . $asmt['grade_level'] . ')');
$nextRow2++;
$sheet2->setCellValue("A{$nextRow2}", 'Test Title:');
$sheet2->setCellValue("B{$nextRow2}", strtoupper($asmt['title']));
$nextRow2++;
$sheet2->setCellValue("A{$nextRow2}", 'Teacher:');
$sheet2->setCellValue("B{$nextRow2}", $teacherName);
$nextRow2+=2;

// Header
$hr1 = $nextRow2;
$col = 1;
$sheet2->setCellValueByColumnAndRow($col++, $hr1, 'Item No.');
foreach ($sections as $sec) {
    $sheet2->setCellValueByColumnAndRow($col, $hr1, $sec['name']);
    $sheet2->mergeCellsByColumnAndRow($col, $hr1, $col+1, $hr1);
    $col += 2;
}
$sheet2->setCellValueByColumnAndRow($col, $hr1, 'TOTAL');
$sheet2->mergeCellsByColumnAndRow($col, $hr1, $col+1, $hr1);

$nextRow2++;
$col = 1;
$sheet2->setCellValueByColumnAndRow($col++, $nextRow2, '');
$sheet2->mergeCellsByColumnAndRow(1, $hr1, 1, $nextRow2);
foreach ($sections as $sec) {
    $sheet2->setCellValueByColumnAndRow($col++, $nextRow2, 'f');
    $sheet2->setCellValueByColumnAndRow($col++, $nextRow2, '%');
}
$sheet2->setCellValueByColumnAndRow($col++, $nextRow2, 'f');
$sheet2->setCellValueByColumnAndRow($col,   $nextRow2, '%');
$nextRow2++;

// CASES row — denominator for % formulas; row-absolute in Excel formulas below
$casesRow = $nextRow2;
$col = 1;
$sheet2->setCellValueByColumnAndRow($col++, $casesRow, 'CASES');
$grandCases = 0;
foreach ($sections as $sec) {
    $cases = $sectionCases[$sec['id']] ?? 0;
    $sheet2->setCellValueByColumnAndRow($col++, $casesRow, $cases ?: '');
    $col++;  // % column blank for CASES row
    $grandCases += $cases;
}
$sheet2->setCellValueByColumnAndRow($col, $casesRow, $grandCases ?: '');
$nextRow2++;

// Item rows — % cells use live Excel formula =(fCell)/(fCell)$casesRow*100
for ($item = 1; $item <= $totalItems; $item++) {
    $col = 1;
    $sheet2->setCellValueByColumnAndRow($col++, $nextRow2, $item);
    $totF = 0; $totCases = 0;
    foreach ($sections as $idx => $sec) {
        $f       = $icc[$sec['id']][$item] ?? 0;
        $cases   = $sectionCases[$sec['id']] ?? 0;
        $fColIdx = 2 + $idx * 2;
        $fColLtr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($fColIdx);
        $sheet2->setCellValueByColumnAndRow($col++, $nextRow2, $f ?: '');
        if ($cases > 0) {
            $sheet2->setCellValueByColumnAndRow($col, $nextRow2, "={$fColLtr}{$nextRow2}/{$fColLtr}\${$casesRow}*100");
        } else {
            $sheet2->setCellValueByColumnAndRow($col, $nextRow2, '—');
        }
        $col++;
        $totF    += $f;
        $totCases += $cases;
    }
    $totFColIdx = 2 + count($sections) * 2;
    $totFColLtr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totFColIdx);
    $sheet2->setCellValueByColumnAndRow($col++, $nextRow2, $totF ?: '');
    if ($totCases > 0) {
        $sheet2->setCellValueByColumnAndRow($col, $nextRow2, "={$totFColLtr}{$nextRow2}/{$totFColLtr}\${$casesRow}*100");
    } else {
        $sheet2->setCellValueByColumnAndRow($col, $nextRow2, '—');
    }
    $nextRow2++;
}

// TOTAL row — sum of item correct counts per section, cross-checked against MPS Σf(x)
$col = 1;
$sheet2->setCellValueByColumnAndRow($col++, $nextRow2, 'TOTAL');
$grandItemTotal = 0;
foreach ($sections as $idx => $sec) {
    $itemTotal = 0;
    if (isset($icc[$sec['id']])) {
        foreach ($icc[$sec['id']] as $cnt) $itemTotal += $cnt;
    }
    $grandItemTotal += $itemTotal;
    $fx = $sectionFx[$sec['id']] ?? 0;
    $sheet2->setCellValueByColumnAndRow($col, $nextRow2, $itemTotal ?: '');
    if ($fx > 0 && $itemTotal !== $fx) {
        $fColLtr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $sheet2->getStyle("{$fColLtr}{$nextRow2}")->getFill()
               ->setFillType(Fill::FILL_SOLID)
               ->getStartColor()->setARGB('FFFF9999');
    }
    $col++;
    $col++;  // skip % column in TOTAL row
}
$sheet2->setCellValueByColumnAndRow($col, $nextRow2, $grandItemTotal ?: '');
$nextRow2++;

foreach (range(1, $itemLastCol) as $ci) {
    $sheet2->getColumnDimensionByColumn($ci)->setAutoSize(true);
}

// ---- Output ----
$filename = preg_replace('/[^a-z0-9_-]/i', '_', $asmt['title']) . '_MPS_ItemAnalysis.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
