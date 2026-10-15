<?php


session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}
if ($_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/notifications.php";

date_default_timezone_set('Asia/Karachi');

$seller_id = intval($_SESSION["user_id"]);

/* ---------- SELLER INFO ---------- */
$user_stmt = $conn->prepare("SELECT full_name, email, avatar, kyc_status, seller_kyc_status, phone_verified, email_verified, bank_verified, address_verified FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $seller_id);
$user_stmt->execute();
$user_info = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

$full_name = $user_info["full_name"] ?? "Seller";
$avatar = $user_info["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

/* ---------- AGGREGATE STATS ---------- */
$stats = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN auction_status = 'draft'      THEN 1 ELSE 0 END) AS draft_count,
        SUM(CASE WHEN auction_status = 'active'     THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN auction_status = 'pending'    THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN auction_status = 'scheduled'  THEN 1 ELSE 0 END) AS scheduled_count,
        SUM(CASE WHEN auction_status = 'sold'       THEN 1 ELSE 0 END) AS sold_count,
        SUM(CASE WHEN auction_status = 'unsold'     THEN 1 ELSE 0 END) AS unsold_count,
        SUM(CASE WHEN auction_status IN ('completed','ended') THEN 1 ELSE 0 END) AS completed_count
    FROM auctions WHERE seller_id = $seller_id
")->fetch_assoc();

$total_auctions = intval($stats['total'] ?? 0);
$draft_auctions = intval($stats['draft_count'] ?? 0);
$active_auctions = intval($stats['active_count'] ?? 0);
$pending_auctions = intval($stats['pending_count'] ?? 0);
$scheduled_auctions = intval($stats['scheduled_count'] ?? 0);
$sold_auctions = intval($stats['sold_count'] ?? 0);
$unsold_auctions = intval($stats['unsold_count'] ?? 0);
$completed_auctions = intval($stats['completed_count'] ?? 0);

/* Sidebar counts */
$sidebar_total = $total_auctions;
$sidebar_active = $active_auctions;
$sidebar_pending = $pending_auctions;
$sidebar_drafts = $draft_auctions;

/* ---------- BIDS ---------- */
$total_bids = intval($conn->query("
    SELECT COUNT(*) AS t FROM bids b
    INNER JOIN auctions a ON b.auction_id = a.auction_id
    WHERE a.seller_id = $seller_id
")->fetch_assoc()['t'] ?? 0);

/* ---------- EARNINGS ---------- */
$total_earnings = floatval($conn->query("
    SELECT COALESCE(SUM(w.winning_bid), 0) AS t FROM winners w
    INNER JOIN auctions a ON w.auction_id = a.auction_id
    WHERE a.seller_id = $seller_id
")->fetch_assoc()['t'] ?? 0);

$pending_payments = intval($conn->query("
    SELECT COUNT(*) AS t FROM winners w
    INNER JOIN auctions a ON w.auction_id = a.auction_id
    WHERE a.seller_id = $seller_id
      AND (w.status IS NULL OR w.status != 'paid')
")->fetch_assoc()['t'] ?? 0);

/* ---------- REVENUE ANALYTICS ---------- */
$revenue = $conn->query("
    SELECT
        COALESCE(SUM(CASE WHEN DATE(o.created_at) = CURDATE() THEN o.seller_amount ELSE 0 END), 0) AS today,
        COALESCE(SUM(CASE WHEN o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN o.seller_amount ELSE 0 END), 0) AS week,
        COALESCE(SUM(CASE WHEN o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN o.seller_amount ELSE 0 END), 0) AS month,
        COALESCE(SUM(o.seller_amount), 0) AS all_time
    FROM orders o
    WHERE o.seller_id = $seller_id AND o.order_status IN ('completed','delivered','shipped','paid','processing')
")->fetch_assoc();

$revenue_today = floatval($revenue['today'] ?? 0);
$revenue_week = floatval($revenue['week'] ?? 0);
$revenue_month = floatval($revenue['month'] ?? 0);
$revenue_all_time = floatval($revenue['all_time'] ?? 0);

/* Monthly revenue chart */
$revenue_chart = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $row = $conn->query("SELECT COALESCE(SUM(seller_amount), 0) AS t FROM orders
                         WHERE seller_id = $seller_id
                           AND DATE_FORMAT(created_at,'%Y-%m') = '$month'
                           AND order_status IN ('completed','delivered','shipped','paid','processing')
                        ")->fetch_assoc();
    $revenue_chart[] = ['label' => date('M', strtotime("$month-01")), 'value' => floatval($row['t'] ?? 0)];
}
$max_revenue = max(1, max(array_column($revenue_chart, 'value')));

/* ---------- ENDING SOON ---------- */
$ending_soon = $conn->query("
    SELECT a.auction_id, a.title, a.image, a.end_time, a.current_bid, a.starting_price,
           (SELECT COUNT(*) FROM bids WHERE auction_id = a.auction_id) AS bid_count
    FROM auctions a
    WHERE a.seller_id = $seller_id
      AND a.auction_status = 'active'
      AND a.end_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
    ORDER BY a.end_time ASC
    LIMIT 4
")->fetch_all(MYSQLI_ASSOC);

/* ---------- REJECTED AUCTIONS ---------- */
$rejected = $conn->query("
    SELECT auction_id, title, rejection_reason
    FROM auctions
    WHERE seller_id = $seller_id AND auction_status = 'rejected'
    ORDER BY created_at DESC
    LIMIT 3
")->fetch_all(MYSQLI_ASSOC);

/* ---------- RECENT AUCTIONS ---------- */
$recent_auctions = $conn->query("
    SELECT a.*,
        (SELECT COUNT(*) FROM bids WHERE auction_id = a.auction_id) AS bid_count
    FROM auctions a
    WHERE a.seller_id = $seller_id
    ORDER BY a.created_at DESC
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

/* ---------- WEEKLY BIDS ---------- */
$week_bids = $conn->query("
    SELECT DATE(b.bid_time) AS day, COUNT(*) AS total
    FROM bids b INNER JOIN auctions a ON b.auction_id = a.auction_id
    WHERE a.seller_id = $seller_id AND b.bid_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(b.bid_time) ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);
$max_bids = 1;
foreach ($week_bids as $d)
    $max_bids = max($max_bids, intval($d['total']));

/* ---------- RECENT ORDERS ---------- */
$recent_orders = $conn->query("
    SELECT o.order_id, o.order_number, o.order_status, o.total_amount, o.created_at,
           a.title, a.image,
           b.full_name AS buyer_name
    FROM orders o
    INNER JOIN auctions a ON o.auction_id = a.auction_id
    INNER JOIN users b ON o.buyer_id = b.user_id
    WHERE o.seller_id = $seller_id
    ORDER BY o.created_at DESC
    LIMIT 4
")->fetch_all(MYSQLI_ASSOC);

/* ---------- RECENT BIDS ---------- */
$recent_bids = $conn->query("
    SELECT b.bid_amount, b.bid_time, b.bidder_id,
           a.auction_id, a.title AS auction_title, a.image AS auction_image
    FROM bids b
    INNER JOIN auctions a ON b.auction_id = a.auction_id
    WHERE a.seller_id = $seller_id
    ORDER BY b.bid_time DESC
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

// Seller KYC status
$seller_kyc_status = $user_info['seller_kyc_status'] ?? 'unverified';
$kyc_steps = [
    'email' => !empty($user_info['email_verified']),
    'phone' => !empty($user_info['phone_verified']),
    'identity' => ($seller_kyc_status === 'approved'),
    'bank' => !empty($user_info['bank_verified']),
    'address' => !empty($user_info['address_verified']),
];
$kyc_completed = count(array_filter($kyc_steps));
$kyc_pct = round(($kyc_completed / 5) * 100);
$kyc_hide = $kyc_pct >= 100;

/* ---------- HELPERS ---------- */
$status_styles = [
    'draft' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'dot' => 'bg-gray-500', 'label' => 'Draft'],
    'active' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'dot' => 'bg-emerald-500', 'label' => 'Active'],
    'approved' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'dot' => 'bg-emerald-500', 'label' => 'Approved'],
    'pending' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'dot' => 'bg-amber-500', 'label' => 'Pending'],
    'scheduled' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'dot' => 'bg-blue-500', 'label' => 'Scheduled'],
    'completed' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'dot' => 'bg-blue-500', 'label' => 'Completed'],
    'sold' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'dot' => 'bg-emerald-500', 'label' => 'Sold'],
    'unsold' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'dot' => 'bg-gray-500', 'label' => 'Unsold'],
    'ended' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-700 dark:text-gray-400', 'dot' => 'bg-gray-500', 'label' => 'Ended'],
    'rejected' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'dot' => 'bg-rose-500', 'label' => 'Rejected'],
    'cancelled' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'dot' => 'bg-rose-500', 'label' => 'Cancelled'],
    'relisted' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'dot' => 'bg-violet-500', 'label' => 'Relisted'],
];
$default_status = $status_styles['ended'];

$order_styles = [
    'pending' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'dot' => 'bg-amber-500', 'label' => 'Pending'],
    'payment_pending' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'dot' => 'bg-amber-500', 'label' => 'Payment Pending'],
    'paid' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'dot' => 'bg-blue-500', 'label' => 'To Ship'],
    'processing' => ['bg' => 'bg-indigo-500/10', 'text' => 'text-indigo-700 dark:text-indigo-400', 'dot' => 'bg-indigo-500', 'label' => 'Processing'],
    'ready_to_ship' => ['bg' => 'bg-cyan-500/10', 'text' => 'text-cyan-700 dark:text-cyan-400', 'dot' => 'bg-cyan-500', 'label' => 'Ready to Ship'],
    'shipped' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'dot' => 'bg-violet-500', 'label' => 'Shipped'],
    'in_transit' => ['bg' => 'bg-purple-500/10', 'text' => 'text-purple-700 dark:text-purple-400', 'dot' => 'bg-purple-500', 'label' => 'In Transit'],
    'delivered' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'dot' => 'bg-emerald-500', 'label' => 'Delivered'],
    'completed' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'dot' => 'bg-emerald-500', 'label' => 'Completed'],
];

function compact_rs($a)
{
    $a = floatval($a);
    if ($a >= 10000000)
        return round($a / 10000000, 1) . "Cr";
    if ($a >= 100000)
        return round($a / 100000, 1) . "L";
    if ($a >= 1000)
        return round($a / 1000) . "K";
    return number_format($a);
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
    <title>Seller Dashboard | NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = {darkMode: 'class', theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}, animation: {'fade-up': 'fadeUp .6s ease-out forwards'}, keyframes: {fadeUp: {'0%': {opacity: '0', transform: 'translateY(16px)'}, '100%': {opacity: '1', transform: 'translateY(0)'}}}}};</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
            transition: background-color .3s, color .3s;
            font-feature-settings: 'cv02', 'cv03', 'cv04', 'cv11';
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
        }

        .data-row {
            transition: background-color .2s ease;
        }

        .data-row:hover {
            background-color: rgb(238 242 255 / .5);
        }

        .dark .data-row:hover {
            background-color: rgb(30 41 59 / .5);
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
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">
    <div class="flex min-h-screen">

        <?php
        $sidebar_active_page = 'dashboard';
        include __DIR__ . '/../includes/seller-sidebar.php';
        ?>

        <main class="flex-1 lg:ml-64 min-w-0">

            <!-- Topbar -->
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
                            <h1 class="text-lg sm:text-xl font-black truncate">Dashboard Overview</h1>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 sm:gap-3">
                        <button id="themeToggle"
                            class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition-all">
                            <i class="fa-solid fa-moon dark:hidden"></i><i
                                class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                        </button>
                        <a href="../notifications.php"
                            class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition-all">
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($unread_notifications > 0): ?>
                                <span
                                    class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800 animate-pulse"></span>
                            <?php endif; ?>
                        </a>
                        <a href="create-auction.php"
                            class="hidden sm:inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all hover:shadow-lg hover:shadow-indigo-600/30">
                            <i class="fa-solid fa-plus text-xs"></i> Create
                        </a>
                    </div>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6">

                <!-- REJECTION ALERTS -->
                <?php if (!empty($rejected)): ?>
                    <?php foreach ($rejected as $rej): ?>
                        <div
                            class="flex items-start gap-4 p-5 rounded-2xl bg-rose-500/10 border border-rose-500/30 animate-fade-up">
                            <div
                                class="w-11 h-11 rounded-xl bg-rose-500/20 text-rose-600 dark:text-rose-400 flex items-center justify-center text-lg shrink-0">
                                <i class="fa-solid fa-circle-xmark"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-bold text-rose-900 dark:text-rose-300 text-sm">
                                    Auction Rejected: <?= htmlspecialchars($rej['title']) ?>
                                </h3>
                                <?php if (!empty($rej['rejection_reason'])): ?>
                                    <p class="text-xs text-rose-800/80 dark:text-rose-300/80 mt-1 leading-relaxed">
                                        <strong>Reason:</strong> <?= htmlspecialchars($rej['rejection_reason']) ?>
                                    </p>
                                <?php else: ?>
                                    <p class="text-xs text-rose-800/80 dark:text-rose-300/80 mt-1">The administrator has rejected
                                        this listing. Please review and relist if needed.</p>
                                <?php endif; ?>
                                <div class="flex flex-wrap gap-2 mt-3">
                                    <a href="relist-auction.php?id=<?= (int) $rej['auction_id'] ?>"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold transition-all">
                                        <i class="fa-solid fa-rotate text-[10px]"></i> Relist
                                    </a>
                                    <a href="my-auctions.php?status=rejected"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-rose-500/30 text-rose-700 dark:text-rose-400 text-xs font-bold transition-all hover:bg-rose-50 dark:hover:bg-rose-950/30">
                                        <i class="fa-solid fa-list text-[10px]"></i> View All Rejected
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- [NEW] KYC / ONBOARDING PROGRESS -->
                <?php if (!$kyc_hide): ?>
                    <section
                        class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6 animate-fade-up">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <span
                                    class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-shield-halved"></i>
                                </span>
                                <div>
                                    <h3 class="font-bold text-sm">Complete Your Seller Profile</h3>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400"><?= $kyc_pct ?>% complete</p>
                                </div>
                            </div>
                            <a href="<?= in_array($seller_kyc_status, ['unverified', 'rejected'], true) ? 'kyc.php' : 'kyc-status.php' ?>"
                                class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline">
                                <?= in_array($seller_kyc_status, ['unverified', 'rejected'], true) ? 'Start KYC →' : 'View Status →' ?>
                            </a>
                        </div>
                        <div class="h-2 rounded-full bg-gray-100 dark:bg-slate-800 overflow-hidden mb-4">
                            <div class="h-full rounded-full bg-gradient-to-r from-violet-500 to-indigo-500 transition-all duration-700"
                                style="width:<?= $kyc_pct ?>%"></div>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                            <?php foreach ([
                                'email' => 'Email',
                                'phone' => 'Phone',
                                'identity' => 'Identity',
                                'bank' => 'Bank',
                                'address' => 'Address',
                            ] as $k => $label):
                                $ok = $kyc_steps[$k]; ?>
                                <div class="flex items-center gap-2 text-xs">
                                    <i
                                        class="fa-solid <?= $ok ? 'fa-circle-check text-emerald-500' : 'fa-circle text-gray-300 dark:text-slate-700' ?>"></i>
                                    <span
                                        class="<?= $ok ? 'text-gray-700 dark:text-gray-200 font-semibold' : 'text-gray-400' ?>"><?= $label ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- Welcome Hero -->
                <section
                    class="relative overflow-hidden rounded-3xl bg-slate-950 text-white p-6 sm:p-8 shadow-2xl border border-gray-800 noise-overlay animate-fade-up">
                    <div
                        class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-violet-900/40 via-slate-950 to-slate-950">
                    </div>
                    <div
                        class="absolute -right-16 -top-16 w-80 h-80 bg-violet-600/20 rounded-full blur-3xl pointer-events-none">
                    </div>
                    <div
                        class="absolute left-1/4 bottom-0 w-64 h-64 bg-indigo-600/10 rounded-full blur-3xl pointer-events-none">
                    </div>

                    <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                        <div class="space-y-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[11px] font-bold uppercase tracking-wider">
                                    <span class="relative flex h-2 w-2">
                                        <span
                                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-400"></span>
                                    </span>
                                    Store Active
                                </span>
                                <?php if ($pending_auctions > 0): ?>
                                    <span
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-400 text-[11px] font-bold uppercase tracking-wider">
                                        <i class="fa-solid fa-clock text-[9px]"></i>
                                        <?= $pending_auctions ?> Pending Review
                                    </span>
                                <?php endif; ?>
                                <?php if ($draft_auctions > 0): ?>
                                    <span
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-slate-500/20 border border-slate-500/30 text-slate-300 text-[11px] font-bold uppercase tracking-wider">
                                        <i class="fa-solid fa-pen text-[9px]"></i>
                                        <?= $draft_auctions ?> Drafts
                                    </span>
                                <?php endif; ?>
                            </div>
                            <h2 class="text-2xl sm:text-3xl lg:text-4xl font-black tracking-tight leading-tight">
                                Welcome back, <span
                                    class="gradient-text bg-gradient-to-r from-violet-400 via-indigo-400 to-blue-400"><?= htmlspecialchars(explode(' ', $full_name)[0]) ?></span>
                            </h2>
                            <p class="text-gray-300 text-sm max-w-xl leading-relaxed">Manage auctions, track bids, ship
                                orders, and monitor earnings.</p>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <a href="create-auction.php"
                                class="group px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition-all hover:-translate-y-0.5 shadow-lg shadow-indigo-600/30 flex items-center gap-2">
                                <i class="fa-solid fa-plus"></i> New Auction
                                <i
                                    class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform"></i>
                            </a>
                            <a href="orders.php"
                                class="px-5 py-3 rounded-xl bg-slate-800/80 hover:bg-slate-700/80 text-white text-sm font-semibold border border-gray-700 hover:border-gray-600 transition-all hover:-translate-y-0.5 flex items-center gap-2">
                                <i class="fa-solid fa-receipt"></i> Orders
                            </a>
                        </div>
                    </div>
                </section>

                <!-- Stats Grid -->
                <section class="grid grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4 animate-fade-up">
                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 hover:shadow-lg hover:shadow-indigo-500/10 transition-all hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span
                                class="text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs group-hover:scale-110 transition-transform"><i
                                    class="fa-solid fa-box"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($total_auctions) ?></div>
                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">All listings</p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-emerald-500 hover:shadow-lg hover:shadow-emerald-500/10 transition-all hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span
                                class="text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Active</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs group-hover:scale-110 transition-transform"><i
                                    class="fa-solid fa-bolt"></i></span>
                        </div>
                        <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                            <?= number_format($active_auctions) ?>
                        </div>
                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>Live now
                        </p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border <?= $pending_auctions > 0 ? 'border-amber-500/40' : 'border-gray-200 dark:border-gray-800' ?> hover:shadow-lg hover:shadow-amber-500/10 transition-all hover:-translate-y-0.5 relative overflow-hidden">
                        <?php if ($pending_auctions > 0): ?>
                            <div class="absolute top-2 right-2 w-2 h-2 rounded-full bg-amber-500 animate-pulse"></div>
                        <?php endif; ?>
                        <div class="flex items-center justify-between mb-3">
                            <span
                                class="text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Pending</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xs group-hover:scale-110 transition-transform"><i
                                    class="fa-solid fa-clock"></i></span>
                        </div>
                        <div
                            class="text-2xl font-black <?= $pending_auctions > 0 ? 'text-amber-600 dark:text-amber-400' : '' ?> tabular-nums">
                            <?= number_format($pending_auctions) ?>
                        </div>
                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">
                            <?= $pending_auctions > 0 ? 'Needs review' : 'All clear' ?>
                        </p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-violet-500 hover:shadow-lg hover:shadow-violet-500/10 transition-all hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span
                                class="text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Bids</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-xs group-hover:scale-110 transition-transform"><i
                                    class="fa-solid fa-gavel"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($total_bids) ?></div>
                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">Total received</p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-amber-500 hover:shadow-lg hover:shadow-amber-500/10 transition-all hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span
                                class="text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Earnings</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xs group-hover:scale-110 transition-transform"><i
                                    class="fa-solid fa-coins"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums">Rs. <?= compact_rs($total_earnings) ?></div>
                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">From winners</p>
                    </div>

                    <div
                        class="group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-rose-500 hover:shadow-lg hover:shadow-rose-500/10 transition-all hover:-translate-y-0.5">
                        <div class="flex items-center justify-between mb-3">
                            <span
                                class="text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Payments</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-xs group-hover:scale-110 transition-transform"><i
                                    class="fa-solid fa-hourglass-half"></i></span>
                        </div>
                        <div
                            class="text-2xl font-black <?= $pending_payments > 0 ? 'text-rose-600 dark:text-rose-400' : '' ?> tabular-nums">
                            <?= number_format($pending_payments) ?>
                        </div>
                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">
                            <?= $pending_payments > 0 ? 'Awaiting buyers' : 'All settled' ?>
                        </p>
                    </div>
                </section>

                <!-- [NEW] REVENUE ANALYTICS -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6 animate-fade-up">
                    <div class="flex items-center justify-between mb-5">
                        <div class="flex items-center gap-3">
                            <span
                                class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-chart-line"></i>
                            </span>
                            <div>
                                <h2 class="text-base font-bold">Revenue Analytics</h2>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">Last 6 months performance</p>
                            </div>
                        </div>
                        <a href="payouts.php"
                            class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline">Payouts →</a>
                    </div>

                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                        <div
                            class="p-4 rounded-xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">Today</div>
                            <div class="text-lg font-black tabular-nums">Rs. <?= compact_rs($revenue_today) ?></div>
                        </div>
                        <div
                            class="p-4 rounded-xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">This Week
                            </div>
                            <div class="text-lg font-black tabular-nums">Rs. <?= compact_rs($revenue_week) ?></div>
                        </div>
                        <div
                            class="p-4 rounded-xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">This Month
                            </div>
                            <div class="text-lg font-black tabular-nums">Rs. <?= compact_rs($revenue_month) ?></div>
                        </div>
                        <div class="p-4 rounded-xl bg-indigo-500/10 border border-indigo-500/20">
                            <div
                                class="text-[10px] font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400 mb-1">
                                All Time</div>
                            <div class="text-lg font-black text-indigo-700 dark:text-indigo-300 tabular-nums">Rs.
                                <?= compact_rs($revenue_all_time) ?>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-end justify-between gap-2 h-40">
                        <?php foreach ($revenue_chart as $rc):
                            $h = ($rc['value'] / $max_revenue) * 100;
                            ?>
                            <div class="flex-1 flex flex-col items-center gap-2">
                                <div class="text-[10px] font-black tabular-nums">
                                    <?= $rc['value'] > 0 ? 'Rs.' . compact_rs($rc['value']) : '—' ?>
                                </div>
                                <div class="w-full rounded-t-lg bg-gradient-to-t from-indigo-500 to-violet-500 transition-all duration-700"
                                    style="height:<?= max(4, $h) ?>%; min-height:8px;"></div>
                                <div class="text-[10px] font-bold text-gray-500"><?= $rc['label'] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- [NEW] ENDING SOON -->
                <?php if (!empty($ending_soon)): ?>
                    <section
                        class="bg-white dark:bg-slate-900 rounded-3xl border border-amber-500/30 shadow-sm overflow-hidden animate-fade-up">
                        <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span
                                    class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm animate-pulse">
                                    <i class="fa-solid fa-hourglass-end"></i>
                                </span>
                                <div>
                                    <h2 class="text-base font-bold">Ending Soon</h2>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">Auctions ending within 24 hours
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 p-5">
                            <?php foreach ($ending_soon as $es):
                                $secs = strtotime($es['end_time']) - time();
                                $h = intdiv($secs, 3600);
                                $m = intdiv($secs % 3600, 60);
                                $current = floatval($es['current_bid']) > 0 ? floatval($es['current_bid']) : floatval($es['starting_price']);
                                ?>
                                <a href="auction-analytics.php?id=<?= (int) $es['auction_id'] ?>"
                                    class="block group rounded-2xl border border-gray-200 dark:border-gray-800 hover:border-amber-500 overflow-hidden transition-all hover:-translate-y-0.5">
                                    <div class="aspect-video bg-gray-100 dark:bg-slate-800 overflow-hidden">
                                        <?php if (!empty($es['image'])): ?>
                                            <img src="../uploads/auctions/<?= htmlspecialchars($es['image']) ?>"
                                                class="w-full h-full object-cover group-hover:scale-105 transition-transform"
                                                alt="">
                                        <?php endif; ?>
                                    </div>
                                    <div class="p-4">
                                        <div class="font-bold text-xs line-clamp-2 mb-2"><?= htmlspecialchars($es['title']) ?>
                                        </div>
                                        <div
                                            class="text-[10px] text-amber-600 dark:text-amber-400 font-black uppercase tracking-wider mb-1">
                                            <i class="fa-solid fa-clock"></i> <?= $h ?>h <?= $m ?>m left
                                        </div>
                                        <div class="flex items-center justify-between text-[11px]">
                                            <span class="text-gray-500">Bid:</span>
                                            <span class="font-black tabular-nums">Rs. <?= number_format($current) ?></span>
                                        </div>
                                        <div class="text-[10px] text-gray-400 mt-1"><?= (int) $es['bid_count'] ?> bids</div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- Recent Auctions + Sidebar Column -->
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 animate-fade-up">

                    <!-- Recent Auctions -->
                    <div
                        class="xl:col-span-2 bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                        <div
                            class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                            <div class="flex items-center gap-3">
                                <span
                                    class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                </span>
                                <div>
                                    <h2 class="text-base font-bold">Recent Auctions</h2>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Your latest listings</p>
                                </div>
                            </div>
                            <a href="my-auctions.php"
                                class="group inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition-all">
                                View All <i
                                    class="fa-solid fa-arrow-right text-[10px] group-hover:translate-x-0.5 transition-transform"></i>
                            </a>
                        </div>

                        <?php if (!empty($recent_auctions)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead
                                        class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                        <tr>
                                            <th
                                                class="px-6 py-3 text-left text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                Auction</th>
                                            <th
                                                class="px-6 py-3 text-left text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                Bids</th>
                                            <th
                                                class="px-6 py-3 text-left text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                Status</th>
                                            <th
                                                class="px-6 py-3 text-right text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                Ends</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                        <?php foreach ($recent_auctions as $a):
                                            $st = strtolower($a['auction_status'] ?? 'ended');
                                            $style = $status_styles[$st] ?? $default_status;
                                            $end_ts = strtotime($a['end_time'] ?? '');
                                            ?>
                                            <tr class="data-row">
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center gap-3">
                                                        <?php if (!empty($a['image'])): ?>
                                                            <img src="../uploads/auctions/<?= htmlspecialchars($a['image']) ?>"
                                                                class="w-10 h-10 rounded-xl object-cover border border-gray-200 dark:border-gray-700 shrink-0"
                                                                alt="">
                                                        <?php else: ?>
                                                            <div
                                                                class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-xs shrink-0">
                                                                <i class="fa-solid fa-image"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div class="min-w-0">
                                                            <a href="edit-auction.php?id=<?= (int) $a['auction_id'] ?>"
                                                                class="font-semibold hover:text-indigo-600 dark:hover:text-indigo-400 line-clamp-1 max-w-[220px] block">
                                                                <?= htmlspecialchars($a['title']) ?>
                                                            </a>
                                                            <span
                                                                class="text-[10px] text-gray-400 font-mono">#<?= str_pad((int) $a['auction_id'], 5, '0', STR_PAD_LEFT) ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <span
                                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-700 dark:text-violet-400 text-[11px] font-bold">
                                                        <i
                                                            class="fa-solid fa-gavel text-[9px]"></i><?= intval($a['bid_count']) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <span
                                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $style['bg'] . ' ' . $style['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                                        <span class="w-1.5 h-1.5 rounded-full <?= $style['dot'] ?>"></span>
                                                        <?= $style['label'] ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 text-right">
                                                    <?php if ($end_ts): ?>
                                                        <div class="text-xs font-medium"><?= date('d M Y', $end_ts) ?></div>
                                                        <div class="text-[10px] text-gray-400"><?= time_ago_short($a['end_time']) ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-xs text-gray-400">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="p-12 text-center">
                                <div
                                    class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                    <i class="fa-solid fa-box-open"></i>
                                </div>
                                <h3 class="font-bold text-lg">No auctions yet</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 max-w-sm mx-auto">Create your first
                                    auction to start selling on NexusBids.</p>
                                <a href="create-auction.php"
                                    class="inline-flex mt-6 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold items-center gap-2">
                                    <i class="fa-solid fa-plus"></i> Create Auction
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right Column -->
                    <div class="space-y-6">

                        <!-- Recent Orders -->
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div
                                class="p-5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
                                <div class="flex items-center gap-3">
                                    <span
                                        class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                                        <i class="fa-solid fa-receipt"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-sm font-bold">Recent Orders</h2>
                                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">Latest sales</p>
                                    </div>
                                </div>
                                <a href="orders.php"
                                    class="text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline">View
                                    All</a>
                            </div>

                            <?php if (!empty($recent_orders)): ?>
                                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach ($recent_orders as $o):
                                        $ost = strtolower($o['order_status'] ?? 'pending');
                                        $os = $order_styles[$ost] ?? ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'dot' => 'bg-gray-500', 'label' => 'Pending'];
                                        ?>
                                        <a href="order-details.php?id=<?= (int) $o['order_id'] ?>"
                                            class="block p-4 hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors">
                                            <div class="flex items-center gap-3">
                                                <?php if (!empty($o['image'])): ?>
                                                    <img src="../uploads/auctions/<?= htmlspecialchars($o['image']) ?>"
                                                        class="w-10 h-10 rounded-lg object-cover shrink-0" alt="">
                                                <?php else: ?>
                                                    <div
                                                        class="w-10 h-10 rounded-lg bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-xs shrink-0">
                                                        <i class="fa-solid fa-image"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-xs font-semibold line-clamp-1">
                                                        <?= htmlspecialchars($o['title']) ?>
                                                    </div>
                                                    <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">by
                                                        <?= htmlspecialchars($o['buyer_name']) ?>
                                                    </div>
                                                </div>
                                                <div class="text-right shrink-0">
                                                    <div class="text-xs font-black tabular-nums">Rs.
                                                        <?= number_format((float) $o['total_amount']) ?>
                                                    </div>
                                                    <span
                                                        class="inline-flex items-center gap-1 mt-1 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider <?= $os['bg'] . ' ' . $os['text'] ?>">
                                                        <span class="w-1 h-1 rounded-full <?= $os['dot'] ?>"></span>
                                                        <?= $os['label'] ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="p-8 text-center">
                                    <div
                                        class="w-12 h-12 rounded-2xl bg-gray-100 dark:bg-slate-800 text-gray-400 flex items-center justify-center text-lg mx-auto mb-3">
                                        <i class="fa-solid fa-receipt"></i>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">No orders yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Weekly Bids Chart -->
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <span
                                    class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-chart-line"></i>
                                </span>
                                <div>
                                    <h2 class="text-sm font-bold">Bid Activity</h2>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">Bids per day · 7 days
                                    </p>
                                </div>
                            </div>

                            <div class="p-5">
                                <?php if (!empty($week_bids)): ?>
                                    <div class="space-y-3">
                                        <?php foreach ($week_bids as $d):
                                            $pct = ($d['total'] / $max_bids) * 100;
                                            $is_today = date('Y-m-d') === $d['day'];
                                            ?>
                                            <div>
                                                <div class="flex items-center justify-between mb-1">
                                                    <span class="text-xs font-bold flex items-center gap-1.5">
                                                        <?= date('D', strtotime($d['day'])) ?>
                                                        <?php if ($is_today): ?><span
                                                                class="text-[9px] font-bold text-indigo-600 dark:text-indigo-400 uppercase">Today</span><?php endif; ?>
                                                    </span>
                                                    <span
                                                        class="text-xs font-black tabular-nums"><?= number_format($d['total']) ?></span>
                                                </div>
                                                <div class="h-1.5 rounded-full bg-gray-100 dark:bg-slate-800 overflow-hidden">
                                                    <div class="h-full rounded-full bg-gradient-to-r from-violet-500 to-indigo-500 transition-all duration-700"
                                                        style="width:<?= max(4, $pct) ?>%"></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-6">
                                        <div
                                            class="w-12 h-12 rounded-2xl bg-gray-100 dark:bg-slate-800 text-gray-400 flex items-center justify-center text-lg mx-auto mb-3">
                                            <i class="fa-solid fa-chart-simple"></i>
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">No bidding activity in the last
                                            7 days.</p>
                                    </div>
                                <?php endif; ?>

                                <div class="mt-5 pt-4 border-t border-gray-100 dark:border-gray-800 space-y-2">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-gray-500 dark:text-gray-400">Total bids</span>
                                        <span class="font-black tabular-nums"><?= number_format($total_bids) ?></span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-gray-500 dark:text-gray-400">Active auctions</span>
                                        <span
                                            class="font-black text-emerald-600 dark:text-emerald-400 tabular-nums"><?= number_format($active_auctions) ?></span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-gray-500 dark:text-gray-400">Completed</span>
                                        <span
                                            class="font-black text-blue-600 dark:text-blue-400 tabular-nums"><?= number_format($completed_auctions) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- [NEW] RECENT BIDS FEED -->
                <?php if (!empty($recent_bids)): ?>
                    <section
                        class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden animate-fade-up">
                        <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span
                                    class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-gavel"></i>
                                </span>
                                <div>
                                    <h2 class="text-base font-bold">Recent Bids</h2>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">Live bidding activity</p>
                                </div>
                            </div>
                            <a href="bid-activity.php"
                                class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline">View All
                                →</a>
                        </div>
                        <div class="divide-y divide-gray-100 dark:divide-gray-800">
                            <?php foreach ($recent_bids as $rb): ?>
                                <div class="p-4 flex items-center gap-3">
                                    <?php if (!empty($rb['auction_image'])): ?>
                                        <img src="../uploads/auctions/<?= htmlspecialchars($rb['auction_image']) ?>"
                                            class="w-10 h-10 rounded-lg object-cover shrink-0" alt="">
                                    <?php else: ?>
                                        <div
                                            class="w-10 h-10 rounded-lg bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-xs shrink-0">
                                            <i class="fa-solid fa-image"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-xs font-bold line-clamp-1">
                                            <?= htmlspecialchars($rb['auction_title']) ?>
                                        </div>
                                        <div class="text-[10px] text-gray-400">Anonymous Bidder ·
                                            <?= time_ago_short($rb['bid_time']) ?>
                                        </div>
                                    </div>
                                    <div class="text-sm font-black tabular-nums text-emerald-600 dark:text-emerald-400">
                                        +Rs. <?= number_format((float) $rb['bid_amount']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- Quick Actions -->
                <section class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 animate-fade-up">
                    <a href="create-auction.php"
                        class="group bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 hover:border-indigo-500 p-5 transition-all hover:-translate-y-0.5 hover:shadow-lg flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-base group-hover:scale-110 transition-transform shrink-0">
                            <i class="fa-solid fa-plus"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="text-sm font-bold truncate">New Auction</div>
                            <div class="text-[11px] text-gray-500 dark:text-gray-400">List an item</div>
                        </div>
                    </a>
                    <a href="my-auctions.php"
                        class="group bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 hover:border-violet-500 p-5 transition-all hover:-translate-y-0.5 hover:shadow-lg flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-base group-hover:scale-110 transition-transform shrink-0">
                            <i class="fa-solid fa-box"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="text-sm font-bold truncate">My Auctions</div>
                            <div class="text-[11px] text-gray-500 dark:text-gray-400"><?= $total_auctions ?> listings
                            </div>
                        </div>
                    </a>
                    <a href="orders.php"
                        class="group bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 hover:border-emerald-500 p-5 transition-all hover:-translate-y-0.5 hover:shadow-lg flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-base group-hover:scale-110 transition-transform shrink-0">
                            <i class="fa-solid fa-receipt"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="text-sm font-bold truncate">Orders</div>
                            <div class="text-[11px] text-gray-500 dark:text-gray-400">Manage sales</div>
                        </div>
                    </a>
                    <a href="bid-activity.php"
                        class="group bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 hover:border-fuchsia-500 p-5 transition-all hover:-translate-y-0.5 hover:shadow-lg flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-xl bg-fuchsia-500/10 text-fuchsia-600 dark:text-fuchsia-400 flex items-center justify-center text-base group-hover:scale-110 transition-transform shrink-0">
                            <i class="fa-solid fa-gavel"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="text-sm font-bold truncate">Bid Activity</div>
                            <div class="text-[11px] text-gray-500 dark:text-gray-400"><?= number_format($total_bids) ?>
                                bids</div>
                        </div>
                    </a>
                    <a href="payouts.php"
                        class="group bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 hover:border-amber-500 p-5 transition-all hover:-translate-y-0.5 hover:shadow-lg flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-base group-hover:scale-110 transition-transform shrink-0">
                            <i class="fa-solid fa-wallet"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="text-sm font-bold truncate">Payouts</div>
                            <div class="text-[11px] text-gray-500 dark:text-gray-400">Withdraw earnings</div>
                        </div>
                    </a>
                    <a href="reviews.php"
                        class="group bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 hover:border-rose-500 p-5 transition-all hover:-translate-y-0.5 hover:shadow-lg flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-xl bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-base group-hover:scale-110 transition-transform shrink-0">
                            <i class="fa-solid fa-star"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="text-sm font-bold truncate">Reviews</div>
                            <div class="text-[11px] text-gray-500 dark:text-gray-400">Buyer feedback</div>
                        </div>
                    </a>
                </section>
            </div>

            <footer class="mt-8 border-t border-gray-200 dark:border-gray-800 px-4 sm:px-8 py-6">
                <div
                    class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                    <p>© <?= date("Y") ?> NexusBids Seller Panel.</p>
                    <p class="flex items-center gap-1.5">Seller Console <span
                            class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span></p>
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