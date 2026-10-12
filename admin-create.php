<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require __DIR__ . '/session.php';
require __DIR__ . '/config.php';


/*
|--------------------------------------------------------------------------
| JSON response helper
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
| Request method
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
| Get submitted data
|--------------------------------------------------------------------------
*/

$fullName = trim(
    (string)($_POST['full_name'] ?? '')
);

$phone = trim(
    (string)($_POST['phone'] ?? '')
);

$email = strtolower(
    trim(
        (string)($_POST['email'] ?? '')
    )
);

$password = (string)(
    $_POST['password'] ?? ''
);

$confirmPassword = (string)(
    $_POST['confirm_password'] ?? ''
);


/*
|--------------------------------------------------------------------------
| Validate name
|--------------------------------------------------------------------------
*/

if ($fullName === '') {

    respond(
        422,
        false,
        'Enter the administrator full name.'
    );
}


if (mb_strlen($fullName) < 2) {

    respond(
        422,
        false,
        'The administrator name is too short.'
    );
}


/*
|--------------------------------------------------------------------------
| Validate phone
|--------------------------------------------------------------------------
*/

$phone = preg_replace(
    '/[\s\-()]/',
    '',
    $phone
);


/*
|--------------------------------------------------------------------------
| Convert Kenyan +254 number to 07/01 format
|--------------------------------------------------------------------------
|
| Example:
|
| +254712345678
| becomes
| 0712345678
|
*/

if (
    preg_match(
        '/^(?:\+?254)([17]\d{8})$/',
        $phone,
        $matches
    )
) {

    $phone = '0' . $matches[1];
}


if (
    !preg_match(
        '/^0[17]\d{8}$/',
        $phone
    )
) {

    respond(
        422,
        false,
        'Enter a valid Kenyan phone number, for example 0712345678.'
    );
}


/*
|--------------------------------------------------------------------------
| Validate email
|--------------------------------------------------------------------------
*/

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
| Validate password
|--------------------------------------------------------------------------
*/

if (strlen($password) < 8) {

    respond(
        422,
        false,
        'The password must contain at least 8 characters.'
    );
}


if ($password !== $confirmPassword) {

    respond(
        422,
        false,
        'The passwords do not match.'
    );
}


/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

try {

    $database = db();


    /*
    |--------------------------------------------------------------------------
    | Check whether an administrator already exists
    |--------------------------------------------------------------------------
    */

    $adminCountStatement = $database->query(
        "SELECT COUNT(*)
         FROM riders
         WHERE role = 'admin'"
    );

    $adminCount = (int)
        $adminCountStatement->fetchColumn();


    /*
    |--------------------------------------------------------------------------
    | Start session
    |--------------------------------------------------------------------------
    */

    startRiderSession();


    $currentRole = (string)(
        $_SESSION['rider_role'] ?? ''
    );


    /*
    |--------------------------------------------------------------------------
    | Security rule
    |--------------------------------------------------------------------------
    |
    | If an administrator already exists, only an existing
    | administrator can create another administrator.
    |
    */

    if (
        $adminCount > 0 &&
        $currentRole !== 'admin'
    ) {

        respond(
            403,
            false,
            'Only an administrator can create another administrator.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Check duplicate email
    |--------------------------------------------------------------------------
    */

    $emailCheck = $database->prepare(
        'SELECT id
         FROM riders
         WHERE email = :email
         LIMIT 1'
    );

    $emailCheck->execute([
        ':email' => $email
    ]);


    if ($emailCheck->fetch()) {

        respond(
            409,
            false,
            'An account with this email already exists.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Check duplicate phone
    |--------------------------------------------------------------------------
    */

    $phoneCheck = $database->prepare(
        'SELECT id
         FROM riders
         WHERE phone = :phone
         LIMIT 1'
    );

    $phoneCheck->execute([
        ':phone' => $phone
    ]);


    if ($phoneCheck->fetch()) {

        respond(
            409,
            false,
            'An account with this phone number already exists.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Hash password
    |--------------------------------------------------------------------------
    */

    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );


    if ($passwordHash === false) {

        respond(
            500,
            false,
            'Could not secure the password.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Create administrator
    |--------------------------------------------------------------------------
    |
    | Your database only allows:
    |
    | role:
    |   rider
    |   admin
    |
    | status:
    |   pending
    |   verified
    |   rejected
    |
    | Therefore administrators are created as:
    |
    | role   = admin
    | status = verified
    |
    */

    $insert = $database->prepare(
        'INSERT INTO riders
        (
            rider_id,
            full_name,
            phone,
            email,
            password_hash,
            status,
            role
        )
        VALUES
        (
            NULL,
            :full_name,
            :phone,
            :email,
            :password_hash,
            :status,
            :role
        )'
    );


    $insert->execute([
        ':full_name'     => $fullName,
        ':phone'         => $phone,
        ':email'         => $email,
        ':password_hash' => $passwordHash,
        ':status'        => 'verified',
        ':role'          => 'admin'
    ]);


    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    */

    respond(
        201,
        true,
        'Administrator account created successfully.',
        [
            'redirect' => 'admin-login.html'
        ]
    );


} catch (PDOException $e) {

    error_log(
        'Admin creation error: ' .
        $e->getMessage()
    );


    respond(
        500,
        false,
        'Could not create the administrator. Please check your database connection and table structure.'
    );
}