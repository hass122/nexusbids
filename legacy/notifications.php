<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

require_once "config/database.php";
require_once "includes/csrf.php";

$user_id = intval($_SESSION["user_id"]);

/* ============================================================
   FETCH USER INFO
   ============================================================ */
$user_stmt = $conn->prepare("SELECT full_name, role FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_info = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

$full_name = $user_info["full_name"] ?? "User";
$role = $user_info["role"] ?? "user";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));

/* ============================================================
   BACK LINK (Role-aware) — use DB role
   ============================================================ */
$back_link = $role === "seller"
    ? "seller/dashboard.php"
    : ($role === "admin" ? "admin/index.php" : "user/dashboard.php");

/* ============================================================
   HANDLE ACTIONS (mark read / mark all read / delete)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('nb_csrf_check'))
        nb_csrf_check();

    $action = $_POST['action'] ?? '';
    $notif_id = (int) ($_POST['notif_id'] ?? 0);

    if ($action === 'mark_all_read') {
        $u = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $u->bind_param("i", $user_id);
        $u->execute();
        $u->close();
        $_SESSION['notif_flash'] = "All notifications marked as read.";
    } elseif ($action === 'mark_read' && $notif_id > 0) {
        $u = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
        $u->bind_param("ii", $notif_id, $user_id);
        $u->execute();
        $u->close();
    } elseif ($action === 'delete' && $notif_id > 0) {
        $d = $conn->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
        $d->bind_param("ii", $notif_id, $user_id);
        $d->execute();
        $d->close();
        $_SESSION['notif_flash'] = "Notification deleted.";
    } elseif ($action === 'clear_all_read') {
        $d = $conn->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
        $d->bind_param("i", $user_id);
        $d->execute();
        $d->close();
        $_SESSION['notif_flash'] = "All read notifications cleared.";
    }
    header("Location: notifications.php");
    exit();
}

$flash = $_SESSION['notif_flash'] ?? null;
unset($_SESSION['notif_flash']);

/* ============================================================
   FILTERS + PAGINATION
   ============================================================ */
$view = $_GET['view'] ?? 'all';       // all | unread
$type_f = $_GET['type'] ?? 'all';       // all | <type>
$q = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

/* Unread count (before any changes) */
$u_stmt = $conn->prepare("SELECT COUNT(*) AS t FROM notifications WHERE user_id = ? AND is_read = 0");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$unread_count = intval($u_stmt->get_result()->fetch_assoc()['t'] ?? 0);
$u_stmt->close();

/* Total count */
$t_stmt = $conn->prepare("SELECT COUNT(*) AS t FROM notifications WHERE user_id = ?");
$t_stmt->bind_param("i", $user_id);
$t_stmt->execute();
$total_all = intval($t_stmt->get_result()->fetch_assoc()['t'] ?? 0);
$t_stmt->close();

/* Build WHERE */
$where = ["user_id = ?"];
$params = [$user_id];
$types = "i";

if ($view === 'unread') {
    $where[] = "is_read = 0";
}
if ($type_f !== 'all' && $type_f !== '') {
    $where[] = "type = ?";
    $params[] = $type_f;
    $types .= "s";
}
if ($q !== '') {
    $where[] = "(title LIKE ? OR message LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}
$where_sql = implode(' AND ', $where);

/* Count filtered */
$cf = $conn->prepare("SELECT COUNT(*) AS t FROM notifications WHERE $where_sql");
$cf->bind_param($types, ...$params);
$cf->execute();
$total_filtered = intval($cf->get_result()->fetch_assoc()['t'] ?? 0);
$cf->close();

$total_pages = max(1, (int) ceil($total_filtered / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

/* Fetch */
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare("
    SELECT notification_id, type, title, message, link, is_read, created_at
    FROM notifications
    WHERE $where_sql
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$notifications = [];
while ($row = $res->fetch_assoc())
    $notifications[] = $row;
$stmt->close();

$total_notifications = count($notifications);

/* ============================================================
   ICON / COLOR MAP — complete set
   ============================================================ */
$icon_map = [
    'outbid' => [
        'icon' => 'fa-gavel',
        'icon_bg' => 'bg-rose-500/10',
        'icon_text' => 'text-rose-600 dark:text-rose-400',
        'border' => 'border-rose-500/30',
        'accent' => 'bg-rose-500',
        'label' => 'Outbid',
        'badge' => 'bg-rose-500/10 text-rose-700 dark:text-rose-400 border-rose-500/20',
    ],
    'won' => [
        'icon' => 'fa-trophy',
        'icon_bg' => 'bg-amber-500/10',
        'icon_text' => 'text-amber-600 dark:text-amber-400',
        'border' => 'border-amber-500/30',
        'accent' => 'bg-amber-500',
        'label' => 'Won',
        'badge' => 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20',
    ],
    'bid' => [
        'icon' => 'fa-gavel',
        'icon_bg' => 'bg-emerald-500/10',
        'icon_text' => 'text-emerald-600 dark:text-emerald-400',
        'border' => 'border-emerald-500/30',
        'accent' => 'bg-emerald-500',
        'label' => 'Bid',
        'badge' => 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20',
    ],
    'payment' => [
        'icon' => 'fa-credit-card',
        'icon_bg' => 'bg-emerald-500/10',
        'icon_text' => 'text-emerald-600 dark:text-emerald-400',
        'border' => 'border-emerald-500/30',
        'accent' => 'bg-emerald-500',
        'label' => 'Payment',
        'badge' => 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20',
    ],
    'review' => [
        'icon' => 'fa-star',
        'icon_bg' => 'bg-violet-500/10',
        'icon_text' => 'text-violet-600 dark:text-violet-400',
        'border' => 'border-violet-500/30',
        'accent' => 'bg-violet-500',
        'label' => 'Review',
        'badge' => 'bg-violet-500/10 text-violet-700 dark:text-violet-400 border-violet-500/20',
    ],
    'order' => [
        'icon' => 'fa-receipt',
        'icon_bg' => 'bg-blue-500/10',
        'icon_text' => 'text-blue-600 dark:text-blue-400',
        'border' => 'border-blue-500/30',
        'accent' => 'bg-blue-500',
        'label' => 'Order',
        'badge' => 'bg-blue-500/10 text-blue-700 dark:text-blue-400 border-blue-500/20',
    ],
    'shipment' => [
        'icon' => 'fa-truck-fast',
        'icon_bg' => 'bg-violet-500/10',
        'icon_text' => 'text-violet-600 dark:text-violet-400',
        'border' => 'border-violet-500/30',
        'accent' => 'bg-violet-500',
        'label' => 'Shipment',
        'badge' => 'bg-violet-500/10 text-violet-700 dark:text-violet-400 border-violet-500/20',
    ],
    'sale' => [
        'icon' => 'fa-hand-holding-dollar',
        'icon_bg' => 'bg-emerald-500/10',
        'icon_text' => 'text-emerald-600 dark:text-emerald-400',
        'border' => 'border-emerald-500/30',
        'accent' => 'bg-emerald-500',
        'label' => 'Sale',
        'badge' => 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20',
    ],
    'payout' => [
        'icon' => 'fa-wallet',
        'icon_bg' => 'bg-amber-500/10',
        'icon_text' => 'text-amber-600 dark:text-amber-400',
        'border' => 'border-amber-500/30',
        'accent' => 'bg-amber-500',
        'label' => 'Payout',
        'badge' => 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20',
    ],
    'dispute' => [
        'icon' => 'fa-scale-balanced',
        'icon_bg' => 'bg-rose-500/10',
        'icon_text' => 'text-rose-600 dark:text-rose-400',
        'border' => 'border-rose-500/30',
        'accent' => 'bg-rose-500',
        'label' => 'Dispute',
        'badge' => 'bg-rose-500/10 text-rose-700 dark:text-rose-400 border-rose-500/20',
    ],
    'system' => [
        'icon' => 'fa-gear',
        'icon_bg' => 'bg-slate-500/10',
        'icon_text' => 'text-slate-600 dark:text-slate-400',
        'border' => 'border-slate-500/30',
        'accent' => 'bg-slate-500',
        'label' => 'System',
        'badge' => 'bg-slate-500/10 text-slate-700 dark:text-slate-400 border-slate-500/20',
    ],
    'message' => [
        'icon' => 'fa-comment',
        'icon_bg' => 'bg-cyan-500/10',
        'icon_text' => 'text-cyan-600 dark:text-cyan-400',
        'border' => 'border-cyan-500/30',
        'accent' => 'bg-cyan-500',
        'label' => 'Message',
        'badge' => 'bg-cyan-500/10 text-cyan-700 dark:text-cyan-400 border-cyan-500/20',
    ],
];
$default_meta = [
    'icon' => 'fa-bell',
    'icon_bg' => 'bg-indigo-500/10',
    'icon_text' => 'text-indigo-600 dark:text-indigo-400',
    'border' => 'border-indigo-500/30',
    'accent' => 'bg-indigo-500',
    'label' => 'Notice',
    'badge' => 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 border-indigo-500/20',
];

/* Time ago */
function time_ago($datetime)
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

/* Build URL for pagination preserving filters */
function notif_build_url($overrides = [])
{
    return '?' . http_build_query(array_merge($_GET, $overrides));
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <meta name="description"
        content="Your NexusBids notification center — auction updates, wins, payments, and reviews.">

    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif']},
                    colors: {
                        brand: {50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe', 300: '#a5b4fc', 400: '#818cf8', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca', 800: '#3730a3', 900: '#312e81'}
                    },
                    animation: {
                        'fade-up': 'fadeUp 0.6s ease-out forwards',
                        'fade-in': 'fadeIn 0.4s ease-out forwards',
                        'pulse-soft': 'pulseSoft 2s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeUp: {'0%': {opacity: '0', transform: 'translateY(16px)'}, '100%': {opacity: '1', transform: 'translateY(0)'}},
                        fadeIn: {'0%': {opacity: '0'}, '100%': {opacity: '1'}},
                        pulseSoft: {'0%,100%': {opacity: '1'}, '50%': {opacity: '0.5'}},
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

        .custom-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            appearance: none;
        }

        .notif-item {
            transition: all .25s cubic-bezier(.4, 0, .2, 1);
        }

        .notif-item:hover {
            transform: translateX(3px);
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
                <a href="about.php" class="hover:text-white transition-colors duration-200"><i
                        class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
                <a href="about.php#why-nexusbids" class="hover:text-white transition-colors duration-200"><i
                        class="fa-solid fa-shield-halved text-indigo-400"></i> Secure Bidding</a>
            </div>
        </div>
    </div>

    <!-- Navbar -->
    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-[1600px] mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-gray-900 dark:text-white shrink-0 group"
                href="index.php">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30 group-hover:shadow-indigo-500/50 group-hover:scale-105 transition-all duration-300">
                    <i class="fa-solid fa-gavel text-lg"></i>
                </span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            </a>

            <nav class="hidden lg:flex items-center gap-7 text-sm font-medium">
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition"
                    href="index.php">Home</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition"
                    href="auctions.php">Auctions</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition"
                    href="categories.php">Categories</a>
                <a class="relative text-indigo-600 dark:text-indigo-400 font-semibold after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-indigo-600 dark:after:bg-indigo-400 after:rounded-full"
                    href="<?php echo htmlspecialchars($back_link); ?>">Dashboard</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition"
                    href="about.php">About</a>
            </nav>

            <div class="flex items-center gap-2 sm:gap-3">
                <button aria-label="Toggle Theme"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200 hover:scale-105"
                    id="themeToggle">
                    <i class="fa-solid fa-moon dark:hidden"></i>
                    <i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>

                <!-- ⭐ NOTIFICATION BELL — Always Visible (Active State) -->
                <a href="notifications.php"
                    class="relative w-10 h-10 rounded-full bg-indigo-500/10 dark:bg-indigo-500/20 flex items-center justify-center text-indigo-600 dark:text-indigo-400 border border-indigo-500/20 transition hover:scale-105"
                    title="Notifications">
                    <i class="fa-solid fa-bell"></i>
                    <?php if ($unread_count > 0): ?>
                        <span
                            class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center border-2 border-white dark:border-slate-900">
                            <?php echo $unread_count > 99 ? '99+' : $unread_count; ?>
                        </span>
                    <?php endif; ?>
                </a>

                <!-- Desktop User Info + Logout -->
                <div class="hidden lg:flex items-center gap-3 pl-3 border-l border-gray-200 dark:border-gray-800">
                    <div class="relative">
                        <div
                            class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg ring-2 ring-white dark:ring-slate-900">
                            <?php echo htmlspecialchars($initials); ?>
                        </div>
                        <span
                            class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900"></span>
                    </div>
                    <div class="text-left">
                        <div class="text-sm font-bold leading-tight truncate max-w-32">
                            <?php echo htmlspecialchars($full_name); ?>
                        </div>
                        <div
                            class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1">
                            <i class="fa-solid fa-circle-check text-[8px]"></i>
                            <?php echo ucfirst(htmlspecialchars($role)); ?>
                        </div>
                    </div>
                    <a class="inline-flex px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition"
                        href="auth/logout.php">
                        <i class="fa-solid fa-right-from-bracket mr-1.5"></i> Logout
                    </a>
                </div>

                <!-- Hamburger -->
                <button id="mobileMenuBtn" aria-label="Toggle Menu" aria-expanded="false"
                    class="lg:hidden w-10 h-10 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors">
                    <i class="fa-solid fa-bars" id="menuIcon"></i>
                </button>
            </div>
        </div>
    </header>
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
            <a href="about.php"
                class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
                <i class="fa-solid fa-circle-info w-5 text-center text-indigo-500"></i> About
            </a>

            <div class="my-2 border-t border-gray-100 dark:border-gray-800"></div>

            <a href="<?php echo htmlspecialchars($back_link); ?>"
                class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
                <i class="fa-solid fa-gauge-high w-5 text-center text-indigo-500"></i> Dashboard
            </a>
            <a href="notifications.php"
                class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/30 transition-all">
                <i class="fa-solid fa-bell w-5 text-center"></i> Notifications
                <?php if ($unread_count > 0): ?>
                    <span class="ml-auto px-2 py-0.5 rounded-full bg-rose-500 text-white text-[10px] font-bold">
                        <?php echo $unread_count; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="auth/logout.php"
                class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-all">
                <i class="fa-solid fa-right-from-bracket w-5 text-center"></i> Logout
            </a>
        </nav>
    </div>

    <main class="flex-grow max-w-[1600px] mx-auto w-full px-4 sm:px-8 py-8 space-y-8">

        <!-- Flash -->
        <?php if ($flash): ?>
            <div
                class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 flex items-center gap-3 animate-fade-up">
                <i class="fa-solid fa-circle-check text-emerald-600 dark:text-emerald-400"></i>
                <span
                    class="text-sm text-emerald-700 dark:text-emerald-300 font-medium"><?php echo htmlspecialchars($flash); ?></span>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <section class="animate-fade-up">
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div>
                    <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                        <a href="<?php echo htmlspecialchars($back_link); ?>"
                            class="hover:text-indigo-600 transition">Dashboard</a>
                        <i class="fa-solid fa-chevron-right text-[8px]"></i>
                        <span class="text-gray-900 dark:text-white font-semibold">Notifications</span>
                    </nav>
                    <h1
                        class="text-3xl sm:text-4xl font-black tracking-tight text-gray-900 dark:text-white flex items-center gap-3">
                        <span
                            class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white shadow-lg">
                            <i class="fa-solid fa-bell text-lg"></i>
                        </span>
                        Notifications
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                        You have <strong
                            class="text-gray-900 dark:text-white"><?php echo number_format($total_all); ?></strong>
                        total
                        <?php if ($unread_count > 0): ?>
                            · <span class="text-indigo-600 dark:text-indigo-400 font-semibold"><?php echo $unread_count; ?>
                                unread</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="<?php echo htmlspecialchars($back_link); ?>"
                        class="px-5 py-2.5 rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 text-gray-700 dark:text-gray-200 text-sm font-semibold transition flex items-center gap-2">
                        <i class="fa-solid fa-arrow-left"></i> Dashboard
                    </a>
                    <?php if ($unread_count > 0): ?>
                        <form method="POST" class="inline">
                            <?php if (function_exists('nb_csrf_field'))
                                nb_csrf_field(); ?>
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit"
                                class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition flex items-center gap-2 shadow-lg shadow-indigo-600/30">
                                <i class="fa-solid fa-check-double"></i> Mark all read
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Filter Bar -->
        <?php if ($total_all > 0): ?>
            <section
                class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4 animate-fade-up"
                style="animation-delay: 0.05s">
                <form method="GET" class="flex flex-col lg:flex-row gap-3">

                    <!-- View tabs -->
                    <div class="flex items-center gap-1 p-1 rounded-xl bg-gray-100 dark:bg-slate-800">
                        <a href="<?php echo htmlspecialchars(notif_build_url(['view' => 'all', 'page' => 1])); ?>"
                            class="px-4 py-2 rounded-lg text-xs font-bold transition <?php echo $view === 'all' ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'; ?>">
                            All <span class="text-[10px] opacity-75">(<?php echo $total_all; ?>)</span>
                        </a>
                        <a href="<?php echo htmlspecialchars(notif_build_url(['view' => 'unread', 'page' => 1])); ?>"
                            class="px-4 py-2 rounded-lg text-xs font-bold transition <?php echo $view === 'unread' ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'; ?>">
                            Unread <?php if ($unread_count > 0): ?><span
                                    class="text-[10px] text-rose-500 font-bold">(<?php echo $unread_count; ?>)</span><?php endif; ?>
                        </a>
                    </div>

                    <!-- Search -->
                    <div class="relative flex-1">
                        <i
                            class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>"
                            class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            placeholder="Search notifications...">
                    </div>

                    <!-- Type filter -->
                    <select name="type" onchange="this.form.submit()"
                        class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer min-w-[150px]">
                        <option value="all">All Types</option>
                        <option value="outbid" <?php echo $type_f === 'outbid' ? 'selected' : ''; ?>>Outbid</option>
                        <option value="won" <?php echo $type_f === 'won' ? 'selected' : ''; ?>>Won</option>
                        <option value="bid" <?php echo $type_f === 'bid' ? 'selected' : ''; ?>>Bid</option>
                        <option value="payment" <?php echo $type_f === 'payment' ? 'selected' : ''; ?>>Payment</option>
                        <option value="order" <?php echo $type_f === 'order' ? 'selected' : ''; ?>>Order</option>
                        <option value="shipment" <?php echo $type_f === 'shipment' ? 'selected' : ''; ?>>Shipment</option>
                        <option value="review" <?php echo $type_f === 'review' ? 'selected' : ''; ?>>Review</option>
                        <option value="sale" <?php echo $type_f === 'sale' ? 'selected' : ''; ?>>Sale</option>
                        <option value="payout" <?php echo $type_f === 'payout' ? 'selected' : ''; ?>>Payout</option>
                        <option value="dispute" <?php echo $type_f === 'dispute' ? 'selected' : ''; ?>>Dispute</option>
                        <option value="system" <?php echo $type_f === 'system' ? 'selected' : ''; ?>>System</option>
                    </select>

                    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">

                    <button type="submit"
                        class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold whitespace-nowrap">
                        <i class="fa-solid fa-filter mr-1.5"></i> Filter
                    </button>

                    <?php if ($q !== '' || $type_f !== 'all' || $view !== 'all'): ?>
                        <a href="notifications.php"
                            class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-sm font-semibold whitespace-nowrap">
                            <i class="fa-solid fa-xmark"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </section>
        <?php endif; ?>

        <!-- Notifications List -->
        <?php if ($total_notifications > 0): ?>

            <section class="space-y-3 animate-fade-up" style="animation-delay: 0.1s">
                <?php foreach ($notifications as $n):
                    $type = strtolower($n["type"] ?? "");
                    $meta = $icon_map[$type] ?? $default_meta;
                    $link = !empty($n["link"]) ? $n["link"] : "#";
                    $is_unread = (int) ($n["is_read"] ?? 1) === 0;
                    $nid = (int) $n['notification_id'];
                    ?>
                    <div
                        class="notif-item group relative rounded-2xl bg-white dark:bg-slate-900 border <?php echo $is_unread ? 'border-indigo-500/40' : 'border-gray-200 dark:border-gray-800'; ?> hover:border-indigo-500 hover:shadow-xl hover:shadow-indigo-500/5 transition-all duration-300 overflow-hidden">

                        <div class="absolute top-0 left-0 w-1 h-full <?php echo $meta['accent']; ?>"></div>

                        <?php if ($is_unread): ?>
                            <div class="absolute top-4 right-4 w-2 h-2 rounded-full bg-indigo-500 animate-pulse-soft"></div>
                        <?php endif; ?>

                        <div class="flex items-start gap-4 p-5 pl-6">
                            <a href="<?php echo htmlspecialchars($link); ?>"
                                class="w-12 h-12 rounded-xl <?php echo $meta['icon_bg'] . ' ' . $meta['icon_text']; ?> flex items-center justify-center text-lg shrink-0 group-hover:scale-110 group-hover:-rotate-3 transition-all duration-300">
                                <i class="fa-solid <?php echo $meta['icon']; ?>"></i>
                            </a>

                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2 mb-1.5">
                                    <span
                                        class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md border <?php echo $meta['badge']; ?>">
                                        <?php echo $meta['label']; ?>
                                    </span>
                                    <?php if ($is_unread): ?>
                                        <span
                                            class="text-[10px] font-bold text-indigo-600 dark:text-indigo-400 uppercase tracking-wider">New</span>
                                    <?php endif; ?>
                                </div>

                                <a href="<?php echo htmlspecialchars($link); ?>" class="block">
                                    <h3
                                        class="font-bold text-gray-900 dark:text-white text-base leading-snug hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                                        <?php echo htmlspecialchars($n["title"]); ?>
                                    </h3>
                                    <?php if (!empty($n['message'])): ?>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1.5 leading-relaxed">
                                            <?php echo htmlspecialchars($n["message"]); ?>
                                        </p>
                                    <?php endif; ?>
                                </a>

                                <div class="flex flex-wrap items-center gap-3 mt-3">
                                    <div class="flex items-center gap-2 text-[11px] text-gray-400">
                                        <i class="fa-regular fa-clock text-[10px]"></i>
                                        <span
                                            title="<?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($n['created_at']))); ?>">
                                            <?php echo htmlspecialchars(time_ago($n['created_at'])); ?>
                                        </span>
                                    </div>

                                    <div class="flex items-center gap-1.5 ml-auto">
                                        <?php if ($is_unread): ?>
                                            <form method="POST" class="inline">
                                                <?php if (function_exists('nb_csrf_field'))
                                                    nb_csrf_field(); ?>
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="notif_id" value="<?php echo $nid; ?>">
                                                <button type="submit"
                                                    class="px-2.5 py-1 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-emerald-500 hover:text-white text-gray-600 dark:text-gray-400 text-[11px] font-bold transition"
                                                    title="Mark as read">
                                                    <i class="fa-solid fa-check text-[9px]"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($link !== '#'): ?>
                                            <a href="<?php echo htmlspecialchars($link); ?>"
                                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 hover:bg-indigo-600 hover:text-white text-indigo-600 dark:text-indigo-400 text-[11px] font-bold transition"
                                                title="Open">
                                                <i class="fa-solid fa-arrow-right text-[9px]"></i>
                                            </a>
                                        <?php endif; ?>

                                        <form method="POST" class="inline"
                                            onsubmit="return confirm('Delete this notification?');">
                                            <?php if (function_exists('nb_csrf_field'))
                                                nb_csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="notif_id" value="<?php echo $nid; ?>">
                                            <button type="submit"
                                                class="px-2.5 py-1 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-rose-500 hover:text-white text-gray-600 dark:text-gray-400 text-[11px] font-bold transition"
                                                title="Delete">
                                                <i class="fa-solid fa-trash text-[9px]"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav class="flex flex-wrap items-center justify-between gap-4 px-2 animate-fade-up">
                    <p class="text-xs text-gray-500">
                        Page <strong class="text-gray-900 dark:text-white"><?php echo $page; ?></strong> of
                        <strong class="text-gray-900 dark:text-white"><?php echo $total_pages; ?></strong>
                        · <?php echo number_format($total_filtered); ?> total
                    </p>
                    <div class="flex items-center gap-1.5">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo htmlspecialchars(notif_build_url(['page' => $page - 1])); ?>"
                                class="w-9 h-9 rounded-xl flex items-center justify-center bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:bg-indigo-100 dark:hover:bg-slate-800 transition">
                                <i class="fa-solid fa-chevron-left text-xs"></i>
                            </a>
                        <?php endif; ?>

                        <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++):
                            $active = ($p === $page);
                            ?>
                            <a href="<?php echo htmlspecialchars(notif_build_url(['page' => $p])); ?>"
                                class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold transition <?php echo $active ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:bg-indigo-100 dark:hover:bg-slate-800'; ?>">
                                <?php echo $p; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="<?php echo htmlspecialchars(notif_build_url(['page' => $page + 1])); ?>"
                                class="w-9 h-9 rounded-xl flex items-center justify-center bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:bg-indigo-100 dark:hover:bg-slate-800 transition">
                                <i class="fa-solid fa-chevron-right text-xs"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>

        <?php elseif ($total_all > 0): ?>

            <!-- Filtered empty -->
            <div
                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-12 sm:p-16 text-center animate-fade-up">
                <div
                    class="w-16 h-16 mx-auto rounded-2xl bg-gray-100 dark:bg-slate-800 text-gray-400 flex items-center justify-center text-2xl mb-4">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <h3 class="text-xl font-black text-gray-900 dark:text-white">No matching notifications</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Try adjusting your filters.</p>
                <a href="notifications.php"
                    class="inline-flex mt-6 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold items-center gap-2">
                    <i class="fa-solid fa-rotate-right"></i> Clear Filters
                </a>
            </div>

        <?php else: ?>

            <!-- Empty state -->
            <div
                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-12 sm:p-20 text-center animate-fade-up">
                <div
                    class="w-24 h-24 mx-auto rounded-3xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 flex items-center justify-center text-4xl mb-6 relative">
                    <i class="fa-regular fa-bell text-indigo-500"></i>
                    <span
                        class="absolute -bottom-1 -right-1 w-6 h-6 rounded-full bg-gray-200 dark:bg-slate-800 flex items-center justify-center">
                        <i class="fa-solid fa-check text-gray-500 dark:text-gray-400 text-[10px]"></i>
                    </span>
                </div>
                <h2 class="text-2xl sm:text-3xl font-black text-gray-900 dark:text-white">You're all caught up!</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-3 max-w-md mx-auto leading-relaxed">
                    No notifications to show. We'll let you know when there's an update on your auctions, bids, or payments.
                </p>
                <div class="flex flex-wrap gap-3 justify-center mt-8">
                    <a href="auctions.php"
                        class="group px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold transition hover:-translate-y-0.5 shadow-lg shadow-indigo-600/30 flex items-center gap-2">
                        <i class="fa-solid fa-gavel"></i> Browse Auctions
                    </a>
                    <a href="<?php echo htmlspecialchars($back_link); ?>"
                        class="px-6 py-3 rounded-xl bg-white dark:bg-slate-800 border border-gray-200 dark:border-gray-700 hover:border-indigo-500 text-gray-700 dark:text-gray-200 text-sm font-bold transition hover:-translate-y-0.5 flex items-center gap-2">
                        <i class="fa-solid fa-arrow-left"></i> Dashboard
                    </a>
                </div>
            </div>

        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-950 text-gray-400 text-xs border-t border-gray-900 mt-8">
        <div class="max-w-[1600px] mx-auto px-4 sm:px-8 py-16 grid grid-cols-1 md:grid-cols-5 gap-10">
            <div class="md:col-span-2 space-y-5">
                <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-white" href="index.php">
                    <div
                        class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg">
                        <i class="fa-solid fa-gavel text-lg"></i>
                    </div>
                    Nexus<span class="text-indigo-400">Bids</span>
                </a>
                <p class="text-gray-400 text-xs leading-relaxed max-w-sm">Pakistan's modern online auction marketplace.
                    Discover, bid and win products you love.</p>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Marketplace</h4>
                <ul class="space-y-2.5">
                    <li><a class="hover:text-white" href="auctions.php">Browse Auctions</a></li>
                    <li><a class="hover:text-white" href="categories.php">Categories</a></li>
                </ul>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Account</h4>
                <ul class="space-y-2.5">
                    <li><a class="hover:text-white" href="<?php echo htmlspecialchars($back_link); ?>">My Dashboard</a>
                    </li>
                    <li><a class="hover:text-white" href="notifications.php">Notifications</a></li>
                    <li><a class="hover:text-white" href="auth/logout.php">Logout</a></li>
                </ul>
            </div>
            <div class="space-y-4">
                <h4 class="text-sm font-bold text-white uppercase tracking-wider">Support</h4>
                <ul class="space-y-2.5">
                    <li><a class="hover:text-white" href="about.php">Help Center</a></li>
                    <li><a class="hover:text-white" href="about.php#contact">Contact Us</a></li>
                    <li><a class="hover:text-white" href="about.php">Terms &amp; Conditions</a></li>
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
            // Mobile Menu Toggle
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const mobileMenu = document.getElementById('mobileMenu');
            const menuIcon = document.getElementById('menuIcon');
            if (mobileMenuBtn && mobileMenu) {
                mobileMenuBtn.addEventListener('click', function () {
                    const isOpen = !mobileMenu.classList.contains('hidden');
                    mobileMenu.classList.toggle('hidden');
                    mobileMenuBtn.setAttribute('aria-expanded', !isOpen);
                    if (menuIcon) menuIcon.className = isOpen ? 'fa-solid fa-bars' : 'fa-solid fa-xmark';
                });
            }
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                html.classList.add('dark');
            }
            themeToggle?.addEventListener('click', function () {
                html.classList.toggle('dark');
                localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
            });
        })();
    </script>
    <?php require_once __DIR__ . '/includes/chat-widget.php'; ?>
</body>

</html>