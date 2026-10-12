<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';


/*
|--------------------------------------------------------------------------
| SECURITY
|--------------------------------------------------------------------------
|
| This file is only for creating the first administrator.
|
| After the administrator has been created successfully,
| DELETE THIS FILE FROM YOUR SERVER.
|
|--------------------------------------------------------------------------
*/


$message = '';
$messageType = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $fullName = trim(
        (string)(
            $_POST['full_name'] ?? ''
        )
    );

    $phone = trim(
        (string)(
            $_POST['phone'] ?? ''
        )
    );

    $email = strtolower(
        trim(
            (string)(
                $_POST['email'] ?? ''
            )
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

    if (mb_strlen($fullName) < 2) {

        $message =
            'Please enter the administrator full name.';

        $messageType = 'error';

    }


    /*
    |--------------------------------------------------------------------------
    | Validate phone
    |--------------------------------------------------------------------------
    */

    elseif (
        !preg_match(
            '/^0[17]\d{8}$/',
            preg_replace(
                '/[\s\-()]/',
                '',
                $phone
            )
        )
    ) {

        $message =
            'Enter a valid Kenyan phone number, for example 0712345678.';

        $messageType = 'error';

    }


    /*
    |--------------------------------------------------------------------------
    | Validate email
    |--------------------------------------------------------------------------
    */

    elseif (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $message =
            'Enter a valid email address.';

        $messageType = 'error';

    }


    /*
    |--------------------------------------------------------------------------
    | Validate password
    |--------------------------------------------------------------------------
    */

    elseif (strlen($password) < 8) {

        $message =
            'Password must contain at least 8 characters.';

        $messageType = 'error';

    }


    elseif ($password !== $confirmPassword) {

        $message =
            'The passwords do not match.';

        $messageType = 'error';

    }


    /*
    |--------------------------------------------------------------------------
    | Create administrator
    |--------------------------------------------------------------------------
    */

    else {

        try {

            $database = db();


            /*
            |--------------------------------------------------------------------------
            | Check if an admin already exists
            |--------------------------------------------------------------------------
            */

            $checkAdmin = $database->query(
                "SELECT COUNT(*)
                 FROM riders
                 WHERE role = 'admin'"
            );


            $adminCount =
                (int)$checkAdmin->fetchColumn();


            if ($adminCount > 0) {

                $message =
                    'An administrator already exists. Delete this setup file and use the administrator login.';

                $messageType = 'error';

            } else {


                /*
                |--------------------------------------------------------------------------
                | Check duplicate email
                |--------------------------------------------------------------------------
                */

                $checkEmail =
                    $database->prepare(
                        'SELECT id
                         FROM riders
                         WHERE email = :email
                         LIMIT 1'
                    );


                $checkEmail->execute([
                    ':email' => $email
                ]);


                if ($checkEmail->fetch()) {

                    $message =
                        'This email address already exists.';

                    $messageType = 'error';

                } else {


                    /*
                    |--------------------------------------------------------------------------
                    | Check duplicate phone
                    |--------------------------------------------------------------------------
                    */

                    $cleanPhone =
                        preg_replace(
                            '/[\s\-()]/',
                            '',
                            $phone
                        );


                    $checkPhone =
                        $database->prepare(
                            'SELECT id
                             FROM riders
                             WHERE phone = :phone
                             LIMIT 1'
                        );


                    $checkPhone->execute([
                        ':phone' => $cleanPhone
                    ]);


                    if ($checkPhone->fetch()) {

                        $message =
                            'This phone number already exists.';

                        $messageType = 'error';

                    } else {


                        /*
                        |--------------------------------------------------------------------------
                        | Hash password
                        |--------------------------------------------------------------------------
                        */

                        $passwordHash =
                            password_hash(
                                $password,
                                PASSWORD_DEFAULT
                            );


                        /*
                        |--------------------------------------------------------------------------
                        | Insert administrator
                        |--------------------------------------------------------------------------
                        */

                        $insert =
                            $database->prepare(
                                'INSERT INTO riders
                                (
                                    full_name,
                                    phone,
                                    email,
                                    password_hash,
                                    status,
                                    role
                                )
                                VALUES
                                (
                                    :full_name,
                                    :phone,
                                    :email,
                                    :password_hash,
                                    :status,
                                    :role
                                )'
                            );


                        $insert->execute([
                            ':full_name' =>
                                $fullName,

                            ':phone' =>
                                $cleanPhone,

                            ':email' =>
                                $email,

                            ':password_hash' =>
                                $passwordHash,

                            ':status' =>
                                'verified',

                            ':role' =>
                                'admin'
                        ]);


                        $message =
                            'Administrator created successfully. DELETE setup-admin.php NOW, then go to admin-login.html.';

                        $messageType =
                            'success';
                    }
                }
            }


        } catch (PDOException $e) {

            error_log(
                'Setup admin error: ' .
                $e->getMessage()
            );


            $message =
                'Could not create the administrator. Check your database connection.';

            $messageType =
                'error';
        }
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Initial Administrator Setup</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;

            min-height: 100vh;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 20px;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background:
                #080808;

            color: #fff;
        }

        .box {

            width: 100%;

            max-width: 480px;

            background: #171717;

            border:
                1px solid #333;

            border-radius: 15px;

            padding: 30px;

            box-shadow:
                0 20px 60px
                rgba(0,0,0,.7);
        }

        h1 {

            margin-top: 0;

            color: #ff7a00;

            font-size: 25px;
        }

        .warning {

            background:
                rgba(255,122,0,.1);

            border:
                1px solid #ff7a00;

            color: #ffb26b;

            padding: 14px;

            border-radius: 8px;

            margin-bottom: 20px;

            font-size: 14px;

            line-height: 1.5;
        }

        label {

            display: block;

            margin:
                15px 0 7px;

            font-weight: bold;

            font-size: 14px;
        }

        input {

            width: 100%;

            height: 46px;

            padding: 0 13px;

            background: #0c0c0c;

            border:
                1px solid #3a3a3a;

            border-radius: 7px;

            color: #fff;

            outline: none;

            font-size: 15px;
        }

        input:focus {

            border-color:
                #ff7a00;
        }

        button {

            width: 100%;

            height: 48px;

            margin-top: 25px;

            border: 0;

            border-radius: 7px;

            background:
                #ff7a00;

            color: #000;

            font-weight: bold;

            cursor: pointer;
        }

        .message {

            padding: 13px;

            border-radius: 7px;

            margin-bottom: 18px;

            font-size: 14px;

            line-height: 1.5;
        }

        .message.error {

            background:
                rgba(220,40,40,.1);

            border:
                1px solid #a33;

            color: #ff7777;
        }

        .message.success {

            background:
                rgba(30,180,90,.1);

            border:
                1px solid #298653;

            color: #65df98;
        }

        .login {

            display: block;

            text-align: center;

            margin-top: 20px;

            color: #ff7a00;

            text-decoration: none;
        }

    </style>

</head>


<body>


<div class="box">

    <h1>
        Jowaina Son
    </h1>

    <h2>
        Initial Administrator Setup
    </h2>


    <div class="warning">

        <strong>IMPORTANT:</strong>

        This page is only for creating the first
        administrator account.

        After successfully creating the account,
        <strong>DELETE setup-admin.php</strong>
        from your server.

    </div>


    <?php if ($message !== ''): ?>

        <div class="message <?= htmlspecialchars(
            $messageType,
            ENT_QUOTES,
            'UTF-8'
        ) ?>">

            <?= htmlspecialchars(
                $message,
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </div>

    <?php endif; ?>


    <form method="POST">


        <label>
            Full Name
        </label>

        <input
            type="text"
            name="full_name"
            required
        >


        <label>
            Phone Number
        </label>

        <input
            type="tel"
            name="phone"
            placeholder="0712345678"
            required
        >


        <label>
            Administrator Email
        </label>

        <input
            type="email"
            name="email"
            placeholder="jowian@gmail.com"
            required
        >


        <label>
            Password
        </label>

        <input
            type="password"
            name="password"
            minlength="8"
            required
        >


        <label>
            Confirm Password
        </label>

        <input
            type="password"
            name="confirm_password"
            minlength="8"
            required
        >


        <button type="submit">

            Create Administrator

        </button>

    </form>


    <a
        href="admin-login.html"
        class="login"
    >

        Go to Administrator Login

    </a>

</div>


</body>

</html>