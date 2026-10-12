<?php
declare(strict_types=1);

/**
 * register.php - receives the Join the community form and saves the rider.
 * Always answers with JSON, which the page's JavaScript reads.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(int $code, bool $success, string $message, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

// Only accept form submissions (not someone just opening the address)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, false, 'Invalid request.');
}

// Hidden "website" box: real people never fill it in, bots often do
if (!empty($_POST['website'])) {
    respond(200, true, 'Registration successful!');
}

require __DIR__ . '/config.php';

/* ---------- 1. Read the form ---------- */
$fullName = trim((string)($_POST['full_name'] ?? ''));
$phone    = preg_replace('/[\s\-()]/', '', (string)($_POST['phone'] ?? ''));
$email    = strtolower(trim((string)($_POST['email'] ?? '')));
$password = (string)($_POST['password'] ?? '');
$confirm  = (string)($_POST['confirm_password'] ?? '');

/* ---------- 2. Clean the Kenyan phone number ----------
   +254712345678, 254712345678 and 0712345678 all become 0712345678 */
if (preg_match('/^(?:\+?254)([17]\d{8})$/', $phone, $m)) {
    $phone = '0' . $m[1];
}

/* ---------- 3. Check everything ---------- */
if (!preg_match("/^[\p{L}][\p{L}\s'.\-]{1,99}$/u", $fullName)) {
    respond(422, false, 'Please enter your full name (letters only).');
}
if (!preg_match('/^0[17]\d{8}$/', $phone)) {
    respond(422, false, 'Enter a valid phone number, e.g. 0712345678.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
    respond(422, false, 'Please enter a valid email address.');
}
if (strlen($password) < 8) {
    respond(422, false, 'Password must be at least 8 characters.');
}
if ($password !== $confirm) {
    respond(422, false, 'Passwords do not match.');
}

/* ---------- 4. Save to the database ---------- */
try {
    $pdo = db();
    $pdo->beginTransaction();

    // The password is scrambled (hashed) - the real one is never stored
    $hash = password_hash($password, PASSWORD_DEFAULT);

    // status and role are set by the database defaults (pending / rider),
    // so nobody can make themselves an admin from this form
    $stmt = $pdo->prepare(
        'INSERT INTO riders (full_name, phone, email, password_hash)
         VALUES (:name, :phone, :email, :hash)'
    );
    $stmt->execute([
        ':name'  => $fullName,
        ':phone' => $phone,
        ':email' => $email,
        ':hash'  => $hash,
    ]);

    // Give the rider a friendly ID like JS-000001
    $newId   = (int)$pdo->lastInsertId();
    $riderId = 'JS-' . str_pad((string)$newId, 6, '0', STR_PAD_LEFT);

    $pdo->prepare('UPDATE riders SET rider_id = :rid WHERE id = :id')
        ->execute([':rid' => $riderId, ':id' => $newId]);

    $pdo->commit();

    respond(201, true, 'Registration successful! Welcome to the community.', ['rider_id' => $riderId]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // 1062 = duplicate phone or email
    if (($e->errorInfo[1] ?? 0) === 1062) {
        respond(409, false, 'An account with this phone number or email already exists.');
    }

    // Real error goes to the server log, not to the visitor
    error_log('Registration error: ' . $e->getMessage());
    respond(500, false, 'Something went wrong. Please try again later.');
}