<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';
require_once __DIR__ . '/../config/grades.php';

// Admin and Program Head see every class; a professor sees only the classes the Admin assigned to him or her.
facultyOnly();                         // recording is for the assigned professor
$activePage = 'classes';
$isProf = isFaculty();
$cur = currentSchoolYear($pdo);

// ---- filters ----
$ayList = $pdo->query("SELECT DISTINCT academic_year FROM classes ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($cur['ay'], $ayList, true)) { array_unshift($ayList, $cur['ay']); }
$viewAy  = $cur['ay'];            // always the current period: earlier semesters are archived
$search  = mb_substr(trim($_GET['q'] ?? ''), 0, 60);
$semF    = (int) $cur['sem'];
$secF    = preg_match('/^BSIT-[1-4][1-8]$/', $_GET['section'] ?? '') ? $_GET['section'] : '';
$flagF   = in_array($_GET['flag'] ?? '', ['attention', 'alerts', 'unassigned'], true) ? $_GET['flag'] : '';
$ownerF  = (!$isProf && isset($_GET['owner'])) ? (string) $_GET['owner'] : '';

// every student is put in the class of each subject he or she takes (automatic)
$setupError = '';
try { refreshAllRosters($pdo, $viewAy); }
catch (PDOException $e) { $setupError = 'The Classes tables are not up to date. Run database/migration_classes.sql in phpMyAdmin, then reload this page. (' . $e->getMessage() . ')'; }

$where = ["c.academic_year = ?"]; $params = [$viewAy];
if ($isProf)           { $where[] = "c.owner_user_id = ?"; $params[] = $_SESSION['user_id']; }
if ($semF)             { $where[] = "c.semester = ?";      $params[] = $semF; }
if ($secF !== '')      { $where[] = "c.section = ?";       $params[] = $secF; }
if ($ownerF === 'none' || $flagF === 'unassigned') { $where[] = "(c.owner_user_id IS NULL OR c.owner_user_id = '')"; }
elseif ($ownerF !== '') { $where[] = "c.owner_user_id = ?"; $params[] = $ownerF; }
if ($search !== '') {
    $where[] = "(c.subject_name LIKE ? OR c.subject_code LIKE ? OR c.section LIKE ? OR u.full_name LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
$rows = [];
try {
    $q = $pdo->prepare("SELECT c.id FROM classes c LEFT JOIN users u ON u.user_id = c.owner_user_id WHERE " . implode(' AND ', $where) . " ORDER BY c.section ASC, c.semester ASC, c.subject_name ASC");
    $q->execute($params);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $s = classSummary($pdo, (int) $cid);
        if (!$s) { continue; }
        if ($flagF === 'attention' && $s['attention'] === 0) { continue; }
        if ($flagF === 'alerts' && ($s['drop'] + $s['warn']) === 0) { continue; }
        $rows[] = $s;
    }
} catch (PDOException $e) { $setupError = $setupError ?: 'The Classes tables are not up to date. Run database/migration_classes.sql. (' . $e->getMessage() . ')'; }

$faculty = $pdo->query("SELECT user_id, full_name FROM users WHERE role = 'faculty' ORDER BY full_name")->fetchAll();
$ord = ['1st', '2nd', '3rd', '4th'];
$totalAttention = array_sum(array_map(function ($r) { return $r['attention']; }, $rows));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isProf ? 'My Classes' : 'Classes' ?> | Attrition Risk Prediction</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
    <style>.small-ay { display:block; font-size: 11px; color:#8a959b; }</style>
</head>
<body>

<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <section class="page-header">
        <div>
            <h1><?= $isProf ? 'My Classes' : 'Classes' ?> <small class="term-small"><?= htmlspecialchars($cur['ay']) ?> · <?= (int) $cur['sem'] === 1 ? '1st' : '2nd' ?> Semester</small></h1>
            <p><?php if ($isProf): ?>The subjects the Program Head assigned to you. Open one to record attendance and scores — the grades are calculated and each student's risk is predicted again with every entry.
               <?php else: ?>Every class, with its attendance and grades. <?= canAssignClasses() ? 'You decide which professor handles each class under <a href="assignments.php" style="color:#087fa7;font-weight:600;">Class Assignments</a>.' : 'You can look at everything here; only the assigned professor records.' ?><?php endif; ?></p>
        </div>
        <?php if (canAssignClasses()): ?><a class="btn-primary" style="text-decoration:none;" href="assignments.php"><i class="fa-solid fa-user-tag"></i> Class Assignments</a><?php endif; ?>
    </section>

    <?php if ($setupError): ?><div class="alert-banner alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($setupError) ?></div><?php endif; ?>

    <!-- search and filters work in place (no page reload) -->
    <form method="GET" class="toolbar" id="filterForm" data-base="classes.php">
        <div class="search-box">
            <button type="submit" class="search-submit-btn" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
            <input type="text" name="q" placeholder="Search subject, section or professor" value="<?= htmlspecialchars($search) ?>" oninput="applyFilters(this)" autocomplete="off">
        </div>
        <select name="section" class="filter-select" onchange="applyFilters(this)">
            <option value="">All sections</option>
            <?php for ($y = 1; $y <= 4; $y++): for ($b = 1; $b <= 8; $b++): $sec = 'BSIT-' . $y . $b; ?>
                <option value="<?= $sec ?>" <?= $secF === $sec ? 'selected' : '' ?>><?= $sec ?></option>
            <?php endfor; endfor; ?>
        </select>
        <?php if (!$isProf): ?>
        <select name="owner" class="filter-select" onchange="applyFilters(this)">
            <option value="">All professors</option>
            <option value="none" <?= $ownerF === 'none' ? 'selected' : '' ?>>Not assigned yet</option>
            <?php foreach ($faculty as $f): ?><option value="<?= htmlspecialchars($f['user_id']) ?>" <?= $ownerF === $f['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['full_name']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="flag" class="filter-select" onchange="applyFilters(this)">
            <option value="">All classes</option>
            <option value="attention" <?= $flagF === 'attention' ? 'selected' : '' ?>>With students needing attention</option>
            <option value="alerts" <?= $flagF === 'alerts' ? 'selected' : '' ?>>With absence alerts</option>
            <?php if (!$isProf): ?><option value="unassigned" <?= $flagF === 'unassigned' ? 'selected' : '' ?>>Not assigned to a professor</option><?php endif; ?>
        </select>
    </form>

    <div id="viewArea" data-live>
        <p style="margin:0 30px 10px;font-size:13px;color:#64727a;"><?= count($rows) ?> class<?= count($rows) === 1 ? '' : 'es' ?> · <?= $totalAttention ?> student<?= $totalAttention === 1 ? '' : 's' ?> need attention</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Subject</th><th>Section</th><th>Semester</th><th>Professor</th><th>Students</th><th>Meetings</th><th>Absence alerts</th><th>Need attention</th><th>Grades in</th><th></th></tr></thead>
                <tbody>
                    <?php if (!$rows): ?><tr><td colspan="10"><?= $isProf ? 'No class is assigned to you for these filters. The Program Head assigns your subjects.' : 'No class matches these filters.' ?></td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): $c = $r['d']['class']; $own = canEditClass($c); ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($c['subject_name']) ?></strong><?= $c['subject_code'] ? '<span class="small-ay">' . htmlspecialchars($c['subject_code']) . '</span>' : '' ?></td>
                            <td><?= htmlspecialchars($c['section']) ?><span class="small-ay"><?= $ord[classSectionYear($c['section']) - 1] ?> Year</span></td>
                            <td><?= (int) $c['semester'] === 1 ? '1st' : '2nd' ?> Semester</td>
                            <td><?= $c['owner_name'] ? htmlspecialchars($c['owner_name']) : '<span class="badge moderate-badge">Not assigned</span>' ?></td>
                            <td><?= count($r['d']['students']) ?></td>
                            <td><?= count($r['d']['meetings']) ?></td>
                            <td><?= $r['drop'] > 0 ? '<span class="badge high-badge">' . $r['drop'] . ' at 3+</span> ' : '' ?><?= $r['warn'] > 0 ? '<span class="badge moderate-badge">' . $r['warn'] . ' at 2</span>' : '' ?><?= ($r['drop'] + $r['warn']) === 0 ? '<span style="color:#8a959b;">None</span>' : '' ?></td>
                            <td><?= $r['attention'] > 0 ? '<span class="badge high-badge">' . $r['attention'] . ' student' . ($r['attention'] === 1 ? '' : 's') . '</span>' : '<span style="color:#8a959b;">None</span>' ?></td>
                            <td><?php $cp = $r['comp']; if (!empty($c['cleared_at'])): ?><span class="pchip ok"><i class="fa-solid fa-lock"></i> Cleared</span><?php else: foreach ([1 => 'P', 2 => 'M', 3 => 'F'] as $tk => $tl):
                                $dn = $cp['terms'][$tk]; $cl = ($cp['n'] > 0 && $dn >= $cp['n']) ? 'ok' : ($dn > 0 ? 'warn' : 'zero'); ?><span class="cmp <?= $cl ?>" title="<?= [1 => 'Prelim', 2 => 'Midterm', 3 => 'Pre-Finals'][$tk] ?>: <?= $dn ?> of <?= $cp['n'] ?> students complete" style="margin-right:3px;"><?= $tl ?></span><?php endforeach; endif; ?></td>
                            <td><a data-keep class="btn-secondary" style="padding:8px 14px;" href="class.php?id=<?= (int) $c['id'] ?>"><i class="fa-solid fa-<?= $own ? 'pen-to-square' : 'eye' ?>"></i> <?= $own ? 'Open' : 'View' ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<script src="../assets/live-filter.js?v=2"></script>
</body>
</html>
