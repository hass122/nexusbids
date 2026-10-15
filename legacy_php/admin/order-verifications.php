<?php
session_start();
date_default_timezone_set('Asia/Karachi');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/notifications.php";
require_once "../includes/order-proofs.php";

$admin_id = (int) $_SESSION['user_id'];

$filter = $_GET['status'] ?? 'pending';
$valid = ['pending', 'reviewing', 'approved', 'rejected', 'disputed', 'all'];
if (!in_array($filter, $valid, true))
    $filter = 'pending';

$where = "o.buyer_proof_uploaded_at IS NOT NULL";
$params = [];
$types = "";

if ($filter !== 'all') {
    $where .= " AND o.admin_review_status = ?";
    $params[] = $filter;
    $types = "s";
}

$sql = "
    SELECT o.order_id, o.order_number, o.order_status, o.payment_status,
           o.total_amount, o.seller_amount, o.admin_review_status,
           o.seller_proof_uploaded_at, o.buyer_proof_uploaded_at,
           o.created_at, o.auction_id,
           a.title AS auction_title, a.image AS auction_image,
           s.full_name AS seller_name,
           b.full_name AS buyer_name,
           (SELECT COUNT(*) FROM order_proofs WHERE order_id=o.order_id AND uploader_type='seller') AS seller_proofs,
           (SELECT COUNT(*) FROM order_proofs WHERE order_id=o.order_id AND uploader_type='buyer') AS buyer_proofs
    FROM orders o
    INNER JOIN auctions a ON o.auction_id = a.auction_id
    INNER JOIN users s ON o.seller_id = s.user_id
    INNER JOIN users b ON o.buyer_id = b.user_id
    WHERE $where
    ORDER BY o.buyer_proof_uploaded_at DESC
    LIMIT 100
";

$stmt = $conn->prepare($sql);
if ($types)
    $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Stats */
$stats = ['pending' => 0, 'reviewing' => 0, 'approved' => 0, 'rejected' => 0, 'disputed' => 0];
$r = $conn->query("SELECT admin_review_status, COUNT(*) AS c FROM orders WHERE buyer_proof_uploaded_at IS NOT NULL GROUP BY admin_review_status");
while ($x = $r->fetch_assoc())
    $stats[$x['admin_review_status']] = (int) $x['c'];

$unread_notifications = nb_unread_count($conn, $admin_id);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Verifications · Admin · NexusBids</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = {darkMode: 'class', theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}}}};</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%)
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">

    <header
        class="sticky top-0 z-40 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-xl font-black" href="index.php">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white"><i
                        class="fa-solid fa-gavel"></i></span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
                <span class="text-xs bg-rose-500 text-white px-2 py-0.5 rounded font-black ml-1">ADMIN</span>
            </a>
            <div class="flex items-center gap-2">
                <button id="themeToggle"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                    <i class="fa-solid fa-moon dark:hidden"></i><i
                        class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>
                <a href="index.php"
                    class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:text-indigo-600 transition">
                    <i class="fa-solid fa-arrow-left mr-1.5"></i> Admin Home
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-8 py-8 space-y-6">

        <div>
            <nav class="flex items-center gap-2 text-xs text-gray-500 mb-3">
                <a href="index.php" class="hover:text-indigo-600">Admin</a>
                <i class="fa-solid fa-chevron-right text-[8px]"></i>
                <span class="text-gray-900 dark:text-white font-semibold">Order Verifications</span>
            </nav>
            <h1 class="text-3xl font-black tracking-tight flex items-center gap-3">
                <span
                    class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-500 flex items-center justify-center text-white shadow-lg"><i
                        class="fa-solid fa-clipboard-check text-lg"></i></span>
                Order Proof Verifications
            </h1>
            <p class="text-sm text-gray-500 mt-2">Review seller shipping + buyer receiving proofs. Release payment after
                verification.</p>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <?php
            $stat_meta = [
                'pending' => ['label' => 'Pending', 'icon' => 'fa-hourglass-half', 'bg' => 'bg-amber-500/10', 'text' => 'text-amber-600 dark:text-amber-400'],
                'reviewing' => ['label' => 'Reviewing', 'icon' => 'fa-magnifying-glass', 'bg' => 'bg-blue-500/10', 'text' => 'text-blue-600 dark:text-blue-400'],
                'approved' => ['label' => 'Approved', 'icon' => 'fa-circle-check', 'bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-600 dark:text-emerald-400'],
                'rejected' => ['label' => 'Rejected', 'icon' => 'fa-circle-xmark', 'bg' => 'bg-rose-500/10', 'text' => 'text-rose-600 dark:text-rose-400'],
                'disputed' => ['label' => 'Disputed', 'icon' => 'fa-scale-balanced', 'bg' => 'bg-violet-500/10', 'text' => 'text-violet-600 dark:text-violet-400'],
            ];
            foreach ($stat_meta as $k => $m): ?>
                <a href="?status=<?= $k ?>"
                    class="bg-white dark:bg-slate-900 rounded-2xl p-5 border <?= $filter === $k ? 'border-indigo-500' : 'border-gray-200 dark:border-gray-800' ?> hover:border-indigo-500 transition">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider"><?= $m['label'] ?></span>
                        <span
                            class="w-9 h-9 rounded-lg <?= $m['bg'] ?> <?= $m['text'] ?> flex items-center justify-center text-xs"><i
                                class="fa-solid <?= $m['icon'] ?>"></i></span>
                    </div>
                    <div class="text-2xl font-black tabular-nums"><?= $stats[$k] ?? 0 ?></div>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- List -->
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <?php if (empty($rows)): ?>
                <div class="p-16 text-center">
                    <div
                        class="w-20 h-20 mx-auto rounded-2xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center text-3xl mb-5">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <h3 class="text-xl font-black">All caught up!</h3>
                    <p class="text-sm text-gray-500 mt-2">No orders in this queue.</p>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    <?php foreach ($rows as $r):
                        $status_meta = match ($r['admin_review_status']) {
                            'approved' => ['label' => 'Approved', 'bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'icon' => 'fa-circle-check'],
                            'rejected' => ['label' => 'Rejected', 'bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'icon' => 'fa-circle-xmark'],
                            'disputed' => ['label' => 'Disputed', 'bg' => 'bg-violet-500/10', 'text' => 'text-violet-700 dark:text-violet-400', 'icon' => 'fa-scale-balanced'],
                            'reviewing' => ['label' => 'Reviewing', 'bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'icon' => 'fa-magnifying-glass'],
                            default => ['label' => 'Pending', 'bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'icon' => 'fa-hourglass-half'],
                        };
                        ?>
                        <div class="p-5 hover:bg-gray-50 dark:hover:bg-slate-800/50 transition">
                            <div class="flex flex-col lg:flex-row items-start lg:items-center gap-4">
                                <!-- Image -->
                                <div
                                    class="w-16 h-16 rounded-xl overflow-hidden bg-gray-100 dark:bg-slate-800 flex items-center justify-center shrink-0">
                                    <?php if (!empty($r['auction_image'])): ?>
                                        <img src="../uploads/auctions/<?= htmlspecialchars($r['auction_image']) ?>"
                                            class="w-full h-full object-cover" alt="">
                                    <?php else: ?>
                                        <i class="fa-solid fa-image text-gray-400"></i>
                                    <?php endif; ?>
                                </div>

                                <!-- Info -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-wrap items-center gap-2 mb-1">
                                        <span
                                            class="font-mono text-xs font-bold"><?= htmlspecialchars($r['order_number']) ?></span>
                                        <span
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $status_meta['bg'] ?> <?= $status_meta['text'] ?> text-[10px] font-bold uppercase tracking-wider">
                                            <i class="fa-solid <?= $status_meta['icon'] ?> text-[9px]"></i>
                                            <?= $status_meta['label'] ?>
                                        </span>
                                    </div>
                                    <h3 class="font-bold truncate"><?= htmlspecialchars($r['auction_title']) ?></h3>
                                    <div class="flex flex-wrap gap-3 text-xs text-gray-500 mt-1">
                                        <span><i class="fa-solid fa-store text-[10px]"></i>
                                            <?= htmlspecialchars($r['seller_name']) ?></span>
                                        <span><i class="fa-solid fa-user text-[10px]"></i>
                                            <?= htmlspecialchars($r['buyer_name']) ?></span>
                                        <span><i class="fa-solid fa-video text-[10px]"></i> Seller proofs:
                                            <strong><?= (int) $r['seller_proofs'] ?></strong></span>
                                        <span><i class="fa-solid fa-video text-[10px]"></i> Buyer proofs:
                                            <strong><?= (int) $r['buyer_proofs'] ?></strong></span>
                                    </div>
                                </div>

                                <!-- Amount -->
                                <div class="text-right shrink-0">
                                    <div class="text-[10px] uppercase font-bold text-gray-400">Seller Payout</div>
                                    <div class="text-xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">Rs.
                                        <?= number_format((float) $r['seller_amount']) ?></div>
                                </div>

                                <!-- Action -->
                                <a href="order-proof-review.php?id=<?= (int) $r['order_id'] ?>"
                                    class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold shrink-0 transition">
                                    <i class="fa-solid fa-gavel"></i> Review
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        (function () {const h = document.documentElement, s = localStorage.getItem('theme'); if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) h.classList.add('dark'); document.getElementById('themeToggle')?.addEventListener('click', () => {h.classList.toggle('dark'); localStorage.setItem('theme', h.classList.contains('dark') ? 'dark' : 'light');});})();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>