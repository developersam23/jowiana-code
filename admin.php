<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| JOWAINA SON - ADMIN DASHBOARD (merged, with rider verification buttons)
|--------------------------------------------------------------------------
| File location: C:\xampp\htdocs\Jowian codes\admin.php
|
| Required files in the SAME folder:
| - config.php
| - admin-session.php
| - administrators.php
| - add-admin.php
| - file.php (serves uploaded documents - must be admin-protected)
|--------------------------------------------------------------------------
*/

require __DIR__ . '/admin-session.php';
require __DIR__ . '/config.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* Single-line, trimmed text (used for the copy-and-paste details) */
function oneLine(mixed $value): string
{
    return trim((string)preg_replace('/\s+/', ' ', (string)$value));
}

/* ---------------------------- Variables ---------------------------- */

$error = '';
$flash = null;
$csrf  = '';

$adminName  = '';
$adminEmail = '';

$totalRiders    = 0;
$verifiedRiders = 0;
$pendingRiders  = 0;
$rejectedRiders = 0;

$totalAdmins = 0;

$educationFilled     = 0;
$educationMissing    = 0;
$educationPercentage = 0;

$trainingTotal = 0;

$monthlyRegistrations = [];
$statusData           = [];
$educationData        = [];

$recentRiders = [];
$riders       = [];
$trainingsBy  = [];
$docsBy       = [];

$docTotal   = 0;
$recentDocs = [];
$docError   = '';

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

/* ---------------------------- Database ---------------------------- */

try {

    $pdo = db();

    /* Verify currently logged-in administrator */
    $me = $pdo->prepare(
        'SELECT id, full_name, email, role, status
           FROM riders
          WHERE id = :id
          LIMIT 1'
    );
    $me->execute([':id' => (int)($_SESSION['rider_db_id'] ?? 0)]);
    $admin = $me->fetch(PDO::FETCH_ASSOC);

    if (!$admin || $admin['role'] !== 'admin' || $admin['status'] !== 'verified') {
        session_destroy();
        header('Location: admin-login.html');
        exit;
    }

    $adminName  = (string)$admin['full_name'];
    $adminEmail = (string)$admin['email'];

    /* CSRF token for the status buttons */
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    $csrf = (string)$_SESSION['admin_csrf'];

    /* Accept / Reject / Pending a rider */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {

        $postedToken = (string)($_POST['csrf'] ?? '');
        $riderDbId   = (int)($_POST['rider_db_id'] ?? 0);
        $newStatus   = strtolower((string)($_POST['status'] ?? ''));
        $backQ       = mb_substr(trim((string)($_POST['q'] ?? '')), 0, 100);

        if (!hash_equals($csrf, $postedToken)) {
            $_SESSION['admin_flash'] = ['err', 'Security check failed. Please try again.'];
        } elseif ($riderDbId > 0 && in_array($newStatus, ['verified', 'pending', 'rejected'], true)) {
            $upd = $pdo->prepare(
                "UPDATE riders SET status = :s WHERE id = :id AND role <> 'admin'"
            );
            $upd->execute([':s' => $newStatus, ':id' => $riderDbId]);
            $_SESSION['admin_flash'] = ['ok', 'Rider marked as ' . $newStatus . '.'];
        } else {
            $_SESSION['admin_flash'] = ['err', 'Invalid request.'];
        }

        $back = 'admin.php' . ($backQ !== '' ? '?q=' . urlencode($backQ) : '') . '#rider-' . $riderDbId;
        header('Location: ' . $back);
        exit;
    }

    /* One-time message after a status change */
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);

    /* Total riders */
    $stmt = $pdo->query("SELECT COUNT(*) FROM riders WHERE role <> 'admin'");
    $totalRiders = (int)$stmt->fetchColumn();

    /* Rider status statistics */
    $stmt = $pdo->query(
        "SELECT status, COUNT(*) AS total
           FROM riders
          WHERE role <> 'admin'
       GROUP BY status"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = strtolower((string)$row['status']);
        $count  = (int)$row['total'];
        if ($status === 'verified') { $verifiedRiders = $count; }
        if ($status === 'pending')  { $pendingRiders  = $count; }
        if ($status === 'rejected') { $rejectedRiders = $count; }
    }

    /* Administrator count */
    $stmt = $pdo->query("SELECT COUNT(*) FROM riders WHERE role = 'admin'");
    $totalAdmins = (int)$stmt->fetchColumn();

    /* Education statistics */
    $stmt = $pdo->query(
        "SELECT COUNT(DISTINCT r.id)
           FROM riders r
     INNER JOIN rider_education e ON e.rider_id = r.id
          WHERE r.role <> 'admin'"
    );
    $educationFilled  = (int)$stmt->fetchColumn();
    $educationMissing = max(0, $totalRiders - $educationFilled);

    if ($totalRiders > 0) {
        $educationPercentage = round(($educationFilled / $totalRiders) * 100);
    }

    /* Training statistics */
    $stmt = $pdo->query("SELECT COUNT(*) FROM rider_trainings");
    $trainingTotal = (int)$stmt->fetchColumn();

    /* Monthly rider registrations */
    $stmt = $pdo->query(
        "SELECT
            DATE_FORMAT(created_at, '%Y-%m') AS month_key,
            DATE_FORMAT(created_at, '%b %Y') AS month_name,
            COUNT(*) AS total
           FROM riders
          WHERE role <> 'admin'
            AND created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
       GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b %Y')
       ORDER BY month_key ASC"
    );
    $monthlyRegistrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Chart data */
    $statusData = [
        ['label' => 'Verified', 'value' => $verifiedRiders],
        ['label' => 'Pending',  'value' => $pendingRiders],
        ['label' => 'Rejected', 'value' => $rejectedRiders],
    ];

    $educationData = [
        ['label' => 'Completed',     'value' => $educationFilled],
        ['label' => 'Not completed', 'value' => $educationMissing],
    ];

    /* Recent riders */
    $stmt = $pdo->query(
        "SELECT id, full_name, rider_id, phone, email, status, created_at
           FROM riders
          WHERE role <> 'admin'
       ORDER BY id DESC
          LIMIT 8"
    );
    $recentRiders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Document count for the stat card (own try so a problem here never breaks the page) */
    try {
        $docTotal = (int)$pdo->query("SELECT COUNT(*) FROM rider_documents")->fetchColumn();

    } catch (PDOException $e) {
        error_log('admin.php document list: ' . $e->getMessage());
        $docError = 'Could not read the rider_documents table: ' . $e->getMessage();
    }

    /* Search riders + education */
    $sql = "
        SELECT
            r.id, r.full_name, r.rider_id, r.phone, r.email, r.status, r.created_at,
            e.edu_level, e.course, e.field_of_study, e.school, e.location,
            e.year_done, e.edu_status, e.grade, e.cert_name, e.other_quals, e.updated_at
          FROM riders r
     LEFT JOIN rider_education e ON e.rider_id = r.id
         WHERE r.role <> 'admin'
    ";

    $params = [];

    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $sql .= " AND (
                r.full_name LIKE :q1
                OR r.phone LIKE :q2
                OR r.email LIKE :q3
                OR r.rider_id LIKE :q4
            )";
        $params = [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
    }

    $sql .= " ORDER BY r.id DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Trainings + documents for the riders shown */
    if ($riders) {

        $ids = array_map(static fn(array $r): int => (int)$r['id'], $riders);
        $marks = implode(',', array_fill(0, count($ids), '?'));

        $trainingQuery = $pdo->prepare(
            "SELECT rider_id, training_type, institution, certification, completed_on
               FROM rider_trainings
              WHERE rider_id IN ($marks)
           ORDER BY id ASC"
        );
        $trainingQuery->execute($ids);

        foreach ($trainingQuery->fetchAll(PDO::FETCH_ASSOC) as $training) {
            $trainingsBy[(int)$training['rider_id']][] = $training;
        }

        /* Uploaded documents (own try so the page still works if the table is missing) */
        try {
            $docQuery = $pdo->prepare(
                "SELECT id, rider_id, doc_type, title, original_name, mime_type, size_bytes, uploaded_at
                   FROM rider_documents
                  WHERE rider_id IN ($marks)
               ORDER BY doc_type, id"
            );
            $docQuery->execute($ids);

            foreach ($docQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $docsBy[(int)$row['rider_id']][] = $row;
            }
        } catch (PDOException $e) {
            error_log('admin.php documents: ' . $e->getMessage());
            $docError = 'Could not read the rider_documents table: ' . $e->getMessage();
        }
    }

} catch (PDOException $e) {

    error_log('Jowaina Son admin dashboard error: ' . $e->getMessage());

    $error = 'Unable to load dashboard data. Please check your database tables.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Dashboard | Jowaina Son</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

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
            font-family: 'Poppins', Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.5;
            overflow-wrap: break-word;
        }

        a { text-decoration: none; }
        button, input { font-family: inherit; }

        /* ============ SIDEBAR ============ */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: var(--sidebar-width); height: 100vh;
            background: linear-gradient(180deg, #0b0b0d 0%, #121214 100%);
            color: white; z-index: 1000;
            display: flex; flex-direction: column;
            border-right: 1px solid #222;
        }
        .brand { height: 78px; display: flex; align-items: center; gap: 12px; padding: 0 22px; border-bottom: 1px solid #252525; }
        .brand-icon {
            width: 42px; height: 42px; background: var(--orange); border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; font-weight: 800; box-shadow: 0 8px 25px rgba(255,106,0,.25);
        }
        .brand-text strong { display: block; font-size: 15px; letter-spacing: .4px; }
        .brand-text span { display: block; color: #858585; font-size: 10px; margin-top: 1px; }

        .nav { padding: 20px 12px; flex: 1; overflow-y: auto; }
        .nav-title { color: #666; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 0 12px 9px; }
        .nav a {
            display: flex; align-items: center; gap: 12px; padding: 11px 13px;
            color: #a9a9ad; border-radius: 8px; margin-bottom: 4px; font-size: 13px; transition: .2s;
        }
        .nav a i { width: 20px; text-align: center; font-size: 14px; }
        .nav a:hover { background: #1f1f22; color: white; }
        .nav a.active { background: var(--orange); color: white; box-shadow: 0 7px 18px rgba(255,106,0,.22); }

        .sidebar-bottom { padding: 15px; border-top: 1px solid #252525; }
        .admin-mini { display: flex; align-items: center; gap: 10px; padding: 10px; background: #18181b; border-radius: 9px; margin-bottom: 10px; }
        .admin-avatar {
            width: 35px; height: 35px; border-radius: 50%; background: var(--orange);
            display: flex; align-items: center; justify-content: center; color: white; font-size: 13px; font-weight: 700;
        }
        .admin-info { min-width: 0; }
        .admin-info strong { display: block; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .admin-info span { display: block; color: #777; font-size: 9px; }
        .logout { display: flex; justify-content: center; align-items: center; gap: 7px; padding: 9px; border-radius: 7px; color: #aaa; font-size: 12px; background: #18181b; }
        .logout:hover { color: white; background: #252529; }

        /* ============ MAIN ============ */
        .main { margin-left: var(--sidebar-width); min-height: 100vh; }

        .topbar {
            height: 78px; background: white; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 30px; position: sticky; top: 0; z-index: 100;
        }
        .mobile-menu { display: none; border: 0; background: transparent; font-size: 21px; cursor: pointer; color: var(--text); }
        .top-title h1 { font-size: 21px; font-weight: 700; }
        .top-title p { color: var(--muted); font-size: 11px; margin-top: 2px; }
        .top-right { display: flex; align-items: center; gap: 12px; }
        .date-badge { padding: 7px 11px; background: #f7f7f8; border: 1px solid var(--border); border-radius: 7px; color: var(--muted); font-size: 11px; }

        .content { padding: 28px 30px; max-width: 1600px; margin: auto; }

        html { scroll-behavior: smooth; }
        #education, #riders { scroll-margin-top: 96px; }
        details.rider { scroll-margin-top: 96px; }

        /* ============ WELCOME ============ */
        .welcome {
            background: linear-gradient(110deg, #101012 0%, #17171a 55%, #21170f 100%);
            border-radius: 15px; padding: 25px 28px; color: white;
            display: flex; align-items: center; justify-content: space-between; gap: 20px;
            margin-bottom: 22px; overflow: hidden; position: relative;
        }
        .welcome::after {
            content: ''; position: absolute; width: 240px; height: 240px; border-radius: 50%;
            background: var(--orange); opacity: .08; right: -70px; top: -100px;
        }
        .welcome h2 { font-size: 20px; margin-bottom: 4px; }
        .welcome p { color: #a7a7aa; font-size: 11px; }
        .welcome-action { position: relative; z-index: 2; display: flex; gap: 9px; flex-wrap: wrap; }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            padding: 10px 15px; border-radius: 7px; border: 0; font-size: 11px; font-weight: 600; cursor: pointer;
        }
        .btn-orange { background: var(--orange); color: white; }
        .btn-orange:hover { background: var(--orange-dark); }
        .btn-dark { background: #29292c; color: white; border: 1px solid #3a3a3d; }

        /* ============ STAT CARDS ============ */
        .stats-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card {
            background: white; border: 1px solid var(--border); border-radius: 12px; padding: 17px;
            position: relative; overflow: hidden; transition: transform .2s, box-shadow .2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,.06); }
        .stat-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .stat-icon { width: 38px; height: 38px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 15px; }
        .icon-orange { background: var(--orange-light); color: var(--orange); }
        .icon-green  { background: var(--green-bg); color: var(--green); }
        .icon-yellow { background: var(--yellow-bg); color: var(--yellow); }
        .icon-red    { background: var(--red-bg); color: var(--red); }
        .icon-blue   { background: var(--blue-bg); color: var(--blue); }
        .stat-label { color: var(--muted); font-size: 10px; font-weight: 500; }
        .stat-number { font-size: 25px; line-height: 1; font-weight: 800; margin-top: 3px; }
        .stat-sub { color: #9298a2; font-size: 9px; margin-top: 7px; }

        /* ============ CHARTS ============ */
        .charts-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(300px, 1fr); gap: 18px; margin-bottom: 20px; }
        .chart-card { background: white; border: 1px solid var(--border); border-radius: 12px; padding: 18px; min-width: 0; }
        .card-heading { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 17px; }
        .card-heading h3 { font-size: 13px; font-weight: 700; }
        .card-heading p { font-size: 9px; color: var(--muted); margin-top: 2px; }
        .chart-wrap { position: relative; height: 260px; }
        .chart-wrap.small { height: 220px; }

        /* ============ LOWER GRID ============ */
        .lower-grid { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(300px, 1fr); gap: 18px; margin-bottom: 22px; }
        .panel { background: white; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
        .panel-header { padding: 16px 18px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .panel-header h3 { font-size: 13px; }
        .panel-header a { color: var(--orange); font-size: 10px; font-weight: 600; }

        /* ============ RECENT RIDERS ============ */
        .rider-row { padding: 13px 18px; display: grid; grid-template-columns: 38px minmax(0, 1fr) auto; gap: 11px; align-items: center; border-bottom: 1px solid #f0f1f3; }
        .rider-row:last-child { border-bottom: 0; }
        .rider-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, var(--orange), #ff9b50);
            display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 11px;
        }
        .rider-name { font-size: 11px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rider-meta { font-size: 9px; color: var(--muted); margin-top: 2px; }

        .status { display: inline-flex; padding: 4px 8px; border-radius: 20px; font-size: 8px; font-weight: 600; }
        .status-verified { color: var(--green); background: var(--green-bg); }
        .status-pending  { color: var(--yellow); background: var(--yellow-bg); }
        .status-rejected { color: var(--red); background: var(--red-bg); }

        /* ============ QUICK ACTIONS ============ */
        .actions { padding: 15px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .action { padding: 14px 12px; border: 1px solid var(--border); border-radius: 9px; color: var(--text); transition: .2s; background: white; }
        .action:hover { border-color: var(--orange); background: var(--orange-light); transform: translateY(-1px); }
        .action i {
            width: 31px; height: 31px; display: flex; align-items: center; justify-content: center;
            border-radius: 7px; background: var(--orange-light); color: var(--orange); margin-bottom: 8px; font-size: 12px;
        }
        .action strong { display: block; font-size: 10px; }
        .action span { display: block; color: var(--muted); font-size: 8px; margin-top: 2px; }

        /* ============ EDUCATION PROGRESS ============ */
        .education-box { padding: 20px; }
        .progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .progress-header span { font-size: 10px; color: var(--muted); }
        .progress-header strong { font-size: 17px; color: var(--orange); }
        .progress { height: 9px; background: #eee; border-radius: 10px; overflow: hidden; }
        .progress-bar {
            height: 100%; background: linear-gradient(90deg, var(--orange), #ff9a4d);
            border-radius: 10px; width: <?= min(100, (int)$educationPercentage) ?>%;
        }
        .education-info { display: grid; grid-template-columns: 1fr 1fr; margin-top: 13px; gap: 10px; }
        .education-info div { padding: 10px; border-radius: 8px; background: #f8f8f9; }
        .education-info span { display: block; color: var(--muted); font-size: 8px; }
        .education-info strong { display: block; font-size: 14px; margin-top: 2px; }

        /* ============ RIDER SEARCH ============ */
        .search-section { margin-top: 22px; }
        .search-bar { display: flex; gap: 8px; margin-bottom: 15px; flex-wrap: wrap; }
        .search-bar input {
            flex: 1; min-width: 12rem; padding: 11px 13px; border: 1px solid var(--border);
            border-radius: 8px; outline: none; font-size: max(16px, 11px); background: white;
        }
        .search-bar input:focus { border-color: var(--orange); box-shadow: 0 0 0 3px rgba(255,106,0,.08); }
        .search-button { padding: 0 17px; min-height: 42px; border: 0; background: var(--orange); color: white; border-radius: 8px; font-size: 11px; font-weight: 600; cursor: pointer; }
        .clear-button { padding: 10px 13px; border: 1px solid var(--border); color: var(--muted); background: white; border-radius: 8px; font-size: 11px; display: inline-flex; align-items: center; }

        /* ============ RIDER DETAILS ============ */
        details.rider { background: white; border: 1px solid var(--border); border-radius: 10px; margin-bottom: 8px; overflow: hidden; }
        details.rider[open] { border-color: var(--orange); }
        summary { list-style: none; cursor: pointer; padding: 13px 15px; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 3px 10px; align-items: center; }
        summary::-webkit-details-marker { display: none; }
        .summary-name { font-size: 11px; font-weight: 600; }
        .summary-meta { color: var(--muted); font-size: 9px; grid-column: 1; }
        .summary-tags { grid-column: 2; grid-row: 1 / span 2; display: flex; gap: 5px; flex-wrap: wrap; justify-content: flex-end; }
        .tag { font-size: 8px; font-weight: 600; padding: 4px 8px; border-radius: 20px; background: #f1f3f5; color: var(--muted); white-space: nowrap; }
        .tag.ok   { color: var(--green); background: var(--green-bg); }
        .tag.wait { color: var(--yellow); background: var(--yellow-bg); }
        .tag.bad  { color: var(--red); background: var(--red-bg); }
        .rider-body { padding: 17px; border-top: 1px solid var(--border); }

        .section-title { font-size: 11px; margin: 18px 0 9px; font-weight: 700; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 600px; font-size: 9px; }
        th { text-align: left; padding: 9px; color: var(--muted); background: #f8f8f9; font-size: 8px; }
        td { padding: 9px; border-bottom: 1px solid #eee; }
        .muted { color: var(--muted); font-size: 9px; }

        /* ============ DOCUMENTS ============ */
        .docs { display: grid; grid-template-columns: repeat(auto-fill, minmax(8.5rem, 1fr)); gap: 12px; }
        .docbox { display: flex; flex-direction: column; gap: 4px; color: inherit; border: 1px solid var(--border); border-radius: 9px; padding: 8px; background: #fff; min-width: 0; }
        .docbox:hover { border-color: var(--orange); }
        .dthumb { height: 96px; border-radius: 6px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; overflow: hidden; color: #dc2626; font-size: 14px; }
        .dthumb img { width: 100%; height: 100%; object-fit: cover; }
        .dkind { font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--orange); }
        .dname { font-size: 10px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* ============ COPY DETAILS ============ */
        .copy-box { display: flex; justify-content: flex-end; align-items: center; gap: 8px; margin-bottom: 10px; }
        .copy-icon { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); border-radius: 8px; background: #fff; color: var(--muted); font-size: 13px; cursor: pointer; transition: .2s; }
        .copy-icon:hover { border-color: var(--orange); color: var(--orange); background: var(--orange-light); }
        .copy-msg { font-size: 10px; font-weight: 600; color: var(--green); }

        /* ============ VERIFICATION BUTTONS ============ */
        .verification-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .verification-actions form { margin: 0; }
        .verification-actions .btn[disabled] { opacity: .45; cursor: default; }
        .btn-success { background: #16834b !important; color: #fff !important; border-color: #16834b !important; }
        .btn-danger  { background: #c93636 !important; color: #fff !important; border-color: #c93636 !important; }
        .btn-warn    { background: #d97706 !important; color: #fff !important; }

        /* ============ ERROR / EMPTY / FLASH ============ */
        .error { padding: 15px; border-radius: 10px; background: var(--red-bg); border: 1px solid #f5bebe; color: #991b1b; font-size: 11px; margin-bottom: 18px; }
        .flash-ok { padding: 15px; border-radius: 10px; background: var(--green-bg); border: 1px solid #b7e4c7; color: #166534; font-size: 11px; margin-bottom: 18px; }
        .empty { padding: 35px 20px; text-align: center; background: white; border: 1px dashed var(--border); border-radius: 10px; color: var(--muted); font-size: 11px; }
        .empty i { display: block; font-size: 30px; margin-bottom: 10px; color: #c5c8ce; }

        /* ============ SHARED COMPONENTS (other admin pages) ============ */
        .page-heading { display: flex; justify-content: space-between; align-items: center; gap: 18px; margin-bottom: 22px; }
        .page-heading h2 { margin: 0; font-size: 24px; }
        .page-heading p { margin: 6px 0 0; color: var(--muted); font-size: 13px; }
        .form-label { display: block; margin-bottom: 7px; font-size: 12px; font-weight: 700; color: var(--text); }
        .search-input { width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; background: #fff; color: var(--text); outline: none; }
        .search-input:focus { border-color: var(--orange); box-shadow: 0 0 0 3px rgba(255,106,0,.10); }
        .back-link { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 18px; color: var(--orange); font-weight: 700; text-decoration: none; }
        .details-card { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 22px; margin-bottom: 20px; box-shadow: 0 5px 18px rgba(0,0,0,.04); }
        .details-card h3 { margin: 0 0 18px; font-size: 17px; }
        .details-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
        .details-grid > div { padding: 13px; border: 1px solid var(--border); border-radius: 10px; background: #fafafa; min-width: 0; font-size: 11px; font-weight: 500; }
        .details-grid label { display: block; color: var(--muted); font-size: 9px; font-weight: 700; text-transform: uppercase; margin-bottom: 6px; }
        .status-pill { display: inline-flex; align-items: center; padding: 7px 12px; border-radius: 999px; font-size: 12px; font-weight: 800; text-transform: capitalize; }

        /* ============ MOBILE ============ */
        .overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 999; }

        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .charts-grid { grid-template-columns: 1fr; }
            .lower-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 900px) {
            .details-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .page-heading { align-items: flex-start; flex-direction: column; }
        }

        @media (max-width: 850px) {
            :root { --sidebar-width: 0px; }
            .sidebar { transform: translateX(-260px); transition: transform .25s ease; width: 255px; }
            .sidebar.open { transform: translateX(0); }
            .overlay.open { display: block; }
            .main { margin-left: 0; }
            .mobile-menu { display: block; }
            .topbar { padding: 0 16px; }
            .content { padding: 18px 15px; }
            .welcome { padding: 20px; flex-direction: column; align-items: flex-start; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .date-badge { display: none; }
        }

        @media (max-width: 600px) {
            .details-grid { grid-template-columns: 1fr; }
            .verification-actions { flex-direction: column; }
            .verification-actions form, .verification-actions .btn, .verification-actions button { width: 100%; }
            .page-heading h2 { font-size: 20px; }
        }

        @media (max-width: 500px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 9px; }
            .stat-card { padding: 13px; }
            .stat-number { font-size: 21px; }
            .top-title h1 { font-size: 17px; }
            .welcome h2 { font-size: 17px; }
            .welcome-action { width: 100%; }
            .welcome-action .btn { flex: 1; }
            .chart-wrap { height: 220px; }
            .actions { grid-template-columns: 1fr; }
            .summary-tags { grid-column: 1; grid-row: 3; justify-content: flex-start; margin-top: 5px; }
            summary { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- ============================ SIDEBAR ============================ -->
<aside class="sidebar" id="sidebar">

    <div class="brand">
        <div class="brand-icon">JS</div>
        <div class="brand-text">
            <strong>JOWAINA SON</strong>
            <span>ADMINISTRATION PANEL</span>
        </div>
    </div>

    <nav class="nav">

        <div class="nav-title">Main menu</div>

        <a href="admin.php" class="active">
            <i class="fa-solid fa-chart-pie"></i> Dashboard
        </a>

        <a href="administrators.php">
            <i class="fa-solid fa-user-shield"></i> Administrators
        </a>

        <a href="add-admin.php">
            <i class="fa-solid fa-user-plus"></i> Add Administrator
        </a>

        <div class="nav-title" style="margin-top:22px;">Management</div>

        <a href="admin.php#riders">
            <i class="fa-solid fa-motorcycle"></i> Riders
        </a>

        <a href="admin.php#education">
            <i class="fa-solid fa-graduation-cap"></i> Education
        </a>

        <a href="admin.php#riders">
            <i class="fa-solid fa-certificate"></i> Training
        </a>

        <a href="admin.php#riders">
            <i class="fa-solid fa-file-lines"></i> Documents
        </a>

        <div class="nav-title" style="margin-top:22px;">Account</div>

        <a href="admin-profile.php">
            <i class="fa-solid fa-user"></i> My Profile
        </a>

    </nav>

    <div class="sidebar-bottom">

        <div class="admin-mini">
            <div class="admin-avatar"><?= h(strtoupper(substr($adminName, 0, 1))) ?></div>
            <div class="admin-info">
                <strong><?= h($adminName) ?></strong>
                <span>Administrator</span>
            </div>
        </div>

        <a class="logout" href="logout.php">
            <i class="fa-solid fa-right-from-bracket"></i> Sign out
        </a>

    </div>

</aside>

<!-- ============================== MAIN ============================== -->
<div class="main">

    <header class="topbar">

        <div style="display:flex;align-items:center;gap:12px;">

            <button class="mobile-menu" onclick="openSidebar()" aria-label="Open menu">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="top-title">
                <h1>Dashboard</h1>
                <p>Jowaina Son Boda Boda Community</p>
            </div>

        </div>

        <div class="top-right">
            <div class="date-badge">
                <i class="fa-regular fa-calendar"></i>
                <?= date('l, d M Y') ?>
            </div>
        </div>

    </header>

    <main class="content">

        <?php if (!empty($flash)): ?>
            <div class="<?= $flash[0] === 'ok' ? 'flash-ok' : 'error' ?>" role="status">
                <?= h($flash[1]) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <!-- WELCOME -->
        <section class="welcome">

            <div>
                <h2>Welcome back, <?= h($adminName) ?> 👋</h2>
                <p>Here's what's happening with the Jowaina Son rider community today.</p>
            </div>

            <div class="welcome-action">
                <a href="add-admin.php" class="btn btn-orange">
                    <i class="fa-solid fa-user-plus"></i> Add Administrator
                </a>
                <a href="admin.php#riders" class="btn btn-dark">
                    <i class="fa-solid fa-motorcycle"></i> View Riders
                </a>
            </div>

        </section>

        <!-- STATISTICS -->
        <section class="stats-grid">

            <div class="stat-card">
                <div class="stat-top">
                    <div>
                        <span class="stat-label">Total Riders</span>
                        <div class="stat-number"><?= number_format($totalRiders) ?></div>
                    </div>
                    <div class="stat-icon icon-orange"><i class="fa-solid fa-motorcycle"></i></div>
                </div>
                <div class="stat-sub">Registered community riders</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div>
                        <span class="stat-label">Verified</span>
                        <div class="stat-number"><?= number_format($verifiedRiders) ?></div>
                    </div>
                    <div class="stat-icon icon-green"><i class="fa-solid fa-circle-check"></i></div>
                </div>
                <div class="stat-sub">Approved rider accounts</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div>
                        <span class="stat-label">Pending</span>
                        <div class="stat-number"><?= number_format($pendingRiders) ?></div>
                    </div>
                    <div class="stat-icon icon-yellow"><i class="fa-solid fa-clock"></i></div>
                </div>
                <div class="stat-sub">Waiting for verification</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div>
                        <span class="stat-label">Education</span>
                        <div class="stat-number"><?= $educationPercentage ?>%</div>
                    </div>
                    <div class="stat-icon icon-blue"><i class="fa-solid fa-graduation-cap"></i></div>
                </div>
                <div class="stat-sub">Riders with education details</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div>
                        <span class="stat-label">Training Records</span>
                        <div class="stat-number"><?= number_format($trainingTotal) ?></div>
                    </div>
                    <div class="stat-icon icon-orange"><i class="fa-solid fa-certificate"></i></div>
                </div>
                <div class="stat-sub">Professional training records</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div>
                        <span class="stat-label">Documents</span>
                        <div class="stat-number"><?= number_format($docTotal) ?></div>
                    </div>
                    <div class="stat-icon icon-blue"><i class="fa-solid fa-file-lines"></i></div>
                </div>
                <div class="stat-sub">Files uploaded by riders</div>
            </div>

        </section>

        <!-- CHARTS -->
        <section class="charts-grid">

            <div class="chart-card">
                <div class="card-heading">
                    <div>
                        <h3>Rider Registration Trend</h3>
                        <p>New riders registered over the last 12 months</p>
                    </div>
                </div>
                <div class="chart-wrap">
                    <canvas id="registrationChart"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <div class="card-heading">
                    <div>
                        <h3>Rider Status</h3>
                        <p>Current registration status</p>
                    </div>
                </div>
                <div class="chart-wrap small">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>

        </section>

        <!-- LOWER DASHBOARD -->
        <section class="lower-grid">

            <div class="panel">

                <div class="panel-header">
                    <h3>
                        <i class="fa-solid fa-users" style="color:var(--orange);margin-right:7px;"></i>
                        Recent Riders
                    </h3>
                    <a href="admin.php#riders">View all</a>
                </div>

                <?php if ($recentRiders): ?>

                    <?php foreach ($recentRiders as $rider):
                        $initial = strtoupper(substr((string)$rider['full_name'], 0, 1));
                        $status = strtolower((string)$rider['status']);
                        $statusClass = 'status-' . $status;
                    ?>
                        <div class="rider-row">
                            <div class="rider-avatar"><?= h($initial) ?></div>
                            <div>
                                <div class="rider-name"><?= h($rider['full_name']) ?></div>
                                <div class="rider-meta">
                                    <?= h($rider['rider_id']) ?> &nbsp;&bull;&nbsp; <?= h($rider['phone']) ?>
                                </div>
                            </div>
                            <span class="status <?= h($statusClass) ?>"><?= h(ucfirst($status)) ?></span>
                        </div>
                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty">
                        <i class="fa-solid fa-users"></i>
                        No riders registered yet.
                    </div>

                <?php endif; ?>

            </div>

            <div class="panel">

                <div class="panel-header">
                    <h3>Quick Actions</h3>
                </div>

                <div class="actions">

                    <a href="add-admin.php" class="action">
                        <i class="fa-solid fa-user-plus"></i>
                        <strong>Add Administrator</strong>
                        <span>Create staff account</span>
                    </a>

                    <a href="administrators.php" class="action">
                        <i class="fa-solid fa-user-shield"></i>
                        <strong>Administrators</strong>
                        <span>Manage administrators</span>
                    </a>

                    <a href="admin.php#riders" class="action">
                        <i class="fa-solid fa-motorcycle"></i>
                        <strong>Find Rider</strong>
                        <span>Search rider records</span>
                    </a>

                    <a href="admin.php#education" class="action">
                        <i class="fa-solid fa-graduation-cap"></i>
                        <strong>Education</strong>
                        <span>Review education data</span>
                    </a>

                </div>

            </div>

        </section>

        <!-- EDUCATION ANALYTICS -->
        <section class="charts-grid" id="education">

            <div class="chart-card">
                <div class="card-heading">
                    <div>
                        <h3>Education Completion</h3>
                        <p>Rider profile education completion</p>
                    </div>
                </div>
                <div class="chart-wrap small">
                    <canvas id="educationChart"></canvas>
                </div>
            </div>

            <div class="panel">

                <div class="panel-header">
                    <h3>Education Progress</h3>
                </div>

                <div class="education-box">

                    <div class="progress-header">
                        <span>Overall completion</span>
                        <strong><?= $educationPercentage ?>%</strong>
                    </div>

                    <div class="progress">
                        <div class="progress-bar"></div>
                    </div>

                    <div class="education-info">
                        <div>
                            <span>Completed</span>
                            <strong><?= number_format($educationFilled) ?></strong>
                        </div>
                        <div>
                            <span>Missing</span>
                            <strong><?= number_format($educationMissing) ?></strong>
                        </div>
                    </div>

                    <div style="margin-top:18px;color:var(--muted);font-size:9px;line-height:1.7;">
                        <i class="fa-solid fa-lightbulb" style="color:var(--orange);"></i>
                        Riders who have not completed their education information can be identified
                        from the rider records below.
                    </div>

                </div>

            </div>

        </section>

        <!-- ===================== EDUCATION RECORDS ===================== -->
        <section class="search-section" id="education-records" style="margin-bottom:28px;">

            <div class="card-heading">
                <div>
                    <h3>Education Records</h3>
                    <p>Education details submitted by riders</p>
                </div>
            </div>

            <form class="search-bar" method="get" action="admin.php#education-records">
                <input
                    type="search"
                    name="q"
                    value="<?= h($q) ?>"
                    placeholder="Search by name, phone, email or Rider ID"
                    maxlength="100"
                >
                <button type="submit" class="search-button">Search</button>
                <?php if ($q !== ''): ?>
                    <a class="clear-button" href="admin.php#education-records">Clear</a>
                <?php endif; ?>
            </form>

            <?php
            $eduRows = array_values(array_filter(
                $riders,
                static fn(array $r): bool => $r['edu_level'] !== null
            ));
            ?>

            <div class="panel">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Rider</th>
                                <th>Education level</th>
                                <th>Course / Field</th>
                                <th>School</th>
                                <th>Year</th>
                                <th>Status</th>
                                <th>Grade</th>
                                <th>Certificate</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($eduRows as $er): ?>
                            <tr>
                                <td>
                                    <strong><?= h($er['full_name']) ?></strong><br>
                                    <span class="muted"><?= h($er['rider_id'] ?: 'No rider ID') ?></span>
                                </td>
                                <td><?= h($er['edu_level']) ?></td>
                                <td>
                                    <?= h($er['course']) ?><br>
                                    <span class="muted"><?= h($er['field_of_study']) ?></span>
                                </td>
                                <td>
                                    <?= h($er['school']) ?><br>
                                    <span class="muted"><?= h($er['location']) ?></span>
                                </td>
                                <td><?= h($er['year_done']) ?></td>
                                <td><?= h($er['edu_status']) ?></td>
                                <td><?= h($er['grade']) ?></td>
                                <td><?= ($er['cert_name'] ?? '') !== '' ? h($er['cert_name']) : '-' ?></td>
                                <td><?= h($er['updated_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$eduRows): ?>
                            <tr>
                                <td colspan="9" class="muted" style="padding:20px;text-align:center;">
                                    <?= $q !== '' ? 'No education records match your search.' : 'No education records have been submitted yet.' ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </section>

        <!-- ===================== RIDER DETAILS ===================== -->
        <section class="search-section" id="riders">

            <div class="card-heading">
                <div>
                    <h3>Rider Details</h3>
                    <p>Verification, education, training and uploaded documents</p>
                </div>
            </div>

            <form class="search-bar" method="get" action="admin.php#riders">
                <input
                    type="search"
                    name="q"
                    value="<?= h($q) ?>"
                    placeholder="Search by name, phone, email or Rider ID"
                    maxlength="100"
                >
                <button type="submit" class="search-button">Search</button>
                <?php if ($q !== ''): ?>
                    <a class="clear-button" href="admin.php#riders">Clear</a>
                <?php endif; ?>
            </form>

            <?php if ($docError): ?>
                <div class="error">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <?= h($docError) ?>
                </div>
            <?php endif; ?>

            <?php if (!$riders): ?>
                <div class="empty">
                    <i class="fa-solid fa-users"></i>
                    <?= $q !== '' ? 'No riders match your search.' : 'No riders have registered yet.' ?>
                </div>
            <?php endif; ?>

            <?php foreach ($riders as $r):
                $hasEdu    = $r['edu_level'] !== null;
                $myDocs    = $docsBy[(int)$r['id']] ?? [];
                $trainings = $trainingsBy[(int)$r['id']] ?? [];
                $rStatus   = strtolower((string)$r['status']);
                $verified  = in_array($rStatus, ['verified', 'approved'], true);
                $tagClass  = $verified ? 'ok' : ($rStatus === 'rejected' ? 'bad' : 'wait');

                /* Plain text of this rider's details, with titles, for copy and paste */
                $baseLines = [
                    'Name: '     . oneLine($r['full_name']),
                    'Rider ID: ' . oneLine($r['rider_id']),
                    'Phone: '    . oneLine($r['phone']),
                    'Email: '    . oneLine($r['email']),
                    'Status: '   . ucfirst($rStatus),
                ];

                $fullLines = array_merge(['RIDER DETAILS'], $baseLines);

                if ($hasEdu) {
                    $fullLines[] = '';
                    $fullLines[] = 'EDUCATION';
                    $fullLines[] = 'Highest Level: '          . oneLine($r['edu_level']);
                    $fullLines[] = 'Course / Programme: '     . oneLine($r['course']);
                    $fullLines[] = 'Field of Study: '         . oneLine($r['field_of_study']);
                    $fullLines[] = 'School / Institution: '   . oneLine($r['school']);
                    $fullLines[] = 'Location: '               . oneLine($r['location']);
                    $fullLines[] = 'Year Completed: '         . oneLine($r['year_done']);
                    $fullLines[] = 'Education Status: '       . oneLine($r['edu_status']);
                    $fullLines[] = 'Grade: '                  . oneLine($r['grade']);
                    $fullLines[] = 'Certificate / Award: '    . (oneLine($r['cert_name'] ?? '') !== '' ? oneLine($r['cert_name']) : '-');
                    $fullLines[] = 'Other Qualifications: '   . (oneLine($r['other_quals'] ?? '') !== '' ? oneLine($r['other_quals']) : '-');

                    if ($trainings) {
                        $fullLines[] = '';
                        $fullLines[] = 'TRAINING';
                        foreach ($trainings as $ti => $tr) {
                            $fullLines[] = 'Training ' . ($ti + 1) . ': '
                                . oneLine($tr['training_type'])
                                . ' | Institution: ' . oneLine($tr['institution'])
                                . ' | Certification: ' . oneLine($tr['certification'])
                                . ' | Completed: ' . oneLine($tr['completed_on']);
                        }
                    }
                } else {
                    $fullLines[] = 'Education: Not filled in yet';
                }

                $fullText  = implode("\n", $fullLines);
            ?>
            <details class="rider" id="rider-<?= (int)$r['id'] ?>">
                <summary>
                    <span class="summary-name"><?= h($r['full_name']) ?></span>
                    <span class="summary-meta">
                        <?= h($r['rider_id']) ?> &middot; <?= h($r['phone']) ?> &middot; <?= h($r['email']) ?>
                    </span>
                    <span class="summary-tags">
                        <span class="tag <?= h($tagClass) ?>"><?= h(ucfirst($rStatus)) ?></span>
                        <span class="tag <?= $hasEdu ? 'ok' : '' ?>"><?= $hasEdu ? h($r['edu_level']) : 'No education yet' ?></span>
                        <span class="tag <?= $myDocs ? 'ok' : '' ?>">
                            <?= count($myDocs) ?> <?= count($myDocs) === 1 ? 'document' : 'documents' ?>
                        </span>
                    </span>
                </summary>

                <div class="rider-body">

                    <h4 class="section-title" style="margin-top:0">Verification</h4>
                    <div class="verification-actions" style="margin-bottom:18px;">
                        <?php foreach ([
                            'verified' => ['Accept',  'btn-success', 'fa-circle-check'],
                            'pending'  => ['Pending', 'btn-warn',    'fa-clock'],
                            'rejected' => ['Reject',  'btn-danger',  'fa-circle-xmark'],
                        ] as $val => [$label, $cls, $icon]):
                            $isCurrent = ($rStatus === $val) || ($val === 'verified' && $rStatus === 'approved');
                        ?>
                            <form method="post" action="admin.php">
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="rider_db_id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="q" value="<?= h($q) ?>">
                                <input type="hidden" name="status" value="<?= h($val) ?>">
                                <button type="submit" class="btn <?= h($cls) ?>" <?= $isCurrent ? 'disabled' : '' ?>>
                                    <i class="fa-solid <?= h($icon) ?>"></i> <?= h($label) ?>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>

                    <div class="copy-box">
                        <span class="copy-msg" role="status"></span>
                        <button
                            type="button"
                            class="copy-icon"
                            data-copy-target="copy-full-<?= (int)$r['id'] ?>"
                            title="Copy details"
                            aria-label="Copy details of <?= h($r['full_name']) ?>"
                        >
                            <i class="fa-regular fa-copy"></i>
                        </button>
                        <textarea id="copy-full-<?= (int)$r['id'] ?>" hidden readonly><?= h($fullText) ?></textarea>
                    </div>

                    <?php if (!$hasEdu): ?>
                        <p class="muted">This rider has not filled in the education form yet.</p>
                    <?php else: ?>
                        <div class="details-grid">
                            <div><label>Highest level</label><?= h($r['edu_level']) ?></div>
                            <div><label>Course / programme</label><?= h($r['course']) ?></div>
                            <div><label>Field of study</label><?= h($r['field_of_study']) ?></div>
                            <div><label>School / institution</label><?= h($r['school']) ?></div>
                            <div><label>Location</label><?= h($r['location']) ?></div>
                            <div><label>Year completed</label><?= h($r['year_done']) ?></div>
                            <div><label>Status</label><?= h($r['edu_status']) ?></div>
                            <div><label>Grade</label><?= h($r['grade']) ?></div>
                            <div><label>Certificate / award</label><?= ($r['cert_name'] ?? '') !== '' ? h($r['cert_name']) : '-' ?></div>
                            <div><label>Other qualifications</label><?= ($r['other_quals'] ?? '') !== '' ? h($r['other_quals']) : '-' ?></div>
                        </div>

                        <h4 class="section-title">Professional &amp; vocational training (<?= count($trainings) ?>)</h4>
                        <?php if ($trainings): ?>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Training</th>
                                            <th>Institution</th>
                                            <th>Certification</th>
                                            <th>Completed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($trainings as $t): ?>
                                        <tr>
                                            <td><?= h($t['training_type']) ?></td>
                                            <td><?= h($t['institution']) ?></td>
                                            <td><?= h($t['certification']) ?></td>
                                            <td><?= h($t['completed_on']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="muted">No trainings recorded.</p>
                        <?php endif; ?>

                        <p class="muted" style="margin-top:10px">Last updated: <?= h($r['updated_at']) ?></p>
                    <?php endif; ?>

                    <h4 class="section-title">Documents (<?= count($myDocs) ?>)</h4>
                    <?php if ($myDocs): ?>
                        <div class="docs">
                        <?php foreach ($myDocs as $dc):
                            $isPdf = $dc['mime_type'] === 'application/pdf';
                            $kind  = [
                                'photo'       => 'Profile photo',
                                'id_card'     => 'ID card',
                                'certificate' => 'Certificate',
                            ][$dc['doc_type']] ?? $dc['doc_type'];
                        ?>
                            <a class="docbox" href="file.php?id=<?= (int)$dc['id'] ?>" target="_blank" rel="noopener">
                                <span class="dthumb">
                                    <?php if ($isPdf): ?>
                                        <b>PDF</b>
                                    <?php else: ?>
                                        <img src="file.php?id=<?= (int)$dc['id'] ?>" alt="" loading="lazy">
                                    <?php endif; ?>
                                </span>
                                <span class="dkind"><?= h($kind) ?></span>
                                <span class="dname"><?= h($dc['title'] !== '' ? $dc['title'] : $dc['original_name']) ?></span>
                            </a>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="muted">This rider has not uploaded any documents yet.</p>
                    <?php endif; ?>

                </div>
            </details>
            <?php endforeach; ?>

            <?php if (count($riders) === 200): ?>
                <p class="muted">Showing the 200 newest riders. Use the search box to find others.</p>
            <?php endif; ?>

        </section>

    </main>
</div>

<script>
    /* ---------------- Chart data from PHP ---------------- */

    const monthlyLabels = <?= json_encode(array_column($monthlyRegistrations, 'month_name'), JSON_UNESCAPED_UNICODE) ?>;
    const monthlyValues = <?= json_encode(array_map('intval', array_column($monthlyRegistrations, 'total'))) ?>;

    const statusLabels = <?= json_encode(array_column($statusData, 'label'), JSON_UNESCAPED_UNICODE) ?>;
    const statusValues = <?= json_encode(array_map('intval', array_column($statusData, 'value'))) ?>;

    const educationLabels = <?= json_encode(array_column($educationData, 'label'), JSON_UNESCAPED_UNICODE) ?>;
    const educationValues = <?= json_encode(array_map('intval', array_column($educationData, 'value'))) ?>;

    Chart.defaults.font.family = "'Poppins', Arial, sans-serif";
    Chart.defaults.font.size = 10;
    Chart.defaults.color = '#747b87';

    /* ---------------- Registration chart ---------------- */
    const registrationCanvas = document.getElementById('registrationChart');

    if (registrationCanvas) {
        new Chart(registrationCanvas, {
            type: 'line',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'New riders',
                    data: monthlyValues,
                    borderColor: '#ff6a00',
                    backgroundColor: 'rgba(255,106,0,.10)',
                    borderWidth: 3,
                    pointBackgroundColor: '#ff6a00',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true,
                    tension: .4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#111114',
                        padding: 10,
                        titleFont: { size: 10 },
                        bodyFont: { size: 10 },
                        displayColors: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, font: { size: 9 } },
                        grid: { color: '#eeeeef' }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 9 } }
                    }
                }
            }
        });
    }

    /* ---------------- Status chart ---------------- */
    const statusCanvas = document.getElementById('statusChart');

    if (statusCanvas) {
        new Chart(statusCanvas, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusValues,
                    backgroundColor: ['#16a34a', '#f59e0b', '#dc2626'],
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, pointStyle: 'circle', padding: 15, font: { size: 9 } }
                    },
                    tooltip: { backgroundColor: '#111114', padding: 10 }
                }
            }
        });
    }

    /* ---------------- Education chart ---------------- */
    const educationCanvas = document.getElementById('educationChart');

    if (educationCanvas) {
        new Chart(educationCanvas, {
            type: 'doughnut',
            data: {
                labels: educationLabels,
                datasets: [{
                    data: educationValues,
                    backgroundColor: ['#ff6a00', '#e5e7eb'],
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, pointStyle: 'circle', padding: 15, font: { size: 9 } }
                    },
                    tooltip: { backgroundColor: '#111114', padding: 10 }
                }
            }
        });
    }
</script>

<script>
    function openSidebar() {
        document.getElementById('sidebar').classList.add('open');
        document.getElementById('overlay').classList.add('open');
    }

    function closeSidebar() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('overlay').classList.remove('open');
    }

    /* Copy a rider's details to the clipboard */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-copy-target]');
        if (!btn) return;

        var source = document.getElementById(btn.getAttribute('data-copy-target'));
        if (!source) return;

        var text = source.value;
        var msg = btn.closest('.copy-box').querySelector('.copy-msg');

        function done(ok) {
            msg.style.color = ok ? '' : '#b32f2f';
            msg.textContent = ok ? 'Copied' : 'Could not copy';
            setTimeout(function () { msg.textContent = ''; }, 3500);
        }

        function fallback() {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
            document.body.removeChild(ta);
            done(ok);
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () { done(true); }, fallback);
        } else {
            fallback();
        }
    });

    /* Close the mobile menu after tapping a link */
    document.querySelectorAll('.nav a').forEach(function (link) {
        link.addEventListener('click', closeSidebar);
    });

    /* After changing a rider's status, reopen that rider and scroll to it */
    if (location.hash.indexOf('#rider-') === 0) {
        var openRider = document.querySelector(location.hash);
        if (openRider && openRider.tagName === 'DETAILS') {
            openRider.open = true;
            openRider.scrollIntoView({ block: 'center' });
        }
    }
</script>

</body>
</html>