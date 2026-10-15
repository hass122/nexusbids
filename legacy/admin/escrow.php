<?php
/* =========================================================
   admin/escrow.php — Escrow Management (Release/Refund/Freeze)
   Uses: includes/ledger.php helpers + escrow_holds(PK=id)
   ========================================================= */
require_once "admin-auth.php";
require_once "../includes/notifications.php";
require_once "../includes/ledger.php";
require_once "../includes/csrf.php";

date_default_timezone_set('Asia/Karachi');

$admin_id = (int) $_SESSION["user_id"];

/* ---------- Admin info ---------- */
$admin_stmt = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$admin_stmt->bind_param("i", $admin_id);
$admin_stmt->execute();
$admin_info = $admin_stmt->get_result()->fetch_assoc();
$admin_stmt->close();

$admin_name = $admin_info["full_name"] ?? "Admin";
$admin_avatar = $admin_info["avatar"] ?? "";
$admin_initials = strtoupper(substr(preg_replace('/\s+/', '', $admin_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $admin_id);

/* ---------- Helpers ---------- */
if (!function_exists('safe_count')) {
    function safe_count($conn, $sql)
    {
        $r = $conn->query($sql);
        return $r ? intval($r->fetch_assoc()["total"] ?? 0) : 0;
    }
}
if (!function_exists('money')) {
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
}
if (!function_exists('escrow_badge')) {
    function escrow_badge($s)
    {
        $map = [
            'held' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Held', 'dot' => 'bg-amber-500'],
            'partially_released' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'label' => 'Partial Release', 'dot' => 'bg-blue-500'],
            'released' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Released', 'dot' => 'bg-emerald-500'],
            'refunded' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Refunded', 'dot' => 'bg-rose-500'],
            'disputed' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Disputed', 'dot' => 'bg-rose-500'],
            'cancelled' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Cancelled', 'dot' => 'bg-gray-500'],
        ];
        return $map[strtolower($s)] ?? ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Unknown', 'dot' => 'bg-gray-500'];
    }
}

$tables = nb_ledger_tables_exist($conn);
$escrow_ready = $tables['escrow_holds'] ?? false;

/* ============================================================
   POST HANDLER
   ============================================================ */
$flash_error = '';
$flash_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $escrow_ready) {
    if (function_exists('nb_csrf_check'))
        nb_csrf_check();

    $action = $_POST['action'] ?? '';
    $escrow_id = (int) ($_POST['escrow_id'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($escrow_id <= 0 || !in_array($action, ['release', 'refund', 'freeze', 'unfreeze'], true)) {
        $flash_error = "Invalid request.";
    } else {
        /* ---------- RELEASE ---------- */
        if ($action === 'release') {
            $res = nb_ledger_release_escrow($conn, $escrow_id, $admin_id, $notes);
            if (!empty($res['ok'])) {
                /* Notify seller + buyer */
                if ($esc = nb_escrow_fetch($conn, $escrow_id)) {
                    if (function_exists('nb_notify')) {
                        try {
                            nb_notify(
                                $conn,
                                (int) $esc['seller_id'],
                                'payment',
                                'Payout Released',
                                'Rs. ' . number_format((float) $res['amount']) . ' has been released to your wallet for order ' . ($esc['order_number'] ?? '') . '.',
                                'seller/wallet.php'
                            );
                        } catch (Throwable $e) {
                        }
                        try {
                            nb_notify(
                                $conn,
                                (int) $esc['buyer_id'],
                                'order',
                                'Order Completed',
                                'Escrow for order ' . ($esc['order_number'] ?? '') . ' has been released.',
                                'order-details.php?id=' . (int) $esc['auction_id']
                            );
                        } catch (Throwable $e) {
                        }
                    }
                }
                $flash_success = "Released Rs. " . number_format((float) $res['amount']) . " to seller.";
            } else {
                $flash_error = $res['error'] ?? 'Release failed.';
            }
        }

        /* ---------- REFUND ---------- */ elseif ($action === 'refund') {
            $refund_amount = isset($_POST['refund_amount']) && $_POST['refund_amount'] !== ''
                ? (float) $_POST['refund_amount']
                : 0;

            $res = nb_ledger_refund_escrow($conn, $escrow_id, $admin_id, $refund_amount, $notes);
            if (!empty($res['ok'])) {
                if ($esc = nb_escrow_fetch($conn, $escrow_id)) {
                    if (function_exists('nb_notify')) {
                        try {
                            nb_notify(
                                $conn,
                                (int) $esc['buyer_id'],
                                'payment',
                                'Refund Processed',
                                'Rs. ' . number_format((float) $res['amount']) . ' refunded for order ' . ($esc['order_number'] ?? '') . '.',
                                'order-details.php?id=' . (int) $esc['auction_id']
                            );
                            nb_notify(
                                $conn,
                                (int) $esc['seller_id'],
                                'payment',
                                'Order Refunded',
                                'Order ' . ($esc['order_number'] ?? '') . ' refunded to buyer.',
                                'seller/orders.php'
                            );
                        } catch (Throwable $e) {
                        }
                    }
                }
                $flash_success = "Refunded Rs. " . number_format((float) $res['amount']) . " to buyer.";
            } else {
                $flash_error = $res['error'] ?? 'Refund failed.';
            }
        }

        /* ---------- FREEZE ---------- */ elseif ($action === 'freeze') {
            $res = nb_ledger_freeze_escrow($conn, $escrow_id, $admin_id);
            if (!empty($res['ok'])) {
                $flash_success = "Escrow frozen. Payout blocked.";
            } else {
                $flash_error = $res['error'] ?? 'Freeze failed.';
            }
        }

        /* ---------- UNFREEZE ---------- */ elseif ($action === 'unfreeze') {
            $res = nb_ledger_unfreeze_escrow($conn, $escrow_id, $admin_id);
            if (!empty($res['ok'])) {
                $flash_success = "Escrow unfrozen.";
            } else {
                $flash_error = $res['error'] ?? 'Unfreeze failed.';
            }
        }
    }

    if ($flash_success)
        $_SESSION['flash_success'] = $flash_success;
    if ($flash_error)
        $_SESSION['flash_error'] = $flash_error;
    header("Location: escrow.php" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit();
}

$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

/* ---------- Filters & pagination ---------- */
$status_f = trim($_GET['status'] ?? '');
$q = trim($_GET['q'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

$holds = [];
$total_filtered = 0;
$total_pages = 1;

if ($escrow_ready) {
    $where = ["1=1"];
    $params = [];
    $types = "";

    if ($status_f !== '' && $status_f !== 'all') {
        $where[] = "e.status = ?";
        $params[] = $status_f;
        $types .= "s";
    }
    if ($q !== '') {
        $where[] = "(a.title LIKE ? OR u.full_name LIKE ? OR o.order_number LIKE ?)";
        $like = "%$q%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= "sss";
    }
    $wsql = implode(' AND ', $where);

    /* Count */
    $cq = "SELECT COUNT(*) AS t
           FROM escrow_holds e
           LEFT JOIN orders o ON e.order_id = o.order_id
           LEFT JOIN auctions a ON o.auction_id = a.auction_id
           LEFT JOIN users u ON e.seller_id = u.user_id
           WHERE $wsql";
    $cs = $conn->prepare($cq);
    if ($params)
        $cs->bind_param($types, ...$params);
    $cs->execute();
    $total_filtered = intval($cs->get_result()->fetch_assoc()['t'] ?? 0);
    $cs->close();

    $total_pages = max(1, (int) ceil($total_filtered / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    /* Fetch */
    $lq = "SELECT e.*, o.order_number, o.total_amount, o.order_status, o.payment_status, o.auction_id,
                  a.title AS auction_title, a.image AS auction_image,
                  s.full_name AS seller_name, s.email AS seller_email,
                  b.full_name AS buyer_name
           FROM escrow_holds e
           LEFT JOIN orders o ON e.order_id = o.order_id
           LEFT JOIN auctions a ON o.auction_id = a.auction_id
           LEFT JOIN users s ON e.seller_id = s.user_id
           LEFT JOIN users b ON e.buyer_id = b.user_id
           WHERE $wsql
           ORDER BY e.created_at DESC
           LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    $ls = $conn->prepare($lq);
    $ls->bind_param($types, ...$params);
    $ls->execute();
    $holds = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
    $ls->close();
}

/* ---------- Stats ---------- */
$stats = ['held' => 0, 'released' => 0, 'refunded' => 0, 'disputed' => 0, 'total_held' => 0];
if ($escrow_ready) {
    try {
        $r = $conn->query("SELECT status, COUNT(*) AS c,
                                  COALESCE(SUM(amount - released_amount - refunded_amount), 0) AS amt
                           FROM escrow_holds GROUP BY status");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $s = strtolower($row['status']);
                if (isset($stats[$s]))
                    $stats[$s] = intval($row['c']);
                if (in_array($s, ['held', 'partially_released', 'disputed'], true))
                    $stats['total_held'] += floatval($row['amt']);
            }
        }
    } catch (Throwable $e) {
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Escrow Holds · NexusBids Admin</title>
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
                        <h1 class="text-lg sm:text-xl font-black">Escrow Holds</h1>
                    </div>
                    <div class="flex items-center gap-2">
                        <button id="themeToggle"
                            class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                            <i class="fa-solid fa-moon dark:hidden"></i>
                            <i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                        </button>
                        <a href="../notifications.php"
                            class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($unread_notifications > 0): ?>
                                <span
                                    class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800"></span>
                            <?php endif; ?>
                        </a>
                        <?php if (!empty($admin_avatar)): ?>
                            <img src="../uploads/avatars/<?= htmlspecialchars($admin_avatar) ?>"
                                class="w-10 h-10 rounded-full object-cover" alt="">
                        <?php else: ?>
                            <div
                                class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center">
                                <?= htmlspecialchars($admin_initials) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6">

                <!-- Flash -->
                <?php if ($flash_success): ?>
                    <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 flex items-start gap-3">
                        <i class="fa-solid fa-circle-check text-emerald-600 mt-0.5"></i>
                        <p class="text-sm text-emerald-700 dark:text-emerald-300 font-medium">
                            <?= htmlspecialchars($flash_success) ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($flash_error): ?>
                    <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 flex items-start gap-3">
                        <i class="fa-solid fa-circle-exclamation text-rose-600 mt-0.5"></i>
                        <p class="text-sm text-rose-700 dark:text-rose-300 font-medium">
                            <?= htmlspecialchars($flash_error) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!$escrow_ready): ?>
                    <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
                        <p class="text-xs text-amber-800 dark:text-amber-300">
                            Escrow table not found. Run <code
                                class="px-1 bg-black/5 dark:bg-white/10 rounded font-mono">includes/payment_schema.sql</code>.
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Stats -->
                <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-gradient-to-br from-amber-500 to-orange-500 rounded-2xl p-5 text-white shadow-lg">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-white/80 mb-1">Currently Held
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= money($stats['total_held']) ?></div>
                        <p class="text-[10px] text-white/80 mt-1">Waiting for delivery</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">Active Holds
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($stats['held']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">In escrow</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">Released</div>
                        <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                            <?= number_format($stats['released']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Completed</p>
                    </div>
                    <div
                        class="bg-white dark:bg-slate-900 rounded-2xl p-5 border <?= $stats['disputed'] > 0 ? 'border-rose-500/40' : 'border-gray-200 dark:border-gray-800' ?>">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">In Dispute</div>
                        <div
                            class="text-2xl font-black <?= $stats['disputed'] > 0 ? 'text-rose-600 dark:text-rose-400' : '' ?> tabular-nums">
                            <?= number_format($stats['disputed']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Frozen</p>
                    </div>
                </section>

                <!-- Filter -->
                <form method="GET"
                    class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4 flex flex-wrap gap-3 items-center">
                    <div class="relative flex-1 min-w-[200px]">
                        <i
                            class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
                            placeholder="Search order, item, seller..."
                            class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                    </div>
                    <select name="status"
                        class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                        <option value="">All Status</option>
                        <option value="held" <?= $status_f === 'held' ? 'selected' : '' ?>>Held</option>
                        <option value="partially_released" <?= $status_f === 'partially_released' ? 'selected' : '' ?>>
                            Partially Released</option>
                        <option value="released" <?= $status_f === 'released' ? 'selected' : '' ?>>Released</option>
                        <option value="refunded" <?= $status_f === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                        <option value="disputed" <?= $status_f === 'disputed' ? 'selected' : '' ?>>Disputed</option>
                        <option value="cancelled" <?= $status_f === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                    <button type="submit"
                        class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold">
                        <i class="fa-solid fa-filter"></i>
                    </button>
                    <?php if ($q || $status_f): ?>
                        <a href="escrow.php"
                            class="text-xs font-semibold text-gray-500 hover:text-rose-600 whitespace-nowrap">
                            <i class="fa-solid fa-xmark"></i> Reset
                        </a>
                    <?php endif; ?>
                </form>

                <!-- Table -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <?php if (empty($holds)): ?>
                        <div class="p-16 text-center">
                            <div
                                class="w-20 h-20 mx-auto rounded-2xl bg-amber-500/10 text-amber-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-solid fa-lock"></i>
                            </div>
                            <h3 class="text-xl font-black">No escrow holds</h3>
                            <p class="text-sm text-gray-500 mt-2">Escrow records will appear as buyers pay for orders.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                    <tr>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Order
                                            / Item</th>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Seller
                                        </th>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Buyer
                                        </th>
                                        <th class="px-5 py-4 text-right text-[10px] font-bold uppercase text-gray-500">Held
                                        </th>
                                        <th class="px-5 py-4 text-right text-[10px] font-bold uppercase text-gray-500">
                                            Released</th>
                                        <th class="px-5 py-4 text-center text-[10px] font-bold uppercase text-gray-500">
                                            Status</th>
                                        <th class="px-5 py-4 text-right text-[10px] font-bold uppercase text-gray-500">
                                            Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach ($holds as $h):
                                        $b = escrow_badge($h['status'] ?? 'held');
                                        $m = nb_escrow_metrics($h);
                                        $amt = $m['total'];
                                        $rel = $m['released'];
                                        $ref = $m['refunded'];
                                        $available = $m['available'];
                                        $st = $m['status'];
                                        $is_open = in_array($st, ['held', 'partially_released', 'disputed'], true) && $available > 0;
                                        $auto_at = $h['auto_release_at'] ?? null;
                                        ?>
                                        <tr class="data-row">
                                            <td class="px-5 py-4">
                                                <div class="flex items-center gap-2.5">
                                                    <?php if (!empty($h['auction_image'])): ?>
                                                        <img src="../uploads/auctions/<?= htmlspecialchars($h['auction_image']) ?>"
                                                            class="w-9 h-9 rounded-lg object-cover shrink-0" alt="">
                                                    <?php else: ?>
                                                        <div
                                                            class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-500 flex items-center justify-center text-xs shrink-0">
                                                            <i class="fa-solid fa-box"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="min-w-0">
                                                        <div class="text-xs font-bold truncate max-w-[200px]">
                                                            <?= htmlspecialchars($h['auction_title'] ?? 'Order') ?>
                                                        </div>
                                                        <div class="text-[10px] text-gray-400 font-mono">
                                                            <?= htmlspecialchars($h['order_number'] ?? ('#' . $h['order_id'])) ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="text-xs font-medium">
                                                    <?= htmlspecialchars($h['seller_name'] ?? '—') ?></div>
                                                <div class="text-[10px] text-gray-400">
                                                    <?= htmlspecialchars($h['seller_email'] ?? '') ?></div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="text-xs font-medium">
                                                    <?= htmlspecialchars($h['buyer_name'] ?? '—') ?></div>
                                            </td>
                                            <td class="px-5 py-4 text-right font-black tabular-nums">
                                                Rs. <?= number_format($amt) ?>
                                            </td>
                                            <td
                                                class="px-5 py-4 text-right font-bold tabular-nums text-emerald-600 dark:text-emerald-400">
                                                <?= $rel > 0 ? 'Rs. ' . number_format($rel) : '—' ?>
                                                <?php if ($ref > 0): ?>
                                                    <div class="text-[10px] text-rose-500">− Rs. <?= number_format($ref) ?> refunded
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4 text-center">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $b['bg'] . ' ' . $b['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                                    <span
                                                        class="w-1.5 h-1.5 rounded-full <?= $b['dot'] ?> <?= in_array($st, ['held', 'disputed'], true) ? 'animate-pulse' : '' ?>"></span>
                                                    <?= $b['label'] ?>
                                                </span>
                                                <?php if ($available > 0 && $available < $amt): ?>
                                                    <div class="text-[9px] text-gray-400 mt-1">Avail: Rs.
                                                        <?= number_format($available) ?></div>
                                                <?php endif; ?>
                                                <?php if ($auto_at): ?>
                                                    <div class="text-[9px] text-gray-400 mt-1" title="Auto-release scheduled">
                                                        <i class="fa-solid fa-clock"></i>
                                                        <?= htmlspecialchars(date('d M', strtotime($auto_at))) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <?php if ($is_open): ?>
                                                        <button type="button"
                                                            class="btn-release inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-[11px] font-bold"
                                                            data-id="<?= (int) $h['id'] ?>"
                                                            data-order="<?= htmlspecialchars((string) ($h['order_number'] ?? '')) ?>"
                                                            data-seller="<?= htmlspecialchars((string) ($h['seller_name'] ?? '')) ?>"
                                                            data-available="<?= number_format($available, 2, '.', '') ?>">
                                                            <i class="fa-solid fa-circle-check text-[9px]"></i> Release
                                                        </button>

                                                        <button type="button"
                                                            class="btn-refund inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-violet-500/10 hover:bg-violet-600 hover:text-white text-violet-700 dark:text-violet-400 text-[11px] font-bold"
                                                            data-id="<?= (int) $h['id'] ?>"
                                                            data-order="<?= htmlspecialchars((string) ($h['order_number'] ?? '')) ?>"
                                                            data-buyer="<?= htmlspecialchars((string) ($h['buyer_name'] ?? '')) ?>"
                                                            data-available="<?= number_format($available, 2, '.', '') ?>">
                                                            <i class="fa-solid fa-rotate-left text-[9px]"></i> Refund
                                                        </button>

                                                        <?php if ($st === 'disputed'): ?>
                                                            <form method="POST" class="inline"
                                                                onsubmit="return confirm('Unfreeze this escrow?');">
                                                                <?php if (function_exists('nb_csrf_field'))
                                                                    nb_csrf_field(); ?>
                                                                <input type="hidden" name="action" value="unfreeze">
                                                                <input type="hidden" name="escrow_id" value="<?= (int) $h['id'] ?>">
                                                                <button type="submit"
                                                                    class="inline-flex items-center px-2 py-1.5 rounded-lg bg-blue-500/10 hover:bg-blue-600 hover:text-white text-blue-700 dark:text-blue-400 text-[11px] font-bold"
                                                                    title="Unfreeze">
                                                                    <i class="fa-solid fa-lock-open text-[10px]"></i>
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <form method="POST" class="inline"
                                                                onsubmit="return confirm('Freeze this escrow? Payout will be blocked.');">
                                                                <?php if (function_exists('nb_csrf_field'))
                                                                    nb_csrf_field(); ?>
                                                                <input type="hidden" name="action" value="freeze">
                                                                <input type="hidden" name="escrow_id" value="<?= (int) $h['id'] ?>">
                                                                <button type="submit"
                                                                    class="inline-flex items-center px-2 py-1.5 rounded-lg bg-amber-500/10 hover:bg-amber-600 hover:text-white text-amber-700 dark:text-amber-400 text-[11px] font-bold"
                                                                    title="Freeze">
                                                                    <i class="fa-solid fa-snowflake text-[10px]"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-[10px] text-gray-400 italic">
                                                            <?php
                                                            if ($st === 'released')
                                                                echo 'Paid to seller';
                                                            elseif ($st === 'refunded')
                                                                echo 'Refunded';
                                                            elseif ($st === 'cancelled')
                                                                echo 'Cancelled';
                                                            else
                                                                echo 'Closed';
                                                            ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <nav
                                class="flex flex-wrap items-center justify-between gap-4 p-5 border-t border-gray-100 dark:border-gray-800">
                                <p class="text-xs text-gray-500">
                                    Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong>
                                    · <?= number_format($total_filtered) ?> total
                                </p>
                                <div class="flex items-center gap-1.5">
                                    <?php
                                    $qp = $_GET;
                                    if ($page > 1) {
                                        $qp['page'] = $page - 1;
                                        echo '<a href="?' . htmlspecialchars(http_build_query($qp)) . '" class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100"><i class="fa-solid fa-chevron-left text-xs"></i></a>';
                                    }
                                    for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++):
                                        $qp['page'] = $p;
                                        $active = ($p === $page);
                                        ?>
                                        <a href="?<?= htmlspecialchars(http_build_query($qp)) ?>"
                                            class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold <?= $active ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100' ?>">
                                            <?= $p ?>
                                        </a>
                                    <?php endfor;
                                    if ($page < $total_pages) {
                                        $qp['page'] = $page + 1;
                                        echo '<a href="?' . htmlspecialchars(http_build_query($qp)) . '" class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100"><i class="fa-solid fa-chevron-right text-xs"></i></a>';
                                    }
                                    ?>
                                </div>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>

    <!-- ============ RELEASE MODAL ============ -->
    <div id="releaseModal"
        class="hidden fixed inset-0 z-[100] bg-black/60 backdrop-blur-sm p-4 items-center justify-center">
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-2xl w-full max-w-md overflow-hidden">
            <div class="p-6 border-b border-gray-100 dark:border-gray-800">
                <div class="flex items-center gap-3">
                    <div
                        class="w-11 h-11 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-lg">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-black">Release Payout</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Send available funds to seller's ledger</p>
                    </div>
                </div>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <?php if (function_exists('nb_csrf_field'))
                    nb_csrf_field(); ?>
                <input type="hidden" name="action" value="release">
                <input type="hidden" name="escrow_id" id="releaseEscrowId">

                <div class="p-4 rounded-2xl bg-emerald-500/5 border border-emerald-500/20 space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-gray-500">Order</span><strong
                            id="releaseOrder">—</strong></div>
                    <div class="flex justify-between"><span class="text-gray-500">Seller</span><strong
                            id="releaseSeller">—</strong></div>
                    <div class="flex justify-between pt-2 border-t border-emerald-500/20">
                        <span class="text-gray-500">Available</span>
                        <strong class="text-emerald-600 dark:text-emerald-400 text-base tabular-nums">Rs. <span
                                id="releaseAmount">0</span></strong>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Notes (optional)</label>
                    <textarea name="notes" rows="2" maxlength="500" placeholder="Internal note..."
                        class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 resize-none"></textarea>
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="button" data-close-modal
                        class="flex-1 py-3 rounded-xl bg-gray-100 dark:bg-slate-800 text-sm font-semibold">Cancel</button>
                    <button type="submit"
                        class="flex-1 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold shadow-lg shadow-emerald-600/30">
                        <i class="fa-solid fa-check mr-1.5"></i> Confirm Release
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============ REFUND MODAL ============ -->
    <div id="refundModal"
        class="hidden fixed inset-0 z-[100] bg-black/60 backdrop-blur-sm p-4 items-center justify-center">
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-2xl w-full max-w-md overflow-hidden">
            <div class="p-6 border-b border-gray-100 dark:border-gray-800">
                <div class="flex items-center gap-3">
                    <div
                        class="w-11 h-11 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-lg">
                        <i class="fa-solid fa-rotate-left"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-black">Refund Buyer</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Return funds to buyer</p>
                    </div>
                </div>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <?php if (function_exists('nb_csrf_field'))
                    nb_csrf_field(); ?>
                <input type="hidden" name="action" value="refund">
                <input type="hidden" name="escrow_id" id="refundEscrowId">

                <div class="p-4 rounded-2xl bg-violet-500/5 border border-violet-500/20 space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-gray-500">Order</span><strong
                            id="refundOrder">—</strong></div>
                    <div class="flex justify-between"><span class="text-gray-500">Buyer</span><strong
                            id="refundBuyer">—</strong></div>
                    <div class="flex justify-between pt-2 border-t border-violet-500/20">
                        <span class="text-gray-500">Available</span>
                        <strong class="text-violet-600 dark:text-violet-400 text-base tabular-nums">Rs. <span
                                id="refundAvailable">0</span></strong>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">
                        Refund Amount <span class="text-rose-500">*</span>
                    </label>
                    <input type="number" name="refund_amount" id="refundAmountInput" step="0.01" min="0.01" required
                        class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500 font-bold tabular-nums">
                    <p class="text-[11px] text-gray-400 mt-1.5">Full or partial. Max = available balance.</p>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Reason (optional)</label>
                    <textarea name="notes" rows="2" maxlength="500" placeholder="Why refunding?"
                        class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500 resize-none"></textarea>
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="button" data-close-modal
                        class="flex-1 py-3 rounded-xl bg-gray-100 dark:bg-slate-800 text-sm font-semibold">Cancel</button>
                    <button type="submit"
                        class="flex-1 py-3 rounded-xl bg-violet-600 hover:bg-violet-500 text-white text-sm font-bold shadow-lg shadow-violet-600/30">
                        <i class="fa-solid fa-rotate-left mr-1.5"></i> Process Refund
                    </button>
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

            /* RELEASE MODAL */
            const releaseModal = document.getElementById('releaseModal');
            document.querySelectorAll('.btn-release').forEach(b => {
                b.addEventListener('click', () => {
                    document.getElementById('releaseEscrowId').value = b.dataset.id;
                    document.getElementById('releaseOrder').textContent = '#' + (b.dataset.order || '');
                    document.getElementById('releaseSeller').textContent = b.dataset.seller || '—';
                    document.getElementById('releaseAmount').textContent = Number(b.dataset.available || 0).toLocaleString();
                    releaseModal.classList.remove('hidden');
                    releaseModal.classList.add('flex');
                });
            });

            /* REFUND MODAL */
            const refundModal = document.getElementById('refundModal');
            document.querySelectorAll('.btn-refund').forEach(b => {
                b.addEventListener('click', () => {
                    const avail = parseFloat(b.dataset.available || 0);
                    document.getElementById('refundEscrowId').value = b.dataset.id;
                    document.getElementById('refundOrder').textContent = '#' + (b.dataset.order || '');
                    document.getElementById('refundBuyer').textContent = b.dataset.buyer || '—';
                    document.getElementById('refundAvailable').textContent = avail.toLocaleString();
                    const inp = document.getElementById('refundAmountInput');
                    inp.value = avail.toFixed(2);
                    inp.max = avail;
                    refundModal.classList.remove('hidden');
                    refundModal.classList.add('flex');
                });
            });

            /* Close modals */
            document.querySelectorAll('[data-close-modal]').forEach(el => {
                el.addEventListener('click', () => {
                    const m = el.closest('.fixed');
                    m.classList.add('hidden');
                    m.classList.remove('flex');
                });
            });
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') {
                    document.querySelectorAll('[data-close-modal]').forEach(el => {
                        const m = el.closest('.fixed');
                        if (m && !m.classList.contains('hidden')) {
                            m.classList.add('hidden');
                            m.classList.remove('flex');
                        }
                    });
                }
            });

            /* Refund amount clamp */
            const refundInput = document.getElementById('refundAmountInput');
            refundInput?.addEventListener('input', function () {
                const max = parseFloat(this.max || 0);
                const val = parseFloat(this.value || 0);
                if (val > max) this.value = max.toFixed(2);
                if (val < 0) this.value = '0.00';
            });
        })();
    </script>
</body>

</html>