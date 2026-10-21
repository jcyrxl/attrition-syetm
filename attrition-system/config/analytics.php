<?php
/**
 * Numbers and small SVG charts for the Dashboard (the CURRENT school year) and History (older school years).
 * Everything is drawn with plain SVG/CSS, so it works offline and needs no chart library.
 */
require_once __DIR__ . '/academic.php';
require_once __DIR__ . '/predict.php';

// ------------------------------------------------------------------ which school year is "current"?
/** From today's date: June-December = 1st semester of the school year that starts this year; January-May = 2nd semester. */
function calendarSchoolYear(?DateTime $now = null): array {
    $now = $now ?: new DateTime();
    $y = (int) $now->format('Y');
    $m = (int) $now->format('n');
    $start = $m >= 6 ? $y : $y - 1;
    return ['ay' => $start . '-' . ($start + 1), 'sem' => ($m >= 6 && $m <= 12) ? 1 : 2];
}

function schoolYearsWithData(PDO $pdo): array {
    return $pdo->query("SELECT DISTINCT academic_year FROM student_records ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);
}

function latestSemesterWithData(PDO $pdo, string $ay): ?int {
    $q = $pdo->prepare("SELECT MAX(sub.semester) FROM subject_records sub
                        INNER JOIN student_records sr ON sr.student_id = sub.student_id AND sr.year_level = sub.year_level
                        WHERE sr.academic_year = ?");
    $q->execute([$ay]);
    $v = $q->fetchColumn();
    return ($v !== null && $v !== false) ? (int) $v : null;
}

/** The school year the Faculty chose as current (Start new school year); null = follow the calendar / the data. */
function storedCurrentSchoolYear(PDO $pdo): ?string {
    try {
        $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'current_school_year'")->fetchColumn();
        return ($v && in_array($v, AY_ENTRY_OPTIONS, true)) ? $v : null;
    } catch (PDOException $e) { return null; }
}
function setCurrentSchoolYear(PDO $pdo, string $ay): void {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('current_school_year', ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$ay]);
}

/**
 * The current school year (Dashboard, Students). Everything before it is History.
 *  - once the Faculty has used "Start new school year", that choice is the current school year;
 *  - before that, it follows the calendar (Oct 2026 -> 2026-2027, 1st semester) as soon as that year has data,
 *    otherwise the latest school year that has data (with a notice).
 * 'new_year' = the calendar says a newer school year has started than the chosen one (so the Faculty can be reminded to move on).
 */
function storedCurrentSemester(PDO $pdo): ?int {
    try {
        $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'current_semester'")->fetchColumn();
        return in_array((int) $v, [1, 2], true) ? (int) $v : null;
    } catch (PDOException $e) { return null; }
}
/** The Admin moves the system to a new period: the one before is archived (its classes become view only). */
function setCurrentPeriod(PDO $pdo, string $ay, int $sem): void {
    setCurrentSchoolYear($pdo, $ay);
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('current_semester', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([(string) $sem]);
}
/** The period that comes after this one: 1st -> 2nd semester, 2nd -> 1st semester of the next school year. */
function nextPeriod(string $ay, int $sem): array {
    if ($sem === 1) { return [$ay, 2]; }
    $y = (int) substr($ay, 0, 4) + 1;
    return [$y . '-' . ($y + 1), 1];
}
/** A class of an earlier period is archived: it can be looked at, never changed. */
function isClassArchived(array $class): bool {
    global $pdo;
    static $cur = null;
    if ($cur === null) { $cur = currentSchoolYear($pdo); }
    if (!empty($class['cleared_at'])) { return true; }                    // cleared by the Program Head
    return $class['academic_year'] !== $cur['ay'] || (int) $class['semester'] !== (int) $cur['sem'];
}
function currentSchoolYear(PDO $pdo): array {
    $cal = calendarSchoolYear();
    $years = schoolYearsWithData($pdo);
    $manual = storedCurrentSchoolYear($pdo);
    $fallback = false;
    if ($manual !== null)                         { $ay = $manual; }
    elseif (in_array($cal['ay'], $years, true))   { $ay = $cal['ay']; }
    elseif ($years)                               { $ay = $years[0]; $fallback = true; }
    else                                          { $ay = $cal['ay']; $fallback = true; }
    $storedSem = storedCurrentSemester($pdo);
    $sem = ($manual !== null && $storedSem !== null) ? $storedSem : (latestSemesterWithData($pdo, $ay) ?? ($ay === $cal['ay'] ? $cal['sem'] : 1));
    return ['ay' => $ay, 'sem' => $sem, 'fallback' => $fallback, 'calendar' => $cal['ay'], 'manual' => $manual !== null,
            'new_year' => ($manual !== null && $cal['ay'] > $manual) ? $cal['ay'] : null];
}

// ------------------------------------------------------------------ the numbers
/**
 * Risk of attrition for a school year.
 *  - current year: the stored predictions (the same badges the Students page shows), enrolled students only
 *  - an older year: recomputed with the same formula from each student's records up to that year ("risk at that time")
 */
function riskSnapshot(PDO $pdo, string $ay, bool $useStored): array {
    $out = ['High' => 0, 'Moderate' => 0, 'Low' => 0, 'total' => 0, 'watch' => []];

    if ($useStored) {
        $inYear = "EXISTS (SELECT 1 FROM student_records x WHERE x.student_id = s.student_id AND x.academic_year = ?)";
        $q = $pdo->prepare("SELECT COUNT(*) FROM students s WHERE s.status = 'enrolled' AND $inYear");
        $q->execute([$ay]);
        $out['total'] = (int) $q->fetchColumn();

        $q = $pdo->prepare("SELECT p.risk_level, COUNT(*) AS cnt FROM students s
                            INNER JOIN predictions p ON p.student_id = s.student_id
                                AND p.predicted_at = (SELECT MAX(predicted_at) FROM predictions WHERE student_id = s.student_id)
                            WHERE s.status = 'enrolled' AND $inYear GROUP BY p.risk_level");
        $q->execute([$ay]);
        foreach ($q->fetchAll() as $r) { if (isset($out[$r['risk_level']])) { $out[$r['risk_level']] = (int) $r['cnt']; } }

        $q = $pdo->prepare("SELECT s.student_id, s.full_name, p.probability, sr.section,
                                   (SELECT z.gwa FROM student_records z WHERE z.student_id = s.student_id AND z.gwa IS NOT NULL ORDER BY z.year_level DESC LIMIT 1) AS gwa
                            FROM students s
                            INNER JOIN predictions p ON p.student_id = s.student_id
                                AND p.predicted_at = (SELECT MAX(predicted_at) FROM predictions WHERE student_id = s.student_id)
                            INNER JOIN student_records sr ON sr.student_id = s.student_id AND sr.academic_year = ?
                            WHERE s.status = 'enrolled' AND p.risk_level = 'High'
                            ORDER BY p.probability DESC LIMIT 8");
        $q->execute([$ay]);
        $out['watch'] = $q->fetchAll();
        return $out;
    }

    $coeffs = getModelCoefficients($pdo);
    $q = $pdo->prepare("SELECT r.student_id, r.year_level, r.academic_year, r.gwa, r.attendance_rate, r.units_failed, r.section, s.full_name
                        FROM student_records r INNER JOIN students s ON s.student_id = r.student_id
                        WHERE r.student_id IN (SELECT student_id FROM student_records WHERE academic_year = ?)
                        ORDER BY r.student_id, r.year_level");
    $q->execute([$ay]);
    $by = [];
    foreach ($q->fetchAll() as $r) { $by[$r['student_id']][] = $r; }
    $watch = [];
    foreach ($by as $recs) {
        $target = null;
        foreach ($recs as $r) { if ($r['academic_year'] === $ay) { $target = $r; } }
        if (!$target || $target['gwa'] === null) { continue; }      // enrolled but no grades yet
        [$prob, $level] = computeRisk((float) $target['gwa'], (float) $target['attendance_rate'], (int) $target['units_failed'],
                                      (float) $target['gwa'] - (float) $recs[0]['gwa'], $coeffs);
        $out[$level]++;
        $out['total']++;
        if ($level === 'High') {
            $watch[] = ['student_id' => $target['student_id'], 'full_name' => $target['full_name'], 'probability' => $prob, 'section' => $target['section'], 'gwa' => $target['gwa']];
        }
    }
    usort($watch, function ($a, $b) { return $b['probability'] <=> $a['probability']; });
    $out['watch'] = array_slice($watch, 0, 8);
    return $out;
}

/** Where the students of a school year stand now (Enrolled, LOA, HD, ...). */
function statusSnapshot(PDO $pdo, string $ay): array {
    $counts = array_fill_keys(array_keys(STUDENT_STATUSES), 0);
    $q = $pdo->prepare("SELECT s.status, COUNT(*) AS cnt FROM students s
                        WHERE EXISTS (SELECT 1 FROM student_records x WHERE x.student_id = s.student_id AND x.academic_year = ?)
                        GROUP BY s.status");
    $q->execute([$ay]);
    foreach ($q->fetchAll() as $r) { if (isset($counts[$r['status']])) { $counts[$r['status']] = (int) $r['cnt']; } }
    return $counts;
}

/** Leave / dismissal / drop / shift requests of a school year: by type, by semester, by year level, and by reason ("Others" is one bar). */
function caseStatsForYear(PDO $pdo, string $ay): array {
    $types = array_fill_keys(array_keys(CASE_TYPES), 0);
    $out = ['total' => 0, 'byType' => $types, 'bySem' => [], 'byYear' => [], 'reasons' => []];
    foreach (CASE_SEMESTERS as $sm) { $out['bySem'][$sm] = $types; }
    foreach ([1, 2, 3, 4] as $y)     { $out['byYear'][$y] = $types; }
    try {
        $q = $pdo->prepare("SELECT c.case_type, c.semester, c.reason, COALESCE(sr.year_level, s.year_level) AS yl
                            FROM student_cases c
                            INNER JOIN students s ON s.student_id = c.student_id
                            LEFT JOIN student_records sr ON sr.student_id = c.student_id AND sr.academic_year = c.academic_year
                            WHERE c.academic_year = ?");
        $q->execute([$ay]);
        foreach ($q->fetchAll() as $r) {
            $t = $r['case_type'];
            if (!isset($types[$t])) { continue; }
            $out['total']++;
            $out['byType'][$t]++;
            if (isset($out['bySem'][$r['semester']])) { $out['bySem'][$r['semester']][$t]++; }
            $yl = (int) $r['yl'];
            if (isset($out['byYear'][$yl])) { $out['byYear'][$yl][$t]++; }
            $out['reasons'][$r['reason']] = ($out['reasons'][$r['reason']] ?? 0) + 1;
        }
    } catch (PDOException $e) { /* student_cases not created yet */ }
    uksort($out['reasons'], function ($a, $b) use ($out) {
        if (($a === 'Others') !== ($b === 'Others')) { return $a === 'Others' ? 1 : -1; }
        return ($out['reasons'][$b] <=> $out['reasons'][$a]) ?: strcmp($a, $b);
    });
    return $out;
}

// ------------------------------------------------------------------ predicting grades and attendance
/**
 * A student's next value from the trend of their own earlier semesters.
 * $prior = [[termNumber, value], ...] oldest first. One earlier semester -> assume it repeats;
 * two or more -> straight-line trend over the last four (capped at 4 points per semester), kept inside 0-100.
 */
function forecastValue(array $prior, int $tTarget): float {
    $n = count($prior);
    $last = $prior[$n - 1];
    if ($n === 1) { return (float) $last[1]; }
    $use = array_slice($prior, -4);
    $m = count($use);
    $sx = $sy = $sxx = $sxy = 0.0;
    foreach ($use as $pt) {
        $sx += $pt[0]; $sy += $pt[1]; $sxx += $pt[0] * $pt[0]; $sxy += $pt[0] * $pt[1];
    }
    $den = $m * $sxx - $sx * $sx;
    $slope = $den != 0 ? ($m * $sxy - $sx * $sy) / $den : 0.0;
    $slope = max(-4.0, min(4.0, $slope));
    return max(0.0, min(100.0, $last[1] + $slope * ($tTarget - $last[0])));
}

function avgOrNull(array $v): ?float {
    return $v ? array_sum($v) / count($v) : null;
}

/**
 * Average grade and attendance of a school year, per semester: what really happened vs what the earlier semesters predicted.
 * A semester nobody has grades for yet is a forecast only — this also works at the start of a school year,
 * when students are enrolled but no grades exist yet.
 */
function gradeAttendanceForecast(PDO $pdo, string $ay): array {
    $blank = ['actual_g' => null, 'pred_g' => null, 'actual_a' => null, 'pred_a' => null, 'n_actual' => 0, 'n_pred' => 0, 'forecast' => false];
    $res = [1 => $blank, 2 => $blank];

    $rows = $pdo->query("SELECT sub.student_id, sub.year_level, sub.semester, sr.academic_year AS ay, AVG(sub.grade) AS g, AVG(sub.attendance_rate) AS a
                         FROM subject_records sub
                         INNER JOIN student_records sr ON sr.student_id = sub.student_id AND sr.year_level = sub.year_level
                         GROUP BY sub.student_id, sub.year_level, sub.semester, sr.academic_year
                         ORDER BY sub.student_id, sub.year_level, sub.semester")->fetchAll();
    $series = [];
    foreach ($rows as $r) {
        $series[$r['student_id']][] = ['t' => ((int) $r['year_level'] - 1) * 2 + (int) $r['semester'], 'sem' => (int) $r['semester'], 'ay' => $r['ay'], 'g' => (float) $r['g'], 'a' => (float) $r['a']];
    }
    // everyone who belongs to this school year, and the year level they are in
    $q = $pdo->prepare("SELECT student_id, year_level FROM student_records WHERE academic_year = ?");
    $q->execute([$ay]);
    $ylMap = [];
    foreach ($q->fetchAll() as $r) { $ylMap[$r['student_id']] = (int) $r['year_level']; }

    $hasActual = [1 => false, 2 => false];
    foreach ($ylMap as $sid => $yl) { foreach ($series[$sid] ?? [] as $p) { if ($p['ay'] === $ay) { $hasActual[$p['sem']] = true; } } }

    $acc = [];
    foreach ([1, 2] as $s) { $acc[$s] = ['ag' => [], 'aa' => [], 'pg' => [], 'pa' => [], 'ago' => [], 'aao' => [], 'n_actual' => 0]; }
    foreach ($ylMap as $sid => $yl) {
        $pts = $series[$sid] ?? [];
        foreach ([1, 2] as $s) {
            $tTarget = ($yl - 1) * 2 + $s;
            $target = null;
            foreach ($pts as $p) { if ($p['ay'] === $ay && $p['sem'] === $s) { $target = $p; } }
            $priorG = []; $priorA = [];
            foreach ($pts as $p) { if ($p['t'] < $tTarget) { $priorG[] = [$p['t'], $p['g']]; $priorA[] = [$p['t'], $p['a']]; } }
            if ($target) {
                $acc[$s]['n_actual']++;
                if ($priorG) {
                    $acc[$s]['ag'][] = $target['g']; $acc[$s]['aa'][] = $target['a'];
                    $acc[$s]['pg'][] = forecastValue($priorG, $tTarget); $acc[$s]['pa'][] = forecastValue($priorA, $tTarget);
                } else { $acc[$s]['ago'][] = $target['g']; $acc[$s]['aao'][] = $target['a']; }
            } elseif (!$hasActual[$s] && $priorG) {
                $acc[$s]['pg'][] = forecastValue($priorG, $tTarget); $acc[$s]['pa'][] = forecastValue($priorA, $tTarget);
            }
        }
    }
    foreach ([1, 2] as $s) {
        $a = $acc[$s];
        $res[$s]['n_actual'] = $a['n_actual'];
        $res[$s]['n_pred']   = count($a['pg']);
        $res[$s]['pred_g']   = avgOrNull($a['pg']);
        $res[$s]['pred_a']   = avgOrNull($a['pa']);
        $res[$s]['forecast'] = !$hasActual[$s] && $a['pg'];
        $res[$s]['actual_g'] = avgOrNull($a['ag'] ?: $a['ago']);
        $res[$s]['actual_a'] = avgOrNull($a['aa'] ?: $a['aao']);
    }
    return $res;
}

// ------------------------------------------------------------------ SVG charts
function svgDonut(array $segments, int $size, int $stroke, string $top, string $bottom, string $track = 'rgba(255,255,255,.25)'): string {
    $r = ($size - $stroke) / 2;
    $c = $size / 2;
    $circ = 2 * M_PI * $r;
    $total = 0;
    foreach ($segments as $s) { $total += $s['value']; }
    $svg = '<svg viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size . '" height="' . $size . '" role="img" aria-label="' . htmlspecialchars($bottom) . '">';
    $svg .= '<circle cx="' . $c . '" cy="' . $c . '" r="' . $r . '" fill="none" stroke="' . $track . '" stroke-width="' . $stroke . '"/>';
    $offset = 0;
    if ($total > 0) {
        foreach ($segments as $s) {
            if ($s['value'] <= 0) { continue; }
            $len = $s['value'] / $total * $circ;
            $el = '<circle cx="' . $c . '" cy="' . $c . '" r="' . $r . '" fill="none" stroke="' . $s['color'] . '" stroke-width="' . $stroke . '"'
                . ' stroke-dasharray="' . round($len, 2) . ' ' . round($circ - $len, 2) . '" stroke-dashoffset="' . round(-$offset, 2) . '"'
                . ' transform="rotate(-90 ' . $c . ' ' . $c . ')"><title>' . htmlspecialchars($s['label'] . ': ' . $s['value']) . '</title></circle>';
            if (!empty($s['pop'])) { $el = '<g data-pop="' . $s['pop'] . '" style="cursor:pointer">' . $el . '</g>'; }   // tap = the students behind the slice
            $svg .= !empty($s['href']) ? '<a href="' . htmlspecialchars($s['href']) . '">' . $el . '</a>' : $el;
            $offset += $len;
        }
    }
    $svg .= '<text x="' . $c . '" y="' . ($c + 2) . '" text-anchor="middle" font-size="30" font-weight="700" fill="#fff">' . htmlspecialchars($top) . '</text>';
    $svg .= '<text x="' . $c . '" y="' . ($c + 20) . '" text-anchor="middle" font-size="11" fill="rgba(255,255,255,.9)">' . htmlspecialchars($bottom) . '</text>';
    return $svg . '</svg>';
}

/** Actual (solid green) vs predicted (dashed purple) across the two semesters. $key = 'g' (grades) or 'a' (attendance). */
function svgForecastChart(array $sems, string $key, string $label): string {
    $vals = [];
    foreach ($sems as $s) {
        foreach (['actual_' . $key, 'pred_' . $key] as $k) { if ($s[$k] !== null) { $vals[] = (float) $s[$k]; } }
    }
    if (!$vals) { return '<div class="chart-empty">Not enough grades yet to draw this.</div>'; }

    $lo = max(0, floor((min($vals) - 8) / 5) * 5);
    $hi = min(100, ceil((max($vals) + 8) / 5) * 5);
    if ($hi - $lo < 20) { $lo = max(0, $hi - 20); }
    $W = 480; $H = 250; $L = 46; $R = 26; $T = 30; $B = 52;
    $pw = $W - $L - $R; $ph = $H - $T - $B;
    $xs = [1 => $L + $pw * 0.27, 2 => $L + $pw * 0.73];
    $y = function (float $v) use ($lo, $hi, $T, $ph) { return $T + $ph * (1 - ($v - $lo) / ($hi - $lo)); };

    $svg = '<svg class="fc-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . htmlspecialchars($label) . '">';
    for ($i = 0; $i <= 4; $i++) {
        $v = $lo + ($hi - $lo) * $i / 4;
        $yy = round($y($v), 1);
        $svg .= '<line x1="' . $L . '" y1="' . $yy . '" x2="' . ($W - $R) . '" y2="' . $yy . '" stroke="#e6edf2" stroke-width="1"/>';
        $svg .= '<text x="' . ($L - 8) . '" y="' . ($yy + 4) . '" text-anchor="end" font-size="11" fill="#8a959b">' . round($v) . '</text>';
    }
    foreach ([1, 2] as $s) {
        $svg .= '<text x="' . round($xs[$s], 1) . '" y="' . ($H - 24) . '" text-anchor="middle" font-size="12.5" font-weight="600" fill="#33475a">' . ($s === 1 ? '1st Semester' : '2nd Semester') . '</text>';
        if ($sems[$s]['forecast']) {
            $svg .= '<text x="' . round($xs[$s], 1) . '" y="' . ($H - 8) . '" text-anchor="middle" font-size="11" fill="#5b7fe0">forecast — no grades yet</text>';
        }
    }
    $styles = ['pred' => ['#5b7fe0', '6 5'], 'actual' => ['#0b8fa3', '']];
    $points = [];
    foreach ($styles as $name => $st) {
        $pts = [];
        foreach ([1, 2] as $s) {
            $v = $sems[$s][$name . '_' . $key];
            if ($v !== null) { $pts[$s] = [$xs[$s], $y((float) $v), (float) $v]; $points[$s][$name] = $pts[$s]; }
        }
        if (count($pts) === 2) {
            $dash = $st[1] !== '' ? ' stroke-dasharray="' . $st[1] . '"' : '';
            $svg .= '<line x1="' . round($pts[1][0], 1) . '" y1="' . round($pts[1][1], 1) . '" x2="' . round($pts[2][0], 1) . '" y2="' . round($pts[2][1], 1)
                  . '" stroke="' . $st[0] . '" stroke-width="3" stroke-linecap="round"' . $dash . '/>';
        }
    }
    foreach ($points as $s => $byName) {
        foreach ($byName as $name => $p) {
            $color = $styles[$name][0];
            $hollow = ($name === 'pred' && $sems[$s]['forecast']);
            $svg .= '<circle cx="' . round($p[0], 1) . '" cy="' . round($p[1], 1) . '" r="6" fill="' . ($hollow ? '#fff' : $color) . '" stroke="' . $color . '" stroke-width="3"/>';
            $other = $name === 'pred' ? 'actual' : 'pred';
            $above = !isset($byName[$other]) || $p[2] > $byName[$other][2] || ($p[2] == $byName[$other][2] && $name === 'actual');
            $ly = $above ? $p[1] - 13 : $p[1] + 22;
            $svg .= '<text x="' . round($p[0], 1) . '" y="' . round($ly, 1) . '" text-anchor="middle" font-size="12.5" font-weight="700" fill="' . $color . '">' . number_format($p[2], 1) . '</text>';
        }
    }
    return $svg . '</svg>';
}

/** Side-by-side bars: $groups = [label => [seriesKey => count]], $series = [seriesKey => ['label' => .., 'color' => ..]]. */
function svgGroupedBars(array $groups, array $series, string $label): string {
    $max = 0;
    foreach ($groups as $g) { foreach ($series as $k => $_) { $max = max($max, (int) ($g[$k] ?? 0)); } }
    if ($max === 0) { return '<div class="chart-empty">No requests recorded.</div>'; }
    $niceMax = max(4, (int) (ceil($max / 2) * 2));
    $W = 440; $H = 230; $L = 34; $R = 10; $T = 20; $B = 40;
    $pw = $W - $L - $R; $ph = $H - $T - $B;
    $nG = count($groups); $nS = count($series);
    $groupW = $pw / $nG;
    $barW = min(26, ($groupW * 0.78) / $nS);
    $svg = '<svg class="gb-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . htmlspecialchars($label) . '">';
    foreach ([0, 0.5, 1] as $f) {
        $yy = round($T + $ph * (1 - $f), 1);
        $svg .= '<line x1="' . $L . '" y1="' . $yy . '" x2="' . ($W - $R) . '" y2="' . $yy . '" stroke="#e6edf2"/>';
        $svg .= '<text x="' . ($L - 6) . '" y="' . ($yy + 4) . '" text-anchor="end" font-size="11" fill="#8a959b">' . round($niceMax * $f) . '</text>';
    }
    $i = 0;
    foreach ($groups as $gLabel => $g) {
        $x0 = $L + $i * $groupW + ($groupW - $barW * $nS) / 2;
        $j = 0;
        foreach ($series as $k => $meta) {
            $v = (int) ($g[$k] ?? 0);
            $h = $v / $niceMax * $ph;
            $x = round($x0 + $j * $barW, 1);
            if ($v > 0) {
                $svg .= '<rect x="' . $x . '" y="' . round($T + $ph - $h, 1) . '" width="' . round($barW - 3, 1) . '" height="' . round($h, 1) . '" rx="4" fill="' . $meta['color'] . '"><title>'
                      . htmlspecialchars($meta['label'] . ' — ' . $gLabel . ': ' . $v) . '</title></rect>';
                $svg .= '<text x="' . round($x + ($barW - 3) / 2, 1) . '" y="' . round($T + $ph - $h - 5, 1) . '" text-anchor="middle" font-size="11" font-weight="700" fill="#33475a">' . $v . '</text>';
            }
            $j++;
        }
        $svg .= '<text x="' . round($L + $i * $groupW + $groupW / 2, 1) . '" y="' . ($H - 16) . '" text-anchor="middle" font-size="12" fill="#33475a">' . htmlspecialchars((string) $gLabel) . '</text>';
        $i++;
    }
    return $svg . '</svg>';
}

// ------------------------------------------------------------------ leave of absence (Dashboard)
/** Leave-of-absence students of a school year: per year level and semester, per reason, and the cases behind them (for the pop-up). */
function loaStatsForYear(PDO $pdo, string $ay): array {
    $out = ['total' => 0, 'grid' => [], 'reasons' => [], 'cases' => []];
    foreach ([1, 2, 3, 4] as $y) { foreach (CASE_SEMESTERS as $sm) { $out['grid'][$y][$sm] = 0; } }
    try {
        $q = $pdo->prepare("SELECT c.id, c.case_type, c.academic_year, c.semester, c.reason, c.reason_other, c.details, c.filed_on,
                                   s.student_id, s.full_name, COALESCE(sr.year_level, s.year_level) AS yl
                            FROM student_cases c
                            INNER JOIN students s ON s.student_id = c.student_id
                            LEFT JOIN student_records sr ON sr.student_id = c.student_id AND sr.academic_year = c.academic_year
                            WHERE c.case_type = 'loa' AND c.academic_year = ?
                            ORDER BY s.full_name ASC");
        $q->execute([$ay]);
        foreach ($q->fetchAll() as $r) {
            $out['total']++;
            $yl = (int) $r['yl'];
            if (isset($out['grid'][$yl][$r['semester']])) { $out['grid'][$yl][$r['semester']]++; }
            $out['reasons'][$r['reason']] = ($out['reasons'][$r['reason']] ?? 0) + 1;
            $out['cases'][] = $r;
        }
    } catch (PDOException $e) { /* student_cases not created yet */ }
    uksort($out['reasons'], function ($a, $b) use ($out) {
        if (($a === 'Others') !== ($b === 'Others')) { return $a === 'Others' ? 1 : -1; }
        return ($out['reasons'][$b] <=> $out['reasons'][$a]) ?: strcmp($a, $b);
    });
    return $out;
}

// ------------------------------------------------------------------ History: records of earlier school years
/** Risk "at that time" for one year record (the same formula the live predictions use). $rec needs gwa, attendance_rate, units_failed, first_gwa. */
function recordRisk(array $rec, array $coeffs): array {
    $trend = (float) $rec['gwa'] - (float) ($rec['first_gwa'] ?? $rec['gwa']);
    [$p, $level] = computeRisk((float) $rec['gwa'], (float) $rec['attendance_rate'], (int) $rec['units_failed'], $trend, $coeffs);
    return ['probability' => $p, 'level' => $level];
}

/** Every section of every earlier school year, as a folder: ['section', 'ay', 'year', 'total', 'high']. Newest school year first. */
function historyFolders(PDO $pdo, string $currentAy): array {
    $coeffs = getModelCoefficients($pdo);
    $q = $pdo->prepare("SELECT sr.student_id, sr.year_level, sr.academic_year, sr.section, sr.gwa, sr.attendance_rate, sr.units_failed,
                               (SELECT z.gwa FROM student_records z WHERE z.student_id = sr.student_id ORDER BY z.year_level ASC LIMIT 1) AS first_gwa
                        FROM student_records sr WHERE sr.academic_year < ?");
    $q->execute([$currentAy]);
    $folders = [];
    foreach ($q->fetchAll() as $r) {
        $sec = (string) ($r['section'] ?? '');
        $key = $sec . '|' . $r['academic_year'];
        if (!isset($folders[$key])) {
            $yr = preg_match('/^BSIT-([1-4])[1-8]$/', $sec, $m) ? (int) $m[1] : (int) $r['year_level'];
            $folders[$key] = ['section' => $sec, 'ay' => $r['academic_year'], 'year' => $yr, 'total' => 0, 'high' => 0];
        }
        $folders[$key]['total']++;
        if ($r['gwa'] !== null && recordRisk($r, $coeffs)['level'] === 'High') { $folders[$key]['high']++; }
    }
    uasort($folders, function ($a, $b) {
        return [$b['ay'], $a['section']] <=> [$a['ay'], $b['section']];
    });
    return array_values($folders);
}

/**
 * The student records of earlier school years, alphabetical by last name.
 * $f = ['ay', 'section', 'status', 'risk', 'q'] (empty = no filter). Each row carries its own risk level at that time.
 */
function historyRecords(PDO $pdo, string $currentAy, array $f): array {
    $coeffs = getModelCoefficients($pdo);
    $where = ["sr.academic_year < ?"]; $params = [$currentAy];
    if (($f['ay'] ?? '') !== '')      { $where[] = "sr.academic_year = ?"; $params[] = $f['ay']; }
    if (($f['section'] ?? '') !== '') { $where[] = "sr.section = ?";       $params[] = $f['section']; }
    if (($f['status'] ?? '') !== '')  { $where[] = "s.status = ?";         $params[] = $f['status']; }
    if (($f['q'] ?? '') !== '')       { $where[] = "(s.full_name LIKE ? OR s.student_id LIKE ?)"; array_push($params, '%' . $f['q'] . '%', '%' . $f['q'] . '%'); }
    $q = $pdo->prepare("SELECT s.student_id, s.full_name, s.status, sr.year_level, sr.academic_year, sr.section, sr.gwa, sr.attendance_rate, sr.units_failed,
                               (SELECT z.gwa FROM student_records z WHERE z.student_id = sr.student_id ORDER BY z.year_level ASC LIMIT 1) AS first_gwa
                        FROM student_records sr INNER JOIN students s ON s.student_id = sr.student_id
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY s.full_name ASC, sr.academic_year DESC");
    $q->execute($params);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $risk = $r['gwa'] !== null ? recordRisk($r, $coeffs) : ['level' => null, 'probability' => null];     // enrolled, no grades: no risk then
        if (($f['risk'] ?? '') !== '' && $risk['level'] !== $f['risk']) { continue; }
        $r['risk_level'] = $risk['level'];
        $r['risk_prob']  = $risk['probability'];
        $out[] = $r;
    }
    return $out;
}

// ------------------------------------------------------------------ attrition itself (Reports) and the prediction page
/** "Attrition" = Dropped (Unofficially) + Honorable Dismissal (the student left). Shifted students stay in the University; Leave of Absence may come back. */
const ATTRITION_STATUSES = ['dropped', 'hd'];

/**
 * Attrition per school year: how many students there were, how many left, and per year level.
 * A student who left is counted in the school year (and year level) of their LAST record.
 * Returns [ay => ['total' => n, 'du' => n, 'hd' => n, 'byYear' => [yl => ['du' => n, 'hd' => n]]]], oldest first.
 */
function attritionStats(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT academic_year AS ay, COUNT(DISTINCT student_id) AS n FROM student_records GROUP BY academic_year")->fetchAll() as $r) {
        $out[$r['ay']] = ['total' => (int) $r['n'], 'du' => 0, 'hd' => 0, 'byYear' => [1 => ['du' => 0, 'hd' => 0], 2 => ['du' => 0, 'hd' => 0], 3 => ['du' => 0, 'hd' => 0], 4 => ['du' => 0, 'hd' => 0]]];
    }
    $rows = $pdo->query("SELECT sr.academic_year AS ay, sr.year_level AS yl, s.status, COUNT(*) AS n
                         FROM students s
                         INNER JOIN student_records sr ON sr.student_id = s.student_id
                             AND sr.year_level = (SELECT MAX(z.year_level) FROM student_records z WHERE z.student_id = s.student_id)
                         WHERE s.status IN ('dropped', 'hd')
                         GROUP BY sr.academic_year, sr.year_level, s.status")->fetchAll();
    foreach ($rows as $r) {
        if (!isset($out[$r['ay']])) { continue; }
        $k = $r['status'] === 'dropped' ? 'du' : 'hd';
        $out[$r['ay']][$k] += (int) $r['n'];
        if (isset($out[$r['ay']]['byYear'][(int) $r['yl']])) { $out[$r['ay']]['byYear'][(int) $r['yl']][$k] += (int) $r['n']; }
    }
    ksort($out);
    return $out;
}

/** Did the predictions come true? For a school year: students by the risk they had THEN, and how many of them later left. */
function riskOutcomes(PDO $pdo, string $ay): array {
    $coeffs = getModelCoefficients($pdo);
    $out = ['High' => ['n' => 0, 'left' => 0], 'Moderate' => ['n' => 0, 'left' => 0], 'Low' => ['n' => 0, 'left' => 0]];
    $q = $pdo->prepare("SELECT sr.gwa, sr.attendance_rate, sr.units_failed, s.status,
                               (SELECT z.gwa FROM student_records z WHERE z.student_id = sr.student_id ORDER BY z.year_level ASC LIMIT 1) AS first_gwa
                        FROM student_records sr INNER JOIN students s ON s.student_id = sr.student_id
                        WHERE sr.academic_year = ? AND sr.gwa IS NOT NULL");
    $q->execute([$ay]);
    foreach ($q->fetchAll() as $r) {
        $lvl = recordRisk($r, $coeffs)['level'];
        $out[$lvl]['n']++;
        if (in_array($r['status'], ATTRITION_STATUSES, true)) { $out[$lvl]['left']++; }
    }
    return $out;
}

/**
 * The Prediction page: the enrolled students of a school year with their latest risk, plus what sets the at-risk ones apart.
 * ['rows' => every scored student, 'avg' => per risk level, 'byYear' => [yl => [High, Moderate]], 'bySection' => [section => [High, Moderate]], 'subjects' => [name => failures]]
 */
/**
 * WHY a student is at risk: how much each of the four inputs pushes the risk up, compared with a safe student (grades 85%, attendance 95%,
 * nothing failed, no drop). The biggest pushes are the "main reasons" (this is just the model's own formula, split by input).
 * Returns [['label' => 'Low grades (62%)', 'push' => 2.1], ...] — the strongest first.
 */
function riskReasons(?array $f, array $c): array {
    if (!$f) { return []; }
    $parts = [
        ['push' => $c['coef_gwa'] * ($f['gwa'] - 85),    'label' => 'Low grades (' . fmtGrade((float) $f['gwa']) . '%)'],
        ['push' => $c['coef_att'] * ($f['att'] - 95),    'label' => 'Low attendance (' . round((float) $f['att']) . '%)'],
        ['push' => $c['coef_failed'] * (int) $f['failed'], 'label' => (int) $f['failed'] . ' failed subject' . ((int) $f['failed'] === 1 ? '' : 's')],
        ['push' => $c['coef_trend'] * (float) $f['trend'], 'label' => 'Grades going down (' . number_format((float) $f['trend'], 0) . ')'],
    ];
    $parts = array_values(array_filter($parts, function ($p) { return $p['push'] >= 0.4; }));
    usort($parts, function ($a, $b) { return $b['push'] <=> $a['push']; });
    return $parts;
}
/** How much the risk moved since the check before the latest one (percentage points), or null when there is no earlier check. */
function riskChange(PDO $pdo, string $sid, float $now): ?float {
    $q = $pdo->prepare("SELECT probability FROM predictions WHERE student_id = ? ORDER BY predicted_at DESC LIMIT 1 OFFSET 1");
    $q->execute([$sid]);
    $prev = $q->fetchColumn();
    return $prev === false ? null : $now - (float) $prev;
}

function predictionInsights(PDO $pdo, string $ay, ?array $scopeIds = null): array {
    $scope = function_exists('scopeInSql') ? scopeInSql($pdo, 's.student_id', $scopeIds) : '';
    $q = $pdo->prepare("SELECT s.student_id, s.full_name, p.risk_level, p.probability, p.predicted_at, sr.section, sr.year_level AS yl,
                               g.gwa, g.attendance_rate, g.units_failed, g.year_level AS graded_year
                        FROM students s
                        INNER JOIN predictions p ON p.student_id = s.student_id
                            AND p.predicted_at = (SELECT MAX(predicted_at) FROM predictions WHERE student_id = s.student_id)
                        INNER JOIN student_records sr ON sr.student_id = s.student_id AND sr.academic_year = ?
                        LEFT JOIN student_records g ON g.student_id = s.student_id
                            AND g.year_level = (SELECT MAX(z.year_level) FROM student_records z WHERE z.student_id = s.student_id AND z.gwa IS NOT NULL)
                        WHERE s.status = 'enrolled'" . ($scope !== '' ? " AND $scope" : "") . "
                        ORDER BY s.full_name ASC");
    $q->execute([$ay]);
    $rows = $q->fetchAll();

    $sum = []; $byYear = [1 => ['High' => 0, 'Moderate' => 0], 2 => ['High' => 0, 'Moderate' => 0], 3 => ['High' => 0, 'Moderate' => 0], 4 => ['High' => 0, 'Moderate' => 0]];
    $bySection = []; $atRiskGraded = [];
    foreach ($rows as $r) {
        $lvl = $r['risk_level'];
        $sum[$lvl]['n'] = ($sum[$lvl]['n'] ?? 0) + 1;
        $sum[$lvl]['gwa'] = ($sum[$lvl]['gwa'] ?? 0) + (float) $r['gwa'];
        $sum[$lvl]['att'] = ($sum[$lvl]['att'] ?? 0) + (float) $r['attendance_rate'];
        $sum[$lvl]['failed'] = ($sum[$lvl]['failed'] ?? 0) + (int) $r['units_failed'];
        if ($lvl === 'High' || $lvl === 'Moderate') {
            if (isset($byYear[(int) $r['yl']])) { $byYear[(int) $r['yl']][$lvl]++; }
            $sec = $r['section'] ?: 'No section';
            $bySection[$sec][$lvl] = ($bySection[$sec][$lvl] ?? 0) + 1;
            if ($r['graded_year'] !== null) { $atRiskGraded[$r['student_id']] = (int) $r['graded_year']; }
        }
    }
    // the main reasons and the change, for the students who are at risk
    if (function_exists('studentFeatures')) {
        $coef = getModelCoefficients($pdo);
        foreach ($rows as &$rr) {
            if ($rr['risk_level'] === 'Low') { $rr['reasons'] = []; $rr['change'] = null; continue; }
            $rr['reasons'] = riskReasons(studentFeatures($pdo, $rr['student_id']), $coef);
            $rr['change'] = riskChange($pdo, $rr['student_id'], (float) $rr['probability']);
        }
        unset($rr);
    }
    $avg = [];
    foreach (['High', 'Moderate', 'Low'] as $lvl) {
        $n = $sum[$lvl]['n'] ?? 0;
        $avg[$lvl] = $n ? ['n' => $n, 'gwa' => $sum[$lvl]['gwa'] / $n, 'att' => $sum[$lvl]['att'] / $n, 'failed' => $sum[$lvl]['failed'] / $n] : ['n' => 0, 'gwa' => null, 'att' => null, 'failed' => null];
    }
    ksort($bySection);

    // the subjects the at-risk students failed in the semesters that were graded
    $subjects = [];
    if ($atRiskGraded) {
        $in = implode(',', array_fill(0, count($atRiskGraded), '?'));
        $sq = $pdo->prepare("SELECT student_id, year_level, subject_name, grade FROM subject_records WHERE student_id IN ($in) AND grade < " . (float) FAILING_GRADE_THRESHOLD);
        $sq->execute(array_keys($atRiskGraded));
        foreach ($sq->fetchAll() as $r) {
            if ($atRiskGraded[$r['student_id']] === (int) $r['year_level']) { $subjects[$r['subject_name']] = ($subjects[$r['subject_name']] ?? 0) + 1; }
        }
        arsort($subjects);
    }
    return ['rows' => $rows, 'avg' => $avg, 'byYear' => $byYear, 'bySection' => $bySection, 'subjects' => array_slice($subjects, 0, 8, true)];
}

/** The reminder shown on the Dashboard and Students pages when a new school year has started and the old one is not in History yet. */
function renderRolloverBanner(array $cur): void {
    if (empty($cur['new_year']) || !function_exists('isProgramHead') || !isProgramHead()) { return; }       // the Program Head closes the semester
    echo '<div class="rollover-banner"><i class="fa-solid fa-calendar-plus"></i><span>School year <strong>' . htmlspecialchars($cur['new_year'])
       . '</strong> has started. Move <strong>' . htmlspecialchars($cur['ay']) . '</strong> to History and begin the new school year?</span>'
       . '<a href="history.php#close">Go to History →</a></div>';
}

/** Why attrition happened in a school year: the reasons written in the letters of students who dropped (unofficially) or were honorably dismissed, and in which semester. */
function attritionReasons(PDO $pdo, string $ay): array {
    $out = ['total' => 0, 'reasons' => [], 'sem' => []];
    foreach (CASE_SEMESTERS as $sm) { $out['sem'][$sm] = 0; }
    try {
        $q = $pdo->prepare("SELECT reason, semester FROM student_cases WHERE case_type IN ('du', 'hd') AND academic_year = ?");
        $q->execute([$ay]);
        foreach ($q->fetchAll() as $r) {
            $out['total']++;
            $out['reasons'][$r['reason']] = ($out['reasons'][$r['reason']] ?? 0) + 1;
            if (isset($out['sem'][$r['semester']])) { $out['sem'][$r['semester']]++; }
        }
    } catch (PDOException $e) { /* student_cases not created yet */ }
    uksort($out['reasons'], function ($a, $b) use ($out) {
        if (($a === 'Others') !== ($b === 'Others')) { return $a === 'Others' ? 1 : -1; }
        return ($out['reasons'][$b] <=> $out['reasons'][$a]) ?: strcmp($a, $b);
    });
    return $out;
}

/**
 * Predictions saved by an older version of the system (or by the dummy data) can disagree with the grades now on record.
 * Once, when the model version changes, every enrolled student is scored again so the risk everywhere matches the data.
 */
function ensurePredictionsCurrent(PDO $pdo, string $version = 'v2.0'): void {
    try {
        $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'predictions_model'")->fetchColumn();
        if ($v === $version) { return; }
        $coeffs = getModelCoefficients($pdo);
        foreach ($pdo->query("SELECT student_id FROM students WHERE status = 'enrolled'")->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            runPredictionForStudent($pdo, (string) $sid, $coeffs);
        }
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('predictions_model', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$version]);
    } catch (PDOException $e) { /* settings table missing: nothing to do */ }
}

/** From history to now: the attrition rate of each earlier school year, and for the current one how many students are at risk right now. */
function historyToNow(PDO $pdo, string $currentAy): array {
    $att = attritionStats($pdo);
    $rows = [];
    foreach ($att as $ay => $v) {
        if ($ay >= $currentAy) { continue; }
        $left = $v['du'] + $v['hd'];
        $rows[] = ['ay' => $ay, 'rate' => $v['total'] ? $left / $v['total'] * 100 : 0.0, 'n' => $left, 'total' => $v['total'], 'now' => false];
    }
    $risk = riskSnapshot($pdo, $currentAy, true);
    $atRisk = $risk['High'] + $risk['Moderate'];
    $cur = $att[$currentAy] ?? ['total' => 0, 'du' => 0, 'hd' => 0];
    $rows[] = ['ay' => $currentAy, 'rate' => $risk['total'] ? $atRisk / $risk['total'] * 100 : 0.0, 'n' => $atRisk, 'total' => $risk['total'], 'now' => true,
               'left' => $cur['du'] + $cur['hd']];
    return $rows;
}

/** The students who left in a school year (dropped unofficially or honorably dismissed), with the reason — the data behind a point of the attrition line. */
function attritionStudents(PDO $pdo, string $ay): array {
    try {
        $q = $pdo->prepare("SELECT s.student_id, s.full_name, c.case_type, c.reason, c.reason_other, c.semester FROM student_cases c
                            INNER JOIN students s ON s.student_id = c.student_id WHERE c.academic_year = ? AND c.case_type IN ('du', 'hd') ORDER BY s.full_name");
        $q->execute([$ay]);
        return $q->fetchAll();
    } catch (PDOException $e) { return []; }
}
