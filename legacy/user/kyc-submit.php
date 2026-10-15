<?php
/**
 * NexusBids — Buyer KYC Submit Handler
 * Scope: buyer, AI auto-processed after submit
 */
session_start();
date_default_timezone_set('Asia/Karachi');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    header("Location: ../auth/login.php"); exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: kyc.php"); exit(); }

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/kyc-helpers.php";

nb_csrf_check();
$user_id = (int) $_SESSION['user_id'];

/* Eligibility */
$eligibility = nb_kyc_can_submit($conn, $user_id);
if (!$eligibility['ok']) {
    $_SESSION['kyc_error'] = $eligibility['reason'];
    header("Location: kyc-status.php"); exit();
}

/* Collect inputs */
$full_name = trim((string) ($_POST['full_name_on_cnic'] ?? ''));
$cnic_raw  = trim((string) ($_POST['cnic_number'] ?? ''));
$cnic      = nb_kyc_normalize_cnic($cnic_raw);
$dob       = trim((string) ($_POST['date_of_birth'] ?? ''));
$phone     = trim((string) ($_POST['phone'] ?? ''));
$father    = trim((string) ($_POST['father_name'] ?? ''));
$address   = trim((string) ($_POST['address'] ?? ''));

/* Validate */
$errors = [];
if ($full_name === '' || mb_strlen($full_name) < 3) $errors[] = 'Full name required.';
if ($cnic === '') $errors[] = 'Invalid CNIC format.';
if ($dob === '' || nb_kyc_calculate_age($dob) < nb_kyc_min_age()) $errors[] = 'Must be 18+.';

$file_fields = [
    'cnic_front'       => 'CNIC Front',
    'cnic_back'        => 'CNIC Back',
    'selfie'           => 'Selfie',
    'selfie_with_cnic' => 'Selfie with CNIC',
];

foreach ($file_fields as $f => $l) {
    if (empty($_FILES[$f]) || $_FILES[$f]['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "$l required.";
    } else {
        $v = nb_kyc_validate_image($_FILES[$f]);
        if (!$v['ok']) $errors[] = "$l: " . $v['error'];
    }
}

if (!empty($errors)) {
    $_SESSION['kyc_error'] = implode(' ', $errors);
    header("Location: kyc.php"); exit();
}

/* Transaction */
$saved = [];
$conn->begin_transaction();

try {
    /* Save files */
    foreach ($file_fields as $f => $l) {
        $r = nb_kyc_save_file($_FILES[$f], $user_id, 'buyer_' . $f);
        if (!$r['ok']) throw new RuntimeException("Failed to save $l: " . ($r['error'] ?? 'unknown'));
        $saved[$f] = $r['path'];
    }

    /* Encrypt CNIC */
    $cnic_enc    = nb_kyc_encrypt($cnic);
    $cnic_masked = nb_kyc_mask_cnic($cnic);
    $ip          = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua          = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    /* SQL — buyer scope, seller fields NULL */
    $sql = "INSERT INTO kyc_verifications
        (user_id, kyc_scope, seller_type, full_name_on_cnic, cnic_number_encrypted, cnic_number_masked,
         father_name, date_of_birth, address, phone,
         cnic_front_path, cnic_back_path, selfie_path, selfie_with_cnic_path,
         bank_name, bank_account_holder, bank_account_number, bank_iban, bank_branch,
         business_name, business_type, business_reg_number, ntn_number,
         status, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $params = [
        $user_id,
        'buyer',                        // kyc_scope
        'individual',                   // seller_type (unused)
        $full_name,
        $cnic_enc,
        $cnic_masked,
        $father,
        $dob,
        $address,
        $phone,
        $saved['cnic_front'],
        $saved['cnic_back'],
        $saved['selfie'],
        $saved['selfie_with_cnic'],
        null, null, null, null, null,   // bank fields
        null, null, null, null,         // business fields
        'pending',                      // status
        $ip,
        $ua,
    ];

    /* Auto-generate types */
    $types = '';
    foreach ($params as $i => $v) {
        $types .= ($i === 0) ? 'i' : 's';
    }

    /* Safety checks */
    $placeholder_count = substr_count($sql, '?');
    if ($placeholder_count !== count($params)) {
        throw new RuntimeException("Placeholder/param mismatch: SQL has $placeholder_count, params have " . count($params));
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Prepare failed: ' . $conn->error);

    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new RuntimeException('Execute failed: ' . $stmt->error);

    $kyc_id = (int) $conn->insert_id;
    $stmt->close();

    /* Update user record */
    $upd = $conn->prepare("
        UPDATE users
        SET kyc_status = 'pending',
            kyc_attempts = kyc_attempts + 1,
            kyc_last_attempt_at = NOW()
        WHERE user_id = ?
    ");
    $upd->bind_param("i", $user_id);
    $upd->execute();
    $upd->close();

    /* Audit log */
    nb_kyc_audit($conn, $kyc_id, $user_id, 'submitted', $user_id, 'user', [
        'scope' => 'buyer',
        'cnic_masked' => $cnic_masked,
    ]);

    /* Notify user */
    if (function_exists('nb_notify')) {
        nb_notify($conn, $user_id, 'system', 'KYC Submitted Successfully',
            'Your identity verification is under review. We will update within 1-2 business days.',
            'user/kyc-status.php');
    }

    /* Notify admins */
    try {
        $admins = $conn->query("SELECT user_id FROM users WHERE role = 'admin'");
        if ($admins) {
            while ($a = $admins->fetch_assoc()) {
                if (function_exists('nb_notify')) {
                    nb_notify($conn, (int) $a['user_id'], 'system', 'New Buyer KYC',
                        "Buyer #$user_id submitted KYC for review.",
                        'admin/kyc-detail.php?id=' . $kyc_id);
                }
            }
        }
    } catch (Throwable $e) { /* ignore */ }

    $conn->commit();

    $_SESSION['kyc_success'] = 'KYC submitted! AI is analyzing your documents...';
    header("Location: kyc-status.php");
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    foreach ($saved as $p) @unlink(KYC_UPLOAD_DIR . '/' . $p);
    error_log("buyer kyc submit failed: " . $e->getMessage());
    $_SESSION['kyc_error'] = 'Something went wrong: ' . $e->getMessage();
    header("Location: kyc.php");
    exit();
}