<?php
require_once "admin-auth.php";
require_once "../includes/notifications.php";

date_default_timezone_set('Asia/Karachi');

/* Admin info */
$admin_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$admin_stmt->bind_param("i", $_SESSION["user_id"]);
$admin_stmt->execute();
$admin_info = $admin_stmt->get_result()->fetch_assoc();
$admin_name = $admin_info["full_name"] ?? "Admin";
$admin_initials = strtoupper(substr(preg_replace('/\s+/', '', $admin_name), 0, 2));
$unread_notifications = nb_unread_count($conn, intval($_SESSION["user_id"]));

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
$total_winners = safe_count($conn, "SELECT COUNT(*) AS total FROM winners");
$total_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments");
$pending_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments WHERE payment_status NOT IN ('paid','refunded')");

/* Filters */
$q = trim($_GET['q'] ?? '');
$action_f = trim($_GET['action'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 30;

$where = ["1=1"];
$params = [];
$types = "";
if ($q !== '') {
    $where[] = "(u.full_name LIKE ? OR l.action LIKE ? OR l.details LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}
if ($action_f !== '') {
    $where[] = "l.action LIKE ?";
    $params[] = "%$action_f%";
    $types .= "s";
}
$where_sql = implode(' AND ', $where);

/* Count */
$total = 0;
try {
    $cs = $conn->prepare("SELECT COUNT(*) AS t FROM admin_activity_log l LEFT JOIN users u ON l.admin_id = u.user_id WHERE $where_sql");
    if ($params)
        $cs->bind_param($types, ...$params);
    $cs->execute();
    $total = intval($cs->get_result()->fetch_assoc()['t'] ?? 0);
    $cs->close();
} catch (Throwable $e) {
    $total = 0;
}

$total_pages = max(1, (int) ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

/* Fetch logs */
$logs = [];
try {
    $lq = "SELECT l.*, u.full_name AS admin_name, u.email AS admin_email
           FROM admin_activity_log l
           LEFT JOIN users u ON l.admin_id = u.user_id
           WHERE $where_sql
           ORDER BY l.created_at DESC
           LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    $ls = $conn->prepare($lq);
    if (!empty($params))
        $ls->bind_param($types, ...$params);
    $ls->execute();
    $logs = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
    $ls->close();
} catch (Throwable $e) {
    $logs = [];
}

/* Helpers */
function activity_icon($action)
{
    $a = strtolower($action);
    if (strpos($a, 'category') !== false)
        return ['fa-layer-group', 'text-violet-600 bg-violet-500/10'];
    if (strpos($a, 'auction') !== false)
        return ['fa-box', 'text-indigo-600 bg-indigo-500/10'];
    if (strpos($a, 'user') !== false || strpos($a, 'block') !== false)
        return ['fa-user', 'text-blue-600 bg-blue-500/10'];
    if (strpos($a, 'payment') !== false)
        return ['fa-credit-card', 'text-emerald-600 bg-emerald-500/10'];
    if (strpos($a, 'payout') !== false)
        return ['fa-wallet', 'text-amber-600 bg-amber-500/10'];
    if (strpos($a, 'dispute') !== false)
        return ['fa-scale-balanced', 'text-rose-600 bg-rose-500/10'];
    if (strpos($a, 'refund') !== false)
        return ['fa-rotate-left', 'text-orange-600 bg-orange-500/10'];
    if (strpos($a, 'reject') !== false || strpos($a, 'delete') !== false)
        return ['fa-xmark', 'text-rose-600 bg-rose-500/10'];
    if (strpos($a, 'approve') !== false)
        return ['fa-check', 'text-emerald-600 bg-emerald-500/10'];
    return ['fa-circle-info', 'text-gray-500 bg-gray-500/10'];
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log · NexusBids Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } } };</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body {
            transition: background-color .3s, color .3s
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%)
        }

        .custom-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right .75rem center;
            appearance: none
        }

        .data-row:hover {
            background: rgb(238 242 255/.5)
        }

        .dark .data-row:hover {
            background: rgb(30 41 59/.5)
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen">
    <div class="flex min-h-screen">

        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="flex-1 lg:ml-64 min-w-0">

            <header
                class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
                <div class="h-20 px-4 sm:px-8 flex items-center justify-between gap-4">
                    <div>
                        <div
                            class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span> System
                        </div>
                        <h1 class="text-lg sm:text-xl font-black">Activity Log</h1>
                    </div>
                    <div class="flex items-center gap-2">
                        <button id="themeToggle"
                            class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                            <i class="fa-solid fa-moon dark:hidden"></i>
                            <i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                        </button>
                        <a href="../notifications.php"
                            class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($unread_notifications > 0): ?><span
                                    class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800"></span><?php endif; ?>
                        </a>
                        <div
                            class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center">
                            <?= htmlspecialchars($admin_initials) ?></div>
                    </div>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6">

                <section>
                    <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                        <a href="index.php" class="hover:text-indigo-600">Dashboard</a>
                        <i class="fa-solid fa-chevron-right text-[8px]"></i>
                        <span class="text-gray-900 dark:text-white font-semibold">Activity Log</span>
                    </nav>
                    <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-2xl bg-gradient-to-br from-gray-600 to-slate-700 flex items-center justify-center text-white shadow-lg">
                            <i class="fa-solid fa-clock-rotate-left text-base"></i>
                        </span>
                        Activity Log
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Complete audit trail of all admin actions
                        on the platform.</p>
                </section>

                <!-- Stat cards -->
                <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Total Logs</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-gray-500/10 text-gray-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-list"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($total) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">All actions</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Today</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-bolt"></i></span>
                        </div>
                        <?php
                        $today_count = 0;
                        try {
                            $tc = $conn->query("SELECT COUNT(*) AS t FROM admin_activity_log WHERE DATE(created_at) = CURDATE()");
                            $today_count = intval($tc->fetch_assoc()['t'] ?? 0);
                        } catch (Throwable $e) {
                        }
                        ?>
                        <div class="text-2xl font-black text-indigo-600 dark:text-indigo-400 tabular-nums">
                            <?= number_format($today_count) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Past 24 hours</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">This Week</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-blue-500/10 text-blue-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-calendar-week"></i></span>
                        </div>
                        <?php
                        $week_count = 0;
                        try {
                            $wc = $conn->query("SELECT COUNT(*) AS t FROM admin_activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
                            $week_count = intval($wc->fetch_assoc()['t'] ?? 0);
                        } catch (Throwable $e) {
                        }
                        ?>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($week_count) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Last 7 days</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Admins</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-violet-500/10 text-violet-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-shield-halved"></i></span>
                        </div>
                        <?php
                        $admin_count = 0;
                        try {
                            $ac = $conn->query("SELECT COUNT(DISTINCT admin_id) AS t FROM admin_activity_log");
                            $admin_count = intval($ac->fetch_assoc()['t'] ?? 0);
                        } catch (Throwable $e) {
                        }
                        ?>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($admin_count) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Unique actors</p>
                    </div>
                </section>

                <!-- Filter bar -->
                <form method="GET"
                    class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4 flex flex-wrap gap-3 items-center">
                    <div class="relative flex-1 min-w-[200px]">
                        <i
                            class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
                            placeholder="Search admin, action, details..."
                            class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <select name="action"
                        class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm cursor-pointer">
                        <option value="">All Actions</option>
                        <option value="Category" <?= $action_f === 'Category' ? 'selected' : '' ?>>Category</option>
                        <option value="Auction" <?= $action_f === 'Auction' ? 'selected' : '' ?>>Auction</option>
                        <option value="User" <?= $action_f === 'User' ? 'selected' : '' ?>>User</option>
                        <option value="Payment" <?= $action_f === 'Payment' ? 'selected' : '' ?>>Payment</option>
                        <option value="Payout" <?= $action_f === 'Payout' ? 'selected' : '' ?>>Payout</option>
                    </select>
                    <button type="submit"
                        class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold">
                        <i class="fa-solid fa-filter mr-1"></i> Apply
                    </button>
                    <?php if ($q !== '' || $action_f !== ''): ?>
                        <a href="activity-log.php"
                            class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 text-sm font-semibold">
                            <i class="fa-solid fa-rotate-right"></i>
                        </a>
                    <?php endif; ?>
                </form>

                <!-- Logs -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <?php if (empty($logs)): ?>
                        <div class="p-16 text-center">
                            <div
                                class="w-20 h-20 mx-auto rounded-2xl bg-gray-500/10 text-gray-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </div>
                            <h3 class="text-xl font-black">No activity recorded</h3>
                            <p class="text-sm text-gray-500 mt-2">
                                <?= ($q !== '' || $action_f !== '') ? 'Try adjusting your filters.' : 'Admin actions will appear here.' ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-gray-100 dark:divide-gray-800">
                            <?php foreach ($logs as $log):
                                [$icon, $icon_cls] = activity_icon($log['action'] ?? '');
                                $admin_name = $log['admin_name'] ?? 'Unknown';
                                $admin_initials = strtoupper(substr(preg_replace('/\s+/', '', $admin_name), 0, 2));
                                $created = $log['created_at'] ?? '';
                                $ts = $created ? strtotime($created) : 0;
                                $diff = time() - $ts;
                                $time_ago = '—';
                                if ($ts) {
                                    if ($diff < 60)
                                        $time_ago = 'just now';
                                    elseif ($diff < 3600)
                                        $time_ago = intdiv($diff, 60) . 'm ago';
                                    elseif ($diff < 86400)
                                        $time_ago = intdiv($diff, 3600) . 'h ago';
                                    elseif ($diff < 604800)
                                        $time_ago = intdiv($diff, 86400) . 'd ago';
                                    else
                                        $time_ago = date('d M Y', $ts);
                                }
                                ?>
                                <div class="data-row p-5 flex items-start gap-4">
                                    <div
                                        class="w-10 h-10 rounded-xl <?= $icon_cls ?> flex items-center justify-center text-sm shrink-0">
                                        <i class="fa-solid <?= $icon ?>"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex flex-wrap items-center gap-2 mb-1">
                                            <span class="font-bold text-sm text-gray-900 dark:text-white">
                                                <?= htmlspecialchars($log['action'] ?? 'Action') ?>
                                            </span>
                                            <span class="text-gray-300 dark:text-gray-700">·</span>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                                by <?= htmlspecialchars($admin_name) ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($log['details'])): ?>
                                            <p class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed">
                                                <?= htmlspecialchars($log['details']) ?>
                                            </p>
                                        <?php endif; ?>
                                        <div class="flex items-center gap-3 mt-2 text-[10px] text-gray-400">
                                            <span><i class="fa-regular fa-clock mr-1"></i><?= $time_ago ?></span>
                                            <?php if ($created): ?>
                                                <span class="font-mono"><?= date('d M Y, h:i A', $ts) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div
                                        class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-500 text-white flex items-center justify-center text-[10px] font-bold shrink-0">
                                        <?= htmlspecialchars($admin_initials) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div
                                class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex items-center justify-center gap-1.5">
                                <?php if ($page > 1): ?>
                                    <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100">
                                        <i class="fa-solid fa-chevron-left text-xs"></i>
                                    </a>
                                <?php endif; ?>
                                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                                    <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $p]))) ?>"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold <?= $p === $page ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-slate-800' ?>"><?= $p ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100">
                                        <i class="fa-solid fa-chevron-right text-xs"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>

    <script>
        (function () {
            const h = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && matchMedia('(prefers-color-scheme: dark)').matches)) h.classList.add('dark');
            t?.addEventListener('click', () => { h.classList.toggle('dark'); localStorage.setItem('theme', h.classList.contains('dark') ? 'dark' : 'light') });
        })();
    </script>
</body>

</html>