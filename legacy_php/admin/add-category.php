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
$total_auctions = safe_count($conn, "SELECT COUNT(*) AS total FROM auctions");
$total_bids = safe_count($conn, "SELECT COUNT(*) AS total FROM bids");
$total_winners = safe_count($conn, "SELECT COUNT(*) AS total FROM winners");
$total_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments");
$pending_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments WHERE payment_status NOT IN ('paid','refunded')");

$icon_options = [
    "fa-mobile-screen-button" => ["label" => "Electronics", "desc" => "Phones, tablets, gadgets"],
    "fa-car" => ["label" => "Vehicles", "desc" => "Cars, bikes, transport"],
    "fa-shirt" => ["label" => "Fashion", "desc" => "Clothing, apparel, wear"],
    "fa-gem" => ["label" => "Jewelry", "desc" => "Rings, gems, ornaments"],
    "fa-house" => ["label" => "Home & Living", "desc" => "Furniture, decor"],
    "fa-palette" => ["label" => "Collectibles", "desc" => "Art, antiques, rare"],
    "fa-laptop" => ["label" => "Computers", "desc" => "Laptops, desktops, tech"],
    "fa-camera-retro" => ["label" => "Cameras", "desc" => "Photo & video gear"],
    "fa-basketball" => ["label" => "Sports", "desc" => "Equipment & gear"],
    "fa-book" => ["label" => "Books", "desc" => "Literature & media"],
    "fa-box-open" => ["label" => "Other", "desc" => "Everything else"],
];

$color_options = [
    "blue" => ["bg" => "bg-blue-500", "ring" => "ring-blue-500", "hex" => "#3b82f6"],
    "green" => ["bg" => "bg-emerald-500", "ring" => "ring-emerald-500", "hex" => "#10b981"],
    "orange" => ["bg" => "bg-orange-500", "ring" => "ring-orange-500", "hex" => "#f97316"],
    "purple" => ["bg" => "bg-violet-500", "ring" => "ring-violet-500", "hex" => "#8b5cf6"],
    "red" => ["bg" => "bg-rose-500", "ring" => "ring-rose-500", "hex" => "#f43f5e"],
    "cyan" => ["bg" => "bg-cyan-500", "ring" => "ring-cyan-500", "hex" => "#06b6d4"],
    "dark" => ["bg" => "bg-slate-700", "ring" => "ring-slate-700", "hex" => "#334155"],
    "gold" => ["bg" => "bg-amber-500", "ring" => "ring-amber-500", "hex" => "#f59e0b"],
];

$current_icon = "fa-box-open";
$current_color = "blue";
$current_status = "active";
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Category | NexusBids Admin</title>
    <meta name="description" content="Create a new auction category on NexusBids admin panel.">

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

        /* Icon selector cards */
        .icon-option {
            position: relative;
            cursor: pointer;
            transition: all .2s ease;
        }

        .icon-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .icon-option input[type="radio"]:checked~.icon-option-inner {
            border-color: #6366f1;
            background-color: rgb(99 102 241 / 0.05);
            box-shadow: 0 0 0 3px rgb(99 102 241 / 0.1);
        }

        .dark .icon-option input[type="radio"]:checked~.icon-option-inner {
            background-color: rgb(99 102 241 / 0.1);
        }

        .icon-option input[type="radio"]:checked~.icon-option-inner .icon-check {
            opacity: 1;
            transform: scale(1);
        }

        .icon-check {
            opacity: 0;
            transform: scale(0.5);
            transition: all .2s ease;
        }

        /* Color selector */
        .color-option {
            position: relative;
            cursor: pointer;
            transition: all .2s ease;
        }

        .color-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .color-swatch {
            transition: all .2s ease;
        }

        .color-option input[type="radio"]:checked~.color-swatch {
            transform: scale(1.15);
            box-shadow: 0 0 0 3px white, 0 0 0 5px currentColor;
        }

        .dark .color-option input[type="radio"]:checked~.color-swatch {
            box-shadow: 0 0 0 3px #0f172a, 0 0 0 5px currentColor;
        }

        .color-option input[type="radio"]:checked~.color-swatch .color-check {
            opacity: 1;
            transform: scale(1);
        }

        .color-check {
            opacity: 0;
            transform: scale(0.5);
            transition: all .2s ease;
        }

        .preview-pulse {
            animation: previewPulse 3s ease-in-out infinite;
        }

        @keyframes previewPulse {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.03);
            }
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
                        <a href="categories.php"
                            class="hidden sm:flex w-10 h-10 rounded-xl bg-gray-100 dark:bg-slate-800 items-center justify-center text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-slate-700 transition"
                            title="Back to categories">
                            <i class="fa-solid fa-arrow-left"></i>
                        </a>
                        <div class="min-w-0">
                            <div
                                class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span> New Category
                            </div>
                            <h1 class="text-lg sm:text-xl font-black truncate">Add Category</h1>
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

                <!-- Breadcrumb -->
                <section class="animate-fade-up">
                    <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <a href="index.php" class="hover:text-indigo-600">Dashboard</a>
                        <i class="fa-solid fa-chevron-right text-[8px]"></i>
                        <a href="categories.php" class="hover:text-indigo-600">Categories</a>
                        <i class="fa-solid fa-chevron-right text-[8px]"></i>
                        <span class="text-gray-900 dark:text-white font-semibold">Add New</span>
                    </nav>
                </section>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

                    <!-- LEFT: FORM -->
                    <div class="lg:col-span-8 space-y-6 animate-fade-up">
                        <form action="add-category-process.php" method="POST" class="space-y-6" id="categoryForm">
                            <?php nb_csrf_field(); ?>

                            <!-- Basic Info Card -->
                            <div
                                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                                <div
                                    class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                    <span
                                        class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                                        <i class="fa-solid fa-circle-info"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-base font-bold">Basic Information</h2>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Category name and
                                            description</p>
                                    </div>
                                </div>

                                <div class="p-5 sm:p-6 space-y-5">
                                    <div>
                                        <label for="category_name"
                                            class="block text-xs font-bold uppercase tracking-wider mb-2">
                                            Category Name <span class="text-rose-500">*</span>
                                        </label>
                                        <div class="relative group">
                                            <i
                                                class="fa-solid fa-tag absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-indigo-500 transition text-sm"></i>
                                            <input type="text" name="category_name" id="category_name" required
                                                maxlength="80" placeholder="e.g. Electronics, Fashion, Vehicles..."
                                                class="w-full pl-11 pr-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition placeholder:text-gray-400">
                                        </div>
                                        <p class="text-[11px] text-gray-400 mt-1.5 flex items-center gap-1.5">
                                            <i class="fa-solid fa-circle-info text-[9px]"></i>
                                            Keep it short and descriptive — max 80 characters.
                                        </p>
                                    </div>

                                    <div>
                                        <label for="description"
                                            class="block text-xs font-bold uppercase tracking-wider mb-2">
                                            Description
                                        </label>
                                        <div class="relative">
                                            <textarea name="description" id="description" rows="4" maxlength="500"
                                                placeholder="Optional: a brief summary of what this category contains..."
                                                class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition placeholder:text-gray-400 resize-none"></textarea>
                                            <div
                                                class="absolute bottom-3 right-3 text-[10px] text-gray-400 tabular-nums">
                                                <span id="descCount">0</span>/500
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Appearance Card -->
                            <div
                                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                                <div
                                    class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                    <span
                                        class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm">
                                        <i class="fa-solid fa-palette"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-base font-bold">Appearance</h2>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Icon and color theme
                                        </p>
                                    </div>
                                </div>

                                <div class="p-5 sm:p-6 space-y-6">
                                    <!-- Icon selector -->
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wider mb-3">
                                            Icon <span class="text-rose-500">*</span>
                                        </label>
                                        <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 gap-2.5">
                                            <?php foreach ($icon_options as $value => $meta): ?>
                                                <label class="icon-option block">
                                                    <input type="radio" name="icon"
                                                        value="<?php echo htmlspecialchars($value); ?>" <?php echo $current_icon === $value ? 'checked' : ''; ?>>
                                                    <div
                                                        class="icon-option-inner relative p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-slate-800 hover:border-indigo-300 dark:hover:border-indigo-800 text-center transition">
                                                        <div
                                                            class="w-9 h-9 rounded-lg bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-200 flex items-center justify-center text-sm mx-auto mb-2">
                                                            <i class="fa-solid <?php echo htmlspecialchars($value); ?>"></i>
                                                        </div>
                                                        <div
                                                            class="text-[10px] font-bold text-gray-700 dark:text-gray-300 truncate">
                                                            <?php echo htmlspecialchars($meta['label']); ?>
                                                        </div>
                                                        <div
                                                            class="icon-check absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-indigo-600 flex items-center justify-center shadow-lg">
                                                            <i class="fa-solid fa-check text-white text-[9px]"></i>
                                                        </div>
                                                    </div>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <!-- Color selector -->
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wider mb-3">
                                            Color Theme <span class="text-rose-500">*</span>
                                        </label>
                                        <div class="flex flex-wrap items-center gap-3">
                                            <?php foreach ($color_options as $value => $meta): ?>
                                                <label class="color-option block" title="<?php echo ucfirst($value); ?>">
                                                    <input type="radio" name="color"
                                                        value="<?php echo htmlspecialchars($value); ?>" <?php echo $current_color === $value ? 'checked' : ''; ?>>
                                                    <div class="color-swatch w-10 h-10 rounded-full <?php echo $meta['bg']; ?> flex items-center justify-center shadow-md relative"
                                                        style="color: <?php echo $meta['hex']; ?>;">
                                                        <div
                                                            class="color-check w-5 h-5 rounded-full bg-white flex items-center justify-center shadow">
                                                            <i class="fa-solid fa-check text-[9px]"
                                                                style="color: <?php echo $meta['hex']; ?>;"></i>
                                                        </div>
                                                    </div>
                                                    <span
                                                        class="block text-[10px] font-semibold text-gray-500 dark:text-gray-400 text-center mt-1.5"><?php echo ucfirst($value); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Status Card -->
                            <div
                                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                                <div
                                    class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                    <span
                                        class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                                        <i class="fa-solid fa-toggle-on"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-base font-bold">Status</h2>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Control visibility on
                                            the site</p>
                                    </div>
                                </div>

                                <div class="p-5 sm:p-6">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <label class="icon-option block">
                                            <input type="radio" name="status" value="active" <?php echo $current_status === 'active' ? 'checked' : ''; ?>>
                                            <div
                                                class="icon-option-inner relative p-4 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-slate-800 hover:border-emerald-300 transition">
                                                <div class="flex items-center gap-3">
                                                    <div
                                                        class="w-10 h-10 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-base shrink-0">
                                                        <i class="fa-solid fa-circle-check"></i>
                                                    </div>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="font-bold text-sm">Active</div>
                                                        <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                                            Visible to users</div>
                                                    </div>
                                                    <div
                                                        class="icon-check w-5 h-5 rounded-full bg-indigo-600 flex items-center justify-center shadow-lg shrink-0">
                                                        <i class="fa-solid fa-check text-white text-[9px]"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        </label>

                                        <label class="icon-option block">
                                            <input type="radio" name="status" value="inactive" <?php echo $current_status === 'inactive' ? 'checked' : ''; ?>>
                                            <div
                                                class="icon-option-inner relative p-4 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-slate-800 hover:border-gray-400 transition">
                                                <div class="flex items-center gap-3">
                                                    <div
                                                        class="w-10 h-10 rounded-lg bg-gray-500/10 text-gray-500 flex items-center justify-center text-base shrink-0">
                                                        <i class="fa-solid fa-circle-pause"></i>
                                                    </div>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="font-bold text-sm">Inactive</div>
                                                        <div class="text-[11px] text-gray-500 dark:text-gray-400">Hidden
                                                            from users</div>
                                                    </div>
                                                    <div
                                                        class="icon-check w-5 h-5 rounded-full bg-indigo-600 flex items-center justify-center shadow-lg shrink-0">
                                                        <i class="fa-solid fa-check text-white text-[9px]"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div
                                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6 flex flex-col sm:flex-row items-center justify-between gap-4">
                                <a href="categories.php"
                                    class="text-sm font-semibold text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition flex items-center gap-1.5 order-2 sm:order-1">
                                    <i class="fa-solid fa-xmark text-xs"></i>
                                    Cancel
                                </a>
                                <button type="submit"
                                    class="group inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white text-sm font-bold shadow-lg shadow-indigo-600/30 transition hover:-translate-y-0.5 order-1 sm:order-2">
                                    <i class="fa-solid fa-plus text-xs"></i>
                                    Create Category
                                    <i
                                        class="fa-solid fa-arrow-right text-xs group-hover:translate-x-0.5 transition-transform"></i>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- RIGHT: PREVIEW -->
                    <div class="lg:col-span-4 space-y-6 animate-fade-up">
                        <div class="lg:sticky lg:top-24 space-y-6">

                            <!-- Live Preview -->
                            <div
                                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                                <div
                                    class="p-5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs">
                                            <i class="fa-solid fa-eye"></i>
                                        </span>
                                        <div>
                                            <h3 class="text-sm font-bold">Live Preview</h3>
                                            <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">Updates as
                                                you type</p>
                                        </div>
                                    </div>
                                    <span
                                        class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded-full">
                                        <span class="w-1 h-1 rounded-full bg-emerald-500 animate-pulse"></span>
                                        LIVE
                                    </span>
                                </div>

                                <div class="p-6">
                                    <div id="previewCard"
                                        class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-slate-900 p-5 shadow-sm">
                                        <div class="flex justify-between items-start mb-4">
                                            <div id="previewIcon"
                                                class="preview-pulse w-14 h-14 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-2xl">
                                                <i class="fa-solid fa-box-open"></i>
                                            </div>
                                            <span id="previewStatusBadge"
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-[10px] font-bold rounded-full border border-emerald-500/20 uppercase tracking-wider">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                Active
                                            </span>
                                        </div>
                                        <h3 id="previewName" class="font-bold text-base">Category Name</h3>
                                        <p id="previewDescription"
                                            class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 line-clamp-2 leading-relaxed">
                                            No description provided.
                                        </p>
                                        <div
                                            class="mt-5 flex items-center justify-between gap-3 pt-4 border-t border-gray-100 dark:border-gray-800">
                                            <span class="text-xs text-gray-400">0 live</span>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-xs font-semibold">
                                                Browse
                                                <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <p
                                        class="text-[11px] text-gray-400 mt-4 text-center flex items-center justify-center gap-1.5">
                                        <i class="fa-solid fa-circle-info text-[9px]"></i>
                                        This is how it appears on the categories page.
                                    </p>
                                </div>
                            </div>

                            <!-- Tips Card -->
                            <div
                                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                                <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                    <span
                                        class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xs">
                                        <i class="fa-solid fa-lightbulb"></i>
                                    </span>
                                    <div>
                                        <h3 class="text-sm font-bold">Tips</h3>
                                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">Best practices
                                        </p>
                                    </div>
                                </div>
                                <div class="p-5 space-y-3 text-xs text-gray-600 dark:text-gray-400">
                                    <div class="flex items-start gap-2.5">
                                        <div
                                            class="w-5 h-5 rounded-full bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-[9px] font-bold shrink-0 mt-0.5">
                                            1</div>
                                        <p class="leading-relaxed">Choose a <strong>clear, unique name</strong> that
                                            buyers will recognize.</p>
                                    </div>
                                    <div class="flex items-start gap-2.5">
                                        <div
                                            class="w-5 h-5 rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-[9px] font-bold shrink-0 mt-0.5">
                                            2</div>
                                        <p class="leading-relaxed">Pick an <strong>icon and color</strong> that visually
                                            represent the category.</p>
                                    </div>
                                    <div class="flex items-start gap-2.5">
                                        <div
                                            class="w-5 h-5 rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-[9px] font-bold shrink-0 mt-0.5">
                                            3</div>
                                        <p class="leading-relaxed">Keep it <strong>active</strong> so buyers can browse
                                            it immediately.</p>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
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

            const nameInput = document.getElementById('category_name');
            const descInput = document.getElementById('description');
            const descCount = document.getElementById('descCount');
            const previewName = document.getElementById('previewName');
            const previewDescription = document.getElementById('previewDescription');
            const previewIcon = document.getElementById('previewIcon');
            const previewStatusBadge = document.getElementById('previewStatusBadge');

            const colorThemes = {
                blue: { bg: 'bg-blue-50 dark:bg-blue-950/50', text: 'text-blue-600 dark:text-blue-400' },
                green: { bg: 'bg-emerald-50 dark:bg-emerald-950/50', text: 'text-emerald-600 dark:text-emerald-400' },
                orange: { bg: 'bg-orange-50 dark:bg-orange-950/50', text: 'text-orange-600 dark:text-orange-400' },
                purple: { bg: 'bg-violet-50 dark:bg-violet-950/50', text: 'text-violet-600 dark:text-violet-400' },
                red: { bg: 'bg-rose-50 dark:bg-rose-950/50', text: 'text-rose-600 dark:text-rose-400' },
                cyan: { bg: 'bg-cyan-50 dark:bg-cyan-950/50', text: 'text-cyan-600 dark:text-cyan-400' },
                dark: { bg: 'bg-slate-100 dark:bg-slate-800', text: 'text-slate-700 dark:text-slate-300' },
                gold: { bg: 'bg-amber-50 dark:bg-amber-950/50', text: 'text-amber-600 dark:text-amber-400' },
            };

            function updatePreview() {
                if (previewName) previewName.textContent = nameInput.value.trim() || 'Category Name';
                if (descInput && previewDescription) {
                    const val = descInput.value.trim();
                    previewDescription.textContent = val || 'No description provided.';
                    if (descCount) descCount.textContent = descInput.value.length;
                }
            }

            function updateIconPreview(iconClass) {
                if (!previewIcon) return;
                previewIcon.innerHTML = '<i class="fa-solid ' + iconClass + '"></i>';
            }

            function updateColorPreview(color) {
                if (!previewIcon || !colorThemes[color]) return;
                const theme = colorThemes[color];
                Object.values(colorThemes).forEach(t => {
                    t.bg.split(' ').forEach(c => previewIcon.classList.remove(c));
                    t.text.split(' ').forEach(c => previewIcon.classList.remove(c));
                });
                theme.bg.split(' ').forEach(c => previewIcon.classList.add(c));
                theme.text.split(' ').forEach(c => previewIcon.classList.add(c));
            }

            function updateStatusPreview(status) {
                if (!previewStatusBadge) return;
                if (status === 'active') {
                    previewStatusBadge.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-[10px] font-bold rounded-full border border-emerald-500/20 uppercase tracking-wider';
                    previewStatusBadge.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active';
                } else {
                    previewStatusBadge.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 bg-gray-500/10 text-gray-700 dark:text-gray-400 text-[10px] font-bold rounded-full border border-gray-500/20 uppercase tracking-wider';
                    previewStatusBadge.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-gray-500"></span> Inactive';
                }
            }

            nameInput?.addEventListener('input', updatePreview);
            descInput?.addEventListener('input', updatePreview);
            updatePreview();

            document.querySelectorAll('input[name="icon"]').forEach(r => {
                r.addEventListener('change', function () { if (this.checked) updateIconPreview(this.value); });
            });
            document.querySelectorAll('input[name="color"]').forEach(r => {
                r.addEventListener('change', function () { if (this.checked) updateColorPreview(this.value); });
            });
            document.querySelectorAll('input[name="status"]').forEach(r => {
                r.addEventListener('change', function () { if (this.checked) updateStatusPreview(this.value); });
            });
        })();
    </script>
</body>

</html>