<?php
require_once "admin-auth.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/ledger.php";

date_default_timezone_set('Asia/Karachi');

/* ---------- Admin info ---------- */
$admin_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$admin_stmt->bind_param("i", $_SESSION["user_id"]);
$admin_stmt->execute();
$admin_info = $admin_stmt->get_result()->fetch_assoc();
$admin_name = $admin_info["full_name"] ?? "Admin";
$admin_initials = strtoupper(substr(preg_replace('/\s+/', '', $admin_name), 0, 2));
$unread_notifications = nb_unread_count($conn, intval($_SESSION["user_id"]));

/* ---------- Sidebar counts ---------- */
if (!function_exists('safe_count')) {
    function safe_count($conn, $sql)
    {
        try {
            $r = $conn->query($sql);
            return $r ? intval($r->fetch_assoc()["total"] ?? 0) : 0;
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
$pending_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments WHERE payment_status NOT IN ('paid','refunded')");

/* ---------- Detect payouts table ---------- */
$payouts_ready = false;
try {
    $r = $conn->query("SHOW TABLES LIKE 'payouts'");
    $payouts_ready = $r && $r->num_rows > 0;
} catch (Throwable $e) {
    $payouts_ready = false;
}

/* Fetch columns */
$payout_cols = [];
if ($payouts_ready) {
    try {
        $r = $conn->query("SHOW COLUMNS FROM payouts");
        while ($c = $r->fetch_assoc())
            $payout_cols[] = $c['Field'];
    } catch (Throwable $e) {
    }
}

/* Safe column picker */
function pick_col(array $cands, array $haystack)
{
    foreach ($cands as $c)
        if (in_array($c, $haystack, true))
            return $c;
    return null;
}

/* Map columns based on actual schema */
$p_id_col = pick_col(['payout_id', 'id'], $payout_cols);
$p_seller_col = pick_col(['seller_id', 'user_id'], $payout_cols);
$p_amount_col = pick_col(['amount'], $payout_cols);
$p_method_col = pick_col(['method', 'payout_method'], $payout_cols);
$p_status_col = pick_col(['status'], $payout_cols);
$p_req_col = pick_col(['requested_at', 'created_at', 'date'], $payout_cols);
$p_proc_col = pick_col(['processed_at', 'paid_at'], $payout_cols);
$p_ref_col = pick_col(['reference', 'ref', 'account_info', 'note', 'admin_note'], $payout_cols);
$p_net_col = pick_col(['net_amount'], $payout_cols);
$p_fee_col = pick_col(['fee'], $payout_cols);

$payouts_usable = $payouts_ready && $p_id_col && $p_seller_col && $p_amount_col;

/* ============================================================
   STATUS MAPPING
   ------------------------------------------------------------
   DB enum: pending | processing | completed | failed

   Flow:
     pending    = seller submitted, waiting admin review
     processing = admin APPROVED, waiting payment
     completed  = admin marked PAID (money sent)
     failed     = admin REJECTED
   ============================================================ */

/* ---------- POST: Approve / Reject / Mark Paid ---------- */
$flash = null;
$flash_err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $payouts_usable) {
    nb_csrf_check();
    $action = $_POST['action'] ?? '';
    $payout_id = intval($_POST['payout_id'] ?? 0);
    $note = trim($_POST['admin_note'] ?? '');

    if ($payout_id > 0 && in_array($action, ['approve', 'reject', 'mark_paid'], true)) {

        $stmt = $conn->prepare("SELECT * FROM payouts WHERE `$p_id_col` = ? LIMIT 1");
        $stmt->bind_param("i", $payout_id);
        $stmt->execute();
        $payout = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$payout) {
            $flash_err = "Payout not found.";
        } else {
            $seller_id = intval($payout[$p_seller_col] ?? 0);
            $amount = floatval($payout[$p_amount_col] ?? 0);
            $current_status = strtolower($payout[$p_status_col] ?? 'pending');

            $conn->begin_transaction();
            try {
                /* ---------- APPROVE → status = processing ---------- */
                if ($action === 'approve') {
                    if ($current_status !== 'pending')
                        throw new Exception("Only pending payouts can be approved.");

                    $new_status = 'processing';

                    if ($p_ref_col && $note !== '') {
                        $upd = $conn->prepare("UPDATE payouts SET `$p_status_col` = ?, `$p_ref_col` = ? WHERE `$p_id_col` = ?");
                        $upd->bind_param("ssi", $new_status, $note, $payout_id);
                    } else {
                        $upd = $conn->prepare("UPDATE payouts SET `$p_status_col` = ? WHERE `$p_id_col` = ?");
                        $upd->bind_param("si", $new_status, $payout_id);
                    }
                    $upd->execute();
                    $upd->close();

                    /* Log approval */
                    if (function_exists('nb_pay_audit')) {
                        nb_pay_audit($conn, [
                            'actor_id' => intval($_SESSION['user_id']),
                            'actor_type' => 'admin',
                            'entity_type' => 'payout',
                            'entity_id' => $payout_id,
                            'action' => 'payout.approved',
                            'new' => ['status' => 'processing', 'note' => $note],
                        ]);
                    }

                    /* Notify seller */
                    if ($seller_id && function_exists('nb_notify')) {
                        try {
                            nb_notify(
                                $conn,
                                $seller_id,
                                'payout',
                                'Payout Approved',
                                'Your withdrawal of Rs. ' . number_format($amount) . ' has been approved. It will be processed shortly.',
                                'seller/payouts.php'
                            );
                        } catch (Throwable $e) {
                        }
                    }

                    $flash = "Payout #{$payout_id} approved. Awaiting payment.";
                }

                /* ---------- MARK PAID → status = completed ---------- */ elseif ($action === 'mark_paid') {
                    if ($current_status !== 'processing')
                        throw new Exception("Only approved payouts can be marked as paid.");

                    $new_status = 'completed';

                    if ($p_proc_col) {
                        $upd = $conn->prepare("UPDATE payouts SET `$p_status_col` = ?, `$p_proc_col` = NOW() WHERE `$p_id_col` = ?");
                        $upd->bind_param("si", $new_status, $payout_id);
                    } else {
                        $upd = $conn->prepare("UPDATE payouts SET `$p_status_col` = ? WHERE `$p_id_col` = ?");
                        $upd->bind_param("si", $new_status, $payout_id);
                    }
                    $upd->execute();
                    $upd->close();

                    /* Update seller_ledger to released */
                    try {
                        $rv = $conn->prepare("UPDATE seller_ledger SET status = 'released' WHERE payout_id = ?");
                        $rv->bind_param("i", $payout_id);
                        $rv->execute();
                        $rv->close();
                    } catch (Throwable $e) {
                    }

                    if (function_exists('nb_pay_audit')) {
                        nb_pay_audit($conn, [
                            'actor_id' => intval($_SESSION['user_id']),
                            'actor_type' => 'admin',
                            'entity_type' => 'payout',
                            'entity_id' => $payout_id,
                            'action' => 'payout.paid',
                            'new' => ['status' => 'completed'],
                        ]);
                    }

                    if ($seller_id && function_exists('nb_notify')) {
                        try {
                            nb_notify(
                                $conn,
                                $seller_id,
                                'payout_completed',
                                'Payout Completed',
                                'Rs. ' . number_format($amount) . ' has been paid out to your account.',
                                'seller/payouts.php'
                            );
                        } catch (Throwable $e) {
                        }
                    }

                    $flash = "Payout #{$payout_id} marked as PAID.";
                }

                /* ---------- REJECT → status = failed ---------- */ elseif ($action === 'reject') {
                    if (!in_array($current_status, ['pending', 'processing'], true))
                        throw new Exception("Cannot reject this payout.");

                    $new_status = 'failed';

                    if ($p_ref_col && $note !== '') {
                        $upd = $conn->prepare("UPDATE payouts SET `$p_status_col` = ?, `$p_ref_col` = ? WHERE `$p_id_col` = ?");
                        $upd->bind_param("ssi", $new_status, $note, $payout_id);
                    } else {
                        $upd = $conn->prepare("UPDATE payouts SET `$p_status_col` = ? WHERE `$p_id_col` = ?");
                        $upd->bind_param("si", $new_status, $payout_id);
                    }
                    $upd->execute();
                    $upd->close();

                    /* Cancel ledger entry */
                    try {
                        $rv = $conn->prepare("UPDATE seller_ledger SET status = 'cancelled' WHERE payout_id = ? AND status = 'pending'");
                        $rv->bind_param("i", $payout_id);
                        $rv->execute();
                        $rv->close();
                    } catch (Throwable $e) {
                    }

                    if (function_exists('nb_pay_audit')) {
                        nb_pay_audit($conn, [
                            'actor_id' => intval($_SESSION['user_id']),
                            'actor_type' => 'admin',
                            'entity_type' => 'payout',
                            'entity_id' => $payout_id,
                            'action' => 'payout.rejected',
                            'new' => ['status' => 'failed', 'note' => $note],
                        ]);
                    }

                    if ($seller_id && function_exists('nb_notify')) {
                        try {
                            nb_notify(
                                $conn,
                                $seller_id,
                                'payout_rejected',
                                'Payout Rejected',
                                'Your withdrawal of Rs. ' . number_format($amount) . ' was rejected. Reason: ' . ($note ?: 'Not specified'),
                                'seller/payouts.php'
                            );
                        } catch (Throwable $e) {
                        }
                    }

                    $flash = "Payout #{$payout_id} rejected.";
                }

                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                $flash_err = "Action failed: " . $ex->getMessage();
            }
        }
    }
}

/* ---------- Filters ---------- */
$status_f = trim($_GET['status'] ?? 'pending');
$q = trim($_GET['q'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

/* Map UI filter → DB enum values */
$status_map_filter = [
    'pending' => 'pending',
    'processing' => 'processing',
    'completed' => 'completed',
    'failed' => 'failed',
    'all' => null,
];

$payouts = [];
$total_filtered = 0;
$total_pages = 1;

if ($payouts_usable) {
    $where = ["1=1"];
    $params = [];
    $types = "";

    $db_status = $status_map_filter[$status_f] ?? null;
    if ($db_status && $p_status_col) {
        $where[] = "p.`$p_status_col` = ?";
        $params[] = $db_status;
        $types .= "s";
    }
    if ($q !== '') {
        $where[] = "(u.full_name LIKE ? OR u.email LIKE ?)";
        $like = "%$q%";
        $params[] = $like;
        $params[] = $like;
        $types .= "ss";
    }
    $where_sql = implode(' AND ', $where);

    try {
        $count_q = "SELECT COUNT(*) AS t FROM payouts p LEFT JOIN users u ON u.user_id = p.`$p_seller_col` WHERE $where_sql";
        $cs = $conn->prepare($count_q);
        if ($params)
            $cs->bind_param($types, ...$params);
        $cs->execute();
        $total_filtered = intval($cs->get_result()->fetch_assoc()['t'] ?? 0);
        $cs->close();
    } catch (Throwable $e) {
        $total_filtered = 0;
    }

    $total_pages = max(1, (int) ceil($total_filtered / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    /* SAFE ORDER BY */
    $order_col = null;
    foreach ([$p_req_col, $p_id_col, 'requested_at', 'created_at'] as $cand) {
        if ($cand && in_array($cand, $payout_cols, true)) {
            $order_col = preg_replace('/[^a-zA-Z0-9_]/', '', $cand);
            break;
        }
    }
    if (!$order_col && !empty($payout_cols)) {
        $order_col = preg_replace('/[^a-zA-Z0-9_]/', '', $payout_cols[0]);
    }
    $order_sql = $order_col ? "ORDER BY p.`$order_col` DESC" : "";

    try {
        $list_q = "SELECT p.*, 
                          u.full_name AS seller_name, 
                          u.email AS seller_email, 
                          u.phone AS seller_phone, 
                          u.avatar AS seller_avatar
                   FROM payouts p 
                   LEFT JOIN users u ON u.user_id = p.`$p_seller_col`
                   WHERE $where_sql 
                   $order_sql 
                   LIMIT ? OFFSET ?";
        $ls = $conn->prepare($list_q);
        $params[] = $per_page;
        $params[] = $offset;
        $types .= "ii";
        $ls->bind_param($types, ...$params);
        $ls->execute();
        $payouts = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
        $ls->close();
    } catch (Throwable $e) {
        error_log("payouts list failed: " . $e->getMessage());
        $payouts = [];
    }
}

/* ---------- Stats ---------- */
$stats = [
    'pending' => 0,
    'pending_amt' => 0,
    'processing' => 0,
    'completed' => 0,
    'completed_amt' => 0,
    'failed' => 0,
];

if ($payouts_usable && $p_status_col && $p_amount_col) {
    try {
        $r = $conn->query("SELECT `$p_status_col` AS s, COUNT(*) AS c, COALESCE(SUM(`$p_amount_col`),0) AS amt FROM payouts GROUP BY `$p_status_col`");
        while ($row = $r->fetch_assoc()) {
            $st = strtolower($row['s'] ?? '');
            if (isset($stats[$st]))
                $stats[$st] = intval($row['c']);
            if ($st === 'pending')
                $stats['pending_amt'] = floatval($row['amt']);
            if ($st === 'completed')
                $stats['completed_amt'] = floatval($row['amt']);
        }
    } catch (Throwable $e) {
    }
}

/* ---------- Helpers ---------- */
function status_badge($status)
{
    $map = [
        'pending' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Pending', 'dot' => 'bg-amber-500'],
        'processing' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'label' => 'Approved', 'dot' => 'bg-blue-500'],
        'completed' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Paid', 'dot' => 'bg-emerald-500'],
        'failed' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Rejected', 'dot' => 'bg-rose-500'],
    ];
    return $map[strtolower($status)] ?? ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => ucfirst($status ?: 'Unknown'), 'dot' => 'bg-gray-500'];
}
function money($a)
{
    $a = floatval($a);
    if ($a >= 10000000)
        return 'Rs. ' . round($a / 10000000, 1) . 'Cr';
    if ($a >= 100000)
        return 'Rs. ' . round($a / 100000, 1) . 'L';
    if ($a >= 1000)
        return 'Rs. ' . round($a / 1000) . 'K';
    return 'Rs. ' . number_format($a);
}
function time_ago($dt)
{
    if (empty($dt))
        return '—';
    $ts = strtotime($dt);
    if (!$ts)
        return '—';
    $d = time() - $ts;
    if ($d < 60)
        return 'just now';
    if ($d < 3600)
        return intdiv($d, 60) . 'm ago';
    if ($d < 86400)
        return intdiv($d, 3600) . 'h ago';
    if ($d < 604800)
        return intdiv($d, 86400) . 'd ago';
    return date('d M Y', $ts);
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payout Approvals · NexusBids Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } } };</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body {
            transition: background-color .3s, color .3s
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%)
        }

        .custom-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right .75rem center;
            appearance: none
        }

        .data-row:hover {
            background: rgb(238 242 255/.5)
        }

        .dark .data-row:hover {
            background: rgb(30 41 59/.5)
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
            border-radius: 0 4px 4px 0
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen">
    <div class="flex min-h-screen">

        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="flex-1 lg:ml-64 min-w-0">

            <header
                class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
                <div class="h-20 px-4 sm:px-8 flex items-center justify-between gap-4">
                    <div>
                        <div
                            class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span> Finance
                        </div>
                        <h1 class="text-lg sm:text-xl font-black">Payout Approvals</h1>
                    </div>
                    <div class="flex items-center gap-2">
                        <button id="themeToggle"
                            class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                            <i class="fa-solid fa-moon dark:hidden"></i>
                            <i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                        </button>
                        <a href="../notifications.php"
                            class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($unread_notifications > 0): ?><span
                                    class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800"></span><?php endif; ?>
                        </a>
                        <div
                            class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center">
                            <?= htmlspecialchars($admin_initials) ?></div>
                    </div>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6">

                <?php if ($flash): ?>
                    <div
                        class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-sm text-emerald-700 dark:text-emerald-300 font-medium flex items-center gap-3">
                        <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($flash) ?>
                    </div>
                <?php endif; ?>
                <?php if ($flash_err): ?>
                    <div
                        class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-sm text-rose-700 dark:text-rose-300 font-medium flex items-center gap-3">
                        <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($flash_err) ?>
                    </div>
                <?php endif; ?>

                <?php if (!$payouts_ready): ?>
                    <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
                        <p class="text-xs text-amber-800 dark:text-amber-300">Payouts table not found. Run the SQL migration
                            first.</p>
                    </div>
                <?php elseif (!$payouts_usable): ?>
                    <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30">
                        <div class="flex items-start gap-3">
                            <i class="fa-solid fa-database text-rose-500 mt-0.5"></i>
                            <div class="text-xs text-rose-800 dark:text-rose-300">
                                <strong>Payouts table schema mismatch.</strong><br>
                                Columns found: <code
                                    class="px-1 bg-black/5 dark:bg-white/10 rounded font-mono"><?= htmlspecialchars(implode(', ', $payout_cols)) ?></code>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Stats -->
                <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Pending</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-clock"></i></span>
                        </div>
                        <div
                            class="text-2xl font-black <?= $stats['pending'] > 0 ? 'text-amber-600 dark:text-amber-400' : '' ?> tabular-nums">
                            <?= number_format($stats['pending']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1"><?= money($stats['pending_amt']) ?> requested</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Approved</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-blue-500/10 text-blue-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-check"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($stats['processing']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Awaiting payment</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Paid</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-money-bill-transfer"></i></span>
                        </div>
                        <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                            <?= number_format($stats['completed']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1"><?= money($stats['completed_amt']) ?> paid out</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Rejected</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-xmark"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($stats['failed']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">All time</p>
                    </div>
                </section>

                <!-- Filters -->
                <form method="GET"
                    class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4 flex flex-wrap gap-3 items-center">
                    <div class="relative flex-1 min-w-[200px]">
                        <i
                            class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
                            placeholder="Search seller name or email..."
                            class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <select name="status"
                        class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                        <option value="pending" <?= $status_f === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="processing" <?= $status_f === 'processing' ? 'selected' : '' ?>>Approved</option>
                        <option value="completed" <?= $status_f === 'completed' ? 'selected' : '' ?>>Paid</option>
                        <option value="failed" <?= $status_f === 'failed' ? 'selected' : '' ?>>Rejected</option>
                        <option value="all" <?= $status_f === 'all' ? 'selected' : '' ?>>All</option>
                    </select>
                    <button type="submit"
                        class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold">
                        <i class="fa-solid fa-filter"></i> Apply
                    </button>
                    <?php if ($q !== '' || $status_f !== 'pending'): ?>
                        <a href="payouts.php"
                            class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 text-sm font-semibold">Reset</a>
                    <?php endif; ?>
                </form>

                <!-- Table -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <?php if (empty($payouts)): ?>
                        <div class="p-16 text-center">
                            <div
                                class="w-20 h-20 mx-auto rounded-2xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-solid fa-wallet"></i>
                            </div>
                            <h3 class="text-xl font-black">No payouts found</h3>
                            <p class="text-sm text-gray-500 mt-2">
                                <?= $status_f === 'pending' ? 'No pending payout requests right now.' : 'Try different filters.' ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                    <tr>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Payout
                                        </th>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Seller
                                        </th>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Amount
                                        </th>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Method
                                        </th>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Status
                                        </th>
                                        <th class="px-5 py-4 text-right text-[10px] font-bold uppercase text-gray-500">
                                            Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach ($payouts as $po):
                                        $sid = intval($po[$p_id_col] ?? 0);
                                        $amt = floatval($po[$p_amount_col] ?? 0);
                                        $meth = $p_method_col ? ($po[$p_method_col] ?? '') : '';
                                        $stat = strtolower($po[$p_status_col] ?? 'pending');
                                        $b = status_badge($stat);
                                        $req_dt = $p_req_col ? ($po[$p_req_col] ?? '') : '';
                                        $proc_dt = $p_proc_col ? ($po[$p_proc_col] ?? '') : '';
                                        $seller_name = $po['seller_name'] ?? 'Unknown';
                                        $seller_email = $po['seller_email'] ?? '';
                                        $seller_initials = strtoupper(substr(preg_replace('/\s+/', '', $seller_name), 0, 2));
                                        $ref = $p_ref_col ? ($po[$p_ref_col] ?? '') : '';
                                        $net = $p_net_col ? floatval($po[$p_net_col] ?? 0) : 0;
                                        $fee = $p_fee_col ? floatval($po[$p_fee_col] ?? 0) : 0;
                                        ?>
                                        <tr class="data-row">
                                            <td class="px-5 py-4">
                                                <div class="font-mono text-xs font-bold">
                                                    #<?= str_pad($sid, 5, '0', STR_PAD_LEFT) ?></div>
                                                <div class="text-[10px] text-gray-400 mt-0.5">
                                                    <?= $req_dt ? date('d M Y, h:i A', strtotime($req_dt)) : '—' ?>
                                                </div>
                                                <?php if ($proc_dt): ?>
                                                    <div class="text-[10px] text-emerald-500 mt-0.5">Paid:
                                                        <?= date('d M Y', strtotime($proc_dt)) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="flex items-center gap-2.5">
                                                    <div
                                                        class="w-9 h-9 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-[10px] flex items-center justify-center shrink-0">
                                                        <?= htmlspecialchars($seller_initials) ?>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="text-xs font-bold truncate max-w-[140px]">
                                                            <?= htmlspecialchars($seller_name) ?></div>
                                                        <div class="text-[10px] text-gray-500 truncate max-w-[140px]">
                                                            <?= htmlspecialchars($seller_email) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="font-black tabular-nums">Rs. <?= number_format($amt) ?></div>
                                                <?php if ($fee > 0): ?>
                                                    <div class="text-[10px] text-rose-500">- Fee: Rs. <?= number_format($fee) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($net > 0): ?>
                                                    <div class="text-[10px] text-emerald-600 dark:text-emerald-400">Net: Rs.
                                                        <?= number_format($net) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4">
                                                <?php if ($meth): ?>
                                                    <div class="text-xs font-bold capitalize flex items-center gap-1.5">
                                                        <i
                                                            class="fa-solid <?= $meth === 'bank' ? 'fa-building-columns text-indigo-500' : 'fa-mobile-screen text-emerald-500' ?> text-[10px]"></i>
                                                        <?= htmlspecialchars($meth) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($ref && $stat !== 'failed'): ?>
                                                    <div class="text-[10px] text-gray-400 mt-0.5 truncate max-w-[160px]"
                                                        title="<?= htmlspecialchars($ref) ?>"><?= htmlspecialchars($ref) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $b['bg'] . ' ' . $b['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                                    <span
                                                        class="w-1.5 h-1.5 rounded-full <?= $b['dot'] ?> <?= $stat === 'pending' ? 'animate-pulse' : '' ?>"></span>
                                                    <?= $b['label'] ?>
                                                </span>
                                                <?php if ($stat === 'failed' && $ref): ?>
                                                    <div class="text-[10px] text-rose-500 mt-1 max-w-[180px] line-clamp-2"
                                                        title="<?= htmlspecialchars($ref) ?>">
                                                        <?= htmlspecialchars($ref) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4 text-right">
                                                <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                                    <?php if ($stat === 'pending'): ?>
                                                        <button type="button"
                                                            onclick="openAction(<?= $sid ?>, 'approve', '<?= htmlspecialchars($seller_name, ENT_QUOTES) ?>', '<?= number_format($amt) ?>')"
                                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500 hover:text-white text-[10px] font-bold transition">
                                                            <i class="fa-solid fa-check"></i> Approve
                                                        </button>
                                                        <button type="button"
                                                            onclick="openAction(<?= $sid ?>, 'reject', '<?= htmlspecialchars($seller_name, ENT_QUOTES) ?>', '<?= number_format($amt) ?>')"
                                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-rose-500/10 text-rose-700 dark:text-rose-400 border border-rose-500/20 hover:bg-rose-500 hover:text-white text-[10px] font-bold transition">
                                                            <i class="fa-solid fa-xmark"></i> Reject
                                                        </button>
                                                    <?php elseif ($stat === 'processing'): ?>
                                                        <button type="button"
                                                            onclick="openAction(<?= $sid ?>, 'mark_paid', '<?= htmlspecialchars($seller_name, ENT_QUOTES) ?>', '<?= number_format($amt) ?>')"
                                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 border border-indigo-500/20 hover:bg-indigo-500 hover:text-white text-[10px] font-bold transition">
                                                            <i class="fa-solid fa-money-bill-transfer"></i> Mark Paid
                                                        </button>
                                                        <button type="button"
                                                            onclick="openAction(<?= $sid ?>, 'reject', '<?= htmlspecialchars($seller_name, ENT_QUOTES) ?>', '<?= number_format($amt) ?>')"
                                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-rose-500/10 text-rose-700 dark:text-rose-400 border border-rose-500/20 hover:bg-rose-500 hover:text-white text-[10px] font-bold transition">
                                                            <i class="fa-solid fa-xmark"></i> Reject
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-[10px] text-gray-400">No action</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <div
                                class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex items-center justify-center gap-1.5">
                                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++):
                                    $q2 = ['q' => $q, 'status' => $status_f, 'page' => $p]; ?>
                                    <a href="?<?= htmlspecialchars(http_build_query($q2)) ?>"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold <?= $p === $page ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-slate-800' ?>"><?= $p ?></a>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>

            <footer class="mt-8 border-t border-gray-200 dark:border-gray-800 px-4 sm:px-8 py-6">
                <div
                    class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                    <p>© <?= date("Y") ?> NexusBids Admin Panel. All rights reserved.</p>
                </div>
            </footer>
        </main>
    </div>

    <!-- Action Modal -->
    <div id="actionModal"
        class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl max-w-md w-full p-6 border border-gray-200 dark:border-gray-800">
            <h3 class="text-lg font-black mb-1" id="actTitle">Confirm Action</h3>
            <p class="text-xs text-gray-500 mb-4" id="actDesc"></p>
            <form method="POST" id="actForm">
                <?php nb_csrf_field(); ?>
                <input type="hidden" name="payout_id" id="actPayoutId">
                <input type="hidden" name="action" id="actAction">
                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-2" id="noteLabel">Note
                    (optional)</label>
                <textarea name="admin_note" id="noteField" rows="2" maxlength="500"
                    class="w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-slate-800 text-sm py-2.5 px-3 focus:border-indigo-500 focus:ring-indigo-500"
                    placeholder="Optional note for the seller / record..."></textarea>
                <div class="flex items-center gap-2 mt-5">
                    <button type="button" onclick="closeAction()"
                        class="flex-1 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 text-sm font-semibold">Cancel</button>
                    <button type="submit" id="actBtn"
                        class="flex-1 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const h = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && matchMedia('(prefers-color-scheme: dark)').matches)) h.classList.add('dark');
            t?.addEventListener('click', () => {
                h.classList.toggle('dark');
                localStorage.setItem('theme', h.classList.contains('dark') ? 'dark' : 'light');
            });
        })();

        function openAction(id, action, seller, amount) {
            const titles = { approve: 'Approve Payout', reject: 'Reject Payout', mark_paid: 'Mark as Paid' };
            const descs = {
                approve: 'Approve payout for ' + seller + ' of Rs. ' + amount + '? Seller will be notified.',
                reject: 'Reject payout for ' + seller + ' of Rs. ' + amount + '? Reason is required.',
                mark_paid: 'Mark payout as PAID for ' + seller + ' (Rs. ' + amount + ')? This will complete the payout.'
            };
            document.getElementById('actTitle').textContent = titles[action] || 'Confirm';
            document.getElementById('actDesc').textContent = descs[action] || '';
            document.getElementById('actPayoutId').value = id;
            document.getElementById('actAction').value = action;

            const noteLabel = document.getElementById('noteLabel');
            const noteField = document.getElementById('noteField');
            if (action === 'reject') {
                noteLabel.innerHTML = 'Rejection Reason <span class="text-rose-500">*</span>';
                noteField.required = true;
                noteField.placeholder = 'e.g. Account details invalid, insufficient KYC...';
            } else {
                noteLabel.textContent = 'Note (optional)';
                noteField.required = false;
                noteField.placeholder = 'Optional note for internal record...';
            }

            const btn = document.getElementById('actBtn');
            btn.className = 'flex-1 py-2.5 rounded-xl text-white text-sm font-bold ' +
                (action === 'reject'
                    ? 'bg-rose-600 hover:bg-rose-500'
                    : (action === 'mark_paid'
                        ? 'bg-indigo-600 hover:bg-indigo-500'
                        : 'bg-emerald-600 hover:bg-emerald-500'));
            const m = document.getElementById('actionModal');
            m.classList.remove('hidden'); m.classList.add('flex');
        }
        function closeAction() {
            const m = document.getElementById('actionModal');
            m.classList.add('hidden'); m.classList.remove('flex');
            document.getElementById('noteField').value = '';
        }
        document.getElementById('actionModal')?.addEventListener('click', function (e) { if (e.target === this) closeAction(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAction(); });
    </script>
</body>

</html>