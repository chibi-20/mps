<?php
// Lightweight ZipArchive-based .xlsx writer — no external dependencies (no Composer/vendor needed).
// Shared by every Excel export in this app.

class MiniXlsx
{
    // Cell style indices — pass to cell()
    const S_DEFAULT   = 0;   // plain
    const S_BOLD      = 1;   // bold
    const S_CTR       = 2;   // centered
    const S_BOLD_CTR  = 3;   // bold + centered
    const S_HDR       = 4;   // table header: bold, light-blue bg, thin border, centered
    const S_TITLE     = 5;   // bold-12, centered (section titles / school name)
    const S_GREEN     = 6;   // green background  (≥75%)
    const S_YELLOW    = 7;   // yellow background (50–74%)
    const S_RED       = 8;   // red background    (<50%)
    const S_SUBHDR    = 9;   // bold, gold bg, centered (sub-section headers)
    const S_SMALL_CTR = 10;  // size-10, centered (DepEd header lines)

    private array $sheets    = [];
    private array $usedNames = [];

    // ---- Public API ----

    public function addSheet(string $name): int
    {
        $clean = $this->dedupName($this->sanitize($name));
        $idx   = count($this->sheets);
        $this->sheets[] = [
            'name'      => $clean,
            'cells'     => [],
            'merges'    => [],
            'colWidths' => [],
            'maxRow'    => 0,
            'maxCol'    => 0,
        ];
        return $idx;
    }

    public function cell(int $si, int $row, int $col, mixed $val, int $style = 0): void
    {
        $s = &$this->sheets[$si];
        $s['cells'][$row][$col] = ['v' => $val, 's' => $style];
        if ($row > $s['maxRow']) $s['maxRow'] = $row;
        if ($col > $s['maxCol']) $s['maxCol'] = $col;
    }

    public function merge(int $si, int $r1, int $c1, int $r2, int $c2): void
    {
        $this->sheets[$si]['merges'][] = [$r1, $c1, $r2, $c2];
    }

    public function colWidth(int $si, int $col, float $w): void
    {
        $this->sheets[$si]['colWidths'][$col] = $w;
    }

    public function output(string $filename): never
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mxl_');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            exit('Could not create export archive.');
        }
        $zip->addFromString('[Content_Types].xml',        $this->buildContentTypes());
        $zip->addFromString('_rels/.rels',                $this->buildTopRels());
        $zip->addFromString('xl/workbook.xml',            $this->buildWorkbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRels());
        $zip->addFromString('xl/styles.xml',              $this->buildStyles());
        foreach ($this->sheets as $i => $sh) {
            $zip->addFromString("xl/worksheets/sheet{$i}.xml", $this->buildSheet($sh));
        }
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    // ---- Private helpers ----

    private function sanitize(string $n): string
    {
        $n = preg_replace('/[\/\\\\?*\[\]:]/', '_', $n);
        $n = trim($n);
        return $n === '' ? 'Sheet' : mb_substr($n, 0, 31);
    }

    private function dedupName(string $name): string
    {
        if (!in_array($name, $this->usedNames, true)) {
            $this->usedNames[] = $name;
            return $name;
        }
        for ($i = 2; $i < 9999; $i++) {
            $suf = " ({$i})";
            $c   = mb_substr($name, 0, 31 - strlen($suf)) . $suf;
            if (!in_array($c, $this->usedNames, true)) {
                $this->usedNames[] = $c;
                return $c;
            }
        }
        return $name . '_' . substr(uniqid(), -4);
    }

    private static function col2l(int $col): string
    {
        $r = '';
        while ($col > 0) {
            $col--;
            $r   = chr(65 + $col % 26) . $r;
            $col = intdiv($col, 26);
        }
        return $r;
    }

    private static function cellRef(int $row, int $col): string
    {
        return self::col2l($col) . $row;
    }

    private static function rangeRef(int $r1, int $c1, int $r2, int $c2): string
    {
        return self::cellRef($r1, $c1) . ':' . self::cellRef($r2, $c2);
    }

    private static function xe(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ---- XML part builders ----

    private function buildContentTypes(): string
    {
        $wsNs  = 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml';
        $wbNs  = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
        $styNs = 'application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml';

        $overrides = "<Override PartName=\"/xl/workbook.xml\" ContentType=\"{$wbNs}\"/>"
                   . "<Override PartName=\"/xl/styles.xml\"   ContentType=\"{$styNs}\"/>";
        foreach ($this->sheets as $i => $_) {
            $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$i}.xml\" ContentType=\"{$wsNs}\"/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
             . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
             . '<Default Extension="xml"  ContentType="application/xml"/>'
             . $overrides
             . '</Types>';
    }

    private function buildTopRels(): string
    {
        $t = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . "<Relationship Id=\"rId1\" Type=\"{$t}\" Target=\"xl/workbook.xml\"/>"
             . '</Relationships>';
    }

    private function buildWorkbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $sh) {
            $n       = self::xe($sh['name']);
            $sheetId = $i + 1;
            $rId     = $i + 1;
            $sheets .= "<sheet name=\"{$n}\" sheetId=\"{$sheetId}\" r:id=\"rId{$rId}\"/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
             . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
             . '<sheets>' . $sheets . '</sheets>'
             . '</workbook>';
    }

    private function buildWorkbookRels(): string
    {
        $wsT  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet';
        $styT = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles';
        $rels = '';
        foreach ($this->sheets as $i => $_) {
            $rels .= "<Relationship Id=\"rId" . ($i+1) . "\" Type=\"{$wsT}\" Target=\"worksheets/sheet{$i}.xml\"/>";
        }
        $styId = count($this->sheets) + 1;
        $rels .= "<Relationship Id=\"rId{$styId}\" Type=\"{$styT}\" Target=\"styles.xml\"/>";
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . $rels
             . '</Relationships>';
    }

    private function buildStyles(): string
    {
        // Fonts: 0=normal-11, 1=bold-11, 2=bold-12, 3=normal-10
        $fonts = '<font><sz val="11"/><name val="Calibri"/></font>'
               . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
               . '<font><b/><sz val="12"/><name val="Calibri"/></font>'
               . '<font><sz val="10"/><name val="Calibri"/></font>';

        // Fills: 0=none, 1=gray125 (required), 2=light-blue, 3=green, 4=yellow, 5=red, 6=gold
        $fills = '<fill><patternFill patternType="none"/></fill>'
               . '<fill><patternFill patternType="gray125"/></fill>'
               . '<fill><patternFill patternType="solid"><fgColor rgb="FFD6E4F0"/></patternFill></fill>'
               . '<fill><patternFill patternType="solid"><fgColor rgb="FF90EE90"/></patternFill></fill>'
               . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFD966"/></patternFill></fill>'
               . '<fill><patternFill patternType="solid"><fgColor rgb="FFFF9999"/></patternFill></fill>'
               . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFC107"/></patternFill></fill>';

        $thin    = '<left style="thin"><color auto="1"/></left>'
                 . '<right style="thin"><color auto="1"/></right>'
                 . '<top style="thin"><color auto="1"/></top>'
                 . '<bottom style="thin"><color auto="1"/></bottom>'
                 . '<diagonal/>';
        $borders = '<border><left/><right/><top/><bottom/><diagonal/></border>'
                 . "<border>{$thin}</border>";

        // [fontId, fillId, borderId, hAlign, vAlign, wrapText]  — indices match S_* constants
        $defs = [
            [0, 0, 0, '',       '',       false],  // 0 S_DEFAULT
            [1, 0, 0, '',       '',       false],  // 1 S_BOLD
            [0, 0, 0, 'center', '',       false],  // 2 S_CTR
            [1, 0, 0, 'center', '',       false],  // 3 S_BOLD_CTR
            [1, 2, 1, 'center', 'center', true ],  // 4 S_HDR
            [2, 0, 0, 'center', '',       false],  // 5 S_TITLE
            [0, 3, 0, '',       '',       false],  // 6 S_GREEN
            [0, 4, 0, '',       '',       false],  // 7 S_YELLOW
            [0, 5, 0, '',       '',       false],  // 8 S_RED
            [1, 6, 0, 'center', '',       false],  // 9 S_SUBHDR
            [3, 0, 0, 'center', '',       false],  // 10 S_SMALL_CTR
        ];
        $xfs = '';
        foreach ($defs as $d) {
            [$fId, $fillId, $bId, $hA, $vA, $wrap] = $d;
            $applyA = ($hA || $vA || $wrap) ? '1' : '0';
            $xfs   .= "<xf numFmtId=\"0\" fontId=\"{$fId}\" fillId=\"{$fillId}\" borderId=\"{$bId}\" xfId=\"0\""
                    . " applyFont=\"1\" applyFill=\"1\" applyBorder=\"1\" applyAlignment=\"{$applyA}\">";
            if ($hA || $vA || $wrap) {
                $al  = $hA   ? " horizontal=\"{$hA}\""  : '';
                $al .= $vA   ? " vertical=\"{$vA}\""    : '';
                $al .= $wrap ? ' wrapText="1"'           : '';
                $xfs .= "<alignment{$al}/>";
            }
            $xfs .= '</xf>';
        }
        $n = count($defs);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . "<fonts count=\"4\">{$fonts}</fonts>"
             . "<fills count=\"7\">{$fills}</fills>"
             . "<borders count=\"2\">{$borders}</borders>"
             . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
             . "<cellXfs count=\"{$n}\">{$xfs}</cellXfs>"
             . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
             . '</styleSheet>';
    }

    private function buildSheet(array $sh): string
    {
        $maxRow = $sh['maxRow'];
        $maxCol = $sh['maxCol'];
        $dim    = $maxRow > 0 ? ('A1:' . self::col2l($maxCol) . $maxRow) : 'A1';

        $colsXml = '';
        if (!empty($sh['colWidths'])) {
            ksort($sh['colWidths']);
            $colsXml = '<cols>';
            foreach ($sh['colWidths'] as $c => $w) {
                $colsXml .= "<col min=\"{$c}\" max=\"{$c}\" width=\"{$w}\" customWidth=\"1\"/>";
            }
            $colsXml .= '</cols>';
        }

        $rowsXml = '';
        ksort($sh['cells']);
        foreach ($sh['cells'] as $row => $cols) {
            $cellsXml = '';
            ksort($cols);
            foreach ($cols as $col => $cell) {
                $r   = self::cellRef($row, $col);
                $val = $cell['v'];
                $s   = $cell['s'];
                $sA  = $s ? " s=\"{$s}\"" : '';

                if ($val === null || $val === '') {
                    if ($s) $cellsXml .= "<c r=\"{$r}\"{$sA}/>";
                } elseif (is_int($val) || is_float($val)) {
                    $cellsXml .= "<c r=\"{$r}\"{$sA}><v>{$val}</v></c>";
                } else {
                    $esc       = self::xe((string)$val);
                    $cellsXml .= "<c r=\"{$r}\" t=\"inlineStr\"{$sA}><is><t>{$esc}</t></is></c>";
                }
            }
            if ($cellsXml) $rowsXml .= "<row r=\"{$row}\">{$cellsXml}</row>";
        }

        $mergesXml = '';
        if (!empty($sh['merges'])) {
            $cnt       = count($sh['merges']);
            $mergesXml = "<mergeCells count=\"{$cnt}\">";
            foreach ($sh['merges'] as $m) {
                $mergesXml .= '<mergeCell ref="' . self::rangeRef(...$m) . '"/>';
            }
            $mergesXml .= '</mergeCells>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . "<dimension ref=\"{$dim}\"/>"
             . $colsXml
             . '<sheetData>' . $rowsXml . '</sheetData>'
             . $mergesXml
             . '</worksheet>';
    }
}

// ---- Shared helpers ----

function mpsStyle(float $pct): int
{
    return $pct >= 75 ? MiniXlsx::S_GREEN : ($pct >= 50 ? MiniXlsx::S_YELLOW : MiniXlsx::S_RED);
}

function xlDepEdHeader(MiniXlsx $xl, int $si, int $numCols): int
{
    $lines = [REPUBLIC, DEPED_HEADER, REGION, DIVISION, SCHOOL_NAME, SCHOOL_ADDRESS];
    $row   = 1;
    foreach ($lines as $i => $text) {
        $style = ($i === 4) ? MiniXlsx::S_TITLE : MiniXlsx::S_SMALL_CTR;
        $xl->cell($si, $row, 1, $text, $style);
        if ($numCols > 1) $xl->merge($si, $row, 1, $row, $numCols);
        $row++;
    }
    return $row; // next available row
}
