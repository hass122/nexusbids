<?php
/* =========================================================
   admin/payments.php — Payments Verification (Updated)
   - Approve → marks paid + escrow auto-create (safety net)
   - Reject  → buyer notified with reason
   - Uses nb_pay_audit + ledger helpers
   ========================================================= */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set("Asia/Karachi");

/* Admin auth */
if (
    empty($_SESSION["user_id"]) ||
    empty($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/payment-helpers.php";
require_once "../includes/ledger.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$allowed_statuses = ["pending", "processing", "paid", "failed", "refunded"];
$admin_id = (int) $_SESSION["user_id"];

/* =========================================================
   POST HANDLER
   ========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    nb_csrf_check();

    $payment_id = (int) ($_POST["payment_id"] ?? 0);
    $action = trim($_POST["action"] ?? "");
    $note = trim($_POST["note"] ?? "");

    /* ---------- Approve / Reject ---------- */
    if ($payment_id > 0 && in_array($action, ['approve', 'reject'], true)) {

        $p = null;
        if (
            $pstmt = $conn->prepare("
            SELECT
                p.payment_id, p.payment_status, p.transaction_id, p.amount,
                p.payment_method, p.auction_id, p.user_id AS payer_id,
                o.order_id, o.buyer_id, o.seller_id, o.order_number,
                a.title AS auction_title
            FROM payments p
            LEFT JOIN orders   o ON o.order_id = p.order_id
            LEFT JOIN auctions a ON a.auction_id = p.auction_id
            WHERE p.payment_id = ?
            LIMIT 1
        ")
        ) {
            $pstmt->bind_param("i", $payment_id);
            $pstmt->execute();
            $p = $pstmt->get_result()->fetch_assoc();
            $pstmt->close();
        }

        if (!$p) {
            $_SESSION['payment_flash_error'] = "Payment #{$payment_id} not found.";
            header("Location: payments.php");
            exit();
        }

        if (!in_array($p['payment_status'], ['pending', 'processing'], true)) {
            $_SESSION['payment_flash_error'] =
                "Payment #{$payment_id} is already marked as " . $p['payment_status'] . ".";
            header("Location: payments.php");
            exit();
        }

        $conn->begin_transaction();
        try {
            if ($action === 'approve') {
                $txn_ref = !empty($p['transaction_id'])
                    ? $p['transaction_id']
                    : ('ADMIN-' . $admin_id . '-' . time());

                if (function_exists('nb_payment_mark_paid')) {
                    $result = nb_payment_mark_paid(
                        $conn,
                        $payment_id,
                        $txn_ref,
                        json_encode(['verified_by' => $admin_id, 'verified_at' => date('c'), 'note' => $note])
                    );
                    if (empty($result['ok'])) {
                        throw new RuntimeException($result['message'] ?? 'Mark paid failed');
                    }
                } else {
                    $upd = $conn->prepare("UPDATE payments SET payment_status = 'paid' WHERE payment_id = ?");
                    $upd->bind_param("i", $payment_id);
                    $upd->execute();
                    $upd->close();

                    if (!empty($p['order_id'])) {
                        $upd = $conn->prepare("UPDATE orders SET payment_status = 'paid', order_status = 'processing', paid_at = NOW() WHERE order_id = ?");
                        $upd->bind_param("i", $p['order_id']);
                        $upd->execute();
                        $upd->close();
                    }
                    if (!empty($p['auction_id']) && !empty($p['buyer_id'])) {
                        $upd = $conn->prepare("UPDATE winners SET status = 'paid' WHERE auction_id = ? AND user_id = ?");
                        $upd->bind_param("ii", $p['auction_id'], $p['buyer_id']);
                        $upd->execute();
                        $upd->close();
                    }
                }

                try {
                    if (function_exists('nb_payment_log')) {
                        nb_payment_log(
                            $conn,
                            $payment_id,
                            'paid',
                            'Approved by admin #' . $admin_id . ($note !== '' ? ": {$note}" : ''),
                            $admin_id,
                            'admin'
                        );
                    }
                } catch (Throwable $e) {
                }

                /* ---- Escrow auto-create safety net ---- */
                if (!empty($p['order_id']) && function_exists('nb_ledger_tables_exist')) {
                    try {
                        $lk_tables = nb_ledger_tables_exist($conn);
                        if ($lk_tables['escrow_holds'] ?? false) {
                            $e_check = $conn->prepare("SELECT id FROM escrow_holds WHERE order_id = ? LIMIT 1");
                            $e_check->bind_param("i", $p['order_id']);
                            $e_check->execute();
                            $e_exists = $e_check->get_result()->fetch_assoc();
                            $e_check->close();

                            if (!$e_exists) {
                                $ord = $conn->prepare("SELECT buyer_id, seller_id, total_amount, auction_id FROM orders WHERE order_id = ?");
                                $ord->bind_param("i", $p['order_id']);
                                $ord->execute();
                                $ord_row = $ord->get_result()->fetch_assoc();
                                $ord->close();

                                if ($ord_row) {
                                    $e_ins = $conn->prepare("
                                        INSERT INTO escrow_holds
                                            (order_id, seller_id, buyer_id, amount, released_amount, refunded_amount,
                                             status, hold_reason, auto_release_at, created_at)
                                        VALUES (?, ?, ?, ?, 0.00, 0.00, 'held', 'order_placed', DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())
                                    ");
                                    $e_ins->bind_param(
                                        "iiid",
                                        $p['order_id'],
                                        $ord_row['seller_id'],
                                        $ord_row['buyer_id'],
                                        $ord_row['total_amount']
                                    );
                                    $e_ins->execute();
                                    $e_ins->close();

                                    error_log("Escrow auto-created for order #{$p['order_id']} during payment approval");
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        error_log("escrow auto-create failed: " . $e->getMessage());
                    }
                }

                /* ---- Audit log ---- */
                if (function_exists('nb_pay_audit')) {
                    try {
                        nb_pay_audit($conn, [
                            'actor_id'    => $admin_id,
                            'actor_type'  => 'admin',
                            'entity_type' => 'payment',
                            'entity_id'   => $payment_id,
                            'action'      => 'payment.approved',
                            'new'         => [
                                'order_id'       => $p['order_id'],
                                'amount'         => $p['amount'],
                                'method'         => $p['payment_method'],
                                'transaction_id' => $txn_ref,
                                'note'           => $note,
                            ],
                        ]);
                    } catch (Throwable $e) {}
                }

                /* ---- Notify buyer ---- */
                if (!empty($p['buyer_id']) && function_exists('nb_notify')) {
                    try {
                        nb_notify(
                            $conn,
                            (int) $p['buyer_id'],
                            'payment',
                            'Payment Approved',
                            'Your payment for "' . ($p['auction_title'] ?? 'your order') . '" has been verified.',
                            'user/order-details.php?id=' . (int) $p['auction_id']
                        );
                    } catch (Throwable $e) {
                    }
                }

                /* ---- Notify seller ---- */
                if (!empty($p['seller_id']) && function_exists('nb_notify')) {
                    try {
                        nb_notify(
                            $conn,
                            (int) $p['seller_id'],
                            'order',
                            'New Paid Order',
                            'Order ' . ($p['order_number'] ?? '') . ' has been paid. Please process it.',
                            'seller/orders.php'
                        );
                    } catch (Throwable $e) {
                    }
                }

                $conn->commit();
                $_SESSION['payment_flash'] = "✅ Payment #{$payment_id} approved. Order marked as paid.";
                header("Location: payments.php?_t=" . time());
                exit();

            } else {
                /* ---------- REJECT ---------- */
                if ($note === '') {
                    throw new RuntimeException("Rejection reason is required.");
                }

                $upd = $conn->prepare("UPDATE payments SET payment_status = 'failed' WHERE payment_id = ?");
                $upd->bind_param("i", $payment_id);
                $upd->execute();
                $upd->close();

                if (!empty($p['order_id'])) {
                    $upd = $conn->prepare("UPDATE orders SET payment_status = 'unpaid', order_status = 'pending_payment' WHERE order_id = ?");
                    $upd->bind_param("i", $p['order_id']);
                    $upd->execute();
                    $upd->close();
                }

                try {
                    if (function_exists('nb_payment_log')) {
                        nb_payment_log(
                            $conn,
                            $payment_id,
                            'failed',
                            'Rejected by admin #' . $admin_id . ": {$note}",
                            $admin_id,
                            'admin'
                        );
                    }
                } catch (Throwable $e) {
                }

                /* ---- Audit log ---- */
                if (function_exists('nb_pay_audit')) {
                    try {
                        nb_pay_audit($conn, [
                            'actor_id'    => $admin_id,
                            'actor_type'  => 'admin',
                            'entity_type' => 'payment',
                            'entity_id'   => $payment_id,
                            'action'      => 'payment.rejected',
                            'new'         => [
                                'order_id' => $p['order_id'],
                                'reason'   => $note,
                            ],
                        ]);
                    } catch (Throwable $e) {}
                }

                /* ---- Notify buyer ---- */
                if (!empty($p['buyer_id']) && function_exists('nb_notify')) {
                    try {
                        nb_notify(
                            $conn,
                            (int) $p['buyer_id'],
                            'payment',
                            'Payment Rejected',
                            'Your payment could not be verified. Reason: ' . $note,
                            '../checkout.php?auction_id=' . (int) $p['auction_id']
                        );
                    } catch (Throwable $e) {
                    }
                }

                $conn->commit();
                $_SESSION['payment_flash'] = "Payment #{$payment_id} rejected. Buyer notified.";
                header("Location: payments.php?_t=" . time());
                exit();
            }

        } catch (Throwable $e) {
            $conn->rollback();
            error_log("verify failed: " . $e->getMessage());
            $_SESSION['payment_flash_error'] = "Verification failed: " . $e->getMessage();
            header("Location: payments.php");
            exit();
        }
    }

    /* ---------- Manual status change ---------- */
    $status = trim($_POST["status"] ?? "");
    if ($payment_id > 0 && in_array($status, $allowed_statuses, true)) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE payments SET payment_status = ? WHERE payment_id = ?");
            $stmt->bind_param("si", $status, $payment_id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            $conn->commit();

            try {
                if (function_exists('nb_payment_log')) {
                    nb_payment_log($conn, $payment_id, $status, 'Manual change by admin #' . $admin_id, $admin_id, 'admin');
                }
                if (function_exists('nb_pay_audit')) {
                    nb_pay_audit($conn, [
                        'actor_id'    => $admin_id,
                        'actor_type'  => 'admin',
                        'entity_type' => 'payment',
                        'entity_id'   => $payment_id,
                        'action'      => 'payment.manual_status',
                        'new'         => ['status' => $status],
                    ]);
                }
            } catch (Throwable $e) {
            }

            $_SESSION['payment_flash'] = $affected > 0
                ? "Payment #{$payment_id} updated to " . ucfirst($status) . "."
                : "Payment #{$payment_id} already set to " . ucfirst($status) . ".";
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['payment_flash_error'] = "Could not update payment.";
        }
        header("Location: payments.php?_t=" . time());
        exit();
    }

    $_SESSION['payment_flash_error'] = "Invalid payment action.";
    header("Location: payments.php");
    exit();
}

/* =========================================================
   FETCH DATA
   ========================================================= */
$admin_name = "Admin";
try {
    $s = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
    $s->bind_param("i", $admin_id);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    $admin_name = $row["full_name"] ?? "Admin";
} catch (Throwable $e) {
}
$admin_initials = strtoupper(substr(preg_replace('/\s+/', '', $admin_name), 0, 2));

$unread_notifications = function_exists('nb_unread_count') ? (int) nb_unread_count($conn, $admin_id) : 0;

if (!function_exists('safe_count')) {
    function safe_count($conn, $sql)
    {
        try {
            $r = $conn->query($sql);
            return $r ? (int) ($r->fetch_assoc()["total"] ?? 0) : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

$total_users = safe_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role != 'admin'");
$total_auctions = safe_count($conn, "SELECT COUNT(*) AS total FROM auctions");
$total_bids = safe_count($conn, "SELECT COUNT(*) AS total FROM bids");
$total_winners = safe_count($conn, "SELECT COUNT(*) AS total FROM winners");
$total_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments");
$pending_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments WHERE payment_status IN ('pending','processing')");

/* Check if escrow table exists */
$has_escrow = false;
if (function_exists('nb_ledger_tables_exist')) {
    $lk = nb_ledger_tables_exist($conn);
    $has_escrow = $lk['escrow_holds'] ?? false;
} else {
    try {
        $r = $conn->query("SHOW TABLES LIKE 'escrow_holds'");
        $has_escrow = $r && $r->num_rows > 0;
    } catch (Throwable $e) {}
}

/* Filters */
$search_term = trim($_GET["q"] ?? "");
$status_filter = trim($_GET["status"] ?? "all");
if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = "all";
}

$per_page = 20;
$page = max(1, (int) ($_GET["page"] ?? 1));

$where_clauses = [];
$where_params = [];
$where_types = "";

if ($search_term !== "") {
    $where_clauses[] = "(u.full_name LIKE ? OR u.email LIKE ? OR a.title LIKE ? OR p.transaction_id LIKE ? OR p.sender_name LIKE ? OR o.order_number LIKE ?)";
    $like = "%" . $search_term . "%";
    for ($i = 0; $i < 6; $i++)
        $where_params[] = $like;
    $where_types .= "ssssss";
}

if ($status_filter !== "all") {
    $where_clauses[] = "p.payment_status = ?";
    $where_params[] = $status_filter;
    $where_types .= "s";
}

$where_sql = $where_clauses ? ("WHERE " . implode(" AND ", $where_clauses)) : "";

/* Count */
$total_filtered = 0;
if (
    $cs = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM payments p
    LEFT JOIN auctions a ON p.auction_id = a.auction_id
    LEFT JOIN users u ON p.user_id = u.user_id
    LEFT JOIN orders o ON p.order_id = o.order_id
    {$where_sql}
")
) {
    if ($where_params)
        $cs->bind_param($where_types, ...$where_params);
    $cs->execute();
    $total_filtered = (int) ($cs->get_result()->fetch_assoc()["total"] ?? 0);
    $cs->close();
}

$total_pages = max(1, (int) ceil($total_filtered / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

/* Fetch payments (with escrow info if available) */
$payments_data = [];
$escrow_select = $has_escrow
    ? ", e.status AS escrow_status, e.amount AS escrow_amount,
         (e.amount - e.released_amount - e.refunded_amount) AS escrow_available,
         e.id AS escrow_id"
    : "";
$escrow_join = $has_escrow ? "LEFT JOIN escrow_holds e ON e.order_id = p.order_id" : "";

$sql = "
    SELECT
        p.payment_id, p.auction_id, p.user_id, p.order_id,
        p.amount, p.payment_method, p.payment_status,
        p.sender_name, p.sender_number, p.transaction_id,
        p.initiated_at, p.created_at,
        a.title AS auction_title,
        u.user_id AS buyer_id,
        u.full_name AS buyer_name,
        u.email AS buyer_email,
        u.phone AS buyer_phone,
        o.order_number, o.order_status,
        s.full_name AS seller_name
        {$escrow_select}
    FROM payments p
    LEFT JOIN auctions a ON p.auction_id = a.auction_id
    LEFT JOIN users u ON p.user_id = u.user_id
    LEFT JOIN orders o ON p.order_id = o.order_id
    LEFT JOIN users s ON o.seller_id = s.user_id
    {$escrow_join}
    {$where_sql}
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
";
if ($ps = $conn->prepare($sql)) {
    $bt = $where_types . "ii";
    $bp = array_merge($where_params, [$per_page, $offset]);
    $ps->bind_param($bt, ...$bp);
    $ps->execute();
    $res = $ps->get_result();
    if ($res)
        $payments_data = $res->fetch_all(MYSQLI_ASSOC);
    $ps->close();
}

/* Stats */
$paid_count = $pending_count = $processing_count = $failed_count = $refunded_count = 0;
$total_revenue = 0.0;
if (
    $stats_stmt = $conn->query("
    SELECT
        SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN payment_status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
        SUM(CASE WHEN payment_status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
        SUM(CASE WHEN payment_status = 'refunded' THEN 1 ELSE 0 END) AS refunded_count,
        COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END), 0) AS total_revenue
    FROM payments
")
) {
    $pstats = $stats_stmt->fetch_assoc();
    $paid_count = (int) ($pstats['paid_count'] ?? 0);
    $pending_count = (int) ($pstats['pending_count'] ?? 0);
    $processing_count = (int) ($pstats['processing_count'] ?? 0);
    $failed_count = (int) ($pstats['failed_count'] ?? 0);
    $refunded_count = (int) ($pstats['refunded_count'] ?? 0);
    $total_revenue = (float) ($pstats['total_revenue'] ?? 0);
}

/* Helpers */
function compact_rs($amount)
{
    $amount = (float) $amount;
    if ($amount >= 10000000)
        return round($amount / 10000000, 1) . "Cr";
    if ($amount >= 100000)
        return round($amount / 100000, 1) . "L";
    if ($amount >= 1000)
        return round($amount / 1000) . "K";
    return number_format($amount, 2);
}

function time_ago_short($datetime)
{
    if (empty($datetime))
        return '';
    $ts = strtotime($datetime);
    if ($ts === false)
        return '';
    $diff = time() - $ts;
    if ($diff < 60)
        return "just now";
    if ($diff < 3600)
        return intdiv($diff, 60) . "m ago";
    if ($diff < 86400)
        return intdiv($diff, 3600) . "h ago";
    if ($diff < 604800)
        return intdiv($diff, 86400) . "d ago";
    return date("d M Y", $ts);
}

$status_styles = [
    'paid' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'border' => 'border-emerald-500/20', 'dot' => 'bg-emerald-500', 'label' => 'Paid', 'icon' => 'fa-circle-check'],
    'pending' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'border' => 'border-amber-500/20', 'dot' => 'bg-amber-500', 'label' => 'Pending', 'icon' => 'fa-clock'],
    'processing' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'border' => 'border-blue-500/20', 'dot' => 'bg-blue-500', 'label' => 'Processing', 'icon' => 'fa-spinner'],
    'failed' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'border' => 'border-rose-500/20', 'dot' => 'bg-rose-500', 'label' => 'Failed', 'icon' => 'fa-circle-xmark'],
    'refunded' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'border' => 'border-violet-500/20', 'dot' => 'bg-violet-500', 'label' => 'Refunded', 'icon' => 'fa-rotate-left'],
];
$default_status_style = ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-700 dark:text-gray-400', 'border' => 'border-gray-500/20', 'dot' => 'bg-gray-500', 'label' => 'Unknown', 'icon' => 'fa-circle-question'];

$escrow_styles = [
    'held' => ['text' => 'text-emerald-600 dark:text-emerald-400', 'label' => 'Held'],
    'partially_released' => ['text' => 'text-blue-600 dark:text-blue-400', 'label' => 'Partial Release'],
    'released' => ['text' => 'text-emerald-600 dark:text-emerald-400', 'label' => 'Released'],
    'refunded' => ['text' => 'text-rose-600 dark:text-rose-400', 'label' => 'Refunded'],
    'disputed' => ['text' => 'text-rose-600 dark:text-rose-400', 'label' => 'Disputed'],
    'cancelled' => ['text' => 'text-gray-500', 'label' => 'Cancelled'],
];

$flash = $_SESSION['payment_flash'] ?? null;
$flash_error = $_SESSION['payment_flash_error'] ?? null;
unset($_SESSION['payment_flash'], $_SESSION['payment_flash_error']);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments | NexusBids Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } }
        };
        (function () {
            try {
                const saved = localStorage.getItem('theme');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (saved === 'dark' || (!saved && prefersDark)) document.documentElement.classList.add('dark');
            } catch (e) { }
        })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        html {
            scroll-behavior: smooth;
        }

        body {
            transition: background-color .3s, color .3s;
        }

        .sidebar-link {
            position: relative;
            transition: all .2s;
        }

        .sidebar-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 60%;
            background: linear-gradient(to bottom, #6366f1, #8b5cf6);
            border-radius: 0 4px 4px 0;
        }

        .custom-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            appearance: none;
        }

        .stat-card {
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px -8px rgba(99, 102, 241, 0.25);
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.12) rotate(-6deg);
        }

        .stat-card .stat-icon {
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .data-row {
            transition: background-color .2s ease;
            position: relative;
        }

        .data-row:hover {
            background: rgb(238 242 255 / 0.4);
        }

        .dark .data-row:hover {
            background: rgb(30 41 59 / 0.4);
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen">

    <div class="flex min-h-screen">

        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="flex-1 lg:ml-64 min-w-0">

            <header
                class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-gray-200 dark:border-gray-800">
                <div class="h-20 px-4 sm:px-8 flex items-center justify-between gap-4">
                    <div>
                        <div
                            class="text-[10px] font-bold uppercase tracking-wider text-gray-400 flex items-center gap-1.5 mb-0.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span> Management
                        </div>
                        <h1 class="text-lg sm:text-xl font-black">Payments Verification</h1>
                    </div>
                    <div class="flex items-center gap-3">
                        <button id="themeToggle"
                            class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                            <i class="fa-solid fa-moon dark:hidden"></i>
                            <i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                        </button>
                        <a href="../notifications.php"
                            class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($unread_notifications > 0): ?>
                                <span
                                    class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800"></span>
                            <?php endif; ?>
                        </a>
                        <div
                            class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center">
                            <?= htmlspecialchars($admin_initials) ?>
                        </div>
                    </div>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6">

                <?php if ($flash): ?>
                    <div
                        class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-sm text-emerald-700 dark:text-emerald-300 font-medium flex items-center gap-3">
                        <i class="fa-solid fa-circle-check"></i>
                        <?= htmlspecialchars($flash) ?>
                    </div>
                <?php endif; ?>
                <?php if ($flash_error): ?>
                    <div
                        class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-sm text-rose-700 dark:text-rose-300 font-medium flex items-center gap-3">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <?= htmlspecialchars($flash_error) ?>
                    </div>
                <?php endif; ?>

                <div>
                    <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                        <a href="index.php" class="hover:text-indigo-600">Dashboard</a>
                        <i class="fa-solid fa-chevron-right text-[8px]"></i>
                        <span class="text-gray-900 dark:text-white font-semibold">Payments</span>
                    </nav>
                    <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white shadow-lg">
                            <i class="fa-solid fa-credit-card text-base"></i>
                        </span>
                        Payments
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Review and verify payments submitted by
                        buyers.</p>
                </div>

                <!-- Stat cards -->
                <section class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                    <div
                        class="stat-card bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Total</span>
                            <span
                                class="stat-icon w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 flex items-center justify-center text-xs">
                                <i class="fa-solid fa-receipt"></i>
                            </span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($total_payments) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">All records</p>
                    </div>

                    <div
                        class="stat-card bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Paid</span>
                            <span
                                class="stat-icon w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center text-xs">
                                <i class="fa-solid fa-circle-check"></i>
                            </span>
                        </div>
                        <div class="text-2xl font-black text-emerald-600 tabular-nums"><?= number_format($paid_count) ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">Verified</p>
                    </div>

                    <div
                        class="stat-card bg-white dark:bg-slate-900 rounded-2xl p-5 border-2 <?= ($pending_count + $processing_count) > 0 ? 'border-amber-500/40' : 'border-gray-200 dark:border-gray-800' ?>">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Awaiting</span>
                            <span
                                class="stat-icon w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 flex items-center justify-center text-xs">
                                <i
                                    class="fa-solid fa-clock <?= ($pending_count + $processing_count) > 0 ? 'animate-pulse' : '' ?>"></i>
                            </span>
                        </div>
                        <div
                            class="text-2xl font-black <?= ($pending_count + $processing_count) > 0 ? 'text-amber-600' : '' ?> tabular-nums">
                            <?= number_format($pending_count + $processing_count) ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1"><?= $pending_count ?> pending ·
                            <?= $processing_count ?> processing</p>
                    </div>

                    <div
                        class="stat-card bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Failed</span>
                            <span
                                class="stat-icon w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 flex items-center justify-center text-xs">
                                <i class="fa-solid fa-circle-xmark"></i>
                            </span>
                        </div>
                        <div class="text-2xl font-black <?= $failed_count > 0 ? 'text-rose-600' : '' ?> tabular-nums">
                            <?= number_format($failed_count) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1"><?= $refunded_count ?> refunded</p>
                    </div>

                    <div
                        class="stat-card bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Revenue</span>
                            <span
                                class="stat-icon w-9 h-9 rounded-lg bg-violet-500/10 text-violet-600 flex items-center justify-center text-xs">
                                <i class="fa-solid fa-coins"></i>
                            </span>
                        </div>
                        <div class="text-2xl font-black tabular-nums">Rs. <?= compact_rs($total_revenue) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">From paid</p>
                    </div>
                </section>

                <!-- Filter Bar -->
                <section class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4">
                    <form method="GET" class="flex flex-col sm:flex-row gap-3">
                        <div class="relative flex-1">
                            <i
                                class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input id="paymentSearch" name="q" value="<?= htmlspecialchars($search_term) ?>"
                                class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                placeholder="Search buyer, email, order#, txn, sender..." type="text"
                                autocomplete="off">
                        </div>
                        <select name="status"
                            class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="processing" <?= $status_filter === 'processing' ? 'selected' : '' ?>>Processing
                            </option>
                            <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="failed" <?= $status_filter === 'failed' ? 'selected' : '' ?>>Failed</option>
                            <option value="refunded" <?= $status_filter === 'refunded' ? 'selected' : '' ?>>Refunded
                            </option>
                        </select>
                        <button type="submit"
                            class="py-2.5 px-5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold">
                            <i class="fa-solid fa-filter mr-1"></i> Apply
                        </button>
                        <?php if ($search_term !== '' || $status_filter !== 'all'): ?>
                            <a href="payments.php"
                                class="py-2.5 px-4 rounded-xl bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 text-sm font-semibold text-center">
                                <i class="fa-solid fa-xmark"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </section>

                <!-- Payments Cards -->
                <section class="space-y-4">
                    <?php if (empty($payments_data)): ?>
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-16 text-center">
                            <div
                                class="w-20 h-20 mx-auto rounded-2xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                            <h3 class="text-xl font-black">
                                <?= ($search_term !== '' || $status_filter !== 'all') ? 'No matching payments' : 'No payments found' ?>
                            </h3>
                            <p class="text-sm text-gray-500 mt-2">
                                <?= ($search_term !== '' || $status_filter !== 'all') ? 'Try adjusting your filters.' : 'Payments will appear here.' ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($payments_data as $p):
                            $status = strtolower($p['payment_status'] ?? 'pending');
                            $s_style = $status_styles[$status] ?? $default_status_style;
                            $is_actionable = in_array($status, ['pending', 'processing'], true);
                            $buyer_name = $p['buyer_name'] ?? 'Unknown';
                            $buyer_email = $p['buyer_email'] ?? '';
                            $buyer_phone = $p['buyer_phone'] ?? '';
                            $buyer_initials = strtoupper(substr(preg_replace('/\s+/', '', $buyer_name), 0, 2));
                            $date_field = $p['created_at'] ?? '';
                            $created_ts = $date_field ? strtotime($date_field) : time();

                            $esc_status = $p['escrow_status'] ?? null;
                            $esc_style = $esc_status ? ($escrow_styles[strtolower($esc_status)] ?? null) : null;
                            ?>
                            <div
                                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden hover:shadow-lg transition-shadow">
                                <div
                                    class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-4 flex-wrap">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 flex items-center justify-center font-bold text-xs">
                                            #<?= str_pad((int) $p['payment_id'], 4, '0', STR_PAD_LEFT) ?>
                                        </div>
                                        <div>
                                            <div class="font-bold text-sm">
                                                <?= htmlspecialchars($p['order_number'] ?? 'Payment') ?>
                                            </div>
                                            <div class="text-[10px] text-gray-500">
                                                <?= date('d M Y, h:i A', $created_ts) ?> · <?= time_ago_short($date_field) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <span
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg <?= $s_style['bg'] . ' ' . $s_style['text'] . ' ' . $s_style['border'] ?> text-[10px] font-bold uppercase tracking-wider border">
                                        <span
                                            class="w-1.5 h-1.5 rounded-full <?= $s_style['dot'] ?> <?= $is_actionable ? 'animate-pulse' : '' ?>"></span>
                                        <?= $s_style['label'] ?>
                                    </span>
                                </div>

                                <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div class="space-y-3">
                                        <h4 class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Buyer</h4>
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-sm flex items-center justify-center shrink-0">
                                                <?= htmlspecialchars($buyer_initials) ?>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="font-bold text-sm truncate"><?= htmlspecialchars($buyer_name) ?>
                                                </div>
                                                <div class="text-[11px] text-gray-500 truncate">
                                                    <?= htmlspecialchars($buyer_email) ?></div>
                                            </div>
                                        </div>
                                        <?php if ($buyer_phone): ?>
                                            <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                                                <i class="fa-solid fa-phone text-[10px] text-gray-400"></i>
                                                <?= htmlspecialchars($buyer_phone) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="space-y-3">
                                        <h4 class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Payment</h4>
                                        <div>
                                            <div
                                                class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                                                Rs. <?= number_format((float) $p['amount'], 2) ?>
                                            </div>
                                            <div class="text-[11px] text-gray-500 capitalize mt-0.5">
                                                via <?= htmlspecialchars(str_replace('_', ' ', $p['payment_method'])) ?>
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-1 gap-1.5 text-xs">
                                            <div class="flex items-center gap-2">
                                                <span class="text-gray-500">Sender:</span>
                                                <span
                                                    class="font-semibold truncate"><?= htmlspecialchars($p['sender_name'] ?: '—') ?></span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span class="text-gray-500">Number:</span>
                                                <span
                                                    class="font-mono text-[11px]"><?= htmlspecialchars($p['sender_number'] ?: '—') ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-3">
                                        <h4 class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Transaction
                                        </h4>
                                        <div
                                            class="p-3 rounded-xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800">
                                            <div class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Txn ID</div>
                                            <div
                                                class="font-mono text-xs font-bold break-all text-indigo-600 dark:text-indigo-400">
                                                <?= htmlspecialchars($p['transaction_id'] ?: '—') ?>
                                            </div>
                                        </div>
                                        <?php if ($p['order_number']): ?>
                                            <div class="text-xs text-gray-600 dark:text-gray-400">
                                                <i class="fa-solid fa-receipt text-[10px] mr-1"></i>
                                                Order: <span
                                                    class="font-mono font-semibold"><?= htmlspecialchars($p['order_number']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($p['auction_title']): ?>
                                            <div class="text-xs text-gray-600 dark:text-gray-400 line-clamp-2">
                                                <i class="fa-solid fa-gavel text-[10px] mr-1"></i>
                                                <?= htmlspecialchars($p['auction_title']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($p['seller_name']): ?>
                                            <div class="text-xs text-gray-600 dark:text-gray-400">
                                                <i class="fa-solid fa-store text-[10px] mr-1"></i>
                                                Seller: <strong><?= htmlspecialchars($p['seller_name']) ?></strong>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Escrow status -->
                                        <?php if ($has_escrow): ?>
                                            <?php if ($esc_status): ?>
                                                <div class="text-xs flex items-center gap-1.5 pt-1 border-t border-gray-100 dark:border-gray-800">
                                                    <i class="fa-solid fa-vault text-[10px] text-emerald-500"></i>
                                                    <span class="text-gray-500">Escrow:</span>
                                                    <span class="font-bold <?= $esc_style['text'] ?? 'text-gray-600' ?>">
                                                        <?= htmlspecialchars($esc_style['label'] ?? ucfirst($esc_status)) ?>
                                                    </span>
                                                    <?php if (!empty($p['escrow_available']) && $p['escrow_available'] > 0): ?>
                                                        <span class="text-gray-400">· Rs. <?= number_format((float) $p['escrow_available']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($is_actionable): ?>
                                                <div class="text-xs flex items-center gap-1.5 pt-1 border-t border-gray-100 dark:border-gray-800 text-amber-600 dark:text-amber-400">
                                                    <i class="fa-solid fa-triangle-exclamation text-[10px]"></i>
                                                    <span class="font-semibold">No escrow yet — will auto-create on approve</span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div
                                    class="px-6 py-4 bg-gray-50 dark:bg-slate-800/50 border-t border-gray-100 dark:border-gray-800 flex items-center justify-end gap-2 flex-wrap">
                                    <a href="../auction-details.php?id=<?= (int) $p['auction_id'] ?>" target="_blank"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-xs font-semibold">
                                        <i class="fa-solid fa-gavel"></i> View Auction
                                    </a>

                                    <?php if ($p['order_id']): ?>
                                        <a href="../user/order-details.php?id=<?= (int) $p['auction_id'] ?>" target="_blank"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-xs font-semibold">
                                            <i class="fa-regular fa-eye"></i> View Order
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($is_actionable): ?>
                                        <form method="POST" class="inline">
                                            <?php nb_csrf_field(); ?>
                                            <input type="hidden" name="payment_id" value="<?= (int) $p['payment_id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit"
                                                onclick="return confirm('Approve payment #<?= (int) $p['payment_id'] ?>?\n\nThis will mark payment as PAID, order as PROCESSING, and notify buyer + seller.')"
                                                class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition">
                                                <i class="fa-solid fa-check"></i> Approve
                                            </button>
                                        </form>

                                        <button type="button"
                                            onclick="openRejectModal(<?= (int) $p['payment_id'] ?>, '<?= htmlspecialchars($buyer_name, ENT_QUOTES) ?>')"
                                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold transition">
                                            <i class="fa-solid fa-xmark"></i> Reject
                                        </button>
                                    <?php else: ?>
                                        <span
                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg <?= $s_style['bg'] . ' ' . $s_style['text'] ?> text-xs font-bold">
                                            <i class="fa-solid <?= $s_style['icon'] ?>"></i>
                                            <?= $s_style['label'] ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($total_pages > 1): ?>
                            <nav
                                class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 px-6 py-4 flex flex-wrap items-center justify-between gap-4">
                                <p class="text-xs text-gray-500">
                                    Showing <strong><?= count($payments_data) ?></strong> of
                                    <strong><?= number_format($total_filtered) ?></strong>
                                </p>
                                <div class="flex items-center gap-1.5">
                                    <?php
                                    $qp = ['q' => $search_term, 'status' => $status_filter];
                                    if ($page > 1) {
                                        $qp["page"] = $page - 1;
                                        echo '<a href="?' . htmlspecialchars(http_build_query($qp)) . '" class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100"><i class="fa-solid fa-chevron-left text-xs"></i></a>';
                                    }
                                    $window = 2;
                                    $start = max(1, $page - $window);
                                    $end = min($total_pages, $page + $window);
                                    for ($pg = $start; $pg <= $end; $pg++):
                                        $qp["page"] = $pg;
                                        $is_active = $pg === $page;
                                        ?>
                                        <a href="?<?= htmlspecialchars(http_build_query($qp)) ?>"
                                            class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold <?= $is_active ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100' ?>">
                                            <?= $pg ?>
                                        </a>
                                    <?php endfor;
                                    if ($page < $total_pages) {
                                        $qp["page"] = $page + 1;
                                        echo '<a href="?' . htmlspecialchars(http_build_query($qp)) . '" class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100"><i class="fa-solid fa-chevron-right text-xs"></i></a>';
                                    }
                                    ?>
                                </div>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>

            <footer class="mt-8 border-t border-gray-200 dark:border-gray-800 px-4 sm:px-8 py-6">
                <div
                    class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                    <p>© <?= date("Y") ?> NexusBids Admin Panel. All rights reserved.</p>
                    <p class="flex items-center gap-1.5">Admin Console <span
                            class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span></p>
                </div>
            </footer>
        </main>
    </div>

    <!-- REJECT MODAL -->
    <div id="rejectModal"
        class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl max-w-md w-full p-6 border border-gray-200 dark:border-gray-800">
            <div class="flex items-start gap-3 mb-4">
                <div
                    class="w-12 h-12 rounded-xl bg-rose-500/10 text-rose-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fa-solid fa-circle-xmark"></i>
                </div>
                <div>
                    <h3 class="text-lg font-black">Reject Payment</h3>
                    <p class="text-xs text-gray-500 mt-1">
                        Payment <span class="font-mono font-bold" id="rejectPaymentIdLabel"></span>
                        from <span class="font-bold" id="rejectBuyerLabel"></span>
                    </p>
                </div>
            </div>

            <form method="POST" id="rejectForm">
                <?php nb_csrf_field(); ?>
                <input type="hidden" name="payment_id" id="rejectPaymentId">
                <input type="hidden" name="action" value="reject">

                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-2">
                    Reason for rejection <span class="text-rose-500">*</span>
                </label>
                <textarea name="note" id="rejectNote" required maxlength="500" rows="3"
                    class="w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-slate-800 text-sm py-2.5 px-3 focus:border-rose-500 focus:ring-rose-500"
                    placeholder="e.g. Transaction ID not found, amount mismatch, invalid sender name..."></textarea>
                <p class="text-[10px] text-gray-500 mt-1">The buyer will receive this reason.</p>

                <div class="flex items-center gap-2 mt-5">
                    <button type="button" onclick="closeRejectModal()"
                        class="flex-1 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 text-sm font-semibold">
                        Cancel
                    </button>
                    <button type="submit"
                        class="flex-1 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-sm font-bold">
                        <i class="fa-solid fa-xmark mr-1"></i> Confirm Reject
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const html = document.documentElement;
            const t = document.getElementById('themeToggle');
            const saved = localStorage.getItem('theme');
            if (saved === 'dark' || (!saved && matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            t?.addEventListener('click', () => {
                html.classList.toggle('dark');
                localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
            });

            const searchInput = document.getElementById('paymentSearch');
            searchInput?.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); this.closest('form').submit(); }
            });
        })();

        function openRejectModal(paymentId, buyerName) {
            const modal = document.getElementById('rejectModal');
            document.getElementById('rejectPaymentId').value = paymentId;
            document.getElementById('rejectPaymentIdLabel').textContent = '#' + String(paymentId).padStart(6, '0');
            document.getElementById('rejectBuyerLabel').textContent = buyerName || 'this buyer';
            document.getElementById('rejectNote').value = '';
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => document.getElementById('rejectNote').focus(), 100);
        }

        function closeRejectModal() {
            const modal = document.getElementById('rejectModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.getElementById('rejectModal')?.addEventListener('click', function (e) {
            if (e.target === this) closeRejectModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeRejectModal();
        });
    </script>

</body>

</html>