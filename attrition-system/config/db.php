<?php
/**
 * Central database connection.
 * Every page includes this file to talk to MySQL.
 * Edit DB_HOST / DB_NAME / DB_USER / DB_PASS to match your XAMPP/WAMP setup.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'ccs_attrition_system');
define('DB_USER', 'root');
define('DB_PASS', ''); // default XAMPP password is empty

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
