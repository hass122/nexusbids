<?php
session_start();
date_default_timezone_set('Asia/Karachi');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../auth/login.php"); exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/kyc-helpers.php";
require_once "../includes/kyc-ai.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: kyc-queue.php"); exit();
}

nb_csrf_check();

$kyc_id = (int) ($_POST['kyc_id'] ?? 0);
if ($kyc_id <= 0) {
    $_SESSION['flash_error'] = 'Invalid KYC.';
    header("Location: kyc-queue.php"); exit();
}

$result = nb_kyc_ai_process($conn, $kyc_id);

if ($result['ok']) {
    $_SESSION['flash_success'] = "AI analysis complete. Score: {$result['score']}% · Recommendation: {$result['recommendation']}";
} else {
    $_SESSION['flash_error'] = 'AI failed: ' . ($result['error'] ?? 'Unknown');
}

header("Location: kyc-detail.php?id=" . $kyc_id);
exit();