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
$disputes_ready = $tables['payment_disputes'] ?? false;

/* Handle resolve */
$flash = null;
$flash_err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $disputes_ready && ($_POST['action'] ?? '') === 'resolve') {
    nb_csrf_check();
    $did = intval($_POST['dispute_id'] ?? 0);
    $decision = $_POST['decision'] ?? ''; // won | lost | cancelled
    $notes = trim($_POST['resolution_notes'] ?? '');

    if (!in_array($decision, ['won', 'lost', 'cancelled'], true)) {
        $flash_err = "Invalid decision.";
    } else {
        $conn->begin_transaction();
        try {
            $st = $conn->prepare("SELECT * FROM payment_disputes WHERE id = ? LIMIT 1");
            $st->bind_param("i", $did);
            $st->execute();
            $disp = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$disp)
                throw new Exception("Dispute not found.");

            $upd = $conn->prepare("UPDATE payment_disputes SET status = ?, resolution_notes = ?, resolved_at = NOW() WHERE id = ?");
            $upd->bind_param("ssi", $decision, $notes, $did);
            $upd->execute();
            $upd->close();

            $seller_id = intval($disp['seller_id'] ?? 0);
            $amount = floatval($disp['amount'] ?? 0);

            // Ledger impact
            if ($decision === 'won' && $seller_id && $tables['seller_ledger']) {
                // Release held funds back to seller
                nb_ledger_add($conn, [
                    'seller_id' => $seller_id,
                    'order_id' => intval($disp['order_id'] ?? 0),
                    'entry_type' => 'dispute_release',
                    'entry_category' => 'dispute_won',
                    'amount' => $amount,
                    'balance_after' => 0,
                    'status' => 'released',
                    'description' => 'Dispute #' . $did . ' resolved in seller favor',
                ]);
            } elseif ($decision === 'lost' && $seller_id && $tables['seller_ledger']) {
                nb_ledger_add($conn, [
                    'seller_id' => $seller_id,
                    'order_id' => intval($disp['order_id'] ?? 0),
                    'entry_type' => 'refund',
                    'entry_category' => 'dispute_lost',
                    'amount' => $amount,
                    'balance_after' => 0,
                    'status' => 'released',
                    'description' => 'Dispute #' . $did . ' lost — refunded to buyer',
                ]);
            }

            // Notify both parties
            if ($seller_id && function_exists('nb_notify')) {
                $msg = $decision === 'won' ? "Dispute #{$did} was resolved in your favor. Rs. " . number_format($amount) . " released." :
                    ($decision === 'lost' ? "Dispute #{$did} was resolved against you. Rs. " . number_format($amount) . " refunded to buyer." :
                        "Dispute #{$did} was cancelled.");
                nb_notify($conn, $seller_id, 'dispute', 'Dispute Update', $msg, 'seller/disputes.php');
            }

            $buyer_id = intval($disp['buyer_id'] ?? 0);
            if ($buyer_id && function_exists('nb_notify')) {
                $msg = $decision === 'lost' ? "Dispute #{$did} resolved in your favor. Refund processed." :
                    ($decision === 'won' ? "Dispute #{$did} was resolved in favor of seller." :
                        "Dispute #{$did} was cancelled.");
                nb_notify($conn, $buyer_id, 'dispute', 'Dispute Update', $msg, 'user/disputes.php');
            }

            nb_pay_audit($conn, [
                'actor_id' => intval($_SESSION['user_id']),
                'actor_type' => 'admin',
                'entity_type' => 'dispute',
                'entity_id' => $did,
                'action' => 'dispute.resolved',
                'new' => ['status' => $decision, 'notes' => $notes],
            ]);

            $conn->commit();
            $flash = "Dispute #{$did} resolved as '{$decision}'.";
        } catch (Throwable $e) {
            $conn->rollback();
            $flash_err = "Could not resolve: " . $e->getMessage();
        }
    }
}

/* Filters */
$status_f = trim($_GET['status'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

$disputes = [];
$total_filtered = 0;
$total_pages = 1;

if ($disputes_ready) {
    $where = ["1=1"];
    $params = [];
    $types = "";
    if ($status_f !== '') {
        $where[] = "d.status = ?";
        $params[] = $status_f;
        $types .= "s";
    }
    $wsql = implode(' AND ', $where);

    $cs = $conn->prepare("SELECT COUNT(*) AS c FROM payment_disputes d WHERE $wsql");
    if ($params)
        $cs->bind_param($types, ...$params);
    $cs->execute();
    $total_filtered = intval($cs->get_result()->fetch_assoc()['c'] ?? 0);
    $cs->close();

    $total_pages = max(1, (int) ceil($total_filtered / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $lq = "SELECT d.*, o.order_number, a.title AS auction_title, a.image AS auction_image,
                s.full_name AS seller_name, s.email AS seller_email,
                b.full_name AS buyer_name, b.email AS buyer_email
         FROM payment_disputes d
         LEFT JOIN orders o ON d.order_id=o.order_id
         LEFT JOIN auctions a ON o.auction_id=a.auction_id
         LEFT JOIN users s ON d.seller_id=s.user_id
         LEFT JOIN users b ON d.buyer_id=b.user_id
         WHERE $wsql ORDER BY d.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    $ls = $conn->prepare($lq);
    $ls->bind_param($types, ...$params);
    $ls->execute();
    $disputes = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
    $ls->close();
}

/* Stats */
$dstats = ['open' => 0, 'under_review' => 0, 'evidence_required' => 0, 'won' => 0, 'lost' => 0, 'total_value' => 0];
if ($disputes_ready) {
    try {
        $r = $conn->query("SELECT status, COUNT(*) AS c, COALESCE(SUM(amount),0) AS amt FROM payment_disputes GROUP BY status");
        while ($row = $r->fetch_assoc()) {
            $s = strtolower($row['status']);
            if (isset($dstats[$s]))
                $dstats[$s] = intval($row['c']);
            if (in_array($s, ['open', 'under_review', 'evidence_required'], true))
                $dstats['total_value'] += floatval($row['amt']);
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
function disp_badge($s)
{
    $map = [
        'open' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Open', 'dot' => 'bg-rose-500'],
        'under_review' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Under Review', 'dot' => 'bg-amber-500'],
        'evidence_required' => ['bg' => 'bg-orange-500/10', 'text' => 'text-orange-700 dark:text-orange-400', 'label' => 'Evidence Required', 'dot' => 'bg-orange-500'],
        'won' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Won', 'dot' => 'bg-emerald-500'],
        'lost' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Lost', 'dot' => 'bg-rose-500'],
        'cancelled' => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Cancelled', 'dot' => 'bg-gray-500'],
    ];
    return $map[strtolower($s)] ?? ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'label' => 'Unknown', 'dot' => 'bg-gray-500'];
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disputes · NexusBids Admin</title>
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
                        <h1 class="text-lg sm:text-xl font-black">Disputes & Chargebacks</h1>
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
                        class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-sm text-emerald-700 dark:text-emerald-300 font-medium flex items-center gap-3">
                        <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($flash) ?></div><?php endif; ?>
                <?php if ($flash_err): ?>
                    <div
                        class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-sm text-rose-700 dark:text-rose-300 font-medium flex items-center gap-3">
                        <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($flash_err) ?></div>
                <?php endif; ?>

                <?php if (!$disputes_ready): ?>
                    <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
                        <p class="text-xs text-amber-800 dark:text-amber-300">Disputes table not found. Run <code
                                class="px-1 bg-black/5 dark:bg-white/10 rounded font-mono">includes/payment_schema.sql</code>.
                        </p>
                    </div>
                <?php endif; ?>

                <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Open</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-scale-balanced"></i></span>
                        </div>
                        <div class="text-2xl font-black text-rose-600 dark:text-rose-400 tabular-nums">
                            <?= number_format($dstats['open'] + $dstats['under_review'] + $dstats['evidence_required']) ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1"><?= money($dstats['total_value']) ?> at stake</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Won</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-check"></i></span>
                        </div>
                        <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                            <?= number_format($dstats['won']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Seller favored</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Lost</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-rose-500/10 text-rose-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-xmark"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($dstats['lost']) ?></div>
                        <p class="text-[10px] text-gray-500 mt-1">Buyer refunded</p>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Cancelled</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-gray-500/10 text-gray-600 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-ban"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums"><?= number_format($dstats['cancelled'] ?? 0) ?>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">Withdrawn</p>
                    </div>
                </section>

                <form method="GET"
                    class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4 flex flex-wrap gap-3 items-center">
                    <select name="status" onchange="this.form.submit()"
                        class="custom-select py-2.5 pl-4 pr-9 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm">
                        <option value="">All Status</option>
                        <option value="open" <?= $status_f === 'open' ? 'selected' : '' ?>>Open</option>
                        <option value="under_review" <?= $status_f === 'under_review' ? 'selected' : '' ?>>Under Review
                        </option>
                        <option value="evidence_required" <?= $status_f === 'evidence_required' ? 'selected' : '' ?>>Evidence
                            Required</option>
                        <option value="won" <?= $status_f === 'won' ? 'selected' : '' ?>>Won</option>
                        <option value="lost" <?= $status_f === 'lost' ? 'selected' : '' ?>>Lost</option>
                        <option value="cancelled" <?= $status_f === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                    <?php if ($status_f): ?><a href="disputes.php"
                            class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 text-sm font-semibold">Reset</a><?php endif; ?>
                </form>

                <section class="space-y-4">
                    <?php if (empty($disputes)): ?>
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-16 text-center">
                            <div
                                class="w-20 h-20 mx-auto rounded-2xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center text-3xl mb-5">
                                <i class="fa-solid fa-circle-check"></i></div>
                            <h3 class="text-xl font-black">No disputes</h3>
                            <p class="text-sm text-gray-500 mt-2">All clear!</p>
                        </div>
                    <?php else:
                        foreach ($disputes as $d):
                            $b = disp_badge($d['status']);
                            $amt = floatval($d['amount']);
                            $open = in_array(strtolower($d['status']), ['open', 'under_review', 'evidence_required'], true);
                            ?>
                            <div
                                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6">
                                <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4 mb-4">
                                    <div class="flex items-start gap-4 flex-1 min-w-0">
                                        <?php if (!empty($d['auction_image'])): ?>
                                            <img src="../uploads/auctions/<?= htmlspecialchars($d['auction_image']) ?>"
                                                class="w-14 h-14 rounded-xl object-cover shrink-0" alt="">
                                        <?php else: ?>
                                            <div
                                                class="w-14 h-14 rounded-xl bg-rose-500/10 text-rose-500 flex items-center justify-center shrink-0">
                                                <i class="fa-solid fa-scale-balanced text-lg"></i></div>
                                        <?php endif; ?>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $b['bg'] . ' ' . $b['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                                    <span
                                                        class="w-1.5 h-1.5 rounded-full <?= $b['dot'] ?> <?= $open ? 'animate-pulse' : '' ?>"></span>
                                                    <?= $b['label'] ?>
                                                </span>
                                                <span
                                                    class="px-2 py-0.5 rounded-md bg-gray-100 dark:bg-slate-800 text-[10px] font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400">
                                                    <?= htmlspecialchars(str_replace('_', ' ', $d['dispute_type'] ?? 'other')) ?>
                                                </span>
                                                <span
                                                    class="text-[10px] text-gray-400 font-mono">#<?= str_pad((int) $d['id'], 5, '0', STR_PAD_LEFT) ?></span>
                                            </div>
                                            <div class="font-bold text-sm mb-1">
                                                <?= htmlspecialchars($d['auction_title'] ?? 'Order') ?></div>
                                            <div class="text-[10px] text-gray-400 font-mono">
                                                <?= htmlspecialchars($d['order_number'] ?? ('#' . $d['order_id'])) ?></div>
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">Disputed
                                            Amount</div>
                                        <div class="text-2xl font-black text-rose-600 dark:text-rose-400 tabular-nums">Rs.
                                            <?= number_format($amt) ?></div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                                    <div
                                        class="p-3 rounded-xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800">
                                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">Seller
                                        </div>
                                        <div class="text-xs font-bold"><?= htmlspecialchars($d['seller_name'] ?? '—') ?></div>
                                        <div class="text-[10px] text-gray-500"><?= htmlspecialchars($d['seller_email'] ?? '') ?>
                                        </div>
                                    </div>
                                    <div
                                        class="p-3 rounded-xl bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-gray-800">
                                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">Buyer
                                        </div>
                                        <div class="text-xs font-bold"><?= htmlspecialchars($d['buyer_name'] ?? '—') ?></div>
                                        <div class="text-[10px] text-gray-500"><?= htmlspecialchars($d['buyer_email'] ?? '') ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!empty($d['reason'])): ?>
                                    <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 mb-3">
                                        <div
                                            class="text-[10px] font-bold uppercase tracking-wider text-rose-600 dark:text-rose-400 mb-1.5">
                                            Buyer's Reason</div>
                                        <p class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed">
                                            <?= nl2br(htmlspecialchars($d['reason'])) ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($d['evidence'])): ?>
                                    <div class="p-4 rounded-xl bg-indigo-500/10 border border-indigo-500/20 mb-3">
                                        <div
                                            class="text-[10px] font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400 mb-1.5">
                                            Evidence</div>
                                        <p class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed">
                                            <?= nl2br(htmlspecialchars($d['evidence'])) ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($d['resolution_notes'])): ?>
                                    <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 mb-3">
                                        <div
                                            class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 mb-1.5">
                                            Resolution</div>
                                        <p class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed">
                                            <?= nl2br(htmlspecialchars($d['resolution_notes'])) ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if ($open): ?>
                                    <div class="flex flex-wrap gap-2 mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
                                        <button onclick="openResolve(<?= (int) $d['id'] ?>, 'won')"
                                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition">
                                            <i class="fa-solid fa-check"></i> Rule in Favor of Seller
                                        </button>
                                        <button onclick="openResolve(<?= (int) $d['id'] ?>, 'lost')"
                                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold transition">
                                            <i class="fa-solid fa-rotate-left"></i> Refund Buyer
                                        </button>
                                        <button onclick="openResolve(<?= (int) $d['id'] ?>, 'cancelled')"
                                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-gray-200 dark:bg-slate-800 text-gray-700 dark:text-gray-300 text-xs font-bold hover:bg-gray-300 dark:hover:bg-slate-700 transition">
                                            <i class="fa-solid fa-ban"></i> Cancel Dispute
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; endif; ?>
                </section>
            </div>
        </main>
    </div>

    <!-- Resolve Modal -->
    <div id="resolveModal"
        class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl max-w-md w-full p-6 border border-gray-200 dark:border-gray-800">
            <h3 class="text-lg font-black mb-1" id="rTitle">Resolve Dispute</h3>
            <p class="text-xs text-gray-500 mb-4" id="rDesc"></p>
            <form method="POST">
                <?php nb_csrf_field(); ?>
                <input type="hidden" name="action" value="resolve">
                <input type="hidden" name="dispute_id" id="rId">
                <input type="hidden" name="decision" id="rDecision">
                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-2">Resolution Notes <span
                        class="text-rose-500">*</span></label>
                <textarea name="resolution_notes" required rows="3" maxlength="1000"
                    class="w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-slate-800 text-sm py-2.5 px-3 focus:border-indigo-500 focus:ring-indigo-500"
                    placeholder="Explain the decision..."></textarea>
                <div class="flex items-center gap-2 mt-5">
                    <button type="button" onclick="closeResolve()"
                        class="flex-1 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-800 text-sm font-semibold">Cancel</button>
                    <button type="submit" id="rBtn"
                        class="flex-1 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold">Confirm</button>
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

        function openResolve(id, decision) {
            const titles = { won: 'Rule in Favor of Seller', lost: 'Refund Buyer', cancelled: 'Cancel Dispute' };
            const descs = {
                won: 'Seller keeps the money. Escrow released to seller ledger.',
                lost: 'Buyer refunded. Seller ledger debited.',
                cancelled: 'Dispute closed with no financial impact.'
            };
            document.getElementById('rTitle').textContent = titles[decision] || 'Resolve';
            document.getElementById('rDesc').textContent = descs[decision] || '';
            document.getElementById('rId').value = id;
            document.getElementById('rDecision').value = decision;
            document.getElementById('rBtn').className = 'flex-1 py-2.5 rounded-xl text-white text-sm font-bold ' +
                (decision === 'won' ? 'bg-emerald-600 hover:bg-emerald-500' : (decision === 'lost' ? 'bg-rose-600 hover:bg-rose-500' : 'bg-gray-600 hover:bg-gray-500'));
            const m = document.getElementById('resolveModal'); m.classList.remove('hidden'); m.classList.add('flex');
        }
        function closeResolve() { const m = document.getElementById('resolveModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
        document.getElementById('resolveModal')?.addEventListener('click', function (e) { if (e.target === this) closeResolve(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeResolve(); });
    </script>
</body>

</html>