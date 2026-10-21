<?php
/**
 * Role-based access control.
 * Include AFTER config/auth.php (which guarantees a logged-in session exists).
 *
 * Three roles:
 *   - admin        : gives out the ACCOUNTS (User Management) and adds the SUBJECTS (Subjects). Nothing else:
 *                    no graphs, no student pages, and it does NOT archive anything.
 *   - program_head : sees EVERYTHING — every graph, student (with every quiz, attendance, grade, and all earlier years), report —
 *                    and changes nothing about the grades — except that, with the PIN, it RECORDS the students' REQUESTS (leave of absence,
 *                    dropped, honorable dismissal, shifted — from the letters submitted to the College) in Reports, CLEARS a class whose grades
 *                    are complete and ARCHIVES a semester (History). There is no Classes page: it is all under Students.
 *   - faculty      : a professor. Records attendance and scores in the classes assigned to him or her, and sees the Dashboard,
 *                    Students, Prediction and Reports of ONLY his or her own subjects and students. No History.
 */

function isAdmin(): bool       { return ($_SESSION['role'] ?? '') === 'admin'; }
function isProgramHead(): bool { return ($_SESSION['role'] ?? '') === 'program_head'; }
function isFaculty(): bool     { return ($_SESSION['role'] ?? '') === 'faculty'; }

/** True if the logged-in user may add / edit student data and record in classes. Faculty only (and only inside his or her own students). */
function canManageData(): bool { return isFaculty(); }

/** True if the logged-in user may create/edit/delete USER ACCOUNTS and assign classes. Admin only. */
function canManageUsers(): bool { return isAdmin(); }

/** True if the logged-in user sees ALL students, subjects and reports (view only). Program Head only. */
function canViewAllData(): bool { return isProgramHead(); }

/** Roles that hold a personal PIN: Faculty (recording in classes) and the Program Head (clearing classes and archiving a semester). */
function canUsePin(): bool { return isFaculty() || isProgramHead(); }
/** Only the Program Head clears classes and archives a semester (with the PIN). */
function canCloseSemester(): bool { return isProgramHead(); }
/** Only the Program Head makes classes and assigns them (subject code, section, professor) — from the subjects the Admin added. */
function canAssignClasses(): bool { return isProgramHead(); }
/** Only the Admin adds subjects (the name only). */
function canManageSubjects(): bool { return isAdmin(); }
/** Only the Program Head records and manages the students' requests (leave of absence, dropped, honorable dismissal, shifted), with the PIN — from the letters submitted to the College. */
function canManageRequests(): bool { return isProgramHead(); }

/** The Admin has no student pages: send him or her to User Management. */
function denyAdmin(): void {
    if (isAdmin()) { header('Location: users.php'); exit; }
}
/** History is for the Program Head only. */
function historyOnly(): void {
    if (isAdmin()) { header('Location: users.php'); exit; }
    if (isFaculty()) { header('Location: dashboard.php'); exit; }
}
/** Classes (recording) are for the professor only. */
function facultyOnly(): void {
    if (isAdmin()) { header('Location: users.php'); exit; }
    if (isProgramHead()) { header('Location: dashboard.php'); exit; }
}

/** Human-readable label of the current role. */
function currentRoleLabel(): string {
    return ucwords(str_replace('_', ' ', $_SESSION['role'] ?? ''));
}

/**
 * Call at the top of any action that changes STUDENT data (save/delete/import/requests).
 * Blocks anyone who isn't Faculty, even if they craft the POST request directly — this is the real
 * security boundary, not just hidden buttons.
 */
function requireManagePermission(): void {
    if (!canManageData()) {
        http_response_code(403);
        die('Access denied: your account (' . currentRoleLabel() . ') cannot change student data. The Program Head can only view; the Administrator manages accounts and class assignments.');
    }
}

/** Call at the top of any class-assignment action (Program Head only). */
function requireAssignPermission(): void {
    if (!canAssignClasses()) {
        http_response_code(403);
        die('Access denied: only the Program Head makes classes and assigns them to professors. The Administrator adds the subjects.');
    }
}
/** Call at the top of any action that records or changes a student request (Program Head only). */
function requireRequestPermission(): void {
    if (!canManageRequests()) {
        http_response_code(403);
        die('Access denied: your account (' . currentRoleLabel() . ') cannot record student requests. Only the Program Head records leave of absence, dropped, honorable dismissal and shifted requests.');
    }
}

/**
 * Call at the top of any action that changes USER ACCOUNTS (create/edit/delete/role change).
 * Blocks anyone who isn't an Admin, even if they craft the POST request directly.
 */
function requireUserManagePermission(): void {
    if (!canManageUsers()) {
        http_response_code(403);
        die('Access denied: your account (' . currentRoleLabel() . ') cannot manage user accounts. Only an Admin can create, edit, or remove logins.');
    }
}

/**
 * A user's own 6-digit Student Data PIN — a second factor required
 * before any student record can be added, edited, or deleted, on top of
 * being logged in. This protects against someone tampering with student
 * data from an unattended, already-logged-in session. Only the person
 * who owns it can see or change it (via Privacy Settings); nobody else,
 * including Admin, can view or set it for them.
 */
function hasStudentDataPin(PDO $pdo): bool {
    $stmt = $pdo->prepare("SELECT student_data_pin_hash FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Verifies a submitted PIN against the current user's stored PIN hash.
 * Returns false if no PIN has been set up yet, or if it doesn't match.
 */
function verifyStudentDataPin(PDO $pdo, string $pin): bool {
    $stmt = $pdo->prepare("SELECT student_data_pin_hash FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $hash = $stmt->fetchColumn();
    return $hash && password_verify($pin, $hash);
}
