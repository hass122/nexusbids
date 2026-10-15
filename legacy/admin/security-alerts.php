<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";

date_default_timezone_set('Asia/Karachi');

$admin_id = (int) $_SESSION['user_id'];

/* Handle review actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nb_csrf_check();
    $action = $_POST['action'] ?? '';
    $alert_id = (int) ($_POST['alert_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if ($alert_id > 0 && in_array($action, ['resolve', 'false_positive', 'reviewing'], true)) {
        $stmt = $conn->prepare("
            UPDATE security_alerts
            SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?
            WHERE alert_id = ?
        ");
        $stmt->bind_param("sisi", $action, $admin_id, $note, $alert_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['success'] = "Alert updated.";
        header("Location: security-alerts.php");
        exit;
    }
}

/* Filters */
$status_f = $_GET['status'] ?? 'new';
$min_score = (int) ($_GET['min_score'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;

$where = [];
$params = [];
$types = '';

if ($status_f !== 'all') {
    $where[] = "a.status = ?";
    $params[] = $status_f;
    $types .= 's';
}
if ($min_score > 0) {
    $where[] = "a.risk_score >= ?";
    $params[] = $min_score;
    $types .= 'i';
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* Count */
$count_sql = "SELECT COUNT(*) AS t FROM security_alerts a $where_sql";
$cs = $conn->prepare($count_sql);
if ($types) $cs->bind_param($types, ...$params);
$cs->execute();
$total = (int) $cs->get_result()->fetch_assoc()['t'];
$cs->close();

$pages = max(1, (int) ceil($total / $per_page));
$page = min($page, $pages);
$offset = ($page - 1) * $per_page;

/* Fetch */
$sql = "SELECT a.*, u.full_name AS user_name, u.email AS user_email
        FROM security_alerts a
        LEFT JOIN users u ON a.user_id = u.user_id
        $where_sql
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

$st = $conn->prepare($sql);
$st->bind_param($types, ...$params);
$st->execute();
$alerts = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* Stats */
$stats = $conn->query("
    SELECT
        SUM(CASE WHEN status='new' THEN 1 ELSE 0 END) AS new_count,
        SUM(CASE WHEN status='reviewing' THEN 1 ELSE 0 END) AS reviewing_count,
        SUM(CASE WHEN risk_score >= 70 THEN 1 ELSE 0 END) AS high_risk,
        COUNT(*) AS total
    FROM security_alerts
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <title>Security Alerts · Admin</title>
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
                    <i class="fa-solid fa-shield-halved"></i>
                </span>
                Security Alerts
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
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-slate-900 rounded-2xl p-4 border border-slate-800">
                <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">New</div>
                <div class="text-3xl font-black text-amber-400"><?= (int)($stats['new_count'] ?? 0) ?></div>
            </div>
            <div class="bg-slate-900 rounded-2xl p-4 border border-slate-800">
                <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">Reviewing</div>
                <div class="text-3xl font-black text-blue-400"><?= (int)($stats['reviewing_count'] ?? 0) ?></div>
            </div>
            <div class="bg-slate-900 rounded-2xl p-4 border border-rose-500/30">
                <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">High Risk (70+)</div>
                <div class="text-3xl font-black text-rose-400"><?= (int)($stats['high_risk'] ?? 0) ?></div>
            </div>
            <div class="bg-slate-900 rounded-2xl p-4 border border-slate-800">
                <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">Total</div>
                <div class="text-3xl font-black"><?= (int)($stats['total'] ?? 0) ?></div>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="flex flex-wrap gap-3 bg-slate-900 rounded-2xl p-4 border border-slate-800">
            <select name="status" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm">
                <?php foreach (['new' => 'New', 'reviewing' => 'Reviewing', 'resolved' => 'Resolved', 'false_positive' => 'False Positive', 'all' => 'All'] as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $status_f === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <select name="min_score" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm">
                <option value="0">Any Score</option>
                <option value="30" <?= $min_score === 30 ? 'selected' : '' ?>>30+</option>
                <option value="50" <?= $min_score === 50 ? 'selected' : '' ?>>50+</option>
                <option value="70" <?= $min_score === 70 ? 'selected' : '' ?>>70+</option>
                <option value="90" <?= $min_score === 90 ? 'selected' : '' ?>>90+</option>
            </select>
            <button class="px-4 py-2 rounded-xl bg-indigo-600 text-sm font-bold">Filter</button>
        </form>

        <!-- List -->
        <?php if (empty($alerts)): ?>
            <div class="bg-slate-900 rounded-3xl border border-slate-800 p-16 text-center">
                <div class="w-16 h-16 mx-auto rounded-2xl bg-slate-800 flex items-center justify-center text-2xl mb-4 text-gray-500">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <h3 class="font-bold text-lg">No alerts</h3>
                <p class="text-sm text-gray-400 mt-2">All clear.</p>
            </div>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($alerts as $a):
                    $score = (int) $a['risk_score'];
                    $scoreColor = $score >= 70 ? 'rose' : ($score >= 31 ? 'amber' : 'emerald');
                    $actionMap = [
                        'allow'  => ['emerald', 'Allowed'],
                        'verify' => ['amber', 'OTP Required'],
                        'block'  => ['rose', 'Blocked'],
                    ];
                    [$acol, $alab] = $actionMap[$a['action_taken']] ?? ['gray', 'Unknown'];
                    $signals = json_decode($a['signals'] ?? '{}', true) ?: [];
                ?>
                <div class="bg-slate-900 rounded-2xl border border-slate-800 p-5">
                    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3 mb-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap mb-1">
                                <span class="px-2.5 py-1 rounded-lg bg-<?= $scoreColor ?>-500/10 text-<?= $scoreColor ?>-400 text-[11px] font-bold uppercase tracking-wider">
                                    Risk <?= $score ?>
                                </span>
                                <span class="px-2.5 py-1 rounded-lg bg-<?= $acol ?>-500/10 text-<?= $acol ?>-400 text-[11px] font-bold uppercase tracking-wider">
                                    <?= $alab ?>
                                </span>
                                <?php if ($a['status'] === 'new'): ?>
                                    <span class="px-2.5 py-1 rounded-lg bg-blue-500/10 text-blue-400 text-[11px] font-bold uppercase tracking-wider">New</span>
                                <?php endif; ?>
                            </div>
                            <div class="font-bold text-sm"><?= htmlspecialchars($a['user_name'] ?? 'Unknown User') ?></div>
                            <div class="text-xs text-gray-400"><?= htmlspecialchars($a['user_email'] ?? '') ?> · #<?= (int) $a['user_id'] ?></div>
                        </div>
                        <div class="text-right text-[11px] text-gray-500 shrink-0">
                            <?= date('d M Y, h:i A', strtotime($a['created_at'])) ?><br>
                            IP: <?= htmlspecialchars($a['ip_address'] ?? '—') ?>
                        </div>
                    </div>

                    <?php if (!empty($a['ai_reason'])): ?>
                        <div class="p-3 rounded-xl bg-slate-800/50 border border-slate-800 mb-3">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">AI Reasoning</div>
                            <div class="text-xs text-gray-300"><?= htmlspecialchars($a['ai_reason']) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($signals)): ?>
                        <div class="flex flex-wrap gap-1.5 mb-3">
                            <?php foreach ($signals as $k => $v): ?>
                                <span class="px-2 py-0.5 rounded bg-slate-800 text-[10px] font-mono text-gray-400">
                                    <?= htmlspecialchars($k) ?><?= $v === true ? '' : ': ' . htmlspecialchars((string) $v) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($a['status'] === 'new' || $a['status'] === 'reviewing'): ?>
                        <form method="POST" class="flex flex-wrap gap-2 pt-3 border-t border-slate-800">
                            <?php nb_csrf_field(); ?>
                            <input type="hidden" name="alert_id" value="<?= (int) $a['alert_id'] ?>">
                            <input type="text" name="note" placeholder="Optional note…" class="flex-1 min-w-[200px] px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-xs">
                            <button type="submit" name="action" value="reviewing" class="px-3 py-2 rounded-lg bg-blue-500/10 text-blue-400 text-xs font-bold hover:bg-blue-500 hover:text-white transition">Reviewing</button>
                            <button type="submit" name="action" value="false_positive" class="px-3 py-2 rounded-lg bg-gray-500/10 text-gray-400 text-xs font-bold hover:bg-gray-600 hover:text-white transition">False Positive</button>
                            <button type="submit" name="action" value="resolve" class="px-3 py-2 rounded-lg bg-emerald-500/10 text-emerald-400 text-xs font-bold hover:bg-emerald-500 hover:text-white transition">Resolve</button>
                        </form>
                    <?php else: ?>
                        <div class="pt-3 border-t border-slate-800 text-[11px] text-gray-500">
                            Status: <strong class="text-gray-300"><?= htmlspecialchars($a['status']) ?></strong>
                            <?php if (!empty($a['review_note'])): ?>
                                · <?= htmlspecialchars($a['review_note']) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($pages > 1): ?>
                <div class="flex justify-center gap-1.5">
                    <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                        <a href="?status=<?= urlencode($status_f) ?>&min_score=<?= $min_score ?>&page=<?= $p ?>"
                           class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold <?= $p === $page ? 'bg-indigo-600' : 'bg-slate-800' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>