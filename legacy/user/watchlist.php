<?php

session_start();

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";


if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = intval($_SESSION["user_id"]);


$user_stmt = $conn->prepare("SELECT full_name, role FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_info = $user_stmt->get_result()->fetch_assoc();
$full_name = $user_info["full_name"] ?? "User";
$role = $user_info["role"] ?? "user";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));

$unread_notifications = nb_unread_count($conn, $user_id);

/* ==========================================
   GET WATCHLIST AUCTIONS
========================================== */
$stmt = $conn->prepare("
    SELECT
        w.watchlist_id, w.created_at,
        a.auction_id, a.title, a.image, a.starting_price, a.current_bid,
        a.end_time, a.auction_status,
        c.category_name
    FROM watchlist w
    INNER JOIN auctions a ON w.auction_id = a.auction_id
    LEFT JOIN categories c ON a.category_id = c.category_id
    WHERE w.user_id = ?
    ORDER BY w.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$watchlist_result = $stmt->get_result();

$watchlist_data = [];
$live_count = 0;
$ended_count = 0;
$total_value = 0;
while ($row = $watchlist_result->fetch_assoc()) {
    $watchlist_data[] = $row;
    $price = floatval($row["current_bid"]) > 0 ? floatval($row["current_bid"]) : floatval($row["starting_price"]);
    $total_value += $price;
    if (strtolower($row["auction_status"]) === "active" && strtotime($row["end_time"]) > time())
        $live_count++;
    else
        $ended_count++;
}

$total_watchlist = count($watchlist_data);

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

function time_remaining($end_time)
{
    $diff = strtotime($end_time) - time();
    if ($diff <= 0)
        return null;
    return [
        "days" => intdiv($diff, 86400),
        "hours" => intdiv($diff % 86400, 3600),
        "minutes" => intdiv($diff % 3600, 60),
        "seconds" => $diff % 60,
        "total" => $diff,
    ];
}

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Watchlist | NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <meta name="description" content="Auctions you're currently watching on NexusBids.">

    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe', 300: '#a5b4fc',
                            400: '#818cf8', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca',
                            800: '#3730a3', 900: '#312e81',
                        }
                    },
                    animation: {
                        'fade-up': 'fadeUp 0.6s ease-out forwards',
                        'fade-in': 'fadeIn 0.4s ease-out forwards',
                        'pulse-soft': 'pulseSoft 2s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeUp: { '0%': { opacity: '0', transform: 'translateY(16px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        pulseSoft: { '0%, 100%': { opacity: '1' }, '50%': { opacity: '0.5' } },
                    }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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

        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
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

        .watchlist-card {
            transition: transform .3s cubic-bezier(.4, 0, .2, 1), border-color .3s ease, box-shadow .3s ease;
        }

        .watchlist-card:hover {
            transform: translateY(-6px);
        }

        .custom-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            appearance: none;
        }

        .heart-pulse {
            animation: heartPulse 1.8s ease-in-out infinite;
        }

        @keyframes heartPulse {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.15);
            }
        }
    </style>
</head>

<body
    class="bg-gray-50 text-gray-900 dark:bg-slate-950 dark:text-gray-100 font-sans antialiased selection:bg-indigo-500 selection:text-white min-h-screen flex flex-col">

    <!-- Top Utility Bar -->
    <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50 relative z-40">
        <div class="max-w-[1600px] mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-6">
                <span class="flex items-center gap-2"><i class="fa-solid fa-location-dot text-indigo-400"></i>
                    Pakistan</span>
                <span class="hidden sm:flex items-center gap-2"><i class="fa-solid fa-phone text-indigo-400"></i> +92
                    300 1234567</span>
            </div>
            <div class="flex items-center space-x-6">
                <a href="../about.php" class="hover:text-white transition-colors duration-200"><i
                        class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
                <a href="../about.php#why-nexusbids" class="hover:text-white transition-colors duration-200"><i
                        class="fa-solid fa-shield-halved text-indigo-400"></i> Secure Bidding</a>
            </div>
        </div>
    </div>

    <!-- Navbar -->
    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-[1600px] mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-gray-900 dark:text-white shrink-0 group"
                href="../index.php">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30 group-hover:shadow-indigo-500/50 group-hover:scale-105 transition-all duration-300">
                    <i class="fa-solid fa-gavel text-lg"></i>
                </span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            </a>

            <nav class="hidden lg:flex items-center gap-7 text-sm font-medium">
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="../index.php">Home</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="../auctions.php">Auctions</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="../categories.php">Categories</a>
                <a class="relative text-indigo-600 dark:text-indigo-400 font-semibold after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-indigo-600 dark:after:bg-indigo-400 after:rounded-full"
                    href="dashboard.php">Dashboard</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="../about.php">About</a>
            </nav>

            <div class="flex items-center gap-2 sm:gap-3">
                <button aria-label="Toggle Theme"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200 hover:scale-105"
                    id="themeToggle">
                    <i class="fa-solid fa-moon dark:hidden"></i><i
                        class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>

                <a href="../notifications.php"
                    class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200 hover:scale-105"
                    title="Notifications">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unread_notifications > 0): ?><span
                            class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800 animate-pulse-soft"></span><?php endif; ?>
                </a>

                <div class="flex items-center gap-3 pl-3 border-l border-gray-200 dark:border-gray-800">
                    <div class="relative">
                        <div
                            class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg shadow-indigo-500/30 ring-2 ring-white dark:ring-slate-900">
                            <?php echo htmlspecialchars($initials); ?>
                        </div>
                        <span
                            class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900"></span>
                    </div>
                    <div class="hidden sm:block text-left">
                        <div class="text-sm font-bold text-gray-900 dark:text-white leading-tight truncate max-w-32">
                            <?php echo htmlspecialchars($full_name); ?>
                        </div>
                        <div
                            class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1">
                            <i class="fa-solid fa-circle-check text-[8px]"></i> Verified Bidder
                        </div>
                    </div>
                </div>

                <a class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30"
                    href="../auth/logout.php">
                    <i class="fa-solid fa-right-from-bracket mr-1.5"></i> Logout
                </a>
            </div>
        </div>

        <div class="lg:hidden border-t border-gray-100 dark:border-gray-800 px-4 py-3 overflow-x-auto">
            <div class="flex items-center gap-5 text-sm whitespace-nowrap">
                <a class="text-gray-600 dark:text-gray-300" href="../index.php">Home</a>
                <a class="text-gray-600 dark:text-gray-300" href="../auctions.php">Auctions</a>
                <a class="text-gray-600 dark:text-gray-300" href="../categories.php">Categories</a>
                <a class="text-indigo-600 dark:text-indigo-400 font-semibold" href="dashboard.php">Dashboard</a>
                <a class="text-gray-600 dark:text-gray-300" href="../about.php">About</a>
            </div>
        </div>
    </header>

    <main class="flex-grow max-w-[1600px] mx-auto w-full px-4 sm:px-8 py-8 space-y-8">

        <!-- Flash Messages -->
        <?php if (isset($_SESSION["watchlist_success"])): ?>
            <div
                class="flex items-start gap-3 p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 dark:border-emerald-500/20 animate-fade-up">
                <div
                    class="w-9 h-9 rounded-lg bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <p class="text-sm text-emerald-700 dark:text-emerald-300 font-medium self-center">
                    <?php echo htmlspecialchars($_SESSION["watchlist_success"]);
                    unset($_SESSION["watchlist_success"]); ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION["watchlist_error"])): ?>
            <div
                class="flex items-start gap-3 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 dark:border-rose-500/20 animate-fade-up">
                <div
                    class="w-9 h-9 rounded-lg bg-rose-500/20 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-circle-exclamation"></i>
                </div>
                <p class="text-sm text-rose-700 dark:text-rose-300 font-medium self-center">
                    <?php echo htmlspecialchars($_SESSION["watchlist_error"]);
                    unset($_SESSION["watchlist_error"]); ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <section class="animate-fade-up">
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div>
                    <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                        <a href="dashboard.php"
                            class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">Dashboard</a>
                        <i class="fa-solid fa-chevron-right text-[8px]"></i>
                        <span class="text-gray-900 dark:text-white font-semibold">Watchlist</span>
                    </nav>
                    <h1
                        class="text-3xl sm:text-4xl font-black tracking-tight text-gray-900 dark:text-white flex items-center gap-3">
                        <span
                            class="w-12 h-12 rounded-2xl bg-gradient-to-br from-rose-500 to-pink-500 flex items-center justify-center text-white shadow-lg shadow-rose-500/30">
                            <i class="fa-solid fa-heart text-lg heart-pulse"></i>
                        </span>
                        My Watchlist
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Auctions you're currently watching.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="../auctions.php"
                        class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30 flex items-center gap-2">
                        <i class="fa-solid fa-magnifying-glass"></i> Browse Auctions
                    </a>
                    <a href="dashboard.php"
                        class="px-5 py-2.5 rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 dark:hover:border-indigo-500 text-gray-700 dark:text-gray-200 text-sm font-semibold transition-all duration-200 flex items-center gap-2">
                        <i class="fa-solid fa-chart-line"></i> Dashboard
                    </a>
                </div>
            </div>
        </section>

        <?php if ($total_watchlist > 0): ?>

            <!-- Stats Overview -->
            <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 animate-fade-up" style="animation-delay: 0.05s">
                <div
                    class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-rose-500 dark:hover:border-rose-500 hover:shadow-lg hover:shadow-rose-500/10 transition-all duration-300">
                    <div class="flex items-center justify-between mb-3">
                        <span
                            class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Watching</span>
                        <span
                            class="w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-xs group-hover:scale-110 transition-transform duration-300">
                            <i class="fa-solid fa-heart"></i>
                        </span>
                    </div>
                    <div class="text-2xl font-black text-gray-900 dark:text-white tabular-nums">
                        <?php echo $total_watchlist; ?>
                    </div>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">Total saved</p>
                </div>

                <div
                    class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-emerald-500 dark:hover:border-emerald-500 hover:shadow-lg hover:shadow-emerald-500/10 transition-all duration-300">
                    <div class="flex items-center justify-between mb-3">
                        <span
                            class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Live</span>
                        <span
                            class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs group-hover:scale-110 transition-transform duration-300">
                            <i class="fa-solid fa-bolt"></i>
                        </span>
                    </div>
                    <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                        <?php echo $live_count; ?>
                    </div>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">Accepting bids</p>
                </div>

                <div
                    class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-gray-500 dark:hover:border-gray-500 hover:shadow-lg hover:shadow-gray-500/10 transition-all duration-300">
                    <div class="flex items-center justify-between mb-3">
                        <span
                            class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Ended</span>
                        <span
                            class="w-9 h-9 rounded-lg bg-gray-500/10 text-gray-600 dark:text-gray-400 flex items-center justify-center text-xs group-hover:scale-110 transition-transform duration-300">
                            <i class="fa-solid fa-flag-checkered"></i>
                        </span>
                    </div>
                    <div class="text-2xl font-black text-gray-700 dark:text-gray-300 tabular-nums">
                        <?php echo $ended_count; ?>
                    </div>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">No longer active</p>
                </div>

                <div
                    class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 dark:hover:border-indigo-500 hover:shadow-lg hover:shadow-indigo-500/10 transition-all duration-300">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total
                            Value</span>
                        <span
                            class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs group-hover:scale-110 transition-transform duration-300">
                            <i class="fa-solid fa-coins"></i>
                        </span>
                    </div>
                    <div class="text-2xl font-black text-gray-900 dark:text-white tabular-nums">Rs.
                        <?php echo compact_rs($total_value); ?>
                    </div>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">Combined value</p>
                </div>
            </section>

            <!-- Toolbar -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4 flex flex-col sm:flex-row justify-between items-center gap-3 animate-fade-up"
                style="animation-delay: 0.08s">
                <div class="relative w-full sm:w-80 group">
                    <i
                        class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-indigo-500 transition-colors text-sm"></i>
                    <input id="watchSearch"
                        class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 placeholder:text-gray-400"
                        placeholder="Search watched auctions..." type="text" autocomplete="off">
                </div>
                <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                    <select id="statusFilter"
                        class="custom-select flex-1 sm:flex-initial py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 cursor-pointer">
                        <option value="all">All Status</option>
                        <option value="live">Live</option>
                        <option value="ended">Ended</option>
                    </select>
                    <select id="sortFilter"
                        class="custom-select flex-1 sm:flex-initial py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 cursor-pointer">
                        <option value="recent">Recently Added</option>
                        <option value="ending">Ending Soon</option>
                        <option value="price-high">Price: High to Low</option>
                        <option value="price-low">Price: Low to High</option>
                    </select>
                </div>
            </div>

            <!-- Watchlist Grid -->
            <section id="watchlistGrid"
                class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5 animate-fade-up"
                style="animation-delay: 0.1s">
                <?php foreach ($watchlist_data as $item):
                    $price = floatval($item["current_bid"]) > 0 ? floatval($item["current_bid"]) : floatval($item["starting_price"]);
                    $is_active = strtolower($item["auction_status"]) === "active";
                    $remaining = time_remaining($item["end_time"]);
                    $is_live = $is_active && $remaining !== null;
                    $is_ending_soon = $is_live && $remaining["total"] < 3600;
                    ?>
                    <article
                        class="watchlist-card group bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:border-rose-500 dark:hover:border-rose-500 rounded-2xl overflow-hidden shadow-sm hover:shadow-2xl hover:shadow-rose-500/10 flex flex-col"
                        data-title="<?php echo htmlspecialchars(strtolower($item['title'])); ?>"
                        data-status="<?php echo $is_live ? 'live' : 'ended'; ?>" data-price="<?php echo $price; ?>"
                        data-added="<?php echo strtotime($item['created_at']); ?>"
                        data-ending="<?php echo $remaining ? $remaining['total'] : PHP_INT_MAX; ?>">

                        <!-- Image -->
                        <div
                            class="relative h-48 bg-gray-100 dark:bg-slate-800 overflow-hidden flex items-center justify-center">
                            <?php if (!empty($item["image"])): ?>
                                <img src="../uploads/auctions/<?php echo htmlspecialchars($item["image"]); ?>"
                                    alt="<?php echo htmlspecialchars($item["title"]); ?>"
                                    class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700">
                            <?php else: ?>
                                <div class="flex flex-col items-center justify-center text-gray-400">
                                    <i class="fa-solid fa-image text-4xl mb-2"></i>
                                    <span class="text-xs">No Image</span>
                                </div>
                            <?php endif; ?>

                            <!-- Gradient overlay -->
                            <div
                                class="absolute inset-0 bg-gradient-to-t from-black/40 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                            </div>

                            <!-- Top badges -->
                            <div class="absolute top-3 left-3 right-3 flex justify-between items-start">
                                <?php if (!empty($item["category_name"])): ?>
                                    <span
                                        class="px-3 py-1.5 rounded-full bg-white/95 dark:bg-slate-950/95 glass text-gray-800 dark:text-gray-100 text-xs font-bold shadow-lg">
                                        <?php echo htmlspecialchars($item["category_name"]); ?>
                                    </span>
                                <?php else: ?><span></span><?php endif; ?>

                                <?php if ($is_ending_soon): ?>
                                    <span
                                        class="px-3 py-1.5 rounded-full bg-amber-500 text-white text-xs font-bold shadow-lg flex items-center gap-1.5 animate-pulse">
                                        <i class="fa-solid fa-clock text-[9px]"></i> Ending Soon
                                    </span>
                                <?php elseif ($is_live): ?>
                                    <span
                                        class="px-3 py-1.5 rounded-full bg-emerald-500 text-white text-xs font-bold shadow-lg flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span> LIVE
                                    </span>
                                <?php else: ?>
                                    <span
                                        class="px-3 py-1.5 rounded-full bg-gray-700 text-white text-xs font-bold shadow-lg flex items-center gap-1.5">
                                        <i class="fa-solid fa-flag-checkered text-[9px]"></i> Ended
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Heart removal button (top-right when no live badge) -->
                            <?php if (!$is_ending_soon && !$is_live): ?>
                                <form action="../remove-watchlist.php" method="POST" class="absolute bottom-3 right-3"
                                    onsubmit="return confirm('Remove this item from your watchlist?');">
                                    <?php if (function_exists('nb_csrf_field'))
                                        nb_csrf_field(); ?>
                                    <input type="hidden" name="watchlist_id" value="<?php echo (int) $item["watchlist_id"]; ?>">
                                    <button type="submit" aria-label="Remove from watchlist"
                                        class="w-10 h-10 rounded-full bg-rose-500 text-white flex items-center justify-center shadow-lg hover:scale-110 hover:bg-rose-600 transition-all duration-200">
                                        <i class="fa-solid fa-heart text-sm"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <!-- Content -->
                        <div class="p-5 flex-1 flex flex-col">
                            <h3
                                class="font-bold text-gray-900 dark:text-white line-clamp-2 min-h-12 leading-snug group-hover:text-rose-600 dark:group-hover:text-rose-400 transition-colors duration-200">
                                <?php echo htmlspecialchars($item["title"]); ?>
                            </h3>

                            <div class="mt-4 flex items-baseline justify-between">
                                <div>
                                    <span class="text-[11px] text-gray-400 block mb-0.5">Current Bid</span>
                                    <strong class="text-xl font-black text-gray-900 dark:text-white tabular-nums">Rs.
                                        <?php echo number_format($price); ?></strong>
                                </div>
                            </div>

                            <!-- Countdown -->
                            <?php if ($is_live): ?>
                                <div class="mt-4 rounded-xl bg-gray-50 dark:bg-slate-800/70 border border-gray-200 dark:border-gray-700 p-3 countdown-box"
                                    data-end="<?php echo htmlspecialchars(date('c', strtotime($item['end_time']))); ?>">
                                    <div class="flex items-center justify-between mb-2">
                                        <span
                                            class="text-[10px] uppercase tracking-wider font-bold text-gray-500 dark:text-gray-400">
                                            <i class="fa-regular fa-clock mr-1"></i> Time Left
                                        </span>
                                        <?php if ($remaining["days"] > 0): ?>
                                            <span
                                                class="text-[10px] font-bold text-gray-500 dark:text-gray-400"><?php echo $remaining["days"]; ?>
                                                day<?php echo $remaining["days"] == 1 ? '' : 's'; ?></span>
                                        <?php else: ?>
                                            <span class="text-[10px] font-bold text-amber-600 dark:text-amber-400">Same day</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="grid grid-cols-3 gap-2 text-center">
                                        <div>
                                            <span
                                                class="cd-hours block text-base font-black tabular-nums text-gray-900 dark:text-white"><?php echo str_pad($remaining["hours"], 2, '0', STR_PAD_LEFT); ?></span>
                                            <small class="text-[9px] uppercase text-gray-400 tracking-wider">Hours</small>
                                        </div>
                                        <div>
                                            <span
                                                class="cd-minutes block text-base font-black tabular-nums text-gray-900 dark:text-white"><?php echo str_pad($remaining["minutes"], 2, '0', STR_PAD_LEFT); ?></span>
                                            <small class="text-[9px] uppercase text-gray-400 tracking-wider">Min</small>
                                        </div>
                                        <div>
                                            <span
                                                class="cd-seconds block text-base font-black tabular-nums text-gray-900 dark:text-white"><?php echo str_pad($remaining["seconds"], 2, '0', STR_PAD_LEFT); ?></span>
                                            <small class="text-[9px] uppercase text-gray-400 tracking-wider">Sec</small>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div
                                    class="mt-4 rounded-xl bg-gray-50 dark:bg-slate-800/70 border border-gray-200 dark:border-gray-700 p-3 text-center">
                                    <span
                                        class="text-xs font-bold text-gray-500 dark:text-gray-400 flex items-center justify-center gap-1.5">
                                        <i class="fa-solid fa-flag-checkered text-[10px]"></i> Auction Ended
                                    </span>
                                </div>
                            <?php endif; ?>

                            <!-- Actions -->
                            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 flex items-center gap-2">
                                <a href="../auction-details.php?id=<?php echo (int) $item["auction_id"]; ?>"
                                    class="flex-1 group/btn inline-flex items-center justify-center gap-1.5 px-3 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold shadow-sm shadow-indigo-600/20 hover:shadow-indigo-500/40 transition-all duration-200">
                                    <?php echo $is_live ? 'Bid Now' : 'View Details'; ?>
                                    <i
                                        class="fa-solid fa-arrow-right text-[10px] group-hover/btn:translate-x-0.5 transition-transform duration-200"></i>
                                </a>
                                <form action="../remove-watchlist.php" method="POST"
                                    onsubmit="return confirm('Remove this item from your watchlist?');">
                                    <?php if (function_exists('nb_csrf_field'))
                                        nb_csrf_field(); ?>
                                    <input type="hidden" name="watchlist_id" value="<?php echo (int) $item["watchlist_id"]; ?>">
                                    <button type="submit" aria-label="Remove from watchlist"
                                        class="w-10 h-10 rounded-xl bg-gray-100 dark:bg-slate-800 hover:bg-rose-500 hover:text-white text-gray-500 dark:text-gray-400 flex items-center justify-center transition-all duration-200"
                                        title="Remove from watchlist">
                                        <i class="fa-solid fa-heart-crack text-sm"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <!-- No Filter Results -->
            <div id="noFilterResults"
                class="hidden p-12 text-center bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800">
                <div
                    class="w-16 h-16 mx-auto rounded-2xl bg-gray-100 dark:bg-slate-800 text-gray-400 flex items-center justify-center text-2xl mb-4">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <h3 class="font-bold text-gray-900 dark:text-white">No matching items</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Try adjusting your search or filters.</p>
                <button type="button" id="resetFilters"
                    class="mt-5 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition-all duration-200">
                    <i class="fa-solid fa-rotate-right"></i> Reset Filters
                </button>
            </div>

        <?php else: ?>

            <!-- Empty State -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-12 sm:p-20 text-center animate-fade-up"
                style="animation-delay: 0.05s">
                <div
                    class="w-24 h-24 mx-auto rounded-3xl bg-gradient-to-br from-rose-500/10 to-pink-500/10 flex items-center justify-center text-4xl mb-6 relative">
                    <i class="fa-regular fa-heart text-rose-500 heart-pulse"></i>
                </div>
                <h2 class="text-2xl sm:text-3xl font-black text-gray-900 dark:text-white">Your Watchlist is Empty</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-3 max-w-md mx-auto leading-relaxed">Add auctions to
                    your watchlist to keep track of items you're interested in. You'll be able to monitor prices, bidding
                    activity, and end times all in one place.</p>
                <div class="flex flex-wrap gap-3 justify-center mt-8">
                    <a href="../auctions.php"
                        class="group px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold transition-all duration-300 hover:-translate-y-0.5 shadow-lg shadow-indigo-600/30 hover:shadow-indigo-500/40 flex items-center gap-2">
                        <i class="fa-solid fa-magnifying-glass"></i> Browse Auctions
                        <i
                            class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform duration-300"></i>
                    </a>
                    <a href="../categories.php"
                        class="px-6 py-3 rounded-xl bg-white dark:bg-slate-800 border border-gray-200 dark:border-gray-700 hover:border-indigo-500 dark:hover:border-indigo-500 text-gray-700 dark:text-gray-200 text-sm font-bold transition-all duration-300 hover:-translate-y-0.5 flex items-center gap-2">
                        <i class="fa-solid fa-layer-group"></i> Browse Categories
                    </a>
                </div>

                <!-- Tip -->
                <div
                    class="mt-12 max-w-md mx-auto p-4 rounded-2xl bg-indigo-50/50 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/30">
                    <div class="flex items-start gap-3">
                        <div
                            class="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm shrink-0">
                            <i class="fa-solid fa-lightbulb"></i>
                        </div>
                        <div class="text-left">
                            <h4 class="font-bold text-gray-900 dark:text-white text-xs">Pro Tip</h4>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1 leading-relaxed">Click the heart icon on
                                any auction page to add it to your watchlist for quick access later.</p>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-950 text-gray-400 text-xs border-t border-gray-900 mt-8">
        <div class="max-w-[1600px] mx-auto px-4 sm:px-8 py-16 grid grid-cols-1 md:grid-cols-5 gap-10">
            <div class="md:col-span-2 space-y-5">
                <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-white" href="../index.php">
                    <div
                        class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30">
                        <i class="fa-solid fa-gavel text-lg"></i>
                    </div>
                    Nexus<span class="text-indigo-400">Bids</span>
                </a>
                <p class="text-gray-400 text-xs leading-relaxed max-w-sm">Pakistan's modern online auction marketplace.
                    Discover, bid and win products you love.</p>
                <div class="flex items-center space-x-3 pt-2">
                    <a aria-label="Facebook"
                        class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50 hover:bg-indigo-500/10 transition-all duration-200"
                        href="#"><i class="fa-brands fa-facebook-f"></i></a>
                    <a aria-label="Instagram"
                        class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50 hover:bg-indigo-500/10 transition-all duration-200"
                        href="#"><i class="fa-brands fa-instagram"></i></a>
                    <a aria-label="X"
                        class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50 hover:bg-indigo-500/10 transition-all duration-200"
                        href="#"><i class="fa-brands fa-x-twitter"></i></a>
                    <a aria-label="LinkedIn"
                        class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50 hover:bg-indigo-500/10 transition-all duration-200"
                        href="#"><i class="fa-brands fa-linkedin-in"></i></a>
                </div>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Marketplace</h4>
                <ul class="space-y-2.5">
                    <li><a class="hover:text-white transition-colors duration-200" href="../auctions.php">Browse
                            Auctions</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="../auctions.php">Live
                            Auctions</a></li>
                    <li><a class="hover:text-white transition-colors duration-200"
                            href="../categories.php">Categories</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="../index.php#auctions">Featured
                            Products</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="../index.php#how-it-works">How
                            It Works</a></li>
                </ul>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Account</h4>
                <ul class="space-y-2.5">
                    <li><a class="hover:text-white transition-colors duration-200" href="dashboard.php">My Dashboard</a>
                    </li>
                    <li><a class="hover:text-white transition-colors duration-200" href="my-bids.php">My Bids</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="won-auctions.php">Won
                            Auctions</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="watchlist.php">Watchlist</a>
                    </li>
                    <li><a class="hover:text-white transition-colors duration-200" href="../auth/logout.php">Logout</a>
                    </li>
                </ul>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Support</h4>
                <ul class="space-y-2.5">
                    <li><a class="hover:text-white transition-colors duration-200" href="../about.php">Help Center</a>
                    </li>
                    <li><a class="hover:text-white transition-colors duration-200" href="../about.php#contact">Contact
                            Us</a></li>
                    <li><a class="hover:text-white transition-colors duration-200"
                            href="../about.php#why-nexusbids">Safety Center</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="../about.php">Terms &amp;
                            Conditions</a></li>
                    <li><a href="../about.php" class="hover:text-white transition-colors duration-200">Privacy
                            Policy</a></li>
                </ul>
            </div>
        </div>
        <div
            class="border-t border-gray-900 py-6 text-center text-xs text-gray-500 max-w-[1600px] mx-auto px-4 flex flex-col sm:flex-row justify-between items-center gap-4">
            <p>© <?php echo date("Y"); ?> NexusBids. All rights reserved.</p>
            <p>Made for Pakistan <span class="text-rose-500"><i class="fa-solid fa-heart"></i></span></p>
        </div>
    </footer>

    <script>
        (function () {
            const html = document.documentElement;
            const themeToggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                html.classList.add('dark');
            }
            themeToggle?.addEventListener('click', function () {
                html.classList.toggle('dark');
                localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
            });

            // Countdown timers
            function updateCountdowns() {
                document.querySelectorAll('.countdown-box').forEach(box => {
                    const end = new Date(box.dataset.end).getTime();
                    const diff = end - Date.now();
                    if (diff <= 0) {
                        box.querySelector('.cd-hours').textContent = '00';
                        box.querySelector('.cd-minutes').textContent = '00';
                        box.querySelector('.cd-seconds').textContent = '00';
                        return;
                    }
                    const h = Math.floor(diff / 3600000);
                    const m = Math.floor((diff % 3600000) / 60000);
                    const s = Math.floor((diff % 60000) / 1000);
                    box.querySelector('.cd-hours').textContent = String(h).padStart(2, '0');
                    box.querySelector('.cd-minutes').textContent = String(m).padStart(2, '0');
                    box.querySelector('.cd-seconds').textContent = String(s).padStart(2, '0');
                });
            }
            updateCountdowns();
            setInterval(updateCountdowns, 1000);

            // Filtering & sorting
            const search = document.getElementById('watchSearch');
            const statusFilter = document.getElementById('statusFilter');
            const sortFilter = document.getElementById('sortFilter');
            const grid = document.getElementById('watchlistGrid');
            const noResults = document.getElementById('noFilterResults');
            const resetBtn = document.getElementById('resetFilters');

            if (!grid) return;
            const cards = Array.from(grid.querySelectorAll('.watchlist-card'));

            function applyFilters() {
                const q = (search?.value || '').trim().toLowerCase();
                const status = statusFilter?.value || 'all';
                const sort = sortFilter?.value || 'recent';

                // Sort
                const sorted = [...cards].sort((a, b) => {
                    const aAdded = Number(a.dataset.added || 0);
                    const bAdded = Number(b.dataset.added || 0);
                    const aPrice = Number(a.dataset.price || 0);
                    const bPrice = Number(b.dataset.price || 0);
                    const aEnd = Number(a.dataset.ending || 0);
                    const bEnd = Number(b.dataset.ending || 0);
                    if (sort === 'ending') return aEnd - bEnd;
                    if (sort === 'price-high') return bPrice - aPrice;
                    if (sort === 'price-low') return aPrice - bPrice;
                    return bAdded - aAdded;
                });
                sorted.forEach(c => grid.appendChild(c));

                // Filter
                let shown = 0;
                cards.forEach(card => {
                    const title = card.dataset.title || '';
                    const cardStatus = card.dataset.status || '';
                    const matchesSearch = !q || title.includes(q);
                    const matchesStatus = status === 'all' || cardStatus === status;
                    const show = matchesSearch && matchesStatus;
                    card.style.display = show ? '' : 'none';
                    if (show) shown++;
                });

                if (noResults) noResults.classList.toggle('hidden', shown !== 0);
            }

            search?.addEventListener('input', applyFilters);
            statusFilter?.addEventListener('change', applyFilters);
            sortFilter?.addEventListener('change', applyFilters);

            resetBtn?.addEventListener('click', () => {
                if (search) search.value = '';
                if (statusFilter) statusFilter.value = 'all';
                if (sortFilter) sortFilter.value = 'recent';
                applyFilters();
            });
        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>