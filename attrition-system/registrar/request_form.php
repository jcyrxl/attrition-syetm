<?php
/**
 * The request form — Leave of Absence and Honorable Dismissal, recorded from the letter the student submitted to the College.
 * PROGRAM HEAD ONLY, always behind the PIN. (Shifted has no form: the Program Head classifies it on the Students page.
 * Dropped (Unofficially) is recorded by the professor, in the class.) The requests themselves are listed in Reports → Requests.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';

if (!canManageRequests()) { header('Location: ' . (isAdmin() ? 'users.php' : 'dashboard.php')); exit; }

$activePage = 'requests';
$flash = null;
if (!empty($_SESSION['req_flash'])) { $flash = $_SESSION['req_flash']; unset($_SESSION['req_flash']); }
function dateOk(string $d): bool { $x = DateTime::createFromFormat('Y-m-d', $d); return $x && $x->format('Y-m-d') === $d; }

// editing an existing request (only LOA / HD are edited here)
$editId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$edit = null;
if ($editId) {
    $q = $pdo->prepare("SELECT c.*, s.full_name, sr.section FROM student_cases c INNER JOIN students s ON s.student_id = c.student_id
                        LEFT JOIN student_records sr ON sr.student_id = s.student_id AND sr.year_level = s.year_level WHERE c.id = ?");
    $q->execute([$editId]);
    $edit = $q->fetch() ?: null;
    if (!$edit || !in_array($edit['case_type'], REQUEST_FORM_TYPES, true)) { header('Location: reports.php#requests'); exit; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $check = checkCredential($pdo, 'pin', $_POST['credential'] ?? '');
    if (!$check['ok']) {
        if (!empty($check['logout'])) { endSessionAndRedirectLocked(); }
        $flash = ['type' => 'error', 'msg' => $check['error']];
    } else {
        $studentId = trim($_POST['student_id'] ?? '');
        $type      = $_POST['case_type'] ?? '';
        $ay        = $_POST['ay'] ?? '';
        $sem       = $_POST['semester'] ?? '';
        $filedOn   = trim($_POST['filed_on'] ?? '');
        $reason    = $_POST['reason'] ?? '';
        $other     = trim($_POST['reason_other'] ?? '');
        $details   = trim($_POST['details'] ?? '');
        try {
            if (!in_array($type, REQUEST_FORM_TYPES, true)) { throw new RuntimeException('Please choose the type of request.'); }
            $st = $pdo->prepare("SELECT student_id, full_name, status FROM students WHERE student_id = ?");
            $st->execute([$studentId]);
            $stu = $st->fetch();
            if (!$stu) { throw new RuntimeException('Please choose a student from the list.'); }
            if (!in_array($ay, AY_ENTRY_OPTIONS, true)) { throw new RuntimeException('Please choose the school year.'); }
            if (!in_array($sem, CASE_SEMESTERS, true)) { throw new RuntimeException('Please choose the semester.'); }
            if (!dateOk($filedOn) || $filedOn > date('Y-m-d')) { throw new RuntimeException('Please enter the date the request was filed (it cannot be a future date).'); }
            if (!in_array($reason, CASE_TYPES[$type]['reasons'], true)) { throw new RuntimeException('Please choose a reason.'); }
            if ($reason === 'Others') {
                if ($other === '') { throw new RuntimeException('Please write the reason, since you chose "Others".'); }
                if (strlen($other) > 255) { throw new RuntimeException('The reason is too long (255 characters at most).'); }
            } else { $other = null; }
            if (strlen($details) > 500) { throw new RuntimeException('The remarks are too long (500 characters at most).'); }

            $recordedBy = $_SESSION['full_name'] ?? $_SESSION['user_id'];
            $pdo->beginTransaction();
            $stillOpen = true;
            if ($editId) {
                $cur = $pdo->prepare("SELECT returned_on FROM student_cases WHERE id = ?");
                $cur->execute([$editId]);
                $row = $cur->fetch();
                if (!$row) { throw new RuntimeException('That request could not be found.'); }
                $stillOpen = $row['returned_on'] === null;
                $pdo->prepare("UPDATE student_cases SET student_id=?, case_type=?, academic_year=?, semester=?, reason=?, reason_other=?, details=?, filed_on=? WHERE id=?")
                    ->execute([$studentId, $type, $ay, $sem, $reason, $other, $details !== '' ? $details : null, $filedOn, $editId]);
            } else {
                $pdo->prepare("INSERT INTO student_cases (student_id, case_type, academic_year, semester, reason, reason_other, details, filed_on, recorded_by, recorded_by_user) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$studentId, $type, $ay, $sem, $reason, $other, $details !== '' ? $details : null, $filedOn, $recordedBy, $_SESSION['user_id']]);
            }
            if ($stillOpen) { $pdo->prepare("UPDATE students SET status = ? WHERE student_id = ?")->execute([CASE_TYPES[$type]['status'], $studentId]); }   // the status follows the request
            $pdo->commit();
            $_SESSION['req_flash'] = ['type' => 'success', 'msg' => $stu['full_name'] . ' — ' . CASE_TYPES[$type]['label'] . ' recorded' . ($stillOpen ? '. Their status is now ' . STUDENT_STATUSES[CASE_TYPES[$type]['status']] . '.' : '.')];
            header('Location: ' . ($editId ? 'reports.php?ay=' . urlencode($ay) . '&type=' . urlencode($type) . '#requests' : 'request_form.php')); exit;
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $flash = ['type' => 'error', 'msg' => $e->getMessage()];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $flash = ['type' => 'error', 'msg' => $e->errorInfo[1] == 1062
                ? 'This student already has a request of that type for the same school year and semester. Edit that one instead.'
                : 'Could not save the request: ' . $e->getMessage()];
        }
    }
}

// what the form starts with: the request being edited, or what was just typed (after an error), or an empty form
$prefill = $edit ?: null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $prefill = ['case_type' => $_POST['case_type'] ?? '', 'student_id' => $_POST['student_id'] ?? '', 'full_name' => $_POST['student_label'] ?? '', 'section' => '',
                'academic_year' => $_POST['ay'] ?? '', 'semester' => $_POST['semester'] ?? '', 'filed_on' => $_POST['filed_on'] ?? '', 'reason' => $_POST['reason'] ?? '',
                'reason_other' => $_POST['reason_other'] ?? '', 'details' => $_POST['details'] ?? ''];
}
$studentPick = $pdo->query("SELECT s.student_id, s.full_name, sr.section FROM students s
    LEFT JOIN student_records sr ON sr.student_id = s.student_id AND sr.year_level = s.year_level
    WHERE s.status <> 'graduated' ORDER BY s.full_name ASC")->fetchAll();
$reasonMap = [];
foreach (REQUEST_FORM_TYPES as $k) { $reasonMap[$k] = CASE_TYPES[$k]['reasons']; }
$J = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request form | Student Attrition Risk Prediction System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
    <link rel="stylesheet" href="../assets/request-form.css?v=1">
</head>
<body>
<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <section class="page-header">
        <div>
            <h1><?= $edit ? 'Edit request' : 'Request form' ?></h1>
            <p>Record a <strong>Leave of Absence</strong> or an <strong>Honorable Dismissal</strong> from the letter the student submitted to the College. The student’s status changes as soon as it is saved.</p>
        </div>
    </section>

    <?php if ($flash): ?>
        <div class="rf-flash <?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><i class="fa-solid <?= $flash['type'] === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
            <span><?= htmlspecialchars($flash['msg']) ?></span>
            <?php if ($flash['type'] !== 'error'): ?><a href="reports.php#requests">View in Reports</a><?php endif; ?></div>
    <?php endif; ?>
    <?php if (!hasStudentDataPin($pdo)): ?>
        <div class="rf-flash error"><i class="fa-solid fa-shield-halved"></i><span>You haven’t set up your PIN yet — you’ll need it to save a request.</span><a href="privacy_settings.php">Set it up</a></div>
    <?php endif; ?>

    <div class="rf-wrap">
        <form method="POST" id="caseForm" class="rf-card" novalidate>
            <input type="hidden" name="id" value="<?= (int) $editId ?>">
            <input type="hidden" name="credential" id="c_cred">

            <div class="rf-step"><span class="rf-num">1</span><div><h2>Type of request</h2><p>What does the student’s letter ask for?</p></div></div>
            <div class="rf-body">
                <div class="rf-types">
                    <?php foreach (REQUEST_FORM_TYPES as $key): $t = CASE_TYPES[$key]; ?>
                        <label class="rf-type" style="--c:<?= $t['color'] ?>;--bg:<?= $t['bg'] ?>;">
                            <input type="radio" name="case_type" value="<?= $key ?>" onchange="onTypeChange()" required>
                            <span class="rf-type-body"><span class="rf-type-ico"><i class="fa-solid <?= $t['icon'] ?>"></i></span>
                                <span><strong><?= htmlspecialchars($t['label']) ?></strong><small><?= $key === 'loa' ? 'A temporary break. The student stays a student of the College and may return.' : 'The student leaves the program in good standing, usually to transfer to another school.' ?></small></span></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="rf-step"><span class="rf-num">2</span><div><h2>Student</h2><p>Search by name or student ID.</p></div></div>
            <div class="rf-body">
                <div class="combo">
                    <input type="text" id="stuInput" name="student_label" placeholder="Start typing a name or ID, for example Ma…" autocomplete="off" required role="combobox" aria-autocomplete="list" aria-controls="stuList">
                    <input type="hidden" name="student_id" id="stuId">
                    <ul id="stuList" class="combo-list" role="listbox" style="display:none;"></ul>
                </div>
                <div class="combo-hint" id="stuHint">Pick the student from the suggestions, so we know they are in the records.</div>
            </div>

            <div class="rf-step"><span class="rf-num">3</span><div><h2>When</h2><p>The school year and semester the request covers, and the day the letter was filed.</p></div></div>
            <div class="rf-body">
                <div class="rf-grid3">
                    <div><label class="rf-l" for="c_ay">School year</label>
                        <select name="ay" id="c_ay" required><option value="">Select</option>
                            <?php foreach (AY_ENTRY_OPTIONS as $a): ?><option value="<?= $a ?>"><?= $a ?></option><?php endforeach; ?></select></div>
                    <div><label class="rf-l" for="c_sem">Semester</label>
                        <select name="semester" id="c_sem" required><option value="">Select</option>
                            <?php foreach (CASE_SEMESTERS as $sm): ?><option value="<?= $sm ?>"><?= $sm ?></option><?php endforeach; ?></select></div>
                    <div><label class="rf-l" for="c_date">Date filed</label>
                        <input type="date" name="filed_on" id="c_date" required></div>
                </div>
            </div>

            <div class="rf-step"><span class="rf-num">4</span><div><h2>Reason</h2><p>As written in the letter.</p></div></div>
            <div class="rf-body">
                <label class="rf-l" for="c_reason">Reason</label>
                <select name="reason" id="c_reason" required onchange="onReasonChange()"><option value="">Choose the type of request first</option></select>
                <div id="otherWrap" style="display:none;"><div class="rf-gap"></div>
                    <label class="rf-l" for="c_other">Please write the reason in your own words</label>
                    <input type="text" name="reason_other" id="c_other" maxlength="255" placeholder="The reason that isn’t in the list"></div>
                <div class="rf-gap"></div>
                <label class="rf-l" for="c_details">Remarks <span style="font-weight:400;color:var(--ink-3);">(optional)</span></label>
                <textarea name="details" id="c_details" maxlength="500"></textarea>
            </div>

            <div class="rf-actions">
                <span class="hint"><i class="fa-solid fa-shield-halved"></i> Your PIN is asked when you save.</span>
                <a class="btn-secondary" href="<?= $edit ? 'reports.php#requests' : 'dashboard.php' ?>">Cancel</a>
                <button type="submit" class="btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?= $edit ? 'Save changes' : 'Save request' ?></button>
            </div>
        </form>

        <aside class="rf-side">
            <div class="rf-note">
                <h3>Who records what</h3>
                <ul class="rf-who">
                    <li><i class="fa-solid fa-file-pen"></i><div><strong>Leave of Absence, Honorable Dismissal</strong>You record them here, from the student’s letter.</div></li>
                    <li><i class="fa-solid fa-right-left"></i><div><strong>Shifted</strong>No form. Set the student as Shifted on the <a href="students.php">Students</a> page.</div></li>
                    <li><i class="fa-solid fa-user-slash"></i><div><strong>Dropped (Unofficially)</strong>Recorded by the professor of the class. It appears in your Reports at once.</div></li>
                    <li><i class="fa-solid fa-table-list"></i><div><strong>All requests</strong>See, edit, or re-enroll them in <a href="reports.php#requests">Reports, Requests</a>.</div></li>
                </ul>
            </div>
            <div class="rf-note tip"><p><i class="fa-solid fa-lightbulb"></i> The status follows the request: a student on leave or dismissed leaves the Students list and is no longer scored. A leave can be ended with <em>Re-enroll</em> in Reports.</p></div>
        </aside>
    </div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<script>
    const STUDENTS = <?= json_encode($studentPick, $J) ?>;
    const REASONS = <?= json_encode($reasonMap, $J) ?>;
    const PREFILL = <?= json_encode($prefill, $J) ?>;
    const caseForm = document.getElementById('caseForm');
    const stuInput = document.getElementById('stuInput'), stuId = document.getElementById('stuId');
    const stuList = document.getElementById('stuList'), stuHint = document.getElementById('stuHint');
    const HINT_DEFAULT = 'Pick the student from the suggestions, so we know they are in the records.';
    const IS_EDIT = <?= $edit ? 'true' : 'false' ?>;
    let matches = [], active = 0, askedPin = false;
    function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }

    // Check the form first, then ask for the PIN in a pop-up, then save.
    caseForm.addEventListener('submit', function (e) {
        if (askedPin) return;                       // the second pass, after the PIN was typed
        e.preventDefault();
        if (!document.querySelector('input[name="case_type"]:checked')) { toast('Please choose the type of request.', 'bad'); return; }
        if (!stuId.value) { showHint('Please pick the student from the suggestions.', true); stuInput.focus(); toast('Please pick the student from the suggestions.', 'bad'); return; }
        if (!caseForm.reportValidity()) return;
        askSecret({ title: IS_EDIT ? 'Save changes' : 'Save request', message: 'Enter your PIN to record this request.', type: 'pin', confirmText: 'Save' }).then(function (pin) {
            if (pin === null) return;
            document.getElementById('c_cred').value = pin; askedPin = true; HTMLFormElement.prototype.submit.call(caseForm);
        });
    });

    function showHint(text, isError) { stuHint.textContent = text; stuHint.className = 'combo-hint' + (isError ? ' bad' : (stuId.value ? ' ok' : '')); }
    function matchStudents(q) {
        q = q.trim().toLowerCase(); if (!q) return [];
        const out = [];
        STUDENTS.forEach(function (s) {
            const name = s.full_name.toLowerCase(), words = name.split(/[\s,.\-]+/).filter(Boolean);
            let score = null;
            if (name.indexOf(q) === 0) score = 0; else if (words.some(function (w) { return w.indexOf(q) === 0; })) score = 1;
            else if (s.student_id.toLowerCase().indexOf(q) === 0) score = 2; else if (name.indexOf(q) !== -1) score = 3;
            if (score !== null) out.push({ score: score, s: s });
        });
        out.sort(function (a, b) { return a.score - b.score || a.s.full_name.localeCompare(b.s.full_name); });
        return out.slice(0, 40).map(function (x) { return x.s; });
    }
    function renderList() {
        stuList.innerHTML = '';
        if (!matches.length) {
            if (stuInput.value.trim()) { stuList.innerHTML = '<li class="combo-empty">No student with that name or ID</li>'; stuList.style.display = 'block'; } else stuList.style.display = 'none';
            return;
        }
        matches.forEach(function (s, i) {
            const li = document.createElement('li'); li.className = 'combo-item' + (i === active ? ' on' : ''); li.setAttribute('role', 'option');
            li.innerHTML = '<strong>' + esc(s.full_name) + '</strong><small>' + esc(s.student_id) + (s.section ? ' · ' + esc(s.section) : '') + '</small>';
            li.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(s); });
            stuList.appendChild(li);
        });
        stuList.style.display = 'block';
        const on = stuList.querySelector('.on'); if (on) on.scrollIntoView({ block: 'nearest' });
    }
    function choose(s) {
        stuInput.value = s.full_name + ' — ' + s.student_id; stuId.value = s.student_id; stuList.style.display = 'none';
        showHint('✓ Selected: ' + s.full_name + ' (' + s.student_id + ')' + (s.section ? ' · ' + s.section : ''), false);
    }
    stuInput.addEventListener('input', function () { stuId.value = ''; matches = matchStudents(stuInput.value); active = 0; showHint(HINT_DEFAULT, false); renderList(); });
    stuInput.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(matches.length - 1, active + 1); renderList(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); renderList(); }
        else if (e.key === 'Enter' && stuList.style.display === 'block' && matches.length) { e.preventDefault(); choose(matches[active]); }
        else if (e.key === 'Escape') { stuList.style.display = 'none'; }
    });
    stuInput.addEventListener('blur', function () { setTimeout(function () { stuList.style.display = 'none'; }, 150); });

    function currentType() { const r = document.querySelector('input[name="case_type"]:checked'); return r ? r.value : ''; }
    function onTypeChange(keepReason) {
        const t = currentType(), sel = document.getElementById('c_reason');
        sel.innerHTML = '<option value="">' + (t ? 'Select a reason' : 'Choose the type of request first') + '</option>';
        (REASONS[t] || []).forEach(function (r) { const o = document.createElement('option'); o.value = r; o.textContent = r === 'Others' ? 'Others (please specify)' : r; sel.appendChild(o); });
        if (keepReason) sel.value = keepReason;
        onReasonChange();
    }
    function onReasonChange() {
        const other = document.getElementById('c_reason').value === 'Others';
        document.getElementById('otherWrap').style.display = other ? 'block' : 'none';
        document.getElementById('c_other').required = other;
    }
    (function start() {
        const today = new Date().toISOString().slice(0, 10), d = document.getElementById('c_date');
        d.value = today; d.max = today;
        if (!PREFILL) { onTypeChange(); return; }
        const radio = document.querySelector('input[name="case_type"][value="' + PREFILL.case_type + '"]'); if (radio) radio.checked = true;
        if (PREFILL.student_id) choose({ full_name: PREFILL.full_name || PREFILL.student_id, student_id: PREFILL.student_id, section: PREFILL.section || '' });
        document.getElementById('c_ay').value = PREFILL.academic_year || ''; document.getElementById('c_sem').value = PREFILL.semester || '';
        if (PREFILL.filed_on) d.value = PREFILL.filed_on;
        document.getElementById('c_other').value = PREFILL.reason_other || ''; document.getElementById('c_details').value = PREFILL.details || '';
        onTypeChange(PREFILL.reason);
    })();
</script>
</body>
</html>
