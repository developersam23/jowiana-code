<?php
declare(strict_types=1);

/**
 * me.php - returns the logged-in rider as JSON for the dashboard and other pages.
 *
 *  - Uses startRiderSession() from session.php, the SAME session login.php
 *    creates (plain session_start() can read a different session).
 *  - Returns the rider's details both nested ("rider") and flat
 *    (full_name, rider_id, ...) together with completion, stats and documents.
 *  - Never prints PHP warnings into the JSON.
 *
 * Profile completion:  20% account  +60% education saved  +20% photo and ID card uploaded
 */

ini_set('display_errors', '0');          // errors go to the log, not into the JSON
ob_start();                              // swallow any stray output (spaces, notices)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function send(int $code, array $payload): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($code);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    require_once __DIR__ . '/session.php';
    require_once __DIR__ . '/config.php';

    if (session_status() !== PHP_SESSION_ACTIVE) {
        startRiderSession();
    }

    if (empty($_SESSION['rider_db_id'])) {
        send(401, [
            'success' => false,
            'message' => 'Not logged in. Please log in again.'
        ]);
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $id  = (int) $_SESSION['rider_db_id'];
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT full_name, rider_id, phone, email, status, role
         FROM riders
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rider) {
        $_SESSION = [];
        session_destroy();

        send(401, [
            'success' => false,
            'message' => 'Rider account not found. Please log in again.'
        ]);
    }

    /* Optional tables: the dashboard still works if they do not exist yet. */
    $educationDone = false;
    $trainingCount = 0;

    try {
        $q = $pdo->prepare('SELECT 1 FROM rider_education WHERE rider_id = :id LIMIT 1');
        $q->execute([':id' => $id]);
        $educationDone = (bool) $q->fetchColumn();

        $q = $pdo->prepare('SELECT COUNT(*) FROM rider_trainings WHERE rider_id = :id');
        $q->execute([':id' => $id]);
        $trainingCount = (int) $q->fetchColumn();
    } catch (PDOException $e) {
        error_log('me.php education tables: ' . $e->getMessage());
    }

    $documents = [];
    $photoUrl  = null;
    $hasPhoto  = false;
    $hasIdCard = false;

    try {
        $q = $pdo->prepare(
            'SELECT id, doc_type, title, original_name, uploaded_at
               FROM rider_documents
              WHERE rider_id = :id
              ORDER BY uploaded_at DESC, id DESC'
        );
        $q->execute([':id' => $id]);
        $documents = $q->fetchAll(PDO::FETCH_ASSOC);

        foreach ($documents as $d) {
            if ($d['doc_type'] === 'photo' && !$hasPhoto) {
                $hasPhoto = true;
                $photoUrl = 'file.php?id=' . (int) $d['id'];
            }
            if ($d['doc_type'] === 'id_card') {
                $hasIdCard = true;
            }
        }
    } catch (PDOException $e) {
        error_log('me.php documents table: ' . $e->getMessage());
    }

    $documentsDone = $hasPhoto && $hasIdCard;
    $base          = 20 + ($documentsDone ? 20 : 0);          // what education.js starts from
    $completion    = $base + ($educationDone ? 60 : 0);

    send(200, array_merge($rider, [
        'success'         => true,
        'rider'           => $rider,                         // nested copy
        'csrf_token'      => $_SESSION['csrf_token'],
        'base_completion' => $base,
        'education_done'  => $educationDone,
        'documents_done'  => $documentsDone,
        'completion'      => $completion,
        'photo_url'       => $photoUrl,
        'documents'       => array_slice($documents, 0, 6),   // newest few for the dashboard list
        'stats'           => [
            'documents'     => count($documents),
            'trainings'     => $trainingCount,
            'opportunities' => 0
        ]
    ]));

} catch (Throwable $e) {
    error_log('me.php error: ' . $e->getMessage());

    $payload = [
        'success' => false,
        'message' => 'Unable to load your profile. Please try again.'
    ];

    // On your own computer only, show the real reason to help you debug.
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if ($local) {
        $payload['debug'] = $e->getMessage();
    }

    send(500, $payload);
}