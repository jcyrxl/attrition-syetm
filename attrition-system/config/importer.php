<?php
/**
 * Reads the CCS "Preliminary Enrolment Form" (PEF) — ONE Excel file per student.
 *
 * The form has two parts and both are used:
 *   1. EVALUATION            — the semester that just ended: Subject Code, Subject Title, Credit(s), FINAL GRADE
 *                              (these become the student's grades, and the risk is predicted from them)
 *   2. SUBJECT(S) TO BE ENROLLED — the new semester: semester, school year, SECTION (e.g. IT42 = BSIT-42), subjects
 *                              (this puts the student in the new school year's list; no grades exist yet)
 * Also read: Student's Name (Last Name, First Name, M.I.), Student No., and the form's own GENERAL WEIGHTED AVERAGE
 * (used only as a cross-check). The sheet "SubjCode Reference Tab" tells which year level / semester each subject code belongs to,
 * which is how the year level of the evaluated semester is found (the form does not say it).
 * The form has no attendance — attendance is assumed to be 100% — and no block for the evaluated semester (the new section's block is assumed).
 *
 * Grades are on the 4.00 scale and are converted to percentages (gradeToPercent() in academic.php).
 */
require_once __DIR__ . '/academic.php';
require_once __DIR__ . '/grades.php';        // the enrolled subjects become classes

const IMPORT_MAX_FILES = 40;
const IMPORT_MAX_BYTES = 3 * 1024 * 1024;

function importNorm(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return rtrim($s, '.: ');
}

function importColIndex(string $ref): int {
    if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) { return 0; }
    $n = 0;
    foreach (str_split(strtoupper($m[1])) as $ch) { $n = $n * 26 + (ord($ch) - 64); }
    return $n - 1;
}

function importXlsxSheetRows(ZipArchive $zip, string $sheetName, array $shared): array {
    $stat = $zip->statName($sheetName);
    if ($stat && $stat['size'] > 20 * 1024 * 1024) { throw new RuntimeException('That worksheet is too large to import.'); }
    $xml = $zip->getFromName($sheetName);
    if ($xml === false) { return []; }
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET)) { return []; }

    $rows = [];
    $autoRow = 0;
    foreach ($dom->getElementsByTagNameNS('*', 'row') as $rowEl) {
        $autoRow++;
        $rowNo = (int) $rowEl->getAttribute('r');
        if ($rowNo <= 0) { $rowNo = $autoRow; }

        $cells = [];
        $nextCol = 0;
        foreach ($rowEl->getElementsByTagNameNS('*', 'c') as $c) {
            $ref = $c->getAttribute('r');
            $col = $ref !== '' ? importColIndex($ref) : $nextCol;
            $nextCol = $col + 1;
            $type = $c->getAttribute('t');

            $val = '';
            if ($type === 'inlineStr') {
                foreach ($c->getElementsByTagNameNS('*', 't') as $t) { $val .= $t->textContent; }
            } else {
                $vEls = $c->getElementsByTagNameNS('*', 'v');
                $raw = $vEls->length ? $vEls->item(0)->textContent : '';
                if ($type === 's')      { $val = $shared[(int) $raw] ?? ''; }
                elseif ($type === 'b')  { $val = $raw === '1' ? 'TRUE' : 'FALSE'; }
                else                    { $val = $raw; }
            }
            $cells[$col] = trim($val);
        }
        if (!$cells || implode('', $cells) === '') { continue; }

        $line = array_fill(0, max(array_keys($cells)) + 1, '');
        foreach ($cells as $k => $v) { $line[$k] = $v; }
        $rows[] = ['n' => $rowNo, 'cells' => $line];
    }
    return $rows;
}

function importNamesMatch(string $a, string $b): bool {
    $tok = function (string $s): array {
        return array_filter(preg_split('/[^a-z0-9]+/', strtolower($s)));
    };
    return (bool) array_intersect($tok($a), $tok($b));
}

function importNameKey(string $name): string {
    $t = array_values(array_unique(array_filter(preg_split('/[^a-z0-9]+/', strtolower($name)))));
    sort($t);
    return implode(' ', $t);
}

/** Empty -> null, not a number -> false, otherwise the number. */
function importNumber(string $text) {
    $t = trim(str_replace([',', '%'], '', $text));
    if ($t === '') { return null; }
    return is_numeric($t) ? (float) $t : false;
}

/** Student numbers look like 23-22-028 or 18-40ET-023 — keep the dashes, only remove spaces and use capitals. */
function importCleanId(string $v): string {
    return strtoupper(preg_replace('/\s+/', '', trim($v)));
}
function importValidId(string $id): bool {
    return (bool) preg_match('/^[A-Z0-9][A-Z0-9-]{2,19}$/', $id) && (bool) preg_match('/\d/', $id);
}

/** Reads the named worksheets of an .xlsx file: ['PEF' => rows, ...]; each row = ['n' => row number, 'cells' => [col => text]]. */
function importReadXlsxSheets(string $path, array $wanted): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Excel files need the PHP "zip" extension, which isn\'t on. Turn on extension=zip in php.ini and restart Apache.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('That Excel file could not be opened. Is it a real .xlsx file?');
    }
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $dom = new DOMDocument();
        if (@$dom->loadXML($ssXml, LIBXML_NONET)) {
            foreach ($dom->getElementsByTagNameNS('*', 'si') as $si) {
                $text = '';
                foreach ($si->getElementsByTagNameNS('*', 't') as $t) { $text .= $t->textContent; }
                $shared[] = $text;
            }
        }
    }
    $map = [];                                     // sheet name -> file inside the zip
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $targets = [];
        $rd = new DOMDocument();
        if (@$rd->loadXML($rels, LIBXML_NONET)) {
            foreach ($rd->getElementsByTagNameNS('*', 'Relationship') as $rel) {
                $t = ltrim($rel->getAttribute('Target'), '/');
                if (strpos($t, 'xl/') !== 0) { $t = 'xl/' . $t; }
                $targets[$rel->getAttribute('Id')] = $t;
            }
        }
        $wd = new DOMDocument();
        if (@$wd->loadXML($wb, LIBXML_NONET)) {
            foreach ($wd->getElementsByTagNameNS('*', 'sheet') as $sh) {
                $rid = $sh->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                $map[strtolower(trim($sh->getAttribute('name')))] = $targets[$rid] ?? null;
            }
        }
    }
    $out = [];
    if ($wanted === ['*']) { $first = reset($map); $out['*'] = $first ? importXlsxSheetRows($zip, $first, $shared) : []; $zip->close(); return $out; }   // the first worksheet, whatever its name
    foreach ($wanted as $name) {
        $file = $map[strtolower($name)] ?? null;
        $out[$name] = $file ? importXlsxSheetRows($zip, $file, $shared) : [];
    }
    $zip->close();
    return $out;
}

/** "Second Semester" / "2nd Sem" -> 2 ; 0 when it can't be told. */
function importSemesterNumber(string $text): int {
    if (preg_match('/first|1st/i', $text))  { return 1; }
    if (preg_match('/second|2nd/i', $text)) { return 2; }
    return 0;
}
function importSchoolYear(string $text): string {
    return preg_match('/(\d{4})\s*[-–]\s*(\d{4})/', $text, $m) ? $m[1] . '-' . $m[2] : '';
}

/**
 * Reads ONE PEF into a record (see the top of this file). Content problems are NOT thrown — they are listed by importPefCheck()
 * so the Faculty can correct them on screen; only an unreadable file throws.
 */
function importParsePef(string $tmpPath, string $fileName): array {
    if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'xlsx') {
        throw new RuntimeException('"' . $fileName . '" is not an .xlsx file. Only the Excel PEF can be imported (photos and scans can\'t be read).');
    }
    $sheets = importReadXlsxSheets($tmpPath, ['PEF', 'SubjCode Reference Tab']);
    $rows = $sheets['PEF'];
    if (!$rows) {
        throw new RuntimeException('"' . $fileName . '" has no sheet named PEF — is it the Preliminary Enrolment Form?');
    }
    $byRow = [];
    foreach ($rows as $r) { $byRow[$r['n']] = $r['cells']; }
    $cell = function (int $n, int $c) use ($byRow): string { return trim((string) ($byRow[$n][$c] ?? '')); };

    $rec = ['file' => $fileName, 'student_id' => '', 'name' => '', 'course' => '',
            // the semester that just ended (its grades)
            'ay' => '', 'year' => 0, 'semester' => 0, 'block' => 0, 'subjects' => [], 'pef_gwa' => '', 'standing' => '',
            // the semester being enrolled in (no grades yet)
            'e_ay' => '', 'e_year' => 0, 'e_semester' => 0, 'e_block' => 0, 'e_section' => '', 'e_subjects' => [],
            'warnings' => [], 'errors' => [], 'grades' => []];

    $phase = 'eval';
    $semTxt = ['eval' => ['', ''], 'enrol' => ['', '']];
    $hdr = ['eval' => null, 'enrol' => null];
    $cols = ['eval' => ['code' => 0, 'title' => 1, 'credits' => 4, 'grade' => 5], 'enrol' => ['code' => 0, 'title' => 1, 'credits' => 6]];

    foreach ($rows as $r) {
        $n = $r['n'];
        $a = (string) ($r['cells'][0] ?? '');
        if (stripos($a, 'TO BE ENROLLED') !== false) { $phase = 'enrol'; continue; }
        if ($phase === 'eval' && stripos($a, 'Name') !== false && stripos($a, 'Student') !== false && strpos($a, '(') === false) { $rec['name'] = $cell($n + 1, 0); }
        elseif (stripos($a, '(Student No') === 0) { $rec['student_id'] = importCleanId($cell($n - 1, 0)); }
        elseif (stripos($a, '(College') === 0)    { $rec['course'] = $cell($n - 1, 2); }
        elseif (stripos($a, '(Semester') === 0)   { $semTxt[$phase] = [$cell($n - 1, 0), $cell($n - 1, 2)]; }
        elseif (stripos($a, '(Section') === 0)    { $rec['e_section'] = $cell($n - 1, 0); }
        elseif (strcasecmp(trim($a), 'Subject Code') === 0 && $hdr[$phase] === null) {
            $hdr[$phase] = $n;
            foreach ($r['cells'] as $ci => $txt) {
                if (stripos($txt, 'Subject Title') !== false)                         { $cols[$phase]['title'] = $ci; }
                elseif (stripos($txt, 'Credit') === 0 || stripos($txt, 'Unit') === 0) { $cols[$phase]['credits'] = $ci; }
                elseif (stripos($txt, 'Final Grade') !== false)                       { $cols[$phase]['grade'] = $ci; }
            }
        }
        if ($phase === 'eval') {
            foreach ($r['cells'] as $ci => $txt) {
                if (stripos($txt, 'GENERAL WEIGHTED AVERAGE') !== false) {
                    for ($k = $ci + 1; $k < count($r['cells']); $k++) { if (trim($r['cells'][$k]) !== '') { $rec['pef_gwa'] = trim($r['cells'][$k]); break; } }
                } elseif (stripos($txt, 'ACADEMIC STANDING') !== false && stripos($txt, 'PREVIOUS') === false) {
                    for ($k = $ci + 1; $k < count($r['cells']); $k++) { if (trim($r['cells'][$k]) !== '') { $rec['standing'] = trim($r['cells'][$k]); break; } }
                }
            }
        }
    }

    // the two subject tables: from each header down to the first empty Subject Code
    if ($hdr['eval'] !== null) {
        for ($n = $hdr['eval'] + 1; $n < $hdr['eval'] + 40; $n++) {
            $code = $cell($n, $cols['eval']['code']);
            if ($code === '' || stripos($code, 'GENERAL') !== false) { break; }
            $rec['subjects'][] = ['code' => strtoupper($code), 'title' => $cell($n, $cols['eval']['title']), 'credits' => importNumber($cell($n, $cols['eval']['credits'])),
                                  'raw' => $cell($n, $cols['eval']['grade']), 'percent' => null, 'problem' => null];
        }
    }
    if ($hdr['enrol'] !== null) {
        for ($n = $hdr['enrol'] + 1; $n < $hdr['enrol'] + 40; $n++) {
            $code = $cell($n, $cols['enrol']['code']);
            if ($code === '' || stripos($code, 'Total') !== false) { break; }
            $rec['e_subjects'][] = ['code' => strtoupper($code), 'title' => $cell($n, $cols['enrol']['title']), 'units' => importNumber($cell($n, $cols['enrol']['credits']))];
        }
    }

    // semester + school year as written
    $rec['semester'] = importSemesterNumber($semTxt['eval'][0]);   $rec['ay']   = importSchoolYear($semTxt['eval'][1]);
    $rec['e_semester'] = importSemesterNumber($semTxt['enrol'][0]); $rec['e_ay'] = importSchoolYear($semTxt['enrol'][1]);

    // which year level / semester does each subject code belong to?
    $ref = [];
    foreach ($sheets['SubjCode Reference Tab'] as $r) {
        if (preg_match('/^([1-4])-([1-3])$/', trim((string) ($r['cells'][0] ?? '')), $m) && trim((string) ($r['cells'][1] ?? '')) !== '') {
            $ref[strtoupper(trim($r['cells'][1]))] = [(int) $m[1], (int) $m[2]];
        }
    }
    $vote = function (array $subjects) use ($ref): array {
        $yv = []; $sv = [];
        foreach ($subjects as $s) {
            if (isset($ref[$s['code']])) { $yv[$ref[$s['code']][0]] = ($yv[$ref[$s['code']][0]] ?? 0) + 1; $sv[$ref[$s['code']][1]] = ($sv[$ref[$s['code']][1]] ?? 0) + 1; }
            elseif (!$ref && preg_match('/^(?:ITE|CY|DS)([1-4])\d\d$/', $s['code'], $m)) { $yv[(int) $m[1]] = ($yv[(int) $m[1]] ?? 0) + 1; }
        }
        $year = 0;
        if ($yv) { $best = max($yv); $year = (int) max(array_keys(array_filter($yv, function ($v) use ($best) { return $v === $best; }))); }
        $sem = 0;
        if ($sv) { arsort($sv); $sem = (int) array_key_first($sv); }
        return [$year, $sem];
    };

    // evaluated semester: year level from its subjects
    [$yr, $sm] = $vote($rec['subjects']);
    $rec['year'] = $yr;
    if ($rec['semester'] === 0 && $sm >= 1 && $sm <= 2) { $rec['semester'] = $sm; }
    elseif ($sm >= 1 && $sm <= 2 && $sm !== $rec['semester']) {
        $rec['warnings'][] = 'The evaluated subjects belong to the ' . ($sm === 1 ? '1st' : '2nd') . ' semester, but the form says ' . ($rec['semester'] === 1 ? '1st' : '2nd') . ' semester — please check.';
    }
    // new semester: the section ("IT42" = 4th year, block 2) says the year level and block
    if (preg_match('/^(?:BS)?IT\s*-?\s*([1-4])\s*-?\s*([1-8])$/i', trim($rec['e_section']), $m)) { $rec['e_year'] = (int) $m[1]; $rec['e_block'] = (int) $m[2]; }
    elseif ($rec['e_subjects']) { [$rec['e_year']] = $vote($rec['e_subjects']); }
    // the form does not say the block of the evaluated semester — assume the same block as the new section (can be changed)
    if ($rec['block'] === 0 && $rec['e_block'] > 0) { $rec['block'] = $rec['e_block']; }

    $rec['name'] = tidyStudentName($rec['name']);
    return $rec;
}

/** What is missing or wrong with one PEF (run again after every edit). $seen finds the same student twice in one upload. */
function importPefCheck(array &$rec, array &$seen): void {
    $errors = [];
    $id = (string) $rec['student_id'];
    if ($id === '')                      { $errors[] = 'Missing Student No.'; }
    elseif (!importValidId($id))         { $errors[] = 'Student No. "' . $id . '" does not look right (example: 23-22-028).'; }
    if (trim((string) $rec['name']) === '') { $errors[] = 'Missing student name.'; }

    $hasEval  = count($rec['subjects']) > 0;
    $hasEnrol = $rec['e_ay'] !== '' || $rec['e_section'] !== '' || count($rec['e_subjects']) > 0;
    if (!$hasEval && !$hasEnrol) { $errors[] = 'No subjects were found on the form.'; }

    if ($hasEval) {                       // the semester that just ended
        if (!in_array($rec['ay'], AY_ENTRY_OPTIONS, true))        { $errors[] = 'Evaluated school year "' . $rec['ay'] . '" is missing or not accepted.'; }
        if ((int) $rec['year'] < 1 || (int) $rec['year'] > 4)     { $errors[] = 'The year level of the evaluated semester could not be found — choose it.'; }
        if (!in_array((int) $rec['semester'], [1, 2], true))      { $errors[] = 'The evaluated semester is missing or not 1st / 2nd — choose it.'; }
        if ((int) $rec['block'] < 1 || (int) $rec['block'] > 8)   { $errors[] = 'Choose the block of the evaluated semester (1 to 8).'; }
    }
    if ($hasEnrol) {                      // the semester being enrolled in
        if (!in_array($rec['e_ay'], AY_ENTRY_OPTIONS, true))      { $errors[] = 'Enrolment school year "' . $rec['e_ay'] . '" is missing or not accepted.'; }
        if ((int) $rec['e_year'] < 1 || (int) $rec['e_year'] > 4) { $errors[] = 'The year level / section of the new semester could not be found — choose it.'; }
        if (!in_array((int) $rec['e_semester'], [1, 2], true))    { $errors[] = 'The new semester is missing or not 1st / 2nd — choose it.'; }
        if ((int) $rec['e_block'] < 1 || (int) $rec['e_block'] > 8) { $errors[] = 'Choose the block (section) of the new semester (1 to 8).'; }
    }

    $grades = []; $weighted = 0.0; $credits = 0.0; $titles = [];
    $rec['warnings'] = array_values(array_filter($rec['warnings'], function ($w) { return strpos($w, 'GWA on the form') !== 0 && strpos($w, ' was read as a failing') === false; }));
    foreach ($rec['subjects'] as $i => $s) {
        $g = importNumber((string) $s['raw']);
        $rec['subjects'][$i]['percent'] = null;
        $rec['subjects'][$i]['problem'] = null;
        $label = $s['code'] . ' ' . $s['title'];
        if ($g === null)  { $rec['subjects'][$i]['problem'] = 'no grade';                         $errors[] = $label . ': no grade — type it.'; continue; }
        if ($g === false) { $rec['subjects'][$i]['problem'] = '"' . $s['raw'] . '" is not a grade'; $errors[] = $label . ': "' . $s['raw'] . '" is not a grade — type the number.'; continue; }
        if ($g < 0 || ($g > 4.0 && $g != 5.0)) { $rec['subjects'][$i]['problem'] = $g . ' is outside 0 – 4.00'; $errors[] = $label . ': ' . $g . ' is outside the 0 – 4.00 scale.'; continue; }
        if ($g == 5.0) { $percent = 60.0; $rec['warnings'][] = $label . ': 5.00 was read as a failing grade.'; }   // an old-style 5.00 = failed
        else           { $percent = gradeToPercent($g); }
        $rec['subjects'][$i]['percent'] = $percent;
        $title = $s['title'] !== '' ? $s['title'] : $s['code'];
        if (isset($titles[$title])) { $title .= ' (' . $s['code'] . ')'; }
        $titles[$title] = true;
        $grades[$title] = $percent;
        if ($s['credits']) { $weighted += min($g, 4.0) * $s['credits']; $credits += $s['credits']; }
    }
    $rec['grades'] = $grades;
    if ($credits > 0 && is_numeric($rec['pef_gwa']) && abs($weighted / $credits - (float) $rec['pef_gwa']) > 0.06) {
        $rec['warnings'][] = 'GWA on the form (' . $rec['pef_gwa'] . ') does not match its grades (' . number_format($weighted / $credits, 2) . ') — please check the grades.';
    }

    if (!$errors && $id !== '') {
        $key = $id . '|' . ($hasEval ? $rec['ay'] . '|' . $rec['semester'] : $rec['e_ay'] . '|enrol');
        if (isset($seen[$key])) { $errors[] = 'Another file in this upload is the same student and semester (' . $seen[$key] . ').'; }
        else { $seen[$key] = $rec['file']; }
    }
    $rec['errors'] = $errors;
}

/** Applies what was typed into a record's boxes. */
function importPefApplyEdit(array &$rec, array $e): void {
    if (isset($e['student_id'])) { $rec['student_id'] = importCleanId((string) $e['student_id']); }
    if (isset($e['name']))       { $rec['name'] = trim((string) $e['name']); }
    foreach (['year', 'block', 'semester', 'e_year', 'e_block', 'e_semester'] as $k) { if (isset($e[$k])) { $rec[$k] = (int) $e[$k]; } }
    foreach (['ay', 'e_ay'] as $k) { if (isset($e[$k])) { $rec[$k] = trim((string) $e[$k]); } }
    if (isset($e['g']) && is_array($e['g'])) {
        foreach ($e['g'] as $i => $v) { if (isset($rec['subjects'][(int) $i])) { $rec['subjects'][(int) $i]['raw'] = trim((string) $v); } }
    }
}

/**
 * Returns a function that saves ONE checked PEF:
 *   the evaluated semester -> subject grades (as percentages), that year's GWA / attendance / units failed, its section;
 *   the new semester       -> a record for the new school year (section only, no grades yet);
 *   the student's current year / school year.
 * Result: ['ok' => bool, 'reason' => string, 'new' => bool, 'student_id', 'name', 'subjects', 'status'].
 */
function importMakeSaver(PDO $pdo, bool $addNew): Closure {
    $find        = $pdo->prepare("SELECT student_id, full_name, year_level, academic_year, status FROM students WHERE student_id = ?");
    $conflict    = $pdo->prepare("SELECT year_level, academic_year FROM student_records WHERE student_id = ?
                                  AND ((academic_year = ? AND year_level <> ?) OR (year_level = ? AND academic_year <> ?)) LIMIT 1");
    $delSub      = $pdo->prepare("DELETE FROM subject_records WHERE student_id = ? AND year_level = ? AND semester = ?");
    $insSub      = $pdo->prepare("INSERT INTO subject_records (student_id, year_level, semester, subject_name, grade, attendance_rate) VALUES (?,?,?,?,?,?)");
    $allSub      = $pdo->prepare("SELECT grade, attendance_rate FROM subject_records WHERE student_id = ? AND year_level = ?");
    $upYear      = $pdo->prepare("INSERT INTO student_records (student_id, year_level, academic_year, section, gwa, attendance_rate, units_failed)
                                  VALUES (?,?,?,?,?,?,?)
                                  ON DUPLICATE KEY UPDATE academic_year = VALUES(academic_year), section = VALUES(section), gwa = VALUES(gwa),
                                                          attendance_rate = VALUES(attendance_rate), units_failed = VALUES(units_failed)");
    // enrolment only: never wipe grades that are already there
    $upEnrol     = $pdo->prepare("INSERT INTO student_records (student_id, year_level, academic_year, section, gwa, attendance_rate, units_failed)
                                  VALUES (?,?,?,?,NULL,NULL,NULL)
                                  ON DUPLICATE KEY UPDATE academic_year = VALUES(academic_year), section = VALUES(section)");
    $insStudent  = $pdo->prepare("INSERT INTO students (student_id, full_name, program, gender, age, year_level, academic_year, status) VALUES (?,?,?,?,?,?,?,?)");
    $updStanding = $pdo->prepare("UPDATE students SET year_level = ?, academic_year = ? WHERE student_id = ?");
    $delSS       = $pdo->prepare("DELETE FROM student_subjects WHERE student_id = ? AND academic_year = ? AND semester = ?");
    $insSS       = $pdo->prepare("INSERT INTO student_subjects (student_id, academic_year, semester, subject_code, subject_name) VALUES (?,?,?,?,?)");
    $nameIndex = [];
    foreach ($pdo->query("SELECT student_id, full_name FROM students") as $ns) { $nameIndex[importNameKey($ns['full_name'])] = $ns; }

    return function (array $r) use ($pdo, $addNew, $find, $conflict, $delSub, $insSub, $allSub, $upYear, $upEnrol, $insStudent, $updStanding, $delSS, $insSS, &$nameIndex): array {
        $fail = function (string $why): array { return ['ok' => false, 'reason' => $why]; };
        $sid = (string) $r['student_id'];
        $hasEval  = count($r['subjects']) > 0;
        $hasEnrol = $r['e_ay'] !== '' || $r['e_section'] !== '' || count($r['e_subjects']) > 0;
        $curYear = $hasEnrol ? (int) $r['e_year'] : (int) $r['year'];
        $curAy   = $hasEnrol ? $r['e_ay'] : $r['ay'];

        $find->execute([$sid]);
        $stu = $find->fetch();
        $isNew = false;
        if (!$stu) {
            $nk = importNameKey($r['name']);
            if ($nk !== '' && isset($nameIndex[$nk])) {
                return $fail('A student with this name already exists (Student No. ' . $nameIndex[$nk]['student_id'] . ') — is this Student No. a typo?');
            }
            if (!$addNew) { return $fail('Not in the system yet — tick "Add students who are not in the system yet", or correct the Student No.'); }
            $insStudent->execute([$sid, normalizeStudentName($r['name']), PROGRAM_NAME, 'Other', null, $curYear, $curAy, 'enrolled']);
            $stu = ['student_id' => $sid, 'full_name' => normalizeStudentName($r['name']), 'year_level' => $curYear, 'academic_year' => $curAy, 'status' => 'enrolled'];
            $nameIndex[$nk] = ['student_id' => $sid, 'full_name' => $stu['full_name']];
            $isNew = true;
        }

        // one year level per school year, and one school year per year level
        $pairs = [];
        if ($hasEval)  { $pairs[] = [(int) $r['year'], $r['ay']]; }
        if ($hasEnrol) { $pairs[] = [(int) $r['e_year'], $r['e_ay']]; }
        foreach ($pairs as [$py, $pay]) {
            $conflict->execute([$sid, $pay, $py, $py, $pay]);
            if ($cf = $conflict->fetch()) {
                return $fail(((int) $cf['year_level'] === $py)
                    ? 'Already has Year ' . $py . ' recorded for ' . $cf['academic_year'] . ', but this form says ' . $pay . '.'
                    : 'Already has Year ' . (int) $cf['year_level'] . ' recorded in ' . $pay . '.');
            }
        }

        $saved = 0;
        if ($hasEval) {
            $year = (int) $r['year']; $sem = (int) $r['semester'];
            $delSub->execute([$sid, $year, $sem]);                       // this semester's grades are replaced; other semesters stay
            foreach ($r['grades'] as $subject => $percent) { $insSub->execute([$sid, $year, $sem, (string) $subject, $percent, 100.0]); $saved++; }
            $allSub->execute([$sid, $year]);
            $subRows = $allSub->fetchAll();
            $gSum = 0; $aSum = 0; $failed = 0;
            foreach ($subRows as $x) {
                $gSum += (float) $x['grade']; $aSum += (float) $x['attendance_rate'];
                if ((float) $x['grade'] < FAILING_GRADE_THRESHOLD) { $failed++; }
            }
            $cnt = max(1, count($subRows));
            $upYear->execute([$sid, $year, $r['ay'], 'BSIT-' . $year . (int) $r['block'], round($gSum / $cnt, 2), round($aSum / $cnt, 2), $failed]);
        }
        if ($hasEnrol) {
            $upEnrol->execute([$sid, (int) $r['e_year'], $r['e_ay'], 'BSIT-' . (int) $r['e_year'] . (int) $r['e_block']]);
            // the subjects this student takes: each one is a class (made when it does not exist yet) and the student is put in it automatically
            $delSS->execute([$sid, $r['e_ay'], (int) $r['e_semester']]);
            $enrolSection = 'BSIT-' . (int) $r['e_year'] . (int) $r['e_block'];
            foreach ($r['e_subjects'] as $es) {
                $subTitle = mb_substr($es['title'] !== '' ? $es['title'] : $es['code'], 0, 100);
                $subCode  = $es['code'] !== '' ? mb_substr($es['code'], 0, 20) : null;
                $insSS->execute([$sid, $r['e_ay'], (int) $r['e_semester'], $subCode, $subTitle]);
                ensureClass($pdo, $subTitle, $subCode, $enrolSection, $r['e_ay'], (int) $r['e_semester']);
            }
        }
        // the student's current standing follows the newest term, never backward
        if ($curYear >= (int) $stu['year_level'] && $stu['status'] === 'enrolled') { $updStanding->execute([$curYear, $curAy, $sid]); }

        return ['ok' => true, 'new' => $isNew, 'student_id' => $sid, 'name' => $stu['full_name'], 'subjects' => $saved, 'status' => $stu['status']];
    };
}
