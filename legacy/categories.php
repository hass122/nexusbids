<?php

session_start();

require_once "config/database.php";
require_once "includes/csrf.php";
require_once "includes/notifications.php";

/*
 * EXISTING BACKEND - UNCHANGED
 */
$categories_result = $conn->query("
    SELECT
        c.category_id,
        c.category_name,
        c.icon,
        c.color,
        c.description,
        COUNT(a.auction_id) AS auction_count
    FROM categories c
    LEFT JOIN auctions a
        ON a.category_id = c.category_id
        AND a.auction_status = 'active'
    WHERE c.status = 'active'
    GROUP BY c.category_id, c.category_name, c.icon, c.color, c.description
    ORDER BY c.category_name ASC
");

$categories = $categories_result ? $categories_result->fetch_all(MYSQLI_ASSOC) : [];

$unread_notifications = isset($_SESSION["user_id"])
    ? nb_unread_count($conn, intval($_SESSION["user_id"]))
    : 0;

// Aggregate stats for hero
$total_active_auctions = 0;
$total_categories_with_auctions = 0;
foreach ($categories as $c) {
    $total_active_auctions += intval($c['auction_count'] ?? 0);
    if (intval($c['auction_count'] ?? 0) > 0)
        $total_categories_with_auctions++;
}

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories | NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <meta name="description"
        content="Browse all auction categories on NexusBids — electronics, vehicles, fashion, jewelry, collectibles and more.">

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
                        'float': 'float 6s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeUp: {'0%': {opacity: '0', transform: 'translateY(16px)'}, '100%': {opacity: '1', transform: 'translateY(0)'}},
                        fadeIn: {'0%': {opacity: '0'}, '100%': {opacity: '1'}},
                        pulseSoft: {'0%, 100%': {opacity: '1'}, '50%': {opacity: '0.5'}},
                        float: {'0%, 100%': {transform: 'translateY(0)'}, '50%': {transform: 'translateY(-10px)'}},
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

        .category-card {
            transition: transform .3s cubic-bezier(.4, 0, .2, 1), border-color .3s ease, box-shadow .3s ease;
        }

        .category-card:hover {
            transform: translateY(-6px);
        }

        .category-hidden {
            display: none !important;
        }

        .mobile-menu-open {
            display: flex !important;
        }

        @media (max-width: 767px) {
            .desktop-nav {
                display: none !important;
            }
        }

        .custom-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            appearance: none;
        }
    </style>
</head>

<body
    class="bg-gray-50 text-gray-900 dark:bg-slate-950 dark:text-gray-100 font-sans antialiased selection:bg-indigo-500 selection:text-white">

    <!-- Top Utility Bar -->
    <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50 relative z-40">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-6">
                <span class="flex items-center gap-2"><i class="fa-solid fa-location-dot text-indigo-400"></i>
                    Pakistan</span>
                <span class="hidden sm:flex items-center gap-2"><i class="fa-solid fa-phone text-indigo-400"></i> +92
                    300 1234567</span>
            </div>
            <div class="flex items-center space-x-6">
                <a class="hover:text-white transition-colors duration-200 flex items-center gap-1.5" href="about.php">
                    <i class="fa-regular fa-circle-question text-indigo-400"></i> Help Center
                </a>
                <span class="hidden sm:flex items-center gap-1.5"><i
                        class="fa-solid fa-shield-halved text-indigo-400"></i> Secure Bidding</span>
            </div>
        </div>
    </div>

    <!-- Main Navbar -->
    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between">
            <a href="index.php"
                class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-gray-900 dark:text-white group">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30 group-hover:shadow-indigo-500/50 group-hover:scale-105 transition-all duration-300">
                    <i class="fa-solid fa-gavel text-lg"></i>
                </span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            </a>

            <nav class="desktop-nav hidden md:flex items-center space-x-8 text-sm font-medium">
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="index.php">Home</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="auctions.php">Auctions</a>
                <a class="relative text-indigo-600 dark:text-indigo-400 font-semibold after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-indigo-600 dark:after:bg-indigo-400 after:rounded-full"
                    href="categories.php">Categories</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="about.php">About</a>
            </nav>

            <div class="flex items-center gap-2 sm:gap-3">
                <button aria-label="Toggle Theme"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200 hover:scale-105"
                    id="themeToggle">
                    <i class="fa-solid fa-moon dark:hidden"></i>
                    <i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>

                <!-- ⭐ NOTIFICATION BELL — Always Visible -->
                <?php if (isset($_SESSION["user_id"])): ?>
                    <a href="notifications.php"
                        class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200 hover:scale-105"
                        title="Notifications">
                        <i class="fa-regular fa-bell"></i>
                        <?php if ($unread_notifications > 0): ?>
                            <span
                                class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center border-2 border-white dark:border-gray-800 animate-pulse-soft">
                                <?php echo $unread_notifications > 99 ? '99+' : $unread_notifications; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>

                <!-- Desktop Auth Buttons -->
                <div class="hidden lg:flex items-center gap-2 sm:gap-3">
                    <?php if (isset($_SESSION["user_id"])): ?>
                        <a href="<?php echo ($_SESSION["role"] ?? "") === "seller" ? "seller/dashboard.php" : "user/dashboard.php"; ?>"
                            class="inline-flex px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-md transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30">
                            <i class="fa-solid fa-user mr-2"></i> Dashboard
                        </a>
                    <?php else: ?>
                        <a href="auth/login.php"
                            class="inline-flex px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                            Login
                        </a>
                        <a href="auth/register.php"
                            class="inline-flex px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-md transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30">
                            Register
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Hamburger -->
                <button id="mobileMenuBtn" aria-label="Toggle Menu" aria-expanded="false"
                    class="lg:hidden w-10 h-10 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors">
                    <i class="fa-solid fa-bars" id="menuIcon"></i>
                </button>
            </div>
        </div>

        <!-- Mobile Dropdown Menu -->
        <div id="mobileMenu"
            class="hidden lg:hidden border-t border-gray-200 dark:border-gray-800 bg-white dark:bg-slate-900 shadow-lg">
            <nav class="px-4 py-3 space-y-1">
                <a href="index.php"
                    class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
                    <i class="fa-solid fa-house w-5 text-center text-indigo-500"></i> Home
                </a>
                <a href="auctions.php"
                    class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
                    <i class="fa-solid fa-gavel w-5 text-center text-indigo-500"></i> Auctions
                </a>
                <a href="categories.php"
                    class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/30 transition-all">
                    <i class="fa-solid fa-layer-group w-5 text-center"></i> Categories
                </a>
                <a href="index.php#how-it-works"
                    class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
                    <i class="fa-solid fa-circle-question w-5 text-center text-indigo-500"></i> How It Works
                </a>
                <a href="about.php"
                    class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
                    <i class="fa-solid fa-circle-info w-5 text-center text-indigo-500"></i> About
                </a>

                <div class="my-2 border-t border-gray-100 dark:border-gray-800"></div>

                <?php if (isset($_SESSION["user_id"])): ?>
                    <a href="<?php echo ($_SESSION["role"] ?? "") === "seller" ? "seller/dashboard.php" : "user/dashboard.php"; ?>"
                        class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
                        <i class="fa-solid fa-gauge-high w-5 text-center text-indigo-500"></i> Dashboard
                    </a>
                    <a href="notifications.php"
                        class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
                        <i class="fa-regular fa-bell w-5 text-center text-indigo-500"></i> Notifications
                        <?php if ($unread_notifications > 0): ?>
                            <span
                                class="ml-auto px-2 py-0.5 rounded-full bg-rose-500 text-white text-[10px] font-bold"><?php echo $unread_notifications; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="auth/logout.php"
                        class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-all">
                        <i class="fa-solid fa-right-from-bracket w-5 text-center"></i> Logout
                    </a>
                <?php else: ?>
                    <a href="auth/login.php"
                        class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
                        <i class="fa-solid fa-right-to-bracket w-5 text-center text-indigo-500"></i> Login
                    </a>
                    <a href="auth/register.php"
                        class="flex items-center justify-center gap-2 mx-2 mt-1 px-4 py-3 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 shadow-lg shadow-indigo-600/30 transition-all">
                        <i class="fa-solid fa-user-plus"></i> Create Free Account
                    </a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main>
        <!-- Category Hero -->
        <section class="relative bg-slate-950 text-white overflow-hidden py-16 lg:py-24 noise-overlay">
            <div
                class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-indigo-900/40 via-slate-950 to-slate-950">
            </div>
            <div
                class="absolute top-0 left-1/4 w-[500px] h-[500px] bg-indigo-600/20 rounded-full blur-[120px] pointer-events-none animate-float">
            </div>
            <div
                class="absolute bottom-0 right-1/4 w-[400px] h-[400px] bg-violet-600/15 rounded-full blur-[100px] pointer-events-none">
            </div>
            <div
                class="absolute top-1/2 left-0 w-[300px] h-[300px] bg-blue-600/10 rounded-full blur-[80px] pointer-events-none">
            </div>
            <div class="absolute inset-0 opacity-[0.03]"
                style="background-image: linear-gradient(rgba(255,255,255,.1) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.1) 1px, transparent 1px); background-size: 60px 60px;">
            </div>

            <div class="max-w-7xl mx-auto px-4 sm:px-8 text-center relative z-10 space-y-6">
                <span
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 text-xs font-semibold uppercase tracking-wider animate-fade-up">
                    <span class="relative flex h-2.5 w-2.5">
                        <span
                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-indigo-400"></span>
                    </span>
                    Marketplace Directory
                </span>
                <h1 class="text-4xl sm:text-6xl font-black tracking-tight leading-[1.1] animate-fade-up"
                    style="animation-delay: 0.1s">
                    Explore All <span
                        class="gradient-text bg-gradient-to-r from-blue-400 via-indigo-400 to-violet-400">Categories</span>
                </h1>
                <p class="text-gray-300 text-base sm:text-lg max-w-2xl mx-auto leading-relaxed animate-fade-up"
                    style="animation-delay: 0.2s">
                    Browse active auctions across diverse categories. Find electronics, vehicles, collectibles, fashion
                    and more.
                </p>

                <!-- Hero stats -->
                <div class="flex flex-wrap justify-center gap-3 sm:gap-6 pt-2 animate-fade-up"
                    style="animation-delay: 0.25s">
                    <div
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white/5 border border-white/10 backdrop-blur-sm">
                        <i class="fa-solid fa-layer-group text-indigo-400 text-sm"></i>
                        <span class="text-sm font-bold tabular-nums"><?php echo count($categories); ?></span>
                        <span class="text-xs text-gray-400">Categories</span>
                    </div>
                    <div
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white/5 border border-white/10 backdrop-blur-sm">
                        <i class="fa-solid fa-gavel text-emerald-400 text-sm"></i>
                        <span
                            class="text-sm font-bold tabular-nums"><?php echo number_format($total_active_auctions); ?></span>
                        <span class="text-xs text-gray-400">Live Auctions</span>
                    </div>
                    <div
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white/5 border border-white/10 backdrop-blur-sm">
                        <i class="fa-solid fa-circle-check text-blue-400 text-sm"></i>
                        <span
                            class="text-sm font-bold tabular-nums"><?php echo $total_categories_with_auctions; ?></span>
                        <span class="text-xs text-gray-400">Active Now</span>
                    </div>
                </div>

                <div class="max-w-xl mx-auto animate-fade-up pt-4" style="animation-delay: 0.3s">
                    <div class="relative">
                        <span
                            class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none text-gray-400">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </span>
                        <input id="categorySearch"
                            class="w-full pl-12 pr-12 py-4 bg-slate-900/80 glass border border-gray-700/50 rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-white shadow-xl placeholder:text-gray-500 transition-all duration-200"
                            placeholder="Search categories..." type="text" autocomplete="off">
                        <button id="clearSearch" type="button"
                            class="hidden absolute inset-y-0 right-0 pr-5 flex items-center text-gray-400 hover:text-white transition-colors"
                            aria-label="Clear search">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <!-- Quick filter chips -->
                <div class="flex flex-wrap justify-center items-center gap-2 pt-4 animate-fade-up"
                    style="animation-delay: 0.35s">
                    <button type="button" data-filter="all"
                        class="filter-btn px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-semibold shadow-lg shadow-indigo-600/30 hover:bg-indigo-500 transition-all duration-200">
                        <i class="fa-solid fa-border-all mr-1.5"></i> All Categories
                    </button>
                    <button type="button" data-filter="active"
                        class="filter-btn px-4 py-2 rounded-xl bg-slate-900/80 hover:bg-slate-800 text-gray-300 text-xs font-semibold border border-gray-700/50 hover:border-gray-600 transition-all duration-200">
                        <i class="fa-solid fa-fire text-rose-400 mr-1.5"></i> With Auctions
                    </button>
                    <button type="button" data-filter="empty"
                        class="filter-btn px-4 py-2 rounded-xl bg-slate-900/80 hover:bg-slate-800 text-gray-300 text-xs font-semibold border border-gray-700/50 hover:border-gray-600 transition-all duration-200">
                        <i class="fa-regular fa-circle mr-1.5"></i> No Auctions
                    </button>
                </div>
            </div>
        </section>

        <!-- Toolbar -->
        <div
            class="bg-white dark:bg-slate-900 border-b border-gray-200 dark:border-gray-800 py-4 sticky top-20 z-30 glass">
            <div class="max-w-7xl mx-auto px-4 sm:px-8 flex flex-col sm:flex-row justify-between items-center gap-4">
                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <select id="auctionFilter"
                        class="custom-select py-3 px-4 pr-10 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 cursor-pointer">
                        <option value="all">Auction Status: All</option>
                        <option value="active">With Live Auctions</option>
                        <option value="empty">No Live Auctions</option>
                    </select>
                    <select id="sortFilter"
                        class="custom-select py-3 px-4 pr-10 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 cursor-pointer">
                        <option value="name">Sort: A-Z</option>
                        <option value="count">Most Auctions</option>
                        <option value="count-low">Least Auctions</option>
                    </select>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Showing <strong id="visibleCount"
                        class="text-gray-900 dark:text-white"><?php echo count($categories); ?></strong> of
                    <strong class="text-gray-900 dark:text-white"><?php echo count($categories); ?></strong> categories
                </p>
            </div>
        </div>

        <!-- Dynamic Category Grid -->
        <section class="py-16 sm:py-20 max-w-7xl mx-auto px-4 sm:px-8">
            <?php if (!empty($categories)): ?>
                <div id="categoryGrid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                    <?php foreach ($categories as $category):
                        $categoryId = intval($category["category_id"]);
                        $categoryName = $category["category_name"] ?? "Category";
                        $categoryIcon = trim((string) ($category["icon"] ?? ""));
                        $categoryColor = strtolower(trim((string) ($category["color"] ?? "indigo")));
                        $auctionCount = intval($category["auction_count"] ?? 0);

                        if ($categoryIcon === "") {
                            $categoryIcon = "fa-box-open";
                        } elseif (strpos($categoryIcon, "fa-") !== 0) {
                            $categoryIcon = "fa-" . $categoryIcon;
                        }

                        $colorMap = [
                            "indigo" => ["bg" => "bg-indigo-50 dark:bg-indigo-950/50", "text" => "text-indigo-600 dark:text-indigo-400", "grad" => "from-indigo-500 to-indigo-700", "ring" => "group-hover:ring-indigo-500/20"],
                            "blue" => ["bg" => "bg-blue-50 dark:bg-blue-950/50", "text" => "text-blue-600 dark:text-blue-400", "grad" => "from-blue-500 to-blue-700", "ring" => "group-hover:ring-blue-500/20"],
                            "cyan" => ["bg" => "bg-cyan-50 dark:bg-cyan-950/50", "text" => "text-cyan-600 dark:text-cyan-400", "grad" => "from-cyan-500 to-cyan-700", "ring" => "group-hover:ring-cyan-500/20"],
                            "emerald" => ["bg" => "bg-emerald-50 dark:bg-emerald-950/50", "text" => "text-emerald-600 dark:text-emerald-400", "grad" => "from-emerald-500 to-emerald-700", "ring" => "group-hover:ring-emerald-500/20"],
                            "green" => ["bg" => "bg-green-50 dark:bg-green-950/50", "text" => "text-green-600 dark:text-green-400", "grad" => "from-green-500 to-green-700", "ring" => "group-hover:ring-green-500/20"],
                            "violet" => ["bg" => "bg-violet-50 dark:bg-violet-950/50", "text" => "text-violet-600 dark:text-violet-400", "grad" => "from-violet-500 to-violet-700", "ring" => "group-hover:ring-violet-500/20"],
                            "purple" => ["bg" => "bg-purple-50 dark:bg-purple-950/50", "text" => "text-purple-600 dark:text-purple-400", "grad" => "from-purple-500 to-purple-700", "ring" => "group-hover:ring-purple-500/20"],
                            "fuchsia" => ["bg" => "bg-fuchsia-50 dark:bg-fuchsia-950/50", "text" => "text-fuchsia-600 dark:text-fuchsia-400", "grad" => "from-fuchsia-500 to-fuchsia-700", "ring" => "group-hover:ring-fuchsia-500/20"],
                            "pink" => ["bg" => "bg-pink-50 dark:bg-pink-950/50", "text" => "text-pink-600 dark:text-pink-400", "grad" => "from-pink-500 to-pink-700", "ring" => "group-hover:ring-pink-500/20"],
                            "rose" => ["bg" => "bg-rose-50 dark:bg-rose-950/50", "text" => "text-rose-600 dark:text-rose-400", "grad" => "from-rose-500 to-rose-700", "ring" => "group-hover:ring-rose-500/20"],
                            "red" => ["bg" => "bg-red-50 dark:bg-red-950/50", "text" => "text-red-600 dark:text-red-400", "grad" => "from-red-500 to-red-700", "ring" => "group-hover:ring-red-500/20"],
                            "orange" => ["bg" => "bg-orange-50 dark:bg-orange-950/50", "text" => "text-orange-600 dark:text-orange-400", "grad" => "from-orange-500 to-orange-700", "ring" => "group-hover:ring-orange-500/20"],
                            "amber" => ["bg" => "bg-amber-50 dark:bg-amber-950/50", "text" => "text-amber-600 dark:text-amber-400", "grad" => "from-amber-500 to-amber-700", "ring" => "group-hover:ring-amber-500/20"],
                            "teal" => ["bg" => "bg-teal-50 dark:bg-teal-950/50", "text" => "text-teal-600 dark:text-teal-400", "grad" => "from-teal-500 to-teal-700", "ring" => "group-hover:ring-teal-500/20"],
                            "sky" => ["bg" => "bg-sky-50 dark:bg-sky-950/50", "text" => "text-sky-600 dark:text-sky-400", "grad" => "from-sky-500 to-sky-700", "ring" => "group-hover:ring-sky-500/20"],
                        ];
                        $theme = $colorMap[$categoryColor] ?? $colorMap["indigo"];
                        ?>
                        <article
                            class="category-card group relative bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 dark:hover:border-indigo-500 rounded-2xl p-6 shadow-sm hover:shadow-2xl hover:shadow-indigo-500/10 ring-1 ring-transparent <?php echo $theme['ring']; ?>"
                            data-name="<?php echo htmlspecialchars(strtolower($categoryName), ENT_QUOTES, 'UTF-8'); ?>"
                            data-count="<?php echo $auctionCount; ?>">

                            <!-- Subtle top accent line -->
                            <div
                                class="absolute top-0 left-6 right-6 h-px bg-gradient-to-r from-transparent via-gray-200 dark:via-gray-800 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                            </div>

                            <div class="flex justify-between items-start mb-5">
                                <div
                                    class="w-14 h-14 rounded-2xl <?php echo $theme["bg"]; ?> <?php echo $theme["text"]; ?> flex items-center justify-center text-2xl group-hover:scale-110 group-hover:-rotate-3 transition-all duration-300">
                                    <i class="fa-solid <?php echo htmlspecialchars($categoryIcon, ENT_QUOTES, 'UTF-8'); ?>"></i>
                                </div>
                                <?php if ($auctionCount > 0): ?>
                                    <span
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 text-[10px] font-bold rounded-full border border-emerald-500/20">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                        Active
                                    </span>
                                <?php else: ?>
                                    <span
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-gray-400 text-[10px] font-bold rounded-full border border-gray-200 dark:border-gray-700">
                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                        Empty
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div>
                                <h3
                                    class="font-bold text-gray-900 dark:text-white text-base group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors duration-200">
                                    <?php echo htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8'); ?>
                                </h3>
                                <?php if (!empty($category["description"])): ?>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 line-clamp-2 leading-relaxed">
                                        <?php echo htmlspecialchars($category["description"], ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                <?php else: ?>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5">
                                        <?php echo $auctionCount; ?> active auction<?php echo $auctionCount === 1 ? "" : "s"; ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <!-- Auction count pill -->
                            <div class="mt-5 flex items-center gap-2">
                                <div class="flex-1 h-1.5 bg-gray-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                    <?php
                                    $max_count = 1;
                                    foreach ($categories as $c)
                                        $max_count = max($max_count, intval($c['auction_count'] ?? 0));
                                    $pct = $max_count > 0 ? min(100, ($auctionCount / $max_count) * 100) : 0;
                                    ?>
                                    <div class="h-full rounded-full bg-gradient-to-r <?php echo $theme['grad']; ?> transition-all duration-700"
                                        style="width: <?php echo $pct; ?>%"></div>
                                </div>
                                <span
                                    class="text-[10px] font-bold text-gray-500 dark:text-gray-400 tabular-nums whitespace-nowrap">
                                    <?php echo $auctionCount; ?> live
                                </span>
                            </div>

                            <div
                                class="mt-5 pt-4 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
                                <span class="text-xs text-gray-400 whitespace-nowrap">
                                    <?php echo $auctionCount > 0 ? 'Bid now' : 'Check back'; ?>
                                </span>
                                <a class="group/btn inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30"
                                    href="auctions.php?category=<?php echo $categoryId; ?>">
                                    Browse
                                    <i
                                        class="fa-solid fa-arrow-right text-[10px] group-hover/btn:translate-x-0.5 transition-transform duration-200"></i>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <!-- Empty state -->
                <div id="noResults" class="hidden text-center py-20">
                    <div
                        class="w-20 h-20 rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 mx-auto flex items-center justify-center text-3xl mb-5">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </div>
                    <h3 class="text-xl font-black text-gray-900 dark:text-white">No categories found</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 max-w-md mx-auto">Try a different search term or
                        change the filter to see more categories.</p>
                    <button type="button" id="resetFilters"
                        class="mt-6 inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-semibold text-sm transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30">
                        <i class="fa-solid fa-rotate-right"></i> Reset Filters
                    </button>
                </div>
            <?php else: ?>
                <div class="text-center py-20">
                    <div
                        class="w-20 h-20 rounded-2xl bg-indigo-50 dark:bg-indigo-950/40 text-indigo-500 mx-auto flex items-center justify-center text-3xl mb-5">
                        <i class="fa-solid fa-folder-open"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">No categories available</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Categories will appear here when active
                        categories exist.</p>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-950 text-gray-400 text-xs border-t border-gray-900">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 py-16 grid grid-cols-1 md:grid-cols-5 gap-10">
            <div class="md:col-span-2 space-y-5">
                <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-white" href="index.php">
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
                    <li><a class="hover:text-white transition-colors duration-200" href="auctions.php">Browse
                            Auctions</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="auctions.php">Live Auctions</a>
                    </li>
                    <li><a class="hover:text-white transition-colors duration-200" href="categories.php">Categories</a>
                    </li>
                    <li><a class="hover:text-white transition-colors duration-200" href="index.php#auctions">Featured
                            Products</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="index.php#how-it-works">How It
                            Works</a></li>
                </ul>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Account</h4>
                <ul class="space-y-2.5">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li><a class="hover:text-white transition-colors duration-200"
                                href="<?php echo ($_SESSION['role'] ?? '') === 'seller' ? 'seller/dashboard.php' : 'user/dashboard.php'; ?>">My
                                Dashboard</a></li>
                        <li><a class="hover:text-white transition-colors duration-200"
                                href="notifications.php">Notifications</a></li>
                        <li><a class="hover:text-white transition-colors duration-200" href="auth/logout.php">Logout</a>
                        </li>
                    <?php else: ?>
                        <li><a class="hover:text-white transition-colors duration-200" href="auth/login.php">Login</a></li>
                        <li><a class="hover:text-white transition-colors duration-200" href="auth/register.php">Register</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Support</h4>
                <ul class="space-y-2.5">
                    <li><a class="hover:text-white transition-colors duration-200" href="about.php">Help Center</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="about.php#contact">Contact
                            Us</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="index.php#why-nexusbids">Safety
                            Center</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="about.php">Terms &amp;
                            Conditions</a></li>
                    <li><a class="hover:text-white transition-colors duration-200" href="about.php">Privacy Policy</a>
                    </li>
                </ul>
            </div>
        </div>
        <div
            class="border-t border-gray-900 py-6 text-center text-xs text-gray-500 max-w-7xl mx-auto px-4 flex flex-col sm:flex-row justify-between items-center gap-4">
            <p>© 2026 NexusBids. All rights reserved.</p>
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

            const mobileBtn = document.getElementById('mobileMenuBtn');
            const mobileMenu = document.getElementById('mobileMenu');
            const menuIcon = document.getElementById('menuIcon');
            if (mobileBtn && mobileMenu) {
                mobileBtn.addEventListener('click', function () {
                    const isOpen = !mobileMenu.classList.contains('hidden');
                    mobileMenu.classList.toggle('hidden');
                    mobileBtn.setAttribute('aria-expanded', !isOpen);
                    if (menuIcon) menuIcon.className = isOpen ? 'fa-solid fa-bars' : 'fa-solid fa-xmark';
                });
            }

            const search = document.getElementById('categorySearch');
            const clearSearchBtn = document.getElementById('clearSearch');
            const auctionFilter = document.getElementById('auctionFilter');
            const sortFilter = document.getElementById('sortFilter');
            const grid = document.getElementById('categoryGrid');
            const noResults = document.getElementById('noResults');
            const visibleCount = document.getElementById('visibleCount');
            const resetFiltersBtn = document.getElementById('resetFilters');

            if (!grid) return;

            const cards = Array.from(grid.querySelectorAll('.category-card'));

            function applyFilters() {
                const query = (search?.value || '').trim().toLowerCase();
                const status = auctionFilter?.value || 'all';
                const sorted = [...cards];

                // Toggle clear button
                if (clearSearchBtn) clearSearchBtn.classList.toggle('hidden', !query);

                sorted.sort(function (a, b) {
                    const nameA = a.dataset.name || '';
                    const nameB = b.dataset.name || '';
                    const countA = Number(a.dataset.count || 0);
                    const countB = Number(b.dataset.count || 0);

                    if (sortFilter?.value === 'count') return countB - countA;
                    if (sortFilter?.value === 'count-low') return countA - countB;
                    return nameA.localeCompare(nameB);
                });

                sorted.forEach(card => grid.appendChild(card));

                let shown = 0;
                cards.forEach(function (card) {
                    const name = card.dataset.name || '';
                    const count = Number(card.dataset.count || 0);
                    const matchesSearch = !query || name.includes(query);
                    const matchesStatus = status === 'all' || (status === 'active' ? count > 0 : count === 0);
                    const show = matchesSearch && matchesStatus;

                    card.classList.toggle('category-hidden', !show);
                    if (show) shown++;
                });

                if (visibleCount) visibleCount.textContent = shown;
                noResults?.classList.toggle('hidden', shown !== 0);
            }

            search?.addEventListener('input', applyFilters);
            auctionFilter?.addEventListener('change', applyFilters);
            sortFilter?.addEventListener('change', applyFilters);

            clearSearchBtn?.addEventListener('click', function () {
                if (search) {search.value = ''; search.focus();}
                applyFilters();
            });

            resetFiltersBtn?.addEventListener('click', function () {
                if (search) search.value = '';
                if (auctionFilter) auctionFilter.value = 'all';
                if (sortFilter) sortFilter.value = 'name';
                // Reset chips
                document.querySelectorAll('.filter-btn').forEach(function (btn) {
                    btn.classList.remove('bg-indigo-600', 'text-white', 'shadow-lg', 'shadow-indigo-600/30');
                    btn.classList.add('bg-slate-900/80', 'text-gray-300');
                });
                const allBtn = document.querySelector('.filter-btn[data-filter="all"]');
                allBtn?.classList.add('bg-indigo-600', 'text-white', 'shadow-lg', 'shadow-indigo-600/30');
                allBtn?.classList.remove('bg-slate-900/80', 'text-gray-300');
                applyFilters();
            });

            document.querySelectorAll('.filter-btn').forEach(function (button) {
                button.addEventListener('click', function () {
                    const filter = button.dataset.filter || 'all';
                    if (auctionFilter) auctionFilter.value = filter;
                    document.querySelectorAll('.filter-btn').forEach(function (btn) {
                        btn.classList.remove('bg-indigo-600', 'text-white', 'shadow-lg', 'shadow-indigo-600/30');
                        btn.classList.add('bg-slate-900/80', 'text-gray-300');
                    });
                    button.classList.add('bg-indigo-600', 'text-white', 'shadow-lg', 'shadow-indigo-600/30');
                    button.classList.remove('bg-slate-900/80', 'text-gray-300');
                    applyFilters();
                });
            });

            // Initial filter
            applyFilters();

            // Scroll animations
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.style.opacity = '1';
                            entry.target.style.transform = 'translateY(0)';
                            observer.unobserve(entry.target);
                        }
                    });
                }, {threshold: 0.05, rootMargin: '0px 0px -30px 0px'});

                cards.forEach((el, i) => {
                    el.style.opacity = '0';
                    el.style.transform = 'translateY(20px)';
                    el.style.transition = `opacity 0.5s ease-out ${Math.min(i * 0.04, 0.4)}s, transform 0.5s cubic-bezier(.4,0,.2,1) ${Math.min(i * 0.04, 0.4)}s`;
                    observer.observe(el);
                });
            }
        })();
    </script>
    <?php require_once __DIR__ . '/includes/chat-widget.php'; ?>
</body>

</html>