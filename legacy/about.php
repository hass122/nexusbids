<?php

session_start();

require_once "config/database.php";
require_once "includes/notifications.php";

$stats = [
    "bidders" => $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'user'")->fetch_assoc()["total"] ?? 0,
    "sellers" => $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'seller'")->fetch_assoc()["total"] ?? 0,
    "completed" => $conn->query("SELECT COUNT(*) AS total FROM auctions WHERE auction_status IN ('completed','ended')")->fetch_assoc()["total"] ?? 0,
    "categories" => $conn->query("SELECT COUNT(*) AS total FROM categories WHERE status = 'active'")->fetch_assoc()["total"] ?? 0,
];

$unread_notifications = isset($_SESSION["user_id"])
    ? nb_unread_count($conn, intval($_SESSION["user_id"]))
    : 0;

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About | NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <meta name="description"
        content="Learn about NexusBids — Pakistan's trusted online auction marketplace connecting buyers and sellers through fast, transparent, real-time auctions.">

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

        .timeline-step {
            position: relative;
        }

        .timeline-step::before {
            content: '';
            position: absolute;
            left: 27px;
            top: 60px;
            bottom: -20px;
            width: 2px;
            background: linear-gradient(to bottom, #e5e7eb, transparent);
        }

        .dark .timeline-step::before {
            background: linear-gradient(to bottom, #1f2937, transparent);
        }

        .timeline-step:last-child::before {
            display: none;
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
                <a href="about.php" class="hover:text-white transition-colors duration-200"><i
                        class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
                <a href="#why-nexusbids" class="hover:text-white transition-colors duration-200"><i
                        class="fa-solid fa-shield-halved text-indigo-400"></i> Secure Bidding</a>
            </div>
        </div>
    </div>

    <!-- Main Navbar -->
    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-gray-900 dark:text-white shrink-0 group"
                href="index.php">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30 group-hover:shadow-indigo-500/50 group-hover:scale-105 transition-all duration-300">
                    <i class="fa-solid fa-gavel text-lg"></i>
                </span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            </a>
            <nav class="hidden lg:flex items-center gap-8 text-sm font-medium">
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="index.php">Home</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="auctions.php">Auctions</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="categories.php">Categories</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="index.php#how-it-works">How It Works</a>
                <a class="relative text-indigo-600 dark:text-indigo-400 font-semibold after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-indigo-600 dark:after:bg-indigo-400 after:rounded-full"
                    href="about.php">About</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
                    href="#contact">Contact</a>
            </nav>
            <div class="flex items-center gap-2 sm:gap-3">
                <!-- Theme Toggle -->
                <button aria-label="Toggle Theme"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200 hover:scale-105"
                    id="themeToggle">
                    <i class="fa-solid fa-moon dark:hidden"></i><i
                        class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
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
                        <a class="text-sm font-medium text-gray-700 dark:text-gray-200 max-w-28 truncate hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                            href="<?php echo ($_SESSION["role"] ?? "") === "seller" ? "seller/dashboard.php" : "user/dashboard.php"; ?>">
                            <?php echo htmlspecialchars($_SESSION["full_name"] ?? "Dashboard"); ?>
                        </a>
                        <a href="auth/logout.php"
                            class="inline-flex px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30">
                            Logout
                        </a>
                    <?php else: ?>
                        <a href="auth/login.php"
                            class="text-sm font-semibold text-gray-700 dark:text-gray-200 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                            Login
                        </a>
                        <a href="auth/register.php"
                            class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30">
                            Register
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Hamburger -->
                <button id="mobileMenuBtn" aria-label="Toggle Menu" aria-expanded="false"
                    class="lg:hidden w-10 h-10 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200">
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
                    class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
                    <i class="fa-solid fa-layer-group w-5 text-center text-indigo-500"></i> Categories
                </a>
                <a href="index.php#how-it-works"
                    class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
                    <i class="fa-solid fa-circle-question w-5 text-center text-indigo-500"></i> How It Works
                </a>
                <a href="about.php"
                    class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/30 transition-all">
                    <i class="fa-solid fa-circle-info w-5 text-center"></i> About
                </a>
                <a href="#contact"
                    class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
                    <i class="fa-solid fa-envelope w-5 text-center text-indigo-500"></i> Contact
                </a>

                <!-- Divider -->
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
        <!-- Hero -->
        <section class="relative bg-slate-950 text-white overflow-hidden py-20 lg:py-28 noise-overlay">
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

            <div class="max-w-7xl mx-auto px-4 sm:px-8 text-center relative z-10">
                <span
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 text-xs font-semibold uppercase tracking-wider animate-fade-up">
                    <span class="relative flex h-2.5 w-2.5">
                        <span
                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-indigo-400"></span>
                    </span>
                    About NexusBids
                </span>
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight leading-[1.1] mt-6 animate-fade-up"
                    style="animation-delay: 0.1s">
                    Pakistan's Trusted <br class="hidden sm:block">
                    <span class="gradient-text bg-gradient-to-r from-blue-400 via-indigo-400 to-violet-400">Auction
                        Marketplace</span>
                </h1>
                <p class="text-gray-300 text-base sm:text-lg max-w-2xl mx-auto mt-6 leading-relaxed animate-fade-up"
                    style="animation-delay: 0.2s">
                    NexusBids connects buyers and sellers across Pakistan through fast, transparent, real-time online
                    auctions.
                </p>

                <div class="flex flex-wrap justify-center gap-3 mt-8 animate-fade-up" style="animation-delay: 0.3s">
                    <a href="auctions.php"
                        class="group px-7 py-3.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-semibold shadow-lg shadow-indigo-600/30 hover:shadow-indigo-500/40 transition-all duration-300 hover:-translate-y-0.5 flex items-center gap-2">
                        Browse Auctions
                        <i
                            class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform duration-300"></i>
                    </a>
                    <a href="#who-we-are"
                        class="px-7 py-3.5 rounded-xl bg-gray-800/80 hover:bg-gray-700/80 text-white font-semibold border border-gray-700 hover:border-gray-600 transition-all duration-300 hover:-translate-y-0.5 flex items-center gap-2">
                        Learn More
                        <i class="fa-solid fa-arrow-down text-xs text-indigo-400"></i>
                    </a>
                </div>
            </div>
        </section>

        <!-- Stats Strip -->
        <section class="bg-white dark:bg-slate-900 border-b border-gray-200 dark:border-gray-800 py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-8 grid grid-cols-2 md:grid-cols-4 gap-4">
                <div
                    class="group p-5 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800 hover:border-indigo-500 dark:hover:border-indigo-500 transition-all duration-300 hover:shadow-lg hover:shadow-indigo-500/10">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-12 h-12 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300">
                            <i class="fa-solid fa-users"></i>
                        </div>
                        <div>
                            <h4 class="text-2xl font-black tabular-nums">
                                <?php echo number_format($stats["bidders"]); ?>+
                            </h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">Registered Bidders</p>
                        </div>
                    </div>
                </div>
                <div
                    class="group p-5 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800 hover:border-emerald-500 dark:hover:border-emerald-500 transition-all duration-300 hover:shadow-lg hover:shadow-emerald-500/10">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300">
                            <i class="fa-solid fa-store"></i>
                        </div>
                        <div>
                            <h4 class="text-2xl font-black tabular-nums">
                                <?php echo number_format($stats["sellers"]); ?>+
                            </h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">Active Sellers</p>
                        </div>
                    </div>
                </div>
                <div
                    class="group p-5 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800 hover:border-blue-500 dark:hover:border-blue-500 transition-all duration-300 hover:shadow-lg hover:shadow-blue-500/10">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-12 h-12 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300">
                            <i class="fa-solid fa-trophy"></i>
                        </div>
                        <div>
                            <h4 class="text-2xl font-black tabular-nums">
                                <?php echo number_format($stats["completed"]); ?>+
                            </h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">Auctions Completed</p>
                        </div>
                    </div>
                </div>
                <div
                    class="group p-5 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800 hover:border-violet-500 dark:hover:border-violet-500 transition-all duration-300 hover:shadow-lg hover:shadow-violet-500/10">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-12 h-12 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300">
                            <i class="fa-solid fa-layer-group"></i>
                        </div>
                        <div>
                            <h4 class="text-2xl font-black tabular-nums">
                                <?php echo number_format($stats["categories"]); ?>
                            </h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">Categories</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Who We Are -->
        <section id="who-we-are" class="py-20 sm:py-24 max-w-7xl mx-auto px-4 sm:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-16 items-start">
                <div class="lg:col-span-7 space-y-6">
                    <span
                        class="text-indigo-600 dark:text-indigo-400 font-bold text-xs uppercase tracking-wider block">Who
                        We Are</span>
                    <h2 class="text-3xl sm:text-4xl font-black tracking-tight leading-tight">
                        Built for buyers and sellers across <span
                            class="gradient-text bg-gradient-to-r from-indigo-600 to-violet-600">Pakistan.</span>
                    </h2>
                    <p class="text-gray-600 dark:text-gray-400 leading-relaxed">
                        NexusBids is an online auction platform built for buyers and sellers across Pakistan. Whether
                        you're hunting for a great deal on electronics, vehicles, fashion, or collectibles — or looking
                        to sell items to a wide, engaged audience — NexusBids makes the process simple, secure, and
                        fair.
                    </p>
                    <p class="text-gray-600 dark:text-gray-400 leading-relaxed">
                        Every auction runs in real time, with live bid updates, automatic proxy bidding, and buyer
                        protections like seller ratings and reviews — so you always know who you're dealing with.
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-4">
                        <div
                            class="flex items-start gap-3 p-4 rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800">
                            <div
                                class="w-10 h-10 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm shrink-0">
                                <i class="fa-solid fa-bolt"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-sm text-gray-900 dark:text-white">Real-Time Bidding</h4>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Live updates as bids come in.
                                </p>
                            </div>
                        </div>
                        <div
                            class="flex items-start gap-3 p-4 rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800">
                            <div
                                class="w-10 h-10 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm shrink-0">
                                <i class="fa-solid fa-shield-halved"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-sm text-gray-900 dark:text-white">Secure &amp; Trusted</h4>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Protected transactions &amp;
                                    reviews.</p>
                            </div>
                        </div>
                        <div
                            class="flex items-start gap-3 p-4 rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800">
                            <div
                                class="w-10 h-10 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-sm shrink-0">
                                <i class="fa-solid fa-gavel"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-sm text-gray-900 dark:text-white">Automatic Bidding</h4>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Set a max bid, we'll do the
                                    rest.</p>
                            </div>
                        </div>
                        <div
                            class="flex items-start gap-3 p-4 rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800">
                            <div
                                class="w-10 h-10 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm shrink-0">
                                <i class="fa-solid fa-star"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-sm text-gray-900 dark:text-white">Verified Sellers</h4>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Ratings &amp; reviews to
                                    build trust.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-5">
                    <div class="relative">
                        <!-- Decorative cards -->
                        <div
                            class="absolute -top-4 -right-4 w-full h-full rounded-3xl bg-gradient-to-br from-indigo-500/20 to-violet-500/20 blur-2xl">
                        </div>
                        <div
                            class="relative rounded-3xl bg-slate-950 border border-gray-800 overflow-hidden shadow-2xl shadow-black/40 noise-overlay">
                            <div
                                class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-indigo-900/40 via-slate-950 to-slate-950">
                            </div>
                            <div class="relative p-6 sm:p-8 space-y-5">
                                <div class="flex items-center justify-between pb-4 border-b border-gray-800/80">
                                    <div class="flex items-center gap-2">
                                        <span class="w-3 h-3 rounded-full bg-rose-500"></span>
                                        <span class="w-3 h-3 rounded-full bg-amber-500"></span>
                                        <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                                    </div>
                                    <span
                                        class="text-xs text-emerald-400 font-semibold bg-emerald-950/80 px-3 py-1 rounded-full border border-emerald-800/50 flex items-center gap-1.5">
                                        <i class="fa-solid fa-circle text-[8px] animate-pulse"></i> Live
                                    </span>
                                </div>
                                <div class="space-y-3">
                                    <div class="p-4 rounded-xl bg-slate-900/80 border border-gray-800/80">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-3">
                                                <div
                                                    class="w-9 h-9 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold flex items-center justify-center text-xs">
                                                    AK</div>
                                                <div>
                                                    <p class="text-xs font-bold text-white">Ahmed Khan</p>
                                                    <p class="text-[10px] text-gray-400">placed a bid</p>
                                                </div>
                                            </div>
                                            <span class="text-xs font-black text-indigo-400">Rs. 45,000</span>
                                        </div>
                                    </div>
                                    <div class="p-4 rounded-xl bg-slate-900/80 border border-gray-800/80">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-3">
                                                <div
                                                    class="w-9 h-9 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-500 text-white font-bold flex items-center justify-center text-xs">
                                                    SF</div>
                                                <div>
                                                    <p class="text-xs font-bold text-white">Sara Fatima</p>
                                                    <p class="text-[10px] text-gray-400">won the auction</p>
                                                </div>
                                            </div>
                                            <span class="text-xs font-black text-emerald-400">Rs. 128,500</span>
                                        </div>
                                    </div>
                                    <div class="p-4 rounded-xl bg-slate-900/80 border border-gray-800/80">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-3">
                                                <div
                                                    class="w-9 h-9 rounded-lg bg-gradient-to-br from-blue-500 to-cyan-500 text-white font-bold flex items-center justify-center text-xs">
                                                    MR</div>
                                                <div>
                                                    <p class="text-xs font-bold text-white">Muhammad Raza</p>
                                                    <p class="text-[10px] text-gray-400">placed a bid</p>
                                                </div>
                                            </div>
                                            <span class="text-xs font-black text-indigo-400">Rs. 8,200</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="pt-4 border-t border-gray-800/80 flex items-center justify-between">
                                    <span class="text-xs text-gray-400">Trust score</span>
                                    <span class="flex items-center gap-1.5 text-xs font-bold text-emerald-400">
                                        <i class="fa-solid fa-shield-halved"></i> Verified
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- How It Works Timeline -->
        <section
            class="py-20 sm:py-24 bg-gray-100/60 dark:bg-slate-900/40 border-y border-gray-200 dark:border-gray-800">
            <div class="max-w-7xl mx-auto px-4 sm:px-8">
                <div class="text-center max-w-xl mx-auto mb-14">
                    <span
                        class="text-indigo-600 dark:text-indigo-400 font-bold text-xs uppercase tracking-wider block mb-2">Simple
                        Process</span>
                    <h2 class="text-3xl sm:text-4xl font-black tracking-tight">How It Works</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Start bidding or selling in five simple
                        steps.</p>
                </div>

                <div class="max-w-3xl mx-auto">
                    <?php
                    $steps = [
                        ['icon' => 'fa-user-plus', 'color' => 'indigo', 'title' => 'Create a Free Account', 'desc' => 'Sign up as a bidder or seller in seconds — no fees to join.'],
                        ['icon' => 'fa-list-check', 'color' => 'blue', 'title' => 'List or Discover Items', 'desc' => 'Sellers list items with a starting price and end time; admins review before it goes live.'],
                        ['icon' => 'fa-gavel', 'color' => 'violet', 'title' => 'Place Your Bids', 'desc' => 'Bid manually or set a maximum for automatic proxy bidding.'],
                        ['icon' => 'fa-trophy', 'color' => 'emerald', 'title' => 'Win & Complete Payment', 'desc' => 'When the timer ends, the highest bidder wins and completes payment with the seller.'],
                        ['icon' => 'fa-star', 'color' => 'amber', 'title' => 'Leave a Review', 'desc' => 'Both sides can leave a review to build trust for future auctions.'],
                    ];
                    $colorClasses = [
                        'indigo' => ['bg' => 'bg-indigo-500/10', 'text' => 'text-indigo-600 dark:text-indigo-400', 'ring' => 'group-hover:ring-indigo-500/20', 'num' => 'from-indigo-500 to-violet-500'],
                        'blue' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-600 dark:text-blue-400', 'ring' => 'group-hover:ring-blue-500/20', 'num' => 'from-blue-500 to-cyan-500'],
                        'violet' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-600 dark:text-violet-400', 'ring' => 'group-hover:ring-violet-500/20', 'num' => 'from-violet-500 to-purple-500'],
                        'emerald' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-600 dark:text-emerald-400', 'ring' => 'group-hover:ring-emerald-500/20', 'num' => 'from-emerald-500 to-teal-500'],
                        'amber' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-600 dark:text-amber-400', 'ring' => 'group-hover:ring-amber-500/20', 'num' => 'from-amber-500 to-orange-500'],
                    ];
                    foreach ($steps as $i => $step):
                        $c = $colorClasses[$step['color']];
                        ?>
                        <div class="timeline-step group flex items-start gap-5 pb-8">
                            <div class="relative shrink-0">
                                <div
                                    class="w-14 h-14 rounded-2xl <?php echo $c['bg']; ?> <?php echo $c['text']; ?> flex items-center justify-center text-xl ring-4 ring-transparent <?php echo $c['ring']; ?> group-hover:scale-110 group-hover:-rotate-3 transition-all duration-300">
                                    <i class="fa-solid <?php echo $step['icon']; ?>"></i>
                                </div>
                                <div
                                    class="absolute -top-1.5 -left-1.5 w-6 h-6 rounded-full bg-gradient-to-br <?php echo $c['num']; ?> text-white text-[10px] font-black flex items-center justify-center shadow-lg">
                                    <?php echo $i + 1; ?>
                                </div>
                            </div>
                            <div class="pt-1">
                                <h3
                                    class="font-bold text-gray-900 dark:text-white text-lg group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors duration-200">
                                    <?php echo $step['title']; ?>
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">
                                    <?php echo $step['desc']; ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- Why NexusBids -->
        <section id="why-nexusbids" class="py-20 sm:py-24 max-w-7xl mx-auto px-4 sm:px-8">
            <div class="text-center max-w-xl mx-auto mb-14">
                <span
                    class="text-indigo-600 dark:text-indigo-400 font-bold text-xs uppercase tracking-wider block mb-2">Why
                    NexusBids</span>
                <h2 class="text-3xl sm:text-4xl font-black tracking-tight">The NexusBids Advantage</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Everything you need for a smooth, secure
                    auction experience.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php
                $features = [
                    ['icon' => 'fa-shield-halved', 'color' => 'indigo', 'title' => 'Secure &amp; Trusted', 'desc' => 'Your account and transactions are protected with modern security standards.'],
                    ['icon' => 'fa-bolt', 'color' => 'blue', 'title' => 'Real-Time Bidding', 'desc' => 'See live bids and compete with other bidders instantly.'],
                    ['icon' => 'fa-circle-check', 'color' => 'emerald', 'title' => 'Verified Products', 'desc' => 'Quality listings and verified sellers for a safer marketplace.'],
                    ['icon' => 'fa-robot', 'color' => 'violet', 'title' => 'Proxy Bidding', 'desc' => 'Set your max bid and let our system bid on your behalf.'],
                    ['icon' => 'fa-star', 'color' => 'amber', 'title' => 'Seller Reviews', 'desc' => 'Ratings and reviews to help you make informed decisions.'],
                    ['icon' => 'fa-headset', 'color' => 'rose', 'title' => '24/7 Support', 'desc' => 'Dedicated support team ready to help whenever you need.'],
                ];
                $featureColors = [
                    'indigo' => ['bg' => 'bg-indigo-500/10', 'text' => 'text-indigo-600 dark:text-indigo-400', 'grad' => 'from-indigo-500 to-violet-500'],
                    'blue' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-600 dark:text-blue-400', 'grad' => 'from-blue-500 to-cyan-500'],
                    'emerald' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-600 dark:text-emerald-400', 'grad' => 'from-emerald-500 to-teal-500'],
                    'violet' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-600 dark:text-violet-400', 'grad' => 'from-violet-500 to-purple-500'],
                    'amber' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-600 dark:text-amber-400', 'grad' => 'from-amber-500 to-orange-500'],
                    'rose' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-600 dark:text-rose-400', 'grad' => 'from-rose-500 to-pink-500'],
                ];
                foreach ($features as $f):
                    $c = $featureColors[$f['color']];
                    ?>
                    <div
                        class="group p-7 rounded-2xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 dark:hover:border-indigo-500 hover:shadow-xl hover:shadow-indigo-500/10 hover:-translate-y-1.5 transition-all duration-300">
                        <div
                            class="w-14 h-14 rounded-2xl <?php echo $c['bg']; ?> <?php echo $c['text']; ?> flex items-center justify-center text-2xl mb-5 group-hover:scale-110 group-hover:-rotate-3 transition-all duration-300">
                            <i class="fa-solid <?php echo $f['icon']; ?>"></i>
                        </div>
                        <h3 class="font-bold text-gray-900 dark:text-white text-lg mb-2"><?php echo $f['title']; ?></h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed"><?php echo $f['desc']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Contact Section -->
        <section id="contact"
            class="py-20 sm:py-24 bg-gray-100/60 dark:bg-slate-900/40 border-y border-gray-200 dark:border-gray-800">
            <div class="max-w-7xl mx-auto px-4 sm:px-8 grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                <div class="lg:col-span-5 space-y-6">
                    <span
                        class="text-indigo-600 dark:text-indigo-400 font-bold text-xs uppercase tracking-wider block">Get
                        in Touch</span>
                    <h2 class="text-3xl sm:text-4xl font-black tracking-tight leading-tight">Have Questions? <span
                            class="gradient-text bg-gradient-to-r from-indigo-600 to-violet-600">We're Here to
                            Help.</span></h2>
                    <p class="text-gray-600 dark:text-gray-400 leading-relaxed">Reach out to our support team for any
                        questions about auctions, accounts, or payments. We typically respond within 24 hours.</p>

                    <div class="space-y-4 pt-2">
                        <div class="flex items-start gap-4 group">
                            <div
                                class="w-11 h-11 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-lg shrink-0 group-hover:scale-110 transition-transform duration-300">
                                <i class="fa-solid fa-envelope"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-900 dark:text-white text-sm">Email</h4>
                                <a href="mailto:support@nexusbids.pk"
                                    class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">support@nexusbids.pk</a>
                            </div>
                        </div>
                        <div class="flex items-start gap-4 group">
                            <div
                                class="w-11 h-11 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-lg shrink-0 group-hover:scale-110 transition-transform duration-300">
                                <i class="fa-solid fa-phone"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-900 dark:text-white text-sm">Phone</h4>
                                <a href="tel:+923001234567"
                                    class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">+92
                                    300 1234567</a>
                            </div>
                        </div>
                        <div class="flex items-start gap-4 group">
                            <div
                                class="w-11 h-11 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-lg shrink-0 group-hover:scale-110 transition-transform duration-300">
                                <i class="fa-solid fa-location-dot"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-900 dark:text-white text-sm">Location</h4>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Karachi, Pakistan</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-7">
                    <form
                        class="p-6 sm:p-8 rounded-3xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 shadow-lg shadow-gray-200/50 dark:shadow-black/20 space-y-4"
                        onsubmit="return false;">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Your
                                    Name</label>
                                <input type="text" placeholder="Ahmed Khan"
                                    class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-gray-900 dark:text-white transition-all duration-200 placeholder:text-gray-400">
                            </div>
                            <div>
                                <label
                                    class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Email</label>
                                <input type="email" placeholder="you@example.com"
                                    class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-gray-900 dark:text-white transition-all duration-200 placeholder:text-gray-400">
                            </div>
                        </div>
                        <div>
                            <label
                                class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Subject</label>
                            <input type="text" placeholder="How can we help?"
                                class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-gray-900 dark:text-white transition-all duration-200 placeholder:text-gray-400">
                        </div>
                        <div>
                            <label
                                class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Message</label>
                            <textarea rows="4" placeholder="Tell us more about your question..."
                                class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-gray-900 dark:text-white transition-all duration-200 placeholder:text-gray-400 resize-none"></textarea>
                        </div>
                        <button type="submit"
                            class="group w-full py-3.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-sm shadow-lg shadow-indigo-600/30 hover:shadow-indigo-500/40 transition-all duration-300 flex items-center justify-center gap-2">
                            Send Message
                            <i
                                class="fa-solid fa-paper-plane text-xs group-hover:translate-x-1 transition-transform duration-300"></i>
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="py-20 sm:py-24 max-w-7xl mx-auto px-4 sm:px-8">
            <div
                class="relative rounded-3xl bg-slate-950 text-white p-8 sm:p-14 overflow-hidden shadow-2xl border border-gray-800 noise-overlay">
                <div
                    class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-indigo-900/40 via-slate-950 to-slate-950">
                </div>
                <div
                    class="absolute right-0 top-1/2 -translate-y-1/2 translate-x-1/4 w-96 h-96 bg-indigo-600/20 rounded-full blur-3xl pointer-events-none">
                </div>
                <div
                    class="absolute left-0 bottom-0 -translate-x-1/4 translate-y-1/4 w-80 h-80 bg-violet-600/15 rounded-full blur-3xl pointer-events-none">
                </div>
                <div class="relative z-10 max-w-2xl space-y-6">
                    <span class="text-xs uppercase tracking-wider text-indigo-400 font-bold block">Ready to
                        Start?</span>
                    <h2 class="text-3xl sm:text-5xl font-black tracking-tight leading-tight">
                        Join the <span class="gradient-text bg-gradient-to-r from-indigo-400 to-blue-400">NexusBids
                            Community.</span>
                    </h2>
                    <p class="text-gray-300 text-sm sm:text-base leading-relaxed">Thousands of buyers and sellers trust
                        NexusBids every day. Start bidding or selling in minutes.</p>
                    <div class="flex flex-wrap gap-4 pt-2">
                        <a href="auctions.php"
                            class="group px-7 py-3.5 rounded-xl bg-white text-slate-950 hover:bg-gray-100 font-bold text-sm flex items-center gap-2 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-xl">
                            Browse Auctions
                            <i
                                class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform duration-300"></i>
                        </a>
                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <a href="auth/register.php"
                                class="px-7 py-3.5 rounded-xl bg-slate-900/80 hover:bg-slate-800 text-white font-bold text-sm border border-gray-800 hover:border-gray-700 transition-all duration-300 hover:-translate-y-0.5">Create
                                Account</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
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
                    <li><a class="hover:text-white transition-colors duration-200" href="about.php#why-nexusbids">Safety
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
            <p>© <?php echo date("Y"); ?> NexusBids. All rights reserved.</p>
            <p>Made for Pakistan <span class="text-rose-500"><i class="fa-solid fa-heart"></i></span></p>
        </div>
    </footer>

    <script>
        (function () {
            const root = document.documentElement, btn = document.getElementById('themeToggle');
            // Mobile Menu Toggle
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const mobileMenu = document.getElementById('mobileMenu');
            const menuIcon = document.getElementById('menuIcon');

            if (mobileMenuBtn && mobileMenu) {
                mobileMenuBtn.addEventListener('click', function () {
                    const isOpen = !mobileMenu.classList.contains('hidden');
                    mobileMenu.classList.toggle('hidden');
                    mobileMenuBtn.setAttribute('aria-expanded', !isOpen);
                    if (menuIcon) {
                        menuIcon.className = isOpen ? 'fa-solid fa-bars' : 'fa-solid fa-xmark';
                    }
                });
            }
            const saved = localStorage.getItem('theme');
            if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                root.classList.add('dark');
            }
            btn?.addEventListener('click', () => {
                root.classList.toggle('dark');
                localStorage.setItem('theme', root.classList.contains('dark') ? 'dark' : 'light');
            });

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

                document.querySelectorAll('section > div > div, .timeline-step, #why-nexusbids .grid > div').forEach((el, i) => {
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