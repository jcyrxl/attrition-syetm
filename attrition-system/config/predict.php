<?php
/**
 * Shared prediction logic. Included by students.php (auto-predict on
 * save) and prediction.php (manual/bulk re-run) so both use the exact
 * same scoring — never duplicate this math anywhere else.
 */

function getModelCoefficients(PDO $pdo): array {
    $settings = [];
    foreach ($pdo->query("SELECT setting_key, setting_value FROM model_settings") as $row) {
        $settings[$row['setting_key']] = (float) $row['setting_value'];
    }
    return [
        'intercept'   => $settings['intercept']        ?? 12.50,
        'coef_gwa'    => $settings['coef_gwa']          ?? -0.12,
        'coef_att'    => $settings['coef_attendance']   ?? -0.05,
        'coef_failed' => $settings['coef_units_failed'] ?? 0.60,
        'coef_trend'  => $settings['coef_trend']        ?? -0.04,
    ];
}

/**
 * Logistic Regression scoring function.
 * Grade scale used throughout the system: RAW numbers, 0-100 (e.g. 87, 89) —
 * not a converted GPA scale.
 * trend = latest_gwa - earliest_gwa on file (positive = improving).
 *
 * z = intercept + coef_gwa*gwa + coef_att*attendance + coef_failed*unitsFailed + coef_trend*trend
 * probability of attrition = 1 / (1 + e^-z)
 */
function computeRisk(float $gwa, float $attendance, int $unitsFailed, float $trend, array $c): array {
    $z = $c['intercept']
        + ($c['coef_gwa'] * $gwa)
        + ($c['coef_att'] * $attendance)
        + ($c['coef_failed'] * $unitsFailed)
        + ($c['coef_trend'] * $trend);

    $probability = 1 / (1 + exp(-$z));
    $probabilityPct = round($probability * 100, 2);

    if ($probabilityPct >= 70) {
        $riskLevel = 'High';
    } elseif ($probabilityPct >= 40) {
        $riskLevel = 'Moderate';
    } else {
        $riskLevel = 'Low';
    }

    return [$probabilityPct, $riskLevel];
}

/** How much the CURRENT school year counts compared with everything before it (0.7 = 70% current, 30% history). */
const CURRENT_WEIGHT = 0.70;

/**
 * The four numbers the risk is calculated from. They use ALL of the student's records:
 *   - the current year (the latest record that has any data — grades and attendance recorded in Classes, so far this semester, or the last imported semester)
 *   - every earlier year, even though it is already in History (a 4th-year student is judged on year 1 to year 4)
 * grades / attendance = 70% current + 30% average of the earlier years (just the current one when there is no history yet)
 * failed subjects     = failed in the current year + failed in all earlier years
 * trend               = current grades minus the average of the earlier years (negative = going down)
 * Returns ['gwa', 'att', 'failed', 'trend', 'years'] or null when there is nothing to score from.
 */
function studentFeatures(PDO $pdo, string $studentId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM student_records WHERE student_id = ? ORDER BY year_level ASC");
    $stmt->execute([$studentId]);
    $withData = array_values(array_filter($stmt->fetchAll(), function ($r) { return $r['gwa'] !== null || $r['attendance_rate'] !== null; }));
    if (!$withData) { return null; }

    $cur  = end($withData);
    $hist = array_values(array_filter($withData, function ($r) use ($cur) { return (int) $r['year_level'] < (int) $cur['year_level'] && $r['gwa'] !== null; }));

    $curG = $cur['gwa'] !== null ? (float) $cur['gwa'] : null;
    $curA = $cur['attendance_rate'] !== null ? (float) $cur['attendance_rate'] : null;
    $histG = $histA = null; $histFailed = 0;
    if ($hist) {
        $histG = array_sum(array_map(function ($r) { return (float) $r['gwa']; }, $hist)) / count($hist);
        $attVals = array_values(array_filter(array_map(function ($r) { return $r['attendance_rate'] !== null ? (float) $r['attendance_rate'] : null; }, $hist), function ($v) { return $v !== null; }));
        $histA = $attVals ? array_sum($attVals) / count($attVals) : null;
        foreach ($hist as $r) { $histFailed += (int) $r['units_failed']; }
    }
    $blend = function (?float $current, ?float $history) {
        if ($current !== null && $history !== null) { return CURRENT_WEIGHT * $current + (1 - CURRENT_WEIGHT) * $history; }
        return $current ?? $history;
    };
    $gwa = $blend($curG, $histG);
    if ($gwa === null) { return null; }                       // attendance alone is not enough to score
    $att = $blend($curA, $histA) ?? 100.0;
    return ['gwa' => $gwa, 'att' => $att, 'failed' => (int) $cur['units_failed'] + $histFailed,
            'trend' => ($curG !== null && $histG !== null) ? $curG - $histG : 0.0, 'years' => count($hist) + 1];
}

/**
 * Scores a student from the features above and stores the result in `predictions` (one row per student per day, updated as new
 * attendance / scores come in). A notification is created when the student BECOMES High risk.
 * Returns [probability, riskLevel] or null if there is nothing to score yet.
 */
function runPredictionForStudent(PDO $pdo, string $studentId, ?array $coefficients = null): ?array {
    $coefficients = $coefficients ?? getModelCoefficients($pdo);
    $f = studentFeatures($pdo, $studentId);
    if ($f === null) { return null; }

    [$prob, $risk] = computeRisk($f['gwa'], $f['att'], $f['failed'], $f['trend'], $coefficients);

    $last = $pdo->prepare("SELECT id, risk_level, DATE(predicted_at) = CURDATE() AS today FROM predictions WHERE student_id = ? ORDER BY predicted_at DESC, id DESC LIMIT 1");
    $last->execute([$studentId]);
    $prev = $last->fetch();

    if ($prev && (int) $prev['today'] === 1) {                // already scored today: update that row instead of piling up rows
        $pdo->prepare("UPDATE predictions SET probability = ?, risk_level = ?, model_version = ?, predicted_at = NOW() WHERE id = ?")
            ->execute([$prob, $risk, 'v2.0', $prev['id']]);
        $predictionId = (int) $prev['id'];
    } else {
        $pdo->prepare("INSERT INTO predictions (student_id, probability, risk_level, model_version) VALUES (?,?,?,?)")
            ->execute([$studentId, $prob, $risk, 'v2.0']);
        $predictionId = (int) $pdo->lastInsertId();
    }
    if ($risk === 'High' && (!$prev || $prev['risk_level'] !== 'High')) {
        $pdo->prepare("INSERT INTO notifications (student_id, prediction_id, probability, kind) VALUES (?,?,?,'risk')")->execute([$studentId, $predictionId, $prob]);
    }
    return [$prob, $risk];
}
