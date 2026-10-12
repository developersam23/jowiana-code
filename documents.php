<?php
declare(strict_types=1);

/**
 * documents.php - the logged-in rider's documents.
 *   GET            -> list my documents (+ a CSRF token for the page)
 *   POST upload    -> multipart: action=upload, doc_type, title (optional), files[]
 *   POST delete    -> action=delete, id
 * Always answers with JSON. Files are stored in storage/documents (blocked
 * from the web) and are only ever served through file.php.
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
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    startRiderSession();
}
if (empty($_SESSION['rider_db_id'])) {
    respond(401, false, 'Not logged in. Please log in again.');
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$riderId = (int)$_SESSION['rider_db_id'];

/* ---------- Rules ---------- */
const MAX_BYTES = 5 * 1024 * 1024;                       // 5 MB per file
const LIMITS    = ['photo' => 1, 'id_card' => 2, 'certificate' => 30];
const LABELS    = ['photo' => 'profile photo', 'id_card' => 'ID card files', 'certificate' => 'certificates'];
const IMAGES    = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const WITH_PDF  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

$storage = __DIR__ . '/storage/documents';

function ensureStorage(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        respond(500, false, 'The server could not create the upload folder.');
    }
    // Block direct web access to the folder (Apache). Files are served by file.php.
    $ht = dirname($dir) . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents(
            $ht,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n"
        );
    }
}

function safeStoredName(string $name): bool
{
    return (bool)preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $name);
}

try {
    $pdo = db();

    /* ================= GET: list ================= */
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $s = $pdo->prepare(
            'SELECT id, doc_type, title, original_name, mime_type, size_bytes, uploaded_at
               FROM rider_documents WHERE rider_id = :id ORDER BY uploaded_at DESC, id DESC'
        );
        $s->execute([':id' => $riderId]);
        respond(200, true, 'OK', [
            'documents'  => $s->fetchAll(PDO::FETCH_ASSOC),
            'csrf_token' => $_SESSION['csrf_token'],
            'limits'     => LIMITS,
            'max_mb'     => MAX_BYTES / 1048576,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, false, 'Invalid request.');
    }

    /* ================= POST: common checks ================= */
    // A body bigger than post_max_size arrives completely empty
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        respond(413, false, 'The upload is too large for this server. Choose smaller files (max 5 MB each).');
    }

    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || !hash_equals((string)$_SESSION['csrf_token'], $sent)) {
        respond(403, false, 'Your session expired. Please refresh the page and try again.');
    }

    $action = (string)($_POST['action'] ?? '');

    /* ================= POST: delete ================= */
    if ($action === 'delete') {
        $docId = (int)($_POST['id'] ?? 0);
        $s = $pdo->prepare('SELECT stored_name FROM rider_documents WHERE id = :id AND rider_id = :rid LIMIT 1');
        $s->execute([':id' => $docId, ':rid' => $riderId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            respond(404, false, 'Document not found.');
        }
        $pdo->prepare('DELETE FROM rider_documents WHERE id = :id AND rider_id = :rid')
            ->execute([':id' => $docId, ':rid' => $riderId]);
        if (safeStoredName($row['stored_name'])) {
            @unlink($storage . '/' . $row['stored_name']);
        }
        respond(200, true, 'Document removed.');
    }

    /* ================= POST: upload ================= */
    if ($action !== 'upload') {
        respond(400, false, 'Invalid request.');
    }

    $type = (string)($_POST['doc_type'] ?? '');
    if (!isset(LIMITS[$type])) {
        respond(422, false, 'Invalid document type.');
    }
    $allowed = $type === 'photo' ? IMAGES : WITH_PDF;

    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 100);

    // Turn $_FILES['files'] into a simple list (works for one file or many)
    $files = [];
    if (isset($_FILES['files'])) {
        $f = $_FILES['files'];
        if (is_array($f['name'])) {
            foreach ($f['name'] as $i => $n) {
                $files[] = ['name' => $n, 'tmp' => $f['tmp_name'][$i], 'err' => $f['error'][$i], 'size' => $f['size'][$i]];
            }
        } else {
            $files[] = ['name' => $f['name'], 'tmp' => $f['tmp_name'], 'err' => $f['error'], 'size' => $f['size']];
        }
    }
    $files = array_values(array_filter($files, static fn($x) => $x['err'] !== UPLOAD_ERR_NO_FILE));
    if (!$files) {
        respond(422, false, 'Please choose a file to upload.');
    }

    // Count what the rider already has
    $c = $pdo->prepare('SELECT COUNT(*) FROM rider_documents WHERE rider_id = :id AND doc_type = :t');
    $c->execute([':id' => $riderId, ':t' => $type]);
    $existing = (int)$c->fetchColumn();

    // A new profile photo replaces the old one; the others have a limit
    if ($type !== 'photo' && $existing + count($files) > LIMITS[$type]) {
        respond(422, false, 'You can upload up to ' . LIMITS[$type] . ' ' . LABELS[$type] . ' (you already have ' . $existing . ').');
    }
    if ($type === 'photo' && count($files) > 1) {
        respond(422, false, 'Please choose one photo.');
    }

    ensureStorage($storage);
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $saved = 0;
    $errors = [];
    $oldPhotos = [];

    if ($type === 'photo') {
        $o = $pdo->prepare('SELECT id, stored_name FROM rider_documents WHERE rider_id = :id AND doc_type = \'photo\'');
        $o->execute([':id' => $riderId]);
        $oldPhotos = $o->fetchAll(PDO::FETCH_ASSOC);
    }

    $ins = $pdo->prepare(
        'INSERT INTO rider_documents (rider_id, doc_type, title, original_name, stored_name, mime_type, size_bytes)
         VALUES (:rid, :type, :title, :orig, :stored, :mime, :size)'
    );

    foreach ($files as $f) {
        $label = mb_substr(basename((string)$f['name']), 0, 120);

        if ($f['err'] === UPLOAD_ERR_INI_SIZE || $f['err'] === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = $label . ': file is too large (max 5 MB).';
            continue;
        }
        if ($f['err'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp'])) {
            $errors[] = $label . ': the upload failed. Please try again.';
            continue;
        }
        if ($f['size'] > MAX_BYTES || $f['size'] < 1) {
            $errors[] = $label . ': file must be between 1 byte and 5 MB.';
            continue;
        }

        // Decide the type from the file's real content, never from its name
        $mime = (string)$finfo->file($f['tmp']);
        if (!isset($allowed[$mime])) {
            $errors[] = $label . ': ' . ($type === 'photo' ? 'only JPG, PNG or WEBP images are allowed.' : 'only JPG, PNG, WEBP or PDF files are allowed.');
            continue;
        }
        if ($mime !== 'application/pdf' && @getimagesize($f['tmp']) === false) {
            $errors[] = $label . ': this is not a valid image.';
            continue;
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($f['tmp'], $storage . '/' . $stored)) {
            $errors[] = $label . ': could not be saved on the server.';
            continue;
        }
        @chmod($storage . '/' . $stored, 0644);

        $docTitle = $title;
        if ($docTitle === '' || count($files) > 1) {
            $docTitle = $title !== '' ? $title : mb_substr(pathinfo($label, PATHINFO_FILENAME), 0, 100);
        }

        try {
            $ins->execute([
                ':rid' => $riderId, ':type' => $type, ':title' => $docTitle,
                ':orig' => $label, ':stored' => $stored, ':mime' => $mime, ':size' => (int)$f['size'],
            ]);
            $saved++;
        } catch (PDOException $e) {
            @unlink($storage . '/' . $stored);
            error_log('documents.php insert: ' . $e->getMessage());
            $errors[] = $label . ': could not be recorded.';
        }
    }

    // Remove the previous profile photo only after the new one is safely saved
    if ($type === 'photo' && $saved === 1) {
        foreach ($oldPhotos as $old) {
            $pdo->prepare('DELETE FROM rider_documents WHERE id = :id AND rider_id = :rid')
                ->execute([':id' => $old['id'], ':rid' => $riderId]);
            if (safeStoredName($old['stored_name'])) {
                @unlink($storage . '/' . $old['stored_name']);
            }
        }
    }

    if ($saved === 0) {
        respond(422, false, $errors ? implode(' ', $errors) : 'Nothing was uploaded.', ['errors' => $errors]);
    }

    respond(200, true, $saved . ($saved === 1 ? ' file uploaded.' : ' files uploaded.'), ['saved' => $saved, 'errors' => $errors]);

} catch (Throwable $e) {
    error_log('documents.php: ' . $e->getMessage());
    $extra = [];
    if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        $extra['debug'] = $e->getMessage();
    }
    respond(500, false, 'Something went wrong. Have you run schema-documents.sql in phpMyAdmin?', $extra);
}