<?php

session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/activity.php";

date_default_timezone_set('Asia/Karachi');

$seller_id = intval($_SESSION["user_id"]);

/* ---------- [NEW] BULK ACTION HANDLER ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    nb_csrf_check();
    $ids = array_filter(array_map('intval', (array) ($_POST['auction_ids'] ?? [])));
    $bulk = $_POST['bulk_action'] ?? '';

    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids) + 1);
        $params = array_merge([$seller_id], $ids);

        if ($bulk === 'cancel') {
            $sql = "UPDATE auctions SET auction_status='cancelled', cancelled_at=NOW()
                    WHERE seller_id = ? AND auction_id IN ($in)
                    AND auction_status NOT IN ('completed','ended','cancelled','rejected','sold')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $msg = $stmt->affected_rows . " auction(s) cancelled.";
            $stmt->close();
        } elseif ($bulk === 'relist') {
            // find candidates
            $sql = "SELECT * FROM auctions WHERE seller_id = ? AND auction_id IN ($in)
                    AND auction_status IN ('ended','completed','rejected','cancelled','unsold')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $count = 0;
            foreach ($rows as $row) {
                $new_start = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                $new_end = date('Y-m-d H:i:s', strtotime('+7 days'));
                $ins = $conn->prepare("INSERT INTO auctions
                    (seller_id, category_id, title, description, starting_price, current_bid,
                     bid_increment, start_time, end_time, auction_status, image, relisted_from)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
                $ins->bind_param(
                    "iissdddsssi",
                    $seller_id,
                    $row['category_id'],
                    $row['title'],
                    $row['description'],
                    $row['starting_price'],
                    $row['starting_price'],
                    $row['bid_increment'],
                    $new_start,
                    $new_end,
                    $row['image'],
                    $row['auction_id']
                );
                if ($ins->execute())
                    $count++;
                $ins->close();
            }
            $msg = "$count auction(s) relisted.";
        } elseif ($bulk === 'archive') {
            // Archive = set to 'unsold' (or you can add 'archived' status if desired)
            $sql = "UPDATE auctions SET auction_status='unsold'
                    WHERE seller_id = ? AND auction_id IN ($in)
                    AND auction_status IN ('ended','cancelled','rejected')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $msg = $stmt->affected_rows . " auction(s) archived.";
            $stmt->close();
        } elseif ($bulk === 'export') {
            header("Location: export.php?type=auctions&ids=" . implode(',', $ids));
            exit();
        }
        nb_log_activity($conn, $seller_id, 'auction.bulk_' . $bulk, $msg ?? '');
        $_SESSION['success'] = $msg ?? 'Bulk action completed.';
    }
    header("Location: my-auctions.php");
    exit();
}

$sidebar_active_page = 'my-auctions';
include __DIR__ . '/../includes/seller-sidebar.php';

/* Seller info */
$user_stmt = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $seller_id);
$user_stmt->execute();
$user_info = $user_stmt->get_result()->fetch_assoc();
$full_name = $user_info["full_name"] ?? "Seller";
$avatar = $user_info["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

/* ---------- SIDEBAR COUNTS ---------- */
$sidebar_total = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id")->fetch_assoc()["t"] ?? 0;
$sidebar_active = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'active'")->fetch_assoc()["t"] ?? 0;
$sidebar_pending = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'pending'")->fetch_assoc()["t"] ?? 0;
$sidebar_drafts = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'draft'")->fetch_assoc()["t"] ?? 0;

/* ---------- AGGREGATES ---------- */
$agg = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN auction_status = 'draft'      THEN 1 ELSE 0 END) AS draft_count,
        SUM(CASE WHEN auction_status = 'pending'    THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN auction_status = 'scheduled'  THEN 1 ELSE 0 END) AS scheduled_count,
        SUM(CASE WHEN auction_status IN ('active','approved') THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN auction_status = 'sold'       THEN 1 ELSE 0 END) AS sold_count,
        SUM(CASE WHEN auction_status = 'unsold'     THEN 1 ELSE 0 END) AS unsold_count,
        SUM(CASE WHEN auction_status IN ('completed','ended') THEN 1 ELSE 0 END) AS ended_count,
        SUM(CASE WHEN auction_status IN ('rejected','cancelled') THEN 1 ELSE 0 END) AS inactive_count
    FROM auctions WHERE seller_id = $seller_id
")->fetch_assoc();

$total_count = intval($agg['total'] ?? 0);
$draft_count = intval($agg['draft_count'] ?? 0);
$pending_count = intval($agg['pending_count'] ?? 0);
$scheduled_count = intval($agg['scheduled_count'] ?? 0);
$active_count = intval($agg['active_count'] ?? 0);
$sold_count = intval($agg['sold_count'] ?? 0);
$unsold_count = intval($agg['unsold_count'] ?? 0);
$completed_count = intval($agg['ended_count'] ?? 0);
$inactive_count = intval($agg['inactive_count'] ?? 0);

/* ---------- FILTERS (GET) ---------- */
$q = trim($_GET['q'] ?? '');
$status_f = trim($_GET['status'] ?? 'all');
$cat_f = intval($_GET['category'] ?? 0);
$min_price = floatval($_GET['min_price'] ?? 0);
$max_price = floatval($_GET['max_price'] ?? 0);
$date_f = trim($_GET['date'] ?? '');
$sort_f = trim($_GET['sort'] ?? 'newest');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 15;

$allowed_status = ['all', 'draft', 'pending', 'scheduled', 'active', 'approved', 'ended', 'completed', 'sold', 'unsold', 'rejected', 'cancelled'];
if (!in_array($status_f, $allowed_status, true))
    $status_f = 'all';

$order_by = match ($sort_f) {
    'oldest' => 'a.created_at ASC',
    'bids-high' => 'bid_count DESC, a.created_at DESC',
    'bids-low' => 'bid_count ASC, a.created_at DESC',
    'price-high' => 'a.current_bid DESC, a.starting_price DESC',
    'price-low' => 'a.current_bid ASC, a.starting_price ASC',
    'views-high' => 'a.views_count DESC',
    default => 'a.created_at DESC',
};

/* All categories for filter dropdown */
$all_categories = $conn->query("SELECT category_id, category_name FROM categories WHERE status='active' ORDER BY category_name")->fetch_all(MYSQLI_ASSOC);

/* ---------- BUILD WHERE ---------- */
$where = ["a.seller_id = ?"];
$params = [$seller_id];
$types = "i";

if ($q !== '') {
    $where[] = "(a.title LIKE ? OR c.category_name LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}
if ($status_f !== 'all') {
    if ($status_f === 'active')
        $where[] = "a.auction_status IN ('active','approved')";
    elseif ($status_f === 'ended')
        $where[] = "a.auction_status IN ('completed','ended')";
    elseif ($status_f === 'sold')
        $where[] = "a.auction_status IN ('sold','completed')";
    elseif ($status_f === 'unsold')
        $where[] = "a.auction_status = 'unsold'";
    else {
        $where[] = "a.auction_status = ?";
        $params[] = $status_f;
        $types .= "s";
    }
}
if ($cat_f > 0) {
    $where[] = "a.category_id = ?";
    $params[] = $cat_f;
    $types .= "i";
}
if ($min_price > 0) {
    $where[] = "COALESCE(NULLIF(a.current_bid,0), a.starting_price) >= ?";
    $params[] = $min_price;
    $types .= "d";
}
if ($max_price > 0) {
    $where[] = "COALESCE(NULLIF(a.current_bid,0), a.starting_price) <= ?";
    $params[] = $max_price;
    $types .= "d";
}
if ($date_f === '7d')
    $where[] = "a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
if ($date_f === '30d')
    $where[] = "a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

$where_sql = implode(' AND ', $where);

/* Count */
$count_sql = "SELECT COUNT(*) AS t FROM auctions a
              INNER JOIN categories c ON a.category_id = c.category_id
              WHERE $where_sql";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$filtered_total = intval($count_stmt->get_result()->fetch_assoc()['t'] ?? 0);
$count_stmt->close();

$total_pages = max(1, (int) ceil($filtered_total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

/* Fetch */
$sql = "SELECT a.*, c.category_name,
            (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.auction_id) AS bid_count,
            (SELECT COUNT(DISTINCT bidder_id) FROM bids b WHERE b.auction_id = a.auction_id) AS unique_bidders
        FROM auctions a
        INNER JOIN categories c ON a.category_id = c.category_id
        WHERE $where_sql
        ORDER BY $order_by
        LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$auctions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Status styles */
$status_styles = [
    'draft' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'dot' => 'bg-gray-500', 'label' => 'Draft', 'icon' => 'fa-pen'],
    'active' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'dot' => 'bg-emerald-500', 'label' => 'Active', 'icon' => 'fa-bolt'],
    'approved' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'dot' => 'bg-emerald-500', 'label' => 'Approved', 'icon' => 'fa-circle-check'],
    'pending' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'dot' => 'bg-amber-500', 'label' => 'Pending', 'icon' => 'fa-clock'],
    'scheduled' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'dot' => 'bg-blue-500', 'label' => 'Scheduled', 'icon' => 'fa-calendar'],
    'completed' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'dot' => 'bg-blue-500', 'label' => 'Completed', 'icon' => 'fa-trophy'],
    'sold' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'dot' => 'bg-emerald-500', 'label' => 'Sold', 'icon' => 'fa-hand-holding-dollar'],
    'unsold' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'dot' => 'bg-gray-500', 'label' => 'Unsold', 'icon' => 'fa-circle-minus'],
    'ended' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-700 dark:text-gray-400', 'dot' => 'bg-gray-500', 'label' => 'Ended', 'icon' => 'fa-flag-checkered'],
    'rejected' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'dot' => 'bg-rose-500', 'label' => 'Rejected', 'icon' => 'fa-xmark'],
    'cancelled' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'dot' => 'bg-rose-500', 'label' => 'Cancelled', 'icon' => 'fa-ban'],
    'relisted' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'dot' => 'bg-violet-500', 'label' => 'Relisted', 'icon' => 'fa-rotate'],
];
$default_status = $status_styles['ended'];

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
function build_url(array $overrides = [])
{
    return '?' . http_build_query(array_merge($_GET, $overrides));
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Auctions | NexusBids</title>
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

        .data-row:hover {
            background-color: rgb(238 242 255/.5)
        }

        .dark .data-row:hover {
            background-color: rgb(30 41 59/.5)
        }

        .custom-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right .75rem center;
            appearance: none
        }

        .tab-pill.active {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            box-shadow: 0 10px 20px -5px rgb(99 102 241 / .4)
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
                    <div>
                        <div
                            class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span> Seller Panel
                        </div>
                        <h1 class="text-lg sm:text-xl font-black truncate">My Auctions</h1>
                    </div>
                    <div class="flex items-center gap-2">
                        <button id="themeToggle"
                            class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                            <i class="fa-solid fa-moon dark:hidden"></i><i
                                class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                        </button>
                        <a href="create-auction.php"
                            class="hidden sm:inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl">
                            <i class="fa-solid fa-plus text-xs"></i> Create
                        </a>
                    </div>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6">

                <section>
                    <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                        <a href="dashboard.php" class="hover:text-indigo-600">Dashboard</a>
                        <i class="fa-solid fa-chevron-right text-[8px]"></i>
                        <span class="text-gray-900 dark:text-white font-semibold">My Auctions</span>
                    </nav>
                    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                        <div>
                            <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                                <span
                                    class="w-11 h-11 rounded-2xl bg-gradient-to-br from-violet-500 to-indigo-500 flex items-center justify-center text-white shadow-lg">
                                    <i class="fa-solid fa-box text-base"></i>
                                </span>
                                My Auctions
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Manage, edit, duplicate, or cancel
                                listings.</p>
                        </div>
                        <div class="flex gap-2">
                            <a href="export.php?type=auctions"
                                class="px-4 py-2.5 rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 text-sm font-semibold hover:border-indigo-500 flex items-center gap-2">
                                <i class="fa-solid fa-file-csv text-emerald-500"></i> Export CSV
                            </a>
                            <a href="create-auction.php"
                                class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold shadow-lg shadow-indigo-600/30 flex items-center gap-2">
                                <i class="fa-solid fa-plus"></i> New Auction
                            </a>
                        </div>
                    </div>
                </section>

                <?php if (!empty($_SESSION["success"])): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30">
                        <i class="fa-solid fa-circle-check text-emerald-600 dark:text-emerald-400 mt-1"></i>
                        <p class="text-sm text-emerald-700 dark:text-emerald-300 font-medium self-center">
                            <?= htmlspecialchars($_SESSION["success"]);
                            unset($_SESSION["success"]); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if (!empty($_SESSION["error"])): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30">
                        <i class="fa-solid fa-circle-exclamation text-rose-600 dark:text-rose-400 mt-1"></i>
                        <p class="text-sm text-rose-700 dark:text-rose-300 font-medium self-center">
                            <?= htmlspecialchars($_SESSION["error"]);
                            unset($_SESSION["error"]); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- [NEW] Quick filter tabs -->
                <section class="flex flex-wrap gap-2">
                    <?php
                    $tabs = [
                        'all' => ['All', $total_count],
                        'draft' => ['Drafts', $draft_count],
                        'pending' => ['Pending', $pending_count],
                        'scheduled' => ['Scheduled', $scheduled_count],
                        'active' => ['Active', $active_count],
                        'sold' => ['Sold', $sold_count],
                        'unsold' => ['Unsold', $unsold_count],
                        'ended' => ['Ended', $completed_count],
                        'rejected' => ['Rejected', intval($conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status='rejected'")->fetch_assoc()['t'] ?? 0)],
                    ];
                    foreach ($tabs as $key => [$label, $count]):
                        $active = $status_f === $key;
                        ?>
                        <a href="<?= htmlspecialchars(build_url(['status' => $key, 'page' => 1])) ?>"
                            class="tab-pill <?= $active ? 'active' : 'bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:border-indigo-500' ?> px-4 py-2 rounded-xl text-xs font-bold flex items-center gap-2 transition-all">
                            <?= $label ?>
                            <span
                                class="text-[10px] tabular-nums px-1.5 py-0.5 rounded <?= $active ? 'bg-white/20' : 'bg-gray-100 dark:bg-slate-800' ?>"><?= $count ?></span>
                        </a>
                    <?php endforeach; ?>
                </section>

                <!-- [NEW] Advanced filter form -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <form method="GET"
                        class="p-5 border-b border-gray-100 dark:border-gray-800 grid grid-cols-1 md:grid-cols-6 gap-3">
                        <div class="md:col-span-2 relative">
                            <i
                                class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search auctions..."
                                class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <select name="status"
                            class="custom-select py-2.5 pl-3 pr-8 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                            <option value="all">All Status</option>
                            <?php foreach ($allowed_status as $s):
                                if ($s === 'all')
                                    continue; ?>
                                <option value="<?= $s ?>" <?= $status_f === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="category"
                            class="custom-select py-2.5 pl-3 pr-8 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                            <option value="0">All Categories</option>
                            <?php foreach ($all_categories as $c): ?>
                                <option value="<?= (int) $c['category_id'] ?>" <?= $cat_f === (int) $c['category_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="min_price" value="<?= $min_price ?: '' ?>" placeholder="Min PKR"
                            class="px-3 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm tabular-nums">
                        <input type="number" name="max_price" value="<?= $max_price ?: '' ?>" placeholder="Max PKR"
                            class="px-3 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm tabular-nums">
                        <div class="md:col-span-6 flex flex-wrap items-center gap-2">
                            <select name="date"
                                class="custom-select py-2.5 pl-3 pr-8 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                                <option value="">All Time</option>
                                <option value="7d" <?= $date_f === '7d' ? 'selected' : '' ?>>Last 7 Days</option>
                                <option value="30d" <?= $date_f === '30d' ? 'selected' : '' ?>>Last 30 Days</option>
                            </select>
                            <select name="sort"
                                class="custom-select py-2.5 pl-3 pr-8 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                                <option value="newest" <?= $sort_f === 'newest' ? 'selected' : '' ?>>Newest First</option>
                                <option value="oldest" <?= $sort_f === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                                <option value="bids-high" <?= $sort_f === 'bids-high' ? 'selected' : '' ?>>Most Bids</option>
                                <option value="price-high" <?= $sort_f === 'price-high' ? 'selected' : '' ?>>Highest Price
                                </option>
                                <option value="price-low" <?= $sort_f === 'price-low' ? 'selected' : '' ?>>Lowest Price
                                </option>
                                <option value="views-high" <?= $sort_f === 'views-high' ? 'selected' : '' ?>>Most Views
                                </option>
                            </select>
                            <button type="submit"
                                class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold">
                                <i class="fa-solid fa-filter mr-1"></i> Apply
                            </button>
                            <?php if ($q || $status_f !== 'all' || $cat_f || $min_price || $max_price || $date_f): ?>
                                <a href="my-auctions.php"
                                    class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 text-xs font-bold hover:bg-gray-200 dark:hover:bg-slate-700">
                                    <i class="fa-solid fa-rotate-right mr-1"></i> Reset
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <!-- [NEW] Bulk actions bar (shows when items are checked) -->
                    <form method="POST" id="bulkForm">
                        <?php nb_csrf_field(); ?>
                        <input type="hidden" name="bulk_action" id="bulkActionInput" value="">

                        <div id="bulkBar"
                            class="hidden px-5 py-3 border-b border-gray-100 dark:border-gray-800 bg-indigo-50 dark:bg-indigo-950/30 flex flex-wrap items-center gap-3">
                            <span class="text-xs font-bold"><span id="bulkCount">0</span> selected</span>
                            <button type="button" onclick="submitBulk('cancel')"
                                class="px-3 py-1.5 rounded-lg bg-rose-500 hover:bg-rose-600 text-white text-xs font-bold">
                                <i class="fa-solid fa-ban mr-1"></i> Cancel
                            </button>
                            <button type="button" onclick="submitBulk('relist')"
                                class="px-3 py-1.5 rounded-lg bg-violet-500 hover:bg-violet-600 text-white text-xs font-bold">
                                <i class="fa-solid fa-rotate mr-1"></i> Relist
                            </button>
                            <button type="button" onclick="submitBulk('archive')"
                                class="px-3 py-1.5 rounded-lg bg-gray-500 hover:bg-gray-600 text-white text-xs font-bold">
                                <i class="fa-solid fa-box-archive mr-1"></i> Archive
                            </button>
                            <button type="button" onclick="submitBulk('export')"
                                class="px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold">
                                <i class="fa-solid fa-file-csv mr-1"></i> Export
                            </button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                    <tr>
                                        <th class="px-4 py-4 w-10"><input type="checkbox" id="selectAll"
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        </th>
                                        <th
                                            class="px-4 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Auction</th>
                                        <th
                                            class="px-4 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Price</th>
                                        <th
                                            class="px-4 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Perf</th>
                                        <th
                                            class="px-4 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Status</th>
                                        <th
                                            class="px-4 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Ends</th>
                                        <th
                                            class="px-4 py-4 text-right text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php if (!empty($auctions)): ?>
                                        <?php foreach ($auctions as $auction):
                                            $status = strtolower($auction['auction_status'] ?? 'ended');
                                            $style = $status_styles[$status] ?? $default_status;
                                            $bid_count = intval($auction['bid_count'] ?? 0);
                                            $unique_bidders = intval($auction['unique_bidders'] ?? 0);
                                            $views = intval($auction['views_count'] ?? 0);
                                            $watchers = intval($auction['watchlist_count'] ?? 0);
                                            $current = floatval($auction['current_bid']) > 0 ? floatval($auction['current_bid']) : floatval($auction['starting_price']);
                                            $end_ts = strtotime($auction['end_time'] ?? '');
                                            $can_edit = $bid_count === 0 && in_array($status, ['draft', 'pending', 'scheduled', 'active', 'approved'], true);
                                            $can_cancel = $can_edit && $status !== 'draft';
                                            $can_delete = $status === 'draft';
                                            $can_relist = in_array($status, ['ended', 'completed', 'rejected', 'cancelled', 'unsold', 'sold'], true);
                                            $reject_note = trim($auction['rejection_reason'] ?? '');
                                            ?>
                                            <tr class="data-row">
                                                <td class="px-4 py-4">
                                                    <input type="checkbox" name="auction_ids[]"
                                                        value="<?= (int) $auction['auction_id'] ?>"
                                                        class="row-check rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                </td>
                                                <td class="px-4 py-4">
                                                    <div class="flex items-center gap-3">
                                                        <?php if (!empty($auction['image'])): ?>
                                                            <img src="../uploads/auctions/<?= htmlspecialchars($auction['image']) ?>"
                                                                class="w-11 h-11 rounded-xl object-cover border border-gray-200 dark:border-gray-700 shrink-0"
                                                                alt="">
                                                        <?php else: ?>
                                                            <div
                                                                class="w-11 h-11 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-sm shrink-0">
                                                                <i class="fa-solid fa-image"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div class="min-w-0">
                                                            <a href="../auction-details.php?id=<?= (int) $auction['auction_id'] ?>"
                                                                class="font-semibold hover:text-indigo-600 line-clamp-1 max-w-[220px] block">
                                                                <?= htmlspecialchars($auction['title']) ?>
                                                            </a>
                                                            <div class="flex items-center gap-2 mt-0.5">
                                                                <span
                                                                    class="text-[10px] text-gray-400 font-mono">#<?= str_pad((int) $auction['auction_id'], 5, '0', STR_PAD_LEFT) ?></span>
                                                                <span class="text-[10px] text-gray-400">·</span>
                                                                <span
                                                                    class="text-[10px] text-gray-500"><?= htmlspecialchars($auction['category_name']) ?></span>
                                                            </div>
                                                            <?php if ($status === 'rejected' && $reject_note !== ''): ?>
                                                                <div class="mt-1 text-[10px] text-rose-600 dark:text-rose-400 line-clamp-1"
                                                                    title="<?= htmlspecialchars($reject_note) ?>">
                                                                    <i class="fa-solid fa-circle-info"></i>
                                                                    <?= htmlspecialchars($reject_note) ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <div class="text-xs font-black tabular-nums">Rs.
                                                        <?= number_format($current) ?></div>
                                                    <div
                                                        class="text-[10px] <?= floatval($auction['current_bid']) > 0 ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-gray-400' ?>">
                                                        <?= floatval($auction['current_bid']) > 0 ? 'Current bid' : 'Starting' ?>
                                                    </div>
                                                </td>
                                                <!-- [NEW] Performance mini-metrics -->
                                                <td class="px-4 py-4">
                                                    <div class="grid grid-cols-3 gap-2 text-center">
                                                        <div>
                                                            <div class="text-[9px] text-gray-400 uppercase tracking-wider">Views
                                                            </div>
                                                            <div class="text-xs font-black tabular-nums">
                                                                <?= number_format($views) ?></div>
                                                        </div>
                                                        <div>
                                                            <div class="text-[9px] text-gray-400 uppercase tracking-wider">Watch
                                                            </div>
                                                            <div class="text-xs font-black tabular-nums">
                                                                <?= number_format($watchers) ?></div>
                                                        </div>
                                                        <div>
                                                            <div class="text-[9px] text-gray-400 uppercase tracking-wider">Bids
                                                            </div>
                                                            <div class="text-xs font-black tabular-nums">
                                                                <?= number_format($bid_count) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <span
                                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $style['bg'] . ' ' . $style['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                                        <span
                                                            class="w-1.5 h-1.5 rounded-full <?= $style['dot'] ?> <?= in_array($status, ['active', 'approved']) ? 'animate-pulse' : '' ?>"></span>
                                                        <?= $style['label'] ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <?php if ($end_ts): ?>
                                                        <div class="text-xs font-medium"><?= date('d M Y', $end_ts) ?></div>
                                                        <div class="text-[10px] text-gray-400">
                                                            <?= time_ago_short($auction['end_time']) ?></div>
                                                    <?php else: ?>
                                                        <span class="text-xs text-gray-400">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-4 text-right">
                                                    <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                                        <?php if ($status === 'draft'): ?>
                                                            <a href="create-auction.php?draft=<?= (int) $auction['auction_id'] ?>"
                                                                class="inline-flex items-center gap-1 px-2.5 py-2 rounded-lg bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/20 hover:bg-amber-500 hover:text-white text-xs font-bold transition-all"
                                                                title="Resume Draft">
                                                                <i class="fa-solid fa-pen text-[10px]"></i> Resume
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($can_edit && $status !== 'draft'): ?>
                                                            <a href="edit-auction.php?id=<?= (int) $auction['auction_id'] ?>"
                                                                class="inline-flex items-center gap-1 px-2.5 py-2 rounded-lg bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 border border-indigo-500/20 hover:bg-indigo-600 hover:text-white text-xs font-bold transition-all"
                                                                title="Edit">
                                                                <i class="fa-solid fa-pen text-[10px]"></i>
                                                            </a>
                                                        <?php endif; ?>

                                                        <!-- [NEW] Duplicate button -->
                                                        <a href="duplicate-auction.php?id=<?= (int) $auction['auction_id'] ?>"
                                                            class="inline-flex items-center gap-1 px-2.5 py-2 rounded-lg bg-cyan-500/10 text-cyan-700 dark:text-cyan-400 border border-cyan-500/20 hover:bg-cyan-600 hover:text-white text-xs font-bold transition-all"
                                                            title="Duplicate">
                                                            <i class="fa-solid fa-copy text-[10px]"></i>
                                                        </a>

                                                        <?php if ($can_cancel): ?>
                                                            <a href="cancel-auction.php?id=<?= (int) $auction['auction_id'] ?>"
                                                                class="inline-flex items-center gap-1 px-2.5 py-2 rounded-lg bg-rose-500/10 text-rose-700 dark:text-rose-400 border border-rose-500/20 hover:bg-rose-500 hover:text-white text-xs font-bold transition-all"
                                                                title="Cancel">
                                                                <i class="fa-solid fa-ban text-[10px]"></i>
                                                            </a>
                                                        <?php endif; ?>

                                                        <?php if ($can_relist): ?>
                                                            <a href="relist-auction.php?id=<?= (int) $auction['auction_id'] ?>"
                                                                class="inline-flex items-center gap-1 px-2.5 py-2 rounded-lg bg-violet-500/10 text-violet-700 dark:text-violet-400 border border-violet-500/20 hover:bg-violet-600 hover:text-white text-xs font-bold transition-all"
                                                                title="Relist">
                                                                <i class="fa-solid fa-rotate text-[10px]"></i>
                                                            </a>
                                                        <?php endif; ?>

                                                        <!-- [NEW] Analytics link -->
                                                        <a href="auction-analytics.php?id=<?= (int) $auction['auction_id'] ?>"
                                                            class="inline-flex items-center gap-1 px-2.5 py-2 rounded-lg bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20 hover:bg-emerald-600 hover:text-white text-xs font-bold transition-all"
                                                            title="Analytics">
                                                            <i class="fa-solid fa-chart-line text-[10px]"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="px-6 py-20 text-center">
                                                <div
                                                    class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                                    <i class="fa-solid fa-box-open"></i>
                                                </div>
                                                <h3 class="text-xl font-black">No auctions found</h3>
                                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Try adjusting
                                                    filters or create a new auction.</p>
                                                <a href="create-auction.php"
                                                    class="inline-flex mt-6 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold items-center gap-2">
                                                    <i class="fa-solid fa-plus"></i> Create Auction
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div
                            class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                            <span>Showing <strong
                                    class="text-gray-900 dark:text-white tabular-nums"><?= count($auctions) ?></strong>
                                of
                                <strong
                                    class="text-gray-900 dark:text-white tabular-nums"><?= number_format($filtered_total) ?></strong>
                                auctions</span>
                            <?php if ($total_pages > 1): ?>
                                <nav class="flex items-center gap-1.5">
                                    <?php if ($page > 1): ?>
                                        <a href="<?= htmlspecialchars(build_url(['page' => $page - 1])) ?>"
                                            class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100 dark:hover:bg-slate-700">
                                            <i class="fa-solid fa-chevron-left text-xs"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++):
                                        $is = $p === $page; ?>
                                        <a href="<?= htmlspecialchars(build_url(['page' => $p])) ?>"
                                            class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold transition-all <?= $is ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100 dark:hover:bg-slate-700' ?>"><?= $p ?></a>
                                    <?php endfor; ?>
                                    <?php if ($page < $total_pages): ?>
                                        <a href="<?= htmlspecialchars(build_url(['page' => $page + 1])) ?>"
                                            class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100 dark:hover:bg-slate-700">
                                            <i class="fa-solid fa-chevron-right text-xs"></i>
                                        </a>
                                    <?php endif; ?>
                                </nav>
                            <?php endif; ?>
                        </div>
                    </form>
                </section>
            </div>
        </main>
    </div>

    <script>
        (function () {
            const html = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            t?.addEventListener('click', () => {html.classList.toggle('dark'); localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light')});

            // Bulk select logic
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.row-check');
            const bulkBar = document.getElementById('bulkBar');
            const bulkCount = document.getElementById('bulkCount');

            function updateBulkBar() {
                const n = document.querySelectorAll('.row-check:checked').length;
                bulkCount.textContent = n;
                bulkBar.classList.toggle('hidden', n === 0);
            }
            selectAll?.addEventListener('change', () => {
                checkboxes.forEach(c => c.checked = selectAll.checked);
                updateBulkBar();
            });
            checkboxes.forEach(c => c.addEventListener('change', updateBulkBar));

            window.submitBulk = function (action) {
                const n = document.querySelectorAll('.row-check:checked').length;
                if (n === 0) return;
                const labels = {cancel: 'cancel', relist: 'relist', archive: 'archive', export: 'export'};
                if (action !== 'export' && !confirm(`Are you sure you want to ${labels[action]} ${n} auction(s)?`)) return;
                document.getElementById('bulkActionInput').value = action;
                document.getElementById('bulkForm').submit();
            };
        })();
    </script>
<?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>