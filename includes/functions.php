<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ============================================================
// Display helpers
// ============================================================

/**
 * URL for a static asset (script.js, styles.css) with a cache-busting
 * ?v= query string derived from the file's mtime. Without this, a CDN or
 * browser holding a long max-age on these files (e.g. Hostinger's CDN
 * caches script.js for 7 days) keeps serving stale JS/CSS after a deploy
 * until that cache naturally expires or is manually purged -- appending
 * the mtime makes every content change look like a brand new URL, so it's
 * always fetched fresh.
 */
function asset_url(string $relativePath): string
{
    $file = __DIR__ . '/../' . $relativePath;
    $v    = is_file($file) ? filemtime($file) : time();
    return BASE_URL . $relativePath . '?v=' . $v;
}

/**
 * Build display name: "CANTURIA, Jay Mar V."
 */
function display_name(array $user): string
{
    $last  = mb_strtoupper(trim($user['last_name']));
    $first = ucwords(mb_strtolower(trim($user['first_name'])));
    $mid   = trim($user['middle_name'] ?? '');
    $mi    = $mid !== '' ? ' ' . mb_strtoupper(mb_substr($mid, 0, 1)) . '.' : '';
    return "{$last}, {$first}{$mi}";
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Generate a readable temporary password.
 * Excludes ambiguous characters: 0, O, 1, l, I.
 */
function generate_temp_password(int $length = 10): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $max   = strlen($chars) - 1;
    $out   = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

function termLabel(array $term): string
{
    $no    = $term['term_no'] ?? '?';
    $start = $term['start_date'] ?? null;
    $end   = $term['end_date']   ?? null;
    if ($start && $end) {
        try {
            $s   = new DateTime($start);
            $e   = new DateTime($end);
            $fmt = $s->format('Y') !== $e->format('Y')
                ? $s->format('M j, Y') . ' – ' . $e->format('M j, Y')
                : $s->format('M j')    . ' – ' . $e->format('M j, Y');
            return "Term {$no} ({$fmt})";
        } catch (Exception $ex) { /* fall through */ }
    }
    return "Term {$no}";
}

// ============================================================
// Mastery band helpers
// ============================================================

/**
 * Return the band key for a given percent score.
 */
function get_mastery_band(float $pct): string
{
    foreach (MASTERY_BANDS as $key => $band) {
        if ($pct >= $band['min'] && $pct <= $band['max']) {
            return $key;
        }
    }
    return 'ANM';
}

// ============================================================
// Core MPS computation  (authoritative — used by PHP only)
// ============================================================

/**
 * Compute MPS summary for one (assessment, section) pair.
 *
 * Returns:
 *   cases, mean, mps, npwrm,
 *   bands => [key => count],
 *   band_pct => [key => proportion (0-100)]
 */
function compute_mps_section(int $assessment_id, int $section_id, int $total_items): array
{
    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT score, frequency FROM score_frequencies
         WHERE assessment_id = ? AND section_id = ? AND frequency > 0'
    );
    $stmt->execute([$assessment_id, $section_id]);
    $rows = $stmt->fetchAll();

    $cases   = 0;
    $sum_fx  = 0;
    $npwrm   = 0;
    $bands   = array_fill_keys(array_keys(MASTERY_BANDS), 0);

    foreach ($rows as $row) {
        $f     = (int)$row['frequency'];
        $score = (int)$row['score'];
        $cases  += $f;
        $sum_fx += $f * $score;

        if ($total_items > 0) {
            $pct = $score / $total_items * 100;
            $band = get_mastery_band($pct);
            $bands[$band] += $f;
            if ($pct >= MASTERY_THRESHOLD) {
                $npwrm += $f;
            }
        }
    }

    $mean     = $cases > 0 ? $sum_fx / $cases : 0;
    $mps      = ($cases > 0 && $total_items > 0) ? ($mean / $total_items * 100) : 0;
    $band_pct = [];
    foreach ($bands as $k => $cnt) {
        $band_pct[$k] = $cases > 0 ? round($cnt / $cases * 100, 2) : 0;
    }

    return [
        'cases'    => $cases,
        'sum_fx'   => $sum_fx,           // Σf(x) — used for MEAN and Item Analysis cross-check
        'mean'     => round($mean, 2),
        'mps'      => round($mps, 2),
        'npwrm'    => $npwrm,
        'bands'    => $bands,
        'band_pct' => $band_pct,
    ];
}

/**
 * Compute section-level totals across ALL sections for an assessment.
 */
function compute_mps_all_sections(int $assessment_id, int $total_items): array
{
    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT sf.section_id, s.name AS section_name
         FROM score_frequencies sf
         JOIN sections s ON s.id = sf.section_id
         WHERE sf.assessment_id = ?
         GROUP BY sf.section_id, s.name'
    );
    $stmt->execute([$assessment_id]);
    $sections = $stmt->fetchAll();

    $result = [];
    foreach ($sections as $sec) {
        $r = compute_mps_section($assessment_id, (int)$sec['section_id'], $total_items);
        $r['section_id']   = (int)$sec['section_id'];
        $r['section_name'] = $sec['section_name'];
        $result[]          = $r;
    }
    return $result;
}

// ============================================================
// Item Analysis computation
// ============================================================

/**
 * Compute item-analysis data for one (assessment, section) pair.
 * Returns array of [item_no, correct_count, pct].
 */
function compute_item_analysis_section(int $assessment_id, int $section_id, int $total_items, int $cases): array
{
    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT item_no, correct_count
         FROM item_correct_counts
         WHERE assessment_id = ? AND section_id = ?
         ORDER BY item_no'
    );
    $stmt->execute([$assessment_id, $section_id]);
    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $pct = $cases > 0 ? round($row['correct_count'] / $cases * 100, 2) : 0;
        $items[(int)$row['item_no']] = [
            'item_no'       => (int)$row['item_no'],
            'correct_count' => (int)$row['correct_count'],
            'pct'           => $pct,
        ];
    }
    // Fill missing items with 0
    for ($i = 1; $i <= $total_items; $i++) {
        if (!isset($items[$i])) {
            $items[$i] = ['item_no' => $i, 'correct_count' => 0, 'pct' => 0];
        }
    }
    ksort($items);
    return array_values($items);
}

/**
 * Compute TOTAL item analysis across all sections for an assessment.
 * Returns array of [item_no, total_correct, total_cases, pct].
 */
function compute_item_analysis_totals(int $assessment_id, int $total_items): array
{
    $pdo  = get_pdo();

    // Total cases per section
    $stmt = $pdo->prepare(
        'SELECT section_id, SUM(frequency) AS cases
         FROM score_frequencies
         WHERE assessment_id = ?
         GROUP BY section_id'
    );
    $stmt->execute([$assessment_id]);
    $cases_map = [];
    foreach ($stmt->fetchAll() as $r) {
        $cases_map[(int)$r['section_id']] = (int)$r['cases'];
    }
    $total_cases = array_sum($cases_map);

    // Aggregate correct counts
    $stmt = $pdo->prepare(
        'SELECT item_no, SUM(correct_count) AS total_correct
         FROM item_correct_counts
         WHERE assessment_id = ?
         GROUP BY item_no
         ORDER BY item_no'
    );
    $stmt->execute([$assessment_id]);

    $result = [];
    foreach ($stmt->fetchAll() as $r) {
        $pct = $total_cases > 0 ? round($r['total_correct'] / $total_cases * 100, 2) : 0;
        $result[(int)$r['item_no']] = [
            'item_no'       => (int)$r['item_no'],
            'total_correct' => (int)$r['total_correct'],
            'total_cases'   => $total_cases,
            'pct'           => $pct,
        ];
    }
    for ($i = 1; $i <= $total_items; $i++) {
        if (!isset($result[$i])) {
            $result[$i] = ['item_no' => $i, 'total_correct' => 0, 'total_cases' => $total_cases, 'pct' => 0];
        }
    }
    ksort($result);
    return array_values($result);
}

/**
 * Sum of all item correct counts for one (assessment, section) pair.
 * Should equal compute_mps_section()['sum_fx'] as a cross-check:
 * Σ(item correct counts) ≡ Σ(score × frequency) when each item is 1 point.
 */
function compute_item_total(int $assessment_id, int $section_id): int
{
    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(correct_count), 0) AS total
         FROM item_correct_counts
         WHERE assessment_id = ? AND section_id = ?'
    );
    $stmt->execute([$assessment_id, $section_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Which (assessment_id, section_id) pairs are legitimate to aggregate into
 * reports/dashboards. score_frequencies / item_correct_counts are written as
 * soon as a teacher hits Save Draft (not just Submit), and for shared
 * assessments assessments.status stays 'approved' the whole time it's open
 * for encoding — the real per-teacher progress lives in
 * teacher_assessment_encodings.status. Without this filter, any section a
 * teacher has ever draft-saved gets counted alongside genuinely submitted
 * ones. Legacy (is_shared=0) assessments are unaffected: one teacher owns
 * the whole assessment, so the caller's own status filter on `assessments`
 * already covers every one of its sections.
 *
 * @param int[] $asmtIds
 * @return array<int, array<int, true>> [assessment_id => [section_id => true]]
 */
function get_qualifying_sections(PDO $pdo, array $asmtIds): array
{
    if (empty($asmtIds)) return [];
    $in = implode(',', array_fill(0, count($asmtIds), '?'));

    $stmt = $pdo->prepare(
        "SELECT DISTINCT asec.assessment_id, asec.section_id
         FROM assessment_sections asec
         JOIN assessments a ON a.id = asec.assessment_id
         JOIN terms t ON t.id = a.term_id
         WHERE asec.assessment_id IN ({$in})
           AND (
                a.is_shared = 0
                OR EXISTS (
                    SELECT 1 FROM teacher_assignments ta
                    JOIN teacher_assessment_encodings tae
                      ON tae.teacher_id = ta.teacher_id AND tae.assessment_id = asec.assessment_id
                    WHERE ta.section_id     = asec.section_id
                      AND ta.subject_id     = a.subject_id
                      AND ta.school_year_id = t.school_year_id
                      AND tae.status IN ('submitted','approved')
                )
           )"
    );
    $stmt->execute($asmtIds);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['assessment_id']][(int)$r['section_id']] = true;
    }
    return $out;
}

// ============================================================
// Input validation helpers
// ============================================================

/**
 * Classify a score percentage into one of 7 performance bands.
 * Used by both the band-distribution chart (get_dashboard_data.php)
 * and the JS scoreBand() mirror function.
 */
function get_score_band(float $pct): string
{
    if ($pct >= 98) return '98-100';
    if ($pct >= 95) return '95-97';
    if ($pct >= 90) return '90-94';
    if ($pct >= 85) return '85-89';
    if ($pct >= 80) return '80-84';
    if ($pct >= 75) return '75-79';
    return 'Below 75';
}

/**
 * Classify a score percentage into a DepEd descriptor rating (PROFICIENCY_LEVELS).
 * Used by both the proficiency-level distribution (get_dashboard_data.php)
 * and the JS getPL() mirror function.
 */
function get_proficiency_level(float $pct): string
{
    foreach (PROFICIENCY_LEVELS as $key => $level) {
        if ($pct >= $level['min'] && $pct <= $level['max']) return $key;
    }
    return 'DNME';
}

function validate_int(mixed $val, int $min = 0, int $max = PHP_INT_MAX): ?int
{
    if (!is_numeric($val)) return null;
    $v = (int)$val;
    return ($v >= $min && $v <= $max) ? $v : null;
}

function validate_string(mixed $val, int $max_len = 255): ?string
{
    if (!is_string($val)) return null;
    $v = trim($val);
    return mb_strlen($v) <= $max_len && $v !== '' ? $v : null;
}
