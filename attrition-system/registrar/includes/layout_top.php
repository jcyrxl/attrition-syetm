<?php
/**
 * Include this right after <body>. Expects $activePage to be set
 * (one of: dashboard, students, prediction, reports, profile) and the
 * session to already be started by config/auth.php. $pdo must already
 * be available (every page requires config/db.php before this include).
 */
$activePage = $activePage ?? '';


// Quick navigation: Admin's only page is User Management; everyone else lands on the Dashboard.
$homeUrl   = (function_exists('isAdmin') && isAdmin()) ? 'users.php' : 'dashboard.php';
$homeLabel = 'Dashboard';

// The logged-in person's photo — or, until they set one, the first letter of their name.
$mePicture = null;
$meName = $_SESSION['full_name'] ?? 'User';
if (isset($pdo)) {
    try {
        $meStmt = $pdo->prepare("SELECT full_name, profile_picture FROM users WHERE user_id = ?");
        $meStmt->execute([$_SESSION['user_id']]);
        if ($meRow = $meStmt->fetch()) { $mePicture = $meRow['profile_picture']; $meName = $meRow['full_name']; }
    } catch (PDOException $e) { /* column not migrated yet: just show the initial */ }
}
if (!function_exists('avatarInner')) {
    function avatarInner(?string $picture, string $name): string {
        if ($picture) {
            return '<img src="../uploads/avatars/' . htmlspecialchars($picture) . '" alt="">';
        }
        $name = trim($name);
        $first = function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($name, 0, 1));
        return '<span class="avatar-initial">' . htmlspecialchars($first !== '' ? $first : '?') . '</span>';
    }
}
function navClass($page, $active) {
    return $page === $active ? 'nav-item active' : 'nav-item';
}

// ---- Notifications: recent High-risk alerts, read AND unread both shown —
//      unread ones are bold with a red dot, read ones fade to normal weight.
//      Clicking one marks it read and opens that student's subject breakdown
//      on the Students page. Admin doesn't see these at all, since Admin has
//      no access to student-data pages to begin with.
$notifications = [];
$hideBell = function_exists('isAdmin') && isAdmin();   // the Admin has no student pages, so no bell
if (isset($pdo) && !$hideBell) {
    $bellScope = '';
    if (function_exists('isFaculty') && isFaculty()) {          // a professor is told about his or her own students only
        require_once __DIR__ . '/../../config/grades.php';
        $bellScope = "WHERE n.kind = 'risk' AND " . scopeInSql($pdo, 'n.student_id', scopeStudentIds($pdo));
    }
    $notifications = $pdo->query("
        SELECT n.id AS notification_id, n.student_id, n.probability, n.created_at, n.is_read, n.kind, n.message, s.full_name
        FROM notifications n
        INNER JOIN students s ON s.student_id = n.student_id
        $bellScope
        ORDER BY n.created_at DESC
        LIMIT 15
    ")->fetchAll();
}
$hasRecentNotifications = false;
foreach ($notifications as $n) {
    if (!$n['is_read']) { $hasRecentNotifications = true; break; }
}
?>
<link rel="stylesheet" href="../assets/ui.css?v=2">
<aside class="sidebar" id="sidebar">

    <nav class="navigation">
        <?php if (isAdmin()): ?>
        <a href="users.php" class="<?= navClass('users', $activePage) ?>" title="User Management">
            <i class="fa-solid fa-user-gear"></i>
            <span>User Management</span>
        </a>
        <a href="subjects.php" class="<?= navClass('subjects', $activePage) ?>" title="Subjects">
            <i class="fa-solid fa-book"></i>
            <span>Subjects</span>
        </a>
        <?php elseif (isFaculty()): ?>
        <a href="dashboard.php" class="<?= navClass('dashboard', $activePage) ?>" title="Dashboard">
            <i class="fa-solid fa-house"></i>
            <span>Dashboard</span>
        </a>
        <a href="students.php" class="<?= navClass('students', $activePage) ?>" title="Students">
            <i class="fa-solid fa-users"></i>
            <span>Students</span>
        </a>
        <a href="classes.php" class="<?= navClass('classes', $activePage) ?>" title="My Classes">
            <i class="fa-solid fa-chalkboard-user"></i>
            <span>My Classes</span>
        </a>
        <a href="prediction.php" class="<?= navClass('prediction', $activePage) ?>" title="Prediction">
            <i class="fa-solid fa-chart-line"></i>
            <span>Prediction</span>
        </a>
        <a href="reports.php" class="<?= navClass('reports', $activePage) ?>" title="Reports">
            <i class="fa-solid fa-file-lines"></i>
            <span>Reports</span>
        </a>
        <?php else: ?>
        <a href="dashboard.php" class="<?= navClass('dashboard', $activePage) ?>" title="Dashboard">
            <i class="fa-solid fa-house"></i>
            <span>Dashboard</span>
        </a>
        <a href="students.php" class="<?= navClass('students', $activePage) ?>" title="Students">
            <i class="fa-solid fa-users"></i>
            <span>Students</span>
        </a>
        <?php if (function_exists('canAssignClasses') && canAssignClasses()): ?>
        <a href="assignments.php" class="<?= navClass('assignments', $activePage) ?>" title="Class Assignments">
            <i class="fa-solid fa-user-tag"></i>
            <span>Class Assignments</span>
        </a>
        <?php endif; ?>
        <?php if (function_exists('canManageRequests') && canManageRequests()): ?>
        <a href="request_form.php" class="<?= navClass('requests', $activePage) ?>" title="Request Form">
            <i class="fa-solid fa-file-circle-plus"></i>
            <span>Request Form</span>
        </a>
        <?php endif; ?>
        <a href="history.php" class="<?= navClass('history', $activePage) ?>" title="History">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span>History</span>
        </a>
        <a href="prediction.php" class="<?= navClass('prediction', $activePage) ?>" title="Prediction">
            <i class="fa-solid fa-chart-line"></i>
            <span>Prediction</span>
        </a>
        <a href="reports.php" class="<?= navClass('reports', $activePage) ?>" title="Reports">
            <i class="fa-solid fa-file-lines"></i>
            <span>Reports</span>
        </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-bottom">
        <small>Student Attrition Risk Prediction System</small>
    </div>

</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<main class="main" id="main">
    <div class="page-bg" aria-hidden="true"></div>

    <header class="topbar">

        <div class="topbar-left">
            <button class="menu-button" id="menuButton" type="button" aria-label="Show or hide the menu" title="Show / hide the menu">
                <i class="fa-solid fa-bars"></i>
            </button>
            <a class="brand" href="<?= htmlspecialchars($homeUrl) ?>" title="Student Attrition Risk Prediction System">
                <img src="../images/ccs-logo.png" alt="CCS">
                <span><strong>Student Attrition Risk Prediction System</strong><small>College of Computing Studies · UDM</small></span>
            </a>
        </div>

        <div class="topbar-right">
            <?php if (!$hideBell): ?>
            <div class="profile-container">
                <button class="notification-button" id="notifButton" type="button" aria-label="Notifications">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($hasRecentNotifications): ?><span class="notification-dot"></span><?php endif; ?>
                </button>

                <div class="profile-dropdown" id="notifDropdown" style="width:330px;">
                    <div class="dropdown-header">
                        <strong style="font-size:13px;">At-Risk Notifications</strong>
                    </div>
                    <div class="dropdown-divider"></div>
                    <div style="max-height:360px;overflow-y:auto;">
                        <?php if (empty($notifications)): ?>
                            <div style="padding:14px 10px;font-size:12px;color:#8a959b;text-align:center;">
                                No at-risk alerts yet.
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifications as $n): $unread = !$n['is_read']; ?>
                                <a href="student.php?id=<?= urlencode($n['student_id']) ?>&mark_read=<?= (int) $n['notification_id'] ?>" <?= $unread ? 'data-unread="1"' : '' ?>
                                   style="display:flex;gap:8px;align-items:flex-start;padding:10px;border-radius:7px;<?= $unread ? 'background:#fef6f6;' : '' ?>">
                                    <span style="width:8px;height:8px;border-radius:50%;margin-top:5px;flex-shrink:0;background:<?= $unread ? '#e53935' : 'transparent' ?>;"></span>
                                    <span>
                                        <strong style="font-size:12px;color:<?= $n['kind'] === 'du' ? '#b3261e' : '#c62828' ?>;font-weight:<?= $unread ? '700' : '500' ?>;">
                                            <?= htmlspecialchars($n['full_name']) ?>
                                        </strong>
                                        <div style="font-size:11px;color:#7a858d;margin-top:2px;font-weight:<?= $unread ? '600' : '400' ?>;">
                                            <?= $n['kind'] === 'du' ? htmlspecialchars($n['message'] ?? 'Marked Dropped (Unofficially)') : 'Flagged High Risk (' . number_format($n['probability'], 0) . '%)' ?> —
                                            <?= date('M d, Y', strtotime($n['created_at'])) ?>
                                        </div>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <script>
            // A clicked notification is read: it fades at once, and the red dot goes when none is left (the server saves it as the student opens).
            (function () {
                var box = document.getElementById('notifDropdown');
                if (!box) return;
                box.addEventListener('click', function (e) {
                    var a = e.target.closest('a[data-unread]');
                    if (!a) return;
                    a.removeAttribute('data-unread'); a.style.background = '';
                    var dot = a.querySelector('span'); if (dot) dot.style.background = 'transparent';
                    var strong = a.querySelector('strong'); if (strong) strong.style.fontWeight = '500';
                    var small = a.querySelector('div'); if (small) small.style.fontWeight = '400';
                    if (!box.querySelector('a[data-unread]')) { var nd = document.querySelector('.notification-dot'); if (nd) nd.remove(); }
                }, true);
            })();
            </script>
            <div class="profile-container">
                <button class="profile-button" id="profileButton" type="button">
                    <div class="profile-picture"><?= avatarInner($mePicture, $meName) ?></div>
                    <div class="profile-text">
                        <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?></strong>
                        <span><?= htmlspecialchars(ucwords(str_replace('_', ' ', $_SESSION['role'] ?? ''))) ?></span>
                    </div>
                    <i class="fa-solid fa-chevron-down profile-arrow"></i>
                </button>

                <div class="profile-dropdown" id="profileDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-avatar"><?= avatarInner($mePicture, $meName) ?></div>
                        <div>
                            <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?></strong>
                            <span><?= htmlspecialchars(ucwords(str_replace('_', ' ', $_SESSION['role'] ?? ''))) ?></span>
                        </div>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="profile.php"><i class="fa-regular fa-user"></i> User Profile</a>
                    <a href="privacy_settings.php"><i class="fa-solid fa-shield-halved"></i> Privacy Settings</a>
                    <a href="logout.php" class="logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                </div>
            </div>

        </div>

    </header>
