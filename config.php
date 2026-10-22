<?php
// XAMPP defaults are commonly username=root and an empty password.
// Change these values if you configured a MySQL password.
$DB_HOST = 'localhost';
$DB_NAME = 'school_platform';
$DB_USER = 'root';
$DB_PASS = '';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database connection failed. Check config.php and ensure Apache and MySQL are running in XAMPP.');
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
