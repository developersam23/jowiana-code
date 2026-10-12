<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

ini_set('display_errors', '0');

session_start();

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    respond(405, [
        'success' => false,
        'message' => 'Only POST requests are allowed.'
    ]);
}

if (empty($_SESSION['rider_db_id'])) {
    respond(401, [
        'success' => false,
        'message' => 'Your session has expired. Please log in again.'
    ]);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid request data.'
    ]);
}

/* CSRF protection */
$sessionToken = $_SESSION['csrf_token'] ?? '';
$requestToken = $input['csrf_token'] ?? '';

if (
    !is_string($sessionToken) ||
    $sessionToken === '' ||
    !is_string($requestToken) ||
    !hash_equals($sessionToken, $requestToken)
) {
    respond(403, [
        'success' => false,
        'message' => 'Your security token has expired. Refresh the page and try again.'
    ]);
}

/* Validate the submitted fields */
$fullName = trim((string) ($input['full_name'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));

if (
    $fullName === '' ||
    strlen($fullName) > 120 ||
    preg_match('/[\x00-\x1F\x7F]/', $fullName)
) {
    respond(422, [
        'success' => false,
        'message' => 'Enter a valid full name of up to 120 characters.'
    ]);
}

if (
    $phone === '' ||
    strlen($phone) > 30 ||
    !preg_match('/^[0-9+().\s-]+$/', $phone)
) {
    respond(422, [
        'success' => false,
        'message' => 'Enter a valid phone number.'
    ]);
}

if (
    $email === '' ||
    strlen($email) > 254 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    respond(422, [
        'success' => false,
        'message' => 'Enter a valid email address.'
    ]);
}

require __DIR__ . '/config.php';

try {
    $pdo = db();
    $id = (int) $_SESSION['rider_db_id'];

    /* Check whether the email belongs to another rider. */
    $check = $pdo->prepare(
        'SELECT id FROM riders
         WHERE email = :email AND id <> :id
         LIMIT 1'
    );

    $check->execute([
        ':email' => $email,
        ':id' => $id
    ]);

    if ($check->fetchColumn()) {
        respond(409, [
            'success' => false,
            'message' => 'This email address is already registered.'
        ]);
    }

    /* Update only the authenticated rider's own record. */
    $update = $pdo->prepare(
        'UPDATE riders
         SET full_name = :full_name,
             phone = :phone,
             email = :email
         WHERE id = :id
         LIMIT 1'
    );

    $update->execute([
        ':full_name' => $fullName,
        ':phone' => $phone,
        ':email' => $email,
        ':id' => $id
    ]);

    /* Read back the saved database record. */
    $stmt = $pdo->prepare(
        'SELECT full_name, rider_id, phone, email, status, role
         FROM riders
         WHERE id = :id
         LIMIT 1'
    );

    $stmt->execute([':id' => $id]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rider) {
        respond(404, [
            'success' => false,
            'message' => 'Rider account not found.'
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Your profile has been updated successfully.',
        'rider' => $rider
    ]);

} catch (Throwable $e) {
    error_log('update_profile.php error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'Unable to save your profile because of a server or database error.'
    ]);
}