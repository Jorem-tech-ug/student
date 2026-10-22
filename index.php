<?php
require_once 'functions.php';
$page = $_GET['page'] ?? 'dashboard';
$publicPages = ['public_news','public_gallery','contact'];
if (!in_array($page, $publicPages, true) && !logged_in()) {
    page_header('Welcome');
    echo '<section class="hero"><p class="eyebrow">SCHOOL WEBSITE & PARENT PORTAL</p><h1>Learning Today, Leading Tomorrow</h1><p>School information, admissions, announcements, attendance and academic reports in one place.</p><a class="button" href="admission.php">Enquire about admission</a> <a class="button secondary" href="login.php">Portal login</a></section>';
    echo '<section class="grid"><article class="card"><h2>About our school</h2><p>Replace this text with the school history, mission and vision.</p></article><article class="card"><h2>Academics</h2><p>Add the classes and subjects offered by your school.</p></article><article class="card"><h2>Contact</h2><p>Add the school&#39;s official telephone number, address and visiting hours.</p></article></section>';
    $r=$conn->query("SELECT title,body,created_at FROM announcements WHERE published=1 AND audience IN ('public','all') ORDER BY created_at DESC LIMIT 5");
    echo '<section class="card"><h2>Latest news</h2>';
    while($a=$r->fetch_assoc()) echo '<article class="news"><h3>'.e($a['title']).'</h3><p>'.nl2br(e($a['body'])).'</p><small>'.e($a['created_at']).'</small></article>';
    echo '</section>';
    page_footer(); exit;
}
if (in_array($page, ['public_news','public_gallery','contact'], true)) {
    page_header(ucwords(str_replace('_',' ',$page)));
    if ($page==='public_news') {
        echo '<h1>School News and Announcements</h1>';
        $r=$conn->query("SELECT title,body,created_at FROM announcements WHERE published=1 AND audience IN ('public','all') ORDER BY created_at DESC");
        while($a=$r->fetch_assoc()) echo '<article class="card"><h2>'.e($a['title']).'</h2><p>'.nl2br(e($a['body'])).'</p><small>'.e($a['created_at']).'</small></article>';
    } elseif ($page==='public_gallery') {
        echo '<h1>School Gallery</h1><p>School administrators can add the gallery module in a later release. Publish only photos approved by the school and guardians.</p>';
    } else {
        echo '<h1>Contact the School</h1><div class="card"><p>Phone: Add official school number</p><p>Email: Add official school email</p><p>Location: Add school address</p><p>WhatsApp: Add an official WhatsApp contact link</p></div>';
    }
    page_footer(); exit;
}
require_login();
verify_csrf();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action==='add_class') {
            require_roles(['admin','headteacher']);
            $name=trim($_POST['class_name']??''); $year=trim($_POST['academic_year']??'2026');
            if ($name==='') throw new Exception('Enter a class name.');
            $s=$conn->prepare('INSERT INTO classes(class_name,academic_year) VALUES (?,?)'); $s->bind_param('ss',$name,$year); $s->execute(); audit($conn,"Added class $name"); $msg='Class added.';
        } elseif ($action==='add_subject') {
            require_roles(['admin','headteacher']);
            $name=trim($_POST['subject_name']??''); $max=(float)($_POST['max_marks']??100);
            if ($name==='' || $max<=0) throw new Exception('Enter a subject and a valid maximum mark.');
            $s=$conn->prepare('INSERT INTO subjects(subject_name,max_marks) VALUES (?,?)'); $s->bind_param('sd',$name,$max); $s->execute(); audit($conn,"Added subject $name"); $msg='Subject added.';
        } elseif ($action==='add_student') {
            require_roles(['admin','headteacher']);
            $adm=trim($_POST['admission_no']??''); $name=trim($_POST['full_name']??''); $class=(int)($_POST['class_id']??0);
            $guardian=trim($_POST['guardian_name']??''); $phone=trim($_POST['guardian_phone']??''); $gender=trim($_POST['gender']??'');
            if ($adm===''||$name==='') throw new Exception('Admission number and pupil name are required.');
            $s=$conn->prepare('INSERT INTO students(admission_no,full_name,class_id,guardian_name,guardian_phone,gender) VALUES (?,?,?,?,?,?)');
            $s->bind_param('ssisss',$adm,$name,$class,$guardian,$phone,$gender); $s->execute(); audit($conn,"Registered pupil $adm"); $msg='Pupil registered.';
        } elseif ($action==='add_user') {
            require_roles(['admin']);
            $name=trim($_POST['full_name']??''); $username=trim($_POST['username']??''); $password=$_POST['password']??''; $role=$_POST['role']??'teacher';
            if ($name===''||$username===''||strlen($password)<8||!in_array($role,['admin','headteacher','teacher','parent'],true)) throw new Exception('Enter name, username, valid role and a password of at least 8 characters.');
            $hash=password_hash($password,PASSWORD_DEFAULT);
            $s=$conn->prepare('INSERT INTO users(full_name,username,password_hash,role) VALUES (?,?,?,?)'); $s->bind_param('ssss',$name,$username,$hash,$role); $s->execute(); audit($conn,"Created $role account $username"); $msg='Account created.';
        } elseif ($action==='mark_attendance') {
            require_roles(['admin','headteacher','teacher']);
            $class=(int)($_POST['class_id']??0); $date=$_POST['attendance_date']??date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) throw new Exception('Invalid date.');
            $items=$_POST['status']??[];
            $s=$conn->prepare("INSERT INTO attendance(student_id,class_id,attendance_date,status,note,marked_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE class_id=VALUES(class_id),status=VALUES(status),note=VALUES(note),marked_by=VALUES(marked_by)");
            foreach($items as $sid=>$status) {
                $sid=(int)$sid;
                if (!in_array($status,['present','absent','late'],true)) continue;
                $note=trim($_POST['note'][$sid]??''); $uid=(int)current_user()['id'];
                $s->bind_param('iisssi',$sid,$class,$date,$status,$note,$uid); $s->execute();
            }
            audit($conn,"Saved attendance for class $class on $date"); $msg='Attendance saved.';
        } elseif ($action==='save_mark') {
            require_roles(['admin','headteacher','teacher']);
            $sid=(int)($_POST['student_id']??0); $class=(int)($_POST['class_id']??0); $subject=(int)($_POST['subject_id']??0);
            $term=trim($_POST['term']??'Term 1'); $year=trim($_POST['academic_year']??date('Y')); $exam=trim($_POST['exam_name']??'End of Term'); $score=(float)($_POST['score']??-1);
            $q=$conn->prepare('SELECT max_marks FROM subjects WHERE id=?'); $q->bind_param('i',$subject); $q->execute(); $sub=$q->get_result()->fetch_assoc();
            if (!$sub || $score<0 || $score>(float)$sub['max_marks']) throw new Exception('Mark must be between zero and the subject maximum.');
            $uid=(int)current_user()['id'];
            $s=$conn->prepare('INSERT INTO marks(student_id,class_id,subject_id,term,academic_year,exam_name,score,entered_by) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE class_id=VALUES(class_id),score=VALUES(score),entered_by=VALUES(entered_by)');
            $s->bind_param('iiisssdi',$sid,$class,$subject,$term,$year,$exam,$score,$uid); $s->execute(); audit($conn,"Entered/updated marks for pupil ID $sid"); $msg='Mark saved.';
        } elseif ($action==='add_announcement') {
            require_roles(['admin','headteacher','teacher']);
            $title=trim($_POST['title']??''); $body=trim($_POST['body']??''); $aud=$_POST['audience']??'public';
            if ($title===''||$body===''||!in_array($aud,['public','parents','staff','all'],true)) throw new Exception('Complete the announcement fields.');
            $uid=(int)current_user()['id']; $s=$conn->prepare('INSERT INTO announcements(title,body,audience,created_by) VALUES (?,?,?,?)'); $s->bind_param('sssi',$title,$body,$aud,$uid); $s->execute(); audit($conn,"Published announcement: $title"); $msg='Announcement published.';
        } elseif ($action==='add_homework') {
            require_roles(['admin','headteacher','teacher']);
            $class=(int)($_POST['class_id']??0); $subject=(int)($_POST['subject_id']??0); $title=trim($_POST['title']??''); $instructions=trim($_POST['instructions']??''); $due=$_POST['due_date']?:null; $uid=(int)current_user()['id'];
            if ($title===''||$instructions==='') throw new Exception('Enter a title and instructions.');
            $s=$conn->prepare('INSERT INTO homework(class_id,subject_id,title,instructions,due_date,created_by) VALUES (?,?,?,?,?,?)'); $s->bind_param('iisssi',$class,$subject,$title,$instructions,$due,$uid); $s->execute(); audit($conn,"Added homework: $title"); $msg='Homework added.';
        } elseif ($action==='add_event') {
            require_roles(['admin','headteacher']);
            $title=trim($_POST['title']??''); $date=$_POST['event_date']??''; $desc=trim($_POST['description']??''); $uid=(int)current_user()['id'];
            if ($title===''||$date==='') throw new Exception('Enter an event title and date.');
            $s=$conn->prepare('INSERT INTO school_events(title,event_date,description,created_by) VALUES (?,?,?,?)'); $s->bind_param('sssi',$title,$date,$desc,$uid); $s->execute(); audit($conn,"Added event: $title"); $msg='Event added.';
        } elseif ($action==='enquiry_status') {
            require_roles(['admin','headteacher']);
            $id=(int)($_POST['id']??0); $status=$_POST['status']??'new';
            if (!in_array($status,['new','contacted','accepted','declined'],true)) throw new Exception('Invalid status.');
            $s=$conn->prepare('UPDATE admission_enquiries SET status=? WHERE id=?'); $s->bind_param('si',$status,$id); $s->execute(); audit($conn,"Updated admission enquiry $id"); $msg='Enquiry updated.';
        } else { throw new Exception('Unknown action.'); }
    } catch (Throwable $ex) {
        $msg='Could not complete action: '.$ex->getMessage();
        $msgType='error';
    }
    page_header('School Portal');
    if (isset($msg)) flash($msg, $msgType ?? 'success');
    echo '<p><a class="button secondary" href="index.php?page='.e($page).'">Return to section</a></p>';
    page_footer(); exit;
}

page_header('Dashboard');
if (isset($_GET['msg'])) flash($_GET['msg']);
$role=current_user()['role'];
if ($role==='parent') {
    echo '<h1>Parent Dashboard</h1><div class="grid">';
    $s=$conn->prepare('SELECT st.id,st.full_name,st.admission_no,c.class_name FROM parent_students ps JOIN students st ON st.id=ps.student_id LEFT JOIN classes c ON c.id=st.class_id WHERE ps.user_id=?');
    $uid=(int)current_user()['id']; $s->bind_param('i',$uid); $s->execute(); $children=$s->get_result();
    while($ch=$children->fetch_assoc()) echo '<article class="card"><h2>'.e($ch['full_name']).'</h2><p>Admission: '.e($ch['admission_no']).'</p><p>Class: '.e($ch['class_name']).'</p><a href="index.php?page=child&id='.(int)$ch['id'].'">View attendance and results</a></article>';
    echo '</div><h2>Parent announcements</h2>';
    $r=$conn->query("SELECT title,body,created_at FROM announcements WHERE published=1 AND audience IN ('parents','all') ORDER BY created_at DESC LIMIT 10");
    while($a=$r->fetch_assoc()) echo '<article class="card"><h3>'.e($a['title']).'</h3><p>'.nl2br(e($a['body'])).'</p></article>';
} else {
    echo '<h1>'.(can_manage()?'Administration Dashboard':'Teacher Dashboard').'</h1>';
    $queries=[
      ['Pupils',"SELECT COUNT(*) n FROM students WHERE status='active'"],
      ['Teachers / staff',"SELECT COUNT(*) n FROM users WHERE role IN ('teacher','headteacher','admin') AND active=1"],
      ['Classes',"SELECT COUNT(*) n FROM classes"],
      ['Subjects',"SELECT COUNT(*) n FROM subjects"],
      ["Today's attendance","SELECT COUNT(*) n FROM attendance WHERE attendance_date=CURDATE()"],
      ['Published announcements',"SELECT COUNT(*) n FROM announcements WHERE published=1"]
    ];
    echo '<div class="grid stats">';
    foreach($queries as [$label,$sql]) { $n=(int)$conn->query($sql)->fetch_assoc()['n']; echo '<article class="stat"><span>'.e($label).'</span><strong>'.$n.'</strong></article>'; }
    echo '</div>';
    echo '<section class="card"><h2>Quick actions</h2><div class="actions">';
    if(can_manage()) echo '<a class="button" href="index.php?page=students">Manage pupils</a><a class="button" href="index.php?page=classes">Classes</a><a class="button" href="index.php?page=subjects">Subjects</a><a class="button" href="index.php?page=staff">Staff accounts</a>';
    echo '<a class="button" href="index.php?page=attendance">Attendance</a><a class="button" href="index.php?page=marks">Enter marks</a><a class="button" href="index.php?page=announcements">Announcements</a><a class="button" href="index.php?page=homework">Homework</a><a class="button" href="index.php?page=timetable">Timetable</a><a class="button" href="index.php?page=reports">Reports</a>';
    if(can_manage()) echo '<a class="button" href="index.php?page=admissions">Admission enquiries</a><a class="button" href="index.php?page=events">School calendar</a>';
    echo '</div></section>';
    echo '<section class="card"><h2>Recent announcements</h2>';
    $r=$conn->query("SELECT title,body,created_at FROM announcements WHERE published=1 AND audience IN ('public','all','staff') ORDER BY created_at DESC LIMIT 5");
    while($a=$r->fetch_assoc()) echo '<article class="news"><h3>'.e($a['title']).'</h3><p>'.nl2br(e($a['body'])).'</p><small>'.e($a['created_at']).'</small></article>';
    echo '</section>';
}
switch($page) {
case 'students':
    require_roles(['admin','headteacher']);
    echo '<h1>Pupil Records</h1><form method="post" class="card form-grid"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="add_student"><label>Admission number<input name="admission_no" required></label><label>Full name<input name="full_name" required></label><label>Class<select name="class_id" required><option value="">Choose class</option>'; select_options($conn,'classes','id','class_name'); echo '</select></label><label>Gender<select name="gender"><option>Not specified</option><option>Female</option><option>Male</option></select></label><label>Guardian name<input name="guardian_name"></label><label>Guardian phone<input name="guardian_phone"></label><button class="button">Register pupil</button></form>';
    $r=$conn->query("SELECT s.*,c.class_name FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE s.status='active' ORDER BY s.full_name");
    echo '<div class="table-wrap"><table><tr><th>Admission no.</th><th>Name</th><th>Class</th><th>Guardian</th><th>Phone</th></tr>'; while($x=$r->fetch_assoc()) echo '<tr><td>'.e($x['admission_no']).'</td><td>'.e($x['full_name']).'</td><td>'.e($x['class_name']).'</td><td>'.e($x['guardian_name']).'</td><td>'.e($x['guardian_phone']).'</td></tr>'; echo '</table></div>'; break;
case 'classes':
    require_roles(['admin','headteacher']);
    echo '<h1>Classes</h1><form method="post" class="card form-grid"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="add_class"><label>Class name<input name="class_name" placeholder="P1 or Baby Class" required></label><label>Academic year<input name="academic_year" value="'.date('Y').'" required></label><button class="button">Add class</button></form><div class="card"><h2>Existing classes</h2><ul>';
    $r=$conn->query('SELECT class_name,academic_year FROM classes ORDER BY class_name'); while($x=$r->fetch_assoc()) echo '<li>'.e($x['class_name']).' — '.e($x['academic_year']).'</li>'; echo '</ul></div>'; break;
case 'subjects':
    require_roles(['admin','headteacher']);
    echo '<h1>Subjects</h1><form method="post" class="card form-grid"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="add_subject"><label>Subject name<input name="subject_name" required></label><label>Maximum marks<input name="max_marks" type="number" min="1" step="0.01" value="100" required></label><button class="button">Add subject</button></form><div class="card"><ul>';
    $r=$conn->query('SELECT subject_name,max_marks FROM subjects ORDER BY subject_name'); while($x=$r->fetch_assoc()) echo '<li>'.e($x['subject_name']).' — maximum '.e($x['max_marks']).'</li>'; echo '</ul></div>'; break;
case 'staff':
    require_roles(['admin']);
    echo '<h1>Staff and User Accounts</h1><form method="post" class="card form-grid"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="add_user"><label>Full name<input name="full_name" required></label><label>Username<input name="username" required></label><label>Temporary password (8+ characters)<input type="password" name="password" minlength="8" required></label><label>Role<select name="role"><option value="teacher">Teacher</option><option value="headteacher">Headteacher</option><option value="parent">Parent</option><option value="admin">Administrator</option></select></label><button class="button">Create account</button></form><div class="table-wrap"><table><tr><th>Name</th><th>Username</th><th>Role</th><th>Active</th></tr>';
    $r=$conn->query('SELECT full_name,username,role,active FROM users ORDER BY full_name'); while($x=$r->fetch_assoc()) echo '<tr><td>'.e($x['full_name']).'</td><td>'.e($x['username']).'</td><td>'.e($x['role']).'</td><td>'.($x['active']?'Yes':'No').'</td></tr>'; echo '</table></div>'; break;
case 'attendance':
    require_roles(['admin','headteacher','teacher']);
    $class=(int)($_GET['class_id']??0); $date=$_GET['date']??date('Y-m-d');
    echo '<h1>Attendance Register</h1><form method="get" class="card inline-form"><input type="hidden" name="page" value="attendance"><label>Class<select name="class_id" required><option value="">Choose class</option>'; select_options($conn,'classes','id','class_name',$class); echo '</select></label><label>Date<input type="date" name="date" value="'.e($date).'" required></label><button class="button secondary">Load register</button></form>';
    if($class){ $s=$conn->prepare('SELECT id,admission_no,full_name FROM students WHERE class_id=? AND status="active" ORDER BY full_name'); $s->bind_param('i',$class); $s->execute(); $students=$s->get_result(); echo '<form method="post" class="card"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="mark_attendance"><input type="hidden" name="class_id" value="'.$class.'"><input type="hidden" name="attendance_date" value="'.e($date).'"><div class="table-wrap"><table><tr><th>Admission no.</th><th>Pupil</th><th>Status</th><th>Note (optional)</th></tr>';
    while($st=$students->fetch_assoc()){ $s2=$conn->prepare('SELECT status,note FROM attendance WHERE student_id=? AND attendance_date=?'); $s2->bind_param('is',$st['id'],$date); $s2->execute(); $old=$s2->get_result()->fetch_assoc(); echo '<tr><td>'.e($st['admission_no']).'</td><td>'.e($st['full_name']).'</td><td><select name="status['.$st['id'].']"><option value="present" '.(($old['status']??'present')==='present'?'selected':'').'>Present</option><option value="absent" '.(($old['status']??'')==='absent'?'selected':'').'>Absent</option><option value="late" '.(($old['status']??'')==='late'?'selected':'').'>Late</option></select></td><td><input name="note['.$st['id'].']" value="'.e($old['note']??'').'"></td></tr>'; }
    echo '</table></div><button class="button">Save attendance</button></form>'; } break;
case 'marks':
    require_roles(['admin','headteacher','teacher']);
    echo '<h1>Enter Academic Marks</h1><form method="post" class="card form-grid"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="save_mark"><label>Pupil<select name="student_id" required><option value="">Choose pupil</option>'; select_options($conn,'students','id','full_name'); echo '</select></label><label>Class<select name="class_id" required><option value="">Choose class</option>'; select_options($conn,'classes','id','class_name'); echo '</select></label><label>Subject<select name="subject_id" required><option value="">Choose subject</option>'; select_options($conn,'subjects','id','subject_name'); echo '</select></label><label>Term<input name="term" value="Term 1" required></label><label>Academic year<input name="academic_year" value="'.date('Y').'" required></label><label>Exam name<input name="exam_name" value="End of Term" required></label><label>Score<input name="score" type="number" min="0" step="0.01" required></label><button class="button">Save mark</button></form><p>Marks are checked against each subject maximum mark.</p>'; break;
case 'announcements':
    echo '<h1>Communication Centre</h1>';
    if(is_staff()) echo '<form method="post" class="card form-grid"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="add_announcement"><label>Title<input name="title" required></label><label>Audience<select name="audience"><option value="public">Public website</option><option value="parents">Parents portal</option><option value="staff">Staff</option><option value="all">Public and all portal users</option></select></label><label class="wide">Announcement<textarea name="body" rows="4" required></textarea></label><button class="button">Publish announcement</button></form>';
    $where = current_user()['role']==='parent' ? "audience IN ('parents','all')" : (current_user()['role']==='teacher' ? "audience IN ('staff','all','public')" : "1=1");
    $r=$conn->query("SELECT a.title,a.body,a.audience,a.created_at,u.full_name FROM announcements a JOIN users u ON u.id=a.created_by WHERE a.published=1 AND $where ORDER BY a.created_at DESC");
    while($a=$r->fetch_assoc()) echo '<article class="card"><h2>'.e($a['title']).'</h2><p>'.nl2br(e($a['body'])).'</p><small>'.e($a['audience']).' · '.e($a['created_at']).' · '.e($a['full_name']).'</small></article>'; break;
case 'homework':
    require_roles(['admin','headteacher','teacher','parent']);
    echo '<h1>Homework and Assignments</h1>';
    if(is_staff()) echo '<form method="post" class="card form-grid"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="add_homework"><label>Class<select name="class_id" required><option value="">Choose class</option>'; select_options($conn,'classes','id','class_name'); echo '</select></label><label>Subject<select name="subject_id"><option value="">Choose subject</option>'; select_options($conn,'subjects','id','subject_name'); echo '</select></label><label>Title<input name="title" required></label><label>Due date<input type="date" name="due_date"></label><label class="wide">Instructions<textarea name="instructions" rows="3" required></textarea></label><button class="button">Post homework</button></form>';
    $r=$conn->query('SELECT h.title,h.instructions,h.due_date,c.class_name,s.subject_name FROM homework h JOIN classes c ON c.id=h.class_id LEFT JOIN subjects s ON s.id=h.subject_id ORDER BY h.created_at DESC');
    while($x=$r->fetch_assoc()) echo '<article class="card"><h2>'.e($x['title']).'</h2><p>'.nl2br(e($x['instructions'])).'</p><small>'.e($x['class_name']).' · '.e($x['subject_name']).' · Due: '.e($x['due_date']??'Not set').'</small></article>'; break;
case 'timetable':
    echo '<h1>Class Timetable</h1>';
    if(can_manage()) echo '<p>Timetable viewing is enabled. Add timetable editing in the next development stage after confirming the school lesson periods and teacher assignments.</p>';
    $r=$conn->query('SELECT c.class_name,s.subject_name,t.day_name,t.start_time,t.end_time,t.room FROM timetable t JOIN classes c ON c.id=t.class_id LEFT JOIN subjects s ON s.id=t.subject_id ORDER BY FIELD(t.day_name,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"),t.start_time');
    echo '<div class="table-wrap"><table><tr><th>Class</th><th>Day</th><th>Time</th><th>Subject</th><th>Room</th></tr>'; while($x=$r->fetch_assoc()) echo '<tr><td>'.e($x['class_name']).'</td><td>'.e($x['day_name']).'</td><td>'.e(substr($x['start_time'],0,5)).'–'.e(substr($x['end_time'],0,5)).'</td><td>'.e($x['subject_name']).'</td><td>'.e($x['room']).'</td></tr>'; echo '</table></div>'; break;
case 'admissions':
    require_roles(['admin','headteacher']);
    echo '<h1>Admission Enquiries</h1><div class="table-wrap"><table><tr><th>Parent</th><th>Phone</th><th>Class</th><th>Message</th><th>Status</th><th>Update</th></tr>';
    $r=$conn->query('SELECT * FROM admission_enquiries ORDER BY created_at DESC');
    while($x=$r->fetch_assoc()){ echo '<tr><td>'.e($x['parent_name']).'</td><td>'.e($x['phone']).'</td><td>'.e($x['class_interest']).'</td><td>'.e($x['message']).'</td><td>'.e($x['status']).'</td><td><form method="post"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="enquiry_status"><input type="hidden" name="id" value="'.$x['id'].'"><select name="status"><option>new</option><option>contacted</option><option>accepted</option><option>declined</option></select><button class="button small">Save</button></form></td></tr>'; }
    echo '</table></div>'; break;
case 'events':
    echo '<h1>School Calendar and Events</h1>';
    if(can_manage()) echo '<form method="post" class="card form-grid"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="add_event"><label>Event title<input name="title" required></label><label>Date<input type="date" name="event_date" required></label><label class="wide">Description<textarea name="description" rows="3"></textarea></label><button class="button">Add event</button></form>';
    $r=$conn->query('SELECT title,event_date,description FROM school_events ORDER BY event_date');
    while($x=$r->fetch_assoc()) echo '<article class="card"><h2>'.e($x['title']).'</h2><p>'.e($x['event_date']).'</p><p>'.nl2br(e($x['description'])).'</p></article>'; break;
case 'reports':
    require_roles(['admin','headteacher','teacher']);
    echo '<h1>Reports and Downloads</h1><p>Use your browser Print command to print this page or choose “Save as PDF”.</p><section class="card"><h2>Attendance today</h2>';
    $r=$conn->query("SELECT c.class_name, SUM(a.status='present') present_count, SUM(a.status='absent') absent_count, SUM(a.status='late') late_count, COUNT(*) total FROM attendance a JOIN classes c ON c.id=a.class_id WHERE a.attendance_date=CURDATE() GROUP BY c.id ORDER BY c.class_name");
    echo '<div class="table-wrap"><table><tr><th>Class</th><th>Present</th><th>Absent</th><th>Late</th><th>Marked</th></tr>'; while($x=$r->fetch_assoc()) echo '<tr><td>'.e($x['class_name']).'</td><td>'.(int)$x['present_count'].'</td><td>'.(int)$x['absent_count'].'</td><td>'.(int)$x['late_count'].'</td><td>'.(int)$x['total'].'</td></tr>'; echo '</table></div></section>';
    echo '<section class="card"><h2>Class list</h2><div class="table-wrap"><table><tr><th>Admission</th><th>Pupil</th><th>Class</th></tr>';
    $r=$conn->query("SELECT s.admission_no,s.full_name,c.class_name FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE s.status='active' ORDER BY c.class_name,s.full_name"); while($x=$r->fetch_assoc()) echo '<tr><td>'.e($x['admission_no']).'</td><td>'.e($x['full_name']).'</td><td>'.e($x['class_name']).'</td></tr>'; echo '</table></div><button class="button" onclick="window.print()">Print / Save PDF</button></section>'; break;
case 'child':
    require_roles(['parent']);
    $sid=(int)($_GET['id']??0); $uid=(int)current_user()['id']; $s=$conn->prepare('SELECT st.full_name,st.admission_no,c.class_name FROM parent_students ps JOIN students st ON st.id=ps.student_id LEFT JOIN classes c ON c.id=st.class_id WHERE ps.user_id=? AND st.id=?'); $s->bind_param('ii',$uid,$sid); $s->execute(); $child=$s->get_result()->fetch_assoc();
    if(!$child) { echo '<p>You are not authorised to view this pupil.</p>'; break; }
    echo '<h1>'.e($child['full_name']).' — Academic and Attendance Record</h1><p>Admission: '.e($child['admission_no']).' · Class: '.e($child['class_name']).'</p><section class="card"><h2>Attendance</h2>';
    $s=$conn->prepare('SELECT attendance_date,status FROM attendance WHERE student_id=? ORDER BY attendance_date DESC LIMIT 60'); $s->bind_param('i',$sid); $s->execute(); $r=$s->get_result(); while($x=$r->fetch_assoc()) echo '<p>'.e($x['attendance_date']).' — '.e(ucfirst($x['status'])).'</p>'; echo '</section><section class="card"><h2>Published marks</h2><p>Ask the school to confirm its result publication policy before exposing marks in this portal. A production release should implement explicit publication status.</p></section>'; break;
default:
    break;
}
page_footer();
