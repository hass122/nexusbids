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

/* Fetch categories with auction counts */
$categories_result = $conn->query("
    SELECT
        c.*,
        COUNT(a.auction_id) AS auction_count,
        SUM(CASE WHEN a.auction_status = 'active' THEN 1 ELSE 0 END) AS active_auctions
    FROM categories c
    LEFT JOIN auctions a ON a.category_id = c.category_id
    GROUP BY c.category_id
    ORDER BY c.category_id DESC
");
$categories_data = $categories_result ? $categories_result->fetch_all(MYSQLI_ASSOC) : [];

/* Aggregate counts */
$total_categories = 0;
$active_count = 0;
$inactive_count = 0;
$local_auctions_sum = 0;
$with_auctions = 0;
$empty_count = 0;
foreach ($categories_data as $c) {
    $total_categories++;
    $status = strtolower($c['status'] ?? 'active');
    if ($status === 'active')
        $active_count++;
    else
        $inactive_count++;
    $ac = intval($c['auction_count'] ?? 0);
    $local_auctions_sum += $ac;
    if ($ac > 0)
        $with_auctions++;
    else
        $empty_count++;
}

/* Sidebar counts */
if (!function_exists('safe_count')) {
    function safe_count($conn, $sql)
    {
        try {
            $result = $conn->query($sql);
            return $result ? intval($result->fetch_assoc()["total"] ?? 0) : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

$total_users = safe_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role != 'admin'");
$sidebar_auctions = safe_count($conn, "SELECT COUNT(*) AS total FROM auctions");
$total_bids = safe_count($conn, "SELECT COUNT(*) AS total FROM bids");
$total_winners = safe_count($conn, "SELECT COUNT(*) AS total FROM winners");
$total_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments");
$pending_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments WHERE payment_status NOT IN ('paid','refunded')");

/* Category icon/color fallback */
$icon_map = [
    "electronics" => ["icon" => "fa-mobile-screen-button", "color" => "blue"],
    "vehicles" => ["icon" => "fa-car", "color" => "green"],
    "fashion" => ["icon" => "fa-shirt", "color" => "orange"],
    "jewelry" => ["icon" => "fa-gem", "color" => "purple"],
    "home & living" => ["icon" => "fa-house", "color" => "red"],
    "collectibles" => ["icon" => "fa-palette", "color" => "cyan"],
];

$color_classes = [
    "blue" => ["bg" => "bg-blue-500/10", "text" => "text-blue-600 dark:text-blue-400", "grad" => "from-blue-500 to-cyan-500"],
    "green" => ["bg" => "bg-emerald-500/10", "text" => "text-emerald-600 dark:text-emerald-400", "grad" => "from-emerald-500 to-teal-500"],
    "orange" => ["bg" => "bg-orange-500/10", "text" => "text-orange-600 dark:text-orange-400", "grad" => "from-orange-500 to-amber-500"],
    "purple" => ["bg" => "bg-violet-500/10", "text" => "text-violet-600 dark:text-violet-400", "grad" => "from-violet-500 to-purple-500"],
    "red" => ["bg" => "bg-rose-500/10", "text" => "text-rose-600 dark:text-rose-400", "grad" => "from-rose-500 to-pink-500"],
    "cyan" => ["bg" => "bg-cyan-500/10", "text" => "text-cyan-600 dark:text-cyan-400", "grad" => "from-cyan-500 to-blue-500"],
    "indigo" => ["bg" => "bg-indigo-500/10", "text" => "text-indigo-600 dark:text-indigo-400", "grad" => "from-indigo-500 to-violet-500"],
];

function get_category_style($cat, $icon_map, $color_classes)
{
    $name = strtolower(trim($cat['category_name'] ?? ''));
    $stored_icon = trim((string) ($cat['icon'] ?? ''));
    $stored_color = strtolower(trim((string) ($cat['color'] ?? '')));

    $icon = 'fa-box-open';
    if ($stored_icon !== '') {
        $icon = (strpos($stored_icon, 'fa-') === 0) ? $stored_icon : 'fa-' . $stored_icon;
    } elseif (isset($icon_map[$name])) {
        $icon = $icon_map[$name]['icon'];
    }

    $color = 'indigo';
    if ($stored_color !== '' && isset($color_classes[$stored_color])) {
        $color = $stored_color;
    } elseif (isset($icon_map[$name]['color'])) {
        $color = $icon_map[$name]['color'];
    }

    return [
        'icon' => $icon,
        'color' => $color,
        'bg' => $color_classes[$color]['bg'] ?? $color_classes['indigo']['bg'],
        'text' => $color_classes[$color]['text'] ?? $color_classes['indigo']['text'],
        'grad' => $color_classes[$color]['grad'] ?? $color_classes['indigo']['grad'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories | NexusBids Admin</title>
    <meta name="description" content="Manage NexusBids auction categories.">

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
                            <h1 class="text-lg sm:text-xl font-black truncate">Categories</h1>
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
                                <span class="text-gray-900 dark:text-white font-semibold">Categories</span>
                            </nav>
                            <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                                <span
                                    class="w-11 h-11 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white shadow-lg">
                                    <i class="fa-solid fa-layer-group text-base"></i>
                                </span>
                                Category Management
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Organize auction listings across
                                categories.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="add-category.php"
                                class="group px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold transition flex items-center gap-2">
                                <i class="fa-solid fa-plus"></i>
                                Add Category
                                <i
                                    class="fa-solid fa-arrow-right text-xs group-hover:translate-x-0.5 transition-transform"></i>
                            </a>
                        </div>
                    </div>
                </section>

                <!-- Stat Cards -->
                <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 animate-fade-up">
                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 hover:shadow-lg hover:shadow-indigo-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Total</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-layer-group"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($total_categories); ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">All categories</p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-emerald-500 hover:shadow-lg hover:shadow-emerald-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Active</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-circle-check"></i></span>
                        </div>
                        <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                            <?php echo number_format($active_count); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Visible to users
                        </p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-blue-500 hover:shadow-lg hover:shadow-blue-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">With
                                Auctions</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-box"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($with_auctions); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1"><?php echo number_format($local_auctions_sum); ?>
                            total listings</p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-amber-500 hover:shadow-lg hover:shadow-amber-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Empty</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-folder-open"></i></span>
                        </div>
                        <div
                            class="text-2xl font-black <?php echo $empty_count > 0 ? 'text-amber-600 dark:text-amber-400' : ''; ?> tabular-nums">
                            <?php echo number_format($empty_count); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">
                            <?php echo $empty_count > 0 ? 'No listings yet' : 'All populated'; ?></p>
                    </div>
                </section>

                <!-- Categories Table -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden animate-fade-up">

                    <div
                        class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row justify-between items-center gap-4">
                        <div class="relative w-full sm:w-80">
                            <i
                                class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input id="categorySearch" type="text" autocomplete="off"
                                class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                placeholder="Search categories...">
                        </div>
                        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                            <select id="statusFilter"
                                class="custom-select flex-1 sm:flex-initial py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                                <option value="all">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            <select id="populatedFilter"
                                class="custom-select flex-1 sm:flex-initial py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                                <option value="all">All Categories</option>
                                <option value="with">With Auctions</option>
                                <option value="empty">Empty Only</option>
                            </select>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" id="categoriesTable">
                            <thead
                                class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                <tr>
                                    <th
                                        class="px-4 py-3 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider w-16">
                                        ID</th>
                                    <th
                                        class="px-4 py-3 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Category</th>
                                    <th
                                        class="px-4 py-3 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Description</th>
                                    <th
                                        class="px-4 py-3 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider whitespace-nowrap">
                                        Auctions</th>
                                    <th
                                        class="px-4 py-3 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider whitespace-nowrap">
                                        Status</th>
                                    <th
                                        class="px-4 py-3 text-right text-[10px] font-bold text-gray-500 uppercase tracking-wider whitespace-nowrap">
                                        Actions</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <?php if (!empty($categories_data)): ?>
                                    <?php foreach ($categories_data as $category):
                                        $cat_style = get_category_style($category, $icon_map, $color_classes);
                                        $status = strtolower($category['status'] ?? 'active');
                                        $auction_count = intval($category['auction_count'] ?? 0);
                                        $active_auctions = intval($category['active_auctions'] ?? 0);
                                        ?>
                                        <tr class="data-row"
                                            data-name="<?php echo htmlspecialchars(strtolower($category['category_name'])); ?>"
                                            data-description="<?php echo htmlspecialchars(strtolower($category['description'] ?? '')); ?>"
                                            data-status="<?php echo $status; ?>"
                                            data-populated="<?php echo $auction_count > 0 ? 'with' : 'empty'; ?>">

                                            <!-- ID -->
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <span class="text-xs font-mono font-bold text-gray-400 dark:text-gray-500">
                                                    #<?php echo str_pad((int) $category['category_id'], 3, '0', STR_PAD_LEFT); ?>
                                                </span>
                                            </td>

                                            <!-- Category -->
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-3">
                                                    <div
                                                        class="w-10 h-10 rounded-xl <?php echo $cat_style['bg'] . ' ' . $cat_style['text']; ?> flex items-center justify-center text-base shrink-0">
                                                        <i
                                                            class="fa-solid <?php echo htmlspecialchars($cat_style['icon']); ?>"></i>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="font-bold truncate">
                                                            <?php echo htmlspecialchars($category['category_name']); ?></div>
                                                        <div
                                                            class="text-[10px] text-gray-400 dark:text-gray-500 font-mono uppercase tracking-wider">
                                                            <?php echo htmlspecialchars($cat_style['color']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Description -->
                                            <td class="px-4 py-3">
                                                <p
                                                    class="text-xs text-gray-600 dark:text-gray-400 line-clamp-2 max-w-xs leading-relaxed">
                                                    <?php echo htmlspecialchars($category['description'] ?? 'No description provided.'); ?>
                                                </p>
                                            </td>

                                            <!-- Auctions -->
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <?php if ($auction_count > 0): ?>
                                                    <a href="auctions.php?category=<?php echo (int) $category['category_id']; ?>"
                                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 hover:bg-indigo-500 hover:text-white text-[11px] font-bold transition">
                                                        <i class="fa-solid fa-box text-[9px]"></i>
                                                        <?php echo number_format($auction_count); ?>
                                                        <?php if ($active_auctions > 0): ?>
                                                            <span class="text-[9px] opacity-80">(<?php echo $active_auctions; ?>
                                                                live)</span>
                                                        <?php endif; ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span
                                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-gray-400 text-[11px] font-bold">
                                                        <i class="fa-solid fa-inbox text-[9px]"></i> Empty
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Status -->
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <?php if ($status === 'active'): ?>
                                                    <span
                                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20 text-[10px] font-bold uppercase tracking-wider">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                                                    </span>
                                                <?php else: ?>
                                                    <span
                                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-gray-500/10 text-gray-700 dark:text-gray-400 border border-gray-500/20 text-[10px] font-bold uppercase tracking-wider">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-500"></span> Inactive
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Actions -->
                                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                                <div class="inline-flex items-center gap-1.5">
                                                    <a href="edit-category.php?id=<?php echo (int) $category['category_id']; ?>"
                                                        title="Edit Category"
                                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 border border-indigo-500/20 hover:bg-indigo-600 hover:text-white hover:border-indigo-600 transition">
                                                        <i class="fa-solid fa-pen text-[11px]"></i>
                                                    </a>
                                                    <a href="delete-category.php?id=<?php echo (int) $category['category_id']; ?>"
                                                        title="Delete Category"
                                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-rose-500/10 text-rose-700 dark:text-rose-400 border border-rose-500/20 hover:bg-rose-500 hover:text-white hover:border-rose-500 transition">
                                                        <i class="fa-solid fa-trash text-[11px]"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-20 text-center">
                                            <div
                                                class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                                <i class="fa-solid fa-layer-group"></i>
                                            </div>
                                            <h3 class="text-xl font-black">No categories yet</h3>
                                            <p class="text-sm text-gray-500 mt-2 max-w-md mx-auto">Create your first
                                                category to start organizing auctions.</p>
                                            <a href="add-category.php"
                                                class="inline-flex mt-6 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition items-center gap-2">
                                                <i class="fa-solid fa-plus"></i> Add Category
                                            </a>
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
                        <h3 class="font-bold">No matching categories</h3>
                        <p class="text-sm text-gray-500 mt-1">Try adjusting your search or filters.</p>
                        <button type="button" id="resetFilters"
                            class="mt-5 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition">
                            <i class="fa-solid fa-rotate-right"></i> Reset Filters
                        </button>
                    </div>

                    <div
                        class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500">
                        <span>Showing <strong id="visibleCount"
                                class="text-gray-900 dark:text-white tabular-nums"><?php echo $total_categories; ?></strong>
                            of <strong
                                class="text-gray-900 dark:text-white tabular-nums"><?php echo $total_categories; ?></strong>
                            categories</span>
                        <div class="flex items-center gap-4 flex-wrap">
                            <span class="flex items-center gap-1.5"><span
                                    class="w-2 h-2 rounded-full bg-emerald-500"></span> Active</span>
                            <span class="flex items-center gap-1.5"><span
                                    class="w-2 h-2 rounded-full bg-gray-400"></span> Inactive</span>
                            <span class="flex items-center gap-1.5"><span
                                    class="w-2 h-2 rounded-full bg-amber-500"></span> Empty</span>
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

            const search = document.getElementById('categorySearch');
            const statusFilter = document.getElementById('statusFilter');
            const populatedFilter = document.getElementById('populatedFilter');
            const noResults = document.getElementById('noFilterResults');
            const resetBtn = document.getElementById('resetFilters');
            const rows = Array.from(document.querySelectorAll('.data-row'));
            const visibleCount = document.getElementById('visibleCount');

            function applyFilters() {
                const q = (search?.value || '').trim().toLowerCase();
                const status = statusFilter?.value || 'all';
                const populated = populatedFilter?.value || 'all';

                let shown = 0;
                rows.forEach(row => {
                    const name = row.dataset.name || '';
                    const description = row.dataset.description || '';
                    const rowStatus = row.dataset.status || '';
                    const rowPopulated = row.dataset.populated || '';

                    const matchesSearch = !q || name.includes(q) || description.includes(q);
                    const matchesStatus = status === 'all' || rowStatus === status;
                    const matchesPopulated = populated === 'all' || rowPopulated === populated;

                    const show = matchesSearch && matchesStatus && matchesPopulated;
                    row.style.display = show ? '' : 'none';
                    if (show) shown++;
                });

                if (visibleCount) visibleCount.textContent = shown;
                if (noResults) noResults.classList.toggle('hidden', !(shown === 0 && rows.length > 0));
            }

            search?.addEventListener('input', applyFilters);
            statusFilter?.addEventListener('change', applyFilters);
            populatedFilter?.addEventListener('change', applyFilters);
            resetBtn?.addEventListener('click', () => {
                if (search) search.value = '';
                if (statusFilter) statusFilter.value = 'all';
                if (populatedFilter) populatedFilter.value = 'all';
                applyFilters();
            });
        })();
    </script>
</body>

</html>