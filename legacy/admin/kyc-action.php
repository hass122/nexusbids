<?php
/**
 * NexusBids KYC — Admin Action Handler
 * Handles approve/reject for both Buyer and Seller KYC
 * Also logs AI vs Admin decision for audit
 */
session_start();
date_default_timezone_set('Asia/Karachi');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: kyc-queue.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/kyc-helpers.php";

nb_csrf_check();

$admin_id = (int) $_SESSION['user_id'];
$kyc_id = (int) ($_POST['kyc_id'] ?? 0);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$admin_notes = trim((string) ($_POST['admin_notes'] ?? ''));
$rejection_reason = trim((string) ($_POST['rejection_reason'] ?? ''));

/* ---------- Validation ---------- */
if ($kyc_id <= 0) {
    $_SESSION['flash_error'] = 'Invalid KYC ID.';
    header("Location: kyc-queue.php");
    exit();
}
if (!in_array($action, ['approve', 'reject'], true)) {
    $_SESSION['flash_error'] = 'Invalid action.';
    header("Location: kyc-detail.php?id=" . $kyc_id);
    exit();
}
if ($action === 'reject' && $rejection_reason === '') {
    $_SESSION['flash_error'] = 'Please select a rejection reason.';
    header("Location: kyc-detail.php?id=" . $kyc_id);
    exit();
}
if (mb_strlen($admin_notes) > 500)
    $admin_notes = mb_substr($admin_notes, 0, 500);
if (mb_strlen($rejection_reason) > 255)
    $rejection_reason = mb_substr($rejection_reason, 0, 255);

/* ---------- Fetch KYC (with AI fields) ---------- */
$kyc = null;
$stmt = $conn->prepare("
    SELECT kyc_id, user_id, status, kyc_scope, ai_recommendation, ai_confidence 
    FROM kyc_verifications 
    WHERE kyc_id = ? 
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param("i", $kyc_id);
    $stmt->execute();
    $kyc = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$kyc) {
    $_SESSION['flash_error'] = 'KYC record not found.';
    header("Location: kyc-queue.php");
    exit();
}
if (!in_array($kyc['status'], ['pending', 'under_review'], true)) {
    $_SESSION['flash_error'] = 'This KYC has already been processed.';
    header("Location: kyc-detail.php?id=" . $kyc_id);
    exit();
}

$target_user_id = (int) $kyc['user_id'];
$kyc_scope = $kyc['kyc_scope'] ?? 'buyer';
$ai_rec = $kyc['ai_recommendation'] ?? 'review';
$ai_score = (int) ($kyc['ai_confidence'] ?? 0);

/* ---------- Transaction ---------- */
$conn->begin_transaction();

try {

    /* ═══════════════════════════════════════
       APPROVE
    ═══════════════════════════════════════ */
    if ($action === 'approve') {

        // 1. Update KYC record
        $u1 = $conn->prepare("
            UPDATE kyc_verifications
            SET status = 'approved',
                admin_id = ?,
                admin_notes = ?,
                rejection_reason = NULL,
                reviewed_at = NOW()
            WHERE kyc_id = ?
              AND status IN ('pending', 'under_review')
        ");
        if (!$u1)
            throw new RuntimeException('Prepare failed (approve #1): ' . $conn->error);
        $u1->bind_param("isi", $admin_id, $admin_notes, $kyc_id);
        if (!$u1->execute())
            throw new RuntimeException('Execute failed (approve #1): ' . $u1->error);
        $u1->close();

        // 2. Update user based on scope
        if ($kyc_scope === 'seller') {
            $u2 = $conn->prepare("
                UPDATE users
                SET seller_kyc_status = 'approved',
                    seller_kyc_verified_at = NOW(),
                    seller_kyc_level = 2
                WHERE user_id = ?
            ");
        } else {
            $u2 = $conn->prepare("
                UPDATE users
                SET kyc_status = 'approved',
                    kyc_level = 2,
                    kyc_verified_at = NOW()
                WHERE user_id = ?
            ");
        }
        if (!$u2)
            throw new RuntimeException('Prepare failed (approve #2): ' . $conn->error);
        $u2->bind_param("i", $target_user_id);
        if (!$u2->execute())
            throw new RuntimeException('Execute failed (approve #2): ' . $u2->error);
        $u2->close();

        // 3. Log AI vs Admin decision
        $ai_overridden = ($ai_rec !== 'approve') ? 1 : 0;
        $log_stmt = $conn->prepare("
            UPDATE kyc_verifications 
            SET decision_source = 'admin_after_ai', 
                ai_was_overridden = ?
            WHERE kyc_id = ?
        ");
        if ($log_stmt) {
            $log_stmt->bind_param("ii", $ai_overridden, $kyc_id);
            $log_stmt->execute();
            $log_stmt->close();
        }

        // 4. Audit log
        nb_kyc_audit($conn, $kyc_id, $target_user_id, 'approved', $admin_id, 'admin', [
            'scope' => $kyc_scope,
            'notes' => $admin_notes,
            'ai_recommendation' => $ai_rec,
            'ai_confidence' => $ai_score,
            'ai_overridden' => (bool) $ai_overridden,
        ]);

        // 5. Notify user
        if (function_exists('nb_notify')) {
            try {
                nb_notify(
                    $conn,
                    $target_user_id,
                    'system',
                    $kyc_scope === 'seller' ? 'Seller KYC Approved ✓' : 'Identity Verified ✓',
                    $kyc_scope === 'seller'
                    ? 'Your seller verification is approved. You can now receive payouts and list unlimited items.'
                    : 'Your KYC has been approved. You now have full bidding access.',
                    $kyc_scope === 'seller' ? 'seller/kyc-status.php' : 'user/kyc-status.php'
                );
            } catch (Throwable $e) {
                error_log('KYC notify (approve) failed: ' . $e->getMessage());
            }
        }

        $conn->commit();

        $_SESSION['flash_success'] = 'KYC approved successfully.'
            . ($ai_overridden ? ' (AI recommendation was overridden)' : '');
        header("Location: kyc-detail.php?id=" . $kyc_id);
        exit();
    }

    /* ═══════════════════════════════════════
       REJECT
    ═══════════════════════════════════════ */
    if ($action === 'reject') {

        // 1. Update KYC record
        $u1 = $conn->prepare("
            UPDATE kyc_verifications
            SET status = 'rejected',
                admin_id = ?,
                admin_notes = ?,
                rejection_reason = ?,
                reviewed_at = NOW()
            WHERE kyc_id = ?
              AND status IN ('pending', 'under_review')
        ");
        if (!$u1)
            throw new RuntimeException('Prepare failed (reject #1): ' . $conn->error);
        $u1->bind_param("issi", $admin_id, $admin_notes, $rejection_reason, $kyc_id);
        if (!$u1->execute())
            throw new RuntimeException('Execute failed (reject #1): ' . $u1->error);
        $u1->close();

        // 2. Update user
        if ($kyc_scope === 'seller') {
            $u2 = $conn->prepare("UPDATE users SET seller_kyc_status = 'rejected' WHERE user_id = ?");
        } else {
            $u2 = $conn->prepare("UPDATE users SET kyc_status = 'rejected' WHERE user_id = ?");
        }
        if (!$u2)
            throw new RuntimeException('Prepare failed (reject #2): ' . $conn->error);
        $u2->bind_param("i", $target_user_id);
        if (!$u2->execute())
            throw new RuntimeException('Execute failed (reject #2): ' . $u2->error);
        $u2->close();

        // 3. Log AI vs Admin decision
        $ai_overridden = ($ai_rec !== 'reject') ? 1 : 0;
        $log_stmt = $conn->prepare("
            UPDATE kyc_verifications 
            SET decision_source = 'admin_after_ai', 
                ai_was_overridden = ?
            WHERE kyc_id = ?
        ");
        if ($log_stmt) {
            $log_stmt->bind_param("ii", $ai_overridden, $kyc_id);
            $log_stmt->execute();
            $log_stmt->close();
        }

        // 4. Audit log
        nb_kyc_audit($conn, $kyc_id, $target_user_id, 'rejected', $admin_id, 'admin', [
            'scope' => $kyc_scope,
            'reason' => $rejection_reason,
            'notes' => $admin_notes,
            'ai_recommendation' => $ai_rec,
            'ai_confidence' => $ai_score,
            'ai_overridden' => (bool) $ai_overridden,
        ]);

        // 5. Notify user
        if (function_exists('nb_notify')) {
            try {
                nb_notify(
                    $conn,
                    $target_user_id,
                    'system',
                    'KYC Verification Needs Attention',
                    'Your KYC was not approved. Reason: ' . $rejection_reason
                    . '. You may resubmit with corrected documents.',
                    $kyc_scope === 'seller' ? 'seller/kyc-status.php' : 'user/kyc-status.php'
                );
            } catch (Throwable $e) {
                error_log('KYC notify (reject) failed: ' . $e->getMessage());
            }
        }

        $conn->commit();

        $_SESSION['flash_success'] = 'KYC rejected and user notified.'
            . ($ai_overridden ? ' (AI recommendation was overridden)' : '');
        header("Location: kyc-detail.php?id=" . $kyc_id);
        exit();
    }

    throw new RuntimeException('Unhandled action.');

} catch (Throwable $e) {
    $conn->rollback();
    error_log('KYC action failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Action failed: ' . $e->getMessage();
    header("Location: kyc-detail.php?id=" . $kyc_id);
    exit();
}