<?php
require_once "admin-auth.php";
require_once "../includes/csrf.php";
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
$per_page = 20;
$page = max(1, intval($_GET["page"] ?? 1));

$total_winners = $conn->query("SELECT COUNT(*) AS total FROM winners")->fetch_assoc()["total"] ?? 0;
$total_pages = max(1, ceil($total_winners / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

/* Fetch winners */
$winners_stmt = $conn->prepare("
    SELECT
        w.winner_id,
        w.winning_bid,
        w.status AS winner_status,
        w.created_at AS won_at,
        a.auction_id,
        a.title AS auction_title,
        a.image AS auction_image,
        a.current_bid,
        a.starting_price,
        a.seller_id,
        seller.full_name AS seller_name,
        seller.email AS seller_email,
        buyer.user_id AS buyer_id,
        buyer.full_name AS buyer_name,
        buyer.email AS buyer_email,
        buyer.phone AS buyer_phone,
        p.payment_status AS payment_status,
        p.created_at AS paid_at
    FROM winners w
    INNER JOIN auctions a ON w.auction_id = a.auction_id
    INNER JOIN users seller ON a.seller_id = seller.user_id
    INNER JOIN users buyer ON w.user_id = buyer.user_id
    LEFT JOIN payments p ON p.auction_id = a.auction_id AND p.user_id = w.user_id
    ORDER BY w.created_at DESC
    LIMIT ? OFFSET ?
");
$winners_stmt->bind_param("ii", $per_page, $offset);
$winners_stmt->execute();
$winners_result = $winners_stmt->get_result();
$winners_data = $winners_result->fetch_all(MYSQLI_ASSOC);
$winners_stmt->close();

/* Aggregate stats */
$stats_stmt = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN p.payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN p.payment_status IS NULL OR p.payment_status != 'paid' THEN 1 ELSE 0 END) AS pending_count,
        COALESCE(SUM(w.winning_bid), 0) AS total_value,
        COALESCE(AVG(w.winning_bid), 0) AS avg_bid
    FROM winners w
    LEFT JOIN payments p ON p.auction_id = w.auction_id AND p.user_id = w.user_id
");
$wstats = $stats_stmt->fetch_assoc();
$total_paid = intval($wstats['paid_count'] ?? 0);
$total_pending = intval($wstats['pending_count'] ?? 0);
$total_value = floatval($wstats['total_value'] ?? 0);
$avg_bid = floatval($wstats['avg_bid'] ?? 0);

/* Sidebar counts */
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
$total_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments");
$pending_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments WHERE payment_status NOT IN ('paid','refunded')");

/* Payment status styles */
$payment_styles = [
    'paid' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'border' => 'border-emerald-500/20', 'dot' => 'bg-emerald-500', 'label' => 'Paid', 'icon' => 'fa-circle-check'],
    'pending' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'border' => 'border-amber-500/20', 'dot' => 'bg-amber-500', 'label' => 'Pending', 'icon' => 'fa-clock'],
    'refunded' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'border' => 'border-blue-500/20', 'dot' => 'bg-blue-500', 'label' => 'Refunded', 'icon' => 'fa-rotate-left'],
    'failed' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'border' => 'border-rose-500/20', 'dot' => 'bg-rose-500', 'label' => 'Failed', 'icon' => 'fa-xmark'],
];
$default_payment = ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-700 dark:text-gray-400', 'border' => 'border-gray-500/20', 'dot' => 'bg-gray-500', 'label' => 'Unknown', 'icon' => 'fa-circle-question'];

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
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Winners | NexusBids Admin</title>
    <meta name="description" content="Manage auction winners and payment status on NexusBids.">

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

        .trophy-glow {
            filter: drop-shadow(0 0 12px rgba(251, 191, 36, 0.4));
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
                            <h1 class="text-lg sm:text-xl font-black truncate">Winners</h1>
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

                <!-- Flash messages -->
                <?php if (!empty($_SESSION['winners_flash'])): ?>
                    <div
                        class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-sm text-emerald-700 dark:text-emerald-300 font-medium flex items-center gap-3">
                        <i class="fa-solid fa-circle-check"></i>
                        <?php echo htmlspecialchars($_SESSION['winners_flash']);
                        unset($_SESSION['winners_flash']); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($_SESSION['winners_flash_error'])): ?>
                    <div
                        class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-sm text-rose-700 dark:text-rose-300 font-medium flex items-center gap-3">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <?php echo htmlspecialchars($_SESSION['winners_flash_error']);
                        unset($_SESSION['winners_flash_error']); ?>
                    </div>
                <?php endif; ?>

                <!-- Page Header -->
                <section class="animate-fade-up">
                    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                        <div>
                            <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                                <a href="index.php" class="hover:text-indigo-600">Dashboard</a>
                                <i class="fa-solid fa-chevron-right text-[8px]"></i>
                                <span class="text-gray-900 dark:text-white font-semibold">Winners</span>
                            </nav>
                            <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                                <span
                                    class="w-11 h-11 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-500 flex items-center justify-center text-white shadow-lg trophy-glow">
                                    <i class="fa-solid fa-trophy text-base"></i>
                                </span>
                                Auction Winners
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Track winners, payment status, and
                                completed auction results.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php
                            $export_query = [];
                            if (!empty($_GET['q']))
                                $export_query['q'] = $_GET['q'];
                            if (!empty($_GET['payment']) && $_GET['payment'] !== 'all')
                                $export_query['payment'] = $_GET['payment'];
                            $export_url = 'export-winners.php' . ($export_query ? '?' . http_build_query($export_query) : '');
                            ?>
                            <a href="<?php echo htmlspecialchars($export_url); ?>"
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
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-amber-500 hover:shadow-lg hover:shadow-amber-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Total</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-trophy"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($total_winners); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">All winners</p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-emerald-500 hover:shadow-lg hover:shadow-emerald-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Paid</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-circle-check"></i></span>
                        </div>
                        <div class="text-2xl font-black text-emerald-600 tabular-nums">
                            <?php echo number_format($total_paid); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Completed payments</p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border-2 <?php echo $total_pending > 0 ? 'border-amber-500/40' : 'border-gray-200 dark:border-gray-800'; ?> hover:shadow-lg hover:shadow-amber-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Pending</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-clock"></i></span>
                        </div>
                        <div
                            class="text-2xl font-black <?php echo $total_pending > 0 ? 'text-amber-600' : ''; ?> tabular-nums">
                            <?php echo number_format($total_pending); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">
                            <?php echo $total_pending > 0 ? 'Awaiting payment' : 'All settled'; ?></p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 hover:shadow-lg hover:shadow-indigo-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Total
                                Value</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-coins"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums">Rs. <?php echo compact_rs($total_value); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Combined winning bids</p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-violet-500 hover:shadow-lg hover:shadow-violet-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Avg Bid</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-violet-500/10 text-violet-600 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-chart-line"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums">Rs. <?php echo compact_rs($avg_bid); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Per winning bid</p>
                    </div>
                </section>

                <!-- Winners Table -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden animate-fade-up">

                    <div
                        class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row justify-between items-center gap-4">
                        <div class="relative w-full sm:w-80">
                            <i
                                class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input id="winnerSearch" type="text" autocomplete="off"
                                class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                placeholder="Search winner, auction, seller...">
                        </div>
                        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                            <select id="paymentFilter"
                                class="custom-select flex-1 sm:flex-initial py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                                <option value="all">All Payments</option>
                                <option value="paid">Paid</option>
                                <option value="pending">Pending</option>
                            </select>
                            <select id="sortFilter"
                                class="custom-select flex-1 sm:flex-initial py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                                <option value="newest">Newest First</option>
                                <option value="oldest">Oldest First</option>
                                <option value="highest">Highest Bid</option>
                                <option value="lowest">Lowest Bid</option>
                            </select>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" id="winnersTable">
                            <thead
                                class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                <tr>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Item</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Winner</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Seller</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Winning Bid</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Payment</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Won On</th>
                                    <th
                                        class="px-6 py-4 text-right text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <?php if (!empty($winners_data)): ?>
                                    <?php foreach ($winners_data as $win):
                                        $payment_key = strtolower($win['payment_status'] ?? 'pending');
                                        if (!isset($payment_styles[$payment_key]))
                                            $payment_key = 'pending';
                                        $p_style = $payment_styles[$payment_key] ?? $default_payment;
                                        $winner_initials = strtoupper(substr(preg_replace('/\s+/', '', $win['buyer_name']), 0, 2));
                                        $seller_initials = strtoupper(substr(preg_replace('/\s+/', '', $win['seller_name']), 0, 2));
                                        ?>
                                        <tr class="data-row"
                                            data-winner="<?php echo htmlspecialchars(strtolower($win['buyer_name'])); ?>"
                                            data-auction="<?php echo htmlspecialchars(strtolower($win['auction_title'])); ?>"
                                            data-seller="<?php echo htmlspecialchars(strtolower($win['seller_name'])); ?>"
                                            data-payment="<?php echo $payment_key; ?>"
                                            data-amount="<?php echo floatval($win['winning_bid']); ?>"
                                            data-time="<?php echo strtotime($win['won_at']); ?>">

                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-3">
                                                    <?php if (!empty($win['auction_image'])): ?>
                                                        <img src="../uploads/auctions/<?php echo htmlspecialchars($win['auction_image']); ?>"
                                                            alt=""
                                                            class="w-11 h-11 rounded-xl object-cover border border-gray-200 dark:border-gray-700 shrink-0">
                                                    <?php else: ?>
                                                        <div
                                                            class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-500/10 to-orange-500/10 text-amber-600 flex items-center justify-center text-sm shrink-0">
                                                            <i class="fa-solid fa-trophy"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="min-w-0">
                                                        <a href="../auction-details.php?id=<?php echo (int) $win['auction_id']; ?>"
                                                            class="font-semibold hover:text-indigo-600 line-clamp-1 max-w-[200px] block transition">
                                                            <?php echo htmlspecialchars($win['auction_title']); ?>
                                                        </a>
                                                        <span
                                                            class="text-[10px] text-gray-400 font-mono">#<?php echo str_pad((int) $win['auction_id'], 5, '0', STR_PAD_LEFT); ?></span>
                                                    </div>
                                                </div>
                                            </td>

                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-2.5">
                                                    <div
                                                        class="w-9 h-9 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-500 text-white font-bold text-[10px] flex items-center justify-center shrink-0">
                                                        <?php echo htmlspecialchars($winner_initials); ?>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="text-xs font-bold truncate">
                                                            <?php echo htmlspecialchars($win['buyer_name']); ?></div>
                                                        <div class="text-[10px] text-gray-500 truncate max-w-[160px]">
                                                            <?php echo htmlspecialchars($win['buyer_email']); ?></div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-2.5">
                                                    <div
                                                        class="w-9 h-9 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-[10px] flex items-center justify-center shrink-0">
                                                        <?php echo htmlspecialchars($seller_initials); ?>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="text-xs font-bold truncate">
                                                            <?php echo htmlspecialchars($win['seller_name']); ?></div>
                                                        <div class="text-[10px] text-gray-500 truncate max-w-[160px]">
                                                            <?php echo htmlspecialchars($win['seller_email']); ?></div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td class="px-6 py-4">
                                                <div class="text-sm font-black tabular-nums">Rs.
                                                    <?php echo number_format(floatval($win['winning_bid'])); ?></div>
                                                <div class="text-[10px] text-gray-400">Winning amount</div>
                                            </td>

                                            <td class="px-6 py-4">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?php echo $p_style['bg'] . ' ' . $p_style['text'] . ' ' . $p_style['border']; ?> text-[10px] font-bold uppercase tracking-wider border">
                                                    <span
                                                        class="w-1.5 h-1.5 rounded-full <?php echo $p_style['dot']; ?> <?php echo $payment_key === 'pending' ? 'animate-pulse' : ''; ?>"></span>
                                                    <?php echo $p_style['label']; ?>
                                                </span>
                                                <?php if (!empty($win['paid_at'])): ?>
                                                    <div class="text-[10px] text-gray-400 mt-1">
                                                        <?php echo time_ago_short($win['paid_at']); ?></div>
                                                <?php endif; ?>
                                            </td>

                                            <td class="px-6 py-4">
                                                <div class="text-xs font-medium">
                                                    <?php echo date('d M Y', strtotime($win['won_at'])); ?></div>
                                                <div class="text-[10px] text-gray-400">
                                                    <?php echo time_ago_short($win['won_at']); ?></div>
                                            </td>

                                            <td class="px-6 py-4 text-right">
                                                <div class="flex items-center justify-end gap-2">
                                                    <a href="../auction-details.php?id=<?php echo (int) $win['auction_id']; ?>"
                                                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-indigo-600 hover:text-white text-gray-700 dark:text-gray-200 text-xs font-semibold transition">
                                                        <i class="fa-regular fa-eye text-[10px]"></i> View
                                                    </a>
                                                    <?php if ($payment_key === 'pending'): ?>
                                                        <form method="POST" action="send-reminder.php" class="inline">
                                                            <?php if (function_exists('nb_csrf_field'))
                                                                nb_csrf_field(); ?>
                                                            <input type="hidden" name="winner_id"
                                                                value="<?php echo (int) $win['winner_id']; ?>">
                                                            <button type="submit"
                                                                onclick="return confirm('Send payment reminder to <?php echo htmlspecialchars($win['buyer_name'], ENT_QUOTES); ?> for Rs. <?php echo number_format(floatval($win['winning_bid'])); ?>?')"
                                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/20 hover:bg-amber-500 hover:text-white text-xs font-bold transition"
                                                                title="Send payment reminder">
                                                                <i class="fa-solid fa-bell text-[10px]"></i> Remind
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span
                                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20 text-xs font-bold">
                                                            <i class="fa-solid fa-circle-check text-[10px]"></i> Settled
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="px-6 py-20 text-center">
                                            <div
                                                class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-amber-500/10 to-orange-500/10 text-amber-500 flex items-center justify-center text-3xl mb-5">
                                                <i class="fa-solid fa-trophy"></i>
                                            </div>
                                            <h3 class="text-xl font-black">No winners yet</h3>
                                            <p class="text-sm text-gray-500 mt-2 max-w-md mx-auto">Winners will appear here
                                                once auctions complete.</p>
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
                        <h3 class="font-bold">No matching winners</h3>
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
                                Showing <strong><?php echo count($winners_data); ?></strong> of
                                <strong><?php echo number_format($total_winners); ?></strong>
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
                                for ($p = $start; $p <= $end; $p++):
                                    $query_params["page"] = $p;
                                    $is_active = $p === $page;
                                    ?>
                                    <a href="?<?php echo htmlspecialchars(http_build_query($query_params)); ?>"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold <?php echo $is_active ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100'; ?>">
                                        <?php echo $p; ?>
                                    </a>
                                <?php endfor;
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

            const search = document.getElementById('winnerSearch');
            const paymentFilter = document.getElementById('paymentFilter');
            const sortFilter = document.getElementById('sortFilter');
            const noResults = document.getElementById('noFilterResults');
            const resetBtn = document.getElementById('resetFilters');
            const tbody = document.querySelector('#winnersTable tbody');
            const rows = Array.from(document.querySelectorAll('.data-row'));

            if (!tbody || !rows.length) return;

            function applyFilters() {
                const q = (search?.value || '').trim().toLowerCase();
                const payment = paymentFilter?.value || 'all';
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
                    const winner = row.dataset.winner || '';
                    const auction = row.dataset.auction || '';
                    const seller = row.dataset.seller || '';
                    const rowPayment = row.dataset.payment || '';

                    const matchesSearch = !q || winner.includes(q) || auction.includes(q) || seller.includes(q);
                    const matchesPayment = payment === 'all' || rowPayment === payment;

                    const show = matchesSearch && matchesPayment;
                    row.style.display = show ? '' : 'none';
                    if (show) shown++;
                });

                if (noResults) noResults.classList.toggle('hidden', !(shown === 0 && rows.length > 0));
            }

            search?.addEventListener('input', applyFilters);
            paymentFilter?.addEventListener('change', applyFilters);
            sortFilter?.addEventListener('change', applyFilters);
            resetBtn?.addEventListener('click', () => {
                if (search) search.value = '';
                if (paymentFilter) paymentFilter.value = 'all';
                if (sortFilter) sortFilter.value = 'newest';
                applyFilters();
            });
        })();
    </script>
</body>

</html>