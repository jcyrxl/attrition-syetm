<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';
require_once __DIR__ . '/../config/grades.php';

// Admin's only job is User Management — no access to student-data pages at all.

denyAdmin();                           // the Admin has no graphs: User Management is home
$activePage = 'dashboard';
$isProf = isFaculty();                       // a professor sees graphs about his or her own subjects only
$cur   = currentSchoolYear($pdo);
$scopeClassIds = $isProf ? ownedClassIds($pdo, $cur['ay']) : null;      // a professor: only his or her own classes
$scopeIds      = $isProf ? scopeStudentIds($pdo, $cur['ay']) : null;     // ... and only those classes' students
syncUnsyncedClasses($pdo, $scopeClassIds, 20);       // class results typed straight into the database are copied into the records the first time
if (!$isProf) { ensurePredictionsCurrent($pdo); }   // once, so every risk matches the grades now on record
$years = schoolYearsWithData($pdo);
if (!in_array($cur['ay'], $years, true)) { array_unshift($years, $cur['ay']); }
// The current school year opens first; the small buttons show the graphs of any other school year.
$viewAy    = $cur['ay'];          // always the current school year — earlier ones are archived (History)
$isHistory = ($viewAy !== $cur['ay']);
$semLabel  = $cur['sem'] === 1 ? '1st Semester' : '2nd Semester';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Attrition Risk Prediction</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
</head>
<body>

<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <form id="filterForm" data-base="dashboard.php" onsubmit="return false;"></form>

    <section class="dash-hero">
        <div class="dash-hero-text">
            <h1>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?>! 👋</h1>
            <p><?= $isProf ? 'Your subjects this semester — who needs attention, and where.' : 'Who is at risk of leaving, which section needs attention, and why — at a glance.' ?></p>
            <div class="ay-chips" id="ayChips"><span class="term-chip"><i class="fa-solid fa-calendar-check"></i> School year <?= htmlspecialchars($cur['ay']) ?> · <?= (int) $cur['sem'] === 1 ? '1st' : '2nd' ?> Semester</span></div>
            <?php if ($cur['fallback'] && !$isProf): ?>
                <p class="hero-note"><i class="fa-solid fa-circle-info"></i>
                    No records have been entered for <?= htmlspecialchars($cur['calendar']) ?> yet, so the latest school year with data is shown. Import the new school year's PEFs and it takes over.</p>
            <?php endif; ?>
        </div>
    </section>

    <div style="height:2px"></div>
    <?php renderRolloverBanner($cur); ?>

    <div id="viewArea" data-live>
        <?php include __DIR__ . '/includes/dashboard_graphs.php'; ?>
    </div>
    <?php include __DIR__ . '/includes/dashboard_pop.php'; ?>
    <?php if (!$isProf) { include __DIR__ . '/includes/reason_popup.php'; } ?>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<script src="../assets/live-filter.js?v=2"></script>
</body>
</html>
