<?php

session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "user") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/notifications.php";

date_default_timezone_set('Asia/Karachi');

$user_id = intval($_SESSION["user_id"]);

/* User info */
$user_stmt = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_info = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

$full_name = $user_info["full_name"] ?? "User";
$avatar = $user_info["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $user_id);

/* ---------- GLOBAL STATS (SQL-based — accurate for all bids) ---------- */
$global_stats = [
    'total' => 0,
    'leading' => 0,
    'outbid' => 0,
    'auto' => 0,
    'manual' => 0,
];

$stats_sql = "
    SELECT
        COUNT(*) AS total_bids,
        SUM(CASE WHEN b.is_auto_bid = 1 THEN 1 ELSE 0 END) AS auto_bids,
        SUM(CASE WHEN b.is_auto_bid = 0 OR b.is_auto_bid IS NULL THEN 1 ELSE 0 END) AS manual_bids
    FROM bids b
    WHERE b.bidder_id = ?
";
if ($st = $conn->prepare($stats_sql)) {
    $st->bind_param("i", $user_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    $global_stats['total'] = intval($row['total_bids'] ?? 0);
    $global_stats['auto'] = intval($row['auto_bids'] ?? 0);
    $global_stats['manual'] = intval($row['manual_bids'] ?? 0);
}

/* Leading/Outbid — must be computed per-auction against current_bid */
$lead_sql = "
    SELECT
        SUM(CASE WHEN b.bid_amount >= a.current_bid AND a.auction_status = 'active' THEN 1 ELSE 0 END) AS leading_count,
        SUM(CASE WHEN b.bid_amount < a.current_bid AND a.auction_status = 'active' THEN 1 ELSE 0 END) AS outbid_count
    FROM bids b
    INNER JOIN auctions a ON b.auction_id = a.auction_id
    WHERE b.bidder_id = ?
      AND b.bid_id IN (
          SELECT MAX(b2.bid_id)
          FROM bids b2
          WHERE b2.bidder_id = b.bidder_id
          GROUP BY b2.auction_id
      )
";
if ($ls = $conn->prepare($lead_sql)) {
    $ls->bind_param("i", $user_id);
    $ls->execute();
    $row = $ls->get_result()->fetch_assoc();
    $ls->close();
    $global_stats['leading'] = intval($row['leading_count'] ?? 0);
    $global_stats['outbid'] = intval($row['outbid_count'] ?? 0);
}

/* Pagination */
$per_page = 15;
$page = max(1, intval($_GET["page"] ?? 1));

$total_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM bids WHERE bidder_id = ?");
$total_stmt->bind_param("i", $user_id);
$total_stmt->execute();
$total_bids = intval($total_stmt->get_result()->fetch_assoc()["total"] ?? 0);
$total_stmt->close();

$total_pages = max(1, (int) ceil($total_bids / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$stmt = $conn->prepare("
    SELECT b.bid_amount, b.bid_time, b.is_auto_bid,
        a.auction_id, a.title, a.current_bid, a.auction_status, a.end_time
    FROM bids b
    INNER JOIN auctions a ON b.auction_id = a.auction_id
    WHERE b.bidder_id = ?
    ORDER BY b.bid_time DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("iii", $user_id, $per_page, $offset);
$stmt->execute();
$bids_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bids | NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    animation: { 'fade-up': 'fadeUp 0.6s ease-out forwards', 'pulse-soft': 'pulseSoft 2s ease-in-out infinite' },
                    keyframes: { fadeUp: { '0%': { opacity: '0', transform: 'translateY(16px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } }, pulseSoft: { '0%, 100%': { opacity: '1' }, '50%': { opacity: '0.5' } } }
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
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%);
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

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation-duration: .01ms !important;
                transition-duration: .01ms !important;
            }
        }

        .bid-row {
            transition: background-color .2s ease;
        }

        .bid-row:hover {
            background-color: rgb(238 242 255 / 0.5);
        }

        .dark .bid-row:hover {
            background-color: rgb(30 41 59 / 0.5);
        }

        .custom-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            appearance: none;
        }
    </style>
</head>

<body
    class="bg-gray-50 text-gray-900 dark:bg-slate-950 dark:text-gray-100 font-sans antialiased min-h-screen flex flex-col">

    <!-- Top Utility Bar -->
    <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50">
        <div class="max-w-[1600px] mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-6">
                <span class="flex items-center gap-2"><i class="fa-solid fa-location-dot text-indigo-400"></i>
                    Pakistan</span>
                <span class="hidden sm:flex items-center gap-2"><i class="fa-solid fa-phone text-indigo-400"></i> +92
                    300 1234567</span>
            </div>
            <div class="flex items-center space-x-6">
                <a href="../about.php" class="hover:text-white transition"><i
                        class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
                <a href="../about.php#why-nexusbids" class="hover:text-white transition"><i
                        class="fa-solid fa-shield-halved text-indigo-400"></i> Secure Bidding</a>
            </div>
        </div>
    </div>

    <!-- Navbar -->
    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-[1600px] mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight shrink-0 group" href="../index.php">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg group-hover:scale-105 transition-all">
                    <i class="fa-solid fa-gavel text-lg"></i>
                </span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            </a>
            <nav class="hidden lg:flex items-center gap-7 text-sm font-medium">
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../index.php">Home</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../auctions.php">Auctions</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../categories.php">Categories</a>
                <a class="relative text-indigo-600 dark:text-indigo-400 font-semibold after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-indigo-600 after:rounded-full"
                    href="dashboard.php">Dashboard</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../about.php">About</a>
            </nav>
            <div class="flex items-center gap-2 sm:gap-3">
                <button id="themeToggle"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                    <i class="fa-solid fa-moon dark:hidden"></i><i
                        class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>
                <a href="../notifications.php"
                    class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unread_notifications > 0): ?><span
                            class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800 animate-pulse-soft"></span><?php endif; ?>
                </a>
                <div class="flex items-center gap-3 pl-3 border-l border-gray-200 dark:border-gray-800">
                    <div class="relative">
                        <?php if (!empty($avatar)): ?>
                            <img src="../uploads/avatars/<?php echo htmlspecialchars($avatar); ?>"
                                class="w-10 h-10 rounded-full object-cover ring-2 ring-white dark:ring-slate-900" alt="">
                        <?php else: ?>
                            <div
                                class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg ring-2 ring-white dark:ring-slate-900">
                                <?php echo htmlspecialchars($initials); ?></div>
                        <?php endif; ?>
                        <span
                            class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900"></span>
                    </div>
                    <div class="hidden sm:block text-left">
                        <div class="text-sm font-bold leading-tight truncate max-w-32">
                            <?php echo htmlspecialchars($full_name); ?></div>
                        <div
                            class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1">
                            <i class="fa-solid fa-circle-check text-[8px]"></i> Verified Bidder
                        </div>
                    </div>
                </div>
                <a class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition"
                    href="../auth/logout.php">
                    <i class="fa-solid fa-right-from-bracket mr-1.5"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-[1600px] mx-auto w-full px-4 sm:px-8 py-8 space-y-8">

        <!-- Page Header -->
        <section class="animate-fade-up">
            <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                <a href="dashboard.php" class="hover:text-indigo-600">Dashboard</a>
                <i class="fa-solid fa-chevron-right text-[8px]"></i>
                <span class="text-gray-900 dark:text-white font-semibold">My Bids</span>
            </nav>
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl sm:text-4xl font-black tracking-tight flex items-center gap-3">
                        <span
                            class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white shadow-lg">
                            <i class="fa-solid fa-gavel text-lg"></i>
                        </span>
                        My Bids
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Every bid you've placed, most recent first.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="../auctions.php"
                        class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition flex items-center gap-2">
                        <i class="fa-solid fa-magnifying-glass"></i> Browse Auctions
                    </a>
                    <a href="dashboard.php"
                        class="px-5 py-2.5 rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 text-gray-700 dark:text-gray-200 text-sm font-semibold transition flex items-center gap-2">
                        <i class="fa-solid fa-chart-line"></i> Dashboard
                    </a>
                </div>
            </div>
        </section>

        <!-- Stats (SQL-based, accurate) -->
        <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 animate-fade-up">
            <div
                class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 hover:shadow-lg transition">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Total Bids</span>
                    <span
                        class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                            class="fa-solid fa-gavel"></i></span>
                </div>
                <div class="text-2xl font-black tabular-nums"><?php echo number_format($global_stats['total']); ?></div>
                <p class="text-[11px] text-gray-500 mt-1">Lifetime placed</p>
            </div>

            <div
                class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-emerald-500 hover:shadow-lg transition">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Leading</span>
                    <span
                        class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                            class="fa-solid fa-arrow-trend-up"></i></span>
                </div>
                <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                    <?php echo $global_stats['leading']; ?></div>
                <p class="text-[11px] text-gray-500 mt-1">Active auctions you lead</p>
            </div>

            <div
                class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-rose-500 hover:shadow-lg transition">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Outbid</span>
                    <span
                        class="w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                            class="fa-solid fa-arrow-trend-down"></i></span>
                </div>
                <div class="text-2xl font-black text-rose-600 dark:text-rose-400 tabular-nums">
                    <?php echo $global_stats['outbid']; ?></div>
                <p class="text-[11px] text-gray-500 mt-1">Needs attention</p>
            </div>

            <div
                class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-violet-500 hover:shadow-lg transition">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Auto Bids</span>
                    <span
                        class="w-9 h-9 rounded-lg bg-violet-500/10 text-violet-600 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                            class="fa-solid fa-robot"></i></span>
                </div>
                <div class="text-2xl font-black tabular-nums"><?php echo $global_stats['auto']; ?></div>
                <p class="text-[11px] text-gray-500 mt-1"><?php echo $global_stats['manual']; ?> manual bids</p>
            </div>
        </section>

        <!-- Table -->
        <section
            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden animate-fade-up">

            <div
                class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row justify-between items-center gap-4">
                <div class="relative w-full sm:w-72">
                    <i
                        class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    <input id="bidTableSearch"
                        class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        placeholder="Search by auction name..." type="text" autocomplete="off">
                </div>
                <div class="flex items-center gap-2">
                    <select id="statusFilter"
                        class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                        <option value="all">All Status</option>
                        <option value="leading">Leading</option>
                        <option value="outbid">Outbid</option>
                        <option value="ended">Ended</option>
                    </select>
                    <select id="typeFilter"
                        class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                        <option value="all">All Types</option>
                        <option value="auto">Auto</option>
                        <option value="manual">Manual</option>
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm" id="bidsTable">
                    <thead class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th
                                class="px-6 py-4 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                Auction</th>
                            <th
                                class="px-6 py-4 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                Your Bid</th>
                            <th
                                class="px-6 py-4 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                Current</th>
                            <th
                                class="px-6 py-4 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                Type</th>
                            <th
                                class="px-6 py-4 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                Status</th>
                            <th
                                class="px-6 py-4 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                Placed</th>
                            <th
                                class="px-6 py-4 text-right text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <?php if (!empty($bids_data)): ?>
                            <?php foreach ($bids_data as $bid):
                                $is_leading = floatval($bid["bid_amount"]) >= floatval($bid["current_bid"]);
                                $status_key = $bid["auction_status"] === "active" ? ($is_leading ? "leading" : "outbid") : "ended";
                                $type_key = $bid["is_auto_bid"] ? "auto" : "manual";
                                ?>
                                <tr class="bid-row" data-title="<?php echo htmlspecialchars(strtolower($bid['title'])); ?>"
                                    data-status="<?php echo $status_key; ?>" data-type="<?php echo $type_key; ?>">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="w-10 h-10 rounded-xl <?php echo $status_key === 'leading' ? 'bg-emerald-500/10 text-emerald-600' : ($status_key === 'outbid' ? 'bg-rose-500/10 text-rose-600' : 'bg-gray-500/10 text-gray-500'); ?> flex items-center justify-center text-sm shrink-0">
                                                <i class="fa-solid fa-gavel"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <a href="../auction-details.php?id=<?php echo (int) $bid["auction_id"]; ?>"
                                                    class="font-semibold hover:text-indigo-600 line-clamp-1"><?php echo htmlspecialchars($bid["title"]); ?></a>
                                                <span
                                                    class="text-[11px] text-gray-400 font-mono">#<?php echo (int) $bid["auction_id"]; ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-bold tabular-nums">Rs.
                                            <?php echo number_format((float) $bid["bid_amount"]); ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-semibold tabular-nums">Rs.
                                            <?php echo number_format((float) $bid["current_bid"]); ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if ($bid["is_auto_bid"]): ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-600 text-[11px] font-bold border border-violet-500/20"><i
                                                    class="fa-solid fa-robot text-[9px]"></i> Auto</span>
                                        <?php else: ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-gray-500/10 text-gray-600 text-[11px] font-bold border border-gray-500/20"><i
                                                    class="fa-solid fa-hand text-[9px]"></i> Manual</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if ($status_key === 'leading'): ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 text-[11px] font-bold border border-emerald-500/20"><span
                                                    class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Leading</span>
                                        <?php elseif ($status_key === 'outbid'): ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-rose-500/10 text-rose-600 text-[11px] font-bold border border-rose-500/20"><span
                                                    class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span> Outbid</span>
                                        <?php else: ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-gray-500/10 text-gray-600 text-[11px] font-bold border border-gray-500/20"><i
                                                    class="fa-solid fa-flag-checkered text-[9px]"></i>
                                                <?php echo ucfirst($bid["auction_status"]); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-xs font-medium">
                                            <?php echo date("d M Y", strtotime($bid["bid_time"])); ?></div>
                                        <div class="text-[11px] text-gray-400">
                                            <?php echo date("h:i A", strtotime($bid["bid_time"])); ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <a href="../auction-details.php?id=<?php echo (int) $bid["auction_id"]; ?>"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-indigo-600 hover:text-white text-xs font-semibold transition">
                                            <i class="fa-regular fa-eye text-[10px]"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="px-6 py-20 text-center">
                                    <div
                                        class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                        <i class="fa-solid fa-gavel"></i>
                                    </div>
                                    <h3 class="text-xl font-black">No bids yet</h3>
                                    <p class="text-sm text-gray-500 mt-2 max-w-md mx-auto">You haven't placed any bids yet.
                                    </p>
                                    <a href="../auctions.php"
                                        class="inline-flex mt-6 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold items-center gap-2">
                                        <i class="fa-solid fa-magnifying-glass"></i> Browse Auctions
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="noFilterResults" class="hidden p-12 text-center border-t border-gray-100 dark:border-gray-800">
                <div
                    class="w-16 h-16 mx-auto rounded-2xl bg-gray-100 dark:bg-slate-800 text-gray-400 flex items-center justify-center text-2xl mb-4">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <h3 class="font-bold">No matching bids</h3>
                <p class="text-sm text-gray-500 mt-1">Try adjusting your search or filters.</p>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav
                    class="flex flex-wrap items-center justify-between gap-4 p-5 sm:p-6 border-t border-gray-100 dark:border-gray-800">
                    <p class="text-xs text-gray-500">Showing <strong><?php echo count($bids_data); ?></strong> of
                        <strong><?php echo number_format($total_bids); ?></strong> bids</p>
                    <div class="flex items-center gap-1.5">
                        <?php
                        $qp = $_GET;
                        if ($page > 1) {
                            $qp["page"] = $page - 1;
                            echo '<a href="?' . htmlspecialchars(http_build_query($qp)) . '" class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100 transition"><i class="fa-solid fa-chevron-left text-xs"></i></a>';
                        }
                        for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++):
                            $qp["page"] = $p;
                            $is_active = $p === $page; ?>
                            <a href="?<?php echo htmlspecialchars(http_build_query($qp)); ?>"
                                class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold transition <?php echo $is_active ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100'; ?>"><?php echo $p; ?></a>
                        <?php endfor;
                        if ($page < $total_pages) {
                            $qp["page"] = $page + 1;
                            echo '<a href="?' . htmlspecialchars(http_build_query($qp)) . '" class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100 transition"><i class="fa-solid fa-chevron-right text-xs"></i></a>';
                        }
                        ?>
                    </div>
                </nav>
            <?php endif; ?>
        </section>
    </main>

    <script>
        (function () {
            const html = document.documentElement;
            const themeToggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            themeToggle?.addEventListener('click', () => {
                html.classList.toggle('dark');
                localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
            });

            const search = document.getElementById('bidTableSearch');
            const statusFilter = document.getElementById('statusFilter');
            const typeFilter = document.getElementById('typeFilter');
            const noResults = document.getElementById('noFilterResults');
            const rows = Array.from(document.querySelectorAll('.bid-row'));

            function applyFilters() {
                const q = (search?.value || '').trim().toLowerCase();
                const status = statusFilter?.value || 'all';
                const type = typeFilter?.value || 'all';
                let shown = 0;
                rows.forEach(row => {
                    const matchesSearch = !q || (row.dataset.title || '').includes(q);
                    const matchesStatus = status === 'all' || row.dataset.status === status;
                    const matchesType = type === 'all' || row.dataset.type === type;
                    const show = matchesSearch && matchesStatus && matchesType;
                    row.style.display = show ? '' : 'none';
                    if (show) shown++;
                });
                if (noResults) noResults.classList.toggle('hidden', !(shown === 0 && rows.length > 0));
            }

            search?.addEventListener('input', applyFilters);
            statusFilter?.addEventListener('change', applyFilters);
            typeFilter?.addEventListener('change', applyFilters);
        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>