<?php
require_once __DIR__ . '/config.php';

function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function logged_in() { return !empty($_SESSION['user']); }
function current_user() { return $_SESSION['user'] ?? null; }
function require_login() {
    if (!logged_in()) { header('Location: login.php'); exit; }
}
function require_roles(array $roles) {
    require_login();
    if (!in_array(current_user()['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
}
function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verify_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sent = $_POST['csrf'] ?? '';
        if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
            http_response_code(403); exit('Invalid form token. Go back and try again.');
        }
    }
}
function audit($conn, $message) {
    $uid = current_user()['id'] ?? null;
    $stmt = $conn->prepare('INSERT INTO audit_log(user_id, action_text) VALUES (?, ?)');
    $stmt->bind_param('is', $uid, $message);
    $stmt->execute();
}
function is_staff() {
    return logged_in() && in_array(current_user()['role'], ['admin','headteacher','teacher'], true);
}
function can_manage() {
    return logged_in() && in_array(current_user()['role'], ['admin','headteacher'], true);
}
function select_options($conn, $table, $id_col, $label_col, $selected = '') {
    $allowed = ['classes','subjects','users','students'];
    if (!in_array($table, $allowed, true)) return;
    $result = $conn->query("SELECT `$id_col`, `$label_col` FROM `$table` ORDER BY `$label_col`");
    while ($row = $result->fetch_assoc()) {
        echo '<option value="'.e($row[$id_col]).'" '.((string)$selected === (string)$row[$id_col] ? 'selected' : '').'>'.e($row[$label_col]).'</option>';
    }
}
function page_header($title) {
    $u = current_user();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="theme-color" content="#135c3b"><link rel="manifest" href="manifest.json"><link rel="stylesheet" href="assets/style.css">';
    echo '<title>'.e($title).' - School Platform</title></head><body><header class="top"><a class="brand" href="index.php">School Platform</a>';
    if ($u) echo '<span>'.e($u['full_name']).' · '.e(ucfirst($u['role'])).'</span>';
    echo '</header>';
    if ($u) {
        echo '<nav class="nav"><a href="index.php">Dashboard</a>';
        if (can_manage()) echo '<a href="index.php?page=students">Pupils</a><a href="index.php?page=classes">Classes</a><a href="index.php?page=subjects">Subjects</a><a href="index.php?page=staff">Staff</a><a href="index.php?page=admissions">Admissions</a>';
        if (is_staff()) echo '<a href="index.php?page=attendance">Attendance</a><a href="index.php?page=marks">Marks</a><a href="index.php?page=homework">Homework</a><a href="index.php?page=timetable">Timetable</a>';
        echo '<a href="index.php?page=announcements">Announcements</a><a href="index.php?page=events">Calendar</a><a href="index.php?page=reports">Reports</a><a href="logout.php">Logout</a></nav>';
    } else {
        echo '<nav class="nav"><a href="index.php">Home</a><a href="index.php?page=public_news">News</a><a href="index.php?page=public_gallery">Gallery</a><a href="admission.php">Admissions</a><a href="index.php?page=contact">Contact</a><a href="login.php">Portal login</a></nav>';
    }
    echo '<main class="container">';
}
function page_footer() {
    echo '</main><footer class="footer">School Platform · Learning Today, Leading Tomorrow</footer>';
    echo '<script>if ("serviceWorker" in navigator && location.protocol.startsWith("http")) navigator.serviceWorker.register("service-worker.js").catch(()=>{});</script></body></html>';
}
function flash($message, $type='success') {
    echo '<div class="notice '.e($type).'">'.e($message).'</div>';
}
