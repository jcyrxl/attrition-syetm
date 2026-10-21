<?php
/**
 * Shared validation rules — the single source of truth for both
 * create_admin.php (account creation) and profile.php (change password).
 * Never rely on the client-side JS alone; these are the real checks.
 */

/**
 * User ID rule: numbers and hyphens only (like an employee/ID number,
 * e.g. 2022-1001), 4–20 characters. Everything else is locked out.
 * Returns null if valid, or an error message string if not.
 */
function validateUserId(string $userId): ?string {
    if ($userId === '') {
        return 'User ID is required.';
    }
    if (!preg_match('/^[0-9-]+$/', $userId)) {
        return 'Invalid User ID — numbers and dashes (-) only.';
    }
    if (strlen($userId) < 4 || strlen($userId) > 20) {
        return 'User ID must be 4 to 20 characters long.';
    }
    return null;
}

/**
 * Password rule: at least 8 characters, with at least one uppercase letter,
 * one lowercase letter, one number, and one special character.
 * Returns null if valid, or an error message string if not.
 */
function validateStrongPassword(string $password): ?string {
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must include at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must include at least one special character (e.g. ! @ # $ %).';
    }
    return null;
}

/**
 * Student Data PIN rule: exactly 6 digits, nothing else.
 * Returns null if valid, or an error message string if not.
 */
function validatePin(string $pin): ?string {
    if ($pin === '') {
        return 'PIN is required.';
    }
    if (!preg_match('/^\d{6}$/', $pin)) {
        return 'PIN must be exactly 6 digits.';
    }
    return null;
}

/**
 * Email rule: optional, but if provided must look like a real address.
 * Returns null if valid (including empty/not provided), or an error message.
 */
function validateEmail(string $email): ?string {
    if ($email === '') {
        return null; // optional field
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    return null;
}

/**
 * Contact number rule: optional, but if provided it must be exactly 11 digits
 * (a Philippine mobile number, e.g. 09171234567) — digits only.
 * Returns null if valid (including empty/not provided), or an error message.
 */
function validateContactNumber(string $number): ?string {
    if ($number === '') {
        return null; // optional field
    }
    if (!preg_match('/^\d{11}$/', $number)) {
        return 'Contact number must be exactly 11 digits (e.g. 09171234567).';
    }
    return null;
}
