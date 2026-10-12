<?php
declare(strict_types=1);

/**
 * education.php - the logged-in rider's education + training details (JSON).
 *   GET   -> returns what this rider saved before
 *   POST  -> saves the form sent by education.js (JSON body)
 *
 * NOTE: this is the RIDER endpoint. The admin page that lists all riders'
 * education must have a different name (e.g. admin-education.php).
 */

ini_set('display_errors', '0');
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $code, bool $success, string $message, array $extra = []): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($code);
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $extra),
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function clean(mixed $value, int $max): string
{
    if (!is_string($value) && !is_numeric($value)) {
        return '';
    }
    return mb_substr(trim((string)$value), 0, $max);
}

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    startRiderSession();
}
if (empty($_SESSION['rider_db_id'])) {
    respond(401, false, 'Not logged in. Please log in again.');
}
$riderId = (int)$_SESSION['rider_db_id'];

try {
    $pdo = db();

    /* ================= GET: load saved details ================= */
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        $s = $pdo->prepare(
            'SELECT edu_level, course, field_of_study, school, location, year_done,
                    edu_status, grade, cert_name, other_quals, updated_at
               FROM rider_education
              WHERE rider_id = :id
              LIMIT 1'
        );
        $s->execute([':id' => $riderId]);
        $education = $s->fetch(PDO::FETCH_ASSOC) ?: null;

        $t = $pdo->prepare(
            'SELECT training_type, institution, certification, completed_on
               FROM rider_trainings
              WHERE rider_id = :id
           ORDER BY id ASC'
        );
        $t->execute([':id' => $riderId]);

        respond(200, true, 'OK', [
            'education' => $education,
            'trainings' => $t->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, false, 'Invalid request.');
    }

    /* ================= POST: save ================= */
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) {
        respond(400, false, 'Invalid data was sent. Please refresh the page and try again.');
    }

    $eduLevel   = clean($data['eduLevel']     ?? '', 100);
    $course     = clean($data['course']       ?? '', 150);
    $field      = clean($data['fieldOfStudy'] ?? '', 150);
    $school     = clean($data['school']       ?? '', 150);
    $location   = clean($data['location']     ?? '', 100);
    $yearDone   = clean($data['yearDone']     ?? '', 4);
    $eduStatus  = clean($data['eduStatus']    ?? '', 50);
    $grade      = clean($data['grade']        ?? '', 50);
    $certName   = clean($data['certName']     ?? '', 150);
    $otherQuals = clean($data['otherQuals']   ?? '', 200);

    if ($eduLevel === '' || $course === '' || $school === '') {
        respond(422, false, 'Please fill in your education level, course and school.');
    }
    if (!preg_match('/^\d{4}$/', $yearDone)) {
        respond(422, false, 'Please choose the year you completed or will complete.');
    }

    // Trainings (skip completely empty blocks)
    $trainings = [];
    $sent = $data['trainings'] ?? [];
    if (is_array($sent)) {
        foreach (array_slice($sent, 0, 20) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $type = clean($t['type']          ?? '', 150);
            $inst = clean($t['institution']   ?? '', 150);
            $cert = clean($t['certification'] ?? '', 150);
            $date = clean($t['date']          ?? '', 10);

            if ($type === '' && $inst === '' && $cert === '' && $date === '') {
                continue;
            }

            $dateValue = null;
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                $dateValue = $date;
            }

            $trainings[] = [$type, $inst, $cert, $dateValue];
        }
    }

    $pdo->beginTransaction();

    // Replace this rider's saved details with the new ones
    $pdo->prepare('DELETE FROM rider_education WHERE rider_id = :id')->execute([':id' => $riderId]);

    $pdo->prepare(
        'INSERT INTO rider_education
            (rider_id, edu_level, course, field_of_study, school, location, year_done,
             edu_status, grade, cert_name, other_quals, updated_at)
         VALUES
            (:rid, :level, :course, :field, :school, :loc, :year,
             :status, :grade, :cert, :other, NOW())'
    )->execute([
        ':rid'    => $riderId,
        ':level'  => $eduLevel,
        ':course' => $course,
        ':field'  => $field,
        ':school' => $school,
        ':loc'    => $location,
        ':year'   => $yearDone,
        ':status' => $eduStatus,
        ':grade'  => $grade,
        ':cert'   => $certName,
        ':other'  => $otherQuals,
    ]);

    $pdo->prepare('DELETE FROM rider_trainings WHERE rider_id = :id')->execute([':id' => $riderId]);

    if ($trainings) {
        $ins = $pdo->prepare(
            'INSERT INTO rider_trainings (rider_id, training_type, institution, certification, completed_on)
             VALUES (:rid, :type, :inst, :cert, :done)'
        );
        foreach ($trainings as [$type, $inst, $cert, $done]) {
            $ins->execute([
                ':rid' => $riderId, ':type' => $type, ':inst' => $inst,
                ':cert' => $cert, ':done' => $done,
            ]);
        }
    }

    $pdo->commit();

    respond(200, true, 'Education details saved.');

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('education.php: ' . $e->getMessage());

    $extra = [];
    if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        $extra['debug'] = $e->getMessage();   // visible in the browser Network tab, localhost only
    }
    respond(500, false, 'Something went wrong while saving. Please try again.', $extra);
}