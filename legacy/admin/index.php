<?php

require_once "admin-auth.php";
require_once "../includes/notifications.php";

/* Fetch admin info */
$admin_stmt = $conn->prepare("SELECT full_name, email, avatar FROM users WHERE user_id = ?");
$admin_stmt->bind_param("i", $_SESSION["user_id"]);
$admin_stmt->execute();
$admin_info = $admin_stmt->get_result()->fetch_assoc();
$admin_name = $admin_info["full_name"] ?? "Admin";
$admin_email = $admin_info["email"] ?? "";
$admin_initials = strtoupper(substr(preg_replace('/\s+/', '', $admin_name), 0, 2));
$unread_notifications = nb_unread_count($conn, intval($_SESSION["user_id"]));

/* Safe count helper */
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

/* Safe scalar helper (single-value queries) */
if (!function_exists('safe_scalar')) {
    function safe_scalar($conn, $sql, $default = 0)
    {
        try {
            $r = $conn->query($sql);
            if (!$r)
                return $default;
            $row = $r->fetch_row();
            return $row[0] ?? $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

/* Sidebar + Dashboard KPIs */
$total_users = safe_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role != 'admin'");
$total_sellers = safe_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'seller'");
$total_auctions = safe_count($conn, "SELECT COUNT(*) AS total FROM auctions");
$pending_auctions = safe_count($conn, "SELECT COUNT(*) AS total FROM auctions WHERE auction_status = 'pending'");
$active_auctions = safe_count($conn, "SELECT COUNT(*) AS total FROM auctions WHERE auction_status = 'active'");
$total_bids = safe_count($conn, "SELECT COUNT(*) AS total FROM bids");
$total_winners = safe_count($conn, "SELECT COUNT(*) AS total FROM winners");
$total_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments");
$pending_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments WHERE payment_status NOT IN ('paid','refunded')");
$total_orders = safe_count($conn, "SELECT COUNT(*) AS total FROM orders");

/* ============================================================
   FINANCE & ESCROW WIDGET DATA
   ============================================================ */

/* Check which tables exist */
$has_escrow_tbl = false;
$has_payouts_tbl = false;
try {
    $r = $conn->query("SHOW TABLES LIKE 'escrow_holds'");
    $has_escrow_tbl = $r && $r->num_rows > 0;
} catch (Throwable $e) {
}
try {
    $r = $conn->query("SHOW TABLES LIKE 'payouts'");
    $has_payouts_tbl = $r && $r->num_rows > 0;
} catch (Throwable $e) {
}

/* Escrow stats */
$escrow_held_amount = 0.0;
$escrow_held_count = 0;
$escrow_disputed_count = 0;
$escrow_released_count = 0;

if ($has_escrow_tbl) {
    try {
        $r = $conn->query("
            SELECT 
                COUNT(*) AS cnt,
                COALESCE(SUM(amount - released_amount - refunded_amount), 0) AS amt
            FROM escrow_holds
            WHERE status IN ('held','partially_released')
        ");
        if ($r) {
            $row = $r->fetch_assoc();
            $escrow_held_count = intval($row['cnt'] ?? 0);
            $escrow_held_amount = floatval($row['amt'] ?? 0);
        }
    } catch (Throwable $e) {
    }

    $escrow_disputed_count = (int) safe_scalar(
        $conn,
        "SELECT COUNT(*) FROM escrow_holds WHERE status = 'disputed'",
        0
    );

    $escrow_released_count = (int) safe_scalar(
        $conn,
        "SELECT COUNT(*) FROM escrow_holds WHERE status = 'released'",
        0
    );
}

/* Pending payments amount */
$pending_payments_amount = 0.0;
try {
    $r = $conn->query("
        SELECT COALESCE(SUM(amount), 0) AS amt
        FROM payments
        WHERE payment_status IN ('pending','processing')
    ");
    if ($r)
        $pending_payments_amount = floatval($r->fetch_assoc()['amt'] ?? 0);
} catch (Throwable $e) {
}

/* Pending payouts */
$pending_payouts_count = 0;
$pending_payouts_amount = 0.0;

if ($has_payouts_tbl) {
    try {
        $cols = [];
        $cr = $conn->query("SHOW COLUMNS FROM payouts");
        while ($c = $cr->fetch_assoc())
            $cols[] = $c['Field'];

        $amt_col = in_array('amount', $cols, true) ? 'amount' : null;
        $status_col = in_array('status', $cols, true) ? 'status' : null;

        if ($status_col) {
            $sql = "SELECT COUNT(*) AS c"
                . ($amt_col ? ", COALESCE(SUM(`$amt_col`), 0) AS amt" : "")
                . " FROM payouts WHERE `$status_col` = 'pending'";
            $r = $conn->query($sql);
            if ($r) {
                $row = $r->fetch_assoc();
                $pending_payouts_count = intval($row['c'] ?? 0);
                $pending_payouts_amount = $amt_col ? floatval($row['amt'] ?? 0) : 0.0;
            }
        }
    } catch (Throwable $e) {
    }
}

/* Compact money formatter */
if (!function_exists('compact_rs_admin')) {
    function compact_rs_admin($a)
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

/* RECENT AUCTIONS */
$recent_auctions_result = $conn->query("
    SELECT a.*, u.full_name, c.category_name
    FROM auctions a
    INNER JOIN users u ON a.seller_id = u.user_id
    INNER JOIN categories c ON a.category_id = c.category_id
    ORDER BY a.created_at DESC
    LIMIT 8
");
$recent_auctions = $recent_auctions_result ? $recent_auctions_result->fetch_all(MYSQLI_ASSOC) : [];

/* Weekly bids chart */
$week_bids = $conn->query("
    SELECT DATE(bid_time) AS day, COUNT(*) AS total
    FROM bids
    WHERE bid_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(bid_time)
    ORDER BY day ASC
");
$week_bids_data = $week_bids ? $week_bids->fetch_all(MYSQLI_ASSOC) : [];
$max_bids = 1;
foreach ($week_bids_data as $d) {
    $max_bids = max($max_bids, intval($d['total']));
}

/* Status color map */
$status_styles = [
    'active' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'border' => 'border-emerald-500/20', 'dot' => 'bg-emerald-500', 'icon' => 'fa-bolt'],
    'pending' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'border' => 'border-amber-500/20', 'dot' => 'bg-amber-500', 'icon' => 'fa-clock'],
    'completed' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'border' => 'border-blue-500/20', 'dot' => 'bg-blue-500', 'icon' => 'fa-circle-check'],
    'ended' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-700 dark:text-gray-400', 'border' => 'border-gray-500/20', 'dot' => 'bg-gray-500', 'icon' => 'fa-flag-checkered'],
    'rejected' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'border' => 'border-rose-500/20', 'dot' => 'bg-rose-500', 'icon' => 'fa-xmark'],
];
$default_status = $status_styles['ended'];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | NexusBids</title>
    <meta name="description" content="NexusBids admin dashboard — manage users, auctions, and marketplace activity.">

    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif']},
                    animation: {
                        'fade-up': 'fadeUp 0.6s ease-out forwards',
                        'pulse-soft': 'pulseSoft 2s ease-in-out infinite',
                        'float': 'float 6s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeUp: {'0%': {opacity: '0', transform: 'translateY(16px)'}, '100%': {opacity: '1', transform: 'translateY(0)'}},
                        pulseSoft: {'0%, 100%': {opacity: '1'}, '50%': {opacity: '0.5'}},
                        float: {'0%, 100%': {transform: 'translateY(0)'}, '50%': {transform: 'translateY(-10px)'}},
                    }
                }
            }
        };
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <style>
        html {
            scroll-behavior: smooth;
        }

        body {
            transition: background-color .3s ease, color .3s ease;
            font-feature-settings: 'cv02', 'cv03', 'cv04', 'cv11';
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
        }

        .gradient-text {
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
        }

        .noise-overlay::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 1;
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .dark ::-webkit-scrollbar-thumb {
            background: #334155;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .dark ::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation-duration: .01ms !important;
                transition-duration: .01ms !important;
            }
        }

        .sidebar-link {
            position: relative;
            transition: all .2s ease;
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

        .data-row {
            transition: background-color .2s ease;
        }

        .data-row:hover {
            background-color: rgb(238 242 255 / 0.5);
        }

        .dark .data-row:hover {
            background-color: rgb(30 41 59 / 0.5);
        }
    </style>
</head>

<body class="bg-gray-50 text-gray-900 dark:bg-slate-950 dark:text-gray-100 font-sans antialiased min-h-screen">

    <div class="flex min-h-screen">

        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="flex-1 lg:ml-64 min-w-0">

            <header
                class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
                <div class="h-20 px-4 sm:px-8 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="min-w-0">
                            <div
                                class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span> Admin Panel
                            </div>
                            <h1 class="text-lg sm:text-xl font-black truncate">Dashboard Overview</h1>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 sm:gap-3">
                        <button aria-label="Toggle Theme" id="themeToggle"
                            class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                            <i class="fa-solid fa-moon dark:hidden"></i><i
                                class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                        </button>
                        <a href="../notifications.php"
                            class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($unread_notifications > 0): ?><span
                                    class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800 animate-pulse-soft"></span><?php endif; ?>
                        </a>
                        <div class="flex items-center gap-3 pl-3 border-l border-gray-200 dark:border-gray-800">
                            <div class="relative">
                                <div
                                    class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg ring-2 ring-white dark:ring-slate-900">
                                    <?php echo htmlspecialchars($admin_initials); ?>
                                </div>
                                <span
                                    class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900"></span>
                            </div>
                            <div class="hidden sm:block text-left">
                                <div class="text-sm font-bold leading-tight truncate max-w-32">
                                    <?php echo htmlspecialchars($admin_name); ?>
                                </div>
                                <div
                                    class="text-[11px] text-indigo-600 dark:text-indigo-400 font-medium flex items-center gap-1">
                                    <i class="fa-solid fa-shield-halved text-[8px]"></i> Admin
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <?php
            // After existing $unread_notifications line, add:
            require_once "../includes/kyc-helpers.php";
            $user_kyc_status = $user['kyc_status'] ?? 'unverified';
            ?>

           

            <div class="p-4 sm:p-6 lg:p-8 space-y-6 lg:space-y-8">

                <!-- Welcome Hero -->
                <section
                    class="relative overflow-hidden rounded-3xl bg-slate-950 text-white p-6 sm:p-8 shadow-2xl border border-gray-800 noise-overlay animate-fade-up">
                    <div
                        class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-indigo-900/40 via-slate-950 to-slate-950">
                    </div>
                    <div
                        class="absolute -right-16 -top-16 w-80 h-80 bg-indigo-600/20 rounded-full blur-3xl pointer-events-none animate-float">
                    </div>
                    <div
                        class="absolute left-1/4 bottom-0 w-64 h-64 bg-violet-600/10 rounded-full blur-3xl pointer-events-none">
                    </div>

                    <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                        <div class="space-y-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[11px] font-bold uppercase tracking-wider">
                                    <span class="relative flex h-2 w-2">
                                        <span
                                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-400"></span>
                                    </span>
                                    System Online
                                </span>
                                <span
                                    class="text-[11px] text-gray-400 font-mono tracking-wider"><?php echo date("D, d M Y"); ?></span>
                            </div>
                            <h2 class="text-2xl sm:text-3xl lg:text-4xl font-black tracking-tight leading-tight">
                                Welcome back, <span
                                    class="gradient-text bg-gradient-to-r from-blue-400 via-indigo-400 to-violet-400"><?php echo htmlspecialchars(explode(' ', $admin_name)[0]); ?></span>
                            </h2>
                            <p class="text-gray-300 text-sm max-w-xl leading-relaxed">You're viewing the NexusBids admin
                                control panel. Monitor platform activity and manage users, auctions, and bids below.</p>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <?php if ($pending_auctions > 0): ?>
                                <a class="group px-6 py-3 rounded-xl bg-amber-500 hover:bg-amber-400 text-white text-sm font-semibold transition hover:-translate-y-0.5 shadow-lg shadow-amber-500/30 flex items-center gap-2"
                                    href="auctions.php">
                                    <i class="fa-solid fa-clock"></i>
                                    Review Pending (<?php echo $pending_auctions; ?>)
                                    <i
                                        class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform"></i>
                                </a>
                            <?php endif; ?>
                            <a class="group px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition hover:-translate-y-0.5 shadow-lg shadow-indigo-600/30 flex items-center gap-2"
                                href="auctions.php">
                                <i class="fa-solid fa-box"></i>
                                Manage Auctions
                                <i
                                    class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform"></i>
                            </a>
                        </div>
                    </div>
                </section>

                <!-- Stat Cards Grid -->
                <section class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4 animate-fade-up">
                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 hover:shadow-lg hover:shadow-indigo-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Users</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-users"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($total_users); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1 flex items-center gap-1">
                            <i class="fa-solid fa-circle-check text-[8px] text-emerald-500"></i> Excludes admins
                        </p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-violet-500 hover:shadow-lg hover:shadow-violet-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Sellers</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-store"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($total_sellers); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Active vendors</p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-blue-500 hover:shadow-lg hover:shadow-blue-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Auctions</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-box"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($total_auctions); ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">All listings</p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border-2 <?php echo $pending_auctions > 0 ? 'border-amber-500/40' : 'border-gray-200 dark:border-gray-800'; ?> hover:shadow-lg hover:shadow-amber-500/10 transition hover:-translate-y-0.5 relative">
                        <?php if ($pending_auctions > 0): ?>
                            <div class="absolute top-2 right-2 w-2 h-2 rounded-full bg-amber-500 animate-pulse-soft"></div>
                        <?php endif; ?>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Pending</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-clock"></i></span>
                        </div>
                        <div
                            class="text-2xl font-black <?php echo $pending_auctions > 0 ? 'text-amber-600 dark:text-amber-400' : ''; ?> tabular-nums">
                            <?php echo number_format($pending_auctions); ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1 flex items-center gap-1">
                            <?php if ($pending_auctions > 0): ?>
                                <i class="fa-solid fa-triangle-exclamation text-[8px] text-amber-500"></i> Needs review
                            <?php else: ?>
                                All caught up
                            <?php endif; ?>
                        </p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-emerald-500 hover:shadow-lg hover:shadow-emerald-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Active</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-bolt"></i></span>
                        </div>
                        <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                            <?php echo number_format($active_auctions); ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Live now
                        </p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-rose-500 hover:shadow-lg hover:shadow-rose-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Bids</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-gavel"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($total_bids); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Total placed</p>
                    </div>
                </section>

                <!-- ============================================================
                     FINANCE & ESCROW WIDGET (NEW)
                     ============================================================ -->
                <section class="animate-fade-up" style="animation-delay: 0.05s">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                        <div class="flex items-center gap-3">
                            <span
                                class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-vault"></i>
                            </span>
                            <div>
                                <h2 class="text-base font-bold">Finance &amp; Escrow</h2>
                                <p class="text-xs text-gray-500 mt-0.5">Money flow at a glance</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="escrow.php"
                                class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline whitespace-nowrap">
                                View Escrow →
                            </a>
                            <a href="payments.php"
                                class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline whitespace-nowrap">
                                View Payments →
                            </a>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

                        <!-- Card 1: Escrow Held -->
                        <a href="escrow.php?status=held"
                            class="group bg-gradient-to-br from-amber-500 to-orange-500 rounded-2xl p-5 text-white shadow-lg hover:shadow-amber-500/30 transition hover:-translate-y-0.5 relative overflow-hidden">
                            <div
                                class="absolute -right-6 -top-6 w-24 h-24 bg-white/10 rounded-full blur-2xl pointer-events-none">
                            </div>
                            <div class="relative">
                                <div class="flex items-center justify-between mb-3">
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/85">Escrow
                                        Held</span>
                                    <span
                                        class="w-9 h-9 rounded-lg bg-white/20 flex items-center justify-center text-xs group-hover:scale-110 transition">
                                        <i class="fa-solid fa-lock"></i>
                                    </span>
                                </div>
                                <div class="text-2xl font-black tabular-nums">
                                    <?php echo compact_rs_admin($escrow_held_amount); ?>
                                </div>
                                <p class="text-[10px] text-white/85 mt-1 flex items-center gap-1">
                                    <i class="fa-solid fa-circle-check text-[8px]"></i>
                                    <?php echo number_format($escrow_held_count); ?> active
                                    hold<?php echo $escrow_held_count === 1 ? '' : 's'; ?>
                                </p>
                            </div>
                        </a>

                        <!-- Card 2: Disputed -->
                        <a href="escrow.php?status=disputed"
                            class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border <?php echo $escrow_disputed_count > 0 ? 'border-rose-500/40' : 'border-gray-200 dark:border-gray-800'; ?> hover:border-rose-500 hover:shadow-lg hover:shadow-rose-500/10 transition hover:-translate-y-0.5 relative">
                            <?php if ($escrow_disputed_count > 0): ?>
                                <div class="absolute top-2 right-2 w-2 h-2 rounded-full bg-rose-500 animate-pulse-soft">
                                </div>
                            <?php endif; ?>
                            <div class="flex items-center justify-between mb-3">
                                <span
                                    class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Disputed</span>
                                <span
                                    class="w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-xs group-hover:scale-110 transition">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                </span>
                            </div>
                            <div
                                class="text-2xl font-black <?php echo $escrow_disputed_count > 0 ? 'text-rose-600 dark:text-rose-400' : ''; ?> tabular-nums">
                                <?php echo number_format($escrow_disputed_count); ?>
                            </div>
                            <p class="text-[10px] text-gray-500 mt-1">
                                <?php echo $escrow_disputed_count > 0 ? 'Frozen — needs review' : 'All clear'; ?>
                            </p>
                        </a>

                        <!-- Card 3: Pending Payments -->
                        <a href="payments.php?status=pending"
                            class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border <?php echo $pending_payments > 0 ? 'border-amber-500/40' : 'border-gray-200 dark:border-gray-800'; ?> hover:border-amber-500 hover:shadow-lg hover:shadow-amber-500/10 transition hover:-translate-y-0.5 relative">
                            <?php if ($pending_payments > 0): ?>
                                <div class="absolute top-2 right-2 w-2 h-2 rounded-full bg-amber-500 animate-pulse-soft">
                                </div>
                            <?php endif; ?>
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Awaiting
                                    Verify</span>
                                <span
                                    class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xs group-hover:scale-110 transition">
                                    <i class="fa-solid fa-clock"></i>
                                </span>
                            </div>
                            <div
                                class="text-2xl font-black <?php echo $pending_payments > 0 ? 'text-amber-600 dark:text-amber-400' : ''; ?> tabular-nums">
                                <?php echo number_format($pending_payments); ?>
                            </div>
                            <p class="text-[10px] text-gray-500 mt-1">
                                <?php if ($pending_payments > 0): ?>
                                    <?php echo compact_rs_admin($pending_payments_amount); ?> awaiting
                                <?php else: ?>
                                    All caught up
                                <?php endif; ?>
                            </p>
                        </a>

                        <!-- Card 4: Pending Payouts -->
                        <a href="payouts.php"
                            class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border <?php echo $pending_payouts_count > 0 ? 'border-violet-500/40' : 'border-gray-200 dark:border-gray-800'; ?> hover:border-violet-500 hover:shadow-lg hover:shadow-violet-500/10 transition hover:-translate-y-0.5 relative">
                            <?php if ($pending_payouts_count > 0): ?>
                                <div class="absolute top-2 right-2 w-2 h-2 rounded-full bg-violet-500 animate-pulse-soft">
                                </div>
                            <?php endif; ?>
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Payout
                                    Requests</span>
                                <span
                                    class="w-9 h-9 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-xs group-hover:scale-110 transition">
                                    <i class="fa-solid fa-money-bill-transfer"></i>
                                </span>
                            </div>
                            <div
                                class="text-2xl font-black <?php echo $pending_payouts_count > 0 ? 'text-violet-600 dark:text-violet-400' : ''; ?> tabular-nums">
                                <?php echo number_format($pending_payouts_count); ?>
                            </div>
                            <p class="text-[10px] text-gray-500 mt-1">
                                <?php if (!$has_payouts_tbl): ?>
                                    Payouts not set up
                                <?php elseif ($pending_payouts_count > 0): ?>
                                    <?php echo compact_rs_admin($pending_payouts_amount); ?> to approve
                                <?php else: ?>
                                    No pending requests
                                <?php endif; ?>
                            </p>
                        </a>
                    </div>
                </section>
                <!-- ============================================================
                     /FINANCE & ESCROW WIDGET
                     ============================================================ -->

                <!-- Recent Auctions + Weekly Activity -->
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 animate-fade-up">

                    <div
                        class="xl:col-span-2 bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                        <div
                            class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                            <div class="flex items-center gap-3">
                                <span
                                    class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                </span>
                                <div>
                                    <h2 class="text-base font-bold">Recent Auctions</h2>
                                    <p class="text-xs text-gray-500 mt-0.5">Latest submissions from sellers</p>
                                </div>
                            </div>
                            <a href="auctions.php"
                                class="group inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition whitespace-nowrap">
                                View All
                                <i
                                    class="fa-solid fa-arrow-right text-[10px] group-hover:translate-x-0.5 transition-transform"></i>
                            </a>
                        </div>

                        <?php if (!empty($recent_auctions)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead
                                        class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                        <tr>
                                            <th
                                                class="px-6 py-3 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                                Item</th>
                                            <th
                                                class="px-6 py-3 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                                Seller</th>
                                            <th
                                                class="px-6 py-3 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                                Category</th>
                                            <th
                                                class="px-6 py-3 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                                Price</th>
                                            <th
                                                class="px-6 py-3 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                                Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                        <?php foreach ($recent_auctions as $auction):
                                            $status = strtolower($auction["auction_status"] ?? "ended");
                                            $style = $status_styles[$status] ?? $default_status;
                                            ?>
                                            <tr class="data-row">
                                                <td class="px-6 py-4">
                                                    <a href="../auction-details.php?id=<?php echo (int) $auction['auction_id']; ?>"
                                                        class="font-semibold hover:text-indigo-600 transition line-clamp-1">
                                                        <?php echo htmlspecialchars($auction["title"]); ?>
                                                    </a>
                                                    <span
                                                        class="text-[10px] text-gray-400 font-mono">#<?php echo str_pad((int) $auction['auction_id'], 5, '0', STR_PAD_LEFT); ?></span>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center gap-2">
                                                        <div
                                                            class="w-7 h-7 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-500 text-white flex items-center justify-center text-[9px] font-bold shrink-0">
                                                            <?php echo htmlspecialchars(strtoupper(substr(preg_replace('/\s+/', '', $auction['full_name']), 0, 2))); ?>
                                                        </div>
                                                        <span
                                                            class="text-xs font-medium truncate"><?php echo htmlspecialchars($auction["full_name"]); ?></span>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <span
                                                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 text-[10px] font-bold">
                                                        <?php echo htmlspecialchars($auction["category_name"]); ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 text-xs font-bold tabular-nums">Rs.
                                                    <?php echo number_format((float) $auction["starting_price"]); ?>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <span
                                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?php echo $style['bg'] . ' ' . $style['text'] . ' ' . $style['border']; ?> text-[10px] font-bold border">
                                                        <span
                                                            class="w-1.5 h-1.5 rounded-full <?php echo $style['dot']; ?>"></span>
                                                        <?php echo ucfirst($status); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="p-12 text-center">
                                <div
                                    class="w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-2xl mx-auto mb-4">
                                    <i class="fa-solid fa-box-open"></i>
                                </div>
                                <h3 class="font-bold">No auctions found</h3>
                                <p class="text-sm text-gray-500 mt-1">Auctions will appear here once sellers submit them.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div
                        class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                        <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                            <span
                                class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-chart-line"></i>
                            </span>
                            <div>
                                <h2 class="text-base font-bold">Weekly Activity</h2>
                                <p class="text-xs text-gray-500 mt-0.5">Bids per day (7d)</p>
                            </div>
                        </div>

                        <div class="p-6">
                            <?php if (!empty($week_bids_data)): ?>
                                <div class="space-y-4">
                                    <?php foreach ($week_bids_data as $day):
                                        $pct = ($day['total'] / $max_bids) * 100;
                                        $is_today = date('Y-m-d') === $day['day'];
                                        ?>
                                        <div>
                                            <div class="flex items-center justify-between mb-1.5">
                                                <span class="text-xs font-bold">
                                                    <?php echo date('D', strtotime($day['day'])); ?>
                                                    <?php if ($is_today): ?>
                                                        <span
                                                            class="text-[9px] font-bold text-indigo-600 dark:text-indigo-400 uppercase tracking-wider ml-1">Today</span>
                                                    <?php endif; ?>
                                                </span>
                                                <span
                                                    class="text-xs font-black tabular-nums"><?php echo number_format($day['total']); ?></span>
                                            </div>
                                            <div class="h-2 rounded-full bg-gray-100 dark:bg-slate-800 overflow-hidden">
                                                <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500 transition-all duration-700"
                                                    style="width: <?php echo max(4, $pct); ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-8">
                                    <div
                                        class="w-12 h-12 rounded-2xl bg-gray-100 dark:bg-slate-800 text-gray-400 flex items-center justify-center text-lg mx-auto mb-3">
                                        <i class="fa-solid fa-chart-simple"></i>
                                    </div>
                                    <p class="text-xs text-gray-500">No bidding activity in the last 7 days.</p>
                                </div>
                            <?php endif; ?>

                            <div class="mt-6 pt-5 border-t border-gray-100 dark:border-gray-800 space-y-2.5">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-gray-500 flex items-center gap-1.5"><i
                                            class="fa-solid fa-gavel text-[10px]"></i> Total bids</span>
                                    <span
                                        class="font-black tabular-nums"><?php echo number_format($total_bids); ?></span>
                                </div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-gray-500 flex items-center gap-1.5"><i
                                            class="fa-solid fa-bolt text-[10px]"></i> Active auctions</span>
                                    <span
                                        class="font-black text-emerald-600 dark:text-emerald-400 tabular-nums"><?php echo number_format($active_auctions); ?></span>
                                </div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-gray-500 flex items-center gap-1.5"><i
                                            class="fa-solid fa-clock text-[10px]"></i> Pending review</span>
                                    <span
                                        class="font-black <?php echo $pending_auctions > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400'; ?> tabular-nums"><?php echo number_format($pending_auctions); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <section class="grid grid-cols-2 sm:grid-cols-4 gap-4 animate-fade-up">
                    <a href="users.php"
                        class="group bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 hover:border-indigo-500 p-5 transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/10 flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-base group-hover:scale-110 transition shrink-0">
                            <i class="fa-solid fa-users"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="text-sm font-bold truncate">Manage Users</div>
                            <div class="text-[11px] text-gray-500">Accounts &amp; roles</div>
                        </div>
                    </a>
                    <a href="auctions.php"
                        class="group bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 hover:border-amber-500 p-5 transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-amber-500/10 flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-base group-hover:scale-110 transition shrink-0">
                            <i class="fa-solid fa-clock"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="text-sm font-bold truncate">Review Pending</div>
                            <div class="text-[11px] text-gray-500"><?php echo $pending_auctions; ?> awaiting</div>
                        </div>
                    </a>
                    <a href="payouts.php"
                        class="group bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 hover:border-violet-500 p-5 transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-violet-500/10 flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-base group-hover:scale-110 transition shrink-0">
                            <i class="fa-solid fa-wallet"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="text-sm font-bold truncate">Payout Approvals</div>
                            <div class="text-[11px] text-gray-500">Seller withdrawals</div>
                        </div>
                    </a>
                    <a href="bids.php"
                        class="group bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 hover:border-rose-500 p-5 transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-rose-500/10 flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-xl bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-base group-hover:scale-110 transition shrink-0">
                            <i class="fa-solid fa-gavel"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="text-sm font-bold truncate">Bid History</div>
                            <div class="text-[11px] text-gray-500">All transactions</div>
                        </div>
                    </a>
                </section>
            </div>

            <footer class="mt-8 border-t border-gray-200 dark:border-gray-800 px-4 sm:px-8 py-6">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500">
                    <p>© <?php echo date("Y"); ?> NexusBids Admin Panel. All rights reserved.</p>
                    <p class="flex items-center gap-1.5">Admin Console <span
                            class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span></p>
                </div>
            </footer>
        </main>
    </div>

    <script>
        (function () {
            const html = document.documentElement;
            const themeToggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            themeToggle?.addEventListener('click', function () {
                html.classList.toggle('dark');
                localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
            });
        })();
    </script>
</body>

</html>