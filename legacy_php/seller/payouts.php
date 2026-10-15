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

/* ---------- Schema detection ---------- */
$has_payouts_table = false;
$payout_cols = [];
$users_cols = [];

try {
    if ($conn->query("SHOW TABLES LIKE 'payouts'")->num_rows > 0) {
        $has_payouts_table = true;
        $r = $conn->query("SHOW COLUMNS FROM payouts");
        while ($c = $r->fetch_assoc())
            $payout_cols[] = $c['Field'];
    }
    $r = $conn->query("SHOW COLUMNS FROM users");
    while ($c = $r->fetch_assoc())
        $users_cols[] = $c['Field'];
} catch (Throwable $e) {
}

$pick = function (array $cands, array $haystack) {
    foreach ($cands as $c)
        if (in_array($c, $haystack, true))
            return $c;
    return null;
};

$payout_method_col = $pick(['payout_method'], $users_cols);
$payout_title_col = $pick(['payout_account_title'], $users_cols);
$payout_number_col = $pick(['payout_account_number'], $users_cols);
$payout_bank_col = $pick(['payout_bank_name'], $users_cols);

$p_id_col = $pick(['payout_id', 'id'], $payout_cols);
$p_amount_col = $pick(['amount'], $payout_cols);
$p_method_col = $pick(['method'], $payout_cols);
$p_title_col = $pick(['account_title'], $payout_cols);
$p_number_col = $pick(['account_number'], $payout_cols);
$p_bank_col = $pick(['bank_name'], $payout_cols);
$p_status_col = $pick(['status'], $payout_cols);
$p_req_col = $pick(['requested_at', 'created_at'], $payout_cols);
$p_note_col = $pick(['admin_note', 'note'], $payout_cols);
$p_proc_col = $pick(['processed_at', 'paid_at'], $payout_cols);

/* ---------- Seller info ---------- */
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

/* ---------- Saved payout info ---------- */
$saved = ['method' => '', 'title' => $full_name, 'number' => '', 'bank' => ''];
if ($payout_method_col || $payout_title_col || $payout_number_col || $payout_bank_col) {
    $sel = [];
    if ($payout_method_col)
        $sel[] = "`$payout_method_col` AS m";
    if ($payout_title_col)
        $sel[] = "`$payout_title_col` AS t";
    if ($payout_number_col)
        $sel[] = "`$payout_number_col` AS n";
    if ($payout_bank_col)
        $sel[] = "`$payout_bank_col` AS b";
    if (!empty($sel)) {
        $row = $conn->query("SELECT " . implode(', ', $sel) . " FROM users WHERE user_id = $seller_id LIMIT 1")->fetch_assoc();
        if ($row) {
            $saved['method'] = $row['m'] ?? '';
            $saved['title'] = $row['t'] ?: $full_name;
            $saved['number'] = $row['n'] ?? '';
            $saved['bank'] = $row['b'] ?? '';
        }
    }
}

/* ---------- Balances via ledger ---------- */
$balances = nb_ledger_get_balances($conn, $seller_id);
$available_balance = $balances['available'];
$earned_total = $balances['total_earned'];
$total_withdrawn = $balances['total_withdrawn'];
$pending_payout = $balances['pending_payout'];
$held_amount = $balances['held'];
$in_dispute = $balances['in_dispute'];
$min_payout = 1000;

/* ---------- Handle NEW payout request ---------- */
$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? '') === "request_payout" && $has_payouts_table) {

    nb_csrf_check();

    $amount = floatval($_POST["amount"] ?? 0);
    $method = trim($_POST["method"] ?? '');
    $title = trim($_POST["account_title"] ?? '');
    $number = trim($_POST["account_number"] ?? '');
    $bank = trim($_POST["bank_name"] ?? '');

    if (!in_array($method, ['bank', 'jazzcash', 'easypaisa'], true))
        $errors[] = "Please select a valid payout method.";
    if ($amount < $min_payout)
        $errors[] = "Minimum withdrawal is Rs. " . number_format($min_payout) . ".";
    if ($amount > $available_balance)
        $errors[] = "Amount exceeds your available balance (Rs. " . number_format($available_balance) . ").";
    if ($title === '')
        $errors[] = "Account title is required.";
    if ($number === '')
        $errors[] = "Account number is required.";
    if ($method === 'bank' && $bank === '')
        $errors[] = "Bank name is required.";

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            $cols = ['seller_id'];
            $vals = ['?'];
            $types = 'i';
            $params = [$seller_id];
            if ($p_amount_col) {
                $cols[] = "`$p_amount_col`";
                $vals[] = '?';
                $types .= 'd';
                $params[] = $amount;
            }
            if ($p_method_col) {
                $cols[] = "`$p_method_col`";
                $vals[] = '?';
                $types .= 's';
                $params[] = $method;
            }
            if ($p_title_col) {
                $cols[] = "`$p_title_col`";
                $vals[] = '?';
                $types .= 's';
                $params[] = $title;
            }
            if ($p_number_col) {
                $cols[] = "`$p_number_col`";
                $vals[] = '?';
                $types .= 's';
                $params[] = $number;
            }
            if ($p_bank_col) {
                $cols[] = "`$p_bank_col`";
                $vals[] = '?';
                $types .= 's';
                $params[] = ($method === 'bank' ? $bank : null);
            }
            if ($p_status_col) {
                $cols[] = "`$p_status_col`";
                $vals[] = "'pending'";
            }
            if ($p_req_col) {
                $cols[] = "`$p_req_col`";
                $vals[] = 'NOW()';
            }

            $sql = "INSERT INTO payouts (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
            $ins = $conn->prepare($sql);
            if ($ins) {
                $ins->bind_param($types, ...$params);
                $ins->execute();
                $ins->close();
                $payout_id = $conn->insert_id;

                nb_ledger_add($conn, [
                    'seller_id' => $seller_id,
                    'payout_id' => $payout_id,
                    'entry_type' => 'payout',
                    'entry_category' => 'withdrawal',
                    'amount' => $amount,
                    'balance_after' => max(0, $available_balance - $amount),
                    'status' => 'pending',
                    'description' => 'Withdrawal request via ' . $method,
                ]);

                $tables = nb_ledger_tables_exist($conn);
                if ($tables['payout_approvals'] ?? false) {
                    try {
                        $ap = $conn->prepare("INSERT INTO payout_approvals (payout_id, action, notes) VALUES (?, 'submitted', ?)");
                        $note = "Requested by seller";
                        $ap->bind_param("is", $payout_id, $note);
                        $ap->execute();
                        $ap->close();
                    } catch (Throwable $e) {
                    }
                }

                nb_pay_audit($conn, [
                    'actor_id' => $seller_id,
                    'actor_type' => 'seller',
                    'entity_type' => 'payout',
                    'entity_id' => $payout_id,
                    'action' => 'payout.requested',
                    'new' => ['amount' => $amount, 'method' => $method],
                ]);
            }

            $updates = [];
            $utypes = '';
            $uparams = [];
            if ($payout_method_col) {
                $updates[] = "`$payout_method_col` = ?";
                $utypes .= 's';
                $uparams[] = $method;
            }
            if ($payout_title_col) {
                $updates[] = "`$payout_title_col` = ?";
                $utypes .= 's';
                $uparams[] = $title;
            }
            if ($payout_number_col) {
                $updates[] = "`$payout_number_col` = ?";
                $utypes .= 's';
                $uparams[] = $number;
            }
            if ($payout_bank_col) {
                $updates[] = "`$payout_bank_col` = ?";
                $utypes .= 's';
                $uparams[] = ($method === 'bank' ? $bank : null);
            }

            if (!empty($updates)) {
                $utypes .= 'i';
                $uparams[] = $seller_id;
                $upd = $conn->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE user_id = ?");
                if ($upd) {
                    $upd->bind_param($utypes, ...$uparams);
                    $upd->execute();
                    $upd->close();
                }
            }

            if (file_exists(__DIR__ . '/../includes/activity.php')) {
                require_once "../includes/activity.php";
                if (function_exists('nb_log_activity')) {
                    nb_log_activity(
                        $conn,
                        $seller_id,
                        'payout.requested',
                        "Requested payout of Rs. " . number_format($amount)
                    );
                }
            }

            $conn->commit();
            $_SESSION["success"] = "Withdrawal request submitted. It will be reviewed within 2–3 business days.";
            header("Location: payouts.php");
            exit();
        } catch (Throwable $e) {
            if ($conn->errno)
                @$conn->rollback();
            error_log("payout request failed: " . $e->getMessage());
            $errors[] = "Could not submit request. Please try again.";
        }
    }
}

/* ---------- Fetch history ---------- */
$history = [];
$h_filter = $_GET['h_status'] ?? 'all';
$h_range = $_GET['h_range'] ?? 'all';

if ($has_payouts_table && $p_id_col && $p_amount_col) {
    try {
        $where = ["seller_id = ?"];
        $params = [$seller_id];
        $types = "i";
        if (in_array($h_filter, ['pending', 'approved', 'paid', 'rejected'], true) && $p_status_col) {
            $where[] = "`$p_status_col` = ?";
            $params[] = $h_filter;
            $types .= "s";
        }
        if ($h_range === '30d' && $p_req_col)
            $where[] = "`$p_req_col` >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        if ($h_range === '90d' && $p_req_col)
            $where[] = "`$p_req_col` >= DATE_SUB(NOW(), INTERVAL 90 DAY)";

        $where_sql = implode(' AND ', $where);
        $order_col = $p_req_col ?: $p_id_col;

        $stmt = $conn->prepare("SELECT * FROM payouts WHERE $where_sql ORDER BY `$order_col` DESC LIMIT 100");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        error_log("payouts history failed: " . $e->getMessage());
    }
}

function compact_rs($a)
{
    $a = floatval($a);
    if ($a >= 10000000)
        return round($a / 10000000, 1) . "Cr";
    if ($a >= 100000)
        return round($a / 100000, 1) . "L";
    if ($a >= 1000)
        return round($a / 1000) . "K";
    return number_format($a);
}
function safe_date($d, $fmt = 'd M Y, h:i A')
{
    if (empty($d))
        return '—';
    $ts = strtotime($d);
    return $ts ? date($fmt, $ts) : '—';
}
function mask_account(string $num): string
{
    $num = trim($num);
    if (strlen($num) <= 4)
        return str_repeat('•', strlen($num));
    return str_repeat('•', max(4, strlen($num) - 4)) . substr($num, -4);
}

$status_map = [
    
    'pending' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Pending', 'dot' => 'bg-amber-500'],

    
    'approved' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'label' => 'Approved', 'dot' => 'bg-blue-500'],
    'processing' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'label' => 'Approved', 'dot' => 'bg-blue-500'],

    
    'paid' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Paid', 'dot' => 'bg-emerald-500'],
    'completed' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Paid', 'dot' => 'bg-emerald-500'],

    
    'rejected' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Rejected', 'dot' => 'bg-rose-500'],
    'failed' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'label' => 'Rejected', 'dot' => 'bg-rose-500'],
];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payouts · Seller · NexusBids</title>
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

        .custom-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L2 4h8z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right .75rem center;
            appearance: none
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">
    <div class="flex min-h-screen">

        <?php $sidebar_active_page = 'payouts';
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
                            <h1 class="text-lg sm:text-xl font-black truncate">Payouts & Withdrawals</h1>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="export.php?type=payouts"
                            class="hidden sm:inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 bg-white dark:bg-slate-800 border border-gray-200 dark:border-gray-700 hover:border-indigo-500 rounded-xl transition-all">
                            <i class="fa-solid fa-file-csv text-emerald-500 text-xs"></i> Export
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

                <?php if (!empty($_SESSION['success'])): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30">
                        <i class="fa-solid fa-circle-check text-emerald-600 dark:text-emerald-400 mt-1"></i>
                        <p class="text-sm text-emerald-700 dark:text-emerald-300 font-medium self-center">
                            <?= htmlspecialchars($_SESSION['success']);
                            unset($_SESSION['success']); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if (!empty($errors)):
                    foreach ($errors as $err): ?>
                        <div class="flex items-start gap-3 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30">
                            <i class="fa-solid fa-circle-exclamation text-rose-600 dark:text-rose-400 mt-1"></i>
                            <p class="text-sm text-rose-700 dark:text-rose-300 font-medium self-center">
                                <?= htmlspecialchars($err) ?>
                            </p>
                        </div>
                    <?php endforeach; endif; ?>

                <!-- Balance cards -->
                <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div
                        class="bg-gradient-to-br from-indigo-600 to-violet-600 rounded-3xl p-6 text-white shadow-xl relative overflow-hidden">
                        <div
                            class="absolute -right-8 -top-8 w-32 h-32 bg-white/10 rounded-full blur-2xl pointer-events-none">
                        </div>
                        <div class="relative">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-white/80">Available
                                    Balance</span>
                                <i class="fa-solid fa-wallet text-white/70"></i>
                            </div>
                            <div class="text-3xl font-black tabular-nums">Rs. <?= number_format($available_balance) ?>
                            </div>
                            <p class="text-[11px] text-white/80 mt-2">Ready to withdraw</p>
                        </div>
                    </div>
                    <div
                        class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-gray-200 dark:border-gray-800 shadow-sm">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Pending</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-hourglass-half"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums">Rs. <?= compact_rs($pending_payout) ?></div>
                        <p class="text-[11px] text-gray-500 mt-1">Awaiting approval</p>
                    </div>
                    <div
                        class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-gray-200 dark:border-gray-800 shadow-sm">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Total
                                Earned</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-coins"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums">Rs. <?= compact_rs($earned_total) ?></div>
                        <p class="text-[11px] text-gray-500 mt-1">Lifetime earnings</p>
                    </div>
                    <div
                        class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-gray-200 dark:border-gray-800 shadow-sm">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Total
                                Withdrawn</span>
                            <span
                                class="w-9 h-9 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-xs"><i
                                    class="fa-solid fa-money-bill-transfer"></i></span>
                        </div>
                        <div class="text-2xl font-black tabular-nums">Rs. <?= compact_rs($total_withdrawn) ?></div>
                        <p class="text-[11px] text-gray-500 mt-1">Already paid out</p>
                    </div>
                </section>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    <!-- Request Form -->
                    <div class="lg:col-span-2">
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                            <div
                                class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <span
                                    class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm"><i
                                        class="fa-solid fa-money-bill-transfer"></i></span>
                                <div>
                                    <h2 class="text-base font-bold">Request Withdrawal</h2>
                                    <p class="text-xs text-gray-500 mt-0.5">Minimum Rs.
                                        <?= number_format($min_payout) ?> · Processed within 2–3 business days
                                    </p>
                                </div>
                            </div>

                            <form method="POST" class="p-5 sm:p-6 space-y-5" id="payoutForm">
                                <?php nb_csrf_field(); ?>
                                <input type="hidden" name="action" value="request_payout">

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Amount <span
                                            class="text-rose-500">*</span></label>
                                    <div class="relative">
                                        <span
                                            class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs font-bold">PKR</span>
                                        <input type="number" name="amount" id="amountInput" min="<?= $min_payout ?>"
                                            max="<?= $available_balance ?>" step="1" required placeholder="Enter amount"
                                            class="w-full pl-14 pr-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 tabular-nums">
                                    </div>
                                    <div class="flex items-center justify-between mt-2">
                                        <p class="text-[11px] text-gray-400">Available: Rs.
                                            <?= number_format($available_balance) ?>
                                        </p>
                                        <?php if ($available_balance >= $min_payout): ?>
                                            <button type="button"
                                                onclick="document.getElementById('amountInput').value=<?= (int) $available_balance ?>"
                                                class="text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline">Use
                                                max</button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Payout Method
                                        <span class="text-rose-500">*</span></label>
                                    <div class="grid grid-cols-3 gap-2">
                                        <?php foreach (['bank' => 'Bank Transfer', 'jazzcash' => 'JazzCash', 'easypaisa' => 'EasyPaisa'] as $k => $v): ?>
                                            <label class="cursor-pointer">
                                                <input type="radio" name="method" value="<?= $k ?>"
                                                    class="peer sr-only method-radio" <?= ($saved['method'] === $k || ($k === 'bank' && empty($saved['method']))) ? 'checked' : '' ?> required>
                                                <div
                                                    class="text-center p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700 peer-checked:border-indigo-500 peer-checked:bg-indigo-500/10 transition-all">
                                                    <i
                                                        class="fa-solid <?= $k === 'bank' ? 'fa-building-columns text-indigo-500' : 'fa-mobile-screen text-emerald-500' ?> text-sm mb-1 block"></i>
                                                    <span
                                                        class="text-[10px] font-bold uppercase tracking-wider"><?= $v ?></span>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div id="bankFields"
                                    class="space-y-4 <?= ($saved['method'] === 'bank' || empty($saved['method'])) ? '' : 'hidden' ?>">
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Bank Name
                                            <span class="text-rose-500">*</span></label>
                                        <input type="text" name="bank_name" id="bankNameInput"
                                            value="<?= htmlspecialchars($saved['bank']) ?>"
                                            placeholder="e.g. HBL, Meezan, UBL"
                                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Account
                                            Title <span class="text-rose-500">*</span></label>
                                        <input type="text" name="account_title"
                                            value="<?= htmlspecialchars($saved['title']) ?>" required
                                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Account
                                            Number / IBAN <span class="text-rose-500">*</span></label>
                                        <input type="text" name="account_number"
                                            value="<?= htmlspecialchars($saved['number']) ?>" required
                                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                                    </div>
                                </div>

                                <div
                                    class="p-3 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-start gap-2">
                                    <i class="fa-solid fa-circle-info text-blue-500 text-xs mt-0.5"></i>
                                    <p class="text-[11px] text-blue-800 dark:text-blue-300 leading-relaxed">
                                        Double-check your account details. Incorrect info may delay or fail your payout.
                                    </p>
                                </div>

                                <button type="submit" <?= $available_balance < $min_payout ? 'disabled' : '' ?>
                                    class="w-full py-4 rounded-xl bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:from-gray-400 disabled:to-gray-500 disabled:cursor-not-allowed text-white font-bold text-sm shadow-lg shadow-violet-600/30 transition-all flex items-center justify-center gap-2">
                                    <i class="fa-solid fa-paper-plane"></i>
                                    <?= $available_balance < $min_payout ? 'Insufficient balance' : 'Submit Withdrawal Request' ?>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Info Column -->
                    <div class="space-y-6">

                        <?php if (!empty($saved['number'])): ?>
                            <div
                                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
                                <div class="flex items-center gap-3 mb-4">
                                    <span
                                        class="w-9 h-9 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs">
                                        <i
                                            class="fa-solid <?= $saved['method'] === 'bank' ? 'fa-building-columns' : 'fa-mobile-screen' ?>"></i>
                                    </span>
                                    <div>
                                        <h4 class="text-sm font-bold">Saved Payout Method</h4>
                                        <p class="text-[10px] text-gray-500">Used by default</p>
                                    </div>
                                </div>
                                <div class="space-y-2 text-xs">
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Method</span>
                                        <span
                                            class="font-bold capitalize"><?= htmlspecialchars($saved['method'] ?: '—') ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Title</span>
                                        <span
                                            class="font-bold truncate max-w-[140px]"><?= htmlspecialchars($saved['title']) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Account</span>
                                        <span
                                            class="font-mono font-bold"><?= htmlspecialchars(mask_account($saved['number'])) ?></span>
                                    </div>
                                    <?php if ($saved['bank']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">Bank</span>
                                            <span class="font-bold"><?= htmlspecialchars($saved['bank']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <a href="profile.php#payoutForm"
                                    class="mt-4 inline-flex items-center gap-1.5 text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline">
                                    <i class="fa-solid fa-pen text-[9px]"></i> Edit in Profile
                                </a>
                            </div>
                        <?php endif; ?>

                        <div
                            class="relative rounded-3xl bg-slate-950 text-white p-6 overflow-hidden shadow-xl border border-gray-800">
                            <div
                                class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-indigo-900/40 via-slate-950 to-slate-950">
                            </div>
                            <div class="relative space-y-4">
                                <div class="flex items-center gap-2">
                                    <i class="fa-solid fa-shield-halved text-emerald-400"></i>
                                    <h4 class="text-sm font-bold">Payout Info</h4>
                                </div>
                                <ul class="space-y-3 text-xs text-gray-300">
                                    <li class="flex gap-2"><i
                                            class="fa-solid fa-check text-emerald-400 mt-0.5 text-[10px]"></i><span>Minimum
                                            withdrawal Rs. <?= number_format($min_payout) ?></span></li>
                                    <li class="flex gap-2"><i
                                            class="fa-solid fa-check text-emerald-400 mt-0.5 text-[10px]"></i><span>Requests
                                            reviewed within 2–3 business days</span></li>
                                    <li class="flex gap-2"><i
                                            class="fa-solid fa-check text-emerald-400 mt-0.5 text-[10px]"></i><span>Bank
                                            transfers may take 1–3 days after approval</span></li>
                                    <li class="flex gap-2"><i
                                            class="fa-solid fa-check text-emerald-400 mt-0.5 text-[10px]"></i><span>Wallet
                                            transfers are usually instant</span></li>
                                    <li class="flex gap-2"><i
                                            class="fa-solid fa-shield text-indigo-400 mt-0.5 text-[10px]"></i><span>Account
                                            details are masked for your safety</span></li>
                                </ul>
                            </div>
                        </div>

                        <?php if ($held_amount > 0 || $in_dispute > 0): ?>
                            <div
                                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 space-y-2 text-xs">
                                <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-2">Not Yet
                                    Available</div>
                                <?php if ($held_amount > 0): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">In Escrow</span>
                                        <span class="font-bold tabular-nums">Rs. <?= compact_rs($held_amount) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($in_dispute > 0): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">In Dispute</span>
                                        <span class="font-bold text-rose-600 dark:text-rose-400 tabular-nums">Rs.
                                            <?= compact_rs($in_dispute) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- History -->
                <section
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <div
                        class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span
                                class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </span>
                            <div>
                                <h2 class="text-base font-bold">Withdrawal History</h2>
                                <p class="text-xs text-gray-500 mt-0.5">Filter by status and date</p>
                            </div>
                        </div>
                        <form method="GET" class="flex flex-wrap items-center gap-2">
                            <select name="h_status" onchange="this.form.submit()"
                                class="custom-select py-2.5 pl-3 pr-8 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-xs font-semibold">
                                <option value="all" <?= $h_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="pending" <?= $h_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="approved" <?= $h_filter === 'approved' ? 'selected' : '' ?>>Approved
                                </option>
                                <option value="paid" <?= $h_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                                <option value="rejected" <?= $h_filter === 'rejected' ? 'selected' : '' ?>>Rejected
                                </option>
                            </select>
                            <select name="h_range" onchange="this.form.submit()"
                                class="custom-select py-2.5 pl-3 pr-8 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-xs font-semibold">
                                <option value="all" <?= $h_range === 'all' ? 'selected' : '' ?>>All Time</option>
                                <option value="30d" <?= $h_range === '30d' ? 'selected' : '' ?>>Last 30 Days</option>
                                <option value="90d" <?= $h_range === '90d' ? 'selected' : '' ?>>Last 90 Days</option>
                            </select>
                        </form>
                    </div>

                    <?php if (empty($history)): ?>
                        <div class="p-12 text-center">
                            <div
                                class="w-16 h-16 mx-auto rounded-2xl bg-gray-100 dark:bg-slate-800 text-gray-400 flex items-center justify-center text-2xl mb-4">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                            <p class="text-sm text-gray-500">No withdrawal requests found.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="bg-gray-50 dark:bg-slate-800/50 border-b border-gray-100 dark:border-gray-800">
                                    <tr>
                                        <th
                                            class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Date</th>
                                        <th
                                            class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Amount</th>
                                        <th
                                            class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Method</th>
                                        <th
                                            class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Account</th>
                                        <th
                                            class="px-6 py-4 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach ($history as $h):
                                        $amt = floatval($h[$p_amount_col] ?? 0);
                                        $meth = $h[$p_method_col] ?? '';
                                        $atit = $h[$p_title_col] ?? '';
                                        $anum = $h[$p_number_col] ?? '';
                                        $stat = strtolower($h[$p_status_col] ?? 'pending');
                                        $s_style = $status_map[$stat] ?? $status_map['pending'];
                                        $req_dt = $h[$p_req_col] ?? '';
                                        ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50">
                                            <td class="px-6 py-4">
                                                <div class="text-xs font-medium"><?= safe_date($req_dt, 'd M Y') ?></div>
                                                <div class="text-[10px] text-gray-400"><?= safe_date($req_dt, 'h:i A') ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="font-black tabular-nums">Rs. <?= number_format($amt) ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold capitalize">
                                                    <i
                                                        class="fa-solid <?= $meth === 'bank' ? 'fa-building-columns text-indigo-500' : 'fa-mobile-screen text-emerald-500' ?> text-[10px]"></i>
                                                    <?= htmlspecialchars($meth) ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-xs font-medium"><?= htmlspecialchars($atit) ?></div>
                                                <div class="text-[10px] text-gray-400 font-mono">
                                                    <?= htmlspecialchars(mask_account((string) $anum)) ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $s_style['bg'] . ' ' . $s_style['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                                    <span class="w-1.5 h-1.5 rounded-full <?= $s_style['dot'] ?>"></span>
                                                    <?= $s_style['label'] ?>
                                                </span>
                                                <?php if ($stat === 'rejected' && !empty($h[$p_note_col])): ?>
                                                    <div class="text-[10px] text-rose-500 mt-1 max-w-[220px] line-clamp-2">
                                                        <?= htmlspecialchars($h[$p_note_col]) ?>
                                                    </div>
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

    <script>
        (function () {
            const html = document.documentElement, t = document.getElementById('themeToggle'), s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            t?.addEventListener('click', () => { html.classList.toggle('dark'); localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light') });

            const bankWrap = document.getElementById('bankFields');
            const bankInput = document.getElementById('bankNameInput');
            function updateBank() {
                const v = document.querySelector('.method-radio:checked')?.value;
                if (v === 'bank') { bankWrap.classList.remove('hidden'); bankInput.required = true; }
                else { bankWrap.classList.add('hidden'); bankInput.required = false; }
            }
            document.querySelectorAll('.method-radio').forEach(r => r.addEventListener('change', updateBank));
            updateBank();
        })();
    </script>
<?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>