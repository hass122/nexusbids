<?php
/**
 * Secure proxy to serve order proof media (images/videos)
 * Access: only the buyer, seller, or admin of that order
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/order-proofs.php';

$current_user_id = (int) ($_SESSION['user_id'] ?? 0);
$current_role = $_SESSION['role'] ?? '';
$proof_id = (int) ($_GET['id'] ?? 0);

if ($current_user_id <= 0 || $proof_id <= 0) {
    http_response_code(401);
    exit('Unauthorized');
}

/* Fetch proof + order info */
$stmt = $conn->prepare("
    SELECT p.*, o.buyer_id, o.seller_id
    FROM order_proofs p
    INNER JOIN orders o ON o.order_id = p.order_id
    WHERE p.proof_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $proof_id);
$stmt->execute();
$proof = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$proof) {
    http_response_code(404);
    exit('Not found');
}

/* Authorization */
$is_admin = ($current_role === 'admin');
$is_buyer = ((int) $proof['buyer_id'] === $current_user_id);
$is_seller = ((int) $proof['seller_id'] === $current_user_id);
$is_owner = ((int) $proof['uploader_id'] === $current_user_id);

if (!$is_admin && !$is_buyer && !$is_seller && !$is_owner) {
    http_response_code(403);
    exit('Forbidden');
}

$full_path = ORDER_PROOF_DIR . '/' . $proof['file_path'];

if (!is_file($full_path)) {
    http_response_code(404);
    exit('File missing');
}

/* Extra safety: verify inside dir */
$real = realpath($full_path);
$base = realpath(ORDER_PROOF_DIR);
if (!$real || !$base || strpos($real, $base) !== 0) {
    http_response_code(403);
    exit('Forbidden');
}

$mime = mime_content_type($real) ?: 'application/octet-stream';
$size = filesize($real);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');

/* Range support for video streaming */
if (isset($_SERVER['HTTP_RANGE']) && strpos($mime, 'video/') === 0) {
    if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        $start = (int) $m[1];
        $end = !empty($m[2]) ? (int) $m[2] : $size - 1;
        $end = min($end, $size - 1);
        $length = $end - $start + 1;

        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . $length);

        $fp = fopen($real, 'rb');
        fseek($fp, $start);
        $buffer = 8192;
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $read = min($buffer, $remaining);
            echo fread($fp, $read);
            $remaining -= $read;
        }
        fclose($fp);
        exit;
    }
}

readfile($real);
exit;