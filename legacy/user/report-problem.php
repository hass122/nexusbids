<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set("Asia/Karachi");

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/csrf.php";
require_once __DIR__ . "/../includes/notifications.php";

$user_id = (int) $_SESSION['user_id'];
$order_id = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);

if ($order_id <= 0) {
    $_SESSION['flash_error'] = "Invalid order.";
    header("Location: orders.php");
    exit();
}

/* Fetch order + seller */
$order = null;
if (
    $stmt = $conn->prepare("
    SELECT
        o.order_id, o.order_number, o.order_status, o.payment_status,
        o.total_amount, o.seller_amount, o.delivered_at, o.auction_id,
        a.title AS auction_title, a.image AS auction_image,
        s.user_id AS seller_id, s.full_name AS seller_name
    FROM orders o
    INNER JOIN auctions a ON a.auction_id = o.auction_id
    INNER JOIN users    s ON s.user_id    = o.seller_id
    WHERE o.order_id = ? AND o.buyer_id = ?
    LIMIT 1
")
) {
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$order) {
    $_SESSION['flash_error'] = "Order not found.";
    header("Location: orders.php");
    exit();
}

/* Check if dispute already exists */
$existing_dispute = null;
$has_disputes_table = false;
try {
    $r = $conn->query("SHOW TABLES LIKE 'payment_disputes'");
    $has_disputes_table = $r && $r->num_rows > 0;
} catch (Throwable $e) {
}

if ($has_disputes_table) {
    if ($ds = $conn->prepare("SELECT id, status FROM payment_disputes WHERE order_id = ? LIMIT 1")) {
        $ds->bind_param("i", $order_id);
        $ds->execute();
        $existing_dispute = $ds->get_result()->fetch_assoc();
        $ds->close();
    }
}

/* ---------- POST: Submit dispute ---------- */
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nb_csrf_check();

    $dispute_type = trim($_POST['dispute_type'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $evidence = trim($_POST['evidence'] ?? '');

    $allowed_types = ['item_not_received', 'item_not_as_described', 'unauthorized', 'other'];
    if (!in_array($dispute_type, $allowed_types, true)) {
        $error = "Please select a valid issue type.";
    } elseif (mb_strlen($reason) < 10) {
        $error = "Please describe the issue (at least 10 characters).";
    } elseif (mb_strlen($reason) > 2000) {
        $error = "Description is too long (max 2000 characters).";
    } elseif (!$has_disputes_table) {
        $error = "Disputes system is not available. Contact support.";
    } elseif ($existing_dispute) {
        $error = "A dispute already exists for this order.";
    } else {
        $conn->begin_transaction();
        try {
            /* Insert dispute */
            $ins = $conn->prepare("
                INSERT INTO payment_disputes
                    (order_id, seller_id, buyer_id, dispute_type, amount, reason, evidence, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'open', NOW())
            ");
            $amount = (float) $order['total_amount'];
            $ins->bind_param(
                "iiisdss",
                $order_id,
                $order['seller_id'],
                $user_id,
                $dispute_type,
                $amount,
                $reason,
                $evidence
            );
            $ins->execute();
            $dispute_id = $conn->insert_id;
            $ins->close();

            /* Freeze escrow hold */
            try {
                $es = $conn->prepare("
                    UPDATE escrow_holds
                    SET status = 'disputed'
                    WHERE order_id = ? AND status IN ('held', 'partially_released')
                ");
                $es->bind_param("i", $order_id);
                $es->execute();
                $es->close();
            } catch (Throwable $e) {
            }

            /* Mark order as disputed */
            try {
                $uo = $conn->prepare("UPDATE orders SET order_status = 'disputed' WHERE order_id = ?");
                $uo->bind_param("i", $order_id);
                $uo->execute();
                $uo->close();
            } catch (Throwable $e) {
            }

            /* Notify seller */
            if (function_exists('nb_notify')) {
                try {
                    nb_notify(
                        $conn,
                        (int) $order['seller_id'],
                        'dispute',
                        'Buyer Reported a Problem',
                        'Dispute opened on order ' . $order['order_number'] . '. Please provide evidence to support your case.',
                        'seller/disputes.php'
                    );
                } catch (Throwable $e) {
                }
            }

            /* Notify admin */
            try {
                $ar = $conn->query("SELECT user_id FROM users WHERE role = 'admin'");
                while ($row = $ar->fetch_assoc()) {
                    if (function_exists('nb_notify')) {
                        nb_notify(
                            $conn,
                            (int) $row['user_id'],
                            'dispute',
                            'New Dispute Filed',
                            'Order ' . $order['order_number'] . ' — Rs. ' . number_format($amount) . ' disputed. Requires review.',
                            'admin/disputes.php'
                        );
                    }
                }
            } catch (Throwable $e) {
            }

            /* Audit */
            if (function_exists('nb_pay_audit')) {
                try {
                    nb_pay_audit($conn, [
                        'actor_id' => $user_id,
                        'actor_type' => 'buyer',
                        'entity_type' => 'dispute',
                        'entity_id' => $dispute_id,
                        'action' => 'dispute.created',
                        'new' => ['order_id' => $order_id, 'type' => $dispute_type, 'amount' => $amount],
                    ]);
                } catch (Throwable $e) {
                }
            }

            $conn->commit();
            $_SESSION['flash_success'] = "Dispute submitted. Our team will review and respond within 2–3 business days.";
            header("Location: order-details.php?id=" . (int) $order['auction_id']);
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("report-problem failed: " . $e->getMessage());
            $error = "Could not submit the dispute. Please try again.";
        }
    }
}

/* ---------- Fetch user info for navbar ---------- */
$u_stmt = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$uinfo = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();
$full_name = $uinfo['full_name'] ?? 'User';
$avatar = $uinfo['avatar'] ?? '';
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = function_exists('nb_unread_count') ? nb_unread_count($conn, $user_id) : 0;

$img_url = '';
if (!empty($order['auction_image'])) {
    $b = basename($order['auction_image']);
    if (file_exists(__DIR__ . '/../uploads/auctions/' . $b)) {
        $img_url = '../uploads/auctions/' . rawurlencode($b);
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report a Problem · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } } };</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen">

    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-gray-200 dark:border-gray-800">
        <div class="max-w-5xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between">
            <a href="../index.php" class="flex items-center gap-2.5 text-xl font-black">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white"><i
                        class="fa-solid fa-gavel"></i></span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            </a>
            <div class="flex items-center gap-3">
                <button type="button" id="themeToggle"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-slate-800 flex items-center justify-center">
                    <i class="fa-regular fa-sun dark:hidden"></i><i class="fa-regular fa-moon hidden dark:inline"></i>
                </button>
                <a href="order-details.php?id=<?= (int) $order['auction_id'] ?>"
                    class="text-sm font-semibold text-gray-600 dark:text-gray-300 hover:text-indigo-600">
                    <i class="fa-solid fa-arrow-left mr-1"></i> Order Details
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-3xl mx-auto px-4 sm:px-8 py-8 space-y-6">

        <div>
            <h1 class="text-3xl font-black tracking-tight flex items-center gap-3">
                <span
                    class="w-12 h-12 rounded-2xl bg-gradient-to-br from-rose-500 to-red-500 flex items-center justify-center text-white shadow-lg">
                    <i class="fa-solid fa-triangle-exclamation text-lg"></i>
                </span>
                Report a Problem
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">If something went wrong with this order, let us
                know. Our team will investigate.</p>
        </div>

        <?php if ($existing_dispute): ?>
            <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-start gap-3">
                <i class="fa-solid fa-circle-info text-amber-500 mt-0.5"></i>
                <div class="text-sm text-amber-800 dark:text-amber-300">
                    <strong>Dispute already filed.</strong> Status:
                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $existing_dispute['status']))) ?>.
                    Track it in <a href="order-details.php?id=<?= (int) $order['auction_id'] ?>"
                        class="underline font-bold">order details</a>.
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 flex items-start gap-3">
                <i class="fa-solid fa-circle-exclamation text-rose-500 mt-0.5"></i>
                <p class="text-sm text-rose-700 dark:text-rose-300"><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>

        <!-- Order summary -->
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-5 flex items-center gap-4">
            <?php if ($img_url): ?>
                <img src="<?= htmlspecialchars($img_url) ?>" class="w-16 h-16 rounded-xl object-cover" alt="">
            <?php else: ?>
                <div class="w-16 h-16 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center"><i
                        class="fa-solid fa-image"></i></div>
            <?php endif; ?>
            <div class="flex-1 min-w-0">
                <div class="font-mono text-xs text-gray-500"><?= htmlspecialchars($order['order_number']) ?></div>
                <div class="font-bold truncate"><?= htmlspecialchars($order['auction_title']) ?></div>
                <div class="text-xs text-gray-500 mt-0.5">Seller:
                    <strong><?= htmlspecialchars($order['seller_name']) ?></strong></div>
            </div>
            <div class="text-right shrink-0">
                <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Amount</div>
                <div class="font-black text-rose-600 dark:text-rose-400 tabular-nums">Rs.
                    <?= number_format((float) $order['total_amount']) ?></div>
            </div>
        </div>

        <?php if (!$existing_dispute && $has_disputes_table): ?>
            <form method="POST"
                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-6 space-y-5">
                <?php nb_csrf_field(); ?>
                <input type="hidden" name="order_id" value="<?= (int) $order_id ?>">

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">What's the issue? <span
                            class="text-rose-500">*</span></label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <?php
                        $types = [
                            'item_not_received' => ['Item Not Received', 'fa-box-open'],
                            'item_not_as_described' => ['Not As Described', 'fa-circle-info'],
                            'unauthorized' => ['Unauthorized Charge', 'fa-shield-halved'],
                            'other' => ['Other Issue', 'fa-ellipsis'],
                        ];
                        foreach ($types as $k => $v): ?>
                            <label class="cursor-pointer">
                                <input type="radio" name="dispute_type" value="<?= $k ?>" required class="peer sr-only">
                                <div
                                    class="p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700 peer-checked:border-rose-500 peer-checked:bg-rose-500/10 transition">
                                    <div class="flex items-center gap-2">
                                        <i class="fa-solid <?= $v[1] ?> text-rose-500 text-sm"></i>
                                        <span class="text-xs font-bold"><?= $v[0] ?></span>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Describe the issue <span
                            class="text-rose-500">*</span></label>
                    <textarea name="reason" rows="4" required minlength="10" maxlength="2000"
                        placeholder="Explain what happened in detail..."
                        class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rose-500 resize-none"></textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Evidence (optional)</label>
                    <textarea name="evidence" rows="3" maxlength="2000"
                        placeholder="Describe any evidence — photos, messages, tracking info..."
                        class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rose-500 resize-none"></textarea>
                </div>

                <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-start gap-2">
                    <i class="fa-solid fa-circle-info text-amber-500 text-xs mt-0.5"></i>
                    <p class="text-[11px] text-amber-800 dark:text-amber-300 leading-relaxed">
                        Submitting a dispute freezes the seller's payout until resolved. Both sides will be asked to provide
                        evidence.
                    </p>
                </div>

                <div class="flex gap-2 pt-2">
                    <a href="order-details.php?id=<?= (int) $order['auction_id'] ?>"
                        class="flex-1 py-3 rounded-xl bg-gray-100 dark:bg-slate-800 text-center text-sm font-semibold">Cancel</a>
                    <button type="submit"
                        class="flex-1 py-3 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-sm font-bold shadow-lg shadow-rose-600/30 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i> Submit Dispute
                    </button>
                </div>
            </form>
        <?php elseif (!$has_disputes_table): ?>
            <div class="p-6 rounded-3xl bg-rose-500/10 border border-rose-500/30 text-center">
                <i class="fa-solid fa-circle-exclamation text-rose-500 text-3xl mb-3"></i>
                <h3 class="font-bold mb-1">Disputes system unavailable</h3>
                <p class="text-xs text-gray-600 dark:text-gray-400">Please contact support directly at
                    support@nexusbids.local</p>
            </div>
        <?php endif; ?>
    </main>

    <script>
        (function () {
            const html = document.documentElement;
            const btn = document.getElementById('themeToggle');
            const saved = localStorage.getItem('theme');
            if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            btn?.addEventListener('click', () => {
                html.classList.toggle('dark');
                localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
            });
        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>