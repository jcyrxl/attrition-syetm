<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/grades.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';

historyOnly();                        // Program Head only

$activePage = 'history';
$flash = null;
$readOnly = true;      // the folders below are view only; closing the semester is its own section (PIN)
$cur = currentSchoolYear($pdo);

// ------------------------------------------------------------
// Start a new school year: the finished school year moves to History (nothing is copied or deleted — History simply shows every school year
// before the current one, as folders by school year and year level). Needs the Faculty member's own Student Data PIN.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Clear a class / reopen it / archive the semester — Program Head only, each with the PIN (JSON; the pop-up already checked it, it is checked again here).
    header('Content-Type: application/json; charset=utf-8');
    $reply = function (bool $ok, string $msg, array $extra = []) { echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE); exit; };
    if (!canCloseSemester()) { $reply(false, 'Only the Program Head can do this.'); }
    $check = checkCredential($pdo, 'pin', $_POST['credential'] ?? '');
    if (!$check['ok']) { $reply(false, $check['error'], !empty($check['logout']) ? ['logout' => true] : []); }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'clear_class') {
            $err = clearClassById($pdo, (int) ($_POST['id'] ?? 0), $_SESSION['full_name'] ?? $_SESSION['user_id']);
            $reply($err === null, $err ?? 'Class cleared — its grades are final and it is archived (view only).');
        }
        if ($action === 'reopen_class') {
            $err = reopenClassById($pdo, (int) ($_POST['id'] ?? 0));
            $reply($err === null, $err ?? 'Class reopened — the professor can record in it again.');
        }
        if ($action === 'archive_semester') {
            [$ok, $msg] = archiveCurrentSemester($pdo, $_SESSION['full_name'] ?? $_SESSION['user_id'], !empty($_POST['graduate']));
            $reply($ok, $msg);
        }
        $reply(false, 'Unknown action.');
    } catch (PDOException $e) { $reply(false, 'Could not save: ' . $e->getMessage()); }
}

$currentAy = $cur['ay'];                                   // the current school year lives on the Dashboard and Students pages
$years     = schoolYearsWithData($pdo);
$past      = array_values(array_filter($years, function ($y) use ($currentAy) { return $y < $currentAy; }));

// ---- what was asked for ----
$ay      = in_array($_GET['ay'] ?? '', $past, true) ? $_GET['ay'] : '';
$section = preg_match('/^BSIT-[1-4][1-8]$/', $_GET['section'] ?? '') ? $_GET['section'] : '';
$status  = array_key_exists($_GET['status'] ?? '', STUDENT_STATUSES) ? $_GET['status'] : '';
$risk    = in_array($_GET['risk'] ?? '', ['High', 'Moderate', 'Low'], true) ? $_GET['risk'] : '';
$q       = mb_substr(trim($_GET['q'] ?? ''), 0, 60);
$page    = max(1, (int) ($_GET['page'] ?? 1));

// "root" shows the folders; opening a folder (or using Status / Risk) shows the students inside it
$mode = (isset($_GET['list']) || $status !== '' || $risk !== '') ? 'list' : 'root';

$folders = historyFolders($pdo, $currentAy);
$sectionOptions = [];
foreach ($folders as $f) { if ($f['section'] !== '' && ($ay === '' || $f['ay'] === $ay)) { $sectionOptions[$f['section']] = true; } }
$sectionOptions = array_keys($sectionOptions);
sort($sectionOptions);
if ($section !== '' && !in_array($section, $sectionOptions, true)) { $sectionOptions[] = $section; sort($sectionOptions); }

// ---- folder view: the search and both filters only look at FOLDER NAMES ----
$shownFolders = [];
if ($mode === 'root') {
    foreach ($folders as $f) {
        $label = ($f['section'] !== '' ? $f['section'] : 'No section') . ' (' . $f['ay'] . ')';
        if ($ay !== '' && $f['ay'] !== $ay) { continue; }
        if ($section !== '' && $f['section'] !== $section) { continue; }
        if ($q !== '' && stripos($label, $q) === false) { continue; }
        $shownFolders[$f['ay']][] = $f;
    }
}

// ---- inside a folder: the students, alphabetical by last name ----
$totalRows = 0; $totalPages = 1; $pageRows = []; $page = max(1, $page);
if ($mode === 'list') {
    $rows = historyRecords($pdo, $currentAy, ['ay' => $ay, 'section' => $section, 'status' => $status, 'risk' => $risk, 'q' => $q]);
    $totalRows  = count($rows);
    $perPage    = 12;
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page       = min($page, $totalPages);
    $pageRows   = array_slice($rows, ($page - 1) * $perPage, $perPage);
}
$listUrl = function (int $p) use ($ay, $section, $status, $risk, $q) {
    return 'history.php?' . http_build_query(array_filter(['list' => 1, 'ay' => $ay, 'section' => $section, 'status' => $status, 'risk' => $risk, 'q' => $q, 'page' => $p > 1 ? $p : ''], function ($v) { return $v !== ''; }));
};
$badgeClass = ['High' => 'high-badge', 'Moderate' => 'moderate-badge', 'Low' => 'low-badge'];
$ord = ['1st', '2nd', '3rd', '4th'];

// ---- numbers for the rollover card ----
$enrolledNow = $fourthNow = 0;
$candidates = array_values(array_filter(AY_ENTRY_OPTIONS, function ($y) use ($currentAy) { return $y > $currentAy; }));
if (!$readOnly) {
    $c1 = $pdo->prepare("SELECT COUNT(*) FROM students s WHERE s.status = 'enrolled' AND EXISTS (SELECT 1 FROM student_records r WHERE r.student_id = s.student_id AND r.academic_year = ?)");
    $c1->execute([$currentAy]); $enrolledNow = (int) $c1->fetchColumn();
    $c2 = $pdo->prepare("SELECT COUNT(*) FROM students s WHERE s.status = 'enrolled' AND EXISTS (SELECT 1 FROM student_records r WHERE r.student_id = s.student_id AND r.academic_year = ? AND r.year_level = 4)");
    $c2->execute([$currentAy]); $fourthNow = (int) $c2->fetchColumn();
}
$defaultNext = $cur['new_year'] && in_array($cur['new_year'], $candidates, true) ? $cur['new_year'] : ($candidates[0] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History | Attrition Risk Prediction</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
    <style>.small-ay { display:block; font-size: 11px; color:#8a959b; } .ro-form label { display:block; font-size:13px; font-weight:600; color:#455a64; margin:12px 0 5px; }</style>
</head>
<body>

<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <section class="page-header">
        <div>
            <nav class="crumbs" id="crumbs" data-live aria-label="Where you are">
                <?php if ($mode === 'root'): ?><strong><i class="fa-solid fa-clock-rotate-left"></i> History</strong>
                <?php else: ?><a data-nav href="history.php"><i class="fa-solid fa-clock-rotate-left"></i> History</a><span>›</span><strong><?= $section !== '' ? htmlspecialchars($section) . ($ay !== '' ? ' (' . htmlspecialchars($ay) . ')' : '') : 'Student records' ?></strong>
                <?php endif; ?>
            </nav>
            <h1>History</h1>
            <p>The records of earlier school years — every section, kept in folders by school year and year level. The current school year (<strong><?= htmlspecialchars($currentAy) ?></strong>) is on the Students page.</p>
        </div>
        <div id="backArea" data-live><?php if ($mode !== 'root'): ?><a data-nav class="back-link" style="margin:0;" href="history.php"><i class="fa-solid fa-arrow-left"></i> Back to all folders</a><?php endif; ?></div>
    </section>

    <?php if ($flash): ?>
        <div class="alert-banner alert-<?= $flash['type'] ?>">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i> <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- In the folder view the search and filters read folder names only; inside a folder they work on the students. Nothing reloads. -->
    <form method="GET" class="toolbar" id="filterForm" data-base="history.php">
        <input type="hidden" name="list" id="f_list" value="<?= $mode === 'list' ? '1' : '' ?>" data-live-attr="value">

        <div class="search-box">
            <button type="submit" class="search-submit-btn" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
            <input type="text" name="q" id="f_q" value="<?= htmlspecialchars($q) ?>" autocomplete="off" oninput="applyFilters(this)"
                   data-live-attr="placeholder" placeholder="<?= $mode === 'list' ? 'Search student name or ID' : 'Search folders, e.g. BSIT-21 or 2024-2025' ?>">
        </div>

        <select name="ay" class="filter-select" onchange="applyFilters(this)" data-live-options>
            <option value="">All School Years</option>
            <?php foreach ($past as $y): ?><option value="<?= $y ?>" <?= $y === $ay ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
        </select>

        <select name="section" class="filter-select" onchange="applyFilters(this)" data-live-options>
            <option value="">All Sections</option>
            <?php foreach ($sectionOptions as $opt): ?><option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $section ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option><?php endforeach; ?>
        </select>

        <span class="list-only" id="listOnlyA" data-live-attr="hidden" <?= $mode === 'list' ? '' : 'hidden' ?>>
            <select name="status" class="filter-select" onchange="applyFilters(this)">
                <option value="">All Statuses</option>
                <?php foreach (STUDENT_STATUSES as $k => $lbl): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option><?php endforeach; ?>
            </select>
        </span>
        <span class="list-only" id="listOnlyB" data-live-attr="hidden" <?= $mode === 'list' ? '' : 'hidden' ?>>
            <select name="risk" class="filter-select" onchange="applyFilters(this)">
                <option value="">All Risk Levels</option>
                <?php foreach (['High', 'Moderate', 'Low'] as $rl): ?><option value="<?= $rl ?>" <?= $risk === $rl ? 'selected' : '' ?>><?= $rl ?> Risk</option><?php endforeach; ?>
            </select>
        </span>
    </form>

<?php include __DIR__ . '/includes/semester_close.php'; ?>

<div id="viewArea" data-live>
<?php if ($mode === 'root'): ?>

    <?php if (!$readOnly): ?>
    <div class="rollover-card" id="rollover">
        <div>
            <h3><i class="fa-solid fa-calendar-plus"></i> Move on to a new school year</h3>
            <p>Current school year: <strong><?= htmlspecialchars($currentAy) ?></strong> (<?= $enrolledNow ?> enrolled students). When the next school year begins, move <?= htmlspecialchars($currentAy) ?> to History here.
               Nothing is deleted or copied — its records stay exactly as they are and appear below as folders by school year and year level.</p>
        </div>
        <?php if ($candidates): ?>
            <button type="button" class="btn-primary" onclick="document.getElementById('rolloverModal').classList.add('show')"><i class="fa-solid fa-folder-plus"></i> Start new school year…</button>
        <?php else: ?><span style="font-size:13px;color:#8a959b;">No later school year is available in the list.</span><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="year-legend">
        Folder colours:
        <span><i style="background:#14b8a6"></i>1st Year</span><span><i style="background:#0ea5e9"></i>2nd Year</span>
        <span><i style="background:#3b6fe0"></i>3rd Year</span><span><i style="background:#1e3a8a"></i>4th Year</span>
    </div>

    <?php if (!$shownFolders): ?>
        <p style="margin:0 30px;color:#8a959b;"><?= $folders ? 'No folder matches your search or filters.' : 'Nothing here yet. When a new school year starts and you move the finished one to History, its folders appear here.' ?></p>
    <?php endif; ?>
    <?php foreach ($shownFolders as $fy => $list): ?>
        <h2 class="folder-heading">School year <?= htmlspecialchars($fy) ?> <small style="color:#8a959b;font-weight:500;">· <?= count($list) ?> folder<?= count($list) === 1 ? '' : 's' ?></small></h2>
        <div class="folder-grid">
            <?php foreach ($list as $f):
                $link = 'history.php?' . http_build_query(array_filter(['list' => 1, 'section' => $f['section'], 'ay' => $f['ay']], function ($v) { return $v !== ''; }));
                $blk  = preg_match('/^BSIT-[1-4]([1-8])$/', $f['section'], $bm) ? 'Block ' . $bm[1] . ' · ' : '';
            ?>
                <a data-nav class="folder-card yr<?= (int) $f['year'] ?>" href="<?= htmlspecialchars($link) ?>">
                    <span class="folder-icon"><i class="fa-solid fa-folder"></i></span>
                    <span class="folder-text">
                        <strong><?= htmlspecialchars($f['section'] !== '' ? $f['section'] : 'No section') ?> <span class="fay">(<?= htmlspecialchars($f['ay']) ?>)</span></strong>
                        <small><?= $blk ?><?= (int) $f['total'] ?> student<?= (int) $f['total'] === 1 ? '' : 's' ?></small>
                        <?php if ((int) $f['high'] > 0): ?><em class="folder-flag"><i class="fa-solid fa-triangle-exclamation"></i> <?= (int) $f['high'] ?> high risk</em><?php endif; ?>
                    </span>
                    <i class="fa-solid fa-chevron-right folder-go"></i>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

<?php else: ?>

    <p style="margin:0 30px 12px;font-size:13px;color:#64727a;">
        <i class="fa-solid fa-circle-info"></i>
        <?= $totalRows ?> record<?= $totalRows === 1 ? '' : 's' ?>, alphabetical by last name.
        Risk is the risk <em>at that time</em> (from that school year's grades and attendance); status is where the student is now.
    </p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Student</th><th>Section · Year</th><th>School Year</th><th>GWA</th><th>Attendance</th><th>Risk then</th><th>Status now</th><th>Records</th></tr></thead>
            <tbody>
                <?php if (!$pageRows): ?><tr><td colspan="8">No student records found.</td></tr><?php endif; ?>
                <?php foreach ($pageRows as $r): $stColor = STATUS_COLORS[$r['status']] ?? '#8a959b'; ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($r['full_name']) ?></strong><span class="small-ay"><?= htmlspecialchars($r['student_id']) ?></span></td>
                        <td><?= htmlspecialchars($r['section'] ?: '—') ?><span class="small-ay"><?= $ord[(int) $r['year_level'] - 1] ?? '' ?> Year</span></td>
                        <td><?= htmlspecialchars($r['academic_year']) ?></td>
                        <td><?= $r['gwa'] !== null ? fmtGrade((float) $r['gwa']) : '—' ?></td>
                        <td><?= $r['attendance_rate'] !== null ? number_format((float) $r['attendance_rate'], 1) . '%' : '—' ?></td>
                        <td><?php if ($r['risk_level']): ?><span class="badge <?= $badgeClass[$r['risk_level']] ?>"><?= $r['risk_level'] ?> (<?= number_format($r['risk_prob'], 0) ?>%)</span><?php else: ?><span class="badge" style="background:#eef2f5;color:#8a959b;">No grades</span><?php endif; ?></td>
                        <td><span class="status-pill" style="--c: <?= $stColor ?>;"><?= htmlspecialchars(STUDENT_STATUSES[$r['status']] ?? $r['status']) ?></span></td>
                        <td><a class="btn-secondary" style="padding:8px 12px;" href="student.php?id=<?= urlencode($r['student_id']) ?>"><i class="fa-solid fa-eye"></i> View</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?><a data-nav href="<?= htmlspecialchars($listUrl($i)) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a><?php endfor; ?>
        </div>
    </div>

<?php endif; ?>
</div>

<?php if (!$readOnly && $candidates): ?>
    <div class="modal-overlay" id="rolloverModal">
        <div class="modal-box" style="width:560px;">
            <h2>Start a new school year</h2>
            <p style="font-size:13.5px;color:#55636b;line-height:1.65;margin-bottom:6px;">
                <strong><?= htmlspecialchars($currentAy) ?></strong> moves to History (<?= $enrolledNow ?> enrolled students). The new school year becomes the current one on the Dashboard and the Students page;
                a student shows up in it when their PEF for the new school year is imported.</p>
            <form method="POST" id="rolloverForm" class="ro-form">
                <input type="hidden" name="action" value="start_year">
                <label>New school year</label>
                <select name="new_ay" required style="width:100%;padding:11px;border:1px solid #d8d8d8;border-radius:8px;font-size:14.5px;">
                    <?php foreach ($candidates as $c): ?><option value="<?= $c ?>" <?= $c === $defaultNext ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?>
                </select>
                <?php if ($fourthNow > 0): ?>
                    <label style="display:flex;gap:10px;align-items:flex-start;font-weight:500;margin-top:14px;">
                        <input type="checkbox" name="graduate" value="1" style="margin-top:3px;">
                        <span>Mark the <strong><?= $fourthNow ?></strong> enrolled 4th-year student<?= $fourthNow === 1 ? '' : 's' ?> of <?= htmlspecialchars($currentAy) ?> as <strong>Graduated</strong> (leave unticked if some have not graduated).</span>
                    </label>
                <?php endif; ?>
                <label><i class="fa-solid fa-shield-halved"></i> Your Student Data PIN</label>
                <input type="password" name="credential" id="ro_cred" class="pin-input" inputmode="numeric" maxlength="6" placeholder="6-digit PIN" autocomplete="off" required style="max-width:220px;width:100%;padding:11px;border:1px solid #d8d8d8;border-radius:8px;font-size:16px;box-sizing:border-box;">
                <div class="inline-error" id="ro_cred_error"></div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="document.getElementById('rolloverModal').classList.remove('show')">Cancel</button>
                    <button type="submit" class="btn-primary">Move to History &amp; start</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<?php if (!$readOnly) { include __DIR__ . '/includes/credential_modal.php'; } ?>
<script src="../assets/live-filter.js?v=2"></script>
<?php if (!$readOnly && $candidates): ?>
<script>
    const roPin = document.getElementById('ro_cred');
    roPin.addEventListener('input', function () { roPin.value = roPin.value.replace(/\D/g, '').slice(0, 6); });
    guardForm(document.getElementById('rolloverForm'), roPin, document.getElementById('ro_cred_error'));
    if (location.hash === '#rollover') { const m = document.getElementById('rolloverModal'); if (m) m.classList.add('show'); }
</script>
<?php endif; ?>
</body>
</html>
