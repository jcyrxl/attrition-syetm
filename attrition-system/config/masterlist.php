<?php
/**
 * Reads a pasted (or uploaded .csv/.txt) class list — what a professor sees in the faculty portal or has in Excel — into students.
 * One student per line. Accepted shapes (a student number like 23-22-028 is needed, because it identifies the student):
 *     23-22-028   Dela Cruz, Juan A.
 *     23-22-028 [Tab] Dela Cruz [Tab] Juan [Tab] A        (columns copied from Excel)
 *     23-22-028, Dela Cruz, Juan, A                       (a CSV file)
 * A heading line, or a running number in front ("12.  23-22-028 ..."), is skipped.
 */
require_once __DIR__ . '/academic.php';

function parseMasterlistText(string $text): array {
    $rows = [];
    $seen = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $i => $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        $id = '';
        if (preg_match('/\b(\d{2}-\d{2}-\d{3,4})\b/', $line, $m)) { $id = $m[1]; $line = trim(str_replace($m[0], ' ', $line)); }
        if ($id === '' && preg_match('/student|surname|last\s*name|first\s*name|^name$/i', $line) && !preg_match('/\d/', $line)) { continue; }   // a heading
        $line = preg_replace('/^\s*\d{1,3}[.)]\s+/', '', $line);                    // "12. " numbering
        $line = trim($line, " \t,;|");
        if (preg_match('/[\t;|]/', $line))            { $cells = preg_split('/[\t;|]+/', $line); }
        elseif (substr_count($line, ',') >= 2)        { $cells = explode(',', $line); }
        else                                          { $cells = [$line]; }
        $cells = array_values(array_filter(array_map('trim', $cells), 'strlen'));
        $issue = '';
        if (count($cells) >= 2 && strpos($cells[0], ',') === false) {
            $raw = tidyStudentName($cells[0]) . ', ' . tidyStudentName($cells[1]) . (isset($cells[2]) ? ' ' . $cells[2] : '');
        } else {
            $raw = tidyStudentName(implode(' ', $cells));
        }
        $name = $raw === '' ? '' : normalizeStudentName($raw);
        if ($id === '')                                  { $issue = 'No student number (like 23-22-028).'; }
        elseif ($name === '' || strpos($name, ',') === false) { $issue = 'Write the name as Last Name, First Name.'; }
        elseif (isset($seen[$id]))                       { $issue = 'This student number is on the list twice.'; }
        if ($id !== '') { $seen[$id] = true; }
        $rows[] = ['line' => $i + 1, 'id' => $id, 'name' => $name, 'issue' => $issue];
    }
    return $rows;
}

/**
 * What would happen to each row for this class: 'new' (the student is created), 'existing' (already in the system: only put in the class),
 * 'in_class' (already here), 'conflict' (the name belongs to another student number), 'error' (the line can not be read).
 */
function classifyMasterlist(PDO $pdo, array $rows, int $classId): array {
    $byId   = $pdo->prepare("SELECT student_id, full_name FROM students WHERE student_id = ?");
    $byName = $pdo->prepare("SELECT student_id FROM students WHERE full_name = ? AND student_id <> ? LIMIT 1");
    $inCls  = $pdo->prepare("SELECT excluded FROM class_students WHERE class_id = ? AND student_id = ?");
    foreach ($rows as &$r) {
        if ($r['issue'] !== '') { $r['status'] = 'error'; continue; }
        $byId->execute([$r['id']]);
        $ex = $byId->fetch();
        if ($ex) {
            $inCls->execute([$classId, $r['id']]);
            $x = $inCls->fetchColumn();
            if ($x !== false && (int) $x === 0) { $r['status'] = 'in_class'; }
            else { $r['status'] = 'existing'; $r['name'] = $ex['full_name']; }
            continue;
        }
        $byName->execute([$r['name'], $r['id']]);
        if ($byName->fetchColumn()) { $r['status'] = 'conflict'; $r['issue'] = 'A student with this name already has another student number.'; continue; }
        $r['status'] = 'new';
    }
    unset($r);
    return $rows;
}
