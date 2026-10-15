<?php
/**
 * NexusBids KYC Helpers
 * Buyer + Seller verification
 * 
 * Features:
 * - AES-256 encryption for CNIC
 * - Auto-resize images (downscale > 1600px, upscale < 300px)
 * - File validation with fallback
 * - Audit logging
 * - Buyer + Seller eligibility checks
 */

if (!defined('KYC_UPLOAD_DIR')) {
    define('KYC_UPLOAD_DIR', __DIR__ . '/../uploads/kyc');
}
if (!defined('KYC_UPLOAD_URL')) {
    define('KYC_UPLOAD_URL', 'uploads/kyc');
}

/* =========================================================
   ENCRYPTION (CNIC number)
========================================================= */
function nb_kyc_get_key(): string
{
    $key = getenv('KYC_ENCRYPTION_KEY') ?: ($_ENV['KYC_ENCRYPTION_KEY'] ?? '');
    if ($key === '') {
        $key = hash('sha256', 'nexusbids_kyc_fallback_' . (getenv('DB_NAME') ?: 'nexusbids'));
    }
    return hash('sha256', $key, true);
}

function nb_kyc_encrypt(string $plaintext): string
{
    $key = nb_kyc_get_key();
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) return '';
    return base64_encode($iv . $cipher);
}

function nb_kyc_decrypt(string $encoded): string
{
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $key = nb_kyc_get_key();
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

function nb_kyc_mask_cnic(string $cnic): string
{
    $digits = preg_replace('/\D/', '', $cnic);
    if (strlen($digits) < 13) return '*****-*******-*';
    return substr($digits, 0, 5) . '-*******-' . substr($digits, -1);
}

/* =========================================================
   VALIDATION
========================================================= */
function nb_kyc_cnic_format_valid(string $cnic): bool
{
    $digits = preg_replace('/\D/', '', $cnic);
    return strlen($digits) === 13;
}

function nb_kyc_normalize_cnic(string $cnic): string
{
    $d = preg_replace('/\D/', '', $cnic);
    if (strlen($d) !== 13) return '';
    return substr($d, 0, 5) . '-' . substr($d, 5, 7) . '-' . substr($d, 12, 1);
}

function nb_kyc_calculate_age(string $dob): int
{
    try {
        $birth = new DateTime($dob);
        $now = new DateTime();
        return $now->diff($birth)->y;
    } catch (Throwable $e) {
        return 0;
    }
}

function nb_kyc_min_age(): int
{
    return 18;
}

/* =========================================================
   SETTINGS
========================================================= */
function nb_kyc_get_setting(mysqli $conn, string $key, $default = null)
{
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $conn->prepare("SELECT setting_value FROM kyc_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cache[$key] = $row['setting_value'] ?? $default;
    return $cache[$key];
}

/* =========================================================
   QUERIES
========================================================= */
function nb_kyc_get_latest(mysqli $conn, int $user_id): ?array
{
    $stmt = $conn->prepare("
        SELECT k.*, u.full_name AS user_name, u.email AS user_email
        FROM kyc_verifications k
        INNER JOIN users u ON u.user_id = k.user_id
        WHERE k.user_id = ?
        ORDER BY k.kyc_id DESC
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function nb_kyc_user_status(mysqli $conn, int $user_id): string
{
    $stmt = $conn->prepare("SELECT kyc_status FROM users WHERE user_id = ? LIMIT 1");
    if (!$stmt) return 'unverified';
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['kyc_status'] ?? 'unverified';
}

function nb_kyc_can_submit(mysqli $conn, int $user_id): array
{
    $max = (int) nb_kyc_get_setting($conn, 'max_attempts_per_month', 3);
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c FROM kyc_verifications
        WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    if ($count >= $max) {
        return ['ok' => false, 'reason' => "You've reached the maximum of $max attempts in 30 days."];
    }

    $status = nb_kyc_user_status($conn, $user_id);
    if ($status === 'approved') return ['ok' => false, 'reason' => 'Already verified.'];
    if ($status === 'pending')  return ['ok' => false, 'reason' => 'Pending review.'];

    return ['ok' => true, 'reason' => ''];
}

/* ⭐ SELLER-specific eligibility */
function nb_kyc_can_submit_seller(mysqli $conn, int $user_id): array
{
    $max = (int) nb_kyc_get_setting($conn, 'max_attempts_per_month', 3);
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c FROM kyc_verifications
        WHERE user_id = ? AND kyc_scope = 'seller'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    if ($count >= $max) {
        return ['ok' => false, 'reason' => "Max $max attempts in 30 days. Contact support."];
    }

    $s = $conn->prepare("SELECT seller_kyc_status FROM users WHERE user_id = ? LIMIT 1");
    $s->bind_param("i", $user_id);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    $status = $row['seller_kyc_status'] ?? 'unverified';

    if ($status === 'approved') return ['ok' => false, 'reason' => 'Already verified.'];
    if ($status === 'pending')  return ['ok' => false, 'reason' => 'KYC already pending review.'];

    return ['ok' => true, 'reason' => ''];
}

/* =========================================================
   FILE UPLOAD — Auto-Resize Enabled
========================================================= */

/**
 * Validate uploaded image (loose validation — resize happens later)
 */
function nb_kyc_validate_image(array $file): array
{
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed. Please try again.'];
    }

    // Max raw file size 10MB (before resize)
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'File too large (max 10MB). Please compress the image.'];
    }

    $mime = mime_content_type($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, or WEBP images are allowed.'];
    }

    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        return ['ok' => false, 'error' => 'Invalid or corrupted image file.'];
    }

    // Only reject extremely tiny images (unreadable)
    if ($info[0] < 150 || $info[1] < 150) {
        return ['ok' => false, 'error' => 'Image too small (min 150×150px). Please upload a clearer photo.'];
    }

    // No upper limit — auto-resize will handle it
    return [
        'ok'     => true,
        'ext'    => $allowed[$mime],
        'mime'   => $mime,
        'width'  => $info[0],
        'height' => $info[1],
    ];
}

/**
 * Save file with automatic resize
 * - Downscale if > 1600px (preserves aspect ratio)
 * - Upscale if < 300px (min side)
 * - Compress to JPEG @ 85% quality
 * - Fallback to original if GD unavailable
 */
function nb_kyc_save_file(array $file, int $user_id, string $type): array
{
    $validation = nb_kyc_validate_image($file);
    if (!$validation['ok']) return $validation;

    $user_dir = KYC_UPLOAD_DIR . '/' . $user_id;
    if (!is_dir($user_dir)) {
        if (!mkdir($user_dir, 0755, true)) {
            return ['ok' => false, 'error' => 'Cannot create upload directory.'];
        }
    }

    $hash = bin2hex(random_bytes(8));

    // Try GD-based resize first (always save as .jpg)
    if (function_exists('imagecreatetruecolor')) {
        $filename = $type . '_' . time() . '_' . $hash . '.jpg';
        $full_path = $user_dir . '/' . $filename;

        $result = nb_kyc_resize_and_save($file['tmp_name'], $full_path, $validation['mime']);
        if ($result['ok']) {
            $rel_path = $user_id . '/' . $filename;
            return ['ok' => true, 'path' => $rel_path, 'full' => $full_path];
        }
        // If resize failed, fall through to direct save
        if (is_file($full_path)) @unlink($full_path);
    }

    // Fallback: save original as-is
    $ext = $validation['ext'];
    $filename = $type . '_' . time() . '_' . $hash . '.' . $ext;
    $full_path = $user_dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $full_path)) {
        return ['ok' => false, 'error' => 'Failed to save file. Check folder permissions.'];
    }

    $rel_path = $user_id . '/' . $filename;
    return ['ok' => true, 'path' => $rel_path, 'full' => $full_path];
}

/**
 * Resize image using GD library
 * - Max dimension: 1600px (downscale)
 * - Min dimension: 300px (upscale)
 * - Aspect ratio preserved
 * - Saves as JPEG @ 85% quality
 */
function nb_kyc_resize_and_save(string $src_path, string $dst_path, string $mime): array
{
    // Load source image
    switch ($mime) {
        case 'image/jpeg':
            $src = @imagecreatefromjpeg($src_path);
            break;
        case 'image/png':
            $src = @imagecreatefrompng($src_path);
            break;
        case 'image/webp':
            $src = @imagecreatefromwebp($src_path);
            break;
        default:
            return ['ok' => false, 'error' => 'Unsupported mime type.'];
    }

    if (!$src) {
        return ['ok' => false, 'error' => 'Cannot load image (corrupted?).'];
    }

    $orig_w = imagesx($src);
    $orig_h = imagesy($src);

    $max_dim = 1600;
    $min_dim = 300;

    $target_w = $orig_w;
    $target_h = $orig_h;

    // Downscale if too large
    if ($orig_w > $max_dim || $orig_h > $max_dim) {
        $ratio = min($max_dim / $orig_w, $max_dim / $orig_h);
        $target_w = (int) round($orig_w * $ratio);
        $target_h = (int) round($orig_h * $ratio);
    }

    // Upscale if too small (both sides < min)
    if ($target_w < $min_dim && $target_h < $min_dim) {
        $ratio = max($min_dim / $target_w, $min_dim / $target_h);
        $target_w = (int) round($target_w * $ratio);
        $target_h = (int) round($target_h * $ratio);
    } elseif ($target_w < $min_dim) {
        $ratio = $min_dim / $target_w;
        $target_w = $min_dim;
        $target_h = (int) round($target_h * $ratio);
    } elseif ($target_h < $min_dim) {
        $ratio = $min_dim / $target_h;
        $target_h = $min_dim;
        $target_w = (int) round($target_w * $ratio);
    }

    // Create destination
    $dst = imagecreatetruecolor($target_w, $target_h);

    // White background (for transparency)
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $target_w, $target_h, $white);

    // Resample with smooth quality
    imagecopyresampled(
        $dst, $src,
        0, 0, 0, 0,
        $target_w, $target_h,
        $orig_w, $orig_h
    );

    // Save as JPEG
    $saved = imagejpeg($dst, $dst_path, 85);

    imagedestroy($src);
    imagedestroy($dst);

    if (!$saved) {
        return ['ok' => false, 'error' => 'Failed to write resized image.'];
    }

    return ['ok' => true];
}

/**
 * Delete all files for a user (used on re-submit or cleanup)
 */
function nb_kyc_delete_files(int $user_id): void
{
    $dir = KYC_UPLOAD_DIR . '/' . $user_id;
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*') as $f) {
        if (is_file($f)) @unlink($f);
    }
}

/* =========================================================
   AUDIT LOG
========================================================= */
function nb_kyc_audit(
    mysqli $conn,
    int $kyc_id,
    int $user_id,
    string $action,
    ?int $actor_id = null,
    string $actor_type = 'system',
    ?array $details = null
): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;

    $stmt = $conn->prepare("
        INSERT INTO kyc_audit_log (kyc_id, user_id, action, actor_id, actor_type, details, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) return;
    $stmt->bind_param("iisssss", $kyc_id, $user_id, $action, $actor_id, $actor_type, $json, $ip);
    $stmt->execute();
    $stmt->close();
}

/* =========================================================
   UI HELPERS
========================================================= */
function nb_kyc_status_meta(string $status): array
{
    return match ($status) {
        'approved' => [
            'label'  => 'Verified',
            'bg'     => 'bg-emerald-500/10',
            'text'   => 'text-emerald-700 dark:text-emerald-400',
            'border' => 'border-emerald-500/20',
            'icon'   => 'fa-circle-check',
            'accent' => 'bg-emerald-500',
        ],
        'pending' => [
            'label'  => 'Pending Review',
            'bg'     => 'bg-amber-500/10',
            'text'   => 'text-amber-700 dark:text-amber-400',
            'border' => 'border-amber-500/20',
            'icon'   => 'fa-hourglass-half',
            'accent' => 'bg-amber-500',
        ],
        'under_review' => [
            'label'  => 'Under Review',
            'bg'     => 'bg-blue-500/10',
            'text'   => 'text-blue-700 dark:text-blue-400',
            'border' => 'border-blue-500/20',
            'icon'   => 'fa-magnifying-glass',
            'accent' => 'bg-blue-500',
        ],
        'rejected' => [
            'label'  => 'Rejected',
            'bg'     => 'bg-rose-500/10',
            'text'   => 'text-rose-700 dark:text-rose-400',
            'border' => 'border-rose-500/20',
            'icon'   => 'fa-circle-xmark',
            'accent' => 'bg-rose-500',
        ],
        default => [
            'label'  => 'Not Verified',
            'bg'     => 'bg-gray-500/10',
            'text'   => 'text-gray-700 dark:text-gray-400',
            'border' => 'border-gray-500/20',
            'icon'   => 'fa-circle-question',
            'accent' => 'bg-gray-500',
        ],
    };
}

function nb_kyc_kyc_level_label(int $level): string
{
    return match ($level) {
        1       => 'Basic',
        2       => 'Standard',
        3       => 'Full',
        default => 'None',
    };
}