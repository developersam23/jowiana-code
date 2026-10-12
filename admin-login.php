<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require __DIR__ . '/session.php';
require __DIR__ . '/config.php';


/*
|--------------------------------------------------------------------------
| Response helper
|--------------------------------------------------------------------------
*/

function respond(
    int $code,
    bool $success,
    string $message,
    array $extra = []
): void {

    http_response_code($code);

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message
            ],
            $extra
        )
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Only POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    respond(
        405,
        false,
        'Invalid request method.'
    );
}


/*
|--------------------------------------------------------------------------
| Get login information
|--------------------------------------------------------------------------
*/

$email = strtolower(
    trim(
        (string)(
            $_POST['identifier'] ?? ''
        )
    )
);

$password = (string)(
    $_POST['password'] ?? ''
);


/*
|--------------------------------------------------------------------------
| Validate
|--------------------------------------------------------------------------
*/

if ($email === '' || $password === '') {

    respond(
        422,
        false,
        'Enter your administrator email and password.'
    );
}


if (
    !filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    )
) {

    respond(
        422,
        false,
        'Enter a valid administrator email address.'
    );
}


/*
|--------------------------------------------------------------------------
| Find administrator
|--------------------------------------------------------------------------
*/

try {

    $statement = db()->prepare(
        'SELECT
            id,
            full_name,
            phone,
            email,
            password_hash,
            status,
            role
         FROM riders
         WHERE email = :email
         AND role = :role
         LIMIT 1'
    );


    $statement->execute([
        ':email' => $email,
        ':role'  => 'admin'
    ]);


    $admin = $statement->fetch(
        PDO::FETCH_ASSOC
    );


} catch (PDOException $e) {

    error_log(
        'Admin login error: ' .
        $e->getMessage()
    );


    respond(
        500,
        false,
        'Something went wrong. Please try again later.'
    );
}


/*
|--------------------------------------------------------------------------
| Check account
|--------------------------------------------------------------------------
*/

if (!$admin) {

    respond(
        401,
        false,
        'Invalid administrator email or password.'
    );
}


/*
|--------------------------------------------------------------------------
| Check account status
|--------------------------------------------------------------------------
*/

if (
    (string)$admin['status'] !== 'verified'
) {

    if (
        (string)$admin['status'] === 'pending'
    ) {

        respond(
            403,
            false,
            'This administrator account has not been verified.'
        );
    }


    if (
        (string)$admin['status'] === 'rejected'
    ) {

        respond(
            403,
            false,
            'This administrator account has been rejected.'
        );
    }


    respond(
        403,
        false,
        'This administrator account cannot sign in.'
    );
}


/*
|--------------------------------------------------------------------------
| Verify password
|--------------------------------------------------------------------------
*/

if (
    !password_verify(
        $password,
        $admin['password_hash']
    )
) {

    respond(
        401,
        false,
        'Invalid administrator email or password.'
    );
}


/*
|--------------------------------------------------------------------------
| Start secure session
|--------------------------------------------------------------------------
*/

startRiderSession();

session_regenerate_id(true);


/*
|--------------------------------------------------------------------------
| Store administrator session
|--------------------------------------------------------------------------
*/

$_SESSION['rider_db_id'] =
    (int)$admin['id'];

$_SESSION['rider_name'] =
    (string)$admin['full_name'];

$_SESSION['rider_email'] =
    (string)$admin['email'];

$_SESSION['rider_role'] =
    'admin';


/*
|--------------------------------------------------------------------------
| Successful login
|--------------------------------------------------------------------------
*/

respond(
    200,
    true,
    'Welcome back, Administrator!',
    [
        'redirect'  => 'admin.php',
        'full_name' => $admin['full_name'],
        'role'      => 'admin'
    ]
);