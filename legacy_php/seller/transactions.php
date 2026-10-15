<?php

session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/ledger.php";

date_default_timezone_set('Asia/Karachi');

$seller_id = intval($_SESSION["user_id"]);

$s = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$s->bind_param("i", $seller_id);
$s->execute();
$seller = $s->get_result()->fetch_assoc();
$s->close();

$full_name = $seller["full_name"] ?? "Seller";
$avatar = $seller["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

$sidebar_total = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id")->fetch_assoc()["t"] ?? 0;
$sidebar_active = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'active'")->fetch_assoc()["t"] ?? 0;
$sidebar_pending = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'pending'")->fetch_assoc()["t"] ?? 0;
$sidebar_drafts = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'draft'")->fetch_assoc()["t"] ?? 0;

$tables = nb_ledger_tables_exist($conn);
$txn_ready = $tables['payment_transactions'] ?? false;

$status_f = trim($_GET['status'] ?? '');
$txns = nb_txn_list($conn, $seller_id, ['status' => $status_f, 'limit' => 200]);

if (!$txn_ready) {
    try {
        $txns = $conn->query("
            SELECT o.order_id, o.order_number, o.total_amount AS amount, o.seller_amount,
                   o.order_status AS status, o.created_at, o.paid_at,
                   'manual' AS gateway, o.payment_method, o.payment_reference AS gateway_txn_id,
                   a.title AS auction_title
            FROM orders o
            LEFT JOIN auctions a ON o.auction_id = a.auction_id
            WHERE o.seller_id = $seller_id
            ORDER BY o.created_at DESC
            LIMIT 200
        ")->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $e) {
        $txns = [];
    }
}

function nb_money($a)
{
    $a = floatval($a);
    if ($a >= 10000000)
        return 'Rs. ' . round($a / 10000000, 1) . 'Cr';
    if ($a >= 100000)
        return 'Rs. ' . round($a / 100000, 1) . 'L';
    if ($a >= 1000)
        return 'Rs. ' . round($a / 1000) . 'K';
    return 'Rs. ' . number_format($a);
}
function nb_date($d, $f = 'd M Y, h:i A')
{
    if (empty($d))
        return '—';
    $ts = strtotime($d);
    return $ts ? date($f, $ts) : '—';
}

$status_map = [
    'initiated' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Initiated', 'dot' => 'bg-gray-500'],
    'authorized' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'label' => 'Authorized', 'dot' => 'bg-blue-500'],
    'captured' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Captured', 'dot' => 'bg-emerald-500'],
    'paid' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Paid', 'dot' => 'bg-emerald-500'],
    'failed' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Failed', 'dot' => 'bg-rose-500'],
    'refunded' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Refunded', 'dot' => 'bg-amber-500'],
    'partially_refunded' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Partial Refund', 'dot' => 'bg-amber-500'],
    'disputed' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Disputed', 'dot' => 'bg-rose-500'],
    'chargeback' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Chargeback', 'dot' => 'bg-rose-500'],
    'cancelled' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Cancelled', 'dot' => 'bg-gray-500'],
];
$default_style = ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Unknown', 'dot' => 'bg-gray-500'];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Transactions · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } } };</script>
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
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">
    <div class="flex min-h-screen">

        <?php $sidebar_active_page = 'transactions';
        include __DIR__ . '/../includes/seller-sidebar.php'; ?>

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
                            <h1 class="text-lg sm:text-xl font-black truncate">Payment Transactions</h1>
                        </div>
                    </div>
                    <button id="themeToggle"
                        class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                        <i class="fa-solid fa-moon dark:hidden"></i><i
                            class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                    </button>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-6xl mx-auto">

                <section>
                    <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-500 flex items-center justify-center text-white shadow-lg">
                            <i class="fa-solid fa-credit-card text-base"></i>
                        </span>
                        Payment Transactions
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Gateway payments received on your orders —
                        with fees, net amount, and status.</p>
                </section>

                <?php if (!$txn_ready): ?>
                    <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
                        <p class="text-xs text-amber-800 dark:text-amber-300">Showing derived data from orders. Run <code
                                class="px-1 bg-black/5 dark:bg-white/10 rounded font-mono">includes/payment_schema.sql</code>
                            for full gateway records.</p>
                    </div>
                <?php endif; ?>

                <form method="GET"
                    class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4 flex flex-wrap items-center gap-3">
                    <select name="status" onchange="this.form.submit()"
                        class="px-3 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                        <option value="">All Status</option>
                        <?php foreach (['initiated' => 'Initiated', 'authorized' => 'Authorized', 'captured' => 'Captured', 'failed' => 'Failed', 'refunded' => 'Refunded', 'partially_refunded' => 'Partially Refunded', 'disputed' => 'Disputed', 'chargeback' => 'Chargeback', 'cancelled' => 'Cancelled'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $status_f === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($status_f): ?>
                        <a href="transactions.php"
                            class="px-3 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 text-xs font-bold">
                            <i class="fa-solid fa-rotate-right"></i> Reset
                        </a>
                    <?php endif; ?>
                </form>

                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <?php if (empty($txns)): ?>
                        <div class="p-16 text-center">
                            <div
                                class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-indigo-500/10 to-purple-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-solid fa-credit-card"></i>
                            </div>
                            <h3 class="text-xl font-black">No transactions</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Payments received on your orders will
                                appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                    <tr>
                                        <th
                                            class="px-5 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Order / Item</th>
                                        <th
                                            class="px-5 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Gateway</th>
                                        <th
                                            class="px-5 py-4 text-right text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Amount</th>
                                        <th
                                            class="px-5 py-4 text-right text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Fee</th>
                                        <th
                                            class="px-5 py-4 text-right text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Your Net</th>
                                        <th
                                            class="px-5 py-4 text-center text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach ($txns as $t):
                                        $st = strtolower($t['status'] ?? 'initiated');
                                        $style = $status_map[$st] ?? $default_style;
                                        $amt = floatval($t['amount'] ?? 0);
                                        $fee = floatval($t['platform_fee'] ?? 0) + floatval($t['gateway_fee'] ?? 0);
                                        $net = floatval($t['seller_amount'] ?? ($amt - $fee));
                                        ?>
                                        <tr class="data-row">
                                            <td class="px-5 py-4">
                                                <a href="order-details.php?id=<?= (int) $t['order_id'] ?>"
                                                    class="text-xs font-semibold hover:text-indigo-600 line-clamp-1 max-w-[200px] block">
                                                    <?= htmlspecialchars($t['auction_title'] ?? 'Order') ?>
                                                </a>
                                                <div class="text-[10px] text-gray-400 font-mono mt-0.5">
                                                    <?= htmlspecialchars($t['order_number'] ?? ('#' . $t['order_id'])) ?>
                                                </div>
                                                <div class="text-[10px] text-gray-400 mt-0.5">
                                                    <?= nb_date($t['created_at'] ?? '') ?></div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="text-xs font-bold capitalize">
                                                    <?= htmlspecialchars($t['gateway'] ?? 'manual') ?></div>
                                                <?php if (!empty($t['payment_method'])): ?>
                                                    <div class="text-[10px] text-gray-400 capitalize">
                                                        <?= htmlspecialchars($t['payment_method']) ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($t['gateway_txn_id'])): ?>
                                                    <div class="text-[10px] text-gray-400 font-mono truncate max-w-[130px]">
                                                        <?= htmlspecialchars(substr($t['gateway_txn_id'], 0, 18)) ?>…</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4 text-right font-bold tabular-nums">Rs.
                                                <?= number_format($amt) ?></td>
                                            <td
                                                class="px-5 py-4 text-right tabular-nums text-rose-600 dark:text-rose-400 text-xs">
                                                - Rs. <?= number_format($fee) ?></td>
                                            <td
                                                class="px-5 py-4 text-right font-black tabular-nums text-emerald-600 dark:text-emerald-400">
                                                Rs. <?= number_format($net) ?></td>
                                            <td class="px-5 py-4 text-center">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $style['bg'] . ' ' . $style['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                                    <span class="w-1.5 h-1.5 rounded-full <?= $style['dot'] ?>"></span>
                                                    <?= $style['label'] ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
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