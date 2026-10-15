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
require_once __DIR__ . "/../includes/csrf.php";
require_once __DIR__ . "/../includes/notifications.php";
require_once __DIR__ . "/../includes/payment-helpers.php";

$user_id = (int) $_SESSION['user_id'];
$auction_id = (int) ($_GET['id'] ?? $_GET['auction_id'] ?? 0);

if ($auction_id <= 0) {
    header("Location: orders.php");
    exit();
}

/* Helpers */
if (!function_exists('nb_safe_date')) {
    function nb_safe_date(?string $dt, string $format = 'd M Y, h:i A'): string
    {
        if (empty($dt))
            return '';
        $ts = strtotime($dt);
        return $ts === false ? '' : date($format, $ts);
    }
}
if (!function_exists('nb_initials')) {
    function nb_initials(string $name, int $len = 2): string
    {
        $clean = preg_replace('/\s+/u', '', $name);
        if ($clean === '' || $clean === null)
            return 'U';
        return function_exists('mb_substr') ? mb_strtoupper(mb_substr($clean, 0, $len, 'UTF-8'), 'UTF-8') : strtoupper(substr($clean, 0, $len));
    }
}

/* Fetch user info for navbar */
$u_stmt = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$uinfo = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();
$full_name = $uinfo['full_name'] ?? 'User';
$avatar = $uinfo['avatar'] ?? '';
$initials = nb_initials($full_name, 2);
$unread_notifications = function_exists('nb_unread_count') ? nb_unread_count($conn, $user_id) : 0;

/* Fetch order */
$order = null;
if (
    $stmt = $conn->prepare("
    SELECT
        o.order_id, o.order_number, o.order_status, o.payment_status,
        o.shipping_status, o.winning_bid, o.platform_fee, o.seller_amount,
        o.total_amount, o.courier, o.tracking_number,
        o.shipped_at, o.delivered_at, o.paid_at, o.completed_at,
        o.buyer_confirmed_at, o.cancelled_at, o.notes, o.created_at,
        a.auction_id, a.title AS auction_title, a.image AS auction_image,
        s.user_id AS seller_user_id, s.full_name AS seller_name, s.email AS seller_email
    FROM orders o
    INNER JOIN auctions a ON a.auction_id = o.auction_id
    INNER JOIN users    s ON s.user_id    = o.seller_id
    WHERE o.auction_id = ? AND o.buyer_id = ?
    LIMIT 1
")
) {
    $stmt->bind_param("ii", $auction_id, $user_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$order) {
    $_SESSION['flash_error'] = 'Order not found or access denied.';
    header("Location: orders.php");
    exit();
}

/* Latest payment */
$payment = null;
if (
    $pstmt = $conn->prepare("
    SELECT payment_id, payment_method, payment_status, transaction_id,
           sender_name, sender_number, amount, initiated_at, created_at
    FROM payments WHERE order_id = ?
    ORDER BY payment_id DESC LIMIT 1
")
) {
    $pstmt->bind_param("i", $order['order_id']);
    $pstmt->execute();
    $payment = $pstmt->get_result()->fetch_assoc();
    $pstmt->close();
}

/* Existing dispute? */
$existing_dispute = null;
try {
    if ($conn->query("SHOW TABLES LIKE 'payment_disputes'")->num_rows > 0) {
        if ($ds = $conn->prepare("SELECT id, status, dispute_type FROM payment_disputes WHERE order_id = ? LIMIT 1")) {
            $ds->bind_param("i", $order['order_id']);
            $ds->execute();
            $existing_dispute = $ds->get_result()->fetch_assoc();
            $ds->close();
        }
    }
} catch (Throwable $e) {
}

/* Status styles */
$order_styles = [
    'pending_payment' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Pending Payment', 'icon' => 'fa-clock'],
    'paid' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'label' => 'Paid', 'icon' => 'fa-circle-check'],
    'processing' => ['bg' => 'bg-indigo-500/10', 'text' => 'text-indigo-700 dark:text-indigo-400', 'label' => 'Processing', 'icon' => 'fa-gear'],
    'shipped' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'label' => 'Shipped', 'icon' => 'fa-truck-fast'],
    'delivered' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Delivered', 'icon' => 'fa-box-open'],
    'completed' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Completed', 'icon' => 'fa-check-double'],
    'cancelled' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Cancelled', 'icon' => 'fa-xmark'],
    'refunded' => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'label' => 'Refunded', 'icon' => 'fa-rotate-left'],
    'disputed' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Disputed', 'icon' => 'fa-triangle-exclamation'],
];
$style = $order_styles[$order['order_status']] ?? ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-300', 'label' => ucfirst($order['order_status']), 'icon' => 'fa-circle'];

/* Payment status styling */
$payment_styles = [
    'paid' => 'text-emerald-600 dark:text-emerald-400',
    'processing' => 'text-indigo-600 dark:text-indigo-400',
    'pending' => 'text-amber-600 dark:text-amber-400',
    'failed' => 'text-rose-600 dark:text-rose-400',
    'refunded' => 'text-violet-600 dark:text-violet-400',
];

/* Timeline */
$timeline = [
    ['key' => 'placed', 'label' => 'Order Placed', 'icon' => 'fa-file-invoice', 'done' => true, 'date' => $order['created_at']],
    ['key' => 'paid', 'label' => 'Payment Confirmed', 'icon' => 'fa-circle-check', 'done' => !empty($order['paid_at']), 'date' => $order['paid_at']],
    ['key' => 'shipped', 'label' => 'Shipped', 'icon' => 'fa-truck-fast', 'done' => !empty($order['shipped_at']), 'date' => $order['shipped_at']],
    ['key' => 'delivered', 'label' => 'Delivered', 'icon' => 'fa-box-open', 'done' => !empty($order['delivered_at']), 'date' => $order['delivered_at']],
    ['key' => 'confirmed', 'label' => 'Receipt Confirmed', 'icon' => 'fa-handshake', 'done' => !empty($order['buyer_confirmed_at']), 'date' => $order['buyer_confirmed_at']],
    ['key' => 'completed', 'label' => 'Completed', 'icon' => 'fa-check-double', 'done' => ($order['order_status'] === 'completed'), 'date' => $order['completed_at'] ?? null],
];
if (in_array($order['order_status'], ['cancelled', 'refunded', 'disputed'], true)) {
    $timeline[] = ['key' => $order['order_status'], 'label' => $style['label'], 'icon' => $style['icon'], 'done' => true, 'date' => $order['cancelled_at'] ?? null, 'is_negative' => true];
}

/* Image */
$image_url = '';
if (!empty($order['auction_image'])) {
    $b = basename($order['auction_image']);
    if (file_exists(__DIR__ . '/../uploads/auctions/' . $b)) {
        $image_url = '../uploads/auctions/' . rawurlencode($b);
    }
}

/* Can confirm? */
$can_confirm = empty($order['buyer_confirmed_at'])
    && in_array(strtolower($order['order_status']), ['delivered', 'shipped', 'in_transit'], true)
    && strtolower($order['payment_status']) === 'paid';

/* Can report problem? */
$can_report = empty($existing_dispute)
    && strtolower($order['payment_status']) === 'paid'
    && !in_array(strtolower($order['order_status']), ['cancelled', 'refunded'], true);

/* Show pay now? */
$can_pay = in_array(strtolower($order['payment_status']), ['unpaid', 'failed'], true)
    && strtolower($order['order_status']) !== 'cancelled';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order <?= htmlspecialchars((string) $order['order_number']) ?> · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] }, animation: { 'fade-up': 'fadeUp 0.6s ease-out forwards', 'pulse-soft': 'pulseSoft 2s ease-in-out infinite' }, keyframes: { fadeUp: { '0%': { opacity: '0', transform: 'translateY(16px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } }, pulseSoft: { '0%, 100%': { opacity: '1' }, '50%': { opacity: '0.5' } } } } } };
        (function () { try { const s = localStorage.getItem('theme'); const d = window.matchMedia('(prefers-color-scheme: dark)').matches; if (s === 'dark' || (!s && d)) document.documentElement.classList.add('dark'); } catch (e) { } })();
    </script>
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
                <a href="../about.php" class="hover:text-white"><i
                        class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
                <a href="../about.php#why-nexusbids" class="hover:text-white"><i
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
                    <?php if ($unread_notifications > 0): ?><span
                            class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800 animate-pulse-soft"></span><?php endif; ?>
                </a>
                <div class="flex items-center gap-3 pl-3 border-l border-gray-200 dark:border-gray-800">
                    <div class="relative">
                        <?php if (!empty($avatar)): ?>
                            <img src="../uploads/avatars/<?= htmlspecialchars($avatar) ?>"
                                class="w-10 h-10 rounded-full object-cover ring-2 ring-white dark:ring-slate-900" alt="">
                        <?php else: ?>
                            <div
                                class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg ring-2 ring-white dark:ring-slate-900">
                                <?= htmlspecialchars($initials) ?></div>
                        <?php endif; ?>
                        <span
                            class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900"></span>
                    </div>
                    <div class="hidden sm:block text-left">
                        <div class="text-sm font-bold leading-tight truncate max-w-32">
                            <?= htmlspecialchars($full_name) ?></div>
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

    <main class="max-w-5xl mx-auto px-4 sm:px-8 py-8 space-y-6">

        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 flex items-center gap-3">
                <i class="fa-solid fa-circle-check text-emerald-600"></i>
                <p class="text-sm text-emerald-700 dark:text-emerald-300 font-medium">
                    <?= htmlspecialchars($_SESSION['flash_success']);
                    unset($_SESSION['flash_success']); ?></p>
            </div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 flex items-center gap-3">
                <i class="fa-solid fa-circle-exclamation text-rose-600"></i>
                <p class="text-sm text-rose-700 dark:text-rose-300 font-medium">
                    <?= htmlspecialchars($_SESSION['flash_error']);
                    unset($_SESSION['flash_error']); ?></p>
            </div>
        <?php endif; ?>

        <!-- Breadcrumb -->
        <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
            <a href="dashboard.php" class="hover:text-indigo-600">Dashboard</a>
            <i class="fa-solid fa-chevron-right text-[8px]"></i>
            <a href="orders.php" class="hover:text-indigo-600">My Orders</a>
            <i class="fa-solid fa-chevron-right text-[8px]"></i>
            <span class="text-gray-900 dark:text-white font-semibold">Order Details</span>
        </nav>

        <!-- Header Card -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-6 shadow-sm">
            <div class="flex flex-col sm:flex-row justify-between items-start gap-4">
                <div>
                    <div class="text-[11px] uppercase tracking-wider font-bold text-gray-400">Order</div>
                    <h1 class="text-2xl font-black font-mono mt-1">
                        <?= htmlspecialchars((string) $order['order_number']) ?></h1>
                    <p class="text-xs text-gray-500 mt-2">Placed on
                        <?= htmlspecialchars(nb_safe_date($order['created_at'])) ?></p>
                </div>
                <span
                    class="inline-flex items-center gap-2 px-3 py-2 rounded-xl <?= $style['bg'] . ' ' . $style['text'] ?> text-sm font-bold">
                    <i class="fa-solid <?= htmlspecialchars($style['icon']) ?>"></i>
                    <?= htmlspecialchars($style['label']) ?>
                </span>
            </div>

            <!-- Quick Actions Bar -->
            <?php if ($can_pay || $can_confirm || $can_report): ?>
                <div class="mt-5 pt-5 border-t border-gray-100 dark:border-gray-800 flex flex-wrap gap-2">
                    <?php if ($can_pay): ?>
                        <a href="../checkout.php?auction_id=<?= (int) $order['auction_id'] ?>"
                            class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold flex items-center gap-2">
                            <i class="fa-solid fa-credit-card"></i> Pay Now
                        </a>
                    <?php endif; ?>

                    <?php if ($can_confirm): ?>
                        <form method="POST" action="confirm-receipt.php" class="inline"
                            onsubmit="return confirm('Confirm that you have received this order?\n\nThis will notify the admin to release the seller\'s payout.');">
                            <?php nb_csrf_field(); ?>
                            <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                            <button type="submit"
                                class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold flex items-center gap-2 shadow-lg shadow-emerald-600/30">
                                <i class="fa-solid fa-handshake"></i> Confirm Receipt
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($can_report): ?>
                        <a href="report-problem.php?order_id=<?= (int) $order['order_id'] ?>"
                            class="px-5 py-2.5 rounded-xl bg-rose-500/10 text-rose-700 dark:text-rose-400 border border-rose-500/20 hover:bg-rose-500 hover:text-white text-sm font-bold flex items-center gap-2 transition">
                            <i class="fa-solid fa-triangle-exclamation"></i> Report Problem
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Buyer confirmed banner -->
        <?php if (!empty($order['buyer_confirmed_at'])): ?>
            <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 flex items-start gap-3">
                <i class="fa-solid fa-handshake text-emerald-600 text-lg mt-0.5"></i>
                <div class="text-sm text-emerald-800 dark:text-emerald-300">
                    <strong>You confirmed receipt on
                        <?= htmlspecialchars(nb_safe_date($order['buyer_confirmed_at'])) ?>.</strong>
                    <div class="text-xs mt-1 opacity-90">Admin will review and release the seller's payout.</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Dispute banner -->
        <?php if ($existing_dispute): ?>
            <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 flex items-start gap-3">
                <i class="fa-solid fa-scale-balanced text-rose-600 text-lg mt-0.5"></i>
                <div class="text-sm text-rose-800 dark:text-rose-300">
                    <strong>Dispute filed — Status:
                        <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $existing_dispute['status']))) ?>.</strong>
                    <div class="text-xs mt-1 opacity-90">Our team will contact both parties with next steps.</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Item + Amount -->
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <div class="p-6 flex flex-col sm:flex-row items-start gap-4">
                <?php if ($image_url): ?>
                    <img src="<?= htmlspecialchars($image_url) ?>"
                        class="w-24 h-24 rounded-xl object-cover border border-gray-200 dark:border-gray-700" alt=""
                        loading="lazy">
                <?php else: ?>
                    <div class="w-24 h-24 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center"><i
                            class="fa-solid fa-image text-2xl"></i></div>
                <?php endif; ?>
                <div class="flex-1 min-w-0">
                    <a href="../auction-details.php?id=<?= (int) $order['auction_id'] ?>"
                        class="font-bold text-lg hover:text-indigo-600 line-clamp-2">
                        <?= htmlspecialchars((string) $order['auction_title']) ?>
                    </a>
                    <p class="text-xs text-gray-500 mt-1">Sold by <strong
                            class="text-gray-700 dark:text-gray-300"><?= htmlspecialchars((string) $order['seller_name']) ?></strong>
                    </p>
                </div>
                <div class="text-right shrink-0">
                    <div class="text-[11px] uppercase tracking-wider text-gray-400 font-semibold">Total</div>
                    <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">Rs.
                        <?= number_format((float) $order['total_amount']) ?></div>
                </div>
            </div>

            <div class="border-t border-gray-100 dark:border-gray-800 p-6 bg-gray-50 dark:bg-slate-800/50">
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <div class="text-xs text-gray-500">Winning Bid</div>
                        <div class="font-bold tabular-nums">Rs. <?= number_format((float) $order['winning_bid']) ?>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Platform Fee</div>
                        <div class="font-bold tabular-nums">Rs. <?= number_format((float) $order['platform_fee']) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Timeline -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-6">
            <h2 class="text-base font-bold mb-6">Order Timeline</h2>
            <div class="relative">
                <div class="absolute left-5 top-3 bottom-3 w-0.5 bg-gray-200 dark:bg-slate-700"></div>
                <div class="space-y-6 relative">
                    <?php foreach ($timeline as $step):
                        $done = !empty($step['done']);
                        $is_neg = !empty($step['is_negative']);
                        $color = $is_neg ? 'bg-rose-500 text-white' : ($done ? 'bg-emerald-500 text-white' : 'bg-gray-200 dark:bg-slate-700 text-gray-400');
                        ?>
                        <div class="flex items-start gap-4">
                            <div
                                class="w-10 h-10 rounded-full <?= $color ?> flex items-center justify-center text-sm z-10 shrink-0">
                                <i class="fa-solid <?= htmlspecialchars($step['icon']) ?>"></i>
                            </div>
                            <div class="flex-1 pt-1">
                                <div class="font-bold text-sm <?= $done ? '' : 'text-gray-400' ?>">
                                    <?= htmlspecialchars($step['label']) ?></div>
                                <?php if (!empty($step['date'])): ?>
                                    <div class="text-xs text-gray-500 mt-0.5">
                                        <?= htmlspecialchars(nb_safe_date($step['date'])) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Shipping Info -->
        <?php if (!empty($order['courier']) || !empty($order['tracking_number']) || !empty($order['shipped_at'])): ?>
            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-6">
                <h2 class="text-base font-bold mb-4"><i class="fa-solid fa-truck-fast text-violet-500 mr-2"></i>Shipping
                    Info</h2>
                <div class="space-y-2 text-sm">
                    <?php if (!empty($order['courier'])): ?>
                        <div class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-800"><span
                                class="text-gray-500">Courier</span><strong><?= htmlspecialchars((string) $order['courier']) ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($order['tracking_number'])): ?>
                        <div class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-800"><span
                                class="text-gray-500">Tracking #</span><strong
                                class="font-mono"><?= htmlspecialchars((string) $order['tracking_number']) ?></strong></div>
                    <?php endif; ?>
                    <?php if (!empty($order['shipped_at'])): ?>
                        <div class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-800"><span
                                class="text-gray-500">Shipped
                                on</span><strong><?= htmlspecialchars(nb_safe_date($order['shipped_at'], 'd M Y')) ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($order['delivered_at'])): ?>
                        <div class="flex justify-between py-2"><span class="text-gray-500">Delivered
                                on</span><strong><?= htmlspecialchars(nb_safe_date($order['delivered_at'], 'd M Y')) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Payment Details -->
        <?php if ($payment): ?>
            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-6">
                <h2 class="text-base font-bold mb-4"><i class="fa-solid fa-credit-card text-indigo-500 mr-2"></i>Payment
                    Details</h2>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-800"><span
                            class="text-gray-500">Method</span><strong
                            class="capitalize"><?= htmlspecialchars(str_replace('_', ' ', (string) $payment['payment_method'])) ?></strong>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-800"><span
                            class="text-gray-500">Status</span><strong
                            class="capitalize <?= $payment_styles[$payment['payment_status']] ?? '' ?>"><?= htmlspecialchars(ucfirst((string) $payment['payment_status'])) ?></strong>
                    </div>
                    <?php if (!empty($payment['transaction_id'])): ?>
                        <div class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-800"><span
                                class="text-gray-500">Transaction ID</span><strong
                                class="font-mono break-all"><?= htmlspecialchars((string) $payment['transaction_id']) ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($payment['sender_name'])): ?>
                        <div class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-800"><span
                                class="text-gray-500">Sender</span><strong><?= htmlspecialchars((string) $payment['sender_name']) ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="flex justify-between py-2"><span class="text-gray-500">Amount</span><strong
                            class="tabular-nums">Rs. <?= number_format((float) $payment['amount']) ?></strong></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Order Notes -->
        <?php if (!empty($order['notes'])): ?>
            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-6">
                <h2 class="text-base font-bold mb-3"><i class="fa-solid fa-note-sticky text-amber-500 mr-2"></i>Order Notes
                </h2>
                <p class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-line">
                    <?= htmlspecialchars((string) $order['notes']) ?></p>
            </div>
        <?php endif; ?>

        <!-- Payment banner -->
        <?php
        $banners = [
            'unpaid' => ['bg' => 'bg-amber-50 dark:bg-amber-950/30', 'border' => 'border-amber-200', 'text' => 'text-amber-800 dark:text-amber-300', 'icon' => 'fa-clock', 'title' => 'Payment Pending', 'msg' => 'Please complete your payment to proceed.'],
            'pending' => ['bg' => 'bg-blue-50 dark:bg-blue-950/30', 'border' => 'border-blue-200', 'text' => 'text-blue-800 dark:text-blue-300', 'icon' => 'fa-hourglass-half', 'title' => 'Payment Submitted', 'msg' => 'Awaiting verification by admin.'],
            'processing' => ['bg' => 'bg-indigo-50 dark:bg-indigo-950/30', 'border' => 'border-indigo-200', 'text' => 'text-indigo-800 dark:text-indigo-300', 'icon' => 'fa-spinner', 'title' => 'Processing', 'msg' => 'Payment is being verified.'],
            'failed' => ['bg' => 'bg-rose-50 dark:bg-rose-950/30', 'border' => 'border-rose-200', 'text' => 'text-rose-800 dark:text-rose-300', 'icon' => 'fa-circle-xmark', 'title' => 'Payment Failed', 'msg' => 'Please try again.'],
        ];
        $banner = $banners[$order['payment_status']] ?? null;
        if ($banner): ?>
            <div class="p-4 rounded-2xl <?= $banner['bg'] . ' ' . $banner['border'] ?> border flex items-start gap-3">
                <i class="fa-solid <?= $banner['icon'] ?> <?= $banner['text'] ?> mt-0.5 text-lg"></i>
                <div class="text-sm <?= $banner['text'] ?>">
                    <div class="font-bold"><?= htmlspecialchars($banner['title']) ?></div>
                    <div class="text-xs mt-0.5 opacity-90"><?= htmlspecialchars($banner['msg']) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Bottom Actions -->
        <div class="flex flex-wrap gap-3">
            <a href="orders.php"
                class="px-5 py-3 rounded-xl bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-gray-700 dark:text-gray-200 text-sm font-semibold transition">
                <i class="fa-solid fa-list mr-1"></i> All Orders
            </a>
            <a href="../auction-details.php?id=<?= (int) $order['auction_id'] ?>"
                class="px-5 py-3 rounded-xl bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-gray-700 dark:text-gray-200 text-sm font-semibold transition">
                <i class="fa-solid fa-gavel mr-1"></i> View Auction
            </a>
            <button type="button" onclick="window.print()"
                class="px-5 py-3 rounded-xl bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-gray-700 dark:text-gray-200 text-sm font-semibold transition">
                <i class="fa-solid fa-print mr-1"></i> Print
            </button>
        </div>
    </main>

    <script>
        (function () {
            const btn = document.getElementById('themeToggle');
            btn?.addEventListener('click', function () {
                const isDark = document.documentElement.classList.toggle('dark');
                try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) { }
            });
        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>