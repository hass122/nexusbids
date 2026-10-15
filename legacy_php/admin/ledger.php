<?php
require_once "admin-auth.php";
require_once "../includes/notifications.php";
require_once "../includes/ledger.php";

date_default_timezone_set('Asia/Karachi');

$admin_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$admin_stmt->bind_param("i", $_SESSION["user_id"]);
$admin_stmt->execute();
$admin_info = $admin_stmt->get_result()->fetch_assoc();
$admin_name = $admin_info["full_name"] ?? "Admin";
$admin_initials = strtoupper(substr(preg_replace('/\s+/', '', $admin_name), 0, 2));
$unread_notifications = nb_unread_count($conn, intval($_SESSION["user_id"]));

if (!function_exists('safe_count')) {
    function safe_count($conn, $sql)
    {
        $r = $conn->query($sql);
        return $r ? intval($r->fetch_assoc()["total"] ?? 0) : 0;
    }
}
$total_users = safe_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role != 'admin'");
$total_auctions = safe_count($conn, "SELECT COUNT(*) AS total FROM auctions");
$total_bids = safe_count($conn, "SELECT COUNT(*) AS total FROM bids");
$total_winners = safe_count($conn, "SELECT COUNT(*) AS total FROM winners");
$total_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments");
$pending_payments = safe_count($conn, "SELECT COUNT(*) AS total FROM payments WHERE payment_status NOT IN ('paid','refunded')");

$tables = nb_ledger_tables_exist($conn);
$ledger_ready = $tables['seller_ledger'] ?? false;

$type_f = trim($_GET['type'] ?? '');
$seller_f = intval($_GET['seller'] ?? 0);
$q = trim($_GET['q'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 30;

$entries = [];
$total_filtered = 0;
$total_pages = 1;

if ($ledger_ready) {
    $where = ["1=1"];
    $params = [];
    $types = "";
    if ($type_f !== '') {
        $where[] = "l.entry_type=?";
        $params[] = $type_f;
        $types .= "s";
    }
    if ($seller_f > 0) {
        $where[] = "l.seller_id=?";
        $params[] = $seller_f;
        $types .= "i";
    }
    if ($q !== '') {
        $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR l.description LIKE ?)";
        $like = "%$q%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= "sss";
    }
    $wsql = implode(' AND ', $where);

    $cs = $conn->prepare("SELECT COUNT(*) AS c FROM seller_ledger l LEFT JOIN users u ON u.user_id=l.seller_id WHERE $wsql");
    if ($params)
        $cs->bind_param($types, ...$params);
    $cs->execute();
    $total_filtered = intval($cs->get_result()->fetch_assoc()['c'] ?? 0);
    $cs->close();

    $total_pages = max(1, (int) ceil($total_filtered / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $lq = "SELECT l.*, u.full_name AS seller_name, u.email AS seller_email
         FROM seller_ledger l LEFT JOIN users u ON u.user_id=l.seller_id
         WHERE $wsql ORDER BY l.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    $ls = $conn->prepare($lq);
    $ls->bind_param($types, ...$params);
    $ls->execute();
    $entries = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
    $ls->close();
}

/* Stats */
$lstats = ['total_entries' => 0, 'total_credit' => 0, 'total_debit' => 0];
if ($ledger_ready) {
    try {
        $r = $conn->query("SELECT COUNT(*) AS c, COALESCE(SUM(CASE WHEN entry_type IN ('credit','release','dispute_release') THEN amount ELSE 0 END),0) AS credit, COALESCE(SUM(CASE WHEN entry_type IN ('debit','fee','payout','refund') THEN amount ELSE 0 END),0) AS debit FROM seller_ledger");
        if ($row = $r->fetch_assoc()) {
            $lstats['total_entries'] = intval($row['c']);
            $lstats['total_credit'] = floatval($row['credit']);
            $lstats['total_debit'] = floatval($row['debit']);
        }
    } catch (Throwable $e) {
    }
}

function money($a)
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
function ledger_badge($t)
{
    $map = [
        'credit' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'icon' => 'fa-arrow-down', 'label' => 'Credit'],
        'debit' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'icon' => 'fa-arrow-up', 'label' => 'Debit'],
        'hold' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'icon' => 'fa-lock', 'label' => 'Hold'],
        'release' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'icon' => 'fa-unlock', 'label' => 'Release'],
        'refund' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'icon' => 'fa-rotate-left', 'label' => 'Refund'],
        'fee' => ['bg' => 'bg-slate-500/10', 'text' => 'text-slate-600 dark:text-slate-400', 'icon' => 'fa-percent', 'label' => 'Fee'],
        'payout' => ['bg' => 'bg-indigo-500/10', 'text' => 'text-indigo-700 dark:text-indigo-400', 'icon' => 'fa-money-bill-transfer', 'label' => 'Payout'],
        'adjustment' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'icon' => 'fa-sliders', 'label' => 'Adjustment'],
        'dispute_hold' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'icon' => 'fa-scale-balanced', 'label' => 'Dispute Hold'],
        'dispute_release' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'icon' => 'fa-shield', 'label' => 'Dispute Release'],
    ];
    return $map[strtolower($t)] ?? ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'icon' => 'fa-circle', 'label' => ucfirst($t)];
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Ledger · NexusBids Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } } };</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body {
            transition: background-color .3s, color .3s
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%)
        }

        .custom-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right .75rem center;
            appearance: none
        }

        .data-row:hover {
            background: rgb(238 242 255/.5)
        }

        .dark .data-row:hover {
            background: rgb(30 41 59/.5)
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen">
    <div class="flex min-h-screen">

        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="flex-1 lg:ml-64 min-w-0">
            <header
                class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
                <div class="h-20 px-4 sm:px-8 flex items-center justify-between gap-4">
                    <div>
                        <div
                            class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span> Finance
                        </div>
                        <h1 class="text-lg sm:text-xl font-black">Seller Ledger</h1>
                    </div>
                    <div class="flex items-center gap-2">
                        <button id="themeToggle"
                            class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center"><i
                                class="fa-solid fa-moon dark:hidden"></i><i
                                class="fa-solid fa-sun hidden dark:block text-amber-400"></i></button>
                        <a href="../notifications.php"
                            class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center"><i
                                class="fa-regular fa-bell"></i>
                            <?php if ($unread_notifications > 0): ?><span
                                    class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800"></span><?php endif; ?>
                        </a>
                        <div
                            class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center">
                            <?= htmlspecialchars($admin_initials) ?></div>
                    </div>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6">

                <?php if (!$ledger_ready): ?>
                    <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
                        <p class="text-xs text-amber-800 dark:text-amber-300">Ledger table not found. Run <code
                                class="px-1 bg-black/5 dark:bg-white/10 rounded font-mono">includes/payment_schema.sql</code>.
                        </p>
                    </div>
                <?php endif; ?>

                <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Entries</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-list"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($lstats['total_entries']) ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">All-time</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Credits</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-arrow-down"></i></span>
                        </div>
                        <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                            <?= money($lstats['total_credit']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Money in</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Debits</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-arrow-up"></i></span>
                        </div>
                        <div class="text-2xl font-black text-rose-600 dark:text-rose-400 tabular-nums">
                            <?= money($lstats['total_debit']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Payouts + refunds + fees</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Net</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-violet-500/10 text-violet-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-balance-scale"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums">
                            <?= money($lstats['total_credit'] - $lstats['total_debit']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Platform net flow</p>
                    </div>
                </section>

                <form method="GET"
                    class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4 flex flex-wrap gap-3 items-center">
                    <div class="relative flex-1 min-w-[200px]">
                        <i
                            class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
                            placeholder="Search seller or description..."
                            class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                    </div>
                    <select name="type"
                        class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                        <option value="">All Types</option>
                        <?php foreach (['credit' => 'Credit', 'debit' => 'Debit', 'hold' => 'Hold', 'release' => 'Release', 'refund' => 'Refund', 'fee' => 'Fee', 'payout' => 'Payout', 'adjustment' => 'Adjustment', 'dispute_hold' => 'Dispute Hold', 'dispute_release' => 'Dispute Release'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $type_f === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit"
                        class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold"><i
                            class="fa-solid fa-filter"></i></button>
                    <?php if ($q || $type_f): ?><a href="ledger.php"
                            class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 text-sm font-semibold">Reset</a><?php endif; ?>
                </form>

                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <?php if (empty($entries)): ?>
                        <div class="p-16 text-center">
                            <div
                                class="w-20 h-20 mx-auto rounded-2xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-solid fa-book"></i></div>
                            <h3 class="text-xl font-black">No ledger entries</h3>
                            <p class="text-sm text-gray-500 mt-2">Ledger will populate as orders, payouts, and refunds
                                happen.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                    <tr>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Date
                                        </th>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Seller
                                        </th>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Type
                                        </th>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">
                                            Description</th>
                                        <th class="px-5 py-4 text-right text-[10px] font-bold uppercase text-gray-500">
                                            Amount</th>
                                        <th class="px-5 py-4 text-right text-[10px] font-bold uppercase text-gray-500">
                                            Balance</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach ($entries as $e):
                                        $b = ledger_badge($e['entry_type']);
                                        $amt = floatval($e['amount']);
                                        $pos = in_array($e['entry_type'], ['credit', 'release', 'dispute_release'], true);
                                        $neg = in_array($e['entry_type'], ['debit', 'fee', 'payout', 'refund'], true);
                                        ?>
                                        <tr class="data-row">
                                            <td class="px-5 py-4">
                                                <div class="text-xs font-medium">
                                                    <?= date('d M Y', strtotime($e['created_at'])) ?></div>
                                                <div class="text-[10px] text-gray-400">
                                                    <?= date('h:i A', strtotime($e['created_at'])) ?></div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="text-xs font-bold"><?= htmlspecialchars($e['seller_name'] ?? '—') ?>
                                                </div>
                                                <div class="text-[10px] text-gray-400 truncate max-w-[140px]">
                                                    <?= htmlspecialchars($e['seller_email'] ?? '') ?></div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $b['bg'] . ' ' . $b['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                                    <i class="fa-solid <?= $b['icon'] ?> text-[9px]"></i>
                                                    <?= $b['label'] ?>
                                                </span>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="text-xs"><?= htmlspecialchars($e['description'] ?? '—') ?></div>
                                                <?php if (!empty($e['reference'])): ?>
                                                    <div class="text-[10px] text-gray-400 font-mono mt-0.5">
                                                        <?= htmlspecialchars($e['reference']) ?></div><?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4 text-right">
                                                <div
                                                    class="font-black tabular-nums <?= $pos ? 'text-emerald-600 dark:text-emerald-400' : ($neg ? 'text-rose-600 dark:text-rose-400' : '') ?>">
                                                    <?= $pos ? '+' : ($neg ? '-' : '') ?> Rs. <?= number_format($amt) ?>
                                                </div>
                                                <div class="text-[10px] text-gray-400 capitalize">
                                                    <?= htmlspecialchars($e['status'] ?? '') ?></div>
                                            </td>
                                            <td class="px-5 py-4 text-right text-xs font-bold tabular-nums">Rs.
                                                <?= number_format(floatval($e['balance_after'] ?? 0)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <div
                                class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex items-center justify-center gap-1.5">
                                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++):
                                    $q2 = ['q' => $q, 'type' => $type_f, 'page' => $p]; ?>
                                    <a href="?<?= htmlspecialchars(http_build_query($q2)) ?>"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold <?= $p === $page ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-slate-800' ?>"><?= $p ?></a>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>

    <script>
        (function () {
            const h = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && matchMedia('(prefers-color-scheme: dark)').matches)) h.classList.add('dark');
            t?.addEventListener('click', () => { h.classList.toggle('dark'); localStorage.setItem('theme', h.classList.contains('dark') ? 'dark' : 'light') });
        })();
    </script>
</body>

</html>