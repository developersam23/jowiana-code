<?php
declare(strict_types=1);

/*
 * login.php
 * POST:
 *   identifier = phone OR email
 *   password   = password
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require __DIR__ . '/session.php';
require __DIR__ . '/config.php';

function respond(int $code, bool $success, string $message, array $extra = []): void
{
    http_response_code($code);

    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, false, 'Invalid request method.');
}

$identifier = trim((string) ($_POST['identifier'] ?? ''));
$password   = (string) ($_POST['password'] ?? '');

if ($identifier === '' || $password === '') {
    respond(422, false, 'Enter your phone or email and your password.');
}

/* Normalize Kenyan phone numbers. */
$phone = preg_replace('/[\s\-()]/', '', $identifier);

if (preg_match('/^(?:\+?254)([17]\d{8})$/', $phone, $matches)) {
    $phone = '0' . $matches[1];
}

$email = strtolower($identifier);

try {
    $stmt = db()->prepare(
        'SELECT id, full_name, password_hash, status
         FROM riders
         WHERE phone = :phone OR email = :email
         LIMIT 1'
    );

    $stmt->execute([
        ':phone' => $phone,
        ':email' => $email
    ]);

    $rider = $stmt->fetch();

} catch (PDOException $e) {
    error_log('Login error: ' . $e->getMessage());
    respond(500, false, 'Something went wrong. Please try again later.');
}

if (!$rider || !password_verify($password, $rider['password_hash'])) {
    respond(401, false, 'Wrong phone/email or password.');
}

/*
 * This sample allows pending riders to log in.
 * The dashboard shows "Verification pending".
 *
 * If you want to block pending riders later, add a status check here.
 */
startRiderSession();
session_regenerate_id(true);

$_SESSION['rider_db_id'] = (int) $rider['id'];

respond(200, true, 'Welcome back!', [
    'redirect'  => 'dashboard.html',
    'full_name' => $rider['full_name']
]);
