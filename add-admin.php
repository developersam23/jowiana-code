<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| JOWAINA SON - ADD ADMINISTRATOR
|--------------------------------------------------------------------------
| This file is located directly inside:
|
| C:\xampp\htdocs\Jowian codes\
|
| Therefore config.php and admin-session.php are in the same directory.
|--------------------------------------------------------------------------
*/

require __DIR__ . '/admin-session.php';
require __DIR__ . '/config.php';

$message = '';
$messageType = '';

$fullName = '';
$phone = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Validate Full Name
    |--------------------------------------------------------------------------
    */

    if ($fullName === '') {

        $message = 'Please enter the administrator full name.';
        $messageType = 'error';

    }

    /*
    |--------------------------------------------------------------------------
    | Validate Kenyan Phone Number
    |--------------------------------------------------------------------------
    | Examples:
    | 0712345678
    | 0722345678
    | 0112345678
    | 0791234567
    |--------------------------------------------------------------------------
    */

    elseif (!preg_match('/^0[17]\d{8}$/', $phone)) {

        $message = 'Enter a valid Kenyan phone number, for example 0712345678.';
        $messageType = 'error';

    }

    /*
    |--------------------------------------------------------------------------
    | Validate Email
    |--------------------------------------------------------------------------
    */

    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = 'Please enter a valid email address.';
        $messageType = 'error';

    }

    /*
    |--------------------------------------------------------------------------
    | Validate Password
    |--------------------------------------------------------------------------
    */

    elseif (strlen($password) < 8) {

        $message = 'Password must be at least 8 characters.';
        $messageType = 'error';

    }

    /*
    |--------------------------------------------------------------------------
    | Confirm Password
    |--------------------------------------------------------------------------
    */

    elseif ($password !== $confirmPassword) {

        $message = 'Passwords do not match.';
        $messageType = 'error';

    }

    /*
    |--------------------------------------------------------------------------
    | Create Administrator
    |--------------------------------------------------------------------------
    */

    else {

        try {

            $pdo = db();

            /*
            |--------------------------------------------------------------------------
            | Check if email or phone already exists
            |--------------------------------------------------------------------------
            */

            $check = $pdo->prepare(
                'SELECT id
                 FROM riders
                 WHERE email = :email
                    OR phone = :phone
                 LIMIT 1'
            );

            $check->execute([
                ':email' => $email,
                ':phone' => $phone
            ]);

            if ($check->fetch()) {

                $message = 'An account with that email or phone already exists.';
                $messageType = 'error';

            } else {

                /*
                |--------------------------------------------------------------------------
                | Securely hash password
                |--------------------------------------------------------------------------
                */

                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                if ($passwordHash === false) {

                    throw new RuntimeException(
                        'Unable to securely hash administrator password.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Insert Administrator
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare(
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

                $stmt->execute([
                    ':full_name'     => $fullName,
                    ':phone'         => $phone,
                    ':email'         => $email,
                    ':password_hash' => $passwordHash,
                    ':status'        => 'verified',
                    ':role'          => 'admin'
                ]);

                /*
                |--------------------------------------------------------------------------
                | Successfully created
                |--------------------------------------------------------------------------
                */

                header('Location: administrators.php?created=1');
                exit;
            }

        } catch (PDOException $e) {

            error_log(
                'Add administrator database error: ' .
                $e->getMessage()
            );

            /*
            |--------------------------------------------------------------------------
            | Handle duplicate database errors safely
            |--------------------------------------------------------------------------
            */

            if ((int)$e->errorInfo[1] === 1062) {

                $message =
                    'An account with that email or phone already exists.';

            } else {

                $message =
                    'Unable to create administrator. Please try again.';
            }

            $messageType = 'error';

        } catch (Throwable $e) {

            error_log(
                'Add administrator error: ' .
                $e->getMessage()
            );

            $message =
                'Unable to create administrator. Please try again.';

            $messageType = 'error';
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

    <title>Add Administrator - Jowaina Son</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 30px 15px;
            background:
                linear-gradient(
                    135deg,
                    #000000 0%,
                    #111111 55%,
                    #1a1a1a 100%
                );
            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        .box {
            width: 100%;
            max-width: 520px;
            background: #ffffff;
            padding: 35px;
            border-radius: 14px;
            box-shadow:
                0 15px 50px rgba(0, 0, 0, 0.45);
        }

        .logo {
            text-align: center;
            margin-bottom: 10px;
        }

        .logo span {
            display: inline-block;
            width: 55px;
            height: 55px;
            line-height: 55px;
            border-radius: 50%;
            background: #ff7a00;
            color: #ffffff;
            font-size: 24px;
            font-weight: bold;
        }

        h1 {
            margin: 10px 0 0;
            text-align: center;
            color: #111111;
            font-size: 28px;
        }

        p.subtitle {
            text-align: center;
            color: #666666;
            margin: 10px 0 30px;
            line-height: 1.5;
        }

        label {
            display: block;
            margin-top: 18px;
            margin-bottom: 7px;
            font-weight: bold;
            color: #222222;
        }

        input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #cccccc;
            border-radius: 7px;
            font-size: 15px;
            background: #ffffff;
            color: #111111;
        }

        input:focus {
            outline: none;
            border-color: #ff7a00;
            box-shadow:
                0 0 0 3px rgba(255, 122, 0, 0.12);
        }

        button {
            width: 100%;
            margin-top: 25px;
            padding: 14px;
            border: none;
            border-radius: 7px;
            background: #ff7a00;
            color: #ffffff;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        button:hover {
            background: #e66c00;
        }

        .message {
            padding: 13px 15px;
            border-radius: 7px;
            margin-bottom: 20px;
            line-height: 1.4;
            font-size: 14px;
        }

        .error {
            background: #ffe5e5;
            color: #a00000;
            border: 1px solid #ffbcbc;
        }

        .success {
            background: #e7f8ec;
            color: #146c2e;
            border: 1px solid #b9e7c5;
        }

        .security-note {
            margin-top: 20px;
            padding: 13px;
            background: #fff3e8;
            border-left: 4px solid #ff7a00;
            font-size: 13px;
            line-height: 1.5;
            color: #555555;
        }

        .back {
            display: block;
            text-align: center;
            margin-top: 22px;
            color: #333333;
            text-decoration: none;
            font-weight: 600;
        }

        .back:hover {
            color: #ff7a00;
        }

        .required {
            color: #d00000;
        }

        @media (max-width: 600px) {

            body {
                padding: 15px;
                align-items: flex-start;
            }

            .box {
                margin-top: 20px;
                padding: 25px 20px;
            }

            h1 {
                font-size: 24px;
            }
        }

    </style>

</head>

<body>

<div class="box">

    <div class="logo">
        <span>JS</span>
    </div>

    <h1>Add Administrator</h1>

    <p class="subtitle">
        Create a new Jowaina Son administrator account.
    </p>

    <?php if ($message !== ''): ?>

        <div class="message <?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>

    <?php endif; ?>

    <form method="POST" action="">

        <label for="full_name">
            Full Name <span class="required">*</span>
        </label>

        <input
            type="text"
            id="full_name"
            name="full_name"
            placeholder="Enter full name"
            value="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>"
            autocomplete="name"
            required
        >

        <label for="phone">
            Phone Number <span class="required">*</span>
        </label>

        <input
            type="tel"
            id="phone"
            name="phone"
            placeholder="0712345678"
            value="<?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') ?>"
            autocomplete="tel"
            maxlength="10"
            required
        >

        <label for="email">
            Email Address <span class="required">*</span>
        </label>

        <input
            type="email"
            id="email"
            name="email"
            placeholder="admin@example.com"
            value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
            autocomplete="email"
            required
        >

        <label for="password">
            Password <span class="required">*</span>
        </label>

        <input
            type="password"
            id="password"
            name="password"
            placeholder="Minimum 8 characters"
            autocomplete="new-password"
            minlength="8"
            required
        >

        <label for="confirm_password">
            Confirm Password <span class="required">*</span>
        </label>

        <input
            type="password"
            id="confirm_password"
            name="confirm_password"
            placeholder="Repeat password"
            autocomplete="new-password"
            minlength="8"
            required
        >

        <button type="submit">
            Create Administrator
        </button>

    </form>

    <div class="security-note">
        <strong>Security:</strong>
        Only logged-in administrators can access this page.
        The new account will automatically be created as a
        <strong>verified administrator</strong>.
    </div>

    <a href="administrators.php" class="back">
        ← Back to Administrators
    </a>

</div>

</body>

</html>