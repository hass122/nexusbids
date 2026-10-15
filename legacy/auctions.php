<?php

session_start();

require_once "config/database.php";
require_once "includes/notifications.php";
$unread_notifications = isset($_SESSION["user_id"]) 
    ? nb_unread_count($conn, intval($_SESSION["user_id"])) 
    : 0;

/*
|--------------------------------------------------------------------------
| Get Categories
|--------------------------------------------------------------------------
*/

$categories = $conn->query("
    SELECT category_id, category_name
    FROM categories
    WHERE status = 'active'
    ORDER BY category_name ASC
");

/*
|--------------------------------------------------------------------------
| Search and Category Filter
|--------------------------------------------------------------------------
*/

$search = trim($_GET["search"] ?? "");
$category_id = intval($_GET["category"] ?? 0);

/*
|--------------------------------------------------------------------------
| Get Active Auctions
|--------------------------------------------------------------------------
*/

$where_sql = "
    WHERE a.auction_status = 'active'
      AND a.end_time > NOW()
";

if (!empty($search)) {
    $safe_search = $conn->real_escape_string($search);
    $where_sql .= "
        AND (
            a.title LIKE '%$safe_search%'
            OR a.description LIKE '%$safe_search%'
        )
    ";
}

if ($category_id > 0) {
    $where_sql .= "
        AND a.category_id = $category_id
    ";
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$count_sql = "
    SELECT COUNT(*) AS total
    FROM auctions a
    INNER JOIN categories c ON a.category_id = c.category_id
    INNER JOIN users u ON a.seller_id = u.user_id
    $where_sql
";

$count_result = $conn->query($count_sql);
$total_auctions = $count_result ? intval($count_result->fetch_assoc()["total"]) : 0;

$per_page = 12;
$page = max(1, intval($_GET["page"] ?? 1));
$total_pages = max(1, ceil($total_auctions / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = "
    SELECT
        a.auction_id, a.title, a.description, a.starting_price, a.current_bid,
        a.bid_increment, a.start_time, a.end_time, a.image, a.auction_status,
        c.category_id, c.category_name,
        u.user_id AS seller_id, u.full_name AS seller_name
    FROM auctions a
    INNER JOIN categories c ON a.category_id = c.category_id
    INNER JOIN users u ON a.seller_id = u.user_id
    $where_sql
    ORDER BY a.end_time ASC
    LIMIT $per_page OFFSET $offset
";

$auctions = $conn->query($sql);

// Pre-fetch bid counts in a single query (performance optimization)
$bid_counts = [];
$auction_ids = [];
if ($auctions && $auctions->num_rows > 0) {
    $auctions_data = $auctions->fetch_all(MYSQLI_ASSOC);
    foreach ($auctions_data as $a)
        $auction_ids[] = intval($a['auction_id']);
    if (!empty($auction_ids)) {
        $id_list = implode(',', $auction_ids);
        $bc_result = $conn->query("SELECT auction_id, COUNT(*) AS total FROM bids WHERE auction_id IN ($id_list) GROUP BY auction_id");
        if ($bc_result) {
            while ($row = $bc_result->fetch_assoc()) {
                $bid_counts[intval($row['auction_id'])] = intval($row['total']);
            }
        }
    }
} else {
    $auctions_data = [];
}

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Auctions | NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <meta name="description"
        content="Browse live auctions on NexusBids — bid on electronics, vehicles, fashion, jewelry, and collectibles.">
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

        .auction-card-image {
            height: 240px;
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

        /* Custom select arrow */
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

    <!-- Top utility bar -->
    <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50 relative z-40">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-6">
                <span class="flex items-center gap-2"><i class="fa-solid fa-location-dot text-indigo-400"></i>
                    Pakistan</span>
                <span class="hidden sm:flex items-center gap-2"><i class="fa-solid fa-gavel text-indigo-400"></i> Live
                    online marketplace</span>
            </div>
            <div class="flex items-center space-x-6">
                <a href="about.php" class="hover:text-white transition-colors duration-200"><i
                        class="fa-regular fa-circle-question text-indigo-400 mr-1"></i> Help Center</a>
                <span class="flex items-center gap-1.5"><i class="fa-solid fa-shield-halved text-indigo-400"></i> Secure
                    Bidding</span>
            </div>
        </div>
    </div>

    <!-- Main navbar -->
    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between">
            <a href="index.php"
                class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-gray-900 dark:text-white group">
                <div
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30 group-hover:shadow-indigo-500/50 group-hover:scale-105 transition-all duration-300">
                    <i class="fa-solid fa-gavel text-lg"></i>
                </div>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            </a>

            <nav class="hidden md:flex items-center space-x-8 text-sm font-medium">
                <a href="index.php"
                    class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200">Home</a>
                <a href="auctions.php"
                    class="relative text-indigo-600 dark:text-indigo-400 font-semibold after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-indigo-600 dark:after:bg-indigo-400 after:rounded-full">Auctions</a>
                <a href="categories.php"
                    class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200">Categories</a>
                <a href="about.php"
                    class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200">About</a>
            </nav>

            <div class="flex items-center gap-2 sm:gap-3">
                <button id="themeToggle" aria-label="Toggle Theme"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200 hover:scale-105">
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
                <div class="hidden lg:flex items-center gap-2 pl-3 border-l border-gray-200 dark:border-gray-800">
                    <?php if (isset($_SESSION["user_id"])): ?>
                        <a href="<?php echo ($_SESSION['role'] ?? '') === 'seller' ? 'seller/dashboard.php' : 'user/dashboard.php'; ?>"
                            class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-md transition">
                            <i class="fa-solid fa-user mr-1"></i> Dashboard
                        </a>
                    <?php else: ?>
                        <a href="auth/login.php"
                            class="px-3 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                            Login
                        </a>
                        <a href="auth/register.php"
                            class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-md transition">
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
                    class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/30 transition-all">
                    <i class="fa-solid fa-gavel w-5 text-center"></i> Auctions
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
                    class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
                    <i class="fa-solid fa-circle-info w-5 text-center text-indigo-500"></i> About
                </a>

                <div class="my-2 border-t border-gray-100 dark:border-gray-800"></div>

                <?php if (isset($_SESSION["user_id"])): ?>
                    <a href="<?php echo ($_SESSION['role'] ?? '') === 'seller' ? 'seller/dashboard.php' : 'user/dashboard.php'; ?>"
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

            <div class="max-w-7xl mx-auto px-4 sm:px-8 relative z-10">
                <div class="max-w-3xl">
                    <div
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold uppercase tracking-wider animate-fade-up">
                        <span class="relative flex h-2.5 w-2.5">
                            <span
                                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-400"></span>
                        </span>
                        Live Auctions Happening Now
                    </div>
                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight leading-[1.1] mt-6 animate-fade-up"
                        style="animation-delay: 0.1s">
                        Discover Amazing <span
                            class="gradient-text bg-gradient-to-r from-blue-400 via-indigo-400 to-violet-400">Live
                            Auctions</span>
                    </h1>
                    <p class="text-gray-300 text-base sm:text-lg max-w-2xl mt-6 leading-relaxed animate-fade-up"
                        style="animation-delay: 0.2s">
                        Bid on unique products, rare collectibles, electronics, vehicles and much more. Find an auction
                        and make your winning bid.
                    </p>
                    <div class="flex flex-wrap gap-3 mt-8 animate-fade-up" style="animation-delay: 0.3s">
                        <a href="#live-auctions"
                            class="group px-7 py-3.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-semibold shadow-lg shadow-indigo-600/30 hover:shadow-indigo-500/40 transition-all duration-300 hover:-translate-y-0.5 flex items-center gap-2">
                            Explore Auctions
                            <i
                                class="fa-solid fa-arrow-down text-xs group-hover:translate-y-1 transition-transform duration-300"></i>
                        </a>
                        <a href="categories.php"
                            class="px-7 py-3.5 rounded-xl bg-gray-800/80 hover:bg-gray-700/80 text-white font-semibold border border-gray-700 hover:border-gray-600 transition-all duration-300 hover:-translate-y-0.5">
                            Browse Categories
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Search/filter -->
        <section
            class="bg-white dark:bg-slate-900 border-b border-gray-200 dark:border-gray-800 shadow-sm md:sticky md:top-20 z-30 glass">
            <div class="max-w-7xl mx-auto px-4 sm:px-8 py-5">
                <form method="GET" action="auctions.php" class="grid grid-cols-1 md:grid-cols-12 gap-3">
                    <div class="relative md:col-span-6 group">
                        <i
                            class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-indigo-500 transition-colors"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="Search products, auctions or descriptions..."
                            class="w-full pl-11 pr-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-gray-900 dark:text-white transition-all duration-200 placeholder:text-gray-400">
                    </div>
                    <div class="relative md:col-span-3">
                        <i
                            class="fa-solid fa-layer-group absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                        <select name="category"
                            class="custom-select w-full pl-11 pr-10 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-gray-900 dark:text-white transition-all duration-200 cursor-pointer">
                            <option value="0">All Categories</option>
                            <?php if ($categories && $categories->num_rows > 0): ?>
                                <?php while ($category = $categories->fetch_assoc()): ?>
                                    <option value="<?php echo intval($category["category_id"]); ?>" <?php echo $category_id == $category["category_id"] ? "selected" : ""; ?>>
                                        <?php echo htmlspecialchars($category["category_name"]); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="md:col-span-3 flex gap-2">
                        <button type="submit"
                            class="flex-1 px-5 py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold rounded-xl shadow-md transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30 flex items-center justify-center gap-2">
                            <i class="fa-solid fa-filter"></i> Search
                        </button>
                        <?php if (!empty($search) || $category_id > 0): ?>
                            <a href="auctions.php"
                                class="px-5 py-3.5 bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-gray-700 dark:text-gray-200 text-sm font-semibold rounded-xl transition-all duration-200 flex items-center justify-center"
                                title="Clear filters">
                                <i class="fa-solid fa-xmark"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Active filters -->
                <?php if (!empty($search) || $category_id > 0): ?>
                    <div
                        class="flex flex-wrap items-center gap-2 mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 animate-fade-in">
                        <span class="text-xs text-gray-500 dark:text-gray-400 font-medium">Active filters:</span>
                        <?php if (!empty($search)): ?>
                            <span
                                class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-indigo-50 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-300 text-xs font-semibold border border-indigo-100 dark:border-indigo-900/50">
                                <i class="fa-solid fa-magnifying-glass text-[10px]"></i>
                                "<?php echo htmlspecialchars($search); ?>"
                            </span>
                        <?php endif; ?>
                        <?php if ($category_id > 0):
                            $cats_reset = $conn->query("SELECT category_id, category_name FROM categories WHERE status = 'active'");
                            $active_cat_name = '';
                            if ($cats_reset) {
                                while ($c = $cats_reset->fetch_assoc()) {
                                    if (intval($c['category_id']) === $category_id) {
                                        $active_cat_name = $c['category_name'];
                                        break;
                                    }
                                }
                            }
                            ?>
                            <span
                                class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-violet-50 dark:bg-violet-950/50 text-violet-700 dark:text-violet-300 text-xs font-semibold border border-violet-100 dark:border-violet-900/50">
                                <i class="fa-solid fa-layer-group text-[10px]"></i>
                                <?php echo htmlspecialchars($active_cat_name); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Stats -->
        <section class="bg-white dark:bg-slate-900 border-b border-gray-200 dark:border-gray-800 py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-8 grid grid-cols-2 md:grid-cols-4 gap-4">
                <div
                    class="group p-5 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800 hover:border-indigo-500 dark:hover:border-indigo-500 transition-all duration-300 hover:shadow-lg hover:shadow-indigo-500/10">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-12 h-12 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300">
                            <i class="fa-solid fa-gavel"></i>
                        </div>
                        <div>
                            <h4 class="text-2xl font-black tabular-nums"><?php echo intval($total_auctions); ?></h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">Matching Auctions</p>
                        </div>
                    </div>
                </div>
                <div
                    class="group p-5 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800 hover:border-emerald-500 dark:hover:border-emerald-500 transition-all duration-300 hover:shadow-lg hover:shadow-emerald-500/10">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div>
                            <h4 class="text-2xl font-black">LIVE</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">Bidding Open</p>
                        </div>
                    </div>
                </div>
                <div
                    class="group p-5 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800 hover:border-blue-500 dark:hover:border-blue-500 transition-all duration-300 hover:shadow-lg hover:shadow-blue-500/10">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-12 h-12 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300">
                            <i class="fa-solid fa-bolt"></i>
                        </div>
                        <div>
                            <h4 class="text-2xl font-black">24/7</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">Online Marketplace</p>
                        </div>
                    </div>
                </div>
                <div
                    class="group p-5 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800 hover:border-violet-500 dark:hover:border-violet-500 transition-all duration-300 hover:shadow-lg hover:shadow-violet-500/10">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-12 h-12 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div>
                            <h4 class="text-2xl font-black">Secure</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">Bidding Platform</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Auctions -->
        <section id="live-auctions" class="py-16 sm:py-20 max-w-7xl mx-auto px-4 sm:px-8">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-end gap-4 mb-10">
                <div>
                    <span
                        class="text-indigo-600 dark:text-indigo-400 font-bold text-xs uppercase tracking-wider block mb-2">
                        <i class="fa-solid fa-fire"></i> Trending Now
                    </span>
                    <h2 class="text-3xl sm:text-4xl font-black tracking-tight">
                        <?php echo (!empty($search) || $category_id > 0) ? 'Search Results' : 'Featured Live Auctions'; ?>
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                        <?php echo count($auctions_data); ?> auction(s) available for bidding.
                    </p>
                </div>
                <div
                    class="inline-flex items-center gap-2 px-3.5 py-2 rounded-full bg-rose-500/10 border border-rose-500/20 text-rose-600 dark:text-rose-400 text-xs font-bold w-fit">
                    <span class="relative flex h-2 w-2">
                        <span
                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-500 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-rose-500"></span>
                    </span>
                    LIVE NOW
                </div>
            </div>

            <?php if (!empty($auctions_data)): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($auctions_data as $auction): ?>
                        <?php
                        $current_bid = floatval($auction["current_bid"]);
                        $starting_price = floatval($auction["starting_price"]);
                        $display_price = $current_bid > 0 ? $current_bid : $starting_price;
                        $auction_id = intval($auction["auction_id"]);
                        $bid_count = $bid_counts[$auction_id] ?? 0;
                        $end_iso = date("c", strtotime($auction["end_time"]));
                        $next_bid = $display_price + floatval($auction["bid_increment"]);
                        ?>
                        <article
                            class="group bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 rounded-2xl overflow-hidden shadow-sm hover:shadow-2xl hover:shadow-indigo-500/10 hover:border-indigo-500 dark:hover:border-indigo-500 hover:-translate-y-1.5 transition-all duration-300 flex flex-col">
                            <div class="relative auction-card-image bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                <?php if (!empty($auction["image"])): ?>
                                    <img src="uploads/auctions/<?php echo htmlspecialchars($auction["image"]); ?>"
                                        alt="<?php echo htmlspecialchars($auction["title"]); ?>"
                                        class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700">
                                <?php else: ?>
                                    <div class="w-full h-full flex flex-col items-center justify-center text-gray-400">
                                        <i class="fa-solid fa-image text-4xl mb-2"></i>
                                        <span class="text-xs">No Image</span>
                                    </div>
                                <?php endif; ?>
                                <div
                                    class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                </div>

                                <div class="absolute top-3 left-3 right-3 flex justify-between items-start">
                                    <span
                                        class="px-3 py-1.5 rounded-full bg-white/95 dark:bg-slate-950/95 glass text-gray-800 dark:text-gray-100 text-xs font-bold shadow-lg">
                                        <?php echo htmlspecialchars($auction["category_name"]); ?>
                                    </span>
                                    <span
                                        class="px-3 py-1.5 rounded-full bg-rose-500 text-white text-xs font-bold shadow-lg flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span> LIVE
                                    </span>
                                </div>

                                <!-- Quick action overlay button -->
                                <div
                                    class="absolute bottom-3 right-3 opacity-0 group-hover:opacity-100 translate-y-2 group-hover:translate-y-0 transition-all duration-300">
                                    <a href="auction-details.php?id=<?php echo $auction_id; ?>"
                                        class="w-10 h-10 rounded-full bg-white dark:bg-slate-950 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shadow-lg hover:scale-110 transition-transform duration-200"
                                        title="View details">
                                        <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i>
                                    </a>
                                </div>
                            </div>

                            <div class="p-5 flex-1 flex flex-col">
                                <h3
                                    class="font-bold text-lg text-gray-900 dark:text-white line-clamp-2 min-h-[3.5rem] leading-snug group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors duration-200">
                                    <?php echo htmlspecialchars($auction["title"]); ?>
                                </h3>

                                <div class="flex items-center gap-2 mt-3 text-xs text-gray-500 dark:text-gray-400">
                                    <span
                                        class="w-7 h-7 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                                        <i class="fa-solid fa-user text-[10px]"></i>
                                    </span>
                                    <span class="truncate">Seller: <strong
                                            class="font-semibold text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($auction["seller_name"]); ?></strong></span>
                                </div>

                                <div
                                    class="flex justify-between items-end mt-5 pb-4 border-b border-gray-100 dark:border-gray-800">
                                    <div>
                                        <span class="text-xs text-gray-400 block mb-0.5">Current Bid</span>
                                        <strong class="text-2xl font-black text-gray-900 dark:text-white tabular-nums">Rs.
                                            <?php echo number_format($display_price); ?></strong>
                                    </div>
                                    <div class="text-right">
                                        <span
                                            class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-slate-800 px-2 py-1 rounded-lg">
                                            <i class="fa-solid fa-gavel text-indigo-500"></i> <?php echo $bid_count; ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between py-3 text-xs text-gray-500 dark:text-gray-400">
                                    <span><i class="fa-solid fa-arrow-up text-indigo-500 mr-1"></i> Next bid</span>
                                    <strong class="text-gray-800 dark:text-gray-200 tabular-nums">Rs.
                                        <?php echo number_format($next_bid); ?></strong>
                                </div>

                                <div class="countdown-box rounded-xl bg-gray-50 dark:bg-slate-800/70 border border-gray-200 dark:border-gray-700 p-3.5 mb-4"
                                    data-end-time="<?php echo htmlspecialchars($end_iso); ?>">
                                    <div class="flex items-center justify-between mb-2.5">
                                        <span
                                            class="text-[11px] uppercase tracking-wider font-bold text-gray-500 dark:text-gray-400">
                                            <i class="fa-regular fa-clock mr-1"></i> Time Remaining
                                        </span>
                                        <span
                                            class="countdown-status text-[10px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded">ACTIVE</span>
                                    </div>
                                    <div class="grid grid-cols-4 gap-1.5 sm:gap-2 text-center">
                                        <div>
                                            <span class="cd-days block text-lg font-black tabular-nums">00</span>
                                            <small class="text-[9px] uppercase text-gray-400 tracking-wider">Days</small>
                                        </div>
                                        <div>
                                            <span class="cd-hours block text-lg font-black tabular-nums">00</span>
                                            <small class="text-[9px] uppercase text-gray-400 tracking-wider">Hours</small>
                                        </div>
                                        <div>
                                            <span class="cd-minutes block text-lg font-black tabular-nums">00</span>
                                            <small class="text-[9px] uppercase text-gray-400 tracking-wider">Min</small>
                                        </div>
                                        <div>
                                            <span class="cd-seconds block text-lg font-black tabular-nums">00</span>
                                            <small class="text-[9px] uppercase text-gray-400 tracking-wider">Sec</small>
                                        </div>
                                    </div>
                                </div>

                                <a href="auction-details.php?id=<?php echo $auction_id; ?>"
                                    class="mt-auto group/btn w-full py-3.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-sm shadow-lg shadow-indigo-600/20 hover:shadow-indigo-500/40 transition-all duration-300 flex items-center justify-center gap-2">
                                    View Auction
                                    <i
                                        class="fa-solid fa-arrow-right text-xs group-hover/btn:translate-x-1 transition-transform duration-300"></i>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div
                    class="p-12 rounded-3xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 text-center shadow-sm">
                    <div
                        class="w-20 h-20 rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-3xl mx-auto mb-5">
                        <i class="fa-solid fa-gavel"></i>
                    </div>
                    <h3 class="text-xl font-black text-gray-900 dark:text-white">No Live Auctions Found</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 max-w-md mx-auto">
                        <?php if (!empty($search) || $category_id > 0): ?>
                            We couldn't find any auctions matching your current filters. Try adjusting your search or browse all
                            auctions.
                        <?php else: ?>
                            There are currently no active auctions. Check back soon for new listings.
                        <?php endif; ?>
                    </p>
                    <div class="flex flex-wrap gap-3 justify-center mt-6">
                        <a href="auctions.php"
                            class="px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-semibold text-sm transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30">
                            <i class="fa-solid fa-rotate-right mr-1"></i> View All Auctions
                        </a>
                        <a href="categories.php"
                            class="px-6 py-3 rounded-xl bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-gray-700 dark:text-gray-200 font-semibold text-sm transition-all duration-200">
                            Browse Categories
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($total_pages > 1): ?>
                <nav class="flex flex-wrap justify-center items-center gap-2 mt-12" aria-label="Pagination">
                    <?php
                    $query_params = $_GET;
                    // Previous
                    if ($page > 1) {
                        $query_params["page"] = $page - 1;
                        echo '<a href="auctions.php?' . htmlspecialchars(http_build_query($query_params)) . '" class="w-10 h-10 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-gray-200 hover:bg-indigo-100 dark:hover:bg-slate-700 transition-all duration-200"><i class="fa-solid fa-chevron-left text-xs"></i></a>';
                    }

                    // Smart page numbers
                    $window = 2;
                    $start = max(1, $page - $window);
                    $end = min($total_pages, $page + $window);

                    if ($start > 1) {
                        $query_params["page"] = 1;
                        echo '<a href="auctions.php?' . htmlspecialchars(http_build_query($query_params)) . '" class="w-10 h-10 rounded-xl flex items-center justify-center text-sm font-bold bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-gray-200 hover:bg-indigo-100 dark:hover:bg-slate-700 transition-all duration-200">1</a>';
                        if ($start > 2)
                            echo '<span class="px-2 text-gray-400">…</span>';
                    }

                    for ($p = $start; $p <= $end; $p++):
                        $query_params["page"] = $p;
                        $page_url = "auctions.php?" . http_build_query($query_params);
                        $is_active = $p === $page;
                        ?>
                        <a href="<?php echo htmlspecialchars($page_url); ?>"
                            class="w-10 h-10 rounded-xl flex items-center justify-center text-sm font-bold transition-all duration-200 <?php echo $is_active ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30 scale-105' : 'bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-gray-200 hover:bg-indigo-100 dark:hover:bg-slate-700'; ?>"
                            <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                            <?php echo $p; ?>
                        </a>
                    <?php endfor;

                    if ($end < $total_pages) {
                        if ($end < $total_pages - 1)
                            echo '<span class="px-2 text-gray-400">…</span>';
                        $query_params["page"] = $total_pages;
                        echo '<a href="auctions.php?' . htmlspecialchars(http_build_query($query_params)) . '" class="w-10 h-10 rounded-xl flex items-center justify-center text-sm font-bold bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-gray-200 hover:bg-indigo-100 dark:hover:bg-slate-700 transition-all duration-200">' . $total_pages . '</a>';
                    }

                    // Next
                    if ($page < $total_pages) {
                        $query_params["page"] = $page + 1;
                        echo '<a href="auctions.php?' . htmlspecialchars(http_build_query($query_params)) . '" class="w-10 h-10 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-gray-200 hover:bg-indigo-100 dark:hover:bg-slate-700 transition-all duration-200"><i class="fa-solid fa-chevron-right text-xs"></i></a>';
                    }
                    ?>
                </nav>
                <p class="text-center text-xs text-gray-500 dark:text-gray-400 mt-4">
                    Page <?php echo $page; ?> of <?php echo $total_pages; ?> &middot; Showing
                    <?php echo count($auctions_data); ?> of <?php echo $total_auctions; ?> auctions
                </p>
            <?php endif; ?>
        </section>

        <!-- How it works -->
        <section class="py-20 bg-gray-100/60 dark:bg-slate-900/40 border-y border-gray-200 dark:border-gray-800">
            <div class="max-w-7xl mx-auto px-4 sm:px-8">
                <div class="text-center max-w-xl mx-auto mb-14">
                    <span
                        class="text-indigo-600 dark:text-indigo-400 font-bold text-xs uppercase tracking-wider block mb-2">Simple
                        Process</span>
                    <h2 class="text-3xl sm:text-4xl font-black mt-1 tracking-tight">How NexusBids Works</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Start bidding in three simple steps.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div
                        class="group p-8 rounded-2xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 text-center shadow-sm hover:shadow-xl hover:shadow-indigo-500/10 hover:-translate-y-1.5 transition-all duration-300">
                        <div
                            class="w-16 h-16 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-2xl mx-auto mb-6 group-hover:scale-110 group-hover:-rotate-3 transition-all duration-300">
                            <i class="fa-solid fa-user-plus"></i>
                        </div>
                        <h3 class="font-bold text-lg">Create Your Account</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2 leading-relaxed">Register for free and
                            create your secure NexusBids account in seconds.</p>
                    </div>
                    <div
                        class="group p-8 rounded-2xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 text-center shadow-sm hover:shadow-xl hover:shadow-indigo-500/10 hover:-translate-y-1.5 transition-all duration-300">
                        <div
                            class="w-16 h-16 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-2xl mx-auto mb-6 group-hover:scale-110 group-hover:-rotate-3 transition-all duration-300">
                            <i class="fa-solid fa-gavel"></i>
                        </div>
                        <h3 class="font-bold text-lg">Find &amp; Bid</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2 leading-relaxed">Explore live auctions
                            and place your competitive bid to win.</p>
                    </div>
                    <div
                        class="group p-8 rounded-2xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 text-center shadow-sm hover:shadow-xl hover:shadow-indigo-500/10 hover:-translate-y-1.5 transition-all duration-300">
                        <div
                            class="w-16 h-16 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-2xl mx-auto mb-6 group-hover:scale-110 group-hover:-rotate-3 transition-all duration-300">
                            <i class="fa-solid fa-trophy"></i>
                        </div>
                        <h3 class="font-bold text-lg">Win &amp; Receive</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2 leading-relaxed">Win the auction,
                            complete payment and receive your product.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="py-24 max-w-7xl mx-auto px-4 sm:px-8">
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
                    <span class="text-xs uppercase tracking-wider text-indigo-400 font-bold block">Start Today</span>
                    <h2 class="text-3xl sm:text-5xl font-black tracking-tight leading-tight">Ready to Make <span
                            class="gradient-text bg-gradient-to-r from-indigo-400 to-blue-400">Your First Bid?</span>
                    </h2>
                    <p class="text-gray-300 text-sm sm:text-base leading-relaxed">Join NexusBids and discover amazing
                        deals from sellers across Pakistan.</p>
                    <div class="flex flex-wrap gap-4 pt-2">
                        <?php if (isset($_SESSION["user_id"])): ?>
                            <a href="<?php echo ($_SESSION['role'] ?? '') === 'seller' ? 'seller/dashboard.php' : 'user/dashboard.php'; ?>"
                                class="group px-7 py-3.5 rounded-xl bg-white text-slate-950 hover:bg-gray-100 font-bold text-sm flex items-center gap-2 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-xl">
                                Go to Dashboard
                                <i
                                    class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform duration-300"></i>
                            </a>
                        <?php else: ?>
                            <a href="auth/register.php"
                                class="group px-7 py-3.5 rounded-xl bg-white text-slate-950 hover:bg-gray-100 font-bold text-sm flex items-center gap-2 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-xl">
                                Create Account
                                <i
                                    class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform duration-300"></i>
                            </a>
                        <?php endif; ?>
                        <a href="categories.php"
                            class="px-7 py-3.5 rounded-xl bg-slate-900/80 hover:bg-slate-800 text-white font-bold text-sm border border-gray-800 hover:border-gray-700 transition-all duration-300 hover:-translate-y-0.5">Browse
                            Categories</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

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
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                html.classList.add('dark');
            }

            const themeBtn = document.getElementById('themeToggle');
            if (themeBtn) {
                themeBtn.addEventListener('click', function () {
                    html.classList.toggle('dark');
                    localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
                });
            }

            const menuBtn = document.getElementById('mobileMenuBtn');
            const menu = document.getElementById('mobileMenu');
            const menuIcon = document.getElementById('menuIcon');
            if (menuBtn && menu) {
                menuBtn.addEventListener('click', function () {
                    const isOpen = !menu.classList.contains('hidden');
                    menu.classList.toggle('hidden');
                    menuBtn.setAttribute('aria-expanded', !isOpen);
                    if (menuIcon) menuIcon.className = isOpen ? 'fa-solid fa-bars' : 'fa-solid fa-xmark';
                });
            }

            // Countdown with animation on finish
            function updateCountdowns() {
                document.querySelectorAll('.countdown-box').forEach(function (box) {
                    const end = new Date(box.dataset.endTime).getTime();
                    const diff = end - Date.now();
                    const status = box.querySelector('.countdown-status');

                    if (diff <= 0) {
                        box.querySelector('.cd-days').textContent = '00';
                        box.querySelector('.cd-hours').textContent = '00';
                        box.querySelector('.cd-minutes').textContent = '00';
                        box.querySelector('.cd-seconds').textContent = '00';
                        if (status) {
                            status.textContent = 'ENDED';
                            status.className = 'countdown-status text-[10px] font-bold text-rose-600 dark:text-rose-400 bg-rose-500/10 px-2 py-0.5 rounded';
                        }
                        return;
                    }
                    const days = Math.floor(diff / 86400000);
                    const hours = Math.floor((diff % 86400000) / 3600000);
                    const mins = Math.floor((diff % 3600000) / 60000);
                    const secs = Math.floor((diff % 60000) / 1000);
                    box.querySelector('.cd-days').textContent = String(days).padStart(2, '0');
                    box.querySelector('.cd-hours').textContent = String(hours).padStart(2, '0');
                    box.querySelector('.cd-minutes').textContent = String(mins).padStart(2, '0');
                    box.querySelector('.cd-seconds').textContent = String(secs).padStart(2, '0');

                    // Urgency indicator
                    if (status && diff < 3600000) {
                        status.textContent = 'ENDING SOON';
                        status.className = 'countdown-status text-[10px] font-bold text-amber-600 dark:text-amber-400 bg-amber-500/10 px-2 py-0.5 rounded animate-pulse';
                    }
                });
            }
            updateCountdowns();
            setInterval(updateCountdowns, 1000);

            // Check ended auctions
            function checkEndedAuctions() {
                fetch('process-runner.php')
                    .then(r => r.json())
                    .then(data => console.log('Auction check executed:', data.timestamp))
                    .catch(err => console.error('Auction check error:', err));
            }
            checkEndedAuctions();
            setInterval(checkEndedAuctions, 5000);

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

                document.querySelectorAll('#live-auctions article, #live-auctions > div > div').forEach((el, i) => {
                    el.style.opacity = '0';
                    el.style.transform = 'translateY(20px)';
                    el.style.transition = `opacity 0.5s ease-out ${i * 0.05}s, transform 0.5s ease-out ${i * 0.05}s`;
                    observer.observe(el);
                });
            }
        })();
    </script>
    <?php require_once __DIR__ . '/includes/chat-widget.php'; ?>
</body>

</html>