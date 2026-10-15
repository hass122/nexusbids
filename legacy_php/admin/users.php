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

/* Fetch all users */
$users_result = $conn->query("
    SELECT *
    FROM users
    ORDER BY created_at DESC
");
$users_data = $users_result ? $users_result->fetch_all(MYSQLI_ASSOC) : [];

/* Aggregate counts */
$total_count = 0;
$bidders_count = 0;
$sellers_count = 0;
$admins_count = 0;
$active_count = 0;
$blocked_count = 0;
foreach ($users_data as $u) {
    $total_count++;
    $role = strtolower($u['role'] ?? 'user');
    $status = strtolower($u['status'] ?? 'active');
    if ($role === 'seller')
        $sellers_count++;
    elseif ($role === 'admin')
        $admins_count++;
    else
        $bidders_count++;
    if ($status === 'active')
        $active_count++;
    else
        $blocked_count++;
}

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
$total_bids = safe_count($conn, "SELECT COUNT(*) AS total FROM bids");
$total_winners = safe_count($conn, "SELECT COUNT(*) AS total FROM winners");
$total_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments");
$pending_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments WHERE payment_status NOT IN ('paid','refunded')");

/* Role style map */
$role_styles = [
    'admin' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'border' => 'border-rose-500/20', 'icon' => 'fa-shield-halved'],
    'seller' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'border' => 'border-violet-500/20', 'icon' => 'fa-store'],
    'user' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'border' => 'border-blue-500/20', 'icon' => 'fa-user'],
];
$default_role = $role_styles['user'];

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
    <title>Users | NexusBids Admin</title>
    <meta name="description" content="Manage NexusBids users, sellers, and administrators.">

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
                            <h1 class="text-lg sm:text-xl font-black truncate">Users</h1>
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
                                <span class="text-gray-900 dark:text-white font-semibold">Users</span>
                            </nav>
                            <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                                <span
                                    class="w-11 h-11 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white shadow-lg">
                                    <i class="fa-solid fa-users text-base"></i>
                                </span>
                                User Management
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Manage bidders, sellers, and
                                administrators across the platform.</p>
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
                                    class="fa-solid fa-users"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($total_count); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">All accounts</p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-blue-500 hover:shadow-lg hover:shadow-blue-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Bidders</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-user"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($bidders_count); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Regular users</p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-violet-500 hover:shadow-lg hover:shadow-violet-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Sellers</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-store"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?php echo number_format($sellers_count); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Verified vendors</p>
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
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Currently enabled
                        </p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border-2 <?php echo $blocked_count > 0 ? 'border-rose-500/30' : 'border-gray-200 dark:border-gray-800'; ?> hover:shadow-lg hover:shadow-rose-500/10 transition hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Blocked</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-xs group-hover:scale-110 transition"><i
                                    class="fa-solid fa-ban"></i></span>
                        </div>
                        <div
                            class="text-2xl font-black <?php echo $blocked_count > 0 ? 'text-rose-600 dark:text-rose-400' : ''; ?> tabular-nums">
                            <?php echo number_format($blocked_count); ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Suspended accounts</p>
                    </div>
                </section>

                <!-- Users Table -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden animate-fade-up">

                    <div
                        class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row justify-between items-center gap-4">
                        <div class="relative w-full sm:w-80">
                            <i
                                class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input id="userSearch" type="text" autocomplete="off"
                                class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                placeholder="Search by name, email, or phone...">
                        </div>
                        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                            <select id="roleFilter"
                                class="custom-select flex-1 sm:flex-initial py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                                <option value="all">All Roles</option>
                                <option value="user">Bidders</option>
                                <option value="seller">Sellers</option>
                                <option value="admin">Admins</option>
                            </select>
                            <select id="statusFilter"
                                class="custom-select flex-1 sm:flex-initial py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                                <option value="all">All Status</option>
                                <option value="active">Active</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" id="usersTable">
                            <thead
                                class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                <tr>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        User</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Contact</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Role</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Joined</th>
                                    <th
                                        class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Status</th>
                                    <th
                                        class="px-6 py-4 text-right text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                        Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <?php if (!empty($users_data)): ?>
                                    <?php foreach ($users_data as $user):
                                        $role = strtolower($user['role'] ?? 'user');
                                        $status = strtolower($user['status'] ?? 'active');
                                        $role_style = $role_styles[$role] ?? $default_role;
                                        $is_admin = $role === 'admin';
                                        $initials = strtoupper(substr(preg_replace('/\s+/', '', $user['full_name']), 0, 2));
                                        $created = $user['created_at'] ?? null;
                                        ?>
                                        <tr class="data-row"
                                            data-name="<?php echo htmlspecialchars(strtolower($user['full_name'] ?? '')); ?>"
                                            data-email="<?php echo htmlspecialchars(strtolower($user['email'] ?? '')); ?>"
                                            data-phone="<?php echo htmlspecialchars(strtolower($user['phone'] ?? '')); ?>"
                                            data-role="<?php echo $role; ?>" data-status="<?php echo $status; ?>">
                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-3">
                                                    <div
                                                        class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg shrink-0">
                                                        <?php echo htmlspecialchars($initials); ?>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="font-bold truncate">
                                                            <?php echo htmlspecialchars($user['full_name']); ?></div>
                                                        <div class="text-[10px] text-gray-400 font-mono">
                                                            #<?php echo str_pad((int) $user['user_id'], 6, '0', STR_PAD_LEFT); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-xs flex items-center gap-1.5 truncate max-w-[180px]">
                                                    <i class="fa-solid fa-envelope text-gray-400 text-[10px] shrink-0"></i>
                                                    <span
                                                        class="truncate"><?php echo htmlspecialchars($user['email']); ?></span>
                                                </div>
                                                <div class="text-[11px] text-gray-500 flex items-center gap-1.5 mt-1">
                                                    <i class="fa-solid fa-phone text-gray-400 text-[10px] shrink-0"></i>
                                                    <span><?php echo htmlspecialchars($user['phone'] ?? '—'); ?></span>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?php echo $role_style['bg'] . ' ' . $role_style['text'] . ' ' . $role_style['border']; ?> text-[10px] font-bold border uppercase tracking-wider">
                                                    <i class="fa-solid <?php echo $role_style['icon']; ?> text-[9px]"></i>
                                                    <?php echo ucfirst($role); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-xs font-medium">
                                                    <?php echo $created ? date('d M Y', strtotime($created)) : '—'; ?></div>
                                                <div class="text-[10px] text-gray-400">
                                                    <?php echo $created ? time_ago_short($created) : ''; ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php if ($status === 'active'): ?>
                                                    <span
                                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20 text-[10px] font-bold">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                                                    </span>
                                                <?php else: ?>
                                                    <span
                                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-rose-500/10 text-rose-700 dark:text-rose-400 border border-rose-500/20 text-[10px] font-bold">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span>
                                                        Blocked
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 text-right">
                                                <?php if (!$is_admin): ?>
                                                    <a href="user-status.php?id=<?php echo (int) $user['user_id']; ?>"
                                                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold transition <?php echo $status === 'active' ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20 hover:bg-rose-500 hover:text-white' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500 hover:text-white'; ?>">
                                                        <i
                                                            class="fa-solid <?php echo $status === 'active' ? 'fa-ban' : 'fa-circle-check'; ?> text-[10px]"></i>
                                                        <?php echo $status === 'active' ? 'Block' : 'Activate'; ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span
                                                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-slate-800 text-gray-400 text-xs font-bold cursor-not-allowed"
                                                        title="Admin accounts cannot be modified">
                                                        <i class="fa-solid fa-lock text-[10px]"></i> Locked
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-20 text-center">
                                            <div
                                                class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                                <i class="fa-solid fa-users"></i>
                                            </div>
                                            <h3 class="text-xl font-black">No users found</h3>
                                            <p class="text-sm text-gray-500 mt-2 max-w-md mx-auto">Users will appear here
                                                once accounts are created.</p>
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
                        <h3 class="font-bold">No matching users</h3>
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
                            users</span>
                        <div class="flex items-center gap-4">
                            <span class="flex items-center gap-1.5"><span
                                    class="w-2 h-2 rounded-full bg-emerald-500"></span> Active</span>
                            <span class="flex items-center gap-1.5"><span
                                    class="w-2 h-2 rounded-full bg-rose-500"></span> Blocked</span>
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

            const search = document.getElementById('userSearch');
            const roleFilter = document.getElementById('roleFilter');
            const statusFilter = document.getElementById('statusFilter');
            const noResults = document.getElementById('noFilterResults');
            const resetBtn = document.getElementById('resetFilters');
            const rows = Array.from(document.querySelectorAll('.data-row'));
            const visibleCount = document.getElementById('visibleCount');

            function applyFilters() {
                const q = (search?.value || '').trim().toLowerCase();
                const role = roleFilter?.value || 'all';
                const status = statusFilter?.value || 'all';

                let shown = 0;
                rows.forEach(row => {
                    const name = row.dataset.name || '';
                    const email = row.dataset.email || '';
                    const phone = row.dataset.phone || '';
                    const rowRole = row.dataset.role || '';
                    const rowStatus = row.dataset.status || '';

                    const matchesSearch = !q || name.includes(q) || email.includes(q) || phone.includes(q);
                    const matchesRole = role === 'all' || rowRole === role;
                    const matchesStatus = status === 'all' || rowStatus === status;

                    const show = matchesSearch && matchesRole && matchesStatus;
                    row.style.display = show ? '' : 'none';
                    if (show) shown++;
                });

                if (visibleCount) visibleCount.textContent = shown;
                if (noResults) noResults.classList.toggle('hidden', !(shown === 0 && rows.length > 0));
            }

            search?.addEventListener('input', applyFilters);
            roleFilter?.addEventListener('change', applyFilters);
            statusFilter?.addEventListener('change', applyFilters);
            resetBtn?.addEventListener('click', () => {
                if (search) search.value = '';
                if (roleFilter) roleFilter.value = 'all';
                if (statusFilter) statusFilter.value = 'all';
                applyFilters();
            });
        })();
    </script>
</body>

</html>