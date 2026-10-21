<?php
/**
 * Academic constants shared by every page.
 * The study covers BSIT only, so there is no program dropdown anywhere —
 * students are told apart by Section and Year Level instead.
 */
const PROGRAM_NAME = 'BSIT';

// School years offered in the filters (history 2023-2026, plus the current school year).
const AY_OPTIONS = ['2027-2028', '2026-2027', '2025-2026', '2024-2025', '2023-2024'];

// School years a Faculty can pick when entering a year of a student's history.
// 2022-2023 is included only because a 4th year student's 1st year was back then.
const AY_ENTRY_OPTIONS = ['2022-2023', '2023-2024', '2024-2025', '2025-2026', '2026-2027', '2027-2028'];

// A subject counts as "failed" below this grade (raw scale 0-100). Change it here if UDM CCS uses another passing mark.
const FAILING_GRADE_THRESHOLD = 75;

// Student status. 'dropped' is stored as before but shown as "Dropped (Unofficial)".
const STUDENT_STATUSES = [
    'enrolled'  => 'Enrolled',
    'loa'       => 'Leave of Absence (LOA)',
    'hd'        => 'Honorable Dismissal (HD)',
    'dropped'   => 'Dropped (Unofficial)',
    'shifted'   => 'Shifted',
    'graduated' => 'Graduated',
];
const STATUS_COLORS = [
    'enrolled'  => '#0e9aa7',
    'loa'       => '#4f86e8',
    'hd'        => '#d98a00',
    'dropped'   => '#e53935',
    'shifted'   => '#7c83e0',
    'graduated' => '#2d5f8b',
];

// These statuses are NOT typed in on the Students page — they come from a request filed under Reports → Requests.
const CASE_STATUSES  = ['loa', 'hd', 'dropped', 'shifted'];
const CASE_SEMESTERS = ['1st Semester', '2nd Semester', 'Whole Year'];
/** Who records what: the Program Head fills in the form for these two; Shifted is set on the Students page (no form); the professor records Dropped (Unofficially). */
const REQUEST_FORM_TYPES = ['loa', 'hd'];
const DU_DEFAULT_REASON = 'Stopped attending / cannot be contacted';

/**
 * The paper forms, now online: Leave of Absence, Honorable Dismissal, Dropped (Unofficial), Shifted.
 * Every list ends with "Others" — the person fills in their own words, but graphs only ever show "Others";
 * clicking it opens what each student wrote.
 */
const CASE_TYPES = [
    'loa' => [
        'label' => 'Leave of Absence (LOA)', 'short' => 'LOA', 'status' => 'loa',
        'icon' => 'fa-person-walking-luggage', 'color' => '#7a5ec7', 'bg' => '#f1ecfb',
        'reasons' => ['Financial difficulties', 'Health / medical condition', 'Mental health / emotional wellness', 'Family problems or emergency',
                      'Working / employment', 'Pregnancy or childcare', 'Relocation / moved away', 'Personal reasons', 'Academic difficulties', 'Others'],
    ],
    'hd' => [
        'label' => 'Honorable Dismissal (HD)', 'short' => 'HD', 'status' => 'hd',
        'icon' => 'fa-file-signature', 'color' => '#d98a00', 'bg' => '#fff3dc',
        'reasons' => ['Transferring to another school', 'Transferring abroad', 'Financial difficulties', 'Family relocation',
                      'Health / medical condition', 'Personal reasons', 'Others'],
    ],
    'du' => [
        'label' => 'Dropped (Unofficial)', 'short' => 'DU', 'status' => 'dropped',
        'icon' => 'fa-user-slash', 'color' => '#e53935', 'bg' => '#fdeaea',
        'reasons' => ['Financial difficulties', 'Working / employment', 'Lost interest / changed goals', 'Health / medical condition',
                      'Mental health / emotional wellness', 'Family problems or emergency', 'Academic difficulties', 'Pregnancy or childcare',
                      'Stopped attending / cannot be contacted', 'Others'],
    ],
    'shifted' => [
        'label' => 'Shifted to Another Program', 'short' => 'Shifted', 'status' => 'shifted',
        'icon' => 'fa-right-left', 'color' => '#0798b5', 'bg' => '#e6f6fa',
        'reasons' => ['Changed interest / goals', 'Academic difficulties', 'Financial difficulties', 'Advice of family',
                      'Preferred program offered elsewhere', 'Others'],
    ],
];

/** "Others: what the student wrote" or the chosen reason. */
function caseReasonText(array $c): string {
    return $c['reason'] === 'Others' ? 'Others: ' . ($c['reason_other'] ?? '') : $c['reason'];
}

function yearLabel(int $n): string {
    return [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'][$n] ?? ('Year ' . $n);
}

// ------------------------------------------------------------------ student names: "Last Name, First Name M."
/** Splits a name written either way ("Dela Cruz, Juan M." or "Juan M. Dela Cruz") into ['last' =>, 'first' =>, 'mi' =>]. */
function splitStudentName(string $raw): array {
    $raw = trim((string) preg_replace('/\s+/', ' ', $raw));
    if ($raw === '') { return ['last' => '', 'first' => '', 'mi' => '']; }
    if (strpos($raw, ',') !== false) {
        $parts = explode(',', $raw, 2);
        $last = trim($parts[0]);
        $rest = trim($parts[1]);
        $tokens = $rest === '' ? [] : explode(' ', $rest);
    } else {
        $tokens = explode(' ', $raw);
        $particles = ['de', 'del', 'dela', 'delos', 'delas', 'la', 'las', 'los', 'san', 'santa', 'santo', 'van', 'von', 'da', 'di'];
        $lastTokens = [array_pop($tokens)];
        while (count($tokens) > 1 && in_array(strtolower(rtrim(end($tokens), '.')), $particles, true)) {
            array_unshift($lastTokens, array_pop($tokens));
        }
        $last = implode(' ', $lastTokens);
    }
    $mi = '';
    if (count($tokens) > 1 && preg_match('/^[A-Za-z]\.?$/', end($tokens))) {
        $mi = strtoupper(substr((string) array_pop($tokens), 0, 1));
    }
    return ['last' => $last, 'first' => implode(' ', $tokens), 'mi' => $mi];
}

function formatStudentName(string $last, string $first, string $mi = ''): string {
    $last = trim($last); $first = trim($first);
    $mi = strtoupper(substr(trim($mi), 0, 1));
    if ($first === '') { return $last; }
    if ($last === '')  { return $first; }
    return $last . ', ' . $first . ($mi !== '' ? ' ' . $mi . '.' : '');
}

/** Any spelling of a name -> "Last Name, First Name M." (this is how every student is stored, so lists sort by last name). */
function normalizeStudentName(string $raw): string {
    $p = splitStudentName($raw);
    return formatStudentName($p['last'], $p['first'], $p['mi']);
}


/** JSON that is safe to drop inside an HTML attribute or a <script> block. */
function jsonAttr($value): string {
    return json_encode($value, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
}


// ------------------------------------------------------------------ grading scale
// The CCS Preliminary Enrolment Form (PEF) uses a 4.00 scale (4.00 = highest, 0.25 steps, 1.00 = lowest pass).
// The risk model works on percentages, so every PEF grade is converted when it is imported:
//     1.00 = 75%  ...  4.00 = 100%   (straight line)      0.00 – 0.99 = 0% – 74%  (failing)
// !! This conversion is an ASSUMPTION — replace the formula below with the official UDM grading table if it differs.
function gradeToPercent(float $g): float {
    if ($g < 1.0) { return round($g * 75.0, 2); }
    return round(75.0 + ($g - 1.0) * (25.0 / 3.0), 2);
}
/** "REYES, JOULAND CYREL G." -> "Reyes, Jouland Cyrel G." (names typed in capitals are tidied; mixed-case names are left alone). */
function tidyStudentName(string $name): string {
    $name = trim($name);
    if ($name !== '' && $name === mb_strtoupper($name, 'UTF-8')) {
        $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
        $name = preg_replace_callback("/(['’])([a-z])/u", function ($m) { return $m[1] . mb_strtoupper($m[2], 'UTF-8'); }, $name);
    }
    return $name;
}

/** Grades are kept to a whole number or .5 (no 68.33%): the nearest half. */
function roundHalf(float $v): float { return round($v * 2) / 2; }
/** A grade as it is shown: 85 or 85.5 — never 85.0 or 85.33. */
function fmtGrade(?float $v): string {
    if ($v === null) { return '—'; }
    $v = roundHalf($v);
    return $v == floor($v) ? (string) (int) $v : number_format($v, 1, '.', '');
}
/** Scores and items may only be whole numbers or .5. */
function isHalfStep($v): bool { return is_numeric($v) && abs(fmod((float) $v * 2, 1.0)) < 1e-9; }
