<?php
require_once 'functions.php';
$message='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $name=trim($_POST['parent_name']??''); $phone=trim($_POST['phone']??''); $email=trim($_POST['email']??''); $class=trim($_POST['class_interest']??''); $msg=trim($_POST['message']??'');
    if ($name!=='' && $phone!=='' && $class!=='') {
        $s=$conn->prepare('INSERT INTO admission_enquiries(parent_name,phone,email,class_interest,message) VALUES (?,?,?,?,?)');
        $s->bind_param('sssss',$name,$phone,$email,$class,$msg); $s->execute(); $message='Thank you. Your enquiry has been submitted.';
    } else $message='Please enter your name, phone number and class of interest.';
}
page_header('Admission Enquiry');
echo '<section class="card narrow"><h1>Admission Enquiry</h1>';
if($message) flash($message);
echo '<form method="post" class="stack"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><label>Parent / guardian name<input name="parent_name" required></label><label>Phone number<input name="phone" required></label><label>Email (optional)<input name="email" type="email"></label><label>Class interested in<input name="class_interest" required placeholder="e.g. Primary 1"></label><label>Message<textarea name="message" rows="4"></textarea></label><button class="button">Submit enquiry</button></form></section>';
page_footer();
