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
$txn_ready = $tables['payment_transactions'] ?? false;

$status_f = trim($_GET['status'] ?? '');
$q = trim($_GET['q'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

$txns = [];
$total_filtered = 0;
$total_pages = 1;

if ($txn_ready) {
    $where = ["1=1"];
    $params = [];
    $types = "";
    if ($status_f !== '') {
        $where[] = "t.status = ?";
        $params[] = $status_f;
        $types .= "s";
    }
    if ($q !== '') {
        $where[] = "(o.order_number LIKE ? OR u.full_name LIKE ? OR t.gateway_txn_id LIKE ?)";
        $like = "%$q%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= "sss";
    }
    $wsql = implode(' AND ', $where);

    $cs = $conn->prepare("SELECT COUNT(*) AS c FROM payment_transactions t LEFT JOIN orders o ON t.order_id=o.order_id LEFT JOIN users u ON t.buyer_id=u.user_id WHERE $wsql");
    if ($params)
        $cs->bind_param($types, ...$params);
    $cs->execute();
    $total_filtered = intval($cs->get_result()->fetch_assoc()['c'] ?? 0);
    $cs->close();

    $total_pages = max(1, (int) ceil($total_filtered / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $lq = "SELECT t.*, o.order_number, u.full_name AS buyer_name, u.email AS buyer_email,
                s.full_name AS seller_name, a.title AS auction_title
         FROM payment_transactions t
         LEFT JOIN orders o ON t.order_id=o.order_id
         LEFT JOIN auctions a ON o.auction_id=a.auction_id
         LEFT JOIN users u ON t.buyer_id=u.user_id
         LEFT JOIN users s ON t.seller_id=s.user_id
         WHERE $wsql ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    $ls = $conn->prepare($lq);
    $ls->bind_param($types, ...$params);
    $ls->execute();
    $txns = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
    $ls->close();
}

/* Stats */
$stats = ['total' => 0, 'captured' => 0, 'failed' => 0, 'refunded' => 0, 'volume' => 0, 'fee' => 0];
if ($txn_ready) {
    try {
        $r = $conn->query("SELECT status, COUNT(*) AS c, COALESCE(SUM(amount),0) AS amt, COALESCE(SUM(platform_fee+gateway_fee),0) AS fee FROM payment_transactions GROUP BY status");
        while ($row = $r->fetch_assoc()) {
            $stats['total'] += intval($row['c']);
            $s = strtolower($row['status']);
            if (isset($stats[$s]))
                $stats[$s] = intval($row['c']);
            $stats['volume'] += floatval($row['amt']);
            $stats['fee'] += floatval($row['fee']);
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
function txn_badge($s)
{
    $map = [
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
    return $map[strtolower($s)] ?? ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Unknown', 'dot' => 'bg-gray-500'];
}
function time_ago($dt)
{
    if (empty($dt))
        return '';
    $d = time() - strtotime($dt);
    if ($d < 60)
        return 'just now';
    if ($d < 3600)
        return intdiv($d, 60) . 'm ago';
    if ($d < 86400)
        return intdiv($d, 3600) . 'h ago';
    if ($d < 604800)
        return intdiv($d, 86400) . 'd ago';
    return date('d M Y', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions · NexusBids Admin</title>
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
                        <h1 class="text-lg sm:text-xl font-black">Payment Transactions</h1>
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

                <?php if (!$txn_ready): ?>
                    <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
                        <p class="text-xs text-amber-800 dark:text-amber-300">Transactions table not found. Run <code
                                class="px-1 bg-black/5 dark:bg-white/10 rounded font-mono">includes/payment_schema.sql</code>.
                        </p>
                    </div>
                <?php endif; ?>

                <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Volume</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-indigo-500/10 text-indigo-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-coins"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= money($stats['volume']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Lifetime processed</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Fees
                                Collected</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-percent"></i></span>
                        </div>
                        <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                            <?= money($stats['fee']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Platform + gateway</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Captured</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-blue-500/10 text-blue-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-check"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($stats['captured']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Successful</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Failed</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-xmark"></i></span>
                        </div>
                        <div class="text-2xl font-black text-rose-600 dark:text-rose-400 tabular-nums">
                            <?= number_format($stats['failed']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1"><?= number_format($stats['refunded']) ?> refunded</p>
                    </div>
                </section>

                <form method="GET"
                    class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4 flex flex-wrap gap-3 items-center">
                    <div class="relative flex-1 min-w-[200px]">
                        <i
                            class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
                            placeholder="Search order, buyer, txn id..."
                            class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                    </div>
                    <select name="status"
                        class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                        <option value="">All Status</option>
                        <option value="captured" <?= $status_f === 'captured' ? 'selected' : '' ?>>Captured</option>
                        <option value="initiated" <?= $status_f === 'initiated' ? 'selected' : '' ?>>Initiated</option>
                        <option value="authorized" <?= $status_f === 'authorized' ? 'selected' : '' ?>>Authorized</option>
                        <option value="failed" <?= $status_f === 'failed' ? 'selected' : '' ?>>Failed</option>
                        <option value="refunded" <?= $status_f === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                        <option value="disputed" <?= $status_f === 'disputed' ? 'selected' : '' ?>>Disputed</option>
                        <option value="chargeback" <?= $status_f === 'chargeback' ? 'selected' : '' ?>>Chargeback</option>
                    </select>
                    <button type="submit"
                        class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold"><i
                            class="fa-solid fa-filter"></i></button>
                </form>

                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <?php if (empty($txns)): ?>
                        <div class="p-16 text-center">
                            <div
                                class="w-20 h-20 mx-auto rounded-2xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-solid fa-credit-card"></i></div>
                            <h3 class="text-xl font-black">No transactions</h3>
                            <p class="text-sm text-gray-500 mt-2">Payment gateway transactions will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                    <tr>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Txn /
                                            Order</th>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Buyer
                                        </th>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">
                                            Gateway</th>
                                        <th class="px-5 py-4 text-right text-[10px] font-bold uppercase text-gray-500">
                                            Amount</th>
                                        <th class="px-5 py-4 text-right text-[10px] font-bold uppercase text-gray-500">Fee
                                        </th>
                                        <th class="px-5 py-4 text-center text-[10px] font-bold uppercase text-gray-500">
                                            Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach ($txns as $t):
                                        $b = txn_badge($t['status'] ?? 'initiated');
                                        $amt = floatval($t['amount'] ?? 0);
                                        $fee = floatval($t['platform_fee'] ?? 0) + floatval($t['gateway_fee'] ?? 0);
                                        $net = floatval($t['seller_amount'] ?? ($amt - $fee));
                                        ?>
                                        <tr class="data-row">
                                            <td class="px-5 py-4">
                                                <div class="font-mono text-xs font-bold">
                                                    #<?= str_pad((int) $t['id'], 5, '0', STR_PAD_LEFT) ?></div>
                                                <div class="text-[10px] text-gray-400 mt-0.5">
                                                    <?= !empty($t['order_number']) ? htmlspecialchars($t['order_number']) : '—' ?>
                                                </div>
                                                <div class="text-[10px] text-gray-400">
                                                    <?= !empty($t['created_at']) ? time_ago($t['created_at']) : '' ?></div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="text-xs font-medium">
                                                    <?= htmlspecialchars($t['buyer_name'] ?? '—') ?></div>
                                                <div class="text-[10px] text-gray-400 truncate max-w-[160px]">
                                                    <?= htmlspecialchars($t['buyer_email'] ?? '') ?></div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="text-xs font-bold capitalize">
                                                    <?= htmlspecialchars($t['gateway'] ?? 'manual') ?></div>
                                                <?php if (!empty($t['payment_method'])): ?>
                                                    <div class="text-[10px] text-gray-400 capitalize">
                                                        <?= htmlspecialchars($t['payment_method']) ?></div><?php endif; ?>
                                                <?php if (!empty($t['gateway_txn_id'])): ?>
                                                    <div class="text-[10px] text-gray-400 font-mono truncate max-w-[130px]"
                                                        title="<?= htmlspecialchars($t['gateway_txn_id']) ?>">
                                                        <?= htmlspecialchars(substr($t['gateway_txn_id'], 0, 16)) ?>…</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4 text-right">
                                                <div class="font-black tabular-nums">Rs. <?= number_format($amt) ?></div>
                                                <div class="text-[10px] text-emerald-600 dark:text-emerald-400">Net: Rs.
                                                    <?= number_format($net) ?></div>
                                            </td>
                                            <td
                                                class="px-5 py-4 text-right text-xs tabular-nums text-rose-600 dark:text-rose-400">
                                                - Rs. <?= number_format($fee) ?></td>
                                            <td class="px-5 py-4 text-center">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $b['bg'] . ' ' . $b['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                                    <span class="w-1.5 h-1.5 rounded-full <?= $b['dot'] ?>"></span>
                                                    <?= $b['label'] ?>
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
            const h = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && matchMedia('(prefers-color-scheme: dark)').matches)) h.classList.add('dark');
            t?.addEventListener('click', () => { h.classList.toggle('dark'); localStorage.setItem('theme', h.classList.contains('dark') ? 'dark' : 'light') });
        })();
    </script>
</body>

</html>