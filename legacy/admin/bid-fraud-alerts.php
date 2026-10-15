<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/database.php";
require_once "../includes/csrf.php";

date_default_timezone_set('Asia/Karachi');
$admin_id = (int) $_SESSION['user_id'];

/* Handle action */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nb_csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['alert_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    $valid = ['reviewing', 'dismissed', 'confirmed_fraud', 'action_taken'];
    if ($id > 0 && in_array($action, $valid, true)) {
        $stmt = $conn->prepare("
            UPDATE bid_fraud_alerts
            SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?
            WHERE alert_id = ?
        ");
        $stmt->bind_param("sisi", $action, $admin_id, $note, $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['success'] = "Alert updated.";
        header("Location: bid-fraud-alerts.php");
        exit;
    }
}

/* Filter */
$status_f = $_GET['status'] ?? 'new';

$where = "";
$params = [];
$types = "";
if (in_array($status_f, ['new','reviewing','dismissed','confirmed_fraud','action_taken'], true)) {
    $where = "WHERE a.status = ?";
    $params = [$status_f];
    $types = 's';
}

$sql = "SELECT a.*,
        au.title AS auction_title, au.current_bid,
        s.full_name AS seller_name, s.email AS seller_email,
        bd.full_name AS bidder_name, bd.email AS bidder_email
        FROM bid_fraud_alerts a
        LEFT JOIN auctions au ON a.auction_id = au.auction_id
        LEFT JOIN users s ON a.seller_id = s.user_id
        LEFT JOIN users bd ON a.bidder_id = bd.user_id
        $where
        ORDER BY a.created_at DESC
        LIMIT 100";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$alerts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Stats */
$stats = $conn->query("
    SELECT
        SUM(status='new') AS new_count,
        SUM(status='reviewing') AS reviewing_count,
        SUM(status='confirmed_fraud') AS fraud_count,
        SUM(risk_score >= 70) AS high_risk,
        COUNT(*) AS total
    FROM bid_fraud_alerts
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <title>Bid Fraud Alerts · Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>body{font-family:'Inter',system-ui,sans-serif}</style>
</head>
<body class="bg-slate-950 text-white min-h-screen p-4 sm:p-8">
<div class="max-w-6xl mx-auto space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-black flex items-center gap-3">
            <span class="w-11 h-11 rounded-2xl bg-gradient-to-br from-rose-500 to-red-600 flex items-center justify-center">
                <i class="fa-solid fa-gavel"></i>
            </span>
            Shill Bidding Alerts
        </h1>
        <a href="dashboard.php" class="px-4 py-2 rounded-xl bg-slate-800 text-sm font-semibold">
            <i class="fa-solid fa-arrow-left mr-1"></i> Dashboard
        </a>
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-sm">
            <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="bg-slate-900 rounded-2xl p-4 border border-slate-800">
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">New</div>
            <div class="text-3xl font-black text-amber-400"><?= (int)($stats['new_count'] ?? 0) ?></div>
        </div>
        <div class="bg-slate-900 rounded-2xl p-4 border border-slate-800">
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">Reviewing</div>
            <div class="text-3xl font-black text-blue-400"><?= (int)($stats['reviewing_count'] ?? 0) ?></div>
        </div>
        <div class="bg-slate-900 rounded-2xl p-4 border border-slate-800">
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">High Risk</div>
            <div class="text-3xl font-black text-rose-400"><?= (int)($stats['high_risk'] ?? 0) ?></div>
        </div>
        <div class="bg-slate-900 rounded-2xl p-4 border border-slate-800">
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">Confirmed</div>
            <div class="text-3xl font-black text-rose-500"><?= (int)($stats['fraud_count'] ?? 0) ?></div>
        </div>
        <div class="bg-slate-900 rounded-2xl p-4 border border-slate-800">
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">Total</div>
            <div class="text-3xl font-black"><?= (int)($stats['total'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Filter -->
    <form method="GET" class="bg-slate-900 rounded-2xl p-4 border border-slate-800 flex gap-3">
        <select name="status" onchange="this.form.submit()" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm">
            <?php foreach (['new'=>'New','reviewing'=>'Reviewing','dismissed'=>'Dismissed','confirmed_fraud'=>'Confirmed Fraud','action_taken'=>'Action Taken','all'=>'All'] as $k=>$v): ?>
                <option value="<?= $k ?>" <?= $status_f===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <!-- Alerts -->
    <?php if (empty($alerts)): ?>
        <div class="bg-slate-900 rounded-3xl border border-slate-800 p-16 text-center">
            <i class="fa-solid fa-circle-check text-emerald-400 text-4xl mb-4"></i>
            <p class="text-lg font-bold">No alerts in this category</p>
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($alerts as $a):
                $score = (int)$a['risk_score'];
                $color = $score >= 70 ? 'rose' : ($score >= 40 ? 'amber' : 'emerald');
                $signals = json_decode($a['signals'] ?? '{}', true) ?: [];
            ?>
            <div class="bg-slate-900 rounded-2xl border border-slate-800 p-5">
                <div class="flex flex-col sm:flex-row justify-between gap-3 mb-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            <span class="px-2 py-0.5 rounded bg-<?= $color ?>-500/10 text-<?= $color ?>-400 text-[10px] font-bold uppercase">
                                Risk <?= $score ?>
                            </span>
                            <span class="px-2 py-0.5 rounded bg-slate-700 text-gray-300 text-[10px] font-bold uppercase">
                                <?= htmlspecialchars($a['status']) ?>
                            </span>
                        </div>
                        <div class="font-bold text-sm"><?= htmlspecialchars($a['auction_title'] ?? 'Auction') ?></div>
                        <div class="text-xs text-gray-400 mt-1">
                            Seller: <strong><?= htmlspecialchars($a['seller_name'] ?? '?') ?></strong> ·
                            Bidder: <strong><?= htmlspecialchars($a['bidder_name'] ?? '?') ?></strong>
                        </div>
                    </div>
                    <div class="text-right text-[11px] text-gray-500 shrink-0">
                        <?= date('d M Y, h:i A', strtotime($a['created_at'])) ?>
                    </div>
                </div>

                <?php if (!empty($a['ai_reason'])): ?>
                    <div class="p-3 rounded-xl bg-slate-800/50 border border-slate-800 mb-3">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">AI Reason</div>
                        <div class="text-xs text-gray-300"><?= htmlspecialchars($a['ai_reason']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($signals)): ?>
                    <div class="flex flex-wrap gap-1.5 mb-3">
                        <?php foreach ($signals as $k => $v): ?>
                            <span class="px-2 py-0.5 rounded bg-slate-800 text-[10px] font-mono text-gray-400">
                                <?= htmlspecialchars($k) ?><?= $v === true ? '' : ': ' . htmlspecialchars((string)$v) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (in_array($a['status'], ['new','reviewing'], true)): ?>
                    <form method="POST" class="flex flex-wrap gap-2 pt-3 border-t border-slate-800">
                        <?php nb_csrf_field(); ?>
                        <input type="hidden" name="alert_id" value="<?= (int)$a['alert_id'] ?>">
                        <input type="text" name="note" placeholder="Optional note…" class="flex-1 min-w-[200px] px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-xs">
                        <button type="submit" name="action" value="reviewing" class="px-3 py-2 rounded-lg bg-blue-500/10 text-blue-400 text-xs font-bold hover:bg-blue-500 hover:text-white transition">Review</button>
                        <button type="submit" name="action" value="confirmed_fraud" class="px-3 py-2 rounded-lg bg-rose-500/10 text-rose-400 text-xs font-bold hover:bg-rose-500 hover:text-white transition">Confirm Fraud</button>
                        <button type="submit" name="action" value="dismissed" class="px-3 py-2 rounded-lg bg-gray-500/10 text-gray-400 text-xs font-bold hover:bg-gray-600 hover:text-white transition">Dismiss</button>
                    </form>
                <?php else: ?>
                    <div class="pt-3 border-t border-slate-800 text-[11px] text-gray-500">
                        Note: <?= htmlspecialchars($a['review_note'] ?? '—') ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
</body>
</html>