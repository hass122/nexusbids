<?php
/**
 * Secure proxy to serve KYC images to authorized users/admins only
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/kyc-helpers.php';

$current_user_id = (int) ($_SESSION['user_id'] ?? 0);
$current_role = $_SESSION['role'] ?? '';

if ($current_user_id <= 0) {
    http_response_code(401);
    exit('Unauthorized');
}

$target_user_id = (int) ($_GET['u'] ?? 0);
$filename = basename((string) ($_GET['f'] ?? '')); // Prevent traversal

if ($target_user_id <= 0 || $filename === '') {
    http_response_code(400);
    exit('Bad request');
}

// Authorization: user can see own files; admin can see any
$allowed = ($current_user_id === $target_user_id) || ($current_role === 'admin');
if (!$allowed) {
    http_response_code(403);
    exit('Forbidden');
}

$full_path = KYC_UPLOAD_DIR . '/' . $target_user_id . '/' . $filename;

if (!is_file($full_path)) {
    http_response_code(404);
    exit('Not found');
}

// Verify it's inside the KYC upload dir (extra safety)
$real = realpath($full_path);
$base = realpath(KYC_UPLOAD_DIR);
if (!$real || !$base || strpos($real, $base) !== 0) {
    http_response_code(403);
    exit('Forbidden');
}

$mime = mime_content_type($real) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($real);
exit;