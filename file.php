<?php
declare(strict_types=1);

/**
 * file.php?id=123            -> shows an uploaded document
 * file.php?id=123&download=1 -> downloads it
 * Only the rider who owns the file, or an admin, can open it.
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    startRiderSession();
}

function deny(int $code, string $text): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
}

if (empty($_SESSION['rider_db_id'])) {
    deny(401, 'Please log in.');
}

$docId = (int)($_GET['id'] ?? 0);
if ($docId < 1) {
    deny(404, 'Not found.');
}

try {
    $pdo = db();

    $u = $pdo->prepare('SELECT role FROM riders WHERE id = :id LIMIT 1');
    $u->execute([':id' => (int)$_SESSION['rider_db_id']]);
    $role = (string)$u->fetchColumn();

    $s = $pdo->prepare(
        'SELECT rider_id, original_name, stored_name, mime_type FROM rider_documents WHERE id = :id LIMIT 1'
    );
    $s->execute([':id' => $docId]);
    $doc = $s->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('file.php: ' . $e->getMessage());
    deny(500, 'Server error.');
}

// Same answer for "missing" and "not yours" so ids cannot be guessed
if (!$doc || ($role !== 'admin' && (int)$doc['rider_id'] !== (int)$_SESSION['rider_db_id'])) {
    deny(404, 'Not found.');
}

if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', (string)$doc['stored_name'])) {
    deny(404, 'Not found.');
}

$path = __DIR__ . '/storage/documents/' . $doc['stored_name'];
if (!is_file($path)) {
    deny(404, 'File is missing.');
}

$name = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)$doc['original_name']);
$disposition = isset($_GET['download']) ? 'attachment' : 'inline';

header('Content-Type: ' . $doc['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, max-age=300');
header('X-Robots-Tag: noindex');

readfile($path);