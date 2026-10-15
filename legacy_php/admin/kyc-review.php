<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/database.php";
require_once "../includes/csrf.php";

date_default_timezone_set('Asia/Karachi');

$admin_id = (int) $_SESSION['user_id'];

/* Handle review action */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nb_csrf_check();
    $action = $_POST['action'] ?? '';
    $vid    = (int) ($_POST['verification_id'] ?? 0);
    $note   = trim($_POST['note'] ?? '');

    if ($vid > 0 && in_array($action, ['approve', 'reject'], true)) {
        $new_status  = $action === 'approve' ? 'verified' : 'rejected';
        $user_status = $new_status;

        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("
                UPDATE kyc_verifications
                SET ai_status = ?, admin_id = ?, admin_note = ?, reviewed_at = NOW()
                WHERE verification_id = ?
            ");
            $upd->bind_param("sisi", $new_status, $admin_id, $note, $vid);
            $upd->execute();
            $upd->close();

            $get = $conn->prepare("SELECT user_id FROM kyc_verifications WHERE verification_id = ?");
            $get->bind_param("i", $vid);
            $get->execute();
            $row = $get->get_result()->fetch_assoc();
            $get->close();

            if ($row) {
                $u = $conn->prepare("UPDATE users SET kyc_status = ? WHERE user_id = ?");
                $u->bind_param("si", $user_status, $row['user_id']);
                $u->execute();
                $u->close();
            }

            $conn->commit();
            $_SESSION['success'] = "KYC " . $new_status . ".";
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['error'] = "Update failed.";
        }
        header("Location: kyc-review.php");
        exit;
    }
}

/* Filter */
$status_f = $_GET['status'] ?? 'manual_review';
$valid_statuses = ['manual_review', 'verified', 'rejected', 'pending', 'all'];

$where = "";
$params = [];
$types = "";

if (in_array($status_f, ['manual_review', 'verified', 'rejected', 'pending'], true)) {
    $where = "WHERE k.ai_status = ?";
    $params = [$status_f];
    $types = 's';
}

$sql = "SELECT k.*, u.full_name AS user_name, u.email AS user_email, u.kyc_status
        FROM kyc_verifications k
        INNER JOIN users u ON k.user_id = u.user_id
        $where
        ORDER BY k.created_at DESC
        LIMIT 100";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Stats */
$stats = $conn->query("
    SELECT
        SUM(ai_status='manual_review') AS review_count,
        SUM(ai_status='verified') AS verified_count,
        SUM(ai_status='rejected') AS rejected_count,
        COUNT(*) AS total
    FROM kyc_verifications
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <title>KYC Review · Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>body{font-family:'Inter',system-ui,sans-serif}</style>
</head>
<body class="bg-slate-950 text-white min-h-screen p-4 sm:p-8">
<div class="max-w-6xl mx-auto space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-black flex items-center gap-3">
            <span class="w-11 h-11 rounded-2xl bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center">
                <i class="fa-solid fa-id-card"></i>
            </span>
            KYC Review
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

    <?php if (!empty($_SESSION['error'])): ?>
        <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm">
            <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-slate-900 rounded-2xl p-4 border border-slate-800">
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">Manual Review</div>
            <div class="text-3xl font-black text-amber-400"><?= (int)($stats['review_count'] ?? 0) ?></div>
        </div>
        <div class="bg-slate-900 rounded-2xl p-4 border border-slate-800">
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">Verified</div>
            <div class="text-3xl font-black text-emerald-400"><?= (int)($stats['verified_count'] ?? 0) ?></div>
        </div>
        <div class="bg-slate-900 rounded-2xl p-4 border border-slate-800">
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">Rejected</div>
            <div class="text-3xl font-black text-rose-400"><?= (int)($stats['rejected_count'] ?? 0) ?></div>
        </div>
        <div class="bg-slate-900 rounded-2xl p-4 border border-slate-800">
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">Total</div>
            <div class="text-3xl font-black"><?= (int)($stats['total'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Filter -->
    <form method="GET" class="bg-slate-900 rounded-2xl p-4 border border-slate-800 flex gap-3">
        <select name="status" onchange="this.form.submit()" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm">
            <?php foreach (['manual_review' => 'Manual Review', 'verified' => 'Verified', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $status_f === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <!-- Records -->
    <?php if (empty($records)): ?>
        <div class="bg-slate-900 rounded-3xl border border-slate-800 p-16 text-center">
            <i class="fa-solid fa-check-circle text-emerald-400 text-4xl mb-4"></i>
            <p class="text-lg font-bold">No records in this category</p>
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($records as $r):
                $conf = (int) $r['ai_confidence'];
                $confColor = $conf >= 80 ? 'emerald' : ($conf >= 50 ? 'amber' : 'rose');
                $signals = json_decode($r['ai_signals'] ?? '{}', true) ?: [];
            ?>
            <div class="bg-slate-900 rounded-2xl border border-slate-800 p-5">
                <div class="flex flex-col sm:flex-row sm:items-start gap-4">

                    <div class="flex gap-2 shrink-0">
                        <?php if ($r['front_image']): ?>
                            <a href="../uploads/kyc/<?= htmlspecialchars($r['front_image']) ?>" target="_blank" class="block">
                                <img src="../uploads/kyc/<?= htmlspecialchars($r['front_image']) ?>" class="w-24 h-16 object-cover rounded-lg border border-slate-700">
                            </a>
                        <?php endif; ?>
                        <?php if ($r['back_image']): ?>
                            <a href="../uploads/kyc/<?= htmlspecialchars($r['back_image']) ?>" target="_blank" class="block">
                                <img src="../uploads/kyc/<?= htmlspecialchars($r['back_image']) ?>" class="w-24 h-16 object-cover rounded-lg border border-slate-700">
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            <span class="px-2 py-0.5 rounded bg-<?= $confColor ?>-500/10 text-<?= $confColor ?>-400 text-[10px] font-bold uppercase">
                                Confidence: <?= $conf ?>%
                            </span>
                            <span class="px-2 py-0.5 rounded bg-slate-700 text-gray-300 text-[10px] font-bold uppercase">
                                <?= htmlspecialchars($r['ai_status']) ?>
                            </span>
                        </div>
                        <div class="font-bold text-sm"><?= htmlspecialchars($r['user_name']) ?></div>
                        <div class="text-xs text-gray-400"><?= htmlspecialchars($r['user_email']) ?></div>
                        <div class="text-xs text-gray-500 mt-1 font-mono">
                            CNIC: <?= htmlspecialchars($r['id_number']) ?>
                        </div>
                        <?php if ($r['ai_reason']): ?>
                            <div class="text-xs text-gray-300 mt-2 p-2 rounded bg-slate-800">
                                <?= htmlspecialchars($r['ai_reason']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($signals['issues']) && is_array($signals['issues'])): ?>
                            <div class="mt-2 flex flex-wrap gap-1">
                                <?php foreach ($signals['issues'] as $issue): ?>
                                    <span class="px-2 py-0.5 rounded bg-rose-500/10 text-rose-400 text-[10px]">
                                        <?= htmlspecialchars($issue) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($r['ai_status'] === 'manual_review'): ?>
                        <div class="flex flex-col gap-2 shrink-0">
                            <form method="POST" class="flex flex-col gap-2">
                                <?php nb_csrf_field(); ?>
                                <input type="hidden" name="verification_id" value="<?= (int) $r['verification_id'] ?>">
                                <input type="text" name="note" placeholder="Optional note" class="px-2 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-xs w-44">
                                <button type="submit" name="action" value="approve" class="px-3 py-1.5 rounded-lg bg-emerald-500 text-white text-xs font-bold hover:bg-emerald-600">
                                    ✓ Approve
                                </button>
                                <button type="submit" name="action" value="reject" class="px-3 py-1.5 rounded-lg bg-rose-500 text-white text-xs font-bold hover:bg-rose-600">
                                    ✗ Reject
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="text-right text-[11px] text-gray-500 shrink-0">
                            <?php if ($r['admin_note']): ?>
                                <div class="mb-1">Note: <?= htmlspecialchars($r['admin_note']) ?></div>
                            <?php endif; ?>
                            <div>Reviewed: <?= $r['reviewed_at'] ? date('d M Y', strtotime($r['reviewed_at'])) : '—' ?></div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
</body>
</html>