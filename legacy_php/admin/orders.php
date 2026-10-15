<?php
/* =========================================================
   admin/orders.php — Order Management (COMPACT TABLE FIX)
   ========================================================= */
require_once "admin-auth.php";
require_once "../includes/notifications.php";

date_default_timezone_set("Asia/Karachi");

/* Admin info */
$admin_name = "Admin";
if ($s = $conn->prepare("SELECT full_name FROM users WHERE user_id = ? LIMIT 1")) {
    $s->bind_param("i", $_SESSION["user_id"]);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    $admin_name = $row["full_name"] ?? "Admin";
}
$admin_initials = strtoupper(substr(preg_replace('/\s+/', '', $admin_name), 0, 2));
$unread = function_exists('nb_unread_count') ? (int) nb_unread_count($conn, (int) $_SESSION["user_id"]) : 0;

/* Safe count helper */
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

/* Filters & Pagination */
$search_term = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? 'all');
$payment_filter = trim($_GET['payment'] ?? 'all');

$allowed_statuses = ['all', 'pending_payment', 'paid', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'refunded'];
if (!in_array($status_filter, $allowed_statuses, true))
    $status_filter = 'all';

$allowed_payments = ['all', 'unpaid', 'pending', 'processing', 'paid', 'failed', 'refunded'];
if (!in_array($payment_filter, $allowed_payments, true))
    $payment_filter = 'all';

$per_page = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));

$where_clauses = [];
$where_params = [];
$where_types = '';

if ($search_term !== '') {
    $where_clauses[] = "(o.order_number LIKE ? OR a.title LIKE ? OR b.full_name LIKE ? OR s.full_name LIKE ?)";
    $like = '%' . $search_term . '%';
    for ($i = 0; $i < 4; $i++)
        $where_params[] = $like;
    $where_types .= 'ssss';
}

if ($status_filter !== 'all') {
    $where_clauses[] = "o.order_status = ?";
    $where_params[] = $status_filter;
    $where_types .= 's';
}

if ($payment_filter !== 'all') {
    $where_clauses[] = "o.payment_status = ?";
    $where_params[] = $payment_filter;
    $where_types .= 's';
}

$where_sql = $where_clauses ? ('WHERE ' . implode(' AND ', $where_clauses)) : '';

/* Count */
$total_orders = 0;
$count_sql = "
    SELECT COUNT(*) AS total
    FROM orders o
    INNER JOIN auctions a ON o.auction_id = a.auction_id
    INNER JOIN users b ON o.buyer_id = b.user_id
    INNER JOIN users s ON o.seller_id = s.user_id
    {$where_sql}
";
if ($cs = $conn->prepare($count_sql)) {
    if (!empty($where_params))
        $cs->bind_param($where_types, ...$where_params);
    $cs->execute();
    $total_orders = (int) ($cs->get_result()->fetch_assoc()['total'] ?? 0);
    $cs->close();
}
$total_pages = max(1, (int) ceil($total_orders / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

/* Fetch orders */
$orders = [];
$sql = "
    SELECT
        o.order_id, o.order_number, o.order_status, o.payment_status, o.shipping_status,
        o.winning_bid, o.platform_fee, o.seller_amount, o.total_amount,
        o.courier, o.tracking_number,
        o.created_at, o.paid_at, o.shipped_at, o.delivered_at, o.completed_at,
        a.auction_id, a.title AS auction_title, a.image AS auction_image,
        b.user_id AS buyer_id, b.full_name AS buyer_name, b.email AS buyer_email,
        s.user_id AS seller_id, s.full_name AS seller_name, s.email AS seller_email,
        (SELECT p.payment_method FROM payments p WHERE p.order_id = o.order_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_method,
        (SELECT p.transaction_id FROM payments p WHERE p.order_id = o.order_id ORDER BY p.payment_id DESC LIMIT 1) AS transaction_id,
        (SELECT p.sender_name FROM payments p WHERE p.order_id = o.order_id ORDER BY p.payment_id DESC LIMIT 1) AS sender_name,
        (SELECT p.payment_id FROM payments p WHERE p.order_id = o.order_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_id
    FROM orders o
    INNER JOIN auctions a ON o.auction_id = a.auction_id
    INNER JOIN users b ON o.buyer_id = b.user_id
    INNER JOIN users s ON o.seller_id = s.user_id
    {$where_sql}
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?
";
if ($stmt = $conn->prepare($sql)) {
    $bind_types = $where_types . 'ii';
    $bind_params = array_merge($where_params, [$per_page, $offset]);
    $stmt->bind_param($bind_types, ...$bind_params);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res)
        $orders = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* Aggregate stats */
$stats_sql = "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN o.payment_status IN ('pending','processing') THEN 1 ELSE 0 END) AS pending_verify,
        SUM(CASE WHEN o.order_status IN ('shipped','delivered') THEN 1 ELSE 0 END) AS shipped_count,
        SUM(CASE WHEN o.order_status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
        SUM(CASE WHEN o.order_status NOT IN ('cancelled','refunded') THEN o.total_amount ELSE 0 END) AS gross_sales,
        SUM(CASE WHEN o.order_status NOT IN ('cancelled','refunded') THEN o.platform_fee ELSE 0 END) AS platform_rev
    FROM orders o
    INNER JOIN auctions a ON o.auction_id = a.auction_id
    INNER JOIN users b ON o.buyer_id = b.user_id
    INNER JOIN users s ON o.seller_id = s.user_id
    {$where_sql}
";
$pending_verify = $shipped_count = $completed_count = 0;
$gross_sales = $platform_rev = 0.0;

if ($st = $conn->prepare($stats_sql)) {
    if (!empty($where_params))
        $st->bind_param($where_types, ...$where_params);
    $st->execute();
    $stats = $st->get_result()->fetch_assoc();
    $st->close();

    $pending_verify = (int) ($stats['pending_verify'] ?? 0);
    $shipped_count = (int) ($stats['shipped_count'] ?? 0);
    $completed_count = (int) ($stats['completed_count'] ?? 0);
    $gross_sales = (float) ($stats['gross_sales'] ?? 0);
    $platform_rev = (float) ($stats['platform_rev'] ?? 0);
}

/* Status styles */
$styles = [
    'pending_payment' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Pending Payment', 'dot' => 'bg-amber-500'],
    'paid' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'label' => 'Paid', 'dot' => 'bg-blue-500'],
    'processing' => ['bg' => 'bg-indigo-500/10', 'text' => 'text-indigo-700 dark:text-indigo-400', 'label' => 'Processing', 'dot' => 'bg-indigo-500'],
    'shipped' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'label' => 'Shipped', 'dot' => 'bg-violet-500'],
    'delivered' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Delivered', 'dot' => 'bg-emerald-500'],
    'completed' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Completed', 'dot' => 'bg-emerald-500'],
    'cancelled' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Cancelled', 'dot' => 'bg-rose-500'],
    'refunded' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'label' => 'Refunded', 'dot' => 'bg-violet-500'],
    'disputed' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Disputed', 'dot' => 'bg-rose-500'],
];
$default_style = ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-300', 'label' => 'Unknown', 'dot' => 'bg-gray-500'];

$payment_styles = [
    'unpaid' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-700 dark:text-gray-300', 'label' => 'Unpaid'],
    'pending' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Pending'],
    'processing' => ['bg' => 'bg-indigo-500/10', 'text' => 'text-indigo-700 dark:text-indigo-400', 'label' => 'Processing'],
    'paid' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Paid'],
    'failed' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Failed'],
    'refunded' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'label' => 'Refunded'],
];

function compact_rs($a)
{
    $a = (float) $a;
    if ($a >= 10000000)
        return round($a / 10000000, 1) . "Cr";
    if ($a >= 100000)
        return round($a / 100000, 1) . "L";
    if ($a >= 1000)
        return round($a / 1000) . "K";
    return number_format($a);
}

function time_ago_safe(?string $dt): string
{
    if (empty($dt))
        return '';
    $ts = strtotime($dt);
    if ($ts === false)
        return '';
    $d = time() - $ts;
    if ($d < 60)
        return "just now";
    if ($d < 3600)
        return intdiv($d, 60) . "m ago";
    if ($d < 86400)
        return intdiv($d, 3600) . "h ago";
    if ($d < 604800)
        return intdiv($d, 86400) . "d ago";
    return date("d M Y", $ts);
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders · Admin · NexusBids</title>
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
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            will-change: transform, box-shadow, border-color;
        }

        .stat-card:hover {
            transform: translateY(-6px) scale(1.015);
            box-shadow: 0 20px 40px -12px rgba(99, 102, 241, 0.25), 0 8px 16px -8px rgba(99, 102, 241, 0.15);
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.15) rotate(-6deg);
        }

        .stat-card .stat-icon {
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .stat-card.card-indigo:hover {
            box-shadow: 0 20px 40px -12px rgba(99, 102, 241, 0.35);
            border-color: rgb(99 102 241 / 0.5) !important;
        }

        .stat-card.card-amber:hover {
            box-shadow: 0 20px 40px -12px rgba(245, 158, 11, 0.35);
            border-color: rgb(245 158 11 / 0.5) !important;
        }

        .stat-card.card-violet:hover {
            box-shadow: 0 20px 40px -12px rgba(139, 92, 246, 0.35);
            border-color: rgb(139 92 246 / 0.5) !important;
        }

        .stat-card.card-emerald:hover {
            box-shadow: 0 20px 40px -12px rgba(16, 185, 129, 0.35);
            border-color: rgb(16 185 129 / 0.5) !important;
        }

        .stat-card.card-blue:hover {
            box-shadow: 0 20px 40px -12px rgba(59, 130, 246, 0.35);
            border-color: rgb(59 130 246 / 0.5) !important;
        }

        .data-row {
            position: relative;
            transition: all 0.2s ease;
        }

        .data-row::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(to bottom, #6366f1, #8b5cf6);
            transform: scaleY(0);
            transform-origin: center;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .data-row:hover::before {
            transform: scaleY(1);
        }

        .data-row:hover {
            background: linear-gradient(90deg, rgb(238 242 255 / 0.7) 0%, rgb(238 242 255 / 0.3) 50%, transparent 100%);
        }

        .dark .data-row:hover {
            background: linear-gradient(90deg, rgb(30 41 59 / 0.7) 0%, rgb(30 41 59 / 0.3) 50%, transparent 100%);
        }

        @keyframes float-subtle {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-3px);
            }
        }

        .stat-card:hover .stat-icon {
            animation: float-subtle 1.5s ease-in-out infinite;
        }

        @media (prefers-reduced-motion: reduce) {

            .stat-card,
            .stat-card .stat-icon,
            .data-row,
            .data-row::before {
                transition: none !important;
                animation: none !important;
            }

            .stat-card:hover {
                transform: none;
            }
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
                        <h1 class="text-lg sm:text-xl font-black">Orders</h1>
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
                            <?php if ($unread > 0): ?>
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

                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4">
                    <div>
                        <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                            <a href="index.php" class="hover:text-indigo-600">Dashboard</a>
                            <i class="fa-solid fa-chevron-right text-[8px]"></i>
                            <span class="text-gray-900 dark:text-white font-semibold">Orders</span>
                        </nav>
                        <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                            <span
                                class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white shadow-lg">
                                <i class="fa-solid fa-receipt text-lg"></i>
                            </span>
                            Order Management
                        </h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Monitor all orders, verify payments,
                            track shipments.</p>
                    </div>
                </div>

                <!-- Stats -->
                <section class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                    <div
                        class="stat-card card-indigo bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 cursor-default">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Total</span>
                            <span
                                class="stat-icon w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 flex items-center justify-center text-xs">
                                <i class="fa-solid fa-receipt"></i>
                            </span>
                        </div>
                        <div class="stat-number text-2xl font-black tabular-nums"><?= number_format($total_orders) ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">Filtered results</p>
                    </div>

                    <div
                        class="stat-card card-amber bg-white dark:bg-slate-900 rounded-2xl p-5 border-2 <?= $pending_verify > 0 ? 'border-amber-500/40' : 'border-gray-200 dark:border-gray-800' ?> cursor-default">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Verify</span>
                            <span
                                class="stat-icon w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 flex items-center justify-center text-xs">
                                <i class="fa-solid fa-clock <?= $pending_verify > 0 ? 'animate-pulse' : '' ?>"></i>
                            </span>
                        </div>
                        <div
                            class="stat-number text-2xl font-black <?= $pending_verify > 0 ? 'text-amber-600 dark:text-amber-400' : '' ?> tabular-nums">
                            <?= number_format($pending_verify) ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">Awaiting payment</p>
                    </div>

                    <div
                        class="stat-card card-violet bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 cursor-default">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Shipped</span>
                            <span
                                class="stat-icon w-9 h-9 rounded-lg bg-violet-500/10 text-violet-600 flex items-center justify-center text-xs">
                                <i class="fa-solid fa-truck-fast"></i>
                            </span>
                        </div>
                        <div class="stat-number text-2xl font-black tabular-nums"><?= number_format($shipped_count) ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">In transit</p>
                    </div>

                    <div
                        class="stat-card card-emerald bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 cursor-default">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Completed</span>
                            <span
                                class="stat-icon w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center text-xs">
                                <i class="fa-solid fa-circle-check"></i>
                            </span>
                        </div>
                        <div
                            class="stat-number text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                            <?= number_format($completed_count) ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">Finished</p>
                    </div>

                    <div
                        class="stat-card card-blue bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 cursor-default">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Commission</span>
                            <span
                                class="stat-icon w-9 h-9 rounded-lg bg-blue-500/10 text-blue-600 flex items-center justify-center text-xs">
                                <i class="fa-solid fa-coins"></i>
                            </span>
                        </div>
                        <div class="stat-number text-2xl font-black tabular-nums">Rs. <?= compact_rs($platform_rev) ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">Total sales Rs. <?= compact_rs($gross_sales) ?></p>
                    </div>
                </section>

                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">

                    <form method="GET"
                        class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row gap-3 items-stretch sm:items-center">
                        <div class="relative flex-1">
                            <i
                                class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input type="text" name="q" value="<?= htmlspecialchars($search_term) ?>"
                                placeholder="Search order #, auction, buyer, seller..."
                                class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <select name="status"
                            class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                            <option value="all">All Statuses</option>
                            <option value="pending_payment" <?= $status_filter === 'pending_payment' ? 'selected' : '' ?>>
                                Pending Payment</option>
                            <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="processing" <?= $status_filter === 'processing' ? 'selected' : '' ?>>Processing
                            </option>
                            <option value="shipped" <?= $status_filter === 'shipped' ? 'selected' : '' ?>>Shipped</option>
                            <option value="delivered" <?= $status_filter === 'delivered' ? 'selected' : '' ?>>Delivered
                            </option>
                            <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed
                            </option>
                            <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled
                            </option>
                            <option value="refunded" <?= $status_filter === 'refunded' ? 'selected' : '' ?>>Refunded
                            </option>
                        </select>
                        <select name="payment"
                            class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                            <option value="all">All Payments</option>
                            <option value="unpaid" <?= $payment_filter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                            <option value="pending" <?= $payment_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="processing" <?= $payment_filter === 'processing' ? 'selected' : '' ?>>Processing
                            </option>
                            <option value="paid" <?= $payment_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="failed" <?= $payment_filter === 'failed' ? 'selected' : '' ?>>Failed</option>
                            <option value="refunded" <?= $payment_filter === 'refunded' ? 'selected' : '' ?>>Refunded
                            </option>
                        </select>
                        <button type="submit"
                            class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold">
                            <i class="fa-solid fa-filter"></i> Apply
                        </button>
                        <?php if ($search_term !== '' || $status_filter !== 'all' || $payment_filter !== 'all'): ?>
                            <a href="orders.php"
                                class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-sm font-semibold text-center">
                                <i class="fa-solid fa-xmark"></i>
                            </a>
                        <?php endif; ?>
                    </form>

                    <?php if (empty($orders)): ?>
                        <div class="p-16 text-center">
                            <div
                                class="w-20 h-20 mx-auto rounded-2xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                            <h3 class="text-xl font-black">No orders found</h3>
                            <p class="text-sm text-gray-500 mt-2">
                                <?= ($search_term !== '' || $status_filter !== 'all' || $payment_filter !== 'all')
                                    ? 'Try adjusting your filters.' : 'Orders will appear when auctions are won and paid.' ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <!-- Desktop card list -->
                        <div class="hidden md:block divide-y divide-gray-100 dark:divide-gray-800">
                            <?php foreach ($orders as $o):
                                $style = $styles[$o['order_status']] ?? $default_style;
                                $pstyle = $payment_styles[$o['payment_status']] ?? $payment_styles['unpaid'];
                                $img = $o['auction_image'] ? '../uploads/auctions/' . rawurlencode($o['auction_image']) : null;
                                $is_actionable = in_array($o['payment_status'], ['pending', 'processing'], true);
                                ?>
                                <div class="data-row p-3 sm:p-4">
                                    <div class="flex items-center gap-3">

                                        <!-- Image -->
                                        <div class="shrink-0">
                                            <?php if ($img): ?>
                                                <img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"
                                                    class="w-12 h-12 rounded-xl object-cover border border-gray-200 dark:border-gray-700">
                                            <?php else: ?>
                                                <div
                                                    class="w-12 h-12 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-sm">
                                                    <i class="fa-solid fa-image"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Order + Auction + Parties -->
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <a href="../user/order-details.php?id=<?= (int) $o['auction_id'] ?>"
                                                    class="font-mono text-[11px] font-bold hover:text-indigo-600">
                                                    <?= htmlspecialchars($o['order_number']) ?>
                                                </a>
                                                <span
                                                    class="text-[10px] text-gray-400"><?= date('d M y', strtotime($o['created_at'])) ?></span>
                                            </div>
                                            <a href="../auction-details.php?id=<?= (int) $o['auction_id'] ?>"
                                                class="font-semibold text-sm hover:text-indigo-600 line-clamp-1 block mt-0.5">
                                                <?= htmlspecialchars($o['auction_title']) ?>
                                            </a>
                                            <div
                                                class="flex items-center gap-2 text-[11px] mt-1 text-gray-500 dark:text-gray-400">
                                                <span class="truncate max-w-[150px]"
                                                    title="<?= htmlspecialchars($o['buyer_name']) ?>">
                                                    <span
                                                        class="text-[8px] font-bold uppercase text-indigo-500 mr-0.5">B</span><?= htmlspecialchars($o['buyer_name']) ?>
                                                </span>
                                                <span class="text-gray-300 dark:text-gray-700">·</span>
                                                <span class="truncate max-w-[150px]"
                                                    title="<?= htmlspecialchars($o['seller_name']) ?>">
                                                    <span
                                                        class="text-[8px] font-bold uppercase text-violet-500 mr-0.5">S</span><?= htmlspecialchars($o['seller_name']) ?>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Amount -->
                                        <div class="shrink-0 text-right hidden sm:block">
                                            <div class="font-black text-sm tabular-nums">Rs.
                                                <?= number_format((float) $o['total_amount']) ?></div>
                                            <span
                                                class="inline-flex items-center mt-1 px-1.5 py-0.5 rounded <?= $pstyle['bg'] . ' ' . $pstyle['text'] ?> text-[9px] font-bold uppercase tracking-wider">
                                                <?= htmlspecialchars($pstyle['label']) ?>
                                            </span>
                                        </div>

                                        <!-- Status -->
                                        <div class="shrink-0 hidden lg:block">
                                            <span
                                                class="inline-flex items-center gap-1 px-2 py-1 rounded-lg <?= $style['bg'] . ' ' . $style['text'] ?> text-[10px] font-bold uppercase tracking-wider whitespace-nowrap">
                                                <span class="w-1.5 h-1.5 rounded-full <?= $style['dot'] ?>"></span>
                                                <?= $style['label'] ?>
                                            </span>
                                            <?php if (!empty($o['paid_at'])): ?>
                                                <div class="text-[9px] text-gray-400 mt-0.5 text-right">
                                                    <?= time_ago_safe($o['paid_at']) ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Actions -->
                                        <div class="shrink-0 flex items-center gap-1.5">
                                            <?php if ($is_actionable && !empty($o['payment_id'])): ?>
                                                <a href="payments.php?status=<?= $o['payment_status'] ?>&q=<?= urlencode($o['order_number']) ?>"
                                                    title="Verify Payment"
                                                    class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-amber-500 hover:bg-amber-600 text-white transition">
                                                    <i class="fa-solid fa-shield-halved text-sm"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="../user/order-details.php?id=<?= (int) $o['auction_id'] ?>"
                                                title="View Order"
                                                class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-indigo-600 hover:text-white text-gray-700 dark:text-gray-200 transition">
                                                <i class="fa-regular fa-eye text-sm"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Mobile cards -->
                        <div class="md:hidden divide-y divide-gray-100 dark:divide-gray-800">
                            <?php foreach ($orders as $o):
                                $style = $styles[$o['order_status']] ?? $default_style;
                                $pstyle = $payment_styles[$o['payment_status']] ?? $payment_styles['unpaid'];
                                $is_actionable = in_array($o['payment_status'], ['pending', 'processing'], true);
                                ?>
                                <div class="p-4 space-y-3">
                                    <div class="flex items-center justify-between">
                                        <span
                                            class="font-mono text-xs font-bold"><?= htmlspecialchars($o['order_number']) ?></span>
                                        <span
                                            class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md <?= $style['bg'] . ' ' . $style['text'] ?> text-[10px] font-bold uppercase">
                                            <span class="w-1.5 h-1.5 rounded-full <?= $style['dot'] ?>"></span>
                                            <?= $style['label'] ?>
                                        </span>
                                    </div>
                                    <a href="../auction-details.php?id=<?= (int) $o['auction_id'] ?>"
                                        class="font-semibold text-sm hover:text-indigo-600 line-clamp-2 block">
                                        <?= htmlspecialchars($o['auction_title']) ?>
                                    </a>
                                    <div class="grid grid-cols-2 gap-2 text-xs">
                                        <div><span class="text-gray-500">Buyer:</span>
                                            <strong><?= htmlspecialchars($o['buyer_name']) ?></strong>
                                        </div>
                                        <div><span class="text-gray-500">Seller:</span>
                                            <strong><?= htmlspecialchars($o['seller_name']) ?></strong>
                                        </div>
                                        <div><span class="text-gray-500">Amount:</span> <strong>Rs.
                                                <?= number_format((float) $o['total_amount']) ?></strong></div>
                                        <div>
                                            <span
                                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md <?= $pstyle['bg'] . ' ' . $pstyle['text'] ?> text-[10px] font-bold uppercase">
                                                <?= htmlspecialchars($pstyle['label']) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex gap-2 pt-1">
                                        <?php if ($is_actionable && !empty($o['payment_id'])): ?>
                                            <a href="payments.php?status=<?= $o['payment_status'] ?>&q=<?= urlencode($o['order_number']) ?>"
                                                class="flex-1 text-center px-3 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold">
                                                <i class="fa-solid fa-shield-halved mr-1"></i> Verify
                                            </a>
                                        <?php endif; ?>
                                        <a href="../user/order-details.php?id=<?= (int) $o['auction_id'] ?>"
                                            class="flex-1 text-center px-3 py-2 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-indigo-600 hover:text-white text-xs font-semibold">
                                            <i class="fa-regular fa-eye mr-1"></i> View
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <nav
                                class="flex flex-wrap items-center justify-between gap-4 px-6 py-4 border-t border-gray-100 dark:border-gray-800">
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    Showing <strong><?= count($orders) ?></strong> of
                                    <strong><?= number_format($total_orders) ?></strong> orders
                                </p>
                                <div class="flex items-center gap-1.5">
                                    <?php
                                    $qp = ['q' => $search_term, 'status' => $status_filter, 'payment' => $payment_filter];
                                    if ($page > 1) {
                                        $qp['page'] = $page - 1;
                                        echo '<a href="?' . htmlspecialchars(http_build_query($qp)) . '" class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100"><i class="fa-solid fa-chevron-left text-xs"></i></a>';
                                    }
                                    $window = 2;
                                    $start = max(1, $page - $window);
                                    $end = min($total_pages, $page + $window);
                                    for ($p = $start; $p <= $end; $p++):
                                        $qp['page'] = $p;
                                        $is_active = $p === $page;
                                        ?>
                                        <a href="?<?= htmlspecialchars(http_build_query($qp)) ?>"
                                            class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold <?= $is_active ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100' ?>">
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
        })();
    </script>

</body>

</html>