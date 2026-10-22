<?php
require_once 'config.php';
$message='';
$count=(int)$conn->query("SELECT COUNT(*) n FROM users WHERE role='admin'")->fetch_assoc()['n'];
if ($_SERVER['REQUEST_METHOD']==='POST' && $count===0) {
    $name=trim($_POST['full_name']??''); $username=trim($_POST['username']??''); $password=$_POST['password']??'';
    if ($name!=='' && $username!=='' && strlen($password)>=10) {
        $hash=password_hash($password,PASSWORD_DEFAULT);
        $s=$conn->prepare("INSERT INTO users(full_name,username,password_hash,role) VALUES (?,?,?,'admin')");
        $s->bind_param('sss',$name,$username,$hash); $s->execute();
        $message='Administrator created. IMPORTANT: delete setup_admin.php now, then log in.';
        $count=1;
    } else $message='Enter a name, username and password of at least 10 characters.';
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/style.css"><title>One-time admin setup</title></head><body><main class="container"><section class="card narrow"><h1>First Administrator Setup</h1><p>This page should work only once. Delete setup_admin.php immediately after creating the administrator.</p><?php if($message) echo '<p class="notice">'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>'; if($count===0): ?><form method="post" class="stack"><label>Full name<input name="full_name" required></label><label>Username<input name="username" required></label><label>Password (at least 10 characters)<input type="password" name="password" minlength="10" required></label><button class="button">Create administrator</button></form><?php else: ?><p>An administrator already exists. Delete this file.</p><?php endif; ?></section></main></body></html>
