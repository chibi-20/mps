<?php
/**
 * Build a personalized MPS scanner sheet, with one page per enrolled student.
 * The Code 128 symbol width data is from ZXing's Code128Reader (Apache-2.0).
 */
function generate_student_answer_sheets_pdf(array $assessment, array $students): string
{
    $patterns = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];
    $scale = 612 / 2550;
    $templateHeight = 3300;
    $toPdfY = static fn(float $y): float => ($templateHeight - $y) * $scale;
    $toPdfRectY = static fn(float $y, float $height): float => ($templateHeight - $y - $height) * $scale;
    $pdfText = static function (string $value): string {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        if ($encoded === false) $encoded = $value;
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $encoded);
    };
    $text = static function (float $x, float $y, string $value, float $size, bool $bold = false) use ($pdfText, $scale, $toPdfY): string {
        $font = $bold ? 'F2' : 'F1';
        return sprintf("BT /%s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n", $font, $size * $scale, $x * $scale, $toPdfY($y), $pdfText($value));
    };
    $centerText = static function (float $centerX, float $y, string $value, float $size, bool $bold = false) use ($text, $pdfText): string {
        $encoded = $pdfText($value);
        return $text($centerX - strlen($encoded) * $size * 0.255, $y, $value, $size, $bold);
    };
    $fitText = static function (string $value, int $maxCharacters) use ($pdfText): string {
        $encoded = $pdfText($value);
        if (strlen($encoded) <= $maxCharacters) return $value;
        return iconv('Windows-1252', 'UTF-8//IGNORE', substr($encoded, 0, $maxCharacters - 3)) . '...';
    };
    $rectangle = static function (float $x, float $y, float $width, float $height, string $paint = 'S') use ($scale, $toPdfRectY): string {
        return sprintf("%.2f %.2f %.2f %.2f re %s\n", $x * $scale, $toPdfRectY($y, $height), $width * $scale, $height * $scale, $paint);
    };
    $circle = static function (float $cx, float $cy, float $radius) use ($scale, $toPdfY): string {
        $x = $cx * $scale;
        $y = $toPdfY($cy);
        $r = $radius * $scale;
        $k = $r * 0.55228475;
        return sprintf("%.2f %.2f m %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c S\n",
            $x, $y + $r, $x + $k, $y + $r, $x + $r, $y + $k, $x + $r, $y,
            $x + $r, $y - $k, $x + $k, $y - $r, $x, $y - $r,
            $x - $k, $y - $r, $x - $r, $y - $k, $x - $r, $y,
            $x - $r, $y + $k, $x - $k, $y + $r, $x, $y + $r);
    };
    $barcode = static function (string $value, float $x, float $y, float $regionWidth, float $barHeight, float $maxModuleWidth = 4.0) use ($patterns, $scale, $toPdfRectY): string {
        $characters = str_split($value);
        $codes = [104]; // Start in Code Set B; switch to C for long digit runs.
        $set = 'B';
        for ($index = 0, $length = count($characters); $index < $length;) {
            $remaining = implode('', array_slice($characters, $index));
            if ($set === 'B' && preg_match('/^\d{4,}/', $remaining, $match)) {
                $codes[] = 99; // Code Set C packs two digits into each symbol.
                $set = 'C';
                continue;
            }
            if ($set === 'C') {
                if (preg_match('/^\d{2}/', $remaining, $match)) {
                    $codes[] = (int)$match[0];
                    $index += 2;
                    continue;
                }
                $codes[] = 100; // Return to Code Set B for separators and odd digits.
                $set = 'B';
                continue;
            }
            $code = ord($characters[$index]);
            if ($code < 32 || $code > 126) throw new InvalidArgumentException('Barcode data must use printable ASCII.');
            $codes[] = $code - 32;
            $index++;
        }
        $checksum = 104;
        for ($i = 1; $i < count($codes); $i++) $checksum += $codes[$i] * $i;
        $codes[] = $checksum % 103;
        $codes[] = 106; // Stop.
        $modules = 0;
        foreach ($codes as $code) foreach (str_split($patterns[$code]) as $width) $modules += (int)$width;
        $module = min($maxModuleWidth, $regionWidth / ($modules + 20));
        $cursor = $x + ($regionWidth - $modules * $module) / 2;
        $bottom = $toPdfRectY($y, $barHeight);
        $height = $barHeight * $scale;
        $commands = '';
        foreach ($codes as $code) {
            $isBar = true;
            foreach (str_split($patterns[$code]) as $width) {
                $run = (int)$width * $module;
                if ($isBar) $commands .= sprintf("%.2f %.2f %.2f %.2f re f\n", $cursor * $scale, $bottom, $run * $scale, $height);
                $cursor += $run;
                $isBar = !$isBar;
            }
        }
        return $commands;
    };

    // Match the four-subject sheet's fixed barcode and OMR geometry. The API
    // supplies each student's subjects in the order assigned to their section.
    $pageCount = count($students);
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
    $pageIds = [];
    $fontRegularId = 3 + $pageCount * 2;
    $fontBoldId = $fontRegularId + 1;

    foreach ($students as $index => $student) {
        $commands = "0 G 0 g\n0.7 w\n";
        // Keep the frame clear of the four corner reference marks. The top
        // center mark helps with print alignment but is not used for scanning.
        $commands .= $rectangle(70, 70, 2410, 3220);
        foreach ([[100, 108], [2404, 108], [100, 3237], [2404, 3237], [1252, 108]] as [$x, $y]) {
            $commands .= $rectangle($x, $y, 46, 46, 'f');
        }

        $commands .= $text(180, 125, 'STUDENT ANSWER SHEET', 48, true);
        // Three equal-width header fields, without individual or group boxes.
        $commands .= $text(180, 165, 'NAME', 22, true);
        $commands .= $text(924, 165, 'GRADE & SECTION', 22, true);
        $commands .= $text(180, 220, $fitText((string)$student['name'], 45), 28);
        $gradeSection = $fitText('Grade ' . $student['grade_level'] . ' - ' . $student['section_name'], 33);
        $commands .= $text(924, 220, $gradeSection, 24);
        $commands .= $barcode((string)$assessment['id'] . '_' . (string)$student['lrn'], 1668, 155, 704, 80, 4.0);

        foreach (array_slice(($student['subjects'] ?? []), 0, 4) as $column => $subject) {
            $columnLeft = 120 + $column * 580;
            $bubbleX = 235 + $column * 580;
            $subjectId = (int)$subject['subject_id'];
            $commands .= "0.7 w\n" . $rectangle($columnLeft, 250, 555, 3020);
            // Filled corner marks give the scanner four local control points
            // for calibrating this subject's bubble grid independently.
            foreach ([
                [$columnLeft, 250], [$columnLeft + 555, 250],
                [$columnLeft + 555, 3270], [$columnLeft, 3270],
            ] as [$cornerX, $cornerY]) {
                $commands .= $rectangle($cornerX - 8, $cornerY - 8, 16, 16, 'f');
            }
            $commands .= $centerText($columnLeft + 277.5, 285, strtoupper((string)$subject['subject_name']), 28, true);
            // The student LRN is already carried by the main barcode. Keep
            // each subject barcode short enough to scan from a phone photo.
            $commands .= $barcode($subjectId . '_' . (string)$student['section_id'], $columnLeft + 15, 300, 525, 40);
            $commands .= "0.35 w\n";
            for ($item = 1; $item <= 50; $item++) {
                $rowY = 380 + ($item - 1) * 58;
                $commands .= $text($columnLeft + 20, $rowY + 12, str_pad((string)$item, 2, '0', STR_PAD_LEFT), 36, true);
                foreach (['A', 'B', 'C', 'D'] as $choiceIndex => $choice) {
                    $cx = $bubbleX + $choiceIndex * 105;
                    $commands .= $circle($cx, $rowY, 26);
                    $commands .= $centerText($cx, $rowY + 7, $choice, 21);
                }
            }
        }
        $contentId = 3 + $index * 2;
        $pageId = $contentId + 1;
        $pageIds[] = $pageId;
        $objects[$contentId] = '<< /Length ' . strlen($commands) . ">>\nstream\n" . $commands . "endstream";
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 ' . $fontRegularId . ' 0 R /F2 ' . $fontBoldId . ' 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
    }

    $kids = implode(' ', array_map(static fn(int $objectId): string => $objectId . ' 0 R', $pageIds));
    $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . $pageCount . ' >>';
    $objects[$fontRegularId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[$fontBoldId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    $lastId = max(array_keys($objects));
    for ($objectId = 1; $objectId <= $lastId; $objectId++) {
        $offsets[$objectId] = strlen($pdf);
        $pdf .= $objectId . " 0 obj\n" . ($objects[$objectId] ?? 'null') . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($lastId + 1) . "\n0000000000 65535 f \n";
    for ($objectId = 1; $objectId <= $lastId; $objectId++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$objectId]);
    return $pdf . "trailer\n<< /Size " . ($lastId + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
}
