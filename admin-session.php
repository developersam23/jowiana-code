<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';

startRiderSession();


/*
|--------------------------------------------------------------------------
| Require administrator
|--------------------------------------------------------------------------
*/

function requireAdmin(): void
{
    $role = (string)(
        $_SESSION['rider_role'] ?? ''
    );


    if ($role !== 'admin') {

        header(
            'Location: admin-login.html'
        );

        exit;
    }
}


requireAdmin();