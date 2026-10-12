<?php
// 1. Prevent PHP from printing HTML error/warning messages into the output stream
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 2. Set strict JSON header
header('Content-Type: application/json; charset=utf-8');

// Start session if you need user verification
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    // Check if file was uploaded without PHP upload errors
    if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.',
            UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on server.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.'
        ];
        $errCode = $_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errMsg = $uploadErrors[$errCode] ?? 'Unknown upload error.';
        throw new Exception($errMsg);
    }

    $file = $_FILES['profile_picture'];

    // Validate file size (e.g., 5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('File exceeds maximum size limit of 5MB.');
    }

    // Validate image MIME type safely
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mimeType, $allowedMimeTypes)) {
        throw new Exception('Invalid image type. Only JPG, PNG, and WEBP images are allowed.');
    }

    // Ensure target upload directory exists
    $targetDir = 'uploads/avatars/';
    if (!file_exists($targetDir)) {
        if (!mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
            throw new Exception('Failed to create upload directory on server.');
        }
    }

    // Generate clean, unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($extension);
    $targetPath = $targetDir . $fileName;

    // Move file to destination folder
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Failed to move uploaded file to destination directory.');
    }

    // Optional: Save file path into database for logged-in rider here
    /*
    if (isset($_SESSION['rider_id'])) {
        $riderId = $_SESSION['rider_id'];
        // $stmt = $pdo->prepare("UPDATE riders SET avatar_url = ? WHERE id = ?");
        // $stmt->execute([$targetPath, $riderId]);
    }
    */

    // Return successful JSON response
    echo json_encode([
        'success' => true,
        'message' => 'Profile picture updated successfully!',
        'avatar_url' => $targetPath
    ]);
    exit;

} catch (Exception $e) {
    // Return structured JSON error response on failure
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}