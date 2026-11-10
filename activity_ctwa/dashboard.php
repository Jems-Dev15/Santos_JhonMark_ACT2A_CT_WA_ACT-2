<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit;
}

require __DIR__ . "/config.php";

$fullName = $_SESSION["full_name"] ?? "User";
$role = $_SESSION["role"] ?? "teacher";

$totalStudents = 0;
$presentToday = 0;
$absentToday = 0;
$lateToday = 0;
$recentAttendance = [];
$dbError = false;

try {
    $pdo = getPDO();

    $totalStudents = (int) $pdo->query(
        "SELECT COUNT(*) FROM students"
    )->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(status = 'Present'), 0) AS present_count,
            COALESCE(SUM(status = 'Absent'), 0) AS absent_count,
            COALESCE(SUM(status = 'Late'), 0) AS late_count
         FROM attendance
         WHERE attendance_date = CURDATE()"
    );
    $stmt->execute();
    $stats = $stmt->fetch();

    $presentToday = (int) $stats["present_count"];
    $absentToday = (int) $stats["absent_count"];
    $lateToday = (int) $stats["late_count"];

    $stmt = $pdo->prepare(
        "SELECT
            s.student_number,
            s.full_name,
            a.attendance_date,
            a.status,
            a.time_in
         FROM attendance a
         INNER JOIN students s ON s.id = a.student_id
         ORDER BY a.created_at DESC
         LIMIT 8"
    );
    $stmt->execute();
    $recentAttendance = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $dbError = true;
}

$totalRecorded = $presentToday + $absentToday + $lateToday;

$attendanceRate = $totalRecorded > 0
    ? round(($presentToday / $totalRecorded) * 100)
    : 0;

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Dashboard | Attendance Monitoring System</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: "Segoe UI", Arial, sans-serif;
}

:root {
    --green: #087f5b;
    --green-dark: #03422f;
    --green-light: #e9f7ef;
    --background: #f4f7f5;
    --text: #20392d;
    --muted: #829087;
    --border: #e5ece7;
}

body {
    min-height: 100vh;
    background: var(--background);
    color: var(--text);
}

.layout {
    min-height: 100vh;
    display: flex;
}

/* SIDEBAR */

.sidebar {
    width: 255px;
    flex-shrink: 0;
    background: var(--green-dark);
    color: white;
    padding: 26px 18px;
    display: flex;
    flex-direction: column;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 8px 30px;
    border-bottom: 1px solid #ffffff24;
}

.brand-icon {
    width: 43px;
    height: 43px;
    border-radius: 13px;
    background: #e0ffed;
    color: var(--green);
    display: grid;
    place-items: center;
    font-size: 22px;
    font-weight: 800;
}

.brand h2 {
    font-size: 16px;
    line-height: 1.4;
}

.brand p {
    font-size: 11px;
    color: #b9d9c9;
    margin-top: 3px;
}

.nav-title {
    margin: 28px 10px 12px;
    font-size: 10px;
    color: #a7c7b7;
    text-transform: uppercase;
    letter-spacing: 1.5px;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 13px 14px;
    margin-bottom: 7px;
    color: #d8eee2;
    text-decoration: none;
    border-radius: 10px;
    font-size: 14px;
    transition: .2s;
}

.nav-link:hover,
.nav-link.active {
    background: #ffffff19;
    color: white;
}

.nav-link.active {
    border-left: 3px solid #7de0ac;
}

.nav-icon {
    width: 23px;
    text-align: center;
    font-size: 17px;
}

.sidebar-bottom {
    margin-top: auto;
    padding-top: 20px;
}

.user-mini {
    padding: 15px 10px;
    border-top: 1px solid #ffffff24;
    margin-bottom: 10px;
    overflow-wrap: anywhere;
}

.user-mini strong {
    display: block;
    font-size: 13px;
}

.user-mini span {
    display: block;
    font-size: 12px;
    color: #b9d9c9;
    margin-top: 4px;
    text-transform: capitalize;
}

.logout {
    display: block;
    color: #ffd5d5;
    text-decoration: none;
    padding: 12px 14px;
    border-radius: 9px;
    font-size: 14px;
}

.logout:hover {
    background: #ffffff19;
}

/* MAIN CONTENT */

.main {
    flex: 1;
    min-width: 0;
    padding: 30px;
}

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 32px;
}

.topbar h1 {
    font-size: 28px;
    color: #173e2d;
}

.topbar p {
    margin-top: 7px;
    color: var(--muted);
    font-size: 14px;
}

.profile {
    display: flex;
    align-items: center;
    gap: 12px;
    background: white;
    padding: 10px 15px;
    border: 1px solid var(--border);
    border-radius: 13px;
}

.avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--green-light);
    color: var(--green);
    display: grid;
    place-items: center;
    font-weight: 800;
}

.profile strong {
    display: block;
    font-size: 13px;
}

.profile small {
    display: block;
    margin-top: 4px;
    color: var(--muted);
    text-transform: capitalize;
}

/* DATE BANNER */

.date-banner {
    background: linear-gradient(110deg, #087f5b, #12a16c);
    color: white;
    padding: 23px 25px;
    border-radius: 16px;
    margin-bottom: 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
}

.date-banner h2 {
    font-size: 20px;
    margin-bottom: 6px;
}

.date-banner p {
    color: #d8f3e5;
    font-size: 13px;
}

.date-badge {
    background: #ffffff20;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    white-space: nowrap;
}

/* STATISTICS */

.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 27px;
}

.stat-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 15px;
    padding: 22px;
    min-width: 0;
    transition: transform .2s, box-shadow .2s;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 9px 25px #123b2510;
}

.stat-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 18px;
}

.stat-label {
    font-size: 12px;
    color: var(--muted);
}

.stat-icon {
    width: 39px;
    height: 39px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    font-size: 19px;
}

.icon-green { background: #e5f7ed; }
.icon-blue { background: #e7f1ff; }
.icon-red { background: #fff0ef; }
.icon-orange { background: #fff2df; }

.stat-value {
    font-size: 31px;
    font-weight: 800;
    color: #173e2d;
}

.stat-description {
    color: var(--muted);
    font-size: 11px;
    margin-top: 8px;
}

/* CONTENT CARDS */

.content-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 290px;
    gap: 22px;
    align-items: start;
}

.card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 15px;
    padding: 23px;
    min-width: 0;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 22px;
}

.card-header h2 {
    font-size: 17px;
    color: #173e2d;
}

.card-header p {
    font-size: 12px;
    color: var(--muted);
    margin-top: 5px;
}

.view-all {
    color: var(--green);
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    white-space: nowrap;
}

th {
    text-align: left;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: var(--muted);
    padding: 13px 10px;
    border-bottom: 1px solid var(--border);
}

td {
    padding: 15px 10px;
    font-size: 12px;
    border-bottom: 1px solid #eef2ef;
}

tbody tr:last-child td {
    border-bottom: none;
}

.status {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

.status-present {
    background: #e5f7ed;
    color: #08734f;
}

.status-absent {
    background: #fff0ef;
    color: #c0392b;
}

.status-late {
    background: #fff2df;
    color: #a76508;
}

.empty {
    padding: 30px 10px;
    text-align: center;
    color: var(--muted);
    font-size: 13px;
}

/* ATTENDANCE OVERVIEW */

.overview-number {
    font-size: 36px;
    font-weight: 800;
    color: var(--green);
    margin: 12px 0 5px;
}

.overview-caption {
    font-size: 12px;
    color: var(--muted);
}

.progress-track {
    height: 10px;
    width: 100%;
    background: #e8efe9;
    border-radius: 10px;
    overflow: hidden;
    margin: 18px 0 22px;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #087f5b, #45c78c);
    border-radius: 10px;
}

.legend {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 15px 0;
    font-size: 12px;
    color: #59695f;
}

.legend-label {
    display: flex;
    align-items: center;
    gap: 9px;
}

.dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
}

.dot-green { background: #087f5b; }
.dot-red { background: #e45b5b; }
.dot-orange { background: #e9a23b; }

.quick-actions {
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
}

.quick-actions h3 {
    font-size: 14px;
    margin-bottom: 13px;
}

.action-link {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 13px 0;
    color: #30473b;
    text-decoration: none;
    font-size: 13px;
    border-bottom: 1px solid #eef2ef;
}

.action-link:last-child {
    border-bottom: none;
}

.action-link:hover {
    color: var(--green);
}

.db-warning {
    background: #fff4e5;
    border: 1px solid #f5d49c;
    color: #80520d;
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.6;
}

/* RESPONSIVE */

@media (max-width: 1150px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .content-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}

@media (max-width: 760px) {
    .layout {
        display: block;
    }

    .sidebar {
        width: 100%;
        padding: 15px;
    }

    .brand {
        padding-bottom: 15px;
    }

    .nav-title,
    .sidebar-bottom {
        display: none;
    }

    .sidebar nav {
        display: flex;
        gap: 6px;
        overflow-x: auto;
        padding-top: 12px;
    }

    .nav-link {
        flex-shrink: 0;
        margin: 0;
        padding: 10px;
        font-size: 12px;
    }

    .nav-icon {
        display: none;
    }

    .main {
        padding: 20px 15px;
    }

    .topbar {
        align-items: flex-start;
    }

    .topbar h1 {
        font-size: 23px;
    }

    .profile {
        padding: 7px;
    }

    .profile div:last-child {
        display: none;
    }

    .date-banner {
        padding: 20px;
    }
}

@media (max-width: 420px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .stat-card {
        padding: 15px;
    }

    .stat-value {
        font-size: 26px;
    }

    .stat-top {
        align-items: flex-start;
        flex-direction: column-reverse;
    }

    .date-badge {
        display: none;
    }

    .card {
        padding: 16px;
    }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>
</head>

<body>

<div class="layout">

    <!-- SIDEBAR -->
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
            <a href="dashboard.php" class="nav-link active">
                <span class="nav-icon">▦</span>
                Dashboard
            </a>

         <a href="student.php" class="nav-link">
    <span class="nav-icon">♙</span>
    Students
</a>
            <a href="attendance.php" class="nav-link">
                <span class="nav-icon">▣</span>
                Attendance
            </a>

            <a href="reports.php" class="nav-link">
                <span class="nav-icon">▤</span>
                Reports
            </a>
        </nav>

        <div class="sidebar-bottom">
            <div class="user-mini">
                <strong><?= e($fullName) ?></strong>
                <span><?= e($role) ?></span>
            </div>

            <a href="logout.php" class="logout">↪ &nbsp; Logout</a>
        </div>

    </aside>

    <!-- MAIN DASHBOARD -->
    <main class="main">

        <header class="topbar">
            <div>
                <h1>Dashboard</h1>
                <p>Welcome back! Here's your attendance overview.</p>
            </div>

            <div class="profile">
                <div class="avatar">
                    <?= e(strtoupper(substr($fullName, 0, 1))) ?>
                </div>
                <div>
                    <strong><?= e($fullName) ?></strong>
                    <small><?= e($role) ?></small>
                </div>
            </div>
        </header>

        <?php if ($dbError): ?>
            <div class="db-warning">
                <strong>Database tables are unavailable.</strong><br>
                Check your MySQL connection and create the students
                and attendance tables using the SQL provided below.
            </div>
        <?php endif; ?>

        <section class="date-banner">
            <div>
                <h2>Today's Attendance</h2>
                <p>Monitor your students' attendance records.</p>
            </div>

            <div class="date-badge">
                <?= date("F d, Y") ?>
            </div>
        </section>

        <!-- STATISTICS -->
        <section class="stats">

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Total Students</span>
                    <span class="stat-icon icon-green">♙</span>
                </div>
                <div class="stat-value"><?= $totalStudents ?></div>
                <p class="stat-description">Registered students</p>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Present Today</span>
                    <span class="stat-icon icon-blue">✓</span>
                </div>
                <div class="stat-value"><?= $presentToday ?></div>
                <p class="stat-description">Marked present today</p>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Absent Today</span>
                    <span class="stat-icon icon-red">✕</span>
                </div>
                <div class="stat-value"><?= $absentToday ?></div>
                <p class="stat-description">Marked absent today</p>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Late Today</span>
                    <span class="stat-icon icon-orange">◷</span>
                </div>
                <div class="stat-value"><?= $lateToday ?></div>
                <p class="stat-description">Arrived late today</p>
            </div>

        </section>

        <section class="content-grid">

            <div class="card">

                <div class="card-header">
                    <div>
                        <h2>Recent Attendance</h2>
                        <p>Latest recorded attendance entries</p>
                    </div>

                    <a href="attendance.php" class="view-all">
                        View All →
                    </a>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Date</th>
                                <th>Time In</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if (empty($recentAttendance)): ?>
                            <tr>
                                <td colspan="4" class="empty">
                                    No attendance records yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentAttendance as $record): ?>
                                <?php
                                $status = strtolower($record["status"]);
                                $statusClass = in_array(
                                    $status,
                                    ["present", "absent", "late"],
                                    true
                                ) ? $status : "absent";
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= e($record["full_name"]) ?></strong><br>
                                        <span style="color:#829087">
                                            <?= e($record["student_number"]) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= e(date(
                                            "M d, Y",
                                            strtotime($record["attendance_date"])
                                        )) ?>
                                    </td>

                                    <td>
                                        <?= $record["time_in"]
                                            ? e(date("h:i A", strtotime($record["time_in"])))
                                            : "—" ?>
                                    </td>

                                    <td>
                                        <span class="status status-<?= e($statusClass) ?>">
                                            <?= e(ucfirst($status)) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>

            <!-- ATTENDANCE OVERVIEW -->
            <div class="card">

                <div class="card-header">
                    <div>
                        <h2>Attendance Overview</h2>
                        <p>Today's recorded entries</p>
                    </div>
                </div>

                <div class="overview-number">
                    <?= $attendanceRate ?>%
                </div>

                <p class="overview-caption">
                    Present out of all recorded entries today
                </p>

                <div class="progress-track">
                    <div
                        class="progress-bar"
                        style="width: <?= $attendanceRate ?>%"
                    ></div>
                </div>

                <div class="legend">
                    <span class="legend-label">
                        <span class="dot dot-green"></span>
                        Present
                    </span>
                    <strong><?= $presentToday ?></strong>
                </div>

                <div class="legend">
                    <span class="legend-label">
                        <span class="dot dot-red"></span>
                        Absent
                    </span>
                    <strong><?= $absentToday ?></strong>
                </div>

                <div class="legend">
                    <span class="legend-label">
                        <span class="dot dot-orange"></span>
                        Late
                    </span>
                    <strong><?= $lateToday ?></strong>
                </div>

                <div class="quick-actions">
                    <h3>Quick Actions</h3>

                   <a href="student.php" class="action-link">
    <span>＋ Add Student</span>
    <span>→</span>
</a>

                    <a href="attendance.php" class="action-link">
                        <span>✓ Record Attendance</span>
                        <span>→</span>
                    </a>

                    <a href="reports.php" class="action-link">
                        <span>▤ View Reports</span>
                        <span>→</span>
                    </a>
                </div>

            </div>

        </section>

    </main>
</div>

</body>
</html>
