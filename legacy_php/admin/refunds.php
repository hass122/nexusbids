<?php
require_once "admin-auth.php";
require_once "../includes/csrf.php";
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
$refunds_ready = $tables['refunds'] ?? false;

/* Handle status change */
$flash = null;
$flash_err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $refunds_ready && ($_POST['action'] ?? '') === 'update_status') {
    nb_csrf_check();
    $rid = intval($_POST['refund_id'] ?? 0);
    $new_status = trim($_POST['new_status'] ?? '');
    if (in_array($new_status, ['approved', 'processing', 'completed', 'failed', 'rejected'], true)) {
        $conn->begin_transaction();
        try {
            $st = $conn->prepare("SELECT * FROM refunds WHERE id=? LIMIT 1");
            $st->bind_param("i", $rid);
            $st->execute();
            $ref = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$ref)
                throw new Exception("Refund not found.");

            $upd = $conn->prepare("UPDATE refunds SET status=?, processed_at=NOW() WHERE id=?");
            $upd->bind_param("si", $new_status, $rid);
            $upd->execute();
            $upd->close();

            // If completed: log ledger refund
            if ($new_status === 'completed' && $tables['seller_ledger'] && !empty($ref['seller_id'])) {
                nb_ledger_add($conn, [
                    'seller_id' => intval($ref['seller_id']),
                    'order_id' => intval($ref['order_id']),
                    'entry_type' => 'refund',
                    'entry_category' => 'refund',
                    'amount' => floatval($ref['amount']),
                    'balance_after' => 0,
                    'status' => 'released',
                    'description' => 'Refund #' . $rid . ' completed — ' . ($ref['reason'] ?? ''),
                ]);
            }

            // Notify buyer
            if (!empty($ref['buyer_id']) && function_exists('nb_notify')) {
                $map = ['approved' => 'approved', 'processing' => 'is processing', 'completed' => 'completed', 'failed' => 'failed', 'rejected' => 'was rejected'];
                nb_notify(
                    $conn,
                    intval($ref['buyer_id']),
                    'payment',
                    'Refund Update',
                    'Your refund of Rs. ' . number_format(floatval($ref['amount'])) . ' ' . ($map[$new_status] ?? 'updated') . '.',
                    'user/orders.php'
                );
            }

            nb_pay_audit($conn, [
                'actor_id' => intval($_SESSION['user_id']),
                'actor_type' => 'admin',
                'entity_type' => 'refund',
                'entity_id' => $rid,
                'action' => 'refund.' . $new_status,
            ]);

            $conn->commit();
            $flash = "Refund #{$rid} marked as " . ucfirst($new_status) . ".";
        } catch (Throwable $e) {
            $conn->rollback();
            $flash_err = "Failed: " . $e->getMessage();
        }
    }
}

$status_f = trim($_GET['status'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

$refunds = [];
$total_filtered = 0;
$total_pages = 1;
if ($refunds_ready) {
    $where = ["1=1"];
    $params = [];
    $types = "";
    if ($status_f !== '') {
        $where[] = "r.status=?";
        $params[] = $status_f;
        $types .= "s";
    }
    $wsql = implode(' AND ', $where);

    $cs = $conn->prepare("SELECT COUNT(*) AS c FROM refunds r WHERE $wsql");
    if ($params)
        $cs->bind_param($types, ...$params);
    $cs->execute();
    $total_filtered = intval($cs->get_result()->fetch_assoc()['c'] ?? 0);
    $cs->close();
    $total_pages = max(1, (int) ceil($total_filtered / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $lq = "SELECT r.*, o.order_number, a.title AS auction_title, a.image AS auction_image,
                s.full_name AS seller_name, b.full_name AS buyer_name, b.email AS buyer_email
         FROM refunds r
         LEFT JOIN orders o ON r.order_id=o.order_id
         LEFT JOIN auctions a ON o.auction_id=a.auction_id
         LEFT JOIN users s ON r.seller_id=s.user_id
         LEFT JOIN users b ON r.buyer_id=b.user_id
         WHERE $wsql ORDER BY r.requested_at DESC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    $ls = $conn->prepare($lq);
    $ls->bind_param($types, ...$params);
    $ls->execute();
    $refunds = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
    $ls->close();
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
function refund_badge($s)
{
    $map = [
        'requested' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Requested', 'dot' => 'bg-amber-500'],
        'approved' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'label' => 'Approved', 'dot' => 'bg-blue-500'],
        'processing' => ['bg' => 'bg-indigo-500/10', 'text' => 'text-indigo-700 dark:text-indigo-400', 'label' => 'Processing', 'dot' => 'bg-indigo-500'],
        'completed' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Completed', 'dot' => 'bg-emerald-500'],
        'failed' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Failed', 'dot' => 'bg-rose-500'],
        'rejected' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Rejected', 'dot' => 'bg-rose-500'],
    ];
    return $map[strtolower($s)] ?? ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Unknown', 'dot' => 'bg-gray-500'];
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refunds · NexusBids Admin</title>
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
                        <h1 class="text-lg sm:text-xl font-black">Refunds</h1>
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

                <?php if ($flash): ?>
                    <div
                        class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-sm text-emerald-700 dark:text-emerald-300 flex items-center gap-3">
                        <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($flash) ?></div><?php endif; ?>
                <?php if ($flash_err): ?>
                    <div
                        class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-sm text-rose-700 dark:text-rose-300 flex items-center gap-3">
                        <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($flash_err) ?></div>
                <?php endif; ?>

                <?php if (!$refunds_ready): ?>
                    <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
                        <p class="text-xs text-amber-800 dark:text-amber-300">Refunds table not found. Run <code
                                class="px-1 bg-black/5 dark:bg-white/10 rounded font-mono">includes/payment_schema.sql</code>.
                        </p>
                    </div>
                <?php endif; ?>

                <form method="GET"
                    class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4">
                    <select name="status" onchange="this.form.submit()"
                        class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                        <option value="">All Status</option>
                        <option value="requested" <?= $status_f === 'requested' ? 'selected' : '' ?>>Requested</option>
                        <option value="approved" <?= $status_f === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="processing" <?= $status_f === 'processing' ? 'selected' : '' ?>>Processing</option>
                        <option value="completed" <?= $status_f === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="failed" <?= $status_f === 'failed' ? 'selected' : '' ?>>Failed</option>
                        <option value="rejected" <?= $status_f === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </form>

                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <?php if (empty($refunds)): ?>
                        <div class="p-16 text-center">
                            <div
                                class="w-20 h-20 mx-auto rounded-2xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-solid fa-rotate-left"></i></div>
                            <h3 class="text-xl font-black">No refunds</h3>
                            <p class="text-sm text-gray-500 mt-2">Refund requests will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                    <tr>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Refund
                                        </th>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Order
                                            / Item</th>
                                        <th class="px-5 py-4 text-left text-[10px] font-bold uppercase text-gray-500">Buyer
                                            / Seller</th>
                                        <th class="px-5 py-4 text-right text-[10px] font-bold uppercase text-gray-500">
                                            Amount</th>
                                        <th class="px-5 py-4 text-center text-[10px] font-bold uppercase text-gray-500">
                                            Status</th>
                                        <th class="px-5 py-4 text-right text-[10px] font-bold uppercase text-gray-500">
                                            Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach ($refunds as $r):
                                        $b = refund_badge($r['status'] ?? 'requested');
                                        $amt = floatval($r['amount']);
                                        $can_act = in_array(strtolower($r['status']), ['requested', 'approved', 'processing'], true);
                                        ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50">
                                            <td class="px-5 py-4">
                                                <div class="font-mono text-xs font-bold">
                                                    #<?= str_pad((int) $r['id'], 5, '0', STR_PAD_LEFT) ?></div>
                                                <div class="text-[10px] text-gray-400 mt-0.5">
                                                    <?= date('d M Y', strtotime($r['requested_at'] ?? 'now')) ?></div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="text-xs font-bold truncate max-w-[200px]">
                                                    <?= htmlspecialchars($r['auction_title'] ?? 'Order') ?></div>
                                                <div class="text-[10px] text-gray-400 font-mono">
                                                    <?= htmlspecialchars($r['order_number'] ?? ('#' . $r['order_id'])) ?></div>
                                                <?php if (!empty($r['reason'])): ?>
                                                    <div class="text-[10px] text-gray-500 mt-1 line-clamp-1">
                                                        <?= htmlspecialchars($r['reason']) ?></div><?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4 text-xs">
                                                <div>B: <strong><?= htmlspecialchars($r['buyer_name'] ?? '—') ?></strong></div>
                                                <div class="mt-0.5">S:
                                                    <strong><?= htmlspecialchars($r['seller_name'] ?? '—') ?></strong></div>
                                            </td>
                                            <td class="px-5 py-4 text-right font-black tabular-nums">Rs.
                                                <?= number_format($amt) ?></td>
                                            <td class="px-5 py-4 text-center">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $b['bg'] . ' ' . $b['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                                    <span
                                                        class="w-1.5 h-1.5 rounded-full <?= $b['dot'] ?> <?= in_array(strtolower($r['status'] ?? ''), ['requested', 'processing'], true) ? 'animate-pulse' : '' ?>"></span>
                                                    <?= $b['label'] ?>
                                                </span>
                                            </td>
                                            <td class="px-5 py-4 text-right">
                                                <?php if ($can_act): ?>
                                                    <button
                                                        onclick="openRefundAction(<?= (int) $r['id'] ?>, '<?= htmlspecialchars($r['status'] ?? 'requested') ?>')"
                                                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 border border-indigo-500/20 hover:bg-indigo-600 hover:text-white text-[10px] font-bold transition">
                                                        <i class="fa-solid fa-pen"></i> Update
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-[10px] text-gray-400">No action</span>
                                                <?php endif; ?>
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

    <div id="refundModal"
        class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl max-w-md w-full p-6 border border-gray-200 dark:border-gray-800">
            <h3 class="text-lg font-black mb-1">Update Refund Status</h3>
            <p class="text-xs text-gray-500 mb-4">Refund #<span id="rIdLabel"></span></p>
            <form method="POST">
                <?php nb_csrf_field(); ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="refund_id" id="rIdInput">
                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-2">New Status</label>
                <select name="new_status" id="rStatusSel"
                    class="w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-slate-800 text-sm py-2.5 px-3">
                    <option value="approved">Approved</option>
                    <option value="processing">Processing</option>
                    <option value="completed">Completed (refund buyer, debit seller)</option>
                    <option value="failed">Failed</option>
                    <option value="rejected">Rejected</option>
                </select>
                <div class="flex items-center gap-2 mt-5">
                    <button type="button" onclick="closeRefund()"
                        class="flex-1 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 text-sm font-semibold">Cancel</button>
                    <button type="submit"
                        class="flex-1 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold">Update</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const h = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && matchMedia('(prefers-color-scheme: dark)').matches)) h.classList.add('dark');
            t?.addEventListener('click', () => { h.classList.toggle('dark'); localStorage.setItem('theme', h.classList.contains('dark') ? 'dark' : 'light') });
        })();

        function openRefundAction(id, current) {
            document.getElementById('rIdLabel').textContent = String(id).padStart(5, '0');
            document.getElementById('rIdInput').value = id;
            const m = document.getElementById('refundModal'); m.classList.remove('hidden'); m.classList.add('flex');
        }
        function closeRefund() { const m = document.getElementById('refundModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
        document.getElementById('refundModal')?.addEventListener('click', function (e) { if (e.target === this) closeRefund(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeRefund(); });
    </script>
</body>

</html>