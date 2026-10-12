<?php
declare(strict_types=1);

require __DIR__ . '/admin-session.php';
require __DIR__ . '/config.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php');
    exit;
}

$riderId = filter_input(INPUT_POST, 'rider_id', FILTER_VALIDATE_INT);
$action  = trim((string)($_POST['action'] ?? ''));

if (!$riderId || $riderId <= 0) {
    header('Location: admin.php?error=invalid_rider');
    exit;
}

$allowedActions = [
    'verify' => 'verified',
    'reject' => 'rejected',
    'pending' => 'pending'
];

if (!isset($allowedActions[$action])) {
    header('Location: admin.php?error=invalid_action');
    exit;
}

$newStatus = $allowedActions[$action];

try {
    $pdo = db();

    /*
     * Make sure the selected account is actually a rider.
     * Administrators cannot be changed using this page.
     */
    $check = $pdo->prepare(
        "SELECT id, full_name, status
         FROM riders
         WHERE id = :id
           AND role = 'rider'
         LIMIT 1"
    );

    $check->execute([
        ':id' => $riderId
    ]);

    $rider = $check->fetch(PDO::FETCH_ASSOC);

    if (!$rider) {
        header('Location: admin.php?error=rider_not_found');
        exit;
    }

    /*
     * Update rider status.
     */
    $stmt = $pdo->prepare(
        "UPDATE riders
         SET status = :status
         WHERE id = :id
           AND role = 'rider'
         LIMIT 1"
    );

    $stmt->execute([
        ':status' => $newStatus,
        ':id'     => $riderId
    ]);

    /*
     * Redirect back to dashboard.
     */
    header(
        'Location: admin.php?status_updated=' .
        urlencode($newStatus)
    );
    exit;

} catch (PDOException $e) {

    error_log('rider-status.php: ' . $e->getMessage());

    header('Location: admin.php?error=database');
    exit;
}