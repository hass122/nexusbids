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

$balances = nb_ledger_get_balances($conn, $seller_id);
$tables = nb_ledger_tables_exist($conn);
$escrow_ready = $tables['escrow_holds'] ?? false;

$status_f = trim($_GET['status'] ?? '');
$holds = nb_escrow_list($conn, $seller_id, ['status' => $status_f, 'limit' => 200]);

if (!$escrow_ready) {
    try {
        $holds = $conn->query("
            SELECT o.order_id, o.seller_amount AS amount, 0 AS released_amount, 0 AS refunded_amount,
                   'held' AS status, o.created_at, o.order_status,
                   o.order_number, o.total_amount,
                   a.title AS auction_title, a.image AS auction_image
            FROM orders o
            LEFT JOIN auctions a ON o.auction_id = a.auction_id
            WHERE o.seller_id = $seller_id
              AND o.order_status IN ('paid','processing','ready_to_ship','shipped','in_transit')
            ORDER BY o.created_at DESC
            LIMIT 200
        ")->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $e) {
        $holds = [];
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
function nb_date($d, $f = 'd M Y')
{
    if (empty($d))
        return '—';
    $ts = strtotime($d);
    return $ts ? date($f, $ts) : '—';
}

$status_map = [
    'held' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Held', 'dot' => 'bg-amber-500'],
    'partially_released' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'label' => 'Partially Released', 'dot' => 'bg-blue-500'],
    'released' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Released', 'dot' => 'bg-emerald-500'],
    'refunded' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Refunded', 'dot' => 'bg-rose-500'],
    'disputed' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Disputed', 'dot' => 'bg-rose-500'],
    'cancelled' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Cancelled', 'dot' => 'bg-gray-500'],
];
$default = ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Unknown', 'dot' => 'bg-gray-500'];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Escrow Holds · NexusBids</title>
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

        <?php $sidebar_active_page = 'escrow';
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
                            <h1 class="text-lg sm:text-xl font-black truncate">Escrow Holds</h1>
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
                            class="w-11 h-11 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-500 flex items-center justify-center text-white shadow-lg">
                            <i class="fa-solid fa-lock text-base"></i>
                        </span>
                        Escrow Holds
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Funds held until buyer confirms delivery.
                        Released after successful completion.</p>
                </section>

                <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-gradient-to-br from-amber-500 to-orange-500 rounded-3xl p-6 text-white shadow-xl">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-white/80 mb-1.5">Currently Held
                        </div>
                        <div class="text-3xl font-black tabular-nums"><?= nb_money($balances['held']) ?></div>
                        <p class="text-[11px] text-white/80 mt-2">Waiting for delivery</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-gray-200 dark:border-gray-800">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1.5">Active Holds
                        </div>
                        <div class="text-3xl font-black tabular-nums"><?= count($holds) ?></div>
                        <p class="text-[11px] text-gray-500 mt-2">Escrow records</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-gray-200 dark:border-gray-800">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1.5">Next Release
                        </div>
                        <div class="text-3xl font-black tabular-nums">
                            <?php
                            $next = null;
                            foreach ($holds as $h) {
                                if (!empty($h['auto_release_at'])) {
                                    $next = $h['auto_release_at'];
                                    break;
                                }
                            }
                            echo $next ? nb_date($next, 'd M') : '—';
                            ?>
                        </div>
                        <p class="text-[11px] text-gray-500 mt-2">Auto-release date</p>
                    </div>
                </section>

                <?php if (!$escrow_ready): ?>
                    <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
                        <p class="text-xs text-amber-800 dark:text-amber-300">Showing derived holds from paid orders. Run
                            <code
                                class="px-1 bg-black/5 dark:bg-white/10 rounded font-mono">includes/payment_schema.sql</code>
                            for full escrow tracking.</p>
                    </div>
                <?php endif; ?>

                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <?php if (empty($holds)): ?>
                        <div class="p-16 text-center">
                            <div
                                class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-amber-500/10 to-orange-500/10 text-amber-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-solid fa-lock-open"></i>
                            </div>
                            <h3 class="text-xl font-black">No escrow holds</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Money from paid orders will appear here
                                until delivery is confirmed.</p>
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
                                            Held On</th>
                                        <th
                                            class="px-5 py-4 text-right text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Held</th>
                                        <th
                                            class="px-5 py-4 text-right text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Released</th>
                                        <th
                                            class="px-5 py-4 text-center text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach ($holds as $h):
                                        $st = strtolower($h['status'] ?? 'held');
                                        $style = $status_map[$st] ?? $default;
                                        $amt = floatval($h['amount'] ?? 0);
                                        $rel = floatval($h['released_amount'] ?? 0);
                                        ?>
                                        <tr class="data-row">
                                            <td class="px-5 py-4">
                                                <div class="flex items-center gap-3">
                                                    <?php if (!empty($h['auction_image'])): ?>
                                                        <img src="../uploads/auctions/<?= htmlspecialchars($h['auction_image']) ?>"
                                                            class="w-10 h-10 rounded-lg object-cover shrink-0" alt="">
                                                    <?php else: ?>
                                                        <div
                                                            class="w-10 h-10 rounded-lg bg-amber-500/10 text-amber-500 flex items-center justify-center text-xs shrink-0">
                                                            <i class="fa-solid fa-box"></i></div>
                                                    <?php endif; ?>
                                                    <div class="min-w-0">
                                                        <a href="order-details.php?id=<?= (int) $h['order_id'] ?>"
                                                            class="font-semibold text-xs hover:text-indigo-600 line-clamp-1 max-w-[220px]">
                                                            <?= htmlspecialchars($h['auction_title'] ?? $h['order_number'] ?? 'Order #' . $h['order_id']) ?>
                                                        </a>
                                                        <div class="text-[10px] text-gray-400 font-mono">
                                                            <?= htmlspecialchars($h['order_number'] ?? ('#' . $h['order_id'])) ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="text-xs"><?= nb_date($h['created_at'] ?? '') ?></div>
                                                <?php if (!empty($h['auto_release_at'])): ?>
                                                    <div class="text-[10px] text-gray-400">Auto:
                                                        <?= nb_date($h['auto_release_at']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4 text-right font-black tabular-nums text-sm">Rs.
                                                <?= number_format($amt) ?></td>
                                            <td
                                                class="px-5 py-4 text-right font-bold tabular-nums text-sm text-emerald-600 dark:text-emerald-400">
                                                <?= $rel > 0 ? 'Rs. ' . number_format($rel) : '—' ?>
                                            </td>
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