<?php
session_start();
date_default_timezone_set('Asia/Karachi');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/order-proofs.php";

$admin_id = (int) $_SESSION['user_id'];
$order_id = (int) ($_GET['id'] ?? 0);

if ($order_id <= 0) {
    header("Location: order-verifications.php");
    exit();
}

/* Fetch order */
$stmt = $conn->prepare("
    SELECT o.*, a.title AS auction_title, a.image AS auction_image, a.auction_id,
           s.full_name AS seller_name, s.email AS seller_email,
           b.full_name AS buyer_name, b.email AS buyer_email
    FROM orders o
    INNER JOIN auctions a ON o.auction_id = a.auction_id
    INNER JOIN users s ON o.seller_id = s.user_id
    INNER JOIN users b ON o.buyer_id = b.user_id
    WHERE o.order_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    $_SESSION['flash_error'] = 'Order not found.';
    header("Location: order-verifications.php");
    exit();
}

/* Mark as reviewing */
if ($order['admin_review_status'] === 'pending') {
    $upd = $conn->prepare("UPDATE orders SET admin_review_status='reviewing' WHERE order_id=?");
    $upd->bind_param("i", $order_id);
    $upd->execute();
    $upd->close();
    $order['admin_review_status'] = 'reviewing';
}

/* Fetch proofs */
$seller_proofs = nb_proof_list($conn, $order_id, 'seller');
$buyer_proofs = nb_proof_list($conn, $order_id, 'buyer');
$all_proofs = array_merge($seller_proofs, $buyer_proofs);

$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$img_url = '';
if (!empty($order['auction_image'])) {
    $b = basename($order['auction_image']);
    if (file_exists(__DIR__ . '/../uploads/auctions/' . $b)) {
        $img_url = '../uploads/auctions/' . $b;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Proof Review · Admin · NexusBids</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
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

        .proof-card {
            transition: all .2s ease
        }

        .proof-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, .1)
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">

    <header
        class="sticky top-0 z-40 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-xl font-black" href="order-verifications.php">
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
                <a href="order-verifications.php"
                    class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:text-indigo-600 transition">
                    <i class="fa-solid fa-arrow-left mr-1.5"></i> Back to Queue
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-8 py-8 space-y-6">

        <?php if ($flash_success): ?>
            <div
                class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-700 dark:text-emerald-300 flex items-center gap-3">
                <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($flash_success) ?>
            </div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div
                class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-700 dark:text-rose-300 flex items-center gap-3">
                <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($flash_error) ?>
            </div>
        <?php endif; ?>

        <!-- Header card -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-6 shadow-sm">
            <div class="flex flex-col lg:flex-row items-start justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div
                        class="w-16 h-16 rounded-2xl overflow-hidden bg-gray-100 dark:bg-slate-800 flex items-center justify-center shrink-0">
                        <?php if ($img_url): ?>
                            <img src="<?= htmlspecialchars($img_url) ?>" class="w-full h-full object-cover" alt="">
                        <?php else: ?>
                            <i class="fa-solid fa-image text-gray-400"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="font-mono text-xs text-gray-500"><?= htmlspecialchars($order['order_number']) ?>
                        </div>
                        <h1 class="text-xl font-black"><?= htmlspecialchars($order['auction_title']) ?></h1>
                        <div class="flex flex-wrap gap-3 text-xs text-gray-500 mt-1">
                            <span><i class="fa-solid fa-store"></i> Seller:
                                <strong><?= htmlspecialchars($order['seller_name']) ?></strong></span>
                            <span><i class="fa-solid fa-user"></i> Buyer:
                                <strong><?= htmlspecialchars($order['buyer_name']) ?></strong></span>
                        </div>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-[10px] uppercase font-bold text-gray-400">Seller Payout</div>
                    <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">Rs.
                        <?= number_format((float) $order['seller_amount']) ?></div>
                    <div class="text-[10px] text-gray-400 mt-0.5">Total: Rs.
                        <?= number_format((float) $order['total_amount']) ?></div>
                </div>
            </div>
        </div>

        <!-- SELLER PROOFS -->
        <section class="bg-white dark:bg-slate-900 rounded-3xl border-2 border-indigo-500/30 p-6 shadow-sm">
            <div class="flex items-center gap-3 pb-4 border-b border-gray-100 dark:border-gray-800 mb-5">
                <span
                    class="w-12 h-12 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-lg">
                    <i class="fa-solid fa-box-open"></i>
                </span>
                <div class="flex-1">
                    <h2 class="text-lg font-black">Seller Shipping Proof</h2>
                    <p class="text-xs text-gray-500">
                        Uploaded
                        <?= !empty($order['seller_proof_uploaded_at']) ? date('d M Y, h:i A', strtotime($order['seller_proof_uploaded_at'])) : '—' ?>
                        · Courier: <strong><?= htmlspecialchars($order['courier'] ?? '—') ?></strong>
                        · Tracking: <strong
                            class="font-mono"><?= htmlspecialchars($order['tracking_number'] ?? '—') ?></strong>
                    </p>
                </div>
                <span
                    class="text-xs font-bold bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 px-3 py-1.5 rounded-full">
                    <?= count($seller_proofs) ?> file<?= count($seller_proofs) === 1 ? '' : 's' ?>
                </span>
            </div>

            <?php if (empty($seller_proofs)): ?>
                <div class="p-8 text-center text-gray-500 text-sm">No seller proofs uploaded.</div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($seller_proofs as $p):
                        $meta = nb_proof_type_meta($p['proof_type']);
                        ?>
                        <div
                            class="proof-card rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden bg-gray-50 dark:bg-slate-800/50">
                            <div class="aspect-video bg-black flex items-center justify-center">
                                <?php if ($p['file_type'] === 'video'): ?>
                                    <video src="../order-media.php?id=<?= (int) $p['proof_id'] ?>" controls
                                        class="w-full h-full object-contain"></video>
                                <?php else: ?>
                                    <img src="../order-media.php?id=<?= (int) $p['proof_id'] ?>"
                                        class="w-full h-full object-contain cursor-pointer"
                                        onclick="window.open(this.src, '_blank')">
                                <?php endif; ?>
                            </div>
                            <div
                                class="p-3 flex items-center justify-between gap-2 border-t border-gray-200 dark:border-gray-800">
                                <div class="min-w-0">
                                    <div class="text-xs font-bold truncate flex items-center gap-1.5">
                                        <i class="fa-solid <?= $meta['icon'] ?> text-<?= $meta['color'] ?>-500 text-[10px]"></i>
                                        <?= htmlspecialchars($meta['label']) ?>
                                    </div>
                                    <div class="text-[10px] text-gray-500 mt-0.5">
                                        <?= date('d M, h:i A', strtotime($p['created_at'])) ?>
                                    </div>
                                </div>
                                <span
                                    class="text-[10px] px-2 py-0.5 rounded bg-<?= $p['file_type'] === 'video' ? 'rose' : 'blue' ?>-500/10 text-<?= $p['file_type'] === 'video' ? 'rose' : 'blue' ?>-600 dark:text-<?= $p['file_type'] === 'video' ? 'rose' : 'blue' ?>-400 font-bold uppercase tracking-wider">
                                    <?= $p['file_type'] ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- BUYER PROOFS -->
        <section class="bg-white dark:bg-slate-900 rounded-3xl border-2 border-emerald-500/30 p-6 shadow-sm">
            <div class="flex items-center gap-3 pb-4 border-b border-gray-100 dark:border-gray-800 mb-5">
                <span
                    class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-lg">
                    <i class="fa-solid fa-handshake"></i>
                </span>
                <div class="flex-1">
                    <h2 class="text-lg font-black">Buyer Receiving Proof</h2>
                    <p class="text-xs text-gray-500">
                        Uploaded
                        <?= !empty($order['buyer_proof_uploaded_at']) ? date('d M Y, h:i A', strtotime($order['buyer_proof_uploaded_at'])) : '—' ?>
                        · Confirmed:
                        <?= !empty($order['buyer_confirmed_at']) ? date('d M Y', strtotime($order['buyer_confirmed_at'])) : '—' ?>
                    </p>
                </div>
                <span
                    class="text-xs font-bold bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 px-3 py-1.5 rounded-full">
                    <?= count($buyer_proofs) ?> file<?= count($buyer_proofs) === 1 ? '' : 's' ?>
                </span>
            </div>

            <?php if (empty($buyer_proofs)): ?>
                <div class="p-8 text-center text-gray-500 text-sm">No buyer proofs uploaded yet.</div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($buyer_proofs as $p):
                        $meta = nb_proof_type_meta($p['proof_type']);
                        ?>
                        <div
                            class="proof-card rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden bg-gray-50 dark:bg-slate-800/50">
                            <div class="aspect-video bg-black flex items-center justify-center">
                                <?php if ($p['file_type'] === 'video'): ?>
                                    <video src="../order-media.php?id=<?= (int) $p['proof_id'] ?>" controls
                                        class="w-full h-full object-contain"></video>
                                <?php else: ?>
                                    <img src="../order-media.php?id=<?= (int) $p['proof_id'] ?>"
                                        class="w-full h-full object-contain cursor-pointer"
                                        onclick="window.open(this.src, '_blank')">
                                <?php endif; ?>
                            </div>
                            <div
                                class="p-3 flex items-center justify-between gap-2 border-t border-gray-200 dark:border-gray-800">
                                <div class="min-w-0">
                                    <div class="text-xs font-bold truncate flex items-center gap-1.5">
                                        <i class="fa-solid <?= $meta['icon'] ?> text-<?= $meta['color'] ?>-500 text-[10px]"></i>
                                        <?= htmlspecialchars($meta['label']) ?>
                                    </div>
                                    <div class="text-[10px] text-gray-500 mt-0.5">
                                        <?= date('d M, h:i A', strtotime($p['created_at'])) ?>
                                    </div>
                                </div>
                                <span
                                    class="text-[10px] px-2 py-0.5 rounded bg-<?= $p['file_type'] === 'video' ? 'rose' : 'blue' ?>-500/10 text-<?= $p['file_type'] === 'video' ? 'rose' : 'blue' ?>-600 dark:text-<?= $p['file_type'] === 'video' ? 'rose' : 'blue' ?>-400 font-bold uppercase tracking-wider">
                                    <?= $p['file_type'] ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- DECISION -->
        <?php if (in_array($order['admin_review_status'], ['pending', 'reviewing'], true)): ?>
            <form method="POST" action="release-escrow.php"
                class="bg-white dark:bg-slate-900 rounded-3xl border-2 border-violet-500/30 shadow-sm overflow-hidden">
                <?php nb_csrf_field(); ?>
                <input type="hidden" name="order_id" value="<?= $order_id ?>">

                <div class="p-6 border-b border-gray-100 dark:border-gray-800">
                    <div class="flex items-center gap-3">
                        <span
                            class="w-12 h-12 rounded-2xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center text-lg">
                            <i class="fa-solid fa-gavel"></i>
                        </span>
                        <div>
                            <h2 class="text-lg font-black">Your Decision</h2>
                            <p class="text-xs text-gray-500">Review both proofs side-by-side, then make the final call.</p>
                        </div>
                    </div>
                </div>

                <div class="p-6 space-y-5">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-2">Admin Notes (internal)</label>
                        <textarea name="admin_notes" rows="3" maxlength="1000"
                            placeholder="Any observations for the record..."
                            class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-violet-500 resize-none"></textarea>
                    </div>

                    <!-- Fraud detection checklist -->
                    <div class="p-4 rounded-2xl bg-amber-500/5 border border-amber-500/20">
                        <div class="text-xs font-bold text-amber-800 dark:text-amber-300 mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-list-check"></i> Before releasing, verify:
                        </div>
                        <ul class="space-y-2 text-xs text-gray-700 dark:text-gray-300">
                            <li class="flex items-start gap-2"><i
                                    class="fa-solid fa-check text-emerald-500 mt-0.5 text-[10px]"></i> Packing video shows
                                the actual item being sealed</li>
                            <li class="flex items-start gap-2"><i
                                    class="fa-solid fa-check text-emerald-500 mt-0.5 text-[10px]"></i> Item photos match the
                                auction listing</li>
                            <li class="flex items-start gap-2"><i
                                    class="fa-solid fa-check text-emerald-500 mt-0.5 text-[10px]"></i> Unboxing video is
                                continuous (no cuts)</li>
                            <li class="flex items-start gap-2"><i
                                    class="fa-solid fa-check text-emerald-500 mt-0.5 text-[10px]"></i> Buyer's received item
                                matches what seller shipped</li>
                            <li class="flex items-start gap-2"><i
                                    class="fa-solid fa-check text-emerald-500 mt-0.5 text-[10px]"></i> No signs of tampering
                                or fraud on either side</li>
                        </ul>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <button type="submit" name="action" value="approve"
                            class="py-4 px-4 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-black text-sm shadow-lg shadow-emerald-600/30 transition-all hover:-translate-y-0.5"
                            onclick="return confirm('✅ Release escrow to seller?\n\nThis confirms both proofs are valid.\nAmount: Rs. <?= number_format((float) $order['seller_amount']) ?>\n\nThis action is irreversible.')">
                            <i class="fa-solid fa-circle-check mr-1.5"></i> Release Payment
                        </button>

                        <button type="submit" name="action" value="hold"
                            class="py-4 px-4 rounded-xl bg-amber-600 hover:bg-amber-500 text-white font-black text-sm shadow-lg shadow-amber-600/30 transition-all hover:-translate-y-0.5"
                            onclick="return confirm('Hold payment for further investigation?')">
                            <i class="fa-solid fa-pause mr-1.5"></i> Hold / Investigate
                        </button>

                        <button type="submit" name="action" value="reject"
                            class="py-4 px-4 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-black text-sm shadow-lg shadow-rose-600/30 transition-all hover:-translate-y-0.5"
                            onclick="return confirm('❌ Reject this order?\n\nBuyer will be refunded.\nSeller will NOT receive payment.\n\nThis action is irreversible.')">
                            <i class="fa-solid fa-circle-xmark mr-1.5"></i> Reject & Refund
                        </button>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-6 text-center">
                <div class="text-sm text-gray-500">
                    This order has been <strong
                        class="<?= $order['admin_review_status'] === 'approved' ? 'text-emerald-600' : 'text-rose-600' ?>">
                        <?= ucfirst($order['admin_review_status']) ?>
                    </strong>
                    <?= !empty($order['admin_reviewed_at']) ? 'on ' . date('d M Y, h:i A', strtotime($order['admin_reviewed_at'])) : '' ?>.
                </div>
                <?php if (!empty($order['admin_review_notes'])): ?>
                    <div
                        class="mt-3 p-3 rounded-xl bg-gray-100 dark:bg-slate-800 text-xs text-gray-700 dark:text-gray-300 text-left">
                        <strong>Admin notes:</strong> <?= htmlspecialchars($order['admin_review_notes']) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <script>
        (function () {const h = document.documentElement, s = localStorage.getItem('theme'); if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) h.classList.add('dark'); document.getElementById('themeToggle')?.addEventListener('click', () => {h.classList.toggle('dark'); localStorage.setItem('theme', h.classList.contains('dark') ? 'dark' : 'light');});})();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>