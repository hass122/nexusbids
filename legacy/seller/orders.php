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

/* ---------- SELLER INFO ---------- */
$s = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$s->bind_param("i", $seller_id);
$s->execute();
$seller = $s->get_result()->fetch_assoc();
$s->close();

$full_name = $seller["full_name"] ?? "Seller";
$avatar = $seller["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

/* ---------- HANDLE POST ---------- */
$flash = null;
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"])) {
    nb_csrf_check();
    $order_id = intval($_POST["order_id"] ?? 0);
    $action = $_POST["action"];

    if ($order_id <= 0) {
        $flash = ['type' => 'error', 'msg' => 'Invalid order.'];
    } else {
        $chk = $conn->prepare("SELECT order_id FROM orders WHERE order_id = ? AND seller_id = ? LIMIT 1");
        $chk->bind_param("ii", $order_id, $seller_id);
        $chk->execute();
        $valid = $chk->get_result()->num_rows > 0;
        $chk->close();

        if (!$valid) {
            $flash = ['type' => 'error', 'msg' => 'Order not found or not yours.'];
        } elseif ($action === 'deliver') {
            $upd = $conn->prepare("UPDATE orders SET order_status='delivered', shipping_status='delivered', delivered_at=NOW() WHERE order_id = ? AND seller_id = ?");
            $upd->bind_param("ii", $order_id, $seller_id);
            if ($upd->execute()) $flash = ['type' => 'success', 'msg' => 'Order marked as delivered.'];
            else $flash = ['type' => 'error', 'msg' => 'Update failed.'];
            $upd->close();
        } elseif ($action === 'ship') {
            header("Location: ship-order.php?id=" . $order_id);
            exit();
        }
    }
}

/* ---------- SIDEBAR COUNTS ---------- */
$sidebar_total   = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id")->fetch_assoc()["t"] ?? 0;
$sidebar_active  = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'active'")->fetch_assoc()["t"] ?? 0;
$sidebar_pending = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'pending'")->fetch_assoc()["t"] ?? 0;
$sidebar_drafts  = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'draft'")->fetch_assoc()["t"] ?? 0;

/* ---------- FILTERS ---------- */
$q = trim($_GET['q'] ?? '');
$status_f = trim($_GET['status'] ?? 'all');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;

$where = ["o.seller_id = ?"];
$params = [$seller_id];
$types = "i";

if ($q !== '') {
    $where[] = "(o.order_number LIKE ? OR a.title LIKE ? OR b.full_name LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
    $types .= "sss";
}

$valid_statuses = ['pending','payment_pending','paid','processing','ready_to_ship','shipped','in_transit','delivered','completed','cancelled'];
if ($status_f !== 'all' && in_array($status_f, $valid_statuses, true)) {
    $where[] = "o.order_status = ?";
    $params[] = $status_f;
    $types .= "s";
}

$where_sql = implode(' AND ', $where);

$count_sql = "SELECT COUNT(*) AS t FROM orders o
              INNER JOIN auctions a ON o.auction_id = a.auction_id
              INNER JOIN users b ON o.buyer_id = b.user_id
              WHERE $where_sql";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_orders_filtered = intval($count_stmt->get_result()->fetch_assoc()['t'] ?? 0);
$count_stmt->close();

$total_pages = max(1, (int) ceil($total_orders_filtered / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = "SELECT o.*, a.title, a.image, a.auction_id,
               b.full_name AS buyer_name, b.email AS buyer_email
        FROM orders o
        INNER JOIN auctions a ON o.auction_id = a.auction_id
        INNER JOIN users b ON o.buyer_id = b.user_id
        WHERE $where_sql
        ORDER BY o.created_at DESC
        LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- GLOBAL STATS ---------- */
$stats = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN order_status IN ('paid','processing','ready_to_ship') THEN 1 ELSE 0 END) AS to_ship,
        SUM(CASE WHEN order_status IN ('shipped','in_transit') THEN 1 ELSE 0 END) AS shipped,
        SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) AS completed,
        COALESCE(SUM(CASE WHEN order_status = 'completed' THEN seller_amount ELSE 0 END), 0) AS earned,
        COALESCE(SUM(CASE WHEN order_status IN ('paid','processing','ready_to_ship','shipped','in_transit','delivered') THEN seller_amount ELSE 0 END), 0) AS pending
    FROM orders WHERE seller_id = $seller_id
")->fetch_assoc();

$total_orders = intval($stats['total'] ?? 0);
$to_ship = intval($stats['to_ship'] ?? 0);
$earned = floatval($stats['earned'] ?? 0);
$pending_amt = floatval($stats['pending'] ?? 0);

function compact_rs($amount) {
    $amount = floatval($amount);
    if ($amount >= 10000000) return round($amount / 10000000, 1) . "Cr";
    if ($amount >= 100000)   return round($amount / 100000, 1) . "L";
    if ($amount >= 1000)     return round($amount / 1000) . "K";
    return number_format($amount);
}
function safe_date($date, $format = 'd M Y') {
    if (empty($date) || $date === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '—';
}
function order_image_url($img) {
    if (empty($img)) return '';
    if (preg_match('#^https?://#i', $img)) return $img;
    $basename = basename($img);
    $paths = ["../uploads/auctions/", "../uploads/"];
    foreach ($paths as $p) {
        if (file_exists(__DIR__ . '/' . $p . $basename)) return $p . $basename;
    }
    return "../uploads/auctions/" . rawurlencode($basename);
}
function build_url(array $overrides = []) {
    return '?' . http_build_query(array_merge($_GET, $overrides));
}

/* ---------- STATUS STYLES ---------- */
$status_styles = [
    'pending'         => ['bg'=>'bg-amber-500/10','text'=>'text-amber-700 dark:text-amber-400','label'=>'Pending','dot'=>'bg-amber-500'],
    'payment_pending' => ['bg'=>'bg-amber-500/10','text'=>'text-amber-700 dark:text-amber-400','label'=>'Payment Pending','dot'=>'bg-amber-500'],
    'paid'            => ['bg'=>'bg-blue-500/10','text'=>'text-blue-700 dark:text-blue-400','label'=>'Paid · Ready to Ship','dot'=>'bg-blue-500'],
    'processing'      => ['bg'=>'bg-indigo-500/10','text'=>'text-indigo-700 dark:text-indigo-400','label'=>'Processing','dot'=>'bg-indigo-500'],
    'ready_to_ship'   => ['bg'=>'bg-cyan-500/10','text'=>'text-cyan-700 dark:text-cyan-400','label'=>'Ready to Ship','dot'=>'bg-cyan-500'],
    'shipped'         => ['bg'=>'bg-violet-500/10','text'=>'text-violet-700 dark:text-violet-400','label'=>'Shipped','dot'=>'bg-violet-500'],
    'in_transit'      => ['bg'=>'bg-purple-500/10','text'=>'text-purple-700 dark:text-purple-400','label'=>'In Transit','dot'=>'bg-purple-500'],
    'delivered'       => ['bg'=>'bg-emerald-500/10','text'=>'text-emerald-700 dark:text-emerald-400','label'=>'Delivered','dot'=>'bg-emerald-500'],
    'completed'       => ['bg'=>'bg-emerald-500/10','text'=>'text-emerald-700 dark:text-emerald-400','label'=>'Completed','dot'=>'bg-emerald-500'],
    'cancelled'       => ['bg'=>'bg-rose-500/10','text'=>'text-rose-700 dark:text-rose-400','label'=>'Cancelled','dot'=>'bg-rose-500'],
];
$default_style = ['bg'=>'bg-gray-500/10','text'=>'text-gray-600 dark:text-gray-400','label'=>'Pending','dot'=>'bg-gray-500'];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders · Seller · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = {darkMode: 'class', theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}}}};</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; transition: background-color .3s, color .3s; }
        .glass { backdrop-filter: blur(16px) saturate(180%); }
        .data-row:hover { background-color: rgb(238 242 255 / .5); }
        .dark .data-row:hover { background-color: rgb(30 41 59 / .5); }
        .custom-select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right .75rem center; appearance: none; }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">
    <div class="flex min-h-screen">

        <?php include __DIR__ . '/../includes/seller-sidebar.php'; ?>

        <main class="flex-1 lg:ml-64 min-w-0">

            <header class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
                <div class="h-20 px-4 sm:px-8 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <button onclick="document.getElementById('sellerSidebar').classList.remove('-translate-x-full'); document.getElementById('sidebarOverlay').classList.remove('hidden')" class="lg:hidden w-10 h-10 rounded-xl bg-gray-100 dark:bg-slate-800 flex items-center justify-center">
                            <i class="fa-solid fa-bars"></i>
                        </button>
                        <div class="min-w-0">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span> Seller Panel
                            </div>
                            <h1 class="text-lg sm:text-xl font-black truncate">Orders</h1>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 sm:gap-3">
                        <button id="themeToggle" class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition-all">
                            <i class="fa-solid fa-moon dark:hidden"></i><i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                        </button>
                        <a href="export.php?type=orders" class="hidden sm:inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 bg-white dark:bg-slate-800 border border-gray-200 dark:border-gray-700 hover:border-indigo-500 rounded-xl transition-all">
                            <i class="fa-solid fa-file-csv text-emerald-500 text-xs"></i> Export
                        </a>
                    </div>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6">

                <section>
                    <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                        <a href="dashboard.php" class="hover:text-indigo-600">Dashboard</a>
                        <i class="fa-solid fa-chevron-right text-[8px]"></i>
                        <span class="text-gray-900 dark:text-white font-semibold">Orders</span>
                    </nav>
                    <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                        <span class="w-11 h-11 rounded-2xl bg-gradient-to-br from-violet-500 to-indigo-500 flex items-center justify-center text-white shadow-lg">
                            <i class="fa-solid fa-receipt text-base"></i>
                        </span>
                        My Orders
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Manage incoming orders from your sold auctions.</p>
                </section>

                <?php if ($flash): ?>
                    <div class="p-4 rounded-2xl border flex items-center gap-3 <?= $flash['type'] === 'success' ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-700 dark:text-emerald-400' : 'bg-rose-500/10 border-rose-500/30 text-rose-700 dark:text-rose-400' ?>">
                        <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                        <span class="text-sm font-semibold"><?= htmlspecialchars($flash['msg']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($_SESSION['ship_success'])): ?>
                    <div class="p-4 rounded-2xl border bg-emerald-500/10 border-emerald-500/30 text-emerald-700 dark:text-emerald-400 flex items-center gap-3">
                        <i class="fa-solid fa-circle-check"></i>
                        <span class="text-sm font-semibold"><?= htmlspecialchars($_SESSION['ship_success']); unset($_SESSION['ship_success']); ?></span>
                    </div>
                <?php endif; ?>

                <!-- Stats -->
                <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 transition-all">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Orders</span>
                            <span class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs"><i class="fa-solid fa-receipt"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($total_orders) ?></div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-amber-500 transition-all">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">To Ship</span>
                            <span class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xs"><i class="fa-solid fa-box"></i></span>
                        </div>
                        <div class="text-2xl font-black <?= $to_ship > 0 ? 'text-amber-600 dark:text-amber-400' : '' ?> tabular-nums"><?= number_format($to_ship) ?></div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-emerald-500 transition-all">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Earned</span>
                            <span class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs"><i class="fa-solid fa-coins"></i></span>
                        </div>
                        <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">Rs. <?= compact_rs($earned) ?></div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-blue-500 transition-all">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">In Progress</span>
                            <span class="w-9 h-9 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-xs"><i class="fa-solid fa-hourglass-half"></i></span>
                        </div>
                        <div class="text-2xl font-black text-blue-600 dark:text-blue-400 tabular-nums">Rs. <?= compact_rs($pending_amt) ?></div>
                    </div>
                </section>

                <!-- Orders Table -->
                <section class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">

                    <form method="GET" class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row justify-between items-center gap-4">
                        <div class="relative w-full sm:w-80">
                            <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search order #, item, or buyer..."
                                class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                            <select name="status" onchange="this.form.submit()" class="custom-select flex-1 sm:flex-initial py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                                <option value="all" <?= $status_f === 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="payment_pending" <?= $status_f === 'payment_pending' ? 'selected' : '' ?>>Payment Pending</option>
                                <option value="paid" <?= $status_f === 'paid' ? 'selected' : '' ?>>Paid · Ready to Ship</option>
                                <option value="processing" <?= $status_f === 'processing' ? 'selected' : '' ?>>Processing</option>
                                <option value="ready_to_ship" <?= $status_f === 'ready_to_ship' ? 'selected' : '' ?>>Ready to Ship</option>
                                <option value="shipped" <?= $status_f === 'shipped' ? 'selected' : '' ?>>Shipped</option>
                                <option value="in_transit" <?= $status_f === 'in_transit' ? 'selected' : '' ?>>In Transit</option>
                                <option value="delivered" <?= $status_f === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                                <option value="completed" <?= $status_f === 'completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="cancelled" <?= $status_f === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                            <button type="submit" class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold"><i class="fa-solid fa-filter"></i></button>
                            <?php if ($q !== '' || $status_f !== 'all'): ?>
                                <a href="orders.php" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 text-xs font-bold hover:bg-gray-200 dark:hover:bg-slate-700"><i class="fa-solid fa-rotate-right"></i></a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if (empty($orders)): ?>
                        <div class="p-16 text-center">
                            <div class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                            <h3 class="text-xl font-black">No orders found</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 max-w-md mx-auto">
                                <?= ($q !== '' || $status_f !== 'all') ? 'Try different search terms or filters.' : 'Orders will appear here when your auctions sell.' ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                    <tr>
                                        <th class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Order</th>
                                        <th class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Item</th>
                                        <th class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Buyer</th>
                                        <th class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Amount</th>
                                        <th class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-4 text-right text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach ($orders as $o):
                                        $status_key = strtolower($o['order_status'] ?? 'pending');
                                        $style = $status_styles[$status_key] ?? $default_style;
                                        $img_url = order_image_url($o['image'] ?? '');
                                        $order_no = $o['order_number'] ?? ('#' . $o['order_id']);
                                        $auction_id = $o['auction_id'] ?? 0;
                                        $order_id = $o['order_id'] ?? 0;
                                        $total_amount = $o['total_amount'] ?? ($o['winning_bid'] ?? 0);
                                        $seller_amount = $o['seller_amount'] ?? ($o['winning_bid'] ?? 0);
                                        $can_ship = in_array($status_key, ['paid', 'processing', 'ready_to_ship'], true);
                                    ?>
                                        <tr class="data-row">
                                            <td class="px-6 py-4">
                                                <div class="font-mono text-xs font-bold"><?= htmlspecialchars($order_no) ?></div>
                                                <div class="text-[10px] text-gray-400 mt-0.5"><?= safe_date($o['created_at'] ?? '') ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-3">
                                                    <?php if ($img_url): ?>
                                                        <img src="<?= htmlspecialchars($img_url) ?>" class="w-10 h-10 rounded-xl object-cover shrink-0 border border-gray-200 dark:border-gray-700" alt="">
                                                    <?php else: ?>
                                                        <div class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-xs shrink-0">
                                                            <i class="fa-solid fa-image"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    <a href="../auction-details.php?id=<?= (int) $auction_id ?>" class="font-semibold hover:text-indigo-600 dark:hover:text-indigo-400 line-clamp-1 max-w-[200px]">
                                                        <?= htmlspecialchars($o['title'] ?? 'Untitled') ?>
                                                    </a>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-xs font-medium"><?= htmlspecialchars($o['buyer_name'] ?? '—') ?></div>
                                                <?php if (!empty($o['buyer_email'])): ?>
                                                    <div class="text-[10px] text-gray-400"><?= htmlspecialchars($o['buyer_email']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="font-black tabular-nums">Rs. <?= number_format((float) $total_amount) ?></div>
                                                <div class="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold">You: Rs. <?= number_format((float) $seller_amount) ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $style['bg'] . ' ' . $style['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                                    <span class="w-1.5 h-1.5 rounded-full <?= $style['dot'] ?>"></span>
                                                    <?= $style['label'] ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-right">
                                                <div class="flex items-center justify-end gap-2">
                                                    <?php if ($can_ship): ?>
                                                        <a href="ship-order.php?id=<?= (int) $order_id ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition-all">
                                                            <i class="fa-solid fa-truck-fast"></i> Ship
                                                        </a>
                                                    <?php elseif ($status_key === 'shipped' || $status_key === 'in_transit'): ?>
                                                        <form method="POST" class="inline" onsubmit="return confirm('Mark this order as delivered?');">
                                                            <?php nb_csrf_field(); ?>
                                                            <input type="hidden" name="action" value="deliver">
                                                            <input type="hidden" name="order_id" value="<?= (int) $order_id ?>">
                                                            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-violet-600 hover:bg-violet-500 text-white text-xs font-bold transition-all">
                                                                <i class="fa-solid fa-check"></i> Mark Delivered
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <a href="order-details.php?id=<?= (int) $order_id ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-indigo-600 hover:text-white text-gray-700 dark:text-gray-200 text-xs font-semibold transition-all">
                                                        <i class="fa-regular fa-eye"></i> Details
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                            <span>Showing <strong class="text-gray-900 dark:text-white tabular-nums"><?= count($orders) ?></strong> of
                                <strong class="text-gray-900 dark:text-white tabular-nums"><?= number_format($total_orders_filtered) ?></strong> orders</span>
                            <?php if ($total_pages > 1): ?>
                                <nav class="flex items-center gap-1.5">
                                    <?php if ($page > 1): ?>
                                        <a href="<?= htmlspecialchars(build_url(['page' => $page - 1])) ?>" class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100 dark:hover:bg-slate-700">
                                            <i class="fa-solid fa-chevron-left text-xs"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++):
                                        $is = $p === $page; ?>
                                        <a href="<?= htmlspecialchars(build_url(['page' => $p])) ?>"
                                           class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold transition-all <?= $is ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100 dark:hover:bg-slate-700' ?>"><?= $p ?></a>
                                    <?php endfor; ?>
                                    <?php if ($page < $total_pages): ?>
                                        <a href="<?= htmlspecialchars(build_url(['page' => $page + 1])) ?>" class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100 dark:hover:bg-slate-700">
                                            <i class="fa-solid fa-chevron-right text-xs"></i>
                                        </a>
                                    <?php endif; ?>
                                </nav>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <footer class="mt-8 border-t border-gray-200 dark:border-gray-800 px-4 sm:px-8 py-6">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                    <p>© <?= date("Y") ?> NexusBids Seller Panel.</p>
                </div>
            </footer>
        </main>
    </div>

    <script>
        (function () {
            const html = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            t?.addEventListener('click', () => { html.classList.toggle('dark'); localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light') });
        })();
    </script>
<?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>