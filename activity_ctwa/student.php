<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require "config.php";

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'teacher';
$message = $_SESSION['student_message'] ?? '';
$error = '';
unset($_SESSION['student_message']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

try {
    $pdo = getPDO();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid request. Refresh the page and try again.');
        }

        $action = $_POST['student_action'] ?? '';
        $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);

        if ($action === 'save') {
            $studentNumber = trim($_POST['student_number'] ?? '');
            $studentName = trim($_POST['full_name'] ?? '');

            if ($studentNumber === '' || $studentName === '') {
                $error = 'Student number and full name are required.';
            } elseif (mb_strlen($studentNumber) > 50 || mb_strlen($studentName) > 150) {
                $error = 'Student number or full name is too long.';
            } else {
                if ($studentId) {
                    $stmt = $pdo->prepare('UPDATE students SET student_number = ?, full_name = ? WHERE id = ?');
                    $stmt->execute([$studentNumber, $studentName, $studentId]);
                    $_SESSION['student_message'] = 'Student information updated successfully.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO students (student_number, full_name) VALUES (?, ?)');
                    $stmt->execute([$studentNumber, $studentName]);
                    $_SESSION['student_message'] = 'New student added successfully.';
                }
                header('Location: student.php');
                exit;
            }
        } elseif ($action === 'delete' && $studentId) {
            try {
                $stmt = $pdo->prepare('DELETE FROM students WHERE id = ?');
                $stmt->execute([$studentId]);
                $_SESSION['student_message'] = 'Student record deleted successfully.';
                header('Location: student.php');
                exit;
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000') {
                    $error = 'This student has attendance records. Delete or update those records first.';
                } else {
                    throw $ex;
                }
            }
        }
    }

    $editStudent = null;
    if (isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
        $stmt = $pdo->prepare('SELECT id, student_number, full_name FROM students WHERE id = ?');
        $stmt->execute([(int) $_GET['edit']]);
        $editStudent = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $search = trim($_GET['search'] ?? '');
    $letter = strtoupper(trim($_GET['letter'] ?? ''));
    if (!preg_match('/^[A-Z]$/', $letter)) {
        $letter = '';
    }

    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(full_name LIKE ? OR student_number LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    if ($letter !== '') {
        $where[] = 'UPPER(LEFT(TRIM(full_name), 1)) = ?';
        $params[] = $letter;
    }

    $sql = 'SELECT id, student_number, full_name FROM students';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY UPPER(full_name) ASC, student_number ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $letterCounts = $pdo->query("SELECT UPPER(LEFT(TRIM(full_name), 1)) AS initial, COUNT(*) AS total FROM students GROUP BY UPPER(LEFT(TRIM(full_name), 1))")->fetchAll(PDO::FETCH_KEY_PAIR);
    $totalStudents = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();

} catch (Throwable $ex) {
    error_log($ex->getMessage());
    if ($error === '') {
        $error = 'Unable to load student records. Check your database connection and table structure.';
    }
    $students = $students ?? [];
    $letterCounts = $letterCounts ?? [];
    $totalStudents = $totalStudents ?? 0;
    $editStudent = $editStudent ?? null;
}

$groupedStudents = [];
foreach ($students as $student) {
    $initial = strtoupper(mb_substr(ltrim($student['full_name']), 0, 1));
    if (!preg_match('/^[A-Z]$/', $initial)) {
        $initial = '#';
    }
    $groupedStudents[$initial][] = $student;
}
$alphabet = range('A', 'Z');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Students | Attendance Monitoring System</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:"Segoe UI",Arial,sans-serif}
:root{--green:#087f5b;--dark:#03422f;--light:#e9f7ef;--bg:#f4f7f5;--text:#20392d;--muted:#829087;--border:#e5ece7}
body{min-height:100vh;background:var(--bg);color:var(--text)}
.layout{min-height:100vh;display:flex}
.sidebar{width:255px;flex-shrink:0;background:var(--dark);color:#fff;padding:26px 18px;display:flex;flex-direction:column}
.brand{display:flex;align-items:center;gap:12px;padding:0 8px 30px;border-bottom:1px solid #ffffff24}
.brand-icon{width:43px;height:43px;border-radius:13px;background:#e0ffed;color:var(--green);display:grid;place-items:center;font-size:22px;font-weight:800}
.brand h2{font-size:16px;line-height:1.4}.brand p{font-size:11px;color:#b9d9c9;margin-top:3px}
.nav-title{margin:28px 10px 12px;font-size:10px;color:#a7c7b7;text-transform:uppercase;letter-spacing:1.5px}
.nav-link{display:flex;align-items:center;gap:13px;padding:13px 14px;margin-bottom:7px;color:#d8eee2;text-decoration:none;border-radius:10px;font-size:14px;transition:.2s}
.nav-link:hover,.nav-link.active{background:#ffffff19;color:#fff}.nav-link.active{border-left:3px solid #7de0ac}.nav-icon{width:23px;text-align:center;font-size:17px}
.sidebar-bottom{margin-top:auto;padding-top:20px}.user-mini{padding:15px 10px;border-top:1px solid #ffffff24;margin-bottom:10px;overflow-wrap:anywhere}.user-mini strong{display:block;font-size:13px}.user-mini span{display:block;font-size:12px;color:#b9d9c9;margin-top:4px;text-transform:capitalize}.logout{display:block;color:#ffd5d5;text-decoration:none;padding:12px 14px;border-radius:9px;font-size:14px}.logout:hover{background:#ffffff19}
.main{flex:1;min-width:0;padding:30px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:28px}.topbar h1{font-size:28px;color:#173e2d}.topbar p{margin-top:7px;color:var(--muted);font-size:14px}.profile{display:flex;align-items:center;gap:12px;background:#fff;padding:10px 15px;border:1px solid var(--border);border-radius:13px}.avatar{width:40px;height:40px;border-radius:50%;background:var(--light);color:var(--green);display:grid;place-items:center;font-weight:800}.profile strong{display:block;font-size:13px}.profile small{display:block;margin-top:4px;color:var(--muted);text-transform:capitalize}
.summary{display:flex;justify-content:space-between;align-items:center;gap:15px;background:linear-gradient(110deg,#087f5b,#12a16c);color:#fff;padding:22px 25px;border-radius:16px;margin-bottom:22px}.summary h2{font-size:19px;margin-bottom:5px}.summary p{font-size:13px;color:#d8f3e5}.summary-count{background:#ffffff20;border-radius:12px;padding:12px 17px;text-align:center;white-space:nowrap}.summary-count strong{display:block;font-size:24px}.summary-count span{font-size:11px;color:#d8f3e5}
.card{background:#fff;border:1px solid var(--border);border-radius:15px;padding:22px;margin-bottom:22px}.card-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}.card-heading h2{font-size:17px;color:#173e2d}.card-heading p{font-size:12px;color:var(--muted);margin-top:5px}
.alert{padding:12px 14px;border-radius:10px;margin-bottom:16px;font-size:13px}.success{background:#e8f7ed;color:#08734f;border:1px solid #c9ead5}.error{background:#fff0ef;color:#a93226;border:1px solid #f4ceca}
.form-grid{display:grid;grid-template-columns:1fr 1.5fr auto;gap:12px;align-items:end}.field label{display:block;font-size:12px;font-weight:700;color:#52645a;margin-bottom:7px}.field input{width:100%;height:43px;border:1px solid #dce6df;border-radius:9px;padding:0 12px;outline:none;font-size:13px}.field input:focus{border-color:var(--green);box-shadow:0 0 0 3px #087f5b14}
.btn{border:0;border-radius:9px;padding:12px 17px;background:var(--green);color:#fff;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;justify-content:center;align-items:center;gap:6px}.btn:hover{background:#066b4d}.btn-secondary{background:#edf3ef;color:#42564a}.btn-secondary:hover{background:#e0eae3}.btn-danger{background:#fff0ef;color:#bd3d32}.btn-danger:hover{background:#ffe1de}.btn-small{padding:7px 10px;font-size:11px}
.search-row{display:flex;gap:10px;margin-bottom:17px}.search-row input{flex:1;min-width:0;height:42px;border:1px solid #dce6df;border-radius:9px;padding:0 13px;outline:none}.search-row input:focus{border-color:var(--green)}
.alphabet{display:flex;flex-wrap:wrap;gap:6px;margin:16px 0 20px}.letter{width:32px;height:32px;border:1px solid var(--border);border-radius:8px;display:grid;place-items:center;text-decoration:none;color:#53665a;font-size:12px;font-weight:700;background:#fff}.letter:hover,.letter.active{background:var(--green);border-color:var(--green);color:#fff}.letter.disabled{opacity:.35}
.group{margin-top:20px}.group-title{display:flex;align-items:center;gap:10px;padding-bottom:10px;border-bottom:1px solid var(--border);margin-bottom:10px}.group-letter{width:32px;height:32px;border-radius:9px;background:var(--light);color:var(--green);display:grid;place-items:center;font-weight:800}.group-title h3{font-size:14px}.group-title span{font-size:11px;color:var(--muted)}
.student-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.student-item{display:flex;align-items:center;gap:12px;padding:13px;border:1px solid #edf1ee;border-radius:11px;min-width:0}.student-avatar{width:39px;height:39px;flex-shrink:0;border-radius:50%;background:#eef6f0;color:var(--green);display:grid;place-items:center;font-weight:800}.student-info{flex:1;min-width:0}.student-info strong{display:block;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.student-info small{display:block;color:var(--muted);font-size:11px;margin-top:4px}.student-actions{display:flex;gap:5px;flex-shrink:0}.empty{text-align:center;padding:38px 15px;color:var(--muted);font-size:13px}
@media(max-width:1050px){.student-list{grid-template-columns:1fr}}
@media(max-width:760px){.layout{display:block}.sidebar{width:100%;padding:15px}.brand{padding-bottom:15px}.nav-title,.sidebar-bottom{display:none}.sidebar nav{display:flex;gap:6px;overflow-x:auto;padding-top:12px}.nav-link{flex-shrink:0;margin:0;padding:10px;font-size:12px}.nav-icon{display:none}.main{padding:20px 15px}.topbar{align-items:flex-start}.topbar h1{font-size:23px}.profile{padding:7px}.profile div:last-child{display:none}.form-grid{grid-template-columns:1fr 1fr}.form-grid .field:nth-child(2){grid-column:1/-1}.form-grid button{grid-column:1/-1}.card{padding:17px}}
@media(max-width:430px){.summary{padding:17px}.summary-count{padding:9px}.summary-count strong{font-size:20px}.student-item{padding:10px}.student-actions{flex-direction:column}.alphabet{gap:4px}.letter{width:29px;height:29px}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <div class="brand"><div class="brand-icon">A</div><div><h2>Attendance System</h2><p>Monitoring Portal</p></div></div>
    <p class="nav-title">Main Menu</p>
    <nav>
        <a href="dashboard.php" class="nav-link"><span class="nav-icon">▦</span>Dashboard</a>
        <a href="student.php" class="nav-link active"><span class="nav-icon">♙</span>Students</a>
        <a href="attendance.php" class="nav-link"><span class="nav-icon">▣</span>Attendance</a>
        <a href="reports.php" class="nav-link"><span class="nav-icon">▤</span>Reports</a>
    </nav>
    <div class="sidebar-bottom"><div class="user-mini"><strong><?= e($fullName) ?></strong><span><?= e($role) ?></span></div><a href="logout.php" class="logout">↪ &nbsp; Logout</a></div>
</aside>
<main class="main">
    <header class="topbar">
        <div><h1>Students</h1><p>Manage and browse student records alphabetically.</p></div>
        <div class="profile"><div class="avatar"><?= e(strtoupper(mb_substr($fullName, 0, 1))) ?></div><div><strong><?= e($fullName) ?></strong><small><?= e($role) ?></small></div></div>
    </header>

    <section class="summary"><div><h2>Student Directory</h2><p>All registered students arranged by the first letter of their name.</p></div><div class="summary-count"><strong><?= (int) $totalStudents ?></strong><span>Total Students</span></div></section>

    <?php if ($message): ?><div class="alert success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <section class="card" id="student-form">
        <div class="card-heading"><div><h2><?= $editStudent ? 'Edit Student' : 'Add New Student' ?></h2><p>Enter the student's information below.</p></div></div>
        <form method="POST" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="student_action" value="save">
            <input type="hidden" name="student_id" value="<?= (int) ($editStudent['id'] ?? 0) ?>">
            <div class="field"><label for="student_number">Student Number</label><input id="student_number" name="student_number" maxlength="50" required placeholder="e.g. 2026-0001" value="<?= e($editStudent['student_number'] ?? ($_POST['student_number'] ?? '')) ?>"></div>
            <div class="field"><label for="full_name">Full Name</label><input id="full_name" name="full_name" maxlength="150" required placeholder="Last name, First name" value="<?= e($editStudent['full_name'] ?? ($_POST['full_name'] ?? '')) ?>"></div>
            <button class="btn" type="submit"><?= $editStudent ? 'Save Changes' : '+ Add Student' ?></button>
        </form>
        <?php if ($editStudent): ?><p style="margin-top:13px"><a href="student.php" style="color:var(--green);font-size:12px;font-weight:700;text-decoration:none">Cancel editing</a></p><?php endif; ?>
    </section>

    <section class="card">
        <div class="card-heading"><div><h2>Student List</h2><p><?= count($students) ?> student(s) shown · Sorted A to Z</p></div></div>
        <form method="GET" class="search-row">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search by student name or number...">
            <?php if ($letter): ?><input type="hidden" name="letter" value="<?= e($letter) ?>"><?php endif; ?>
            <button class="btn" type="submit">Search</button>
            <a href="student.php" class="btn btn-secondary">Reset</a>
        </form>
        <div class="alphabet">
            <a class="letter <?= $letter === '' ? 'active' : '' ?>" href="student.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>">All</a>
            <?php foreach ($alphabet as $char): $hasStudents = !empty($letterCounts[$char]); ?>
                <a class="letter <?= $letter === $char ? 'active' : '' ?> <?= !$hasStudents ? 'disabled' : '' ?>" href="student.php?letter=<?= $char ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" title="<?= (int) ($letterCounts[$char] ?? 0) ?> student(s)"><?= $char ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (!$students): ?>
            <div class="empty">No students found. Add a student or change your search.</div>
        <?php else: ?>
            <?php foreach ($groupedStudents as $initial => $group): ?>
                <div class="group">
                    <div class="group-title"><div class="group-letter"><?= e($initial) ?></div><h3><?= $initial === '#' ? 'Other Names' : 'Letter ' . e($initial) ?></h3><span><?= count($group) ?> student(s)</span></div>
                    <div class="student-list">
                        <?php foreach ($group as $student): ?>
                            <article class="student-item">
                                <div class="student-avatar"><?= e(strtoupper(mb_substr(ltrim($student['full_name']), 0, 1))) ?></div>
                                <div class="student-info"><strong><?= e($student['full_name']) ?></strong><small><?= e($student['student_number']) ?></small></div>
                                <div class="student-actions">
                                    <a class="btn btn-secondary btn-small" href="student.php?edit=<?= (int) $student['id'] ?>#student-form">Edit</a>
                                    <form method="POST" onsubmit="return confirm('Delete this student record? This may fail if attendance records are linked.');">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="student_action" value="delete">
                                        <input type="hidden" name="student_id" value="<?= (int) $student['id'] ?>">
                                        <button class="btn btn-danger btn-small" type="submit">Delete</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>
</div>
</body>
</html>
