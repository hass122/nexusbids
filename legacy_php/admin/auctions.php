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

/* Auto-update status for past auctions */
$conn->query("
    UPDATE auctions 
    SET auction_status = 'ended' 
    WHERE (auction_status = 'active' OR auction_status = 'approved') 
      AND end_time <= NOW()
");

/* Fetch auctions */
$auctions_result = $conn->query("
    SELECT a.*, u.full_name, c.category_name
    FROM auctions a
    INNER JOIN users u ON a.seller_id = u.user_id
    INNER JOIN categories c ON a.category_id = c.category_id
    ORDER BY
        CASE WHEN a.auction_status = 'pending' THEN 0 ELSE 1 END,
        a.created_at DESC
");
$auctions_data = $auctions_result ? $auctions_result->fetch_all(MYSQLI_ASSOC) : [];

/* Aggregate counts */
$total_count = 0;
$pending_count = 0;
$active_count = 0;
$ended_count = 0;
$completed_count = 0;
$rejected_count = 0;
foreach ($auctions_data as $a) {
    $total_count++;
    $status = strtolower($a['auction_status'] ?? '');
    if ($status === 'pending')
        $pending_count++;
    elseif (in_array($status, ['active', 'approved']))
        $active_count++;
    elseif ($status === 'completed')
        $completed_count++;
    elseif ($status === 'rejected')
        $rejected_count++;
    elseif ($status === 'ended')
        $ended_count++;
}

/* Sidebar counts */
function safe_count($conn, $sql)
{
    try {
        $result = $conn->query($sql);
        return $result ? intval($result->fetch_assoc()["total"] ?? 0) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

$total_users = safe_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role != 'admin'");
$total_auctions = $total_count;
$total_bids = safe_count($conn, "SELECT COUNT(*) AS total FROM bids");
$total_winners = safe_count($conn, "SELECT COUNT(*) AS total FROM winners");
$total_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments");
$pending_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments WHERE payment_status NOT IN ('paid','refunded')");

/* Status styles */
$status_styles = [
    'active' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'border' => 'border-emerald-500/20', 'dot' => 'bg-emerald-500', 'icon' => 'fa-bolt', 'label' => 'Active'],
    'approved' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'border' => 'border-emerald-500/20', 'dot' => 'bg-emerald-500', 'icon' => 'fa-circle-check', 'label' => 'Approved'],
    'pending' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'border' => 'border-amber-500/20', 'dot' => 'bg-amber-500', 'icon' => 'fa-clock', 'label' => 'Pending'],
    'completed' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'border' => 'border-blue-500/20', 'dot' => 'bg-blue-500', 'icon' => 'fa-trophy', 'label' => 'Completed'],
    'ended' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-700 dark:text-gray-400', 'border' => 'border-gray-500/20', 'dot' => 'bg-gray-500', 'icon' => 'fa-flag-checkered', 'label' => 'Ended'],
    'rejected' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'border' => 'border-rose-500/20', 'dot' => 'bg-rose-500', 'icon' => 'fa-xmark', 'label' => 'Rejected'],
    'cancelled' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'border' => 'border-rose-500/20', 'dot' => 'bg-rose-500', 'icon' => 'fa-ban', 'label' => 'Cancelled'],
];
$default_status = $status_styles['ended'];

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
    <title>Auctions | NexusBids Admin</title>
    <meta name="description" content="Review and manage NexusBids auction listings.">

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

        .row-pending {
            background-color: rgb(254 243 199 / 0.2);
        }

        .dark .row-pending {
            background-color: rgb(120 53 15 / 0.1);
        }

        .row-pending:hover {
            background-color: rgb(254 243 199 / 0.4);
        }

        .dark .row-pending:hover {
            background-color: rgb(120 53 15 / 0.2);
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
                            <h1 class="text-lg sm:text-xl font-black truncate">Auctions</h1>
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

                <!-- Page Header -->
                <section class="animate-fade-up">
                    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                        <div>
                            <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                                <a href="index.php" class="hover:text-indigo-600">Dashboard</a>
                                <i class="fa-solid fa-chevron-right text-[8px]"></i>
                                <span class="text-gray-900 dark:text-white font-semibold">Auctions</span>
                            </nav>
                            <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                                <span
                                    class="w-11 h-11 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white shadow-lg">
                                    <i class="fa-solid fa-box text-base"></i>
                                </span>
                                Auction Management
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Review, approve, and manage all
                                auction listings.</p>
                        </div>
                    </div>
                </section>

                <!-- Stat Cards -->
                <section class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 animate-fade-up">
                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 hover:shadow-lg hover:shadow-indigo-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Total</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-box"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($total_count); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">All listings</p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border-2 <?php echo $pending_count > 0 ? 'border-amber-500/40' : 'border-gray-200 dark:border-gray-800'; ?> hover:shadow-lg hover:shadow-amber-500/10 transition hover:-translate-y-0.5 relative">
                        <?php if ($pending_count > 0): ?>
                            <div class="absolute top-2 right-2 w-2 h-2 rounded-full bg-amber-500 animate-pulse-soft"></div>
                        <?php endif; ?>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Pending</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-clock"></i></span>
                        </div>
                        <div
                            class="text-2xl font-black <?php echo $pending_count > 0 ? 'text-amber-600 dark:text-amber-400' : ''; ?> tabular-nums">
                            <?php echo number_format($pending_count); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">
                            <?php echo $pending_count > 0 ? 'Needs review' : 'All reviewed'; ?></p>
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
                            <?php echo number_format($active_count); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Live now
                        </p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-blue-500 hover:shadow-lg hover:shadow-blue-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Completed</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-trophy"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($completed_count); ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">With winners</p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-gray-500 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Ended</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-gray-500/10 text-gray-600 dark:text-gray-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-flag-checkered"></i></span>
                        </div>
                        <div class="text-2xl font-black text-gray-700 dark:text-gray-300 tabular-nums">
                            <?php echo number_format($ended_count + $rejected_count); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1"><?php echo $rejected_count; ?> rejected</p>
                    </div>
                </section>

                <!-- Auctions Table -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden animate-fade-up">

                    <div
                        class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row justify-between items-center gap-4">
                        <div class="relative w-full sm:w-80">
                            <i
                                class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input id="auctionSearch" type="text" autocomplete="off"
                                class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                placeholder="Search by title, seller, or category...">
                        </div>
                        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                            <select id="statusFilter"
                                class="custom-select flex-1 sm:flex-initial py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                                <option value="all">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="active">Active</option>
                                <option value="completed">Completed</option>
                                <option value="ended">Ended</option>
                                <option value="rejected">Rejected</option>
                            </select>
                            <select id="categoryFilter"
                                class="custom-select flex-1 sm:flex-initial py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                                <option value="all">All Categories</option>
                                <?php
                                $seen_cats = [];
                                foreach ($auctions_data as $a) {
                                    $cn = $a['category_name'] ?? '';
                                    if ($cn && !isset($seen_cats[$cn])) {
                                        $seen_cats[$cn] = true;
                                        echo '<option value="' . htmlspecialchars(strtolower($cn)) . '">' . htmlspecialchars($cn) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" id="auctionsTable">
                            <thead
                                class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                <tr>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Item</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Seller</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Price</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Ends</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Status</th>
                                    <th
                                        class="px-6 py-4 text-right text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <?php if (!empty($auctions_data)): ?>
                                    <?php foreach ($auctions_data as $auction):
                                        $status = strtolower($auction['auction_status'] ?? 'ended');
                                        $style = $status_styles[$status] ?? $default_status;
                                        $is_pending = $status === 'pending';
                                        $is_active = in_array($status, ['active', 'approved']);
                                        $price = floatval($auction['current_bid']) > 0 ? floatval($auction['current_bid']) : floatval($auction['starting_price']);
                                        $end_ts = strtotime($auction['end_time'] ?? '');
                                        $ends_in = $end_ts - time();
                                        ?>
                                        <tr class="data-row <?php echo $is_pending ? 'row-pending' : ''; ?>"
                                            data-title="<?php echo htmlspecialchars(strtolower($auction['title'] ?? '')); ?>"
                                            data-seller="<?php echo htmlspecialchars(strtolower($auction['full_name'] ?? '')); ?>"
                                            data-category="<?php echo htmlspecialchars(strtolower($auction['category_name'] ?? '')); ?>"
                                            data-status="<?php echo $status; ?>">

                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-3">
                                                    <?php if (!empty($auction['image'])): ?>
                                                        <img src="../uploads/auctions/<?php echo htmlspecialchars($auction['image']); ?>"
                                                            alt=""
                                                            class="w-12 h-12 rounded-xl object-cover border border-gray-200 dark:border-gray-700 shrink-0">
                                                    <?php else: ?>
                                                        <div
                                                            class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-sm shrink-0">
                                                            <i class="fa-solid fa-image"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="min-w-0">
                                                        <a href="../auction-details.php?id=<?php echo (int) $auction['auction_id']; ?>"
                                                            class="font-semibold hover:text-indigo-600 line-clamp-1 transition">
                                                            <?php echo htmlspecialchars($auction['title']); ?>
                                                        </a>
                                                        <div class="flex items-center gap-2 mt-1">
                                                            <span
                                                                class="text-[10px] text-gray-400 font-mono">#<?php echo str_pad((int) $auction['auction_id'], 5, '0', STR_PAD_LEFT); ?></span>
                                                            <span
                                                                class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 text-[9px] font-bold uppercase tracking-wider">
                                                                <?php echo htmlspecialchars($auction['category_name']); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-2">
                                                    <div
                                                        class="w-7 h-7 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-500 text-white flex items-center justify-center text-[9px] font-bold shrink-0">
                                                        <?php echo htmlspecialchars(strtoupper(substr(preg_replace('/\s+/', '', $auction['full_name']), 0, 2))); ?>
                                                    </div>
                                                    <span
                                                        class="text-xs font-medium truncate"><?php echo htmlspecialchars($auction['full_name']); ?></span>
                                                </div>
                                            </td>

                                            <td class="px-6 py-4">
                                                <div class="text-xs font-black tabular-nums">Rs.
                                                    <?php echo number_format($price); ?></div>
                                                <?php if (floatval($auction['current_bid']) > 0): ?>
                                                    <div class="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold">
                                                        Current bid</div>
                                                <?php else: ?>
                                                    <div class="text-[10px] text-gray-400">Starting price</div>
                                                <?php endif; ?>
                                            </td>

                                            <td class="px-6 py-4">
                                                <?php if ($end_ts && $ends_in > 0 && $is_active): ?>
                                                    <div class="text-xs font-bold"><?php echo date('d M Y', $end_ts); ?></div>
                                                    <div
                                                        class="text-[10px] text-amber-600 dark:text-amber-400 font-semibold flex items-center gap-1 mt-0.5">
                                                        <i class="fa-regular fa-clock text-[9px]"></i>
                                                        <?php
                                                        $days = intdiv($ends_in, 86400);
                                                        $hours = intdiv($ends_in % 86400, 3600);
                                                        if ($days > 0)
                                                            echo $days . 'd ' . $hours . 'h left';
                                                        elseif ($hours > 0)
                                                            echo $hours . 'h left';
                                                        else
                                                            echo intdiv($ends_in % 3600, 60) . 'm left';
                                                        ?>
                                                    </div>
                                                <?php elseif ($end_ts): ?>
                                                    <div class="text-xs font-medium text-gray-500">
                                                        <?php echo date('d M Y', $end_ts); ?></div>
                                                    <div class="text-[10px] text-gray-400">
                                                        <?php echo time_ago_short($auction['end_time']); ?></div>
                                                <?php else: ?>
                                                    <span class="text-xs text-gray-400">—</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="px-6 py-4">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?php echo $style['bg'] . ' ' . $style['text'] . ' ' . $style['border']; ?> text-[10px] font-bold border uppercase tracking-wider">
                                                    <span
                                                        class="w-1.5 h-1.5 rounded-full <?php echo $style['dot']; ?> <?php echo $is_active ? 'animate-pulse' : ''; ?>"></span>
                                                    <?php echo $style['label']; ?>
                                                </span>
                                            </td>

                                            <td class="px-6 py-4 text-right">
                                                <div class="flex items-center justify-end gap-2">
                                                    <?php if ($is_pending): ?>
                                                        <a href="auction-action.php?id=<?php echo (int) $auction['auction_id']; ?>&action=approve"
                                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500 hover:text-white text-xs font-bold transition">
                                                            <i class="fa-solid fa-check text-[10px]"></i> Approve
                                                        </a>
                                                        <a href="auction-action.php?id=<?php echo (int) $auction['auction_id']; ?>&action=reject"
                                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-rose-500/10 text-rose-700 dark:text-rose-400 border border-rose-500/20 hover:bg-rose-500 hover:text-white text-xs font-bold transition">
                                                            <i class="fa-solid fa-xmark text-[10px]"></i> Reject
                                                        </a>
                                                    <?php elseif ($is_active): ?>
                                                        <a href="auction-action.php?id=<?php echo (int) $auction['auction_id']; ?>&action=cancel"
                                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-rose-500/10 text-rose-700 dark:text-rose-400 border border-rose-500/20 hover:bg-rose-500 hover:text-white text-xs font-bold transition">
                                                            <i class="fa-solid fa-ban text-[10px]"></i> Cancel
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="../auction-details.php?id=<?php echo (int) $auction['auction_id']; ?>"
                                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-indigo-600 hover:text-white text-gray-700 dark:text-gray-200 text-xs font-semibold transition">
                                                            <i class="fa-regular fa-eye text-[10px]"></i> View
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-20 text-center">
                                            <div
                                                class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                                <i class="fa-solid fa-box-open"></i>
                                            </div>
                                            <h3 class="text-xl font-black">No auctions found</h3>
                                            <p class="text-sm text-gray-500 mt-2 max-w-md mx-auto">Auctions will appear here
                                                once sellers submit them.</p>
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
                        <h3 class="font-bold">No matching auctions</h3>
                        <p class="text-sm text-gray-500 mt-1">Try adjusting your search or filters.</p>
                        <button type="button" id="resetFilters"
                            class="mt-5 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition">
                            <i class="fa-solid fa-rotate-right"></i> Reset Filters
                        </button>
                    </div>

                    <div
                        class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500">
                        <span>Showing <strong id="visibleCount"
                                class="text-gray-900 dark:text-white tabular-nums"><?php echo $total_count; ?></strong>
                            of <strong
                                class="text-gray-900 dark:text-white tabular-nums"><?php echo $total_count; ?></strong>
                            auctions</span>
                        <div class="flex items-center gap-3 flex-wrap">
                            <span class="flex items-center gap-1.5"><span
                                    class="w-2 h-2 rounded-full bg-amber-500"></span> Pending</span>
                            <span class="flex items-center gap-1.5"><span
                                    class="w-2 h-2 rounded-full bg-emerald-500"></span> Active</span>
                            <span class="flex items-center gap-1.5"><span
                                    class="w-2 h-2 rounded-full bg-blue-500"></span> Completed</span>
                            <span class="flex items-center gap-1.5"><span
                                    class="w-2 h-2 rounded-full bg-rose-500"></span> Rejected</span>
                        </div>
                    </div>
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

            const search = document.getElementById('auctionSearch');
            const statusFilter = document.getElementById('statusFilter');
            const categoryFilter = document.getElementById('categoryFilter');
            const noResults = document.getElementById('noFilterResults');
            const resetBtn = document.getElementById('resetFilters');
            const rows = Array.from(document.querySelectorAll('.data-row'));
            const visibleCount = document.getElementById('visibleCount');

            function applyFilters() {
                const q = (search?.value || '').trim().toLowerCase();
                const status = statusFilter?.value || 'all';
                const category = categoryFilter?.value || 'all';

                let shown = 0;
                rows.forEach(row => {
                    const title = row.dataset.title || '';
                    const seller = row.dataset.seller || '';
                    const rowCategory = row.dataset.category || '';
                    const rowStatus = row.dataset.status || '';

                    const matchesSearch = !q || title.includes(q) || seller.includes(q) || rowCategory.includes(q);
                    const matchesStatus = status === 'all' || rowStatus === status || (status === 'active' && rowStatus === 'approved');
                    const matchesCategory = category === 'all' || rowCategory === category;

                    const show = matchesSearch && matchesStatus && matchesCategory;
                    row.style.display = show ? '' : 'none';
                    if (show) shown++;
                });

                if (visibleCount) visibleCount.textContent = shown;
                if (noResults) noResults.classList.toggle('hidden', !(shown === 0 && rows.length > 0));
            }

            search?.addEventListener('input', applyFilters);
            statusFilter?.addEventListener('change', applyFilters);
            categoryFilter?.addEventListener('change', applyFilters);
            resetBtn?.addEventListener('click', () => {
                if (search) search.value = '';
                if (statusFilter) statusFilter.value = 'all';
                if (categoryFilter) categoryFilter.value = 'all';
                applyFilters();
            });
        })();
    </script>
</body>

</html>