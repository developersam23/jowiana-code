<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| JOWAINA SON - ADMIN DASHBOARD
|--------------------------------------------------------------------------
|
| File location:
| C:\xampp\htdocs\Jowian codes\admin.php
|
| Required files in the SAME folder:
| - config.php
| - admin-session.php
| - administrators.php
| - add-admin.php
|
|--------------------------------------------------------------------------
*/

require __DIR__ . '/admin-session.php';
require __DIR__ . '/config.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Variables
|--------------------------------------------------------------------------
*/

$error = '';

$adminName = '';
$adminEmail = '';

$totalRiders = 0;
$verifiedRiders = 0;
$pendingRiders = 0;
$rejectedRiders = 0;

$totalAdmins = 0;

$educationFilled = 0;
$educationMissing = 0;
$educationPercentage = 0;

$trainingTotal = 0;

$monthlyRegistrations = [];
$statusData = [];
$educationData = [];

$recentRiders = [];
$riders = [];
$trainingsBy = [];

$q = mb_substr(
    trim((string)($_GET['q'] ?? '')),
    0,
    100
);

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

try {

    $pdo = db();

    /*
    |--------------------------------------------------------------------------
    | Verify currently logged-in administrator
    |--------------------------------------------------------------------------
    */

    $me = $pdo->prepare(
        'SELECT
            id,
            full_name,
            email,
            role,
            status
         FROM riders
         WHERE id = :id
         LIMIT 1'
    );

    $me->execute([
        ':id' => (int)($_SESSION['rider_db_id'] ?? 0)
    ]);

    $admin = $me->fetch(PDO::FETCH_ASSOC);

    if (
        !$admin ||
        $admin['role'] !== 'admin' ||
        $admin['status'] !== 'verified'
    ) {

        session_destroy();

        header('Location: admin-login.html');
        exit;
    }

    $adminName = (string)$admin['full_name'];
    $adminEmail = (string)$admin['email'];

    // CSRF protection for rider status changes.
    if (empty($_SESSION['rider_status_csrf'])) {
        $_SESSION['rider_status_csrf'] = bin2hex(random_bytes(32));
    }
    $csrfToken = (string)$_SESSION['rider_status_csrf'];

    // Process Accept / Reject / Set Pending actions before any HTML is sent.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_rider_status'])) {
        $postedToken = (string)($_POST['csrf_token'] ?? '');
        $riderId = filter_var($_POST['rider_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = (string)($_POST['new_status'] ?? '');
        $allowedStatuses = ['verified', 'rejected', 'pending'];

        if (!hash_equals($csrfToken, $postedToken)) {
            header('Location: riders.php?error=invalid_action');
            exit;
        }

        if (!$riderId || $riderId < 1) {
            header('Location: riders.php?error=invalid_rider');
            exit;
        }

        if (!in_array($newStatus, $allowedStatuses, true)) {
            header('Location: riders.php?error=invalid_action');
            exit;
        }

        $target = $pdo->prepare(
            "SELECT id FROM riders WHERE id = :id AND role <> 'admin' LIMIT 1"
        );
        $target->execute([':id' => $riderId]);
        if (!$target->fetchColumn()) {
            header('Location: riders.php?error=rider_not_found');
            exit;
        }

        $update = $pdo->prepare(
            "UPDATE riders SET status = :status WHERE id = :id AND role <> 'admin'"
        );
        $update->execute([':status' => $newStatus, ':id' => $riderId]);

        $redirect = 'riders.php?' . http_build_query([
            'status_updated' => 1,
            'q' => (string)($_POST['return_q'] ?? '')
        ]) . '#rider-' . $riderId;
        header('Location: ' . $redirect);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Total riders
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM riders
         WHERE role <> 'admin'"
    );

    $totalRiders = (int)$stmt->fetchColumn();

    /*
    |--------------------------------------------------------------------------
    | Rider status statistics
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query(
        "SELECT status, COUNT(*) AS total
         FROM riders
         WHERE role <> 'admin'
         GROUP BY status"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {

        $status = strtolower((string)$row['status']);
        $count = (int)$row['total'];

        if ($status === 'verified') {
            $verifiedRiders = $count;
        }

        if ($status === 'pending') {
            $pendingRiders = $count;
        }

        if ($status === 'rejected') {
            $rejectedRiders = $count;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Administrator count
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM riders
         WHERE role = 'admin'"
    );

    $totalAdmins = (int)$stmt->fetchColumn();

    /*
    |--------------------------------------------------------------------------
    | Education statistics
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query(
        "SELECT COUNT(DISTINCT r.id)
         FROM riders r
         INNER JOIN rider_education e
             ON e.rider_id = r.id
         WHERE r.role <> 'admin'"
    );

    $educationFilled = (int)$stmt->fetchColumn();

    $educationMissing = max(
        0,
        $totalRiders - $educationFilled
    );

    if ($totalRiders > 0) {

        $educationPercentage = round(
            ($educationFilled / $totalRiders) * 100
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Training statistics
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM rider_trainings"
    );

    $trainingTotal = (int)$stmt->fetchColumn();

    /*
    |--------------------------------------------------------------------------
    | Monthly rider registrations
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query(
        "SELECT
            DATE_FORMAT(created_at, '%Y-%m') AS month_key,
            DATE_FORMAT(created_at, '%b %Y') AS month_name,
            COUNT(*) AS total
         FROM riders
         WHERE role <> 'admin'
           AND created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
         GROUP BY
            DATE_FORMAT(created_at, '%Y-%m'),
            DATE_FORMAT(created_at, '%b %Y')
         ORDER BY month_key ASC"
    );

    $monthlyRegistrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Status chart data
    |--------------------------------------------------------------------------
    */

    $statusData = [
        [
            'label' => 'Verified',
            'value' => $verifiedRiders
        ],
        [
            'label' => 'Pending',
            'value' => $pendingRiders
        ],
        [
            'label' => 'Rejected',
            'value' => $rejectedRiders
        ]
    ];

    /*
    |--------------------------------------------------------------------------
    | Education chart
    |--------------------------------------------------------------------------
    */

    $educationData = [
        [
            'label' => 'Completed',
            'value' => $educationFilled
        ],
        [
            'label' => 'Not completed',
            'value' => $educationMissing
        ]
    ];

    /*
    |--------------------------------------------------------------------------
    | Recent riders
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query(
        "SELECT
            id,
            full_name,
            rider_id,
            phone,
            email,
            status,
            created_at
         FROM riders
         WHERE role <> 'admin'
         ORDER BY id DESC
         LIMIT 8"
    );

    $recentRiders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Search riders
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            r.id,
            r.full_name,
            r.rider_id,
            r.phone,
            r.email,
            r.status,
            r.created_at,

            e.edu_level,
            e.course,
            e.field_of_study,
            e.school,
            e.location,
            e.year_done,
            e.edu_status,
            e.grade,
            e.cert_name,
            e.other_quals,
            e.updated_at

        FROM riders r

        LEFT JOIN rider_education e
            ON e.rider_id = r.id

        WHERE r.role <> 'admin'
    ";

    $params = [];

    if ($q !== '') {

        $like =
            '%' .
            str_replace(
                ['\\', '%', '_'],
                ['\\\\', '\\%', '\\_'],
                $q
            ) .
            '%';

        $sql .= "
            AND (
                r.full_name LIKE :q1
                OR r.phone LIKE :q2
                OR r.email LIKE :q3
                OR r.rider_id LIKE :q4
            )
        ";

        $params = [
            ':q1' => $like,
            ':q2' => $like,
            ':q3' => $like,
            ':q4' => $like
        ];
    }

    $sql .= "
        ORDER BY r.id DESC
        LIMIT 200
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Load rider training records
    |--------------------------------------------------------------------------
    */

    if ($riders) {

        $ids = array_map(
            static fn(array $r): int => (int)$r['id'],
            $riders
        );

        $marks = implode(
            ',',
            array_fill(0, count($ids), '?')
        );

        $trainingQuery = $pdo->prepare(
            "SELECT
                rider_id,
                training_type,
                institution,
                certification,
                completed_on
             FROM rider_trainings
             WHERE rider_id IN ($marks)
             ORDER BY id ASC"
        );

        $trainingQuery->execute($ids);

        foreach (
            $trainingQuery->fetchAll(PDO::FETCH_ASSOC)
            as $training
        ) {

            $trainingsBy[
                (int)$training['rider_id']
            ][] = $training;
        }
    }

} catch (PDOException $e) {

    error_log(
        'Jowaina Son admin dashboard error: ' .
        $e->getMessage()
    );

    $error =
        'Unable to load dashboard data. Please check your database tables.';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="robots"
        content="noindex, nofollow"
    >

    <title>
        Riders | Jowaina Son
    </title>

    <!-- Google Font -->
    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <!-- Font Awesome -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


<?php
$statusUpdated = (string)($_GET['status_updated'] ?? '');
$urlError = '';
$errorMap = [
    'invalid_rider'=>'Invalid rider selected.',
    'invalid_action'=>'Invalid status action.',
    'rider_not_found'=>'Rider was not found.',
    'database'=>'A database error occurred. Please try again.'
];
if (isset($_GET['error']) && isset($errorMap[$_GET['error']])) { $urlError=$errorMap[$_GET['error']]; }
?>
<style>

    /* =========================================================
           GLOBAL
        ========================================================= */

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {

            --orange: #ff6a00;
            --orange-dark: #e85c00;
            --orange-light: #fff1e6;

            --black: #0b0b0d;
            --black-soft: #151518;

            --white: #ffffff;

            --bg: #f5f6f8;

            --text: #17191c;
            --muted: #747b87;

            --border: #e7e9ed;

            --green: #16a34a;
            --green-bg: #eaf8ef;

            --yellow: #d97706;
            --yellow-bg: #fff7e6;

            --red: #dc2626;
            --red-bg: #feecec;

            --blue: #2563eb;
            --blue-bg: #eaf1ff;

            --sidebar-width: 255px;
        }

        body {

            font-family:
                'Poppins',
                Arial,
                sans-serif;

            background: var(--bg);

            color: var(--text);

            min-height: 100vh;

            line-height: 1.5;
        }

        a {
            text-decoration: none;
        }

        button,
        input {
            font-family: inherit;
        }

        /* =========================================================
           SIDEBAR
        ========================================================= */

        .sidebar {

            position: fixed;

            top: 0;
            left: 0;

            width: var(--sidebar-width);
            height: 100vh;

            background:
                linear-gradient(
                    180deg,
                    #0b0b0d 0%,
                    #121214 100%
                );

            color: white;

            z-index: 1000;

            display: flex;
            flex-direction: column;

            border-right: 1px solid #222;
        }

        .brand {

            height: 78px;

            display: flex;

            align-items: center;

            gap: 12px;

            padding: 0 22px;

            border-bottom: 1px solid #252525;
        }

        .brand-icon {

            width: 42px;
            height: 42px;

            background: var(--orange);

            border-radius: 11px;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 18px;
            font-weight: 800;

            box-shadow:
                0 8px 25px
                rgba(255,106,0,.25);
        }

        .brand-text strong {

            display: block;

            font-size: 15px;

            letter-spacing: .4px;
        }

        .brand-text span {

            display: block;

            color: #858585;

            font-size: 10px;

            margin-top: 1px;
        }

        .nav {

            padding: 20px 12px;

            flex: 1;

            overflow-y: auto;
        }

        .nav-title {

            color: #666;

            font-size: 10px;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: 1px;

            padding: 0 12px 9px;
        }

        .nav a {

            display: flex;

            align-items: center;

            gap: 12px;

            padding: 11px 13px;

            color: #a9a9ad;

            border-radius: 8px;

            margin-bottom: 4px;

            font-size: 13px;

            transition: .2s;
        }

        .nav a i {

            width: 20px;

            text-align: center;

            font-size: 14px;
        }

        .nav a:hover {

            background: #1f1f22;

            color: white;
        }

        .nav a.active {

            background: var(--orange);

            color: white;

            box-shadow:
                0 7px 18px
                rgba(255,106,0,.22);
        }

        .sidebar-bottom {

            padding: 15px;

            border-top: 1px solid #252525;
        }

        .admin-mini {

            display: flex;

            align-items: center;

            gap: 10px;

            padding: 10px;

            background: #18181b;

            border-radius: 9px;

            margin-bottom: 10px;
        }

        .admin-avatar {

            width: 35px;
            height: 35px;

            border-radius: 50%;

            background: var(--orange);

            display: flex;
            align-items: center;
            justify-content: center;

            color: white;

            font-size: 13px;
            font-weight: 700;
        }

        .admin-info {

            min-width: 0;
        }

        .admin-info strong {

            display: block;

            font-size: 11px;

            white-space: nowrap;

            overflow: hidden;

            text-overflow: ellipsis;
        }

        .admin-info span {

            display: block;

            color: #777;

            font-size: 9px;
        }

        .logout {

            display: flex;

            justify-content: center;

            align-items: center;

            gap: 7px;

            padding: 9px;

            border-radius: 7px;

            color: #aaa;

            font-size: 12px;

            background: #18181b;
        }

        .logout:hover {

            color: white;

            background: #252529;
        }

        /* =========================================================
           MAIN
        ========================================================= */

        .main {

            margin-left: var(--sidebar-width);

            min-height: 100vh;
        }

        .topbar {

            height: 78px;

            background: white;

            border-bottom: 1px solid var(--border);

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 0 30px;

            position: sticky;

            top: 0;

            z-index: 100;
        }

        .mobile-menu {

            display: none;

            border: 0;

            background: transparent;

            font-size: 21px;

            cursor: pointer;

            color: var(--text);
        }

        .top-title h1 {

            font-size: 21px;

            font-weight: 700;
        }

        .top-title p {

            color: var(--muted);

            font-size: 11px;

            margin-top: 2px;
        }

        .top-right {

            display: flex;

            align-items: center;

            gap: 12px;
        }

        .date-badge {

            padding: 7px 11px;

            background: #f7f7f8;

            border: 1px solid var(--border);

            border-radius: 7px;

            color: var(--muted);

            font-size: 11px;
        }

        .content {

            padding: 28px 30px;

            max-width: 1600px;

            margin: auto;
        }

        /* Sidebar section targets: keep anchored content below the sticky topbar */
        #education, #riders, #training {
            scroll-margin-top: 96px;
        }

        .section-anchor {
            height: 0;
            margin: 0;
            padding: 0;
            scroll-margin-top: 96px;
        }

        .nav a.section-link.active {
            background: var(--orange);
            color: white;
            box-shadow: 0 7px 18px rgba(255,106,0,.22);
        }

        /* =========================================================
           WELCOME
        ========================================================= */

        .welcome {

            background:
                linear-gradient(
                    110deg,
                    #101012 0%,
                    #17171a 55%,
                    #21170f 100%
                );

            border-radius: 15px;

            padding: 25px 28px;

            color: white;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 20px;

            margin-bottom: 22px;

            overflow: hidden;

            position: relative;
        }

        .welcome::after {

            content: '';

            position: absolute;

            width: 240px;
            height: 240px;

            border-radius: 50%;

            background: var(--orange);

            opacity: .08;

            right: -70px;

            top: -100px;
        }

        .welcome h2 {

            font-size: 20px;

            margin-bottom: 4px;
        }

        .welcome p {

            color: #a7a7aa;

            font-size: 11px;
        }

        .welcome-action {

            position: relative;

            z-index: 2;

            display: flex;

            gap: 9px;

            flex-wrap: wrap;
        }

        .btn {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 7px;

            padding: 10px 15px;

            border-radius: 7px;

            border: 0;

            font-size: 11px;

            font-weight: 600;

            cursor: pointer;
        }

        .btn-orange {

            background: var(--orange);

            color: white;
        }

        .btn-orange:hover {

            background: var(--orange-dark);
        }

        .btn-dark {

            background: #29292c;

            color: white;

            border: 1px solid #3a3a3d;
        }

        /* =========================================================
           STAT CARDS
        ========================================================= */

        .stats-grid {

            display: grid;

            grid-template-columns:
                repeat(5, minmax(0, 1fr));

            gap: 15px;

            margin-bottom: 20px;
        }

        .stat-card {

            background: white;

            border: 1px solid var(--border);

            border-radius: 12px;

            padding: 17px;

            position: relative;

            overflow: hidden;

            transition: transform .2s,
                        box-shadow .2s;
        }

        .stat-card:hover {

            transform: translateY(-2px);

            box-shadow:
                0 8px 25px
                rgba(0,0,0,.06);
        }

        .stat-top {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 12px;
        }

        .stat-icon {

            width: 38px;
            height: 38px;

            border-radius: 9px;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 15px;
        }

        .icon-orange {
            background: var(--orange-light);
            color: var(--orange);
        }

        .icon-green {
            background: var(--green-bg);
            color: var(--green);
        }

        .icon-yellow {
            background: var(--yellow-bg);
            color: var(--yellow);
        }

        .icon-red {
            background: var(--red-bg);
            color: var(--red);
        }

        .icon-blue {
            background: var(--blue-bg);
            color: var(--blue);
        }

        .stat-label {

            color: var(--muted);

            font-size: 10px;

            font-weight: 500;
        }

        .stat-number {

            font-size: 25px;

            line-height: 1;

            font-weight: 800;

            margin-top: 3px;
        }

        .stat-sub {

            color: #9298a2;

            font-size: 9px;

            margin-top: 7px;
        }

        /* =========================================================
           CHARTS
        ========================================================= */

        .charts-grid {

            display: grid;

            grid-template-columns:
                minmax(0, 2fr)
                minmax(300px, 1fr);

            gap: 18px;

            margin-bottom: 20px;
        }

        .chart-card {

            background: white;

            border: 1px solid var(--border);

            border-radius: 12px;

            padding: 18px;

            min-width: 0;
        }

        .card-heading {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 10px;

            margin-bottom: 17px;
        }

        .card-heading h3 {

            font-size: 13px;

            font-weight: 700;
        }

        .card-heading p {

            font-size: 9px;

            color: var(--muted);

            margin-top: 2px;
        }

        .chart-wrap {

            position: relative;

            height: 260px;
        }

        .chart-wrap.small {

            height: 220px;
        }

        /* =========================================================
           LOWER GRID
        ========================================================= */

        .lower-grid {

            display: grid;

            grid-template-columns:
                minmax(0, 1.5fr)
                minmax(300px, 1fr);

            gap: 18px;

            margin-bottom: 22px;
        }

        .panel {

            background: white;

            border: 1px solid var(--border);

            border-radius: 12px;

            overflow: hidden;
        }

        .panel-header {

            padding: 16px 18px;

            border-bottom: 1px solid var(--border);

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 10px;
        }

        .panel-header h3 {

            font-size: 13px;
        }

        .panel-header a {

            color: var(--orange);

            font-size: 10px;

            font-weight: 600;
        }

        /* =========================================================
           RECENT RIDERS
        ========================================================= */

        .rider-row {

            padding: 13px 18px;

            display: grid;

            grid-template-columns:
                38px minmax(0, 1fr) auto;

            gap: 11px;

            align-items: center;

            border-bottom: 1px solid #f0f1f3;
        }

        .rider-row:last-child {

            border-bottom: 0;
        }

        .rider-avatar {

            width: 36px;
            height: 36px;

            border-radius: 50%;

            background:
                linear-gradient(
                    135deg,
                    var(--orange),
                    #ff9b50
                );

            display: flex;

            align-items: center;

            justify-content: center;

            color: white;

            font-weight: 700;

            font-size: 11px;
        }

        .rider-name {

            font-size: 11px;

            font-weight: 600;

            white-space: nowrap;

            overflow: hidden;

            text-overflow: ellipsis;
        }

        .rider-meta {

            font-size: 9px;

            color: var(--muted);

            margin-top: 2px;
        }

        .status {

            display: inline-flex;

            padding: 4px 8px;

            border-radius: 20px;

            font-size: 8px;

            font-weight: 600;
        }

        .status-verified {

            color: var(--green);

            background: var(--green-bg);
        }

        .status-pending {

            color: var(--yellow);

            background: var(--yellow-bg);
        }

        .status-rejected {

            color: var(--red);

            background: var(--red-bg);
        }

        /* =========================================================
           QUICK ACTIONS
        ========================================================= */

        .actions {

            padding: 15px;

            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 10px;
        }

        .action {

            padding: 14px 12px;

            border: 1px solid var(--border);

            border-radius: 9px;

            color: var(--text);

            transition: .2s;

            background: white;
        }

        .action:hover {

            border-color: var(--orange);

            background: var(--orange-light);

            transform: translateY(-1px);
        }

        .action i {

            width: 31px;
            height: 31px;

            display: flex;

            align-items: center;

            justify-content: center;

            border-radius: 7px;

            background: var(--orange-light);

            color: var(--orange);

            margin-bottom: 8px;

            font-size: 12px;
        }

        .action strong {

            display: block;

            font-size: 10px;
        }

        .action span {

            display: block;

            color: var(--muted);

            font-size: 8px;

            margin-top: 2px;
        }

        /* =========================================================
           EDUCATION PROGRESS
        ========================================================= */

        .education-box {

            padding: 20px;
        }

        .progress-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 8px;
        }

        .progress-header span {

            font-size: 10px;

            color: var(--muted);
        }

        .progress-header strong {

            font-size: 17px;

            color: var(--orange);
        }

        .progress {

            height: 9px;

            background: #eee;

            border-radius: 10px;

            overflow: hidden;
        }

        .progress-bar {

            height: 100%;

            background:
                linear-gradient(
                    90deg,
                    var(--orange),
                    #ff9a4d
                );

            border-radius: 10px;

            width: <?= min(100, $educationPercentage) ?>%;
        }

        .education-info {

            display: grid;

            grid-template-columns: 1fr 1fr;

            margin-top: 13px;

            gap: 10px;
        }

        .education-info div {

            padding: 10px;

            border-radius: 8px;

            background: #f8f8f9;
        }

        .education-info span {

            display: block;

            color: var(--muted);

            font-size: 8px;
        }

        .education-info strong {

            display: block;

            font-size: 14px;

            margin-top: 2px;
        }

        /* =========================================================
           RIDER SEARCH
        ========================================================= */

        .search-section {

            margin-top: 22px;
        }

        .search-bar {

            display: flex;

            gap: 8px;

            margin-bottom: 15px;
        }

        .search-bar input {

            flex: 1;

            min-width: 0;

            padding: 11px 13px;

            border: 1px solid var(--border);

            border-radius: 8px;

            outline: none;

            font-size: 11px;

            background: white;
        }

        .search-bar input:focus {

            border-color: var(--orange);

            box-shadow:
                0 0 0 3px
                rgba(255,106,0,.08);
        }

        .search-button {

            padding: 0 17px;

            border: 0;

            background: var(--orange);

            color: white;

            border-radius: 8px;

            font-size: 11px;

            font-weight: 600;

            cursor: pointer;
        }

        .clear-button {

            padding: 10px 13px;

            border: 1px solid var(--border);

            color: var(--muted);

            background: white;

            border-radius: 8px;

            font-size: 11px;

        }

        /* =========================================================
           RIDER DETAILS
        ========================================================= */

        details.rider {

            background: white;

            border: 1px solid var(--border);

            border-radius: 10px;

            margin-bottom: 8px;

            overflow: hidden;
        }

        details.rider[open] {

            border-color: var(--orange);
        }

        summary {

            list-style: none;

            cursor: pointer;

            padding: 13px 15px;

            display: grid;

            grid-template-columns:
                minmax(0, 1fr)
                auto;

            gap: 3px 10px;

            align-items: center;
        }

        summary::-webkit-details-marker {

            display: none;
        }

        .summary-name {

            font-size: 11px;

            font-weight: 600;
        }

        .summary-meta {

            color: var(--muted);

            font-size: 9px;
        }

        .summary-tags {

            grid-column: 2;

            grid-row: 1 / span 2;

            display: flex;

            gap: 5px;

            flex-wrap: wrap;

            justify-content: flex-end;
        }

        .tag {

            font-size: 8px;

            font-weight: 600;

            padding: 4px 8px;

            border-radius: 20px;

            background: #f1f3f5;

            color: var(--muted);
        }

        .tag.ok {

            color: var(--green);

            background: var(--green-bg);
        }

        .tag.wait {

            color: var(--yellow);

            background: var(--yellow-bg);
        }

        .rider-body {

            padding: 17px;

            border-top: 1px solid var(--border);
        }

        .details-grid {

            display: grid;

            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(170px, 1fr)
                );

            gap: 13px 20px;
        }

        .detail-item label {

            display: block;

            text-transform: uppercase;

            letter-spacing: .4px;

            color: var(--muted);

            font-size: 7px;

            font-weight: 600;

            margin-bottom: 2px;
        }

        .detail-item div {

            font-size: 10px;

            font-weight: 500;
        }

        .section-title {

            font-size: 11px;

            margin: 18px 0 9px;

            font-weight: 700;
        }

        .table-wrap {

            overflow-x: auto;
        }

        table {

            width: 100%;

            border-collapse: collapse;

            min-width: 600px;

            font-size: 9px;
        }

        th {

            text-align: left;

            padding: 9px;

            color: var(--muted);

            background: #f8f8f9;

            font-size: 8px;
        }

        td {

            padding: 9px;

            border-bottom: 1px solid #eee;
        }

        .muted {

            color: var(--muted);

            font-size: 9px;
        }

        /* =========================================================
           ERROR / EMPTY
        ========================================================= */

        .success {
            padding: 13px 15px;
            border-radius: 10px;
            background: #eaf8ef;
            border: 1px solid #b8e5c7;
            color: #166534;
            font-size: 12px;
            margin-bottom: 18px;
        }

        .error {

            padding: 15px;

            border-radius: 10px;

            background: var(--red-bg);

            border: 1px solid #f5bebe;

            color: #991b1b;

            font-size: 11px;

            margin-bottom: 18px;
        }

        .empty {

            padding: 35px 20px;

            text-align: center;

            background: white;

            border: 1px dashed var(--border);

            border-radius: 10px;

            color: var(--muted);

            font-size: 11px;
        }

        .empty i {

            display: block;

            font-size: 30px;

            margin-bottom: 10px;

            color: #c5c8ce;
        }

        /* =========================================================
           MOBILE
        ========================================================= */

        .overlay {

            display: none;

            position: fixed;

            inset: 0;

            background: rgba(0,0,0,.45);

            z-index: 999;
        }

        @media (max-width: 1200px) {

            .stats-grid {

                grid-template-columns:
                    repeat(3, 1fr);
            }

            .charts-grid {

                grid-template-columns: 1fr;
            }

            .lower-grid {

                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 850px) {

            :root {

                --sidebar-width: 0px;
            }

            .sidebar {

                transform:
                    translateX(-260px);

                transition: transform .25s ease;

                width: 255px;
            }

            .sidebar.open {

                transform:
                    translateX(0);
            }

            .overlay.open {

                display: block;
            }

            .main {

                margin-left: 0;
            }

            .mobile-menu {

                display: block;
            }

            .topbar {

                padding: 0 16px;
            }

            .content {

                padding: 18px 15px;
            }

            .welcome {

                padding: 20px;

                flex-direction: column;

                align-items: flex-start;
            }

            .stats-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }

            .date-badge {

                display: none;
            }
        }

        @media (max-width: 500px) {

            .stats-grid {

                grid-template-columns: 1fr 1fr;

                gap: 9px;
            }

            .stat-card {

                padding: 13px;
            }

            .stat-number {

                font-size: 21px;
            }

            .top-title h1 {

                font-size: 17px;
            }

            .welcome h2 {

                font-size: 17px;
            }

            .welcome-action {

                width: 100%;
            }

            .welcome-action .btn {

                flex: 1;
            }

            .chart-wrap {

                height: 220px;
            }

            .actions {

                grid-template-columns: 1fr;
            }

            .summary-tags {

                grid-column: 1;

                grid-row: 3;

                justify-content: flex-start;

                margin-top: 5px;
            }

            summary {

                grid-template-columns: 1fr;
            }
        }

    

/* =========================================================
   SEPARATE ADMIN PAGE COMPONENTS
========================================================= */
.page-heading{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:22px;}
.page-heading h2{margin:0;font-size:24px;}
.page-heading p{margin:6px 0 0;color:var(--muted);font-size:13px;}
.form-label{display:block;margin-bottom:7px;font-size:12px;font-weight:700;color:var(--text);}
.search-input{width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:10px;background:#fff;color:var(--text);outline:none;}
.search-input:focus{border-color:var(--orange);box-shadow:0 0 0 3px rgba(255,106,0,.10);}
.btn-success{background:#16834b!important;color:#fff!important;border-color:#16834b!important;}
.btn-danger{background:#c93636!important;color:#fff!important;border-color:#c93636!important;}
.back-link{display:inline-flex;align-items:center;gap:8px;margin-bottom:18px;color:var(--orange);font-weight:700;text-decoration:none;}
.details-card{background:#fff;border:1px solid var(--border);border-radius:14px;padding:22px;margin-bottom:20px;box-shadow:0 5px 18px rgba(0,0,0,.04);}
.details-card h3{margin:0 0 18px;font-size:17px;}
.details-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;}
.details-grid>div{padding:13px;border:1px solid var(--border);border-radius:10px;background:#fafafa;min-width:0;}
.details-grid label{display:block;color:var(--muted);font-size:11px;font-weight:700;text-transform:uppercase;margin-bottom:6px;}
.status-pill{display:inline-flex;align-items:center;padding:7px 12px;border-radius:999px;font-size:12px;font-weight:800;text-transform:capitalize;}
.status-verified{background:#e7f7ee;color:#16834b;}
.status-pending{background:#fff4d8;color:#9b6800;}
.status-rejected{background:#fde9e9;color:#b32f2f;}
.verification-actions{display:flex;gap:10px;flex-wrap:wrap;}
@media(max-width:900px){.details-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.page-heading{align-items:flex-start;flex-direction:column;}}
@media(max-width:600px){.details-grid{grid-template-columns:1fr;}.verification-actions{flex-direction:column;}.verification-actions form,.verification-actions .btn{width:100%;}.verification-actions button{width:100%;}.table-wrap{overflow-x:auto;}.page-heading h2{font-size:20px;}}

    </style>
</head>
<body>

<!-- =========================================================
     MOBILE OVERLAY
========================================================= -->

<div
    class="overlay"
    id="overlay"
    onclick="closeSidebar()"
></div>

<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="brand">

        <div class="brand-icon">
            JS
        </div>

        <div class="brand-text">

            <strong>JOWAINA SON</strong>

            <span>ADMINISTRATION PANEL</span>

        </div>

    </div>

    <nav class="nav">

        <div class="nav-title">
            Main menu
        </div>

        <a
            href="admin.php"
            class=""
        >
            <i class="fa-solid fa-chart-pie"></i>
            Dashboard
        </a>

        <a href="administrators.php">
            <i class="fa-solid fa-user-shield"></i>
            Administrators
        </a>

        <a href="add-admin.php">
            <i class="fa-solid fa-user-plus"></i>
            Add Administrator
        </a>

        <div
            class="nav-title"
            style="margin-top:22px;"
        >
            Management
        </div>

        <a href="riders.php" class="active">
            <i class="fa-solid fa-motorcycle"></i>
            Riders
        </a>

        <a href="education.php" class="">
            <i class="fa-solid fa-graduation-cap"></i>
            Education
        </a>

        <a href="training.php" class="">
            <i class="fa-solid fa-certificate"></i>
            Training
        </a>

        <div
            class="nav-title"
            style="margin-top:22px;"
        >
            Account
        </div>

        <a href="admin-profile.php">
            <i class="fa-solid fa-user"></i>
            My Profile
        </a>

    </nav>

    <div class="sidebar-bottom">

        <div class="admin-mini">

            <div class="admin-avatar">

                <?= h(
                    strtoupper(
                        substr(
                            $adminName,
                            0,
                            1
                        )
                    )
                ) ?>

            </div>

            <div class="admin-info">

                <strong>
                    <?= h($adminName) ?>
                </strong>

                <span>
                    Administrator
                </span>

            </div>

        </div>

        <a
            class="logout"
            href="logout.php"
        >
            <i class="fa-solid fa-right-from-bracket"></i>
            Sign out
        </a>

    </div>

</aside>

<!-- =========================================================
     MAIN
========================================================= -->

<div class="main">

    <!-- TOPBAR -->

    <header class="topbar">

        <div style="display:flex;align-items:center;gap:12px;">

            <button
                class="mobile-menu"
                onclick="openSidebar()"
                aria-label="Open menu"
            >
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="top-title">

                <h1>
                    Riders
                </h1>

                <p>
                    Registered riders and verification management
                </p>

            </div>

        </div>

        <div class="top-right">

            <div class="date-badge">

                <i class="fa-regular fa-calendar"></i>

                <?= date('l, d M Y') ?>

            </div>

        </div>

    </header>

    <!-- CONTENT -->


<main class="content">

<?php if (!empty($statusUpdated)): ?>
<div class="success"><i class="fa-solid fa-circle-check"></i> Rider status updated successfully.</div>
<?php endif; ?>

<?php if ($urlError): ?>
<div class="error"><i class="fa-solid fa-triangle-exclamation"></i> <?= h($urlError) ?></div>
<?php endif; ?>

<div class="page-heading">
    <div><h2>Rider Management</h2><p>View, search and verify registered Jowaina Son riders.</p></div>
    <a class="btn btn-primary" href="riders.php"><i class="fa-solid fa-rotate"></i> Refresh</a>
</div>

<section
            class="search-section section-target"
            id="riders"
        >

            <div
                style="
                    margin-bottom:12px;
                "
            >

                <h2
                    style="
                        font-size:17px;
                        margin-bottom:2px;
                    "
                >
                    Rider Management
                </h2>

                <p
                    style="
                        font-size:10px;
                        color:var(--muted);
                    "
                >
                    Search and review rider registration,
                    education and training information.
                </p>

            </div>

            <form
                class="search-bar"
                method="GET"
                action="riders.php"
            >

                <input
                    type="search"
                    name="q"
                    value="<?= h($q) ?>"
                    placeholder="Search by name, phone, email or Rider ID..."
                    maxlength="100"
                >

                <button
                    class="search-button"
                    type="submit"
                >
                    <i class="fa-solid fa-search"></i>
                    Search
                </button>

                <?php if ($q !== ''): ?>

                    <a
                        href="riders.php"
                        class="clear-button"
                    >
                        Clear
                    </a>

                <?php endif; ?>

            </form>

            <?php if (!$riders): ?>

                <div class="empty">

                    <i class="fa-solid fa-user-slash"></i>

                    <?php if ($q !== ''): ?>

                        No riders match your search.

                    <?php else: ?>

                        No riders have registered yet.

                    <?php endif; ?>

                </div>

            <?php endif; ?>

            <?php foreach ($riders as $rider): ?>

                <?php

                $hasEducation =
                    $rider['edu_level'] !== null;

                $trainings =
                    $trainingsBy[
                        (int)$rider['id']
                    ] ?? [];

                $status =
                    strtolower(
                        (string)$rider['status']
                    );

                $isVerified =
                    $status === 'verified';

                ?>

                <details class="rider" id="rider-<?= (int)$rider['id'] ?>">

                    <summary>

                        <span class="summary-name">

                            <?= h(
                                $rider['full_name']
                            ) ?>

                        </span>

                        <span class="summary-meta">

                            <?= h(
                                $rider['rider_id']
                            ) ?>

                            &nbsp;•&nbsp;

                            <?= h(
                                $rider['phone']
                            ) ?>

                            &nbsp;•&nbsp;

                            <?= h(
                                $rider['email']
                            ) ?>

                        </span>

                        <span class="summary-tags">

                            <span
                                class="tag
                                <?= $isVerified
                                    ? 'ok'
                                    : 'wait'
                                ?>"
                            >

                                <?= h(
                                    ucfirst($status)
                                ) ?>

                            </span>

                            <span
                                class="tag
                                <?= $hasEducation
                                    ? 'ok'
                                    : ''
                                ?>"
                            >

                                <?= $hasEducation
                                    ? h(
                                        $rider['edu_level']
                                    )
                                    : 'No education'
                                ?>

                            </span>

                        </span>

                    </summary>

                    <div class="rider-body">

<a class="btn btn-primary" href="rider-view.php?id=<?= (int)$rider['id'] ?>"><i class="fa-solid fa-eye"></i> Open Full Rider Page</a>

                        <form method="post" action="riders.php" class="verification-actions" style="margin-top:14px;align-items:center;">
                            <input type="hidden" name="update_rider_status" value="1">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="rider_id" value="<?= (int)$rider['id'] ?>">
                            <input type="hidden" name="return_q" value="<?= h($q) ?>">
                            <button class="btn btn-success" type="submit" name="new_status" value="verified" <?= $status === 'verified' ? 'disabled' : '' ?>><i class="fa-solid fa-check"></i> Accept / Verify</button>
                            <button class="btn btn-danger" type="submit" name="new_status" value="rejected" <?= $status === 'rejected' ? 'disabled' : '' ?>><i class="fa-solid fa-xmark"></i> Reject</button>
                            <button class="btn btn-dark" type="submit" name="new_status" value="pending" <?= $status === 'pending' ? 'disabled' : '' ?>><i class="fa-solid fa-clock"></i> Set Pending</button>
                        </form>


                        <!-- BASIC INFORMATION -->

                        <h3 class="section-title">
                            <i
                                class="fa-solid fa-user"
                                style="color:var(--orange);"
                            ></i>

                            Rider Information
                        </h3>

                        <div class="details-grid">

                            <div class="detail-item">

                                <label>
                                    Full name
                                </label>

                                <div>
                                    <?= h(
                                        $rider['full_name']
                                    ) ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <label>
                                    Rider ID
                                </label>

                                <div>
                                    <?= h(
                                        $rider['rider_id']
                                    ) ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <label>
                                    Phone
                                </label>

                                <div>
                                    <?= h(
                                        $rider['phone']
                                    ) ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <label>
                                    Email
                                </label>

                                <div>
                                    <?= h(
                                        $rider['email']
                                    ) ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <label>
                                    Account status
                                </label>

                                <div>
                                    <?= h(
                                        ucfirst($status)
                                    ) ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <label>
                                    Registered
                                </label>

                                <div>
                                    <?= h(
                                        $rider['created_at']
                                    ) ?>
                                </div>

                            </div>

                        </div>

                        <!-- EDUCATION -->

                        <h3 class="section-title">

                            <i
                                class="fa-solid fa-graduation-cap"
                                style="color:var(--orange);"
                            ></i>

                            Education

                        </h3>

                        <?php if (!$hasEducation): ?>

                            <p class="muted">

                                This rider has not filled in
                                the education section yet.

                            </p>

                        <?php else: ?>

                            <div class="details-grid">

                                <div class="detail-item">

                                    <label>
                                        Highest level
                                    </label>

                                    <div>
                                        <?= h(
                                            $rider['edu_level']
                                        ) ?>
                                    </div>

                                </div>

                                <div class="detail-item">

                                    <label>
                                        Course / programme
                                    </label>

                                    <div>
                                        <?= h(
                                            $rider['course']
                                        ) ?>
                                    </div>

                                </div>

                                <div class="detail-item">

                                    <label>
                                        Field of study
                                    </label>

                                    <div>
                                        <?= h(
                                            $rider['field_of_study']
                                        ) ?>
                                    </div>

                                </div>

                                <div class="detail-item">

                                    <label>
                                        School / institution
                                    </label>

                                    <div>
                                        <?= h(
                                            $rider['school']
                                        ) ?>
                                    </div>

                                </div>

                                <div class="detail-item">

                                    <label>
                                        Location
                                    </label>

                                    <div>
                                        <?= h(
                                            $rider['location']
                                        ) ?>
                                    </div>

                                </div>

                                <div class="detail-item">

                                    <label>
                                        Year completed
                                    </label>

                                    <div>
                                        <?= h(
                                            $rider['year_done']
                                        ) ?>
                                    </div>

                                </div>

                                <div class="detail-item">

                                    <label>
                                        Education status
                                    </label>

                                    <div>
                                        <?= h(
                                            $rider['edu_status']
                                        ) ?>
                                    </div>

                                </div>

                                <div class="detail-item">

                                    <label>
                                        Grade
                                    </label>

                                    <div>
                                        <?= h(
                                            $rider['grade']
                                        ) ?>
                                    </div>

                                </div>

                                <div class="detail-item">

                                    <label>
                                        Certificate
                                    </label>

                                    <div>
                                        <?= $rider['cert_name']
                                            ? h(
                                                $rider['cert_name']
                                            )
                                            : '-'
                                        ?>
                                    </div>

                                </div>

                                <div class="detail-item">

                                    <label>
                                        Other qualifications
                                    </label>

                                    <div>
                                        <?= $rider['other_quals']
                                            ? h(
                                                $rider['other_quals']
                                            )
                                            : '-'
                                        ?>
                                    </div>

                                </div>

                            </div>

                        <?php endif; ?>

                        <!-- TRAINING -->

                        <h3 class="section-title rider-training-title">

                            <i
                                class="fa-solid fa-certificate"
                                style="color:var(--orange);"
                            ></i>

                            Professional & Vocational Training

                        </h3>

                        <?php if ($trainings): ?>

                            <div class="table-wrap">

                                <table>

                                    <thead>

                                        <tr>

                                            <th>
                                                Training
                                            </th>

                                            <th>
                                                Institution
                                            </th>

                                            <th>
                                                Certification
                                            </th>

                                            <th>
                                                Completed
                                            </th>

                                        </tr>

                                    </thead>

                                    <tbody>

                                        <?php foreach (
                                            $trainings
                                            as $training
                                        ): ?>

                                            <tr>

                                                <td>
                                                    <?= h(
                                                        $training[
                                                            'training_type'
                                                        ]
                                                    ) ?>
                                                </td>

                                                <td>
                                                    <?= h(
                                                        $training[
                                                            'institution'
                                                        ]
                                                    ) ?>
                                                </td>

                                                <td>
                                                    <?= h(
                                                        $training[
                                                            'certification'
                                                        ]
                                                    ) ?>
                                                </td>

                                                <td>
                                                    <?= h(
                                                        $training[
                                                            'completed_on'
                                                        ]
                                                    ) ?>
                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                        <?php else: ?>

                            <p class="muted">
                                No professional training records.
                            </p>

                        <?php endif; ?>

                        <?php if ($hasEducation): ?>

                            <p
                                class="muted"
                                style="margin-top:12px;"
                            >

                                Last education update:
                                <?= h(
                                    $rider['updated_at']
                                ) ?>

                            </p>

                        <?php endif; ?>

                    </div>

                </details>

            <?php endforeach; ?>

            <?php if (count($riders) === 200): ?>

                <p
                    class="muted"
                    style="margin:12px 0 0;"
                >

                    Showing the 200 newest riders.
                    Use the search box to find other riders.

                </p>

            <?php endif; ?>

        </section>


    </main>
</div>

<script>
function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('overlay').classList.add('open');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').classList.remove('open');}
</script>

</body>
</html>
