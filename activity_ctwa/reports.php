
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config.php';

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'teacher';
$periods = ['1st Grading', '2nd Grading', '3rd Grading', '4th Grading'];

$students = [];
$grades = [];
$attendanceStats = [];
$selectedStudentInfo = null;
$error = '';
$message = $_SESSION['report_message'] ?? '';
unset($_SESSION['report_message']);

$selectedStudent = (int)($_GET['student_id'] ?? 0);
$selectedPeriod = trim($_GET['period'] ?? 'All Periods');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectReport(int $studentId, string $period): void {
   $url = 'reports.php?student_id=' . $studentId .
    '&period=' . urlencode($period);

    header('Location: ' . $url);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_grades (
            id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT(10) UNSIGNED NOT NULL,
            subject VARCHAR(120) NOT NULL,
            grading_period VARCHAR(30) NOT NULL,
            grade DECIMAL(5,2) NOT NULL,
            remarks VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_student_period (student_id, grading_period),
            CONSTRAINT fk_student_grades_student
                FOREIGN KEY (student_id)
                REFERENCES students(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $students = $pdo->query("
        SELECT id, student_number, full_name
        FROM students
        ORDER BY full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$selectedStudent && !empty($students)) {
        $selectedStudent = (int)$students[0]['id'];
    }

    if (!in_array($selectedPeriod, array_merge(['All Periods'], $periods), true)) {
        $selectedPeriod = 'All Periods';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            throw new RuntimeException('Invalid request. Please refresh the page and try again.');
        }

        $action = $_POST['action'] ?? '';
        $studentId = (int)($_POST['student_id'] ?? 0);
        $period = trim($_POST['period'] ?? $_POST['grading_period'] ?? 'All Periods');

        if ($action === 'save') {
            $subject = trim($_POST['subject'] ?? '');
            $gradingPeriod = trim($_POST['grading_period'] ?? '');
            $gradeInput = $_POST['grade'] ?? '';

            if (
                $studentId < 1 ||
                $subject === '' ||
                !in_array($gradingPeriod, $periods, true) ||
                $gradeInput === '' ||
                !is_numeric($gradeInput)
            ) {
                throw new RuntimeException('Please complete all required fields correctly.');
            }

            $grade = (float)$gradeInput;

            if ($grade < 0 || $grade > 100) {
                throw new RuntimeException('Grade must be between 0 and 100.');
            }

            $checkStudent = $pdo->prepare("
                SELECT id FROM students WHERE id = ?
            ");
            $checkStudent->execute([$studentId]);

            if (!$checkStudent->fetchColumn()) {
                throw new RuntimeException('Selected student does not exist.');
            }

            $remarks = trim($_POST['remarks'] ?? '');

            $stmt = $pdo->prepare("
                INSERT INTO student_grades
                    (student_id, subject, grading_period, grade, remarks)
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $studentId,
                $subject,
                $gradingPeriod,
                $grade,
                $remarks !== '' ? $remarks : null
            ]);

            $_SESSION['report_message'] = 'Grade successfully added to the student report card.';

            redirectReport($studentId, $gradingPeriod);
        }

        if ($action === 'delete') {
            $gradeId = (int)($_POST['grade_id'] ?? 0);

            if ($studentId < 1 || $gradeId < 1) {
                throw new RuntimeException('Invalid grade record.');
            }

            $stmt = $pdo->prepare("
                DELETE FROM student_grades
                WHERE id = ? AND student_id = ?
            ");

            $stmt->execute([$gradeId, $studentId]);

            $_SESSION['report_message'] = 'Grade record successfully deleted.';

            redirectReport($studentId, $period);
        }
    }

    if ($selectedStudent > 0) {
        $stmt = $pdo->prepare("
            SELECT id, student_number, full_name
            FROM students
            WHERE id = ?
        ");

        $stmt->execute([$selectedStudent]);
        $selectedStudentInfo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($selectedStudentInfo) {
        $sql = "
            SELECT id, subject, grading_period, grade, remarks, created_at
            FROM student_grades
            WHERE student_id = ?
        ";

        $params = [$selectedStudent];

        if ($selectedPeriod !== 'All Periods') {
            $sql .= " AND grading_period = ?";
            $params[] = $selectedPeriod;
        }

        $sql .= "
            ORDER BY FIELD(
                grading_period,
                '1st Grading',
                '2nd Grading',
                '3rd Grading',
                '4th Grading'
            ), subject ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $attendanceStmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                COALESCE(SUM(LOWER(status) = 'present'), 0) AS present,
                COALESCE(SUM(LOWER(status) = 'late'), 0) AS late,
                COALESCE(SUM(LOWER(status) = 'absent'), 0) AS absent,
                COALESCE(SUM(LOWER(status) = 'excused'), 0) AS excused
            FROM attendance
            WHERE student_id = ?
        ");

        $attendanceStmt->execute([$selectedStudent]);
        $attendanceStats = $attendanceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

} catch (Throwable $ex) {
    error_log($ex->getMessage());

    $error = $ex instanceof RuntimeException
        ? $ex->getMessage()
        : 'Unable to process student reports. Database error: ' . $ex->getMessage();
}

$gradeCount = count($grades);

$average = $gradeCount
    ? array_sum(array_map(fn($item) => (float)$item['grade'], $grades)) / $gradeCount
    : null;

$attendanceTotal = (int)($attendanceStats['total'] ?? 0);

$attendanceRate = $attendanceTotal > 0
    ? round((
        ((int)($attendanceStats['present'] ?? 0) +
        (int)($attendanceStats['late'] ?? 0)) / $attendanceTotal
    ) * 100, 1)
    : 0;

$performance = $average === null
    ? 'No grades yet'
    : ($average >= 90 ? 'Outstanding'
    : ($average >= 85 ? 'Very Satisfactory'
    : ($average >= 80 ? 'Satisfactory'
    : ($average >= 75 ? 'Fairly Satisfactory' : 'Needs Improvement'))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Reports | Attendance Monitoring System</title>

<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:"Segoe UI",Arial,sans-serif}
:root{--green:#087f5b;--dark:#03422f;--light:#e9f7ef;--bg:#f4f7f5;--text:#20392d;--muted:#829087;--border:#e5ece7}
body{min-height:100vh;background:var(--bg);color:var(--text)}
.layout{min-height:100vh;display:flex}
.sidebar{width:255px;flex-shrink:0;background:var(--dark);color:#fff;padding:26px 18px;display:flex;flex-direction:column}
.brand{display:flex;align-items:center;gap:12px;padding:0 8px 30px;border-bottom:1px solid #ffffff24}
.brand-icon{width:43px;height:43px;border-radius:13px;background:#e0ffed;color:var(--green);display:grid;place-items:center;font-size:22px;font-weight:800}
.brand h2{font-size:16px;line-height:1.4}
.brand p{font-size:11px;color:#b9d9c9;margin-top:3px}
.nav-title{margin:28px 10px 12px;font-size:10px;color:#a7c7b7;text-transform:uppercase;letter-spacing:1.5px}
.nav-link{display:flex;align-items:center;gap:13px;padding:13px 14px;margin-bottom:7px;color:#d8eee2;text-decoration:none;border-radius:10px;font-size:14px}
.nav-link:hover,.nav-link.active{background:#ffffff19;color:#fff}
.nav-link.active{border-left:3px solid #7de0ac}
.nav-icon{width:23px;text-align:center;font-size:17px}
.sidebar-bottom{margin-top:auto;padding-top:20px}
.user-mini{padding:15px 10px;border-top:1px solid #ffffff24;margin-bottom:10px;overflow-wrap:anywhere}
.user-mini strong{display:block;font-size:13px}
.user-mini span{display:block;font-size:12px;color:#b9d9c9;margin-top:4px;text-transform:capitalize}
.logout{display:block;color:#ffd5d5;text-decoration:none;padding:12px 14px;border-radius:9px;font-size:14px}
.main{flex:1;min-width:0;padding:30px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:25px}
.topbar h1{font-size:28px;color:#173e2d}
.topbar p{margin-top:7px;color:var(--muted);font-size:14px}
.profile{display:flex;align-items:center;gap:12px;background:#fff;padding:9px 14px;border:1px solid var(--border);border-radius:13px}
.avatar{width:40px;height:40px;border-radius:50%;background:var(--light);color:var(--green);display:grid;place-items:center;font-weight:800}
.profile strong{display:block;font-size:13px}
.profile small{display:block;margin-top:4px;color:var(--muted);text-transform:capitalize}
.banner{background:linear-gradient(110deg,#087f5b,#12a16c);color:#fff;padding:23px 25px;border-radius:16px;margin-bottom:20px}
.banner h2{font-size:20px;margin-bottom:6px}
.banner p{font-size:13px;color:#d8f3e5}
.alert{padding:12px 14px;border-radius:10px;margin-bottom:16px;font-size:13px}
.success{background:#e8f7ed;color:#08734f;border:1px solid #c9ead5}
.error{background:#fff0ef;color:#a93226;border:1px solid #f4ceca}
.card{background:#fff;border:1px solid var(--border);border-radius:15px;padding:22px;margin-bottom:20px}
.card-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:17px}
.card-head h2{font-size:17px;color:#173e2d}
.card-head p{font-size:12px;color:var(--muted);margin-top:5px}
.filters{display:grid;grid-template-columns:1.5fr 1fr auto;gap:12px;align-items:end}
.field label{display:block;font-size:12px;font-weight:700;color:#52645a;margin-bottom:7px}
.field input,.field select{width:100%;height:43px;border:1px solid #dce6df;border-radius:9px;padding:0 12px;outline:none;background:#fff;font-size:13px;color:var(--text)}
.field input:focus,.field select:focus{border-color:var(--green)}
.btn{border:0;border-radius:9px;padding:12px 17px;background:var(--green);color:#fff;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;justify-content:center;align-items:center;gap:6px}
.btn:hover{background:#066b4d}
.btn-light{background:#edf3ef;color:#42564a}
.btn-light:hover{background:#e0eae3}
.btn-danger{background:#fff0ef;color:#bd3d32}
.btn-small{padding:7px 10px;font-size:11px}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px}
.stat{background:#fff;border:1px solid var(--border);border-radius:14px;padding:17px;min-width:0}
.stat span{font-size:12px;color:var(--muted)}
.stat strong{display:block;font-size:25px;color:#173e2d;margin-top:8px}
.stat small{display:block;font-size:11px;color:var(--muted);margin-top:4px}
.report-header{display:flex;justify-content:space-between;align-items:center;gap:15px;padding-bottom:16px;border-bottom:1px solid var(--border);margin-bottom:15px}
.report-student strong{display:block;font-size:18px;color:#173e2d}
.report-student span{font-size:12px;color:var(--muted);display:block;margin-top:5px}
.badge{display:inline-block;padding:7px 10px;border-radius:20px;background:var(--light);color:var(--green);font-size:11px;font-weight:800}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:650px}
th{text-align:left;background:#f7faf8;color:#718076;font-size:11px;text-transform:uppercase;letter-spacing:.5px;padding:12px}
td{padding:13px 12px;border-bottom:1px solid #edf1ee;font-size:13px}
td strong{color:#234333}
.grade-pill{font-weight:800;color:var(--green)}
.empty{text-align:center;padding:35px 12px;color:var(--muted);font-size:13px}
.add-grid{display:grid;grid-template-columns:1.3fr 1fr .8fr 1.2fr auto;gap:10px;align-items:end}
.note{font-size:11px;color:var(--muted);margin-top:12px;line-height:1.5}
.report-actions{display:flex;gap:8px;flex-wrap:wrap}
@media(max-width:1100px){.stats{grid-template-columns:repeat(2,1fr)}.add-grid{grid-template-columns:repeat(2,1fr)}.add-grid button{grid-column:1/-1}}
@media(max-width:760px){.layout{display:block}.sidebar{width:100%;padding:15px}.brand{padding-bottom:15px}.nav-title,.sidebar-bottom{display:none}.sidebar nav{display:flex;gap:6px;overflow-x:auto;padding-top:12px}.nav-link{flex-shrink:0;margin:0;padding:10px;font-size:12px}.nav-icon{display:none}.main{padding:20px 15px}.topbar{align-items:flex-start}.topbar h1{font-size:23px}.profile{padding:7px}.profile div:last-child{display:none}.card{padding:17px}.filters{grid-template-columns:1fr 1fr}.filters button{grid-column:1/-1}.report-header{align-items:flex-start;flex-direction:column}}
@media(max-width:480px){.stats{grid-template-columns:1fr 1fr;gap:9px}.stat{padding:13px}.stat strong{font-size:21px}.filters,.add-grid{grid-template-columns:1fr}.add-grid button{grid-column:auto}.main{padding:16px 12px}}
@media print{body{background:#fff}.sidebar,.topbar,.banner,.filters-card,.add-card,.report-actions,.alert{display:none!important}.main{padding:0}.card,.stat{box-shadow:none;border:1px solid #ddd;break-inside:avoid}.stats{grid-template-columns:repeat(4,1fr)}.report-header{margin-top:10px}}
</style>
</head>

<body>
<div class="layout">

<aside class="sidebar">
    <div class="brand">
        <div class="brand-icon">A</div>
        <div>
            <h2>Attendance System</h2>
            <p>Monitoring Portal</p>
        </div>
    </div>

    <p class="nav-title">Main Menu</p>

    <nav>
        <a href="dashboard.php" class="nav-link">▦ Dashboard</a>
        <a href="student.php" class="nav-link">♙ Students</a>
        <a href="attendance.php" class="nav-link">▣ Attendance</a>
        <a href="reports.php" class="nav-link active">▤ Reports</a>
    </nav>

    <div class="sidebar-bottom">
        <div class="user-mini">
            <strong><?= e($fullName) ?></strong>
            <span><?= e($role) ?></span>
        </div>
        <a href="logout.php" class="logout">↪ &nbsp; Logout</a>
    </div>
</aside>

<main class="main">

<header class="topbar">
    <div>
        <h1>Student Reports</h1>
        <p>Report cards, academic grades, and student progress reports.</p>
    </div>

    <div class="profile">
        <div class="avatar"><?= e(strtoupper(mb_substr($fullName, 0, 1))) ?></div>
        <div>
            <strong><?= e($fullName) ?></strong>
            <small><?= e($role) ?></small>
        </div>
    </div>
</header>

<section class="banner">
    <h2>Report Cards &amp; Progress Reports</h2>
    <p>Review subject grades, grading-period performance, and attendance progress for each student.</p>
</section>

<?php if ($message): ?>
    <div class="alert success"><?= e($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php endif; ?>

<section class="card filters-card">
    <div class="card-head">
        <div>
            <h2>Select Student Report</h2>
            <p>Choose a student and grading period to view their report card.</p>
        </div>
    </div>

    <form method="GET" action="reports.php" class="filters">
        <div class="field">
            <label for="student_id">Student</label>
            <select name="student_id" id="student_id" required>
                <?php foreach ($students as $student): ?>
                    <option value="<?= (int)$student['id'] ?>" <?= $selectedStudent === (int)$student['id'] ? 'selected' : '' ?>>
                        <?= e($student['full_name']) ?> — <?= e($student['student_number']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="period">Grading Period</label>
            <select name="period" id="period">
                <option value="All Periods" <?= $selectedPeriod === 'All Periods' ? 'selected' : '' ?>>All Periods</option>
                <?php foreach ($periods as $period): ?>
                    <option value="<?= e($period) ?>" <?= $selectedPeriod === $period ? 'selected' : '' ?>>
                        <?= e($period) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="btn" type="submit">View Report</button>
    </form>
</section>

<?php if ($selectedStudentInfo): ?>

<section class="stats">
    <article class="stat">
        <span>Recorded Subjects</span>
        <strong><?= $gradeCount ?></strong>
        <small>Grades in selected view</small>
    </article>

    <article class="stat">
        <span>General Average</span>
        <strong><?= $average === null ? '—' : number_format($average, 2) ?></strong>
        <small><?= e($performance) ?></small>
    </article>

    <article class="stat">
        <span>Attendance Rate</span>
        <strong><?= number_format($attendanceRate, 1) ?>%</strong>
        <small><?= $attendanceTotal ?> attendance record(s)</small>
    </article>

    <article class="stat">
        <span>Absent Days</span>
        <strong><?= (int)($attendanceStats['absent'] ?? 0) ?></strong>
        <small>
            <?= (int)($attendanceStats['late'] ?? 0) ?> late ·
            <?= (int)($attendanceStats['excused'] ?? 0) ?> excused
        </small>
    </article>
</section>

<section class="card" id="report-card">
    <div class="report-header">
        <div class="report-student">
            <strong><?= e($selectedStudentInfo['full_name']) ?></strong>
            <span>
                Student No.: <?= e($selectedStudentInfo['student_number']) ?>
                · <?= e($selectedPeriod) ?>
            </span>
        </div>

        <div class="report-actions">
            <span class="badge"><?= e($performance) ?></span>
            <button class="btn btn-light" type="button" onclick="window.print()">Print Report Card</button>
        </div>
    </div>

    <div class="card-head">
        <div>
            <h2>Academic Performance</h2>
            <p>Subject grades and teacher remarks.</p>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>Grading Period</th>
                    <th>Grade</th>
                    <th>Remarks</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!$grades): ?>
                <tr>
                    <td colspan="5" class="empty">No grade records yet for this student and grading period.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($grades as $item): ?>
                    <tr>
                        <td><strong><?= e($item['subject']) ?></strong></td>
                        <td><?= e($item['grading_period']) ?></td>
                        <td class="grade-pill"><?= number_format((float)$item['grade'], 2) ?></td>
                        <td><?= e($item['remarks'] ?: '—') ?></td>
                        <td>
                            <form method="POST" action="reports.php?student_id=<?= (int)$selectedStudent ?>&period=<?= urlencode($selectedPeriod) ?>" onsubmit="return confirm('Delete this grade record?')">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="grade_id" value="<?= (int)$item['id'] ?>">
                                <input type="hidden" name="student_id" value="<?= (int)$selectedStudent ?>">
                                <input type="hidden" name="period" value="<?= e($selectedPeriod) ?>">
                                <button class="btn btn-danger btn-small" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p class="note">
        General average is calculated from the grade records currently shown.
        Attendance rate counts Present and Late records as attended.
    </p>
</section>

<section class="card add-card">
    <div class="card-head">
        <div>
            <h2>Update Report Card</h2>
            <p>Add a subject grade to this student's academic record.</p>
        </div>
    </div>

    <form method="POST" action="reports.php?student_id=<?= (int)$selectedStudent ?>&period=<?= urlencode($selectedPeriod) ?>" class="add-grid">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="student_id" value="<?= (int)$selectedStudent ?>">

        <div class="field">
            <label for="subject">Subject</label>
            <input type="text" id="subject" name="subject" maxlength="120" placeholder="e.g. Mathematics" required>
        </div>

        <div class="field">
            <label for="grading_period">Grading Period</label>
            <select id="grading_period" name="grading_period" required>
                <?php foreach ($periods as $period): ?>
                    <option value="<?= e($period) ?>" <?= $selectedPeriod === $period ? 'selected' : '' ?>>
                        <?= e($period) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="grade">Grade (0–100)</label>
            <input type="number" id="grade" name="grade" min="0" max="100" step="0.01" placeholder="90" required>
        </div>

        <div class="field">
            <label for="remarks">Remarks</label>
            <input type="text" id="remarks" name="remarks" maxlength="255" placeholder="Excellent performance">
        </div>

        <button class="btn" type="submit">+ Add Grade</button>
    </form>

    <p class="note">The grade will be saved to the selected student's report card immediately after submission.</p>
</section>

<?php elseif (!$error && empty($students)): ?>

<section class="card empty">
    No student records found. Add students first in the Students page to create report cards.
</section>

<?php endif; ?>

</main>
</div>
</body>
</html>
