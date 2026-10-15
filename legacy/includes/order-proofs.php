<?php
/**
 * NexusBids — Order Proof Helpers
 * Handles video/photo uploads for seller shipping proof & buyer receiving proof
 */

if (!defined('ORDER_PROOF_DIR')) {
    define('ORDER_PROOF_DIR', __DIR__ . '/../uploads/order-proofs');
}
if (!defined('ORDER_PROOF_URL')) {
    define('ORDER_PROOF_URL', 'uploads/order-proofs');
}

/* =========================================================
   VALIDATE UPLOAD
========================================================= */
function nb_proof_validate(array $file): array
{
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed.'];
    }

    if ($file['size'] > 50 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'File too large (max 50MB).'];
    }

    $mime = mime_content_type($file['tmp_name']);
    $allowed = [
        'image/jpeg' => ['ext' => 'jpg', 'type' => 'image'],
        'image/png' => ['ext' => 'png', 'type' => 'image'],
        'image/webp' => ['ext' => 'webp', 'type' => 'image'],
        'video/mp4' => ['ext' => 'mp4', 'type' => 'video'],
        'video/webm' => ['ext' => 'webm', 'type' => 'video'],
        'video/quicktime' => ['ext' => 'mov', 'type' => 'video'],
    ];

    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, WEBP images or MP4, WEBM, MOV videos allowed.'];
    }

    return [
        'ok' => true,
        'ext' => $allowed[$mime]['ext'],
        'file_type' => $allowed[$mime]['type'],
        'mime' => $mime,
    ];
}

/* =========================================================
   SAVE FILE
========================================================= */
function nb_proof_save(array $file, int $order_id, int $uploader_id, string $proof_type): array
{
    $val = nb_proof_validate($file);
    if (!$val['ok'])
        return $val;

    $order_dir = ORDER_PROOF_DIR . '/' . $order_id;
    if (!is_dir($order_dir)) {
        if (!mkdir($order_dir, 0755, true)) {
            return ['ok' => false, 'error' => 'Cannot create upload directory.'];
        }
    }

    $hash = bin2hex(random_bytes(8));
    $filename = $proof_type . '_' . $uploader_id . '_' . time() . '_' . $hash . '.' . $val['ext'];
    $full_path = $order_dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $full_path)) {
        return ['ok' => false, 'error' => 'Failed to save file.'];
    }

    return [
        'ok' => true,
        'rel_path' => $order_id . '/' . $filename,
        'full_path' => $full_path,
        'file_type' => $val['file_type'],
        'file_size' => (int) $file['size'],
        'mime_type' => $val['mime'],
        'original_name' => $file['name'] ?? '',
    ];
}

/* =========================================================
   INSERT DB ROW
========================================================= */
function nb_proof_insert(
    mysqli $conn,
    int $order_id,
    int $uploader_id,
    string $uploader_type,
    string $proof_type,
    array $file_data,
    ?string $notes = null
): ?int {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    $stmt = $conn->prepare("
        INSERT INTO order_proofs
            (order_id, uploader_id, uploader_type, proof_type,
             file_path, original_name, file_type, file_size, mime_type,
             notes, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt)
        return null;

    $stmt->bind_param(
        "iisssssissss",
        $order_id,
        $uploader_id,
        $uploader_type,
        $proof_type,
        $file_data['rel_path'],
        $file_data['original_name'],
        $file_data['file_type'],
        $file_data['file_size'],
        $file_data['mime_type'],
        $notes,
        $ip,
        $ua
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $id = (int) $conn->insert_id;
    $stmt->close();
    return $id;
}

/* =========================================================
   LIST PROOFS
========================================================= */
function nb_proof_list(mysqli $conn, int $order_id, ?string $uploader_type = null): array
{
    if ($uploader_type) {
        $stmt = $conn->prepare("
            SELECT p.*, u.full_name AS uploader_name
            FROM order_proofs p
            INNER JOIN users u ON u.user_id = p.uploader_id
            WHERE p.order_id = ? AND p.uploader_type = ?
            ORDER BY p.created_at ASC
        ");
        $stmt->bind_param("is", $order_id, $uploader_type);
    } else {
        $stmt = $conn->prepare("
            SELECT p.*, u.full_name AS uploader_name
            FROM order_proofs p
            INNER JOIN users u ON u.user_id = p.uploader_id
            WHERE p.order_id = ?
            ORDER BY p.uploader_type ASC, p.created_at ASC
        ");
        $stmt->bind_param("i", $order_id);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/* =========================================================
   COUNT PROOFS
========================================================= */
function nb_proof_count(mysqli $conn, int $order_id, ?string $uploader_type = null): int
{
    if ($uploader_type) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS t FROM order_proofs WHERE order_id = ? AND uploader_type = ?");
        $stmt->bind_param("is", $order_id, $uploader_type);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS t FROM order_proofs WHERE order_id = ?");
        $stmt->bind_param("i", $order_id);
    }
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_assoc()['t'];
    $stmt->close();
    return $count;
}

/* =========================================================
   AUDIT LOG
========================================================= */
function nb_proof_audit(
    mysqli $conn,
    int $order_id,
    ?int $proof_id,
    string $action,
    ?int $actor_id,
    string $actor_type = 'system',
    ?array $details = null
): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;

    $stmt = $conn->prepare("
        INSERT INTO order_proof_audit
            (order_id, proof_id, action, actor_id, actor_type, details, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt)
        return;
    $stmt->bind_param("iisssss", $order_id, $proof_id, $action, $actor_id, $actor_type, $json, $ip);
    $stmt->execute();
    $stmt->close();
}

/* =========================================================
   SECURE URL HELPER
========================================================= */
function nb_proof_url(int $proof_id): string
{
    return 'order-media.php?id=' . $proof_id;
}

/* =========================================================
   DELETE FILES (cleanup on re-upload)
========================================================= */
function nb_proof_delete_files(int $order_id): void
{
    $dir = ORDER_PROOF_DIR . '/' . $order_id;
    if (!is_dir($dir))
        return;
    foreach (glob($dir . '/*') as $f) {
        if (is_file($f))
            @unlink($f);
    }
}

/* =========================================================
   PROOF TYPE METADATA
========================================================= */
function nb_proof_type_meta(string $type): array
{
    return match ($type) {
        'packing_video' => ['label' => 'Packing Video', 'icon' => 'fa-video', 'color' => 'indigo'],
        'item_photo' => ['label' => 'Item Photo', 'icon' => 'fa-camera', 'color' => 'blue'],
        'courier_receipt' => ['label' => 'Courier Receipt', 'icon' => 'fa-receipt', 'color' => 'violet'],
        'unboxing_video' => ['label' => 'Unboxing Video', 'icon' => 'fa-video', 'color' => 'emerald'],
        'received_photo' => ['label' => 'Received Photo', 'icon' => 'fa-box-open', 'color' => 'teal'],
        'other' => ['label' => 'Other Proof', 'icon' => 'fa-paperclip', 'color' => 'gray'],
        default => ['label' => 'Proof', 'icon' => 'fa-file', 'color' => 'gray'],
    };
}