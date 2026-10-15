<?php
/**
 * NexusBids — Admin Escrow Release / Hold / Reject
 */
session_start();
date_default_timezone_set('Asia/Karachi');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: order-verifications.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/order-proofs.php";

nb_csrf_check();

$admin_id = (int) $_SESSION['user_id'];
$order_id = (int) ($_POST['order_id'] ?? 0);
$action = $_POST['action'] ?? '';
$admin_notes = trim($_POST['admin_notes'] ?? '');

if ($order_id <= 0 || !in_array($action, ['approve', 'hold', 'reject'], true)) {
    $_SESSION['flash_error'] = 'Invalid action.';
    header("Location: order-verifications.php");
    exit();
}

/* Fetch order */
$stmt = $conn->prepare("
    SELECT o.*, a.title AS auction_title, a.auction_id,
           s.full_name AS seller_name, s.user_id AS seller_user_id,
           b.full_name AS buyer_name, b.user_id AS buyer_user_id
    FROM orders o
    INNER JOIN auctions a ON o.auction_id = a.auction_id
    INNER JOIN users s ON o.seller_id = s.user_id
    INNER JOIN users b ON o.buyer_id = b.user_id
    WHERE o.order_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    $_SESSION['flash_error'] = 'Order not found.';
    header("Location: order-verifications.php");
    exit();
}

if (!in_array($order['admin_review_status'], ['pending', 'reviewing'], true)) {
    $_SESSION['flash_error'] = 'This order has already been reviewed.';
    header("Location: order-proof-review.php?id=" . $order_id);
    exit();
}

$conn->begin_transaction();

try {
    if ($action === 'approve') {
        /* ✅ APPROVE — Release escrow */
        $new_status = 'approved';
        $order_update = "completed";

        /* Update order */
        $upd = $conn->prepare("
            UPDATE orders
            SET admin_review_status = 'approved',
                admin_reviewed_at = NOW(),
                admin_reviewer_id = ?,
                admin_review_notes = ?,
                escrow_released_at = NOW(),
                escrow_released_by = ?,
                order_status = 'completed',
                completed_at = COALESCE(completed_at, NOW())
            WHERE order_id = ?
        ");
        $upd->bind_param("isii", $admin_id, $admin_notes, $admin_id, $order_id);
        $upd->execute();
        $upd->close();

        /* Update escrow hold if exists */
        try {
            $esc = $conn->prepare("UPDATE escrow_holds SET status = 'released', released_at = NOW() WHERE order_id = ? AND status IN ('held', 'partially_released')");
            $esc->bind_param("i", $order_id);
            $esc->execute();
            $esc->close();
        } catch (Throwable $e) {
        }

        /* Ledger entry */
        try {
            if (file_exists(__DIR__ . '/../includes/ledger.php')) {
                require_once "../includes/ledger.php";
                if (function_exists('nb_ledger_add')) {
                    nb_ledger_add($conn, [
                        'seller_id' => (int) $order['seller_user_id'],
                        'order_id' => $order_id,
                        'entry_type' => 'release',
                        'entry_category' => 'escrow_release',
                        'amount' => (float) $order['seller_amount'],
                        'status' => 'completed',
                        'description' => 'Escrow released by admin — Order ' . $order['order_number'],
                    ]);
                }
            }
        } catch (Throwable $e) {
        }

        /* Notify seller */
        nb_notify(
            $conn,
            (int) $order['seller_user_id'],
            'payout',
            '💰 Payment Released!',
            'Rs. ' . number_format((float) $order['seller_amount']) . ' has been released for order ' . $order['order_number'] . '. It will be added to your available balance.',
            'seller/earnings.php'
        );

        /* Notify buyer */
        nb_notify(
            $conn,
            (int) $order['buyer_user_id'],
            'order',
            'Order Completed',
            'Your order ' . $order['order_number'] . ' has been verified by admin. Thank you for using NexusBids!',
            'user/order-details.php?id=' . $order['auction_id']
        );

        nb_proof_audit($conn, $order_id, null, 'admin.approved', $admin_id, 'admin', [
            'amount' => (float) $order['seller_amount'],
            'notes' => $admin_notes,
        ]);

        $conn->commit();
        $_SESSION['flash_success'] = 'Payment released to seller! Rs. ' . number_format((float) $order['seller_amount']) . ' — order marked completed.';
        header("Location: order-proof-review.php?id=" . $order_id);
        exit();

    } elseif ($action === 'hold') {
        /* ⏸️ HOLD — Under investigation */
        $upd = $conn->prepare("
            UPDATE orders
            SET admin_review_status = 'disputed',
                admin_review_notes = ?,
                admin_reviewer_id = ?
            WHERE order_id = ?
        ");
        $upd->bind_param("sii", $admin_notes, $admin_id, $order_id);
        $upd->execute();
        $upd->close();

        /* Notify both parties */
        nb_notify(
            $conn,
            (int) $order['seller_user_id'],
            'dispute',
            'Order Under Investigation',
            'Order ' . $order['order_number'] . ' is being reviewed by admin. Please be patient.',
            'seller/orders.php'
        );

        nb_notify(
            $conn,
            (int) $order['buyer_user_id'],
            'dispute',
            'Order Under Investigation',
            'Order ' . $order['order_number'] . ' is being reviewed by admin. Please be patient.',
            'user/order-details.php?id=' . $order['auction_id']
        );

        nb_proof_audit($conn, $order_id, null, 'admin.held', $admin_id, 'admin', [
            'notes' => $admin_notes,
        ]);

        $conn->commit();
        $_SESSION['flash_success'] = 'Order placed on hold. Both parties notified.';
        header("Location: order-proof-review.php?id=" . $order_id);
        exit();

    } elseif ($action === 'reject') {
        /* ❌ REJECT — Refund buyer */
        $upd = $conn->prepare("
            UPDATE orders
            SET admin_review_status = 'rejected',
                admin_reviewed_at = NOW(),
                admin_reviewer_id = ?,
                admin_review_notes = ?,
                order_status = 'refunded'
            WHERE order_id = ?
        ");
        $upd->bind_param("isi", $admin_id, $admin_notes, $order_id);
        $upd->execute();
        $upd->close();

        /* Update escrow hold if exists */
        try {
            $esc = $conn->prepare("UPDATE escrow_holds SET status = 'refunded', refunded_at = NOW() WHERE order_id = ? AND status IN ('held', 'partially_released')");
            $esc->bind_param("i", $order_id);
            $esc->execute();
            $esc->close();
        } catch (Throwable $e) {
        }

        /* Ledger — refund to buyer */
        try {
            if (file_exists(__DIR__ . '/../includes/ledger.php')) {
                require_once "../includes/ledger.php";
                if (function_exists('nb_ledger_add')) {
                    nb_ledger_add($conn, [
                        'seller_id' => (int) $order['seller_user_id'],
                        'order_id' => $order_id,
                        'entry_type' => 'refund',
                        'entry_category' => 'buyer_refund',
                        'amount' => (float) $order['seller_amount'],
                        'status' => 'refunded',
                        'description' => 'Admin rejected order — Refund to buyer',
                    ]);
                }
            }
        } catch (Throwable $e) {
        }

        /* Notify seller */
        nb_notify(
            $conn,
            (int) $order['seller_user_id'],
            'dispute',
            'Order Rejected by Admin',
            'Order ' . $order['order_number'] . ' was rejected. Reason: ' . ($admin_notes ?: 'See admin panel.'),
            'seller/orders.php'
        );

        /* Notify buyer */
        nb_notify(
            $conn,
            (int) $order['buyer_user_id'],
            'order',
            'Refund Approved',
            'Your order ' . $order['order_number'] . ' has been rejected. Refund has been processed.',
            'user/order-details.php?id=' . $order['auction_id']
        );

        nb_proof_audit($conn, $order_id, null, 'admin.rejected', $admin_id, 'admin', [
            'notes' => $admin_notes,
        ]);

        $conn->commit();
        $_SESSION['flash_success'] = 'Order rejected. Buyer refund initiated.';
        header("Location: order-proof-review.php?id=" . $order_id);
        exit();
    }

    throw new RuntimeException('Unhandled action.');

} catch (Throwable $e) {
    $conn->rollback();
    error_log('release-escrow failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Action failed: ' . $e->getMessage();
    header("Location: order-proof-review.php?id=" . $order_id);
    exit();
}