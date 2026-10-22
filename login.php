<?php
require_once 'functions.php';
if (logged_in()) { header('Location: index.php'); exit; }
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $username=trim($_POST['username']??''); $password=$_POST['password']??'';
    $s=$conn->prepare('SELECT id,full_name,username,password_hash,role,active FROM users WHERE username=? LIMIT 1');
    $s->bind_param('s',$username); $s->execute(); $user=$s->get_result()->fetch_assoc();
    if ($user && $user['active'] && password_verify($password,$user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user']=['id'=>(int)$user['id'],'full_name'=>$user['full_name'],'username'=>$user['username'],'role'=>$user['role']];
        audit($conn,'User logged in'); header('Location: index.php'); exit;
    }
    $error='Login failed. Check your username and password.';
}
page_header('Login');
echo '<section class="card narrow"><h1>School Portal Login</h1>';
if($error) flash($error,'error');
echo '<form method="post" class="stack"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><label>Username<input name="username" autocomplete="username" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="button">Login</button></form><p>First time? Run the one-time setup file described in README.txt, then delete it.</p></section>';
page_footer();
