<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set("Asia/Karachi");

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || $role !== 'user') {
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/notifications.php";

/* ------------------------------------------------------------------
   Helpers
   ------------------------------------------------------------------ */
if (!function_exists('nb_initials')) {
    function nb_initials(string $name, int $len = 2): string
    {
        $clean = preg_replace('/\s+/u', '', $name);
        if ($clean === '' || $clean === null)
            return 'U';
        if (function_exists('mb_substr')) {
            return mb_strtoupper(mb_substr($clean, 0, $len, 'UTF-8'), 'UTF-8');
        }
        return strtoupper(substr($clean, 0, $len));
    }
}

if (!function_exists('nb_time_ago')) {
    function nb_time_ago(?string $dt): string
    {
        if (empty($dt))
            return '';
        $ts = strtotime($dt);
        if ($ts === false)
            return '';
        $d = time() - $ts;
        if ($d < 60)
            return "just now";
        if ($d < 3600)
            return intdiv($d, 60) . "m ago";
        if ($d < 86400)
            return intdiv($d, 3600) . "h ago";
        if ($d < 604800)
            return intdiv($d, 86400) . "d ago";
        return date("d M Y", $ts);
    }
}

if (!function_exists('nb_auction_image_url')) {
    function nb_auction_image_url(?string $image): ?string
    {
        if (!$image)
            return null;
        return '../uploads/auctions/' . rawurlencode($image);
    }
}

/* ------------------------------------------------------------------
   Current user
   ------------------------------------------------------------------ */
$user_id = (int) $_SESSION['user_id'];
$full_name = 'User';

if ($u = $conn->prepare("SELECT full_name FROM users WHERE user_id = ? LIMIT 1")) {
    $u->bind_param("i", $user_id);
    $u->execute();
    $row = $u->get_result()->fetch_assoc();
    $u->close();
    if (!empty($row['full_name'])) {
        $full_name = $row['full_name'];
    }
}
$initials = nb_initials($full_name);

$unread = function_exists('nb_unread_count') ? (int) nb_unread_count($conn, $user_id) : 0;

/* ------------------------------------------------------------------
   Fetch orders
   ------------------------------------------------------------------ */
$orders = [];
if (
    $stmt = $conn->prepare("
    SELECT
        o.order_id, o.order_number, o.order_status, o.payment_status, o.shipping_status,
        o.winning_bid, o.platform_fee, o.seller_amount, o.total_amount,
        o.courier, o.tracking_number,
        o.created_at, o.paid_at, o.shipped_at, o.delivered_at,
        a.auction_id, a.title, a.image,
        s.full_name AS seller_name,
        (SELECT r.review_id
           FROM reviews r
          WHERE r.auction_id = o.auction_id
            AND r.reviewer_id = o.buyer_id
          ORDER BY r.review_id DESC
          LIMIT 1) AS review_id
    FROM orders o
    INNER JOIN auctions a ON a.auction_id = o.auction_id
    INNER JOIN users    s ON s.user_id    = o.seller_id
    WHERE o.buyer_id = ?
    ORDER BY o.created_at DESC
    LIMIT 200
")
) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        $orders = $res->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

/* ------------------------------------------------------------------
   Counters
   ------------------------------------------------------------------ */
$total_orders = count($orders);
$pending_pay = 0;
$active_orders = 0;
$completed_orders = 0;
$awaiting_verify = 0;

foreach ($orders as $o) {
    $ostatus = (string) ($o['order_status'] ?? '');
    $pstatus = (string) ($o['payment_status'] ?? '');

    // Awaiting verification (payment submitted but not confirmed)
    if (in_array($pstatus, ['pending', 'processing'], true)) {
        $awaiting_verify++;
    }

    // Pending payment: not initiated yet, not cancelled/refunded
    if (
        $pstatus === 'unpaid'
        && !in_array($ostatus, ['cancelled', 'refunded', 'disputed'], true)
    ) {
        $pending_pay++;
    }

    // Completed
    if (in_array($ostatus, ['completed', 'delivered'], true)) {
        $completed_orders++;
    }

    // Active in-flight
    if (in_array($ostatus, ['paid', 'processing', 'shipped'], true)) {
        $active_orders++;
    }
}

/* ------------------------------------------------------------------
   Status style map
   ------------------------------------------------------------------ */
$order_status_styles = [
    'pending_payment' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Payment Pending', 'icon' => 'fa-clock'],
    'paid' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'label' => 'Paid', 'icon' => 'fa-circle-check'],
    'processing' => ['bg' => 'bg-indigo-500/10', 'text' => 'text-indigo-700 dark:text-indigo-400', 'label' => 'Processing', 'icon' => 'fa-gear'],
    'shipped' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'label' => 'Shipped', 'icon' => 'fa-truck-fast'],
    'delivered' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Delivered', 'icon' => 'fa-box-open'],
    'completed' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Completed', 'icon' => 'fa-circle-check'],
    'cancelled' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Cancelled', 'icon' => 'fa-circle-xmark'],
    'refunded' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'label' => 'Refunded', 'icon' => 'fa-rotate-left'],
    'disputed' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Disputed', 'icon' => 'fa-triangle-exclamation'],
];
$default_style = ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-700 dark:text-gray-300', 'label' => 'Pending', 'icon' => 'fa-hourglass-half'];

/* ------------------------------------------------------------------
   Payment status badge map (small, near item row)
   ------------------------------------------------------------------ */
$payment_badges = [
    'unpaid' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Unpaid', 'icon' => 'fa-circle-exclamation'],
    'pending' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'label' => 'Awaiting Verification', 'icon' => 'fa-hourglass-half'],
    'processing' => ['bg' => 'bg-indigo-500/10', 'text' => 'text-indigo-700 dark:text-indigo-400', 'label' => 'Processing', 'icon' => 'fa-spinner'],
    'paid' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Paid', 'icon' => 'fa-circle-check'],
    'failed' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Failed', 'icon' => 'fa-circle-xmark'],
    'refunded' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'label' => 'Refunded', 'icon' => 'fa-rotate-left'],
];

$currency_symbol = 'Rs.';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View and track all your winning auction orders on NexusBids.">
    <title>My Orders · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } }
        };
        (function () {
            try {
                const saved = localStorage.getItem('nb-theme');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                const useDark = saved ? saved === 'dark' : prefersDark;
                document.documentElement.classList.toggle('dark', useDark);
            } catch (e) { }
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen">

    <!-- Top Utility Bar -->
    <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-6">
                <span class="flex items-center gap-2"><i class="fa-solid fa-location-dot text-indigo-400"></i>
                    Pakistan</span>
                <span class="hidden sm:flex items-center gap-2"><i class="fa-solid fa-phone text-indigo-400"></i> +92
                    300 1234567</span>
            </div>
            <div class="flex items-center space-x-6">
                <a href="../about.php" class="hover:text-white transition-colors"><i
                        class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
                <a href="../about.php#why-nexusbids" class="hover:text-white transition-colors"><i
                        class="fa-solid fa-shield-halved text-indigo-400"></i> Secure Bidding</a>
            </div>
        </div>
    </div>

    <!-- Main Navbar -->
    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight shrink-0 group" href="../index.php">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg group-hover:scale-105 transition-all">
                    <i class="fa-solid fa-gavel text-lg"></i>
                </span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            </a>
            <nav class="hidden lg:flex items-center gap-7 text-sm font-medium">
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../index.php">Home</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../auctions.php">Auctions</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../categories.php">Categories</a>
                <a class="relative text-indigo-600 dark:text-indigo-400 font-semibold after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-indigo-600 after:rounded-full"
                    href="dashboard.php">Dashboard</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../about.php">About</a>
            </nav>
            <div class="flex items-center gap-2 sm:gap-3">
                <button aria-label="Toggle Theme" id="themeToggle"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                    <i class="fa-solid fa-moon dark:hidden"></i><i
                        class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>
                <a href="../notifications.php"
                    class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unread > 0): ?><span
                            class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800 animate-pulse-soft"></span><?php endif; ?>
                </a>
                <div class="flex items-center gap-3 pl-3 border-l border-gray-200 dark:border-gray-800">
                    <div class="relative">
                        <div
                            class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg ring-2 ring-white dark:ring-slate-900">
                            <?php echo htmlspecialchars($initials); ?></div>
                        <span
                            class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900"></span>
                    </div>
                    <div class="hidden sm:block text-left">
                        <div class="text-sm font-bold leading-tight truncate max-w-32">
                            <?php echo htmlspecialchars($full_name); ?></div>
                        <div
                            class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1">
                            <i class="fa-solid fa-circle-check text-[8px]"></i> Verified Bidder
                        </div>
                    </div>
                </div>
                <a class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition"
                    href="../auth/logout.php">
                    <i class="fa-solid fa-right-from-bracket mr-1.5"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <!-- ================= MAIN ================= -->
    <main class="max-w-7xl mx-auto px-4 sm:px-8 py-8 space-y-6">

        <!-- Page header -->
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3"
                    aria-label="Breadcrumb">
                    <a href="dashboard.php" class="hover:text-indigo-600">Dashboard</a>
                    <i class="fa-solid fa-chevron-right text-[8px]"></i>
                    <span class="text-gray-900 dark:text-white font-semibold">My Orders</span>
                </nav>
                <h1 class="text-3xl font-black tracking-tight flex items-center gap-3">
                    <span
                        class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-500 flex items-center justify-center text-white shadow-lg">
                        <i class="fa-solid fa-receipt text-lg"></i>
                    </span>
                    My Orders
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Track your won auctions from payment to
                    delivery.</p>
            </div>
        </div>

        <!-- Stats -->
        <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Total Orders</span>
                    <span
                        class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 flex items-center justify-center text-xs"><i
                            class="fa-solid fa-receipt"></i></span>
                </div>
                <div class="text-2xl font-black tabular-nums"><?php echo (int) $total_orders; ?></div>
            </div>
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Pending Payment</span>
                    <span
                        class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 flex items-center justify-center text-xs"><i
                            class="fa-solid fa-clock"></i></span>
                </div>
                <div
                    class="text-2xl font-black tabular-nums <?php echo $pending_pay > 0 ? 'text-amber-600 dark:text-amber-400' : ''; ?>">
                    <?php echo (int) $pending_pay; ?>
                </div>
            </div>
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Active</span>
                    <span
                        class="w-9 h-9 rounded-lg bg-blue-500/10 text-blue-600 flex items-center justify-center text-xs"><i
                            class="fa-solid fa-truck-fast"></i></span>
                </div>
                <div class="text-2xl font-black tabular-nums"><?php echo (int) $active_orders; ?></div>
            </div>
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Completed</span>
                    <span
                        class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center text-xs"><i
                            class="fa-solid fa-circle-check"></i></span>
                </div>
                <div class="text-2xl font-black tabular-nums text-emerald-600 dark:text-emerald-400">
                    <?php echo (int) $completed_orders; ?>
                </div>
            </div>
        </section>

        <!-- Orders list -->
        <section
            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <?php if (empty($orders)): ?>
                <div class="p-16 text-center">
                    <div
                        class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                    <h3 class="text-xl font-black">No orders yet</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 max-w-md mx-auto">
                        When you win an auction, your order will appear here.
                    </p>
                    <a href="../auctions.php"
                        class="inline-flex mt-6 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition">
                        <i class="fa-solid fa-magnifying-glass mr-2"></i> Browse Auctions
                    </a>
                </div>
            <?php else: ?>
                <!-- Desktop table -->
                <div class="hidden md:block overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                            <tr>
                                <th
                                    class="px-6 py-4 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                    Order</th>
                                <th
                                    class="px-6 py-4 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                    Item</th>
                                <th
                                    class="px-6 py-4 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                    Amount</th>
                                <th
                                    class="px-6 py-4 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                    Status</th>
                                <th
                                    class="px-6 py-4 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                    Date</th>
                                <th
                                    class="px-6 py-4 text-right text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                    Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <?php foreach ($orders as $o):
                                $ostatus = (string) ($o['order_status'] ?? '');
                                $pstatus = (string) ($o['payment_status'] ?? '');
                                $style = $order_status_styles[$ostatus] ?? $default_style;
                                $badge = $payment_badges[$pstatus] ?? $payment_badges['unpaid'];
                                $img_url = nb_auction_image_url($o['image'] ?? null);
                                $auction_id = (int) ($o['auction_id'] ?? 0);

                                // ---- PAY NOW LOGIC (FIXED) ----
                                $is_paid = ($pstatus === 'paid');
                                $is_initiated = in_array($pstatus, ['pending', 'processing'], true);
                                $is_cancelled = in_array($ostatus, ['cancelled', 'refunded', 'disputed'], true);
                                $can_pay = in_array($pstatus, ['unpaid', 'failed'], true) && !$is_cancelled;
                                ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors">
                                    <td class="px-6 py-4">
                                        <a href="order-details.php?id=<?php echo $auction_id; ?>"
                                            class="font-mono text-xs font-bold text-gray-900 dark:text-white hover:text-indigo-600">
                                            <?php echo htmlspecialchars((string) $o['order_number'], ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <?php if ($img_url): ?>
                                                <img src="<?php echo htmlspecialchars($img_url, ENT_QUOTES, 'UTF-8'); ?>"
                                                    class="w-10 h-10 rounded-xl object-cover" alt="" loading="lazy"
                                                    onerror="this.replaceWith(Object.assign(document.createElement('div'),{className:'w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-xs',innerHTML:'<i class=&quot;fa-solid fa-image&quot;></i>'}))">
                                            <?php else: ?>
                                                <div
                                                    class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-xs">
                                                    <i class="fa-solid fa-image"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div class="min-w-0">
                                                <a href="../auction-details.php?id=<?php echo $auction_id; ?>"
                                                    class="font-semibold text-gray-900 dark:text-white hover:text-indigo-600 line-clamp-1 max-w-[200px]">
                                                    <?php echo htmlspecialchars((string) $o['title'], ENT_QUOTES, 'UTF-8'); ?>
                                                </a>
                                                <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                                    Seller:
                                                    <?php echo htmlspecialchars((string) $o['seller_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-black tabular-nums text-gray-900 dark:text-white">
                                            <?php echo $currency_symbol . ' ' . number_format((float) $o['total_amount']); ?>
                                        </div>
                                        <!-- Payment badge -->
                                        <span
                                            class="inline-flex items-center gap-1 mt-1 px-2 py-0.5 rounded-md <?php echo $badge['bg'] . ' ' . $badge['text']; ?> text-[10px] font-bold uppercase tracking-wider">
                                            <i class="fa-solid <?php echo $badge['icon']; ?>"></i>
                                            <?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?php echo $style['bg'] . ' ' . $style['text']; ?> text-[10px] font-bold uppercase tracking-wider">
                                            <i class="fa-solid <?php echo $style['icon']; ?>"></i>
                                            <?php echo htmlspecialchars($style['label'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-xs text-gray-500 dark:text-gray-400">
                                        <?php echo htmlspecialchars(date('d M Y', strtotime((string) $o['created_at'])), ENT_QUOTES, 'UTF-8'); ?>
                                        <div class="text-[10px]">
                                            <?php echo htmlspecialchars(nb_time_ago($o['created_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <?php if ($can_pay): ?>
                                                <a href="../checkout.php?auction_id=<?php echo $auction_id; ?>"
                                                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition">
                                                    <i class="fa-solid fa-credit-card"></i> Pay Now
                                                </a>
                                            <?php elseif ($is_paid): ?>
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-emerald-500/10 text-emerald-600 text-xs font-bold">
                                                    <i class="fa-solid fa-check"></i> Paid
                                                </span>
                                            <?php elseif ($is_initiated): ?>
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 text-xs font-bold">
                                                    <i class="fa-solid fa-hourglass-half"></i> Awaiting Verification
                                                </span>
                                            <?php endif; ?>

                                            <a href="order-details.php?id=<?php echo $auction_id; ?>"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-gray-700 dark:text-gray-200 text-xs font-semibold transition">
                                                <i class="fa-solid fa-eye"></i> View
                                            </a>

                                            <?php if (empty($o['review_id']) && $is_paid): ?>
                                                <a href="add-review.php?auction_id=<?php echo $auction_id; ?>"
                                                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-indigo-600 hover:text-white text-gray-700 dark:text-gray-200 text-xs font-semibold transition">
                                                    <i class="fa-regular fa-star"></i> Review
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile cards -->
                <div class="md:hidden divide-y divide-gray-100 dark:divide-gray-800">
                    <?php foreach ($orders as $o):
                        $ostatus = (string) ($o['order_status'] ?? '');
                        $pstatus = (string) ($o['payment_status'] ?? '');
                        $style = $order_status_styles[$ostatus] ?? $default_style;
                        $badge = $payment_badges[$pstatus] ?? $payment_badges['unpaid'];
                        $img_url = nb_auction_image_url($o['image'] ?? null);
                        $auction_id = (int) ($o['auction_id'] ?? 0);

                        $is_paid = ($pstatus === 'paid');
                        $is_initiated = in_array($pstatus, ['pending', 'processing'], true);
                        $is_cancelled = in_array($ostatus, ['cancelled', 'refunded', 'disputed'], true);
                        $can_pay = in_array($pstatus, ['unpaid', 'failed'], true) && !$is_cancelled;
                        ?>
                        <div class="p-4 space-y-3">
                            <div class="flex items-start gap-3">
                                <?php if ($img_url): ?>
                                    <img src="<?php echo htmlspecialchars($img_url, ENT_QUOTES, 'UTF-8'); ?>"
                                        class="w-14 h-14 rounded-xl object-cover" alt="" loading="lazy">
                                <?php else: ?>
                                    <div
                                        class="w-14 h-14 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center">
                                        <i class="fa-solid fa-image"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="flex-1 min-w-0">
                                    <a href="../auction-details.php?id=<?php echo $auction_id; ?>"
                                        class="font-semibold text-sm line-clamp-2 hover:text-indigo-600">
                                        <?php echo htmlspecialchars((string) $o['title'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                    <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-1 font-mono">
                                        <?php echo htmlspecialchars((string) $o['order_number'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-500 dark:text-gray-400">Amount</span>
                                <span
                                    class="font-black tabular-nums"><?php echo $currency_symbol . ' ' . number_format((float) $o['total_amount']); ?></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?php echo $style['bg'] . ' ' . $style['text']; ?> text-[10px] font-bold uppercase tracking-wider">
                                    <i class="fa-solid <?php echo $style['icon']; ?>"></i>
                                    <?php echo htmlspecialchars($style['label'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md <?php echo $badge['bg'] . ' ' . $badge['text']; ?> text-[10px] font-bold uppercase tracking-wider">
                                    <i class="fa-solid <?php echo $badge['icon']; ?>"></i>
                                    <?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                            <div class="flex gap-2 pt-1">
                                <?php if ($can_pay): ?>
                                    <a href="../checkout.php?auction_id=<?php echo $auction_id; ?>"
                                        class="flex-1 text-center px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold">
                                        <i class="fa-solid fa-credit-card mr-1"></i> Pay Now
                                    </a>
                                <?php elseif ($is_initiated): ?>
                                    <span
                                        class="flex-1 text-center px-3 py-2 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 text-xs font-bold">
                                        <i class="fa-solid fa-hourglass-half mr-1"></i> Awaiting Verification
                                    </span>
                                <?php endif; ?>
                                <a href="order-details.php?id=<?php echo $auction_id; ?>"
                                    class="flex-1 text-center px-3 py-2 rounded-lg bg-gray-100 dark:bg-slate-800 text-xs font-semibold">
                                    <i class="fa-solid fa-eye mr-1"></i> View
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- ================= THEME TOGGLE ================= -->
    <script>
        (function () {
            const btn = document.getElementById('themeToggle');
            if (!btn) return;
            btn.addEventListener('click', function () {
                const isDark = document.documentElement.classList.toggle('dark');
                try { localStorage.setItem('nb-theme', isDark ? 'dark' : 'light'); } catch (e) { }
            });
        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>

</body>

</html>