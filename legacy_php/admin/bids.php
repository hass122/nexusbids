<?php

require_once "admin-auth.php";
require_once "../includes/notifications.php";

/* Fetch admin info */
$admin_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$admin_stmt->bind_param("i", $_SESSION["user_id"]);
$admin_stmt->execute();
$admin_info = $admin_stmt->get_result()->fetch_assoc();
$admin_name = $admin_info["full_name"] ?? "Admin";
$admin_initials = strtoupper(substr(preg_replace('/\s+/', '', $admin_name), 0, 2));
$unread_notifications = nb_unread_count($conn, intval($_SESSION["user_id"]));

/* Pagination */
$per_page = 25;
$page = max(1, intval($_GET["page"] ?? 1));

$total_bids = $conn->query("SELECT COUNT(*) AS total FROM bids")->fetch_assoc()["total"] ?? 0;
$total_pages = max(1, ceil($total_bids / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$bids_result = $conn->query("
    SELECT
        b.*,
        a.title,
        a.auction_id,
        a.current_bid,
        a.auction_status,
        u.full_name,
        u.email,
        u.user_id
    FROM bids b
    INNER JOIN auctions a ON b.auction_id = a.auction_id
    INNER JOIN users u ON b.bidder_id = u.user_id
    ORDER BY b.bid_time DESC
    LIMIT $per_page OFFSET $offset
");
$bids_data = $bids_result ? $bids_result->fetch_all(MYSQLI_ASSOC) : [];

/* Aggregate stats */
$today_bids = $conn->query("SELECT COUNT(*) AS total FROM bids WHERE DATE(bid_time) = CURDATE()")->fetch_assoc()["total"] ?? 0;
$week_bids = $conn->query("SELECT COUNT(*) AS total FROM bids WHERE bid_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()["total"] ?? 0;
$unique_bidders = $conn->query("SELECT COUNT(DISTINCT bidder_id) AS total FROM bids")->fetch_assoc()["total"] ?? 0;
$highest_bid = $conn->query("SELECT COALESCE(MAX(bid_amount), 0) AS max_amount FROM bids")->fetch_assoc()["max_amount"] ?? 0;

/* Sidebar counts */
if (!function_exists('safe_count')) {
    function safe_count($conn, $sql)
    {
        $result = $conn->query($sql);
        return $result ? intval($result->fetch_assoc()["total"] ?? 0) : 0;
    }
}

$total_users = safe_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role != 'admin'");
$total_auctions = safe_count($conn, "SELECT COUNT(*) AS total FROM auctions");
$total_winners = safe_count($conn, "SELECT COUNT(*) AS total FROM winners");
$total_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments");
$pending_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments WHERE payment_status NOT IN ('paid','refunded')");

function compact_rs($amount)
{
    $amount = floatval($amount);
    if ($amount >= 10000000)
        return round($amount / 10000000, 1) . "Cr";
    if ($amount >= 100000)
        return round($amount / 100000, 1) . "L";
    if ($amount >= 1000)
        return round($amount / 1000) . "K";
    return number_format($amount);
}

function time_ago_short($datetime)
{
    if (empty($datetime))
        return '';
    $diff = time() - strtotime($datetime);
    if ($diff < 60)
        return "just now";
    if ($diff < 3600)
        return intdiv($diff, 60) . "m ago";
    if ($diff < 86400)
        return intdiv($diff, 3600) . "h ago";
    if ($diff < 604800)
        return intdiv($diff, 86400) . "d ago";
    return date("d M Y", strtotime($datetime));
}

$auction_status_styles = [
    'active' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'dot' => 'bg-emerald-500'],
    'pending' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'dot' => 'bg-amber-500'],
    'completed' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'dot' => 'bg-blue-500'],
    'ended' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-700 dark:text-gray-400', 'dot' => 'bg-gray-500'],
    'rejected' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'dot' => 'bg-rose-500'],
];
$default_auction_status = $auction_status_styles['ended'];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bids | NexusBids Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'] },
                    animation: {
                        'fade-up': 'fadeUp 0.6s ease-out forwards',
                        'pulse-soft': 'pulseSoft 2s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeUp: { '0%': { opacity: '0', transform: 'translateY(16px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        pulseSoft: { '0%, 100%': { opacity: '1' }, '50%': { opacity: '0.5' } },
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

        .custom-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            appearance: none;
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
                                <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span> Management
                            </div>
                            <h1 class="text-lg sm:text-xl font-black truncate">Bid Activity</h1>
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
                                    class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg shadow-indigo-500/30 ring-2 ring-white dark:ring-slate-900">
                                    <?php echo htmlspecialchars($admin_initials); ?>
                                </div>
                                <span
                                    class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900"></span>
                            </div>
                            <div class="hidden sm:block text-left">
                                <div class="text-sm font-bold leading-tight truncate max-w-32">
                                    <?php echo htmlspecialchars($admin_name); ?></div>
                                <div
                                    class="text-[11px] text-indigo-600 dark:text-indigo-400 font-medium flex items-center gap-1">
                                    <i class="fa-solid fa-shield-halved text-[8px]"></i> Admin
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6">

                <section class="animate-fade-up">
                    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                        <div>
                            <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                                <a href="index.php" class="hover:text-indigo-600">Dashboard</a>
                                <i class="fa-solid fa-chevron-right text-[8px]"></i>
                                <span class="text-gray-900 dark:text-white font-semibold">Bids</span>
                            </nav>
                            <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                                <span
                                    class="w-11 h-11 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white shadow-lg">
                                    <i class="fa-solid fa-gavel text-base"></i>
                                </span>
                                All Bids
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Monitor every bid placed across
                                NexusBids auctions.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="export-bids.php"
                                class="group px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold transition flex items-center gap-2">
                                <i class="fa-solid fa-file-csv"></i>
                                Export CSV
                                <i
                                    class="fa-solid fa-arrow-down text-xs group-hover:translate-y-0.5 transition-transform"></i>
                            </a>
                        </div>
                    </div>
                </section>

                <!-- Stat Cards -->
                <section class="grid grid-cols-2 lg:grid-cols-5 gap-4 animate-fade-up">
                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 hover:shadow-lg hover:shadow-indigo-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Total Bids</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-gavel"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($total_bids); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Lifetime placed</p>
                    </div>
                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-emerald-500 hover:shadow-lg hover:shadow-emerald-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Today</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-bolt"></i></span>
                        </div>
                        <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                            <?php echo number_format($today_bids); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Past 24 hours
                        </p>
                    </div>
                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-blue-500 hover:shadow-lg hover:shadow-blue-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">This Week</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-calendar-week"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($week_bids); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Last 7 days</p>
                    </div>
                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-violet-500 hover:shadow-lg hover:shadow-violet-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Bidders</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-users"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($unique_bidders); ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">Unique users</p>
                    </div>
                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-amber-500 hover:shadow-lg hover:shadow-amber-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Highest
                                Bid</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-trophy"></i></span>
                        </div>
                        <div class="text-2xl font-black text-amber-600 dark:text-amber-400 tabular-nums">Rs.
                            <?php echo compact_rs($highest_bid); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">All-time record</p>
                    </div>
                </section>

                <!-- Bids Table -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden animate-fade-up">

                    <div
                        class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row justify-between items-center gap-4">
                        <div class="relative w-full sm:w-80">
                            <i
                                class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input id="bidSearch" type="text" autocomplete="off"
                                class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                placeholder="Search by bidder, email, or auction...">
                        </div>
                        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                            <select id="amountFilter"
                                class="custom-select flex-1 sm:flex-initial py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                                <option value="all">All Amounts</option>
                                <option value="under-1k">Under Rs. 1K</option>
                                <option value="1k-10k">Rs. 1K - 10K</option>
                                <option value="10k-100k">Rs. 10K - 100K</option>
                                <option value="over-100k">Over Rs. 100K</option>
                            </select>
                            <select id="sortFilter"
                                class="custom-select flex-1 sm:flex-initial py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                                <option value="newest">Newest First</option>
                                <option value="oldest">Oldest First</option>
                                <option value="highest">Highest Amount</option>
                                <option value="lowest">Lowest Amount</option>
                            </select>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" id="bidsTable">
                            <thead
                                class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                <tr>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Bidder</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Auction</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Bid Amount</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Status</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Placed</th>
                                    <th
                                        class="px-6 py-4 text-right text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <?php if (!empty($bids_data)): ?>
                                    <?php foreach ($bids_data as $bid):
                                        $amount = floatval($bid['bid_amount']);
                                        $auction_status = strtolower($bid['auction_status'] ?? 'ended');
                                        $a_style = $auction_status_styles[$auction_status] ?? $default_auction_status;
                                        $is_winning = floatval($bid['current_bid']) <= $amount;
                                        $initials = strtoupper(substr(preg_replace('/\s+/', '', $bid['full_name']), 0, 2));
                                        $bid_time_ts = strtotime($bid['bid_time']);
                                        ?>
                                        <tr class="data-row"
                                            data-bidder="<?php echo htmlspecialchars(strtolower($bid['full_name'])); ?>"
                                            data-email="<?php echo htmlspecialchars(strtolower($bid['email'])); ?>"
                                            data-auction="<?php echo htmlspecialchars(strtolower($bid['title'])); ?>"
                                            data-amount="<?php echo $amount; ?>" data-time="<?php echo $bid_time_ts; ?>">
                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-3">
                                                    <div
                                                        class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg shadow-indigo-500/20 shrink-0">
                                                        <?php echo htmlspecialchars($initials); ?>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="font-bold truncate">
                                                            <?php echo htmlspecialchars($bid['full_name']); ?></div>
                                                        <div
                                                            class="text-[10px] text-gray-500 flex items-center gap-1 truncate max-w-[180px]">
                                                            <i class="fa-solid fa-envelope text-[9px] shrink-0"></i>
                                                            <span
                                                                class="truncate"><?php echo htmlspecialchars($bid['email']); ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <a href="../auction-details.php?id=<?php echo (int) $bid['auction_id']; ?>"
                                                    class="font-semibold hover:text-indigo-600 transition line-clamp-1 max-w-[220px] block">
                                                    <?php echo htmlspecialchars($bid['title']); ?>
                                                </a>
                                                <span
                                                    class="text-[10px] text-gray-400 font-mono">#<?php echo str_pad((int) $bid['auction_id'], 5, '0', STR_PAD_LEFT); ?></span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="font-black tabular-nums">Rs. <?php echo number_format($amount); ?>
                                                </div>
                                                <?php if ($auction_status === 'active'): ?>
                                                    <div
                                                        class="text-[10px] font-bold mt-0.5 <?php echo $is_winning ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'; ?>">
                                                        <i
                                                            class="fa-solid <?php echo $is_winning ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down'; ?> text-[9px]"></i>
                                                        <?php echo $is_winning ? 'Leading' : 'Outbid'; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-[10px] text-gray-400 mt-0.5">Final</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?php echo $a_style['bg'] . ' ' . $a_style['text']; ?> text-[10px] font-bold uppercase tracking-wider">
                                                    <span
                                                        class="w-1.5 h-1.5 rounded-full <?php echo $a_style['dot']; ?> <?php echo $auction_status === 'active' ? 'animate-pulse' : ''; ?>"></span>
                                                    <?php echo ucfirst($auction_status); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-xs font-medium"><?php echo date('d M Y', $bid_time_ts); ?>
                                                </div>
                                                <div class="text-[10px] text-gray-500 flex items-center gap-1">
                                                    <i class="fa-regular fa-clock text-[9px]"></i>
                                                    <?php echo date('h:i A', $bid_time_ts); ?>
                                                    <span class="text-gray-300 dark:text-gray-700">·</span>
                                                    <?php echo time_ago_short($bid['bid_time']); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 text-right">
                                                <a href="../auction-details.php?id=<?php echo (int) $bid['auction_id']; ?>"
                                                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-indigo-600 hover:text-white text-gray-700 dark:text-gray-200 text-xs font-semibold transition">
                                                    <i class="fa-regular fa-eye text-[10px]"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-20 text-center">
                                            <div
                                                class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                                <i class="fa-solid fa-gavel"></i>
                                            </div>
                                            <h3 class="text-xl font-black">No bids found</h3>
                                            <p class="text-sm text-gray-500 mt-2 max-w-md mx-auto">Bidding activity will
                                                appear here once bidders start participating.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div id="noFilterResults"
                        class="hidden p-12 text-center border-t border-gray-100 dark:border-gray-800">
                        <div
                            class="w-16 h-16 mx-auto rounded-2xl bg-gray-100 dark:bg-slate-800 text-gray-400 flex items-center justify-center text-2xl mb-4">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </div>
                        <h3 class="font-bold">No matching bids</h3>
                        <p class="text-sm text-gray-500 mt-1">Try adjusting your search or filters.</p>
                        <button type="button" id="resetFilters"
                            class="mt-5 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition">
                            <i class="fa-solid fa-rotate-right"></i> Reset Filters
                        </button>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <nav
                            class="flex flex-wrap items-center justify-between gap-4 px-6 py-4 border-t border-gray-100 dark:border-gray-800">
                            <p class="text-xs text-gray-500">
                                Showing <strong><?php echo count($bids_data); ?></strong> of
                                <strong><?php echo number_format($total_bids); ?></strong> bids
                            </p>
                            <div class="flex items-center gap-1.5">
                                <?php
                                $query_params = $_GET;
                                if ($page > 1) {
                                    $query_params["page"] = $page - 1;
                                    echo '<a href="?' . htmlspecialchars(http_build_query($query_params)) . '" class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100"><i class="fa-solid fa-chevron-left text-xs"></i></a>';
                                }
                                $window = 2;
                                $start = max(1, $page - $window);
                                $end = min($total_pages, $page + $window);
                                if ($start > 1) {
                                    $query_params["page"] = 1;
                                    echo '<a href="?' . htmlspecialchars(http_build_query($query_params)) . '" class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold bg-gray-100 dark:bg-slate-800">1</a>';
                                    if ($start > 2)
                                        echo '<span class="px-1 text-gray-400">…</span>';
                                }
                                for ($p = $start; $p <= $end; $p++):
                                    $query_params["page"] = $p;
                                    $is_active = $p === $page;
                                    ?>
                                    <a href="?<?php echo htmlspecialchars(http_build_query($query_params)); ?>"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold <?php echo $is_active ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'bg-gray-100 dark:bg-slate-800'; ?>">
                                        <?php echo $p; ?>
                                    </a>
                                <?php endfor;
                                if ($end < $total_pages) {
                                    if ($end < $total_pages - 1)
                                        echo '<span class="px-1 text-gray-400">…</span>';
                                    $query_params["page"] = $total_pages;
                                    echo '<a href="?' . htmlspecialchars(http_build_query($query_params)) . '" class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold bg-gray-100 dark:bg-slate-800">' . $total_pages . '</a>';
                                }
                                if ($page < $total_pages) {
                                    $query_params["page"] = $page + 1;
                                    echo '<a href="?' . htmlspecialchars(http_build_query($query_params)) . '" class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100"><i class="fa-solid fa-chevron-right text-xs"></i></a>';
                                }
                                ?>
                            </div>
                        </nav>
                    <?php endif; ?>
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

            const search = document.getElementById('bidSearch');
            const amountFilter = document.getElementById('amountFilter');
            const sortFilter = document.getElementById('sortFilter');
            const noResults = document.getElementById('noFilterResults');
            const resetBtn = document.getElementById('resetFilters');
            const tbody = document.querySelector('#bidsTable tbody');
            const rows = Array.from(document.querySelectorAll('.data-row'));

            if (!tbody) return;

            function applyFilters() {
                const q = (search?.value || '').trim().toLowerCase();
                const amountRange = amountFilter?.value || 'all';
                const sort = sortFilter?.value || 'newest';

                const sorted = [...rows].sort((a, b) => {
                    const aAmt = Number(a.dataset.amount || 0);
                    const bAmt = Number(b.dataset.amount || 0);
                    const aTime = Number(a.dataset.time || 0);
                    const bTime = Number(b.dataset.time || 0);
                    if (sort === 'oldest') return aTime - bTime;
                    if (sort === 'highest') return bAmt - aAmt;
                    if (sort === 'lowest') return aAmt - bAmt;
                    return bTime - aTime;
                });
                sorted.forEach(r => tbody.appendChild(r));

                let shown = 0;
                rows.forEach(row => {
                    const bidder = row.dataset.bidder || '';
                    const email = row.dataset.email || '';
                    const auction = row.dataset.auction || '';
                    const amount = Number(row.dataset.amount || 0);

                    const matchesSearch = !q || bidder.includes(q) || email.includes(q) || auction.includes(q);

                    let matchesAmount = true;
                    if (amountRange === 'under-1k') matchesAmount = amount < 1000;
                    else if (amountRange === '1k-10k') matchesAmount = amount >= 1000 && amount < 10000;
                    else if (amountRange === '10k-100k') matchesAmount = amount >= 10000 && amount < 100000;
                    else if (amountRange === 'over-100k') matchesAmount = amount >= 100000;

                    const show = matchesSearch && matchesAmount;
                    row.style.display = show ? '' : 'none';
                    if (show) shown++;
                });

                if (noResults) noResults.classList.toggle('hidden', !(shown === 0 && rows.length > 0));
            }

            search?.addEventListener('input', applyFilters);
            amountFilter?.addEventListener('change', applyFilters);
            sortFilter?.addEventListener('change', applyFilters);
            resetBtn?.addEventListener('click', () => {
                if (search) search.value = '';
                if (amountFilter) amountFilter.value = 'all';
                if (sortFilter) sortFilter.value = 'newest';
                applyFilters();
            });
        })();
    </script>
</body>

</html>