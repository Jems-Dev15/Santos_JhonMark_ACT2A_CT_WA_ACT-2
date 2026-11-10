<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config.php';

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'teacher';
$pdo = null;
$dbError = '';
$students = [];
$records = [];
$stats = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
$today = date('Y-m-d');
$selectedDate = $_GET['date'] ?? $today;
$selectedStatus = strtolower(trim($_GET['status'] ?? 'all'));
$search = trim($_GET['search'] ?? '');
$allowedStatuses = ['present', 'absent', 'late', 'excused'];

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) || !strtotime($selectedDate)) {
    $selectedDate = $today;
}
if (!in_array($selectedStatus, array_merge(['all'], $allowedStatuses), true)) {
    $selectedStatus = 'all';
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage(string $message, string $type = 'success'): void {
    $params = http_build_query([
        'date' => $_POST['return_date'] ?? date('Y-m-d'),
        'message' => $message,
        'type' => $type
    ]);
    header('Location: attendance.php?' . $params);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            redirectWithMessage('Invalid request. Please try again.', 'error');
        }

        $action = $_POST['action'] ?? '';
        $returnDate = $_POST['return_date'] ?? $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate) || !strtotime($returnDate)) {
            $returnDate = $today;
        }

        if ($action === 'save') {
            $id = filter_input(INPUT_POST, 'attendance_id', FILTER_VALIDATE_INT) ?: 0;
            $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
            $attendanceDate = trim($_POST['attendance_date'] ?? '');
            $status = strtolower(trim($_POST['status'] ?? ''));
            $timeIn = trim($_POST['time_in'] ?? '');
            $timeOut = trim($_POST['time_out'] ?? '');
            $remarks = trim($_POST['remarks'] ?? '');

            if (!$studentId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate) || !strtotime($attendanceDate) || !in_array($status, $allowedStatuses, true)) {
                redirectWithMessage('Please complete the required fields with valid information.', 'error');
            }
            if ($timeIn !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeIn)) {
                redirectWithMessage('Please enter a valid time-in.', 'error');
            }
            if ($timeOut !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeOut)) {
                redirectWithMessage('Please enter a valid time-out.', 'error');
            }

            $checkStudent = $pdo->prepare('SELECT id FROM students WHERE id = ?');
            $checkStudent->execute([$studentId]);
            if (!$checkStudent->fetchColumn()) {
                redirectWithMessage('Selected student was not found.', 'error');
            }

            $timeIn = $timeIn !== '' ? $timeIn . ':00' : null;
            $timeOut = $timeOut !== '' ? $timeOut . ':00' : null;

            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE attendance SET student_id = ?, attendance_date = ?, status = ?, time_in = ?, time_out = ?, remarks = ? WHERE id = ?');
                $stmt->execute([$studentId, $attendanceDate, $status, $timeIn, $timeOut, $remarks ?: null, $id]);
                redirectWithMessage('Attendance record updated successfully.');
            }

            $duplicate = $pdo->prepare('SELECT id FROM attendance WHERE student_id = ? AND attendance_date = ?');
            $duplicate->execute([$studentId, $attendanceDate]);
            if ($duplicate->fetchColumn()) {
                redirectWithMessage('This student already has an attendance record for that date. Edit the existing record instead.', 'error');
            }

            $stmt = $pdo->prepare('INSERT INTO attendance (student_id, attendance_date, status, time_in, time_out, remarks, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$studentId, $attendanceDate, $status, $timeIn, $timeOut, $remarks ?: null]);
            redirectWithMessage('Attendance recorded successfully.');
        }

        if ($action === 'delete') {
            $id = filter_input(INPUT_POST, 'attendance_id', FILTER_VALIDATE_INT);
            if (!$id) {
                redirectWithMessage('Invalid attendance record.', 'error');
            }
            $stmt = $pdo->prepare('DELETE FROM attendance WHERE id = ?');
            $stmt->execute([$id]);
            redirectWithMessage('Attendance record deleted successfully.');
        }

        redirectWithMessage('Unknown action.', 'error');
    }

    $students = $pdo->query('SELECT id, student_number, full_name FROM students ORDER BY full_name ASC')->fetchAll(PDO::FETCH_ASSOC);

    $statsStmt = $pdo->prepare("SELECT LOWER(status) AS status, COUNT(*) AS total FROM attendance WHERE attendance_date = ? GROUP BY LOWER(status)");
    $statsStmt->execute([$selectedDate]);
    foreach ($statsStmt->fetchAll(PDO::FETCH_ASSOC) as $stat) {
        if (array_key_exists($stat['status'], $stats)) {
            $stats[$stat['status']] = (int)$stat['total'];
        }
    }

    $sql = 'SELECT a.id, a.student_id, a.attendance_date, a.status, a.time_in, a.time_out, a.remarks, s.student_number, s.full_name FROM attendance a INNER JOIN students s ON s.id = a.student_id WHERE a.attendance_date = :attendance_date';
    $params = [':attendance_date' => $selectedDate];
    if ($selectedStatus !== 'all') {
        $sql .= ' AND LOWER(a.status) = :status';
        $params[':status'] = $selectedStatus;
    }
 
if ($search !== '') {
    $sql .= ' AND (s.full_name LIKE :search_name OR s.student_number LIKE :search_number)';

    $searchTerm = '%' . $search . '%';

    $params[':search_name'] = $searchTerm;
    $params[':search_number'] = $searchTerm;
}

    $sql .= ' ORDER BY s.full_name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $dbError = 'Unable to load attendance. Check your database connection and table columns.';
}

$totalRecorded = array_sum($stats);
$attendanceRate = $totalRecorded > 0 ? round((($stats['present'] + $stats['late']) / $totalRecorded) * 100) : 0;
$message = $_GET['message'] ?? '';
$messageType = ($_GET['type'] ?? 'success') === 'error' ? 'error' : 'success';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance | Attendance Monitoring System</title>
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
.sidebar-bottom{margin-top:auto;padding-top:20px}.user-mini{padding:15px 10px;border-top:1px solid #ffffff24;margin-bottom:10px;overflow-wrap:anywhere}.user-mini strong{display:block;font-size:13px}.user-mini span{display:block;font-size:12px;color:#b9d9c9;margin-top:4px;text-transform:capitalize}
.logout{display:block;color:#ffd5d5;text-decoration:none;padding:12px 14px;border-radius:9px;font-size:14px}.logout:hover{background:#ffffff19}
.main{flex:1;min-width:0;padding:30px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:26px}.topbar h1{font-size:28px;color:#173e2d}.topbar p{margin-top:7px;color:var(--muted);font-size:14px}
.profile{display:flex;align-items:center;gap:12px;background:#fff;padding:9px 14px;border:1px solid var(--border);border-radius:13px}.avatar{width:40px;height:40px;border-radius:50%;background:var(--light);color:var(--green);display:grid;place-items:center;font-weight:800}.profile strong{display:block;font-size:13px}.profile small{display:block;margin-top:4px;color:var(--muted);text-transform:capitalize}
.banner{background:linear-gradient(110deg,#087f5b,#12a16c);color:#fff;padding:22px 24px;border-radius:16px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;gap:15px}.banner h2{font-size:20px;margin-bottom:6px}.banner p{font-size:13px;color:#d8f3e5}.date-pill{background:#ffffff20;padding:11px 15px;border-radius:10px;font-size:13px;white-space:nowrap}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px}.stat{background:#fff;border:1px solid var(--border);border-radius:14px;padding:19px;min-width:0}.stat-label{font-size:12px;color:var(--muted)}.stat-value{font-size:29px;font-weight:800;margin-top:11px;color:#173e2d}.stat-note{font-size:11px;color:var(--muted);margin-top:5px}.stat-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}.green{background:#087f5b}.red{background:#e45b5b}.orange{background:#e9a23b}.blue{background:#4789d8}
.panel{background:#fff;border:1px solid var(--border);border-radius:15px;padding:22px;margin-bottom:22px}.panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}.panel-head h2{font-size:17px;color:#173e2d}.panel-head p{font-size:12px;color:var(--muted);margin-top:5px}
.btn{border:0;border-radius:9px;padding:11px 15px;background:var(--green);color:#fff;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:7px}.btn:hover{background:var(--dark)}.btn-light{background:#edf6f0;color:var(--green)}.btn-light:hover{background:#dcefe3}.btn-danger{background:#fff0ef;color:#c0392b}.btn-danger:hover{background:#ffe0dd}.btn-small{padding:7px 10px;font-size:11px}
.filters{display:grid;grid-template-columns:180px minmax(160px,1fr) 170px auto;gap:10px;margin-bottom:17px}.field{width:100%;padding:11px 12px;border:1px solid #dfe8e2;border-radius:9px;background:#fff;color:var(--text);font-size:13px;outline:none}.field:focus{border-color:var(--green);box-shadow:0 0 0 3px #087f5b16}.table-wrap{width:100%;overflow-x:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);padding:13px 10px;border-bottom:1px solid var(--border)}td{padding:14px 10px;font-size:12px;border-bottom:1px solid #eef2ef}tbody tr:last-child td{border-bottom:0}.student-name{font-weight:700;color:#294537}.student-number{font-size:11px;color:var(--muted);margin-top:4px}.status{display:inline-block;padding:6px 9px;border-radius:20px;font-size:10px;font-weight:700;text-transform:capitalize}.status-present{background:#e5f7ed;color:#08734f}.status-absent{background:#fff0ef;color:#c0392b}.status-late{background:#fff2df;color:#a76508}.status-excused{background:#eaf1ff;color:#315fa8}.actions{display:flex;gap:6px}.empty{text-align:center;padding:35px 10px;color:var(--muted)}.notice{padding:13px 15px;border-radius:10px;margin-bottom:18px;font-size:13px}.notice.success{background:#e8f7ee;color:#176b45;border:1px solid #c7ead4}.notice.error{background:#fff0ef;color:#a93226;border:1px solid #f4cbc7}.error-box{padding:15px;background:#fff4e5;border:1px solid #f5d49c;color:#80520d;border-radius:10px;margin-bottom:18px;font-size:13px}
.modal{display:none;position:fixed;inset:0;background:#10271d99;z-index:10;align-items:center;justify-content:center;padding:18px}.modal.show{display:flex}.modal-box{background:#fff;border-radius:16px;padding:24px;width:min(100%,520px);max-height:90vh;overflow:auto;box-shadow:0 20px 60px #0002}.modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.modal-head h2{font-size:19px}.close{border:0;background:#f0f4f1;width:34px;height:34px;border-radius:9px;font-size:20px;cursor:pointer}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.form-group{display:flex;flex-direction:column;gap:7px}.form-group.full{grid-column:1/-1}.form-group label{font-size:12px;font-weight:700;color:#52645a}.form-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:20px}.help{font-size:11px;color:var(--muted)}
@media(max-width:1100px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.filters{grid-template-columns:1fr 1fr}}
@media(max-width:760px){.layout{display:block}.sidebar{width:100%;padding:15px}.brand{padding-bottom:15px}.nav-title,.sidebar-bottom{display:none}.sidebar nav{display:flex;gap:6px;overflow-x:auto;padding-top:12px}.nav-link{flex-shrink:0;margin:0;padding:10px;font-size:12px}.nav-icon{display:none}.main{padding:20px 14px}.topbar{align-items:flex-start}.topbar h1{font-size:23px}.profile{padding:7px}.profile>div:last-child{display:none}.banner{padding:18px}.panel{padding:16px}}
@media(max-width:480px){.stats{gap:9px}.stat{padding:14px}.stat-value{font-size:24px}.filters{grid-template-columns:1fr}.date-pill{display:none}.form-grid{grid-template-columns:1fr}.form-group.full{grid-column:auto}.panel-head{align-items:flex-start;flex-direction:column}.topbar p{font-size:12px}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <div class="brand"><div class="brand-icon">A</div><div><h2>Attendance System</h2><p>Monitoring Portal</p></div></div>
    <p class="nav-title">Main Menu</p>
    <nav>
        <a href="dashboard.php" class="nav-link"><span class="nav-icon">▦</span>Dashboard</a>
        <a href="student.php" class="nav-link"><span class="nav-icon">♙</span>Students</a>
        <a href="attendance.php" class="nav-link active"><span class="nav-icon">▣</span>Attendance</a>
        <a href="reports.php" class="nav-link"><span class="nav-icon">▤</span>Reports</a>
    </nav>
    <div class="sidebar-bottom"><div class="user-mini"><strong><?= e($fullName) ?></strong><span><?= e($role) ?></span></div><a href="logout.php" class="logout">↪ &nbsp; Logout</a></div>
</aside>
<main class="main">
<header class="topbar"><div><h1>Attendance</h1><p>Record and manage daily student attendance.</p></div><div class="profile"><div class="avatar"><?= e(strtoupper(substr($fullName, 0, 1))) ?></div><div><strong><?= e($fullName) ?></strong><small><?= e($role) ?></small></div></div></header>
<?php if ($dbError): ?><div class="error-box"><strong>Database error:</strong> <?= e($dbError) ?><br>Confirm that your attendance table contains the required columns.</div><?php endif; ?>
<?php if ($message): ?><div class="notice <?= e($messageType) ?>"><?= e($message) ?></div><?php endif; ?>
<section class="banner"><div><h2>Daily Attendance Overview</h2><p>Track student presence, absences, late arrivals, and excused records.</p></div><div class="date-pill"><?= e(date('F d, Y', strtotime($selectedDate))) ?></div></section>
<section class="stats">
    <div class="stat"><div class="stat-label"><span class="stat-dot green"></span>Present</div><div class="stat-value"><?= (int)$stats['present'] ?></div><div class="stat-note">Present on selected date</div></div>
    <div class="stat"><div class="stat-label"><span class="stat-dot red"></span>Absent</div><div class="stat-value"><?= (int)$stats['absent'] ?></div><div class="stat-note">Absent on selected date</div></div>
    <div class="stat"><div class="stat-label"><span class="stat-dot orange"></span>Late</div><div class="stat-value"><?= (int)$stats['late'] ?></div><div class="stat-note">Late on selected date</div></div>
    <div class="stat"><div class="stat-label"><span class="stat-dot blue"></span>Attendance rate</div><div class="stat-value"><?= $attendanceRate ?>%</div><div class="stat-note">Present and late / recorded</div></div>
</section>
<section class="panel">
    <div class="panel-head"><div><h2>Attendance Records</h2><p>View, search, add, update, and delete attendance entries.</p></div><button class="btn" type="button" onclick="openAddModal()">＋ Record Attendance</button></div>
    <form class="filters" method="GET" action="attendance.php">
        <input class="field" type="date" name="date" value="<?= e($selectedDate) ?>">
        <input class="field" type="search" name="search" value="<?= e($search) ?>" placeholder="Search student name or ID">
        <select class="field" name="status"><option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>All statuses</option><?php foreach ($allowedStatuses as $s): ?><option value="<?= e($s) ?>" <?= $selectedStatus === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select>
        <button class="btn" type="submit">Apply Filters</button>
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>Student</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Remarks</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$records): ?><tr><td colspan="7" class="empty">No attendance records found for this date.</td></tr><?php else: foreach ($records as $record): $status = strtolower($record['status']); $safeStatus = in_array($status, $allowedStatuses, true) ? $status : 'absent'; ?>
        <tr>
            <td><div class="student-name"><?= e($record['full_name']) ?></div><div class="student-number"><?= e($record['student_number']) ?></div></td>
            <td><?= e(date('M d, Y', strtotime($record['attendance_date']))) ?></td>
            <td><?= $record['time_in'] ? e(date('h:i A', strtotime($record['time_in']))) : '—' ?></td>
            <td><?= $record['time_out'] ? e(date('h:i A', strtotime($record['time_out']))) : '—' ?></td>
            <td><span class="status status-<?= e($safeStatus) ?>"><?= e(ucfirst($safeStatus)) ?></span></td>
            <td><?= e($record['remarks'] ?: '—') ?></td>
            <td><div class="actions"><button class="btn btn-light btn-small" type="button" onclick='openEditModal(<?= json_encode($record, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>Edit</button><form method="POST" onsubmit="return confirm('Delete this attendance record?')"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="attendance_id" value="<?= (int)$record['id'] ?>"><input type="hidden" name="return_date" value="<?= e($selectedDate) ?>"><button class="btn btn-danger btn-small" type="submit">Delete</button></form></div></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</section>
</main>
</div>

<div class="modal" id="attendanceModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
<div class="modal-box">
    <div class="modal-head"><h2 id="modalTitle">Record Attendance</h2><button class="close" type="button" onclick="closeModal()" aria-label="Close">×</button></div>
    <form method="POST" id="attendanceForm">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="attendance_id" id="attendance_id" value="">
        <input type="hidden" name="return_date" value="<?= e($selectedDate) ?>">
        <div class="form-grid">
            <div class="form-group full"><label for="student_id">Student *</label><select class="field" name="student_id" id="student_id" required><option value="">Select a student</option><?php foreach ($students as $student): ?><option value="<?= (int)$student['id'] ?>"><?= e($student['full_name']) ?> — <?= e($student['student_number']) ?></option><?php endforeach; ?></select><?php if (!$students): ?><span class="help">No students found. Add students first on the Students page.</span><?php endif; ?></div>
            <div class="form-group"><label for="attendance_date">Attendance Date *</label><input class="field" type="date" name="attendance_date" id="attendance_date" value="<?= e($selectedDate) ?>" required></div>
            <div class="form-group"><label for="status">Status *</label><select class="field" name="status" id="status" required><option value="present">Present</option><option value="absent">Absent</option><option value="late">Late</option><option value="excused">Excused</option></select></div>
            <div class="form-group"><label for="time_in">Time In</label><input class="field" type="time" name="time_in" id="time_in"></div>
            <div class="form-group"><label for="time_out">Time Out</label><input class="field" type="time" name="time_out" id="time_out"></div>
            <div class="form-group full"><label for="remarks">Remarks</label><input class="field" type="text" name="remarks" id="remarks" maxlength="255" placeholder="Optional notes"></div>
        </div>
        <div class="form-actions"><button class="btn btn-light" type="button" onclick="closeModal()">Cancel</button><button class="btn" type="submit" <?= !$students ? 'disabled' : '' ?>>Save Attendance</button></div>
    </form>
</div>
</div>
<script>
const modal = document.getElementById('attendanceModal');
function openAddModal(){
    document.getElementById('attendanceForm').reset();
    document.getElementById('attendance_id').value = '';
    document.getElementById('attendance_date').value = <?= json_encode($selectedDate) ?>;
    document.getElementById('modalTitle').textContent = 'Record Attendance';
    modal.classList.add('show');
}
function openEditModal(record){
    document.getElementById('attendanceForm').reset();
    document.getElementById('attendance_id').value = record.id;
    document.getElementById('student_id').value = record.student_id;
    document.getElementById('attendance_date').value = record.attendance_date;
    document.getElementById('status').value = String(record.status).toLowerCase();
    document.getElementById('time_in').value = record.time_in ? String(record.time_in).substring(0,5) : '';
    document.getElementById('time_out').value = record.time_out ? String(record.time_out).substring(0,5) : '';
    document.getElementById('remarks').value = record.remarks || '';
    document.getElementById('modalTitle').textContent = 'Edit Attendance';
    modal.classList.add('show');
}
function closeModal(){modal.classList.remove('show');}
modal.addEventListener('click', event => {if(event.target === modal) closeModal();});
document.addEventListener('keydown', event => {if(event.key === 'Escape') closeModal();});
</script>
</body>
</html>
