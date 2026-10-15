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
$order_id = intval($_GET["id"] ?? 0);

if ($order_id <= 0) { header("Location: orders.php"); exit(); }

/* ---------- SELLER INFO ---------- */
$s = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$s->bind_param("i", $seller_id);
$s->execute();
$seller_info = $s->get_result()->fetch_assoc();
$s->close();

$full_name = $seller_info["full_name"] ?? "Seller";
$avatar = $seller_info["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

/* Sidebar counts */
$sidebar_total   = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id")->fetch_assoc()["t"] ?? 0;
$sidebar_active  = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'active'")->fetch_assoc()["t"] ?? 0;
$sidebar_pending = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'pending'")->fetch_assoc()["t"] ?? 0;
$sidebar_drafts  = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'draft'")->fetch_assoc()["t"] ?? 0;

/* ---------- FETCH ORDER ---------- */
$stmt = $conn->prepare("
    SELECT o.*, a.title AS auction_title, a.image AS auction_image, a.auction_id,
           b.full_name AS buyer_name, b.email AS buyer_email
    FROM orders o
    INNER JOIN auctions a ON o.auction_id = a.auction_id
    INNER JOIN users b ON o.buyer_id = b.user_id
    WHERE o.order_id = ? AND o.seller_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $order_id, $seller_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    $_SESSION["error"] = "Order not found.";
    header("Location: orders.php");
    exit();
}

/* ---------- HANDLE POST ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"])) {
    nb_csrf_check();

    if ($_POST["action"] === "deliver") {
        $upd = $conn->prepare("
            UPDATE orders
            SET order_status = 'delivered',
                shipping_status = 'delivered',
                delivered_at = NOW()
            WHERE order_id = ? AND seller_id = ?
        ");
        $upd->bind_param("ii", $order_id, $seller_id);

        if ($upd->execute()) {
            if (function_exists('nb_notify')) {
                nb_notify(
                    $conn, $order['buyer_id'], 'shipment',
                    'Order delivered',
                    'Please confirm receipt to complete the order.',
                    'user/order-details.php?id=' . $order['order_id']
                );
            }
            $_SESSION["success"] = "Order marked as delivered.";
        } else {
            $_SESSION["error"] = "Could not update order.";
        }
        $upd->close();
        header("Location: order-details.php?id=" . $order_id);
        exit();
    }
}

/* ---------- STATUS STYLES ---------- */
$status_key = strtolower($order['order_status'] ?? 'pending');
$styles = [
    'pending'         => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Pending', 'dot' => 'bg-amber-500'],
    'payment_pending' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Payment Pending', 'dot' => 'bg-amber-500'],
    'paid'            => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'label' => 'Paid · Ready to Ship', 'dot' => 'bg-blue-500'],
    'processing'      => ['bg' => 'bg-indigo-500/10', 'text' => 'text-indigo-700 dark:text-indigo-400', 'label' => 'Processing', 'dot' => 'bg-indigo-500'],
    'ready_to_ship'   => ['bg' => 'bg-cyan-500/10', 'text' => 'text-cyan-700 dark:text-cyan-400', 'label' => 'Ready to Ship', 'dot' => 'bg-cyan-500'],
    'shipped'         => ['bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'label' => 'Shipped', 'dot' => 'bg-violet-500'],
    'in_transit'      => ['bg' => 'bg-purple-500/10', 'text' => 'text-purple-700 dark:text-purple-400', 'label' => 'In Transit', 'dot' => 'bg-purple-500'],
    'delivered'       => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Delivered', 'dot' => 'bg-emerald-500'],
    'completed'       => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Completed', 'dot' => 'bg-emerald-500'],
    'cancelled'       => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Cancelled', 'dot' => 'bg-rose-500'],
];
$style = $styles[$status_key] ?? ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Pending', 'dot' => 'bg-gray-500'];

/* Helpers */
function safe_date($d, $fmt = 'd M Y, h:i A') {
    if (empty($d) || $d === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($d);
    return $ts ? date($fmt, $ts) : '—';
}
$img_url = '';
if (!empty($order['auction_image'])) {
    $b = basename($order['auction_image']);
    $img_url = file_exists(__DIR__ . '/../uploads/auctions/' . $b)
        ? '../uploads/auctions/' . $b
        : '../uploads/auctions/' . rawurlencode($b);
}

$total_amount   = floatval($order['total_amount'] ?? $order['winning_bid'] ?? 0);
$seller_amount  = floatval($order['seller_amount'] ?? $order['winning_bid'] ?? 0);
$platform_fee   = floatval($order['platform_fee'] ?? ($total_amount - $seller_amount));
$courier        = $order['courier'] ?? '';
$tracking       = $order['tracking_number'] ?? '';
$estimated      = $order['estimated_delivery'] ?? null;

/* Has disputes table? */
$has_disputes = $conn->query("SHOW TABLES LIKE 'disputes'")->num_rows > 0;
$dispute = null;
if ($has_disputes) {
    $dq = $conn->prepare("SELECT * FROM disputes WHERE order_id = ? LIMIT 1");
    $dq->bind_param("i", $order_id);
    $dq->execute();
    $dispute = $dq->get_result()->fetch_assoc();
    $dq->close();
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = {darkMode: 'class', theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}}}};</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; transition: background-color .3s, color .3s; }
        .glass { backdrop-filter: blur(16px) saturate(180%); }
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
                        <a href="orders.php" class="hidden sm:flex w-10 h-10 rounded-xl bg-gray-100 dark:bg-slate-800 items-center justify-center hover:bg-gray-200 dark:hover:bg-slate-700">
                            <i class="fa-solid fa-arrow-left"></i>
                        </a>
                        <div class="min-w-0">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span> Order Details
                            </div>
                            <h1 class="text-lg sm:text-xl font-black truncate">
                                <?= htmlspecialchars($order['order_number'] ?? ('#' . $order_id)) ?>
                            </h1>
                        </div>
                    </div>
                    <a href="orders.php" class="hidden sm:inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 rounded-xl transition-all">
                        <i class="fa-solid fa-list text-xs"></i> All Orders
                    </a>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-5xl mx-auto">

                <?php if (!empty($_SESSION['success'])): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30">
                        <i class="fa-solid fa-circle-check text-emerald-600 dark:text-emerald-400 mt-1"></i>
                        <p class="text-sm text-emerald-700 dark:text-emerald-300 font-medium self-center">
                            <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if (!empty($_SESSION['error'])): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30">
                        <i class="fa-solid fa-circle-exclamation text-rose-600 dark:text-rose-400 mt-1"></i>
                        <p class="text-sm text-rose-700 dark:text-rose-300 font-medium self-center">
                            <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Status + Amount Banner -->
                <section class="bg-gradient-to-br from-indigo-600 via-violet-600 to-purple-600 rounded-3xl p-6 sm:p-8 text-white shadow-xl relative overflow-hidden">
                    <div class="absolute -right-16 -top-16 w-72 h-72 bg-white/10 rounded-full blur-3xl pointer-events-none"></div>
                    <div class="relative flex flex-col sm:flex-row sm:items-center justify-between gap-6">
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider text-white/70 mb-2">Order Status</div>
                            <div class="flex items-center gap-2 mb-3">
                                <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/15 backdrop-blur border border-white/20 text-white text-xs font-bold uppercase tracking-wider">
                                    <span class="w-2 h-2 rounded-full <?= $style['dot'] ?> <?= in_array($status_key, ['paid','processing','ready_to_ship','shipped','in_transit']) ? 'animate-pulse' : '' ?>"></span>
                                    <?= $style['label'] ?>
                                </span>
                            </div>
                            <p class="text-xs text-white/80 max-w-md leading-relaxed">
                                Placed on <?= safe_date($order['created_at']) ?>
                                <?php if (!empty($order['paid_at'])): ?> · Paid on <?= safe_date($order['paid_at']) ?><?php endif; ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-white/70 mb-1">Your Earnings</div>
                            <div class="text-3xl sm:text-4xl font-black tabular-nums">Rs. <?= number_format($seller_amount) ?></div>
                            <div class="text-[11px] text-white/70 mt-1">Out of Rs. <?= number_format($total_amount) ?> total</div>
                        </div>
                    </div>
                </section>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    <!-- Left: Item + Buyer + Shipping -->
                    <div class="lg:col-span-2 space-y-6">

                        <!-- Item -->
                        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <span class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-box"></i>
                                </span>
                                <div>
                                    <h3 class="text-base font-bold">Item</h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Product in this order</p>
                                </div>
                            </div>
                            <div class="p-5 sm:p-6 flex items-center gap-4">
                                <?php if ($img_url): ?>
                                    <img src="<?= htmlspecialchars($img_url) ?>" class="w-20 h-20 rounded-2xl object-cover shrink-0 border border-gray-200 dark:border-gray-700" alt="">
                                <?php else: ?>
                                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-2xl shrink-0">
                                        <i class="fa-solid fa-image"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="min-w-0 flex-1">
                                    <a href="../auction-details.php?id=<?= (int) $order['auction_id'] ?>" class="font-bold text-base text-gray-900 dark:text-white hover:text-indigo-600 dark:hover:text-indigo-400 line-clamp-2 block">
                                        <?= htmlspecialchars($order['auction_title']) ?>
                                    </a>
                                    <div class="text-[11px] text-gray-500 dark:text-gray-400 font-mono mt-1">
                                        Auction # <?= str_pad((int) $order['auction_id'], 5, '0', STR_PAD_LEFT) ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Buyer -->
                        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <span class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-user"></i>
                                </span>
                                <div>
                                    <h3 class="text-base font-bold">Buyer</h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Contact information</p>
                                </div>
                            </div>
                            <div class="p-5 sm:p-6 space-y-3">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-gray-500 dark:text-gray-400 text-xs font-bold uppercase tracking-wider">Name</span>
                                    <span class="font-bold"><?= htmlspecialchars($order['buyer_name'] ?? '—') ?></span>
                                </div>
                                <?php if (!empty($order['buyer_email'])): ?>
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-gray-500 dark:text-gray-400 text-xs font-bold uppercase tracking-wider">Email</span>
                                        <a href="mailto:<?= htmlspecialchars($order['buyer_email']) ?>" class="font-medium text-indigo-600 dark:text-indigo-400 hover:underline truncate max-w-[200px]">
                                            <?= htmlspecialchars($order['buyer_email']) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Shipping -->
                        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
                                <div class="flex items-center gap-3">
                                    <span class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                                        <i class="fa-solid fa-truck-fast"></i>
                                    </span>
                                    <div>
                                        <h3 class="text-base font-bold">Shipping</h3>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Courier & tracking details</p>
                                    </div>
                                </div>
                                <?php if (in_array($status_key, ['paid', 'processing', 'ready_to_ship'], true)): ?>
                                    <a href="ship-order.php?id=<?= (int) $order_id ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition-all">
                                        <i class="fa-solid fa-truck-fast"></i> Ship Now
                                    </a>
                                <?php elseif (in_array($status_key, ['shipped', 'in_transit'], true)): ?>
                                    <form method="POST" onsubmit="return confirm('Mark this order as delivered?');">
                                        <?php nb_csrf_field(); ?>
                                        <input type="hidden" name="action" value="deliver">
                                        <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-violet-600 hover:bg-violet-500 text-white text-xs font-bold transition-all">
                                            <i class="fa-solid fa-check"></i> Mark Delivered
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <div class="p-5 sm:p-6">
                                <?php if (!empty($courier) || !empty($tracking)): ?>
                                    <div class="space-y-3">
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-gray-500 dark:text-gray-400 text-xs font-bold uppercase tracking-wider">Courier</span>
                                            <span class="font-bold"><?= htmlspecialchars($courier ?: '—') ?></span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-gray-500 dark:text-gray-400 text-xs font-bold uppercase tracking-wider">Tracking #</span>
                                            <span class="font-mono font-bold text-indigo-600 dark:text-indigo-400"><?= htmlspecialchars($tracking ?: '—') ?></span>
                                        </div>
                                        <?php if (!empty($order['shipped_at'])): ?>
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-gray-500 dark:text-gray-400 text-xs font-bold uppercase tracking-wider">Shipped</span>
                                                <span class="text-xs text-gray-700 dark:text-gray-300"><?= safe_date($order['shipped_at']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($estimated)): ?>
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-gray-500 dark:text-gray-400 text-xs font-bold uppercase tracking-wider">Est. Delivery</span>
                                                <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400"><?= safe_date($estimated, 'd M Y') ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($order['delivered_at'])): ?>
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-gray-500 dark:text-gray-400 text-xs font-bold uppercase tracking-wider">Delivered</span>
                                                <span class="text-xs text-gray-700 dark:text-gray-300"><?= safe_date($order['delivered_at']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <div class="w-14 h-14 rounded-2xl bg-gray-100 dark:bg-slate-800 text-gray-400 flex items-center justify-center text-xl mx-auto mb-3">
                                            <i class="fa-solid fa-box-open"></i>
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">No shipping info yet.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- [NEW] Dispute card (if exists) -->
                        <?php if ($dispute): ?>
                            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-rose-500/30 shadow-sm overflow-hidden">
                                <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                    <span class="w-10 h-10 rounded-xl bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-sm">
                                        <i class="fa-solid fa-scale-balanced"></i>
                                    </span>
                                    <div>
                                        <h3 class="text-base font-bold">Dispute on this Order</h3>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Buyer reported an issue</p>
                                    </div>
                                </div>
                                <div class="p-5 sm:p-6 space-y-3">
                                    <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/20">
                                        <div class="text-[10px] font-bold uppercase tracking-wider text-rose-600 dark:text-rose-400 mb-1">
                                            <i class="fa-solid fa-flag text-[9px]"></i> <?= htmlspecialchars($dispute['reason']) ?>
                                        </div>
                                        <p class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed"><?= nl2br(htmlspecialchars($dispute['description'] ?? '')) ?></p>
                                    </div>
                                    <a href="disputes.php" class="inline-flex items-center gap-1.5 text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline">
                                        <i class="fa-solid fa-eye text-[9px]"></i> View in Disputes Dashboard
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right: Summary -->
                    <div class="space-y-6">

                        <!-- Payment Summary -->
                        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <span class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-coins"></i>
                                </span>
                                <div>
                                    <h3 class="text-sm font-bold">Payment Summary</h3>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">Breakdown</p>
                                </div>
                            </div>
                            <div class="p-5 space-y-3 text-sm">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500 dark:text-gray-400 text-xs">Winning Bid</span>
                                    <span class="font-bold tabular-nums">Rs. <?= number_format($total_amount) ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500 dark:text-gray-400 text-xs">Platform Fee</span>
                                    <span class="font-bold text-rose-600 dark:text-rose-400 tabular-nums">- Rs. <?= number_format(max(0, $platform_fee)) ?></span>
                                </div>
                                <div class="pt-3 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between">
                                    <span class="font-bold text-xs uppercase tracking-wider">Your Earnings</span>
                                    <span class="font-black text-emerald-600 dark:text-emerald-400 tabular-nums text-base">Rs. <?= number_format($seller_amount) ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Timeline -->
                        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <span class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-timeline"></i>
                                </span>
                                <div>
                                    <h3 class="text-sm font-bold">Timeline</h3>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">Order progress</p>
                                </div>
                            </div>
                            <div class="p-5 space-y-3">
                                <?php
                                $timeline = [
                                    ['Order placed',   $order['created_at'] ?? null,    true],
                                    ['Payment received', $order['paid_at'] ?? null,      !empty($order['paid_at'])],
                                    ['Shipped',        $order['shipped_at'] ?? null,     !empty($order['shipped_at'])],
                                    ['Delivered',      $order['delivered_at'] ?? null,   !empty($order['delivered_at'])],
                                ];
                                foreach ($timeline as $i => [$label, $ts, $done]):
                                ?>
                                    <div class="flex items-start gap-3">
                                        <div class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-black shrink-0 <?= $done ? 'bg-emerald-500 text-white' : 'bg-gray-200 dark:bg-slate-800 text-gray-400' ?>">
                                            <?php if ($done): ?><i class="fa-solid fa-check"></i><?php else: ?><?= $i + 1 ?><?php endif; ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-xs font-bold <?= $done ? 'text-gray-900 dark:text-white' : 'text-gray-400' ?>"><?= $label ?></div>
                                            <?php if ($ts): ?>
                                                <div class="text-[10px] text-gray-400 mt-0.5"><?= safe_date($ts) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 space-y-2">
                            <a href="orders.php" class="flex items-center gap-3 p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors">
                                <span class="w-8 h-8 rounded-lg bg-gray-500/10 text-gray-600 dark:text-gray-400 flex items-center justify-center text-xs"><i class="fa-solid fa-list"></i></span>
                                <span class="text-sm font-medium">All Orders</span>
                            </a>
                            <a href="../auction-details.php?id=<?= (int) $order['auction_id'] ?>" class="flex items-center gap-3 p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors">
                                <span class="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs"><i class="fa-solid fa-eye"></i></span>
                                <span class="text-sm font-medium">View Auction</span>
                            </a>
                            <a href="disputes.php" class="flex items-center gap-3 p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors">
                                <span class="w-8 h-8 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-xs"><i class="fa-solid fa-scale-balanced"></i></span>
                                <span class="text-sm font-medium">Disputes</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="mt-8 border-t border-gray-200 dark:border-gray-800 px-4 sm:px-8 py-6">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                    <p>© <?= date("Y") ?> NexusBids Seller Panel.</p>
                    <p class="flex items-center gap-1.5">Seller Console <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span></p>
                </div>
            </footer>
        </main>
    </div>
<?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>