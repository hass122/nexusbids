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

$type_f = trim($_GET['type'] ?? '');
$cat_f = trim($_GET['category'] ?? '');
$from_f = trim($_GET['from'] ?? '');
$to_f = trim($_GET['to'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

$filters = ['type' => $type_f, 'category' => $cat_f, 'from' => $from_f, 'to' => $to_f];
$total_entries = nb_ledger_count($conn, $seller_id, $filters);
$total_pages = max(1, (int) ceil($total_entries / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$entries = nb_ledger_list($conn, $seller_id, array_merge($filters, ['limit' => $per_page, 'offset' => $offset]));

$tables = nb_ledger_tables_exist($conn);
$ledger_ready = $tables['seller_ledger'] ?? false;

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
function nb_build_url(array $o = [])
{
    return '?' . http_build_query(array_merge($_GET, $o));
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Earnings & Ledger · NexusBids</title>
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

        <?php $sidebar_active_page = 'earnings';
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
                            <h1 class="text-lg sm:text-xl font-black truncate">Earnings & Ledger</h1>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="payouts.php"
                            class="hidden sm:inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl">
                            <i class="fa-solid fa-wallet text-xs"></i> Payouts
                        </a>
                        <button id="themeToggle"
                            class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                            <i class="fa-solid fa-moon dark:hidden"></i><i
                                class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                        </button>
                    </div>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-6xl mx-auto">

                <section>
                    <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                        <a href="dashboard.php" class="hover:text-indigo-600">Dashboard</a>
                        <i class="fa-solid fa-chevron-right text-[8px]"></i>
                        <span class="text-gray-900 dark:text-white font-semibold">Earnings</span>
                    </nav>
                    <h2 class="text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-3">
                        <span
                            class="w-11 h-11 rounded-2xl bg-gradient-to-br from-emerald-500 to-cyan-500 flex items-center justify-center text-white shadow-lg">
                            <i class="fa-solid fa-coins text-base"></i>
                        </span>
                        Money & Ledger
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Complete history of every credit, hold,
                        release, fee, and payout.</p>
                </section>

                <?php if (!$ledger_ready): ?>
                    <div class="p-5 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
                        <div class="text-xs text-amber-800 dark:text-amber-300 leading-relaxed">
                            <strong>Ledger tables not found.</strong> Balances below are calculated from orders & payouts.
                            Run <code
                                class="px-1 bg-black/5 dark:bg-white/10 rounded font-mono">includes/payment_schema.sql</code>
                            to enable the full ledger.
                        </div>
                    </div>
                <?php endif; ?>

                <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div
                        class="bg-gradient-to-br from-indigo-600 to-violet-600 rounded-3xl p-5 text-white shadow-xl relative overflow-hidden col-span-2 lg:col-span-1">
                        <div
                            class="absolute -right-8 -top-8 w-28 h-28 bg-white/10 rounded-full blur-2xl pointer-events-none">
                        </div>
                        <div class="relative">
                            <div class="flex items-center justify-between mb-2">
                                <span
                                    class="text-[10px] font-bold uppercase tracking-wider text-white/80">Available</span>
                                <i class="fa-solid fa-wallet text-white/70"></i>
                            </div>
                            <div class="text-2xl font-black tabular-nums"><?= nb_money($balances['available']) ?></div>
                            <p class="text-[11px] text-white/80 mt-1.5">Ready to withdraw</p>
                        </div>
                    </div>

                    <div
                        class="bg-white dark:bg-slate-900 rounded-3xl p-5 border border-gray-200 dark:border-gray-800 shadow-sm">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">In Escrow</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-lock"></i></span>
                        </div>
                        <div class="text-xl font-black tabular-nums"><?= nb_money($balances['held']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Held until delivery</p>
                    </div>

                    <div
                        class="bg-white dark:bg-slate-900 rounded-3xl p-5 border border-gray-200 dark:border-gray-800 shadow-sm">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Pending</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-hourglass-half"></i></span>
                        </div>
                        <div class="text-xl font-black tabular-nums"><?= nb_money($balances['pending']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Delivered, not cleared</p>
                    </div>

                    <div
                        class="bg-white dark:bg-slate-900 rounded-3xl p-5 border border-gray-200 dark:border-gray-800 shadow-sm">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">In Dispute</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-scale-balanced"></i></span>
                        </div>
                        <div class="text-xl font-black tabular-nums"><?= nb_money($balances['in_dispute']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Frozen for review</p>
                    </div>
                </section>

                <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-gray-200 dark:border-gray-800">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">Lifetime Earned
                        </div>
                        <div class="text-lg font-black tabular-nums text-emerald-600 dark:text-emerald-400">
                            <?= nb_money($balances['total_earned']) ?></div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-gray-200 dark:border-gray-800">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">Total Withdrawn
                        </div>
                        <div class="text-lg font-black tabular-nums"><?= nb_money($balances['total_withdrawn']) ?></div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-gray-200 dark:border-gray-800">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">Pending Payouts
                        </div>
                        <div class="text-lg font-black tabular-nums text-amber-600 dark:text-amber-400">
                            <?= nb_money($balances['pending_payout']) ?></div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-gray-200 dark:border-gray-800">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">Total Entries
                        </div>
                        <div class="text-lg font-black tabular-nums"><?= number_format($total_entries) ?></div>
                    </div>
                </section>

                <form method="GET"
                    class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4 grid grid-cols-1 sm:grid-cols-5 gap-3">
                    <select name="type"
                        class="px-3 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                        <option value="">All Types</option>
                        <?php foreach (['credit' => 'Credit', 'debit' => 'Debit', 'hold' => 'Hold', 'release' => 'Release', 'refund' => 'Refund', 'fee' => 'Fee', 'payout' => 'Payout', 'adjustment' => 'Adjustment', 'dispute_hold' => 'Dispute Hold', 'dispute_release' => 'Dispute Release'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $type_f === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="category" value="<?= htmlspecialchars($cat_f) ?>" placeholder="Category"
                        class="px-3 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                    <input type="date" name="from" value="<?= htmlspecialchars($from_f) ?>"
                        class="px-3 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                    <input type="date" name="to" value="<?= htmlspecialchars($to_f) ?>"
                        class="px-3 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                    <div class="flex gap-2">
                        <button type="submit"
                            class="flex-1 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold">
                            <i class="fa-solid fa-filter mr-1"></i> Apply
                        </button>
                        <?php if ($type_f || $cat_f || $from_f || $to_f): ?>
                            <a href="earnings.php"
                                class="px-3 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-xs font-bold">
                                <i class="fa-solid fa-rotate-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <?php if (empty($entries)): ?>
                        <div class="p-16 text-center">
                            <div
                                class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-emerald-500/10 to-cyan-500/10 text-emerald-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                            <h3 class="text-xl font-black">No ledger entries</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 max-w-md mx-auto">
                                <?= $ledger_ready ? 'Ledger entries will appear here as orders, payouts, and refunds happen.' : 'Run the payment schema migration to start tracking.' ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                    <tr>
                                        <th
                                            class="px-5 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Date</th>
                                        <th
                                            class="px-5 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Type</th>
                                        <th
                                            class="px-5 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Description</th>
                                        <th
                                            class="px-5 py-4 text-right text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Amount</th>
                                        <th
                                            class="px-5 py-4 text-right text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Balance</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach ($entries as $e):
                                        $d = nb_ledger_display($e);
                                        $amt = floatval($e['amount']);
                                        $positive = in_array($e['entry_type'], ['credit', 'release', 'dispute_release'], true);
                                        $negative = in_array($e['entry_type'], ['debit', 'fee', 'payout', 'refund'], true);
                                        ?>
                                        <tr class="data-row">
                                            <td class="px-5 py-4">
                                                <div class="text-xs font-medium"><?= nb_date($e['created_at'], 'd M Y') ?></div>
                                                <div class="text-[10px] text-gray-400"><?= nb_date($e['created_at'], 'h:i A') ?>
                                                </div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $d['bg'] . ' ' . $d['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                                    <i class="fa-solid <?= $d['icon'] ?> text-[9px]"></i> <?= $d['label'] ?>
                                                </span>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="text-xs"><?= htmlspecialchars($e['description'] ?? '—') ?></div>
                                                <?php if (!empty($e['reference'])): ?>
                                                    <div class="text-[10px] text-gray-400 font-mono mt-0.5">Ref:
                                                        <?= htmlspecialchars($e['reference']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4 text-right">
                                                <div
                                                    class="font-black tabular-nums text-sm <?= $positive ? 'text-emerald-600 dark:text-emerald-400' : ($negative ? 'text-rose-600 dark:text-rose-400' : 'text-gray-700 dark:text-gray-300') ?>">
                                                    <?= $positive ? '+' : ($negative ? '-' : '') ?> Rs.
                                                    <?= number_format($amt) ?>
                                                </div>
                                                <?php if (!empty($e['status'])): ?>
                                                    <div class="text-[10px] text-gray-400 mt-0.5 capitalize">
                                                        <?= htmlspecialchars($e['status']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4 text-right">
                                                <div class="text-xs font-bold tabular-nums">Rs.
                                                    <?= number_format(floatval($e['balance_after'] ?? 0)) ?></div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <div
                                class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex items-center justify-center gap-1.5">
                                <?php if ($page > 1): ?>
                                    <a href="<?= htmlspecialchars(nb_build_url(['page' => $page - 1])) ?>"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100 dark:hover:bg-slate-700">
                                        <i class="fa-solid fa-chevron-left text-xs"></i>
                                    </a>
                                <?php endif; ?>
                                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++):
                                    $is = $p === $page; ?>
                                    <a href="<?= htmlspecialchars(nb_build_url(['page' => $p])) ?>"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold <?= $is ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100 dark:hover:bg-slate-700' ?>"><?= $p ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="<?= htmlspecialchars(nb_build_url(['page' => $page + 1])) ?>"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center bg-gray-100 dark:bg-slate-800 hover:bg-indigo-100 dark:hover:bg-slate-700">
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
            const html = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            t?.addEventListener('click', () => { html.classList.toggle('dark'); localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light') });
        })();
    </script>
<?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>