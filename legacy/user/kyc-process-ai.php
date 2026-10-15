<?php
/**
 * AJAX endpoint — Trigger AI processing for a BUYER KYC
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Karachi');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/kyc-helpers.php";
require_once "../includes/kyc-ai.php";

$user_id = (int) $_SESSION['user_id'];
$kyc_id = (int) ($_POST['kyc_id'] ?? 0);

if ($kyc_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid KYC ID']);
    exit;
}

/* Verify ownership + buyer scope */
$stmt = $conn->prepare("SELECT user_id, ai_status, kyc_scope FROM kyc_verifications WHERE kyc_id = ? LIMIT 1");
$stmt->bind_param("i", $kyc_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || (int) $row['user_id'] !== $user_id) {
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

if (($row['kyc_scope'] ?? '') !== 'buyer') {
    echo json_encode(['ok' => false, 'error' => 'Invalid scope']);
    exit;
}

/* Don't re-run if completed */
if ($row['ai_status'] === 'completed') {
    echo json_encode(['ok' => true, 'status' => 'already_completed']);
    exit;
}

/* Run AI */
$result = nb_kyc_ai_process($conn, $kyc_id);

if (!empty($result['ok'])) {
    echo json_encode([
        'ok' => true,
        'status' => 'completed',
        'score' => $result['score'],
        'recommendation' => $result['recommendation'],
    ]);
} else {
    echo json_encode([
        'ok' => false,
        'status' => 'failed',
        'error' => $result['error'] ?? 'AI processing failed',
    ]);
}