<?php
/**
 * NexusBids — Seller KYC Submit Handler
 * FIXED: Type string ab automatically generate hoti hai.
 */
session_start();
date_default_timezone_set('Asia/Karachi');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'seller') {
    header("Location: ../auth/login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: kyc.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/kyc-helpers.php";

nb_csrf_check();
$seller_id = (int) $_SESSION['user_id'];

/* ---------- Eligibility ---------- */
$eligibility = nb_kyc_can_submit_seller($conn, $seller_id);
if (!$eligibility['ok']) {
    $_SESSION['kyc_error'] = $eligibility['reason'];
    header("Location: kyc-status.php");
    exit();
}

/* ---------- Collect inputs ---------- */
$full_name = trim((string) ($_POST['full_name_on_cnic'] ?? ''));
$cnic_raw = trim((string) ($_POST['cnic_number'] ?? ''));
$cnic = nb_kyc_normalize_cnic($cnic_raw);
$dob = trim((string) ($_POST['date_of_birth'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$father = trim((string) ($_POST['father_name'] ?? ''));
$address = trim((string) ($_POST['address'] ?? ''));

$seller_type = in_array($_POST['seller_type'] ?? '', ['individual', 'business'], true) ? $_POST['seller_type'] : 'individual';
$bank_name = trim((string) ($_POST['bank_name'] ?? ''));
$bank_holder = trim((string) ($_POST['bank_account_holder'] ?? ''));
$bank_number = trim((string) ($_POST['bank_account_number'] ?? ''));
$bank_iban = trim((string) ($_POST['bank_iban'] ?? ''));
$bank_branch = trim((string) ($_POST['bank_branch'] ?? ''));
$biz_name = trim((string) ($_POST['business_name'] ?? ''));
$biz_type = trim((string) ($_POST['business_type'] ?? ''));
$biz_reg = trim((string) ($_POST['business_reg_number'] ?? ''));
$ntn = trim((string) ($_POST['ntn_number'] ?? ''));

/* ---------- Validation ---------- */
$errors = [];
if ($full_name === '' || mb_strlen($full_name) < 3)
    $errors[] = 'Full name required.';
if ($cnic === '')
    $errors[] = 'Invalid CNIC format.';
if ($dob === '' || nb_kyc_calculate_age($dob) < nb_kyc_min_age())
    $errors[] = 'Must be 18+.';
if ($bank_name === '')
    $errors[] = 'Bank name required.';
if ($bank_holder === '')
    $errors[] = 'Account holder required.';
if ($bank_number === '')
    $errors[] = 'Account number required.';

$file_fields = [
    'cnic_front' => 'CNIC Front',
    'cnic_back' => 'CNIC Back',
    'selfie' => 'Selfie',
    'selfie_with_cnic' => 'Selfie with CNIC',
];

foreach ($file_fields as $f => $l) {
    if (empty($_FILES[$f]) || $_FILES[$f]['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "$l required.";
    } else {
        $v = nb_kyc_validate_image($_FILES[$f]);
        if (!$v['ok'])
            $errors[] = "$l: " . $v['error'];
    }
}

if (!empty($errors)) {
    $_SESSION['kyc_error'] = implode(' ', $errors);
    header("Location: kyc.php");
    exit();
}

/* ---------- Transaction ---------- */
$saved = [];
$conn->begin_transaction();

try {
    /* 1. Save files */
    foreach ($file_fields as $f => $l) {
        $r = nb_kyc_save_file($_FILES[$f], $seller_id, 'seller_' . $f);
        if (!$r['ok'])
            throw new RuntimeException("Failed to save $l: " . ($r['error'] ?? 'unknown'));
        $saved[$f] = $r['path'];
    }

    /* 2. Encrypt CNIC */
    $cnic_enc = nb_kyc_encrypt($cnic);
    $cnic_masked = nb_kyc_mask_cnic($cnic);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    /* 3. Build SQL — ALL values as placeholders (no literals) */
    $sql = "INSERT INTO kyc_verifications
        (user_id, kyc_scope, seller_type, full_name_on_cnic, cnic_number_encrypted, cnic_number_masked,
         father_name, date_of_birth, address, phone,
         cnic_front_path, cnic_back_path, selfie_path, selfie_with_cnic_path,
         bank_name, bank_account_holder, bank_account_number, bank_iban, bank_branch,
         business_name, business_type, business_reg_number, ntn_number,
         status, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    /* 4. Params array — 26 items */
    $params = [
        $seller_id,                     // 1  user_id (i)
        'seller',                       // 2  kyc_scope
        $seller_type,                   // 3  seller_type
        $full_name,                     // 4  full_name_on_cnic
        $cnic_enc,                      // 5  cnic_number_encrypted
        $cnic_masked,                   // 6  cnic_number_masked
        $father,                        // 7  father_name
        $dob,                           // 8  date_of_birth
        $address,                       // 9  address
        $phone,                         // 10 phone
        $saved['cnic_front'],           // 11 cnic_front_path
        $saved['cnic_back'],            // 12 cnic_back_path
        $saved['selfie'],               // 13 selfie_path
        $saved['selfie_with_cnic'],     // 14 selfie_with_cnic_path
        $bank_name,                     // 15 bank_name
        $bank_holder,                   // 16 bank_account_holder
        $bank_number,                   // 17 bank_account_number
        $bank_iban,                     // 18 bank_iban
        $bank_branch,                   // 19 bank_branch
        $biz_name,                      // 20 business_name
        $biz_type,                      // 21 business_type
        $biz_reg,                       // 22 business_reg_number
        $ntn,                           // 23 ntn_number
        'pending',                      // 24 status
        $ip,                            // 25 ip_address
        $ua,                            // 26 user_agent
    ];

    /* 5. AUTO-GENERATE type string — never off-by-one again */
    $types = '';
    foreach ($params as $index => $val) {
        // First param (user_id) is int, all others are string
        $types .= ($index === 0) ? 'i' : 's';
    }

    /* 6. Double-check before binding */
    $placeholder_count = substr_count($sql, '?');
    if ($placeholder_count !== count($params)) {
        throw new RuntimeException(
            "Placeholder/param mismatch: SQL has $placeholder_count placeholders, " .
            count($params) . " params provided."
        );
    }
    if (strlen($types) !== count($params)) {
        throw new RuntimeException(
            "Type/param mismatch: " . strlen($types) . " types, " .
            count($params) . " params."
        );
    }

    /* 7. Prepare & bind */
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        throw new RuntimeException('Execute failed: ' . $stmt->error);
    }
    $kyc_id = (int) $conn->insert_id;
    $stmt->close();

    /* 8. Update user */
    $upd = $conn->prepare("UPDATE users SET seller_kyc_status = 'pending' WHERE user_id = ?");
    $upd->bind_param("i", $seller_id);
    $upd->execute();
    $upd->close();

    /* 9. Audit */
    nb_kyc_audit($conn, $kyc_id, $seller_id, 'submitted', $seller_id, 'user', ['scope' => 'seller']);

    /* 10. Notifications */
    if (function_exists('nb_notify')) {
        nb_notify(
            $conn,
            $seller_id,
            'system',
            'Seller KYC Submitted',
            'Your seller verification is under review. We will update within 1-2 business days.',
            'seller/kyc-status.php'
        );

        $admins = $conn->query("SELECT user_id FROM users WHERE role = 'admin'");
        if ($admins) {
            while ($a = $admins->fetch_assoc()) {
                nb_notify(
                    $conn,
                    (int) $a['user_id'],
                    'system',
                    'New Seller KYC',
                    "Seller #$seller_id submitted KYC for review.",
                    'admin/kyc-detail.php?id=' . $kyc_id
                );
            }
        }
    }

    $conn->commit();

    

    $_SESSION['kyc_success'] = 'Seller KYC submitted! AI is analyzing your documents...';
    header("Location: kyc-status.php");
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    foreach ($saved as $p) {
        @unlink(KYC_UPLOAD_DIR . '/' . $p);
    }
    error_log("seller kyc submit failed: " . $e->getMessage());

    // ★ DEBUG - isko hata dein jab sab theek ho jaye
    $_SESSION['kyc_error'] = 'Error: ' . $e->getMessage();
    // Normal version (uncomment jab fix ho jaye):
    // $_SESSION['kyc_error'] = 'Something went wrong. Please try again.';

    header("Location: kyc.php");
    exit();
}