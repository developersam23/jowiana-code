<?php
declare(strict_types=1);

session_start();

require __DIR__ . '/config.php';

/*
 * Protect this page using your actual admin authentication.
 * Change admin_id if your login uses another session variable.
 */
if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Access denied. Administrator login required.');
}

$riderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$riderId || $riderId < 1) {
    http_response_code(400);
    exit('Invalid rider ID.');
}

try {
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT id, full_name, rider_id, phone, email, status, role
         FROM riders
         WHERE id = :id
         LIMIT 1'
    );

    $stmt->execute([':id' => $riderId]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Admin rider details error: ' . $e->getMessage());

    http_response_code(500);
    exit('Unable to load rider information.');
}

if (!$rider) {
    http_response_code(404);
    exit('Rider not found.');
}

function e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin - Rider Details</title>

    <link rel="stylesheet" href="education.css">
    <link rel="stylesheet" href="dashboard.css">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        .admin-rider-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: var(--pad);
        }

        .admin-rider-heading {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: var(--gap);
        }

        .admin-rider-heading h1 {
            font-size: clamp(1.4rem, 2.6vw, 2.1rem);
        }

        .admin-rider-heading p {
            color: var(--slate);
            font-size: .85rem;
            margin-top: .3rem;
        }

        .admin-rider-card {
            background: var(--white);
            border: 1px solid #e8ebf0;
            border-radius: var(--radius);
            padding: var(--pad);
        }

        .admin-rider-card h2 {
            margin-bottom: 1.2rem;
            font-size: 1.1rem;
        }

        .admin-rider-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .admin-rider-field {
            min-width: 0;
            padding: 1rem;
            border: 1px solid #e8ebf0;
            border-radius: .6rem;
            background: #f8fafc;
        }

        .admin-rider-field label {
            display: block;
            margin-bottom: .4rem;
            color: var(--muted);
            font-size: .78rem;
        }

        .admin-rider-field p {
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .admin-rider-actions {
            margin-top: 1.4rem;
        }

        @media (max-width: 639px) {
            .admin-rider-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<main class="admin-rider-container">

    <header class="admin-rider-heading">
        <div class="title-icon">
            <i class="fa-solid fa-user-shield"></i>
        </div>

        <div>
            <h1>Rider Details</h1>
            <p>View the latest information saved for this rider.</p>
        </div>
    </header>

    <section class="admin-rider-card">

        <h2>
            <i class="fa-solid fa-address-card"></i>
            Personal Information
        </h2>

        <div class="admin-rider-grid">

            <div class="admin-rider-field">
                <label>Database Record ID</label>
                <p><?= e($rider['id']) ?></p>
            </div>

            <div class="admin-rider-field">
                <label>Rider ID</label>
                <p><?= e($rider['rider_id']) ?></p>
            </div>

            <div class="admin-rider-field">
                <label>Full Name</label>
                <p><?= e($rider['full_name']) ?></p>
            </div>

            <div class="admin-rider-field">
                <label>Phone Number</label>
                <p><?= e($rider['phone']) ?></p>
            </div>

            <div class="admin-rider-field">
                <label>Email Address</label>
                <p><?= e($rider['email']) ?></p>
            </div>

            <div class="admin-rider-field">
                <label>Account Status</label>
                <p><?= e($rider['status']) ?></p>
            </div>

            <div class="admin-rider-field">
                <label>Account Type</label>
                <p><?= e($rider['role']) ?></p>
            </div>

        </div>

        <div class="admin-rider-actions">
            <a href="javascript:history.back()" class="btn btn-outline-orange">
                <i class="fa-solid fa-arrow-left"></i>
                Back
            </a>
        </div>

    </section>

</main>

</body>
</html>