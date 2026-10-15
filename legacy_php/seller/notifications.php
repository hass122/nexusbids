<?php

session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";

date_default_timezone_set('Asia/Karachi');

$seller_id = intval($_SESSION["user_id"]);

/* ============================================================
   Schema detection — notifications table columns
   ============================================================ */
$notif_cols = [];
$has_notif = false;
$col = [
    'id' => null,
    'user_id' => null,
    'type' => null,
    'title' => null,
    'message' => null,
    'link' => null,
    'is_read' => null,
    'created' => null,
];

try {
    $r = $conn->query("SHOW TABLES LIKE 'notifications'");
    if ($r && $r->num_rows > 0) {
        $has_notif = true;
        $cr = $conn->query("SHOW COLUMNS FROM notifications");
        while ($c = $cr->fetch_assoc())
            $notif_cols[] = $c['Field'];

        $pick = function ($cands) use ($notif_cols) {
            foreach ($cands as $x)
                if (in_array($x, $notif_cols, true))
                    return $x;
            return null;
        };
        $col['id'] = $pick(['notification_id', 'id', 'notif_id']);
        $col['user_id'] = $pick(['user_id', 'recipient_id', 'to_user_id']);
        $col['type'] = $pick(['type', 'notif_type', 'category']);
        $col['title'] = $pick(['title', 'subject', 'heading']);
        $col['message'] = $pick(['message', 'body', 'content', 'text']);
        $col['link'] = $pick(['link', 'url', 'action_url', 'target']);
        $col['is_read'] = $pick(['is_read', 'read', 'seen', 'status']);
        $col['created'] = $pick(['created_at', 'date', 'time', 'timestamp']);
    }
} catch (Throwable $e) {
    error_log("notif schema detect failed: " . $e->getMessage());
    $has_notif = false;
}

$notif_usable = $has_notif && $col['id'] && $col['user_id'] && $col['created'];

/* ============================================================
   POST actions: mark_read, mark_all_read, delete
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $notif_usable) {
    nb_csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'mark_read' && !empty($_POST['notif_id'])) {
            $nid = intval($_POST['notif_id']);
            $is_read = $col['is_read'] ?: null;
            if ($is_read === 'status') {
                $upd = $conn->prepare("UPDATE notifications SET `$is_read` = 'read' WHERE `{$col['id']}` = ? AND `{$col['user_id']}` = ?");
            } else {
                $upd = $conn->prepare("UPDATE notifications SET `$is_read` = 1 WHERE `{$col['id']}` = ? AND `{$col['user_id']}` = ?");
            }
            $upd->bind_param("ii", $nid, $seller_id);
            $upd->execute();
            $upd->close();
            $_SESSION['success'] = "Notification marked as read.";
        } elseif ($action === 'mark_all_read') {
            $is_read = $col['is_read'] ?: null;
            if ($is_read === 'status') {
                $conn->query("UPDATE notifications SET `$is_read` = 'read' WHERE `{$col['user_id']}` = $seller_id AND (`$is_read` IS NULL OR `$is_read` != 'read')");
            } else {
                $conn->query("UPDATE notifications SET `$is_read` = 1 WHERE `{$col['user_id']}` = $seller_id AND (`$is_read` IS NULL OR `$is_read` = 0)");
            }
            $_SESSION['success'] = "All notifications marked as read.";
        } elseif ($action === 'delete' && !empty($_POST['notif_id'])) {
            $nid = intval($_POST['notif_id']);
            $del = $conn->prepare("DELETE FROM notifications WHERE `{$col['id']}` = ? AND `{$col['user_id']}` = ?");
            $del->bind_param("ii", $nid, $seller_id);
            $del->execute();
            $del->close();
            $_SESSION['success'] = "Notification deleted.";
        }
    } catch (Throwable $e) {
        error_log("notif action failed: " . $e->getMessage());
        $_SESSION['error'] = "Could not update notification.";
    }
    header("Location: notifications.php");
    exit();
}

/* ============================================================
   Load notifications
   ============================================================ */
$filter = $_GET['filter'] ?? 'all';
$notifs = [];
$unread_count = 0;
$total_count = 0;

if ($notif_usable) {
    try {
        $where = ["`{$col['user_id']}` = ?"];
        $params = [$seller_id];
        $types = "i";

        if ($filter === 'unread' && $col['is_read']) {
            if ($col['is_read'] === 'status') {
                $where[] = "(`$col[is_read]` IS NULL OR `$col[is_read]` != 'read')";
            } else {
                $where[] = "(`$col[is_read]` IS NULL OR `$col[is_read]` = 0)";
            }
        }

        $where_sql = implode(' AND ', $where);

        $select_cols = "
            `{$col['id']}` AS id,
            " . ($col['type'] ? "`{$col['type']}`" : "NULL") . " AS type,
            " . ($col['title'] ? "`{$col['title']}`" : "NULL") . " AS title,
            " . ($col['message'] ? "`{$col['message']}`" : "NULL") . " AS message,
            " . ($col['link'] ? "`{$col['link']}`" : "NULL") . " AS link,
            " . ($col['is_read'] ? "`{$col['is_read']}`" : "0") . " AS is_read,
            `{$col['created']}` AS created_at
        ";

        $stmt = $conn->prepare("
            SELECT $select_cols
            FROM notifications
            WHERE $where_sql
            ORDER BY `{$col['created']}` DESC
            LIMIT 100
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $notifs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Unread count
        if ($col['is_read']) {
            if ($col['is_read'] === 'status') {
                $unread_count = intval($conn->query("SELECT COUNT(*) AS t FROM notifications WHERE `{$col['user_id']}` = $seller_id AND (`$col[is_read]` IS NULL OR `$col[is_read]` != 'read')")->fetch_assoc()['t'] ?? 0);
            } else {
                $unread_count = intval($conn->query("SELECT COUNT(*) AS t FROM notifications WHERE `{$col['user_id']}` = $seller_id AND (`$col[is_read]` IS NULL OR `$col[is_read]` = 0)")->fetch_assoc()['t'] ?? 0);
            }
        }
        $total_count = intval($conn->query("SELECT COUNT(*) AS t FROM notifications WHERE `{$col['user_id']}` = $seller_id")->fetch_assoc()['t'] ?? 0);

    } catch (Throwable $e) {
        error_log("notifs load failed: " . $e->getMessage());
        $notifs = [];
    }
}

/* Seller info + sidebar */
$s = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$s->bind_param("i", $seller_id);
$s->execute();
$seller_info = $s->get_result()->fetch_assoc();
$s->close();

$full_name = $seller_info["full_name"] ?? "Seller";
$avatar = $seller_info["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = $unread_count;

$sidebar_total = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id")->fetch_assoc()["t"] ?? 0;
$sidebar_active = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'active'")->fetch_assoc()["t"] ?? 0;
$sidebar_pending = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'pending'")->fetch_assoc()["t"] ?? 0;
$sidebar_drafts = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'draft'")->fetch_assoc()["t"] ?? 0;

/* Icon map by type */
function notif_style(string $type): array
{
    $type = strtolower($type);
    $map = [
        'bid' => ['bg-emerald-500/10', 'text-emerald-600 dark:text-emerald-400', 'fa-gavel'],
        'new_bid' => ['bg-emerald-500/10', 'text-emerald-600 dark:text-emerald-400', 'fa-gavel'],
        'bid_outbid' => ['bg-amber-500/10', 'text-amber-600 dark:text-amber-400', 'fa-arrow-trend-up'],
        'auction' => ['bg-indigo-500/10', 'text-indigo-600 dark:text-indigo-400', 'fa-box'],
        'auction_approved' => ['bg-emerald-500/10', 'text-emerald-600 dark:text-emerald-400', 'fa-circle-check'],
        'auction_rejected' => ['bg-rose-500/10', 'text-rose-600 dark:text-rose-400', 'fa-circle-xmark'],
        'auction_live' => ['bg-violet-500/10', 'text-violet-600 dark:text-violet-400', 'fa-bolt'],
        'auction_ending' => ['bg-amber-500/10', 'text-amber-600 dark:text-amber-400', 'fa-hourglass-end'],
        'auction_ended' => ['bg-gray-500/10', 'text-gray-600 dark:text-gray-400', 'fa-flag-checkered'],
        'order' => ['bg-blue-500/10', 'text-blue-600 dark:text-blue-400', 'fa-receipt'],
        'sale' => ['bg-emerald-500/10', 'text-emerald-600 dark:text-emerald-400', 'fa-hand-holding-dollar'],
        'payment' => ['bg-emerald-500/10', 'text-emerald-600 dark:text-emerald-400', 'fa-coins'],
        'shipment' => ['bg-violet-500/10', 'text-violet-600 dark:text-violet-400', 'fa-truck-fast'],
        'payout' => ['bg-amber-500/10', 'text-amber-600 dark:text-amber-400', 'fa-wallet'],
        'payout_completed' => ['bg-emerald-500/10', 'text-emerald-600 dark:text-emerald-400', 'fa-circle-check'],
        'payout_rejected' => ['bg-rose-500/10', 'text-rose-600 dark:text-rose-400', 'fa-circle-xmark'],
        'dispute' => ['bg-rose-500/10', 'text-rose-600 dark:text-rose-400', 'fa-scale-balanced'],
        'message' => ['bg-cyan-500/10', 'text-cyan-600 dark:text-cyan-400', 'fa-comment'],
        'system' => ['bg-slate-500/10', 'text-slate-600 dark:text-slate-400', 'fa-gear'],
    ];
    return $map[$type] ?? ['bg-indigo-500/10', 'text-indigo-600 dark:text-indigo-400', 'fa-bell'];
}

function time_ago_short($dt)
{
    if (empty($dt))
        return '';
    $d = time() - strtotime($dt);
    if ($d < 60)
        return "just now";
    if ($d < 3600)
        return intdiv($d, 60) . "m ago";
    if ($d < 86400)
        return intdiv($d, 3600) . "h ago";
    if ($d < 604800)
        return intdiv($d, 86400) . "d ago";
    return date("d M Y", strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = {darkMode: 'class', theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}}}};</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
            transition: background-color .3s, color .3s
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%)
        }

        .notif-card {
            transition: all .2s ease
        }

        .notif-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04)
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">
    <div class="flex min-h-screen">

        <?php include __DIR__ . '/../includes/seller-sidebar.php'; ?>

        <main class="flex-1 lg:ml-64 min-w-0">

            <header
                class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
                <div class="h-20 px-4 sm:px-8 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <button
                            onclick="document.getElementById('sellerSidebar').classList.remove('-translate-x-full'); document.getElementById('sidebarOverlay').classList.remove('hidden')"
                            class="lg:hidden w-10 h-10 rounded-xl bg-gray-100 dark:bg-slate-800 flex items-center justify-center">
                            <i class="fa-solid fa-bars"></i>
                        </button>
                        <div class="min-w-0">
                            <div
                                class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span> Seller Panel
                            </div>
                            <h1 class="text-lg sm:text-xl font-black truncate">Notifications</h1>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button id="themeToggle"
                            class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition-all">
                            <i class="fa-solid fa-moon dark:hidden"></i><i
                                class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                        </button>
                    </div>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-4xl mx-auto">

                <?php if (!empty($_SESSION['success'])): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30">
                        <i class="fa-solid fa-circle-check text-emerald-600 dark:text-emerald-400 mt-1"></i>
                        <p class="text-sm text-emerald-700 dark:text-emerald-300 font-medium self-center">
                            <?= htmlspecialchars($_SESSION['success']);
                            unset($_SESSION['success']); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if (!empty($_SESSION['error'])): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30">
                        <i class="fa-solid fa-circle-exclamation text-rose-600 dark:text-rose-400 mt-1"></i>
                        <p class="text-sm text-rose-700 dark:text-rose-300 font-medium self-center">
                            <?= htmlspecialchars($_SESSION['error']);
                            unset($_SESSION['error']); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Header -->
                <section class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                            <span
                                class="w-11 h-11 rounded-2xl bg-gradient-to-br from-violet-500 to-indigo-500 flex items-center justify-center text-white shadow-lg">
                                <i class="fa-regular fa-bell text-base"></i>
                            </span>
                            Notifications
                        </h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Auction updates, bids, orders, and
                            payouts — all here.</p>
                    </div>
                    <?php if ($notif_usable && $unread_count > 0): ?>
                        <form method="POST">
                            <?php nb_csrf_field(); ?>
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit"
                                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold shadow-lg shadow-indigo-600/30 transition-all">
                                <i class="fa-solid fa-check-double text-xs"></i> Mark All Read
                            </button>
                        </form>
                    <?php endif; ?>
                </section>

                <?php if (!$notif_usable): ?>

                    <div class="p-8 rounded-3xl bg-amber-500/10 border border-amber-500/30">
                        <div class="flex items-start gap-3">
                            <i class="fa-solid fa-triangle-exclamation text-amber-500 text-xl mt-1"></i>
                            <div class="flex-1">
                                <h3 class="font-bold text-lg">Notifications table schema mismatch</h3>
                                <p class="text-sm text-gray-700 dark:text-gray-300 mt-1">
                                    The notifications table is either missing or has unexpected columns.
                                </p>
                                <?php if (!empty($notif_cols)): ?>
                                    <div
                                        class="mt-3 p-3 rounded-xl bg-white/50 dark:bg-slate-900/50 border border-amber-500/20">
                                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-2">Columns
                                            found:</div>
                                        <div class="flex flex-wrap gap-1.5">
                                            <?php foreach ($notif_cols as $c): ?>
                                                <span
                                                    class="px-2 py-0.5 rounded bg-white dark:bg-slate-800 border border-gray-200 dark:border-gray-700 text-[11px] font-mono">
                                                    <?= htmlspecialchars($c) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <p class="text-xs text-gray-600 dark:text-gray-400 mt-3">
                                    Expected columns (or their aliases): <code
                                        class="font-mono text-[11px] px-1 bg-black/5 dark:bg-white/10 rounded">notification_id</code>,
                                    <code
                                        class="font-mono text-[11px] px-1 bg-black/5 dark:bg-white/10 rounded">user_id</code>,
                                    <code
                                        class="font-mono text-[11px] px-1 bg-black/5 dark:bg-white/10 rounded">type</code>,
                                    <code
                                        class="font-mono text-[11px] px-1 bg-black/5 dark:bg-white/10 rounded">title</code>,
                                    <code
                                        class="font-mono text-[11px] px-1 bg-black/5 dark:bg-white/10 rounded">message</code>,
                                    <code
                                        class="font-mono text-[11px] px-1 bg-black/5 dark:bg-white/10 rounded">is_read</code>,
                                    <code
                                        class="font-mono text-[11px] px-1 bg-black/5 dark:bg-white/10 rounded">created_at</code>
                                </p>
                            </div>
                        </div>
                    </div>

                <?php else: ?>

                    <!-- Filter Tabs -->
                    <section class="flex flex-wrap gap-2">
                        <?php
                        $tabs = [
                            'all' => ['All', $total_count],
                            'unread' => ['Unread', $unread_count],
                        ];
                        foreach ($tabs as $key => [$label, $count]):
                            $is = $filter === $key;
                            ?>
                            <a href="?filter=<?= $key ?>"
                                class="px-4 py-2 rounded-xl text-xs font-bold flex items-center gap-2 transition-all <?= $is ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:border-indigo-500' ?>">
                                <?= $label ?>
                                <span
                                    class="text-[10px] tabular-nums px-1.5 py-0.5 rounded <?= $is ? 'bg-white/20' : 'bg-gray-100 dark:bg-slate-800' ?>">
                                    <?= $count ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </section>

                    <!-- Notifications List -->
                    <?php if (empty($notifs)): ?>
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-16 text-center">
                            <div
                                class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-violet-500/10 to-indigo-500/10 text-violet-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-regular fa-bell"></i>
                            </div>
                            <h3 class="text-xl font-black">No notifications</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 max-w-md mx-auto">
                                <?= $filter === 'unread' ? 'You\'re all caught up!' : 'Updates about your auctions, bids, orders, and payouts will appear here.' ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-2">
                            <?php foreach ($notifs as $n):
                                $type = strtolower($n['type'] ?? 'general');
                                [$bg, $txt, $icon] = notif_style($type);
                                $is_read = !empty($n['is_read']) && ($n['is_read'] === 'read' || $n['is_read'] === 1 || $n['is_read'] === '1');
                                $link = !empty($n['link']) ? $n['link'] : null;
                                $title = $n['title'] ?? 'Notification';
                                $msg = $n['message'] ?? '';
                                ?>
                                <div
                                    class="notif-card group bg-white dark:bg-slate-900 rounded-2xl border <?= $is_read ? 'border-gray-200 dark:border-gray-800' : 'border-indigo-500/30 bg-indigo-50/30 dark:bg-indigo-950/20' ?> p-4 sm:p-5 relative">
                                    <?php if (!$is_read): ?>
                                        <span class="absolute top-4 right-4 w-2 h-2 rounded-full bg-indigo-500 animate-pulse"></span>
                                    <?php endif; ?>

                                    <div class="flex items-start gap-4">
                                        <div
                                            class="w-11 h-11 rounded-xl <?= $bg ?> <?= $txt ?> flex items-center justify-center text-base shrink-0">
                                            <i class="fa-solid <?= $icon ?>"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-start justify-between gap-2 mb-1">
                                                <div class="font-bold text-sm text-gray-900 dark:text-white">
                                                    <?= htmlspecialchars($title) ?>
                                                </div>
                                                <div class="text-[10px] text-gray-400 whitespace-nowrap">
                                                    <?= time_ago_short($n['created_at'] ?? '') ?>
                                                </div>
                                            </div>
                                            <?php if ($msg): ?>
                                                <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
                                                    <?= nl2br(htmlspecialchars($msg)) ?>
                                                </p>
                                            <?php endif; ?>
                                            <div class="flex items-center gap-3 mt-3 flex-wrap">
                                                <?php if ($link): ?>
                                                    <a href="../<?= htmlspecialchars($link) ?>"
                                                        class="inline-flex items-center gap-1.5 text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline">
                                                        <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i> View
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (!$is_read): ?>
                                                    <form method="POST" class="inline">
                                                        <?php nb_csrf_field(); ?>
                                                        <input type="hidden" name="action" value="mark_read">
                                                        <input type="hidden" name="notif_id" value="<?= (int) $n['id'] ?>">
                                                        <button type="submit"
                                                            class="text-[11px] font-bold text-gray-500 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400">
                                                            <i class="fa-solid fa-check text-[10px]"></i> Mark read
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" class="inline ml-auto"
                                                    onsubmit="return confirm('Delete this notification?');">
                                                    <?php nb_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="notif_id" value="<?= (int) $n['id'] ?>">
                                                    <button type="submit"
                                                        class="text-[11px] font-bold text-gray-400 hover:text-rose-500 opacity-0 group-hover:opacity-100 transition-opacity">
                                                        <i class="fa-solid fa-trash text-[10px]"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <footer class="mt-8 border-t border-gray-200 dark:border-gray-800 px-4 sm:px-8 py-6">
                <div
                    class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                    <p>©
                        <?= date("Y") ?> NexusBids Seller Panel.
                    </p>
                </div>
            </footer>
        </main>
    </div>

    <script>
        (function () {
            const html = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            t?.addEventListener('click', () => {html.classList.toggle('dark'); localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light')});
        })();
    </script>
<?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>