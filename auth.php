<?php
declare(strict_types=1);

/**
 * auth.php - shared helpers for sessions (who is logged in).
 * Other PHP files load it with:  require __DIR__ . '/auth.php';
 */

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('JOWAINASESS');
    session_set_cookie_params([
        'lifetime' => 0,                               // ends when the browser closes
        'path'     => '/',
        'httponly' => true,                            // JavaScript cannot read the cookie
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),       // HTTPS only, once your site has it
    ]);
    session_start();
}

/** Returns the logged-in rider's details, or null when nobody is logged in. */
function current_rider(): ?array
{
    start_secure_session();
    return $_SESSION['rider'] ?? null;
}

/** Sends a JSON answer and stops. */
function json_out(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data);
    exit;
}