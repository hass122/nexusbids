<?php
session_start();
date_default_timezone_set("Asia/Karachi");

require_once "config/database.php";
require_once "includes/csrf.php";
require_once "includes/notifications.php";
require_once "includes/payment-helpers.php";
require_once "includes/ledger.php";

if (isset($conn) && $conn instanceof mysqli) {
    $conn->query("SET time_zone = '+05:00'");
}

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

$user_id    = (int) $_SESSION["user_id"];
$auction_id = (int) ($_GET["auction_id"] ?? $_POST["auction_id"] ?? 0);

if ($auction_id <= 0) {
    header("Location: user/orders.php");
    exit();
}

$error_msg   = "";
$success_msg = "";

/* =========================================================
   FETCH ORDER + AUCTION + SELLER
========================================================= */
$order = null;

$stmt = $conn->prepare("
    SELECT
        o.order_id, o.order_number, o.order_status, o.payment_status,
        o.winning_bid, o.platform_fee, o.seller_amount, o.total_amount,
        o.winner_id, o.buyer_id, o.seller_id,
        a.auction_id, a.title AS auction_title, a.image AS auction_image,
        s.full_name AS seller_name
    FROM orders o
    INNER JOIN auctions a ON a.auction_id = o.auction_id
    INNER JOIN users s    ON s.user_id = o.seller_id
    WHERE o.auction_id = ? AND o.buyer_id = ?
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param("ii", $auction_id, $user_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$order) {
    /* Fallback: winners table (agar order auto-create nahi hua) */
    $fb = $conn->prepare("
        SELECT w.winner_id, w.winning_bid, w.user_id AS buyer_id,
               a.auction_id, a.title AS auction_title, a.image AS auction_image,
               a.seller_id, s.full_name AS seller_name
        FROM winners w
        INNER JOIN auctions a ON a.auction_id = w.auction_id
        INNER JOIN users s    ON s.user_id = a.seller_id
        WHERE w.auction_id = ? AND w.user_id = ?
        LIMIT 1
    ");
    if ($fb) {
        $fb->bind_param("ii", $auction_id, $user_id);
        $fb->execute();
        $fallback = $fb->get_result()->fetch_assoc();
        $fb->close();
    } else {
        $fallback = null;
    }

    if (!$fallback) {
        die("<div style='text-align:center;padding:50px;font-family:sans-serif;'><h2>Order not found.</h2><a href='user/orders.php'>Back to My Orders</a></div>");
    }

    $order = [
        'order_id'       => null,
        'order_number'   => 'NB-' . date('Y') . '-' . str_pad((string) $fallback['auction_id'], 5, '0', STR_PAD_LEFT),
        'order_status'   => 'pending_payment',
        'payment_status' => 'unpaid',
        'winning_bid'    => $fallback['winning_bid'],
        'platform_fee'   => 0,
        'seller_amount'  => $fallback['winning_bid'],
        'total_amount'   => $fallback['winning_bid'],
        'winner_id'      => $fallback['winner_id'],
        'buyer_id'       => $fallback['buyer_id'],
        'seller_id'      => $fallback['seller_id'],
        'auction_id'     => $fallback['auction_id'],
        'auction_title'  => $fallback['auction_title'],
        'auction_image'  => $fallback['auction_image'],
        'seller_name'    => $fallback['seller_name'],
    ];
}

$order_id       = $order['order_id'];
$auction_title  = $order['auction_title'];
$seller_name    = $order['seller_name'];
$winning_bid    = (float) $order['winning_bid'];
$total_amount   = (float) $order['total_amount'];
$already_paid   = ($order['payment_status'] === 'paid');

/* =========================================================
   FETCH PAYMENT METHODS
========================================================= */
$methods = function_exists('nb_payment_methods') ? nb_payment_methods() : [];

$method_aliases = [
    'cash_pickup' => 'cash_on_delivery',
];

/* =========================================================
   HANDLE FORM SUBMISSION
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !$already_paid) {

    nb_csrf_check();

    $payment_method = trim($_POST["payment_method"] ?? "");
    $sender_name    = trim($_POST["sender_name"] ?? "");
    $sender_number  = trim($_POST["sender_number"] ?? "");
    $transaction_id = trim($_POST["transaction_id"] ?? "");

    if (isset($method_aliases[$payment_method])) {
        $payment_method = $method_aliases[$payment_method];
    }

    if (!isset($methods[$payment_method])) {
        $error_msg = "Please select a valid payment method.";
    } elseif (mb_strlen($sender_name) < 2) {
        $error_msg = "Please enter your name.";
    } elseif (mb_strlen($sender_number) < 7) {
        $error_msg = "Please enter a valid mobile number.";
    } elseif (
        in_array('transaction_id', $methods[$payment_method]['requires'] ?? [], true)
        && $transaction_id === ''
    ) {
        $error_msg = "Please enter the transaction ID from your payment.";
    } else {

        try {
            $conn->begin_transaction();

            if ($order_id) {
                $lock = $conn->prepare("SELECT order_id FROM orders WHERE order_id = ? FOR UPDATE");
                $lock->bind_param("i", $order_id);
                $lock->execute();
                $lock->get_result()->fetch_assoc();
                $lock->close();
            }

            $chk = $conn->prepare("SELECT payment_id FROM payments WHERE auction_id = ? AND user_id = ? LIMIT 1 FOR UPDATE");
            $chk->bind_param("ii", $auction_id, $user_id);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();

            $initial_status = ($payment_method === 'cash_on_delivery') ? 'pending' : 'processing';

            if ($existing) {
                $upd = $conn->prepare("
                    UPDATE payments
                    SET payment_method   = ?,
                        sender_name      = ?,
                        sender_number    = ?,
                        transaction_id   = ?,
                        payment_status   = ?,
                        order_id         = COALESCE(?, order_id),
                        initiated_at     = COALESCE(initiated_at, NOW())
                    WHERE payment_id = ?
                ");
                $upd->bind_param(
                    "sssssii",
                    $payment_method,
                    $sender_name,
                    $sender_number,
                    $transaction_id,
                    $initial_status,
                    $order_id,
                    $existing['payment_id']
                );
                $upd->execute();
                $upd->close();
                $payment_id = (int) $existing['payment_id'];
            } else {
                if ($order_id === null) {
                    $stmt = $conn->prepare("
                        INSERT INTO payments
                            (order_id, auction_id, winner_id, user_id,
                             amount, payment_method, sender_name, sender_number,
                             transaction_id, payment_status, initiated_at)
                        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    if (!$stmt) {
                        throw new RuntimeException("Payment insert prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param(
                        "iiidsssss",
                        $auction_id,
                        $order['winner_id'],
                        $user_id,
                        $winning_bid,
                        $payment_method,
                        $sender_name,
                        $sender_number,
                        $transaction_id,
                        $initial_status
                    );
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO payments
                            (order_id, auction_id, winner_id, user_id,
                             amount, payment_method, sender_name, sender_number,
                             transaction_id, payment_status, initiated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    if (!$stmt) {
                        throw new RuntimeException("Payment insert prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param(
                        "iiiidsssss",
                        $order_id,
                        $auction_id,
                        $order['winner_id'],
                        $user_id,
                        $winning_bid,
                        $payment_method,
                        $sender_name,
                        $sender_number,
                        $transaction_id,
                        $initial_status
                    );
                }
                $stmt->execute();
                $payment_id = (int) $stmt->insert_id;
                $stmt->close();
            }

            if (function_exists('nb_payment_log')) {
                nb_payment_log(
                    $conn,
                    $payment_id,
                    $initial_status,
                    "Checkout submitted via {$payment_method}",
                    $user_id,
                    'buyer'
                );
            }

            /* Update order → payment pending */
            if ($order_id) {
                $upd = $conn->prepare("UPDATE orders SET payment_status = 'pending' WHERE order_id = ?");
                $upd->bind_param("i", $order_id);
                $upd->execute();
                $upd->close();
            }

            /* ---- ESCROW AUTO-CREATE ---- */
            if ($order_id && function_exists('nb_ledger_tables_exist')) {
                try {
                    $lk = nb_ledger_tables_exist($conn);
                    if ($lk['escrow_holds'] ?? false) {
                        $e_chk = $conn->prepare("SELECT id FROM escrow_holds WHERE order_id = ? LIMIT 1");
                        $e_chk->bind_param("i", $order_id);
                        $e_chk->execute();
                        $e_exists = $e_chk->get_result()->fetch_assoc();
                        $e_chk->close();

                        if (!$e_exists) {
                            $e_ins = $conn->prepare("
                                INSERT INTO escrow_holds
                                    (order_id, seller_id, buyer_id, amount,
                                     released_amount, refunded_amount,
                                     status, hold_reason, auto_release_at, created_at)
                                VALUES (?, ?, ?, ?, 0.00, 0.00,
                                        'held', 'order_placed',
                                        DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())
                            ");
                            $e_ins->bind_param(
                                "iiid",
                                $order_id,
                                $order['seller_id'],
                                $user_id,
                                $total_amount
                            );
                            $e_ins->execute();
                            $e_ins->close();
                        }
                    }
                } catch (Throwable $e) {
                    error_log("escrow auto-create (checkout) failed: " . $e->getMessage());
                }
            }

            /* Notify seller */
            if (function_exists('nb_notify')) {
                try {
                    nb_notify(
                        $conn,
                        (int) $order['seller_id'],
                        'payment',
                        'Payment submitted by buyer',
                        'Your auction "' . $auction_title . '" has a payment awaiting verification.',
                        'seller/orders.php'
                    );
                } catch (Throwable $e) {}
            }

            /* Notify admins */
            if (function_exists('nb_notify')) {
                try {
                    $ar = $conn->query("SELECT user_id FROM users WHERE role = 'admin'");
                    if ($ar) {
                        while ($adm = $ar->fetch_assoc()) {
                            nb_notify(
                                $conn,
                                (int) $adm['user_id'],
                                'payment',
                                'New Payment Submitted',
                                'Order ' . $order['order_number'] . ' — Rs. ' . number_format($total_amount)
                                    . ' via ' . ucfirst(str_replace('_', ' ', $payment_method)) . '. Awaiting verification.',
                                'admin/payments.php'
                            );
                        }
                    }
                } catch (Throwable $e) {}
            }

            $conn->commit();

            $_SESSION['checkout_success'] = "Payment details submitted! We'll verify and confirm shortly.";
            header("Location: user/orders.php");
            exit();

        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $e2) {}
            error_log("checkout failed: " . $e->getMessage());
            $error_msg = "Something went wrong. Please try again.";
        }
    }
}

function nb_auction_image_url(?string $image): ?string
{
    if (!$image) return null;
    return 'uploads/auctions/' . rawurlencode($image);
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Payment · <?php echo htmlspecialchars($auction_title, ENT_QUOTES, 'UTF-8'); ?> · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>

<body class="bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100 font-sans antialiased min-h-screen">

<div class="max-w-3xl mx-auto px-4 sm:px-6 py-12">

    <a href="user/orders.php"
       class="inline-flex items-center text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:underline mb-6">
        <i class="fa-solid fa-arrow-left mr-2"></i> Back to My Orders
    </a>

    <div class="mb-6">
        <h1 class="text-3xl font-black tracking-tight">Complete Payment</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">
            Order <span class="font-mono font-semibold text-slate-700 dark:text-slate-300">
                <?php echo htmlspecialchars((string) $order['order_number'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </p>
    </div>

    <?php if ($error_msg): ?>
        <div class="mb-6 p-4 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/60 text-sm text-rose-700 dark:text-rose-300 flex items-center gap-3">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['checkout_success'])): ?>
        <div class="mb-6 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-900/60 text-sm text-emerald-700 dark:text-emerald-300 flex items-center gap-3">
            <i class="fa-solid fa-circle-check"></i>
            <span><?php echo htmlspecialchars($_SESSION['checkout_success'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php unset($_SESSION['checkout_success']); ?>
    <?php endif; ?>

    <!-- Order Summary -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl p-6 shadow-sm border border-slate-200 dark:border-slate-800 mb-6">
        <div class="flex items-start gap-4">
            <?php $img = nb_auction_image_url($order['auction_image'] ?? null); ?>
            <?php if ($img): ?>
                <img src="<?php echo htmlspecialchars($img, ENT_QUOTES, 'UTF-8'); ?>"
                     class="w-20 h-20 rounded-xl object-cover" alt="">
            <?php else: ?>
                <div class="w-20 h-20 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center">
                    <i class="fa-solid fa-image text-2xl"></i>
                </div>
            <?php endif; ?>
            <div class="flex-1 min-w-0">
                <h2 class="font-bold text-lg text-slate-900 dark:text-white line-clamp-2">
                    <?php echo htmlspecialchars($auction_title, ENT_QUOTES, 'UTF-8'); ?>
                </h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    Seller: <span class="font-medium"><?php echo htmlspecialchars($seller_name, ENT_QUOTES, 'UTF-8'); ?></span>
                </p>
            </div>
            <div class="text-right shrink-0">
                <div class="text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-semibold">Total</div>
                <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400">
                    Rs. <?php echo number_format($total_amount); ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($already_paid): ?>

        <div class="bg-white dark:bg-slate-900 rounded-2xl p-8 shadow-sm border border-emerald-200 dark:border-emerald-900 text-center">
            <div class="w-20 h-20 mx-auto rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-3xl mb-4">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <h2 class="text-2xl font-black text-slate-900 dark:text-white mb-2">Payment Confirmed</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
                This order has been paid. The seller will begin processing shortly.
            </p>
            <a href="user/orders.php"
               class="inline-block px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-semibold">
                <i class="fa-solid fa-receipt mr-2"></i> View My Orders
            </a>
        </div>

    <?php else: ?>

        <form method="POST"
              class="bg-white dark:bg-slate-900 rounded-2xl p-6 shadow-sm border border-slate-200 dark:border-slate-800 space-y-6"
              id="checkoutForm"
              novalidate>
            <?php nb_csrf_field(); ?>
            <input type="hidden" name="auction_id" value="<?php echo (int) $auction_id; ?>">

            <!-- Method Selection -->
            <div>
                <label class="block text-sm font-bold text-slate-800 dark:text-slate-200 mb-3">
                    <i class="fa-solid fa-wallet text-indigo-500 mr-1"></i> Payment Method
                </label>

                <?php if (empty($methods)): ?>
                    <div class="p-4 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/60 text-sm text-rose-700 dark:text-rose-300">
                        No payment methods are currently available. Please contact support.
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3" id="methodGrid">
                        <?php $first = true; foreach ($methods as $key => $m): ?>
                            <label class="method-card relative flex flex-col p-4 rounded-xl border-2 <?php echo $first ? 'border-indigo-600 bg-indigo-50/50 dark:bg-indigo-950/30' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800'; ?> cursor-pointer transition-all hover:border-indigo-400"
                                   data-requires-txn="<?php echo in_array('transaction_id', $m['requires'] ?? [], true) ? '1' : '0'; ?>">
                                <div class="flex items-center justify-between mb-2">
                                    <i class="fa-solid <?php echo htmlspecialchars($m['icon'], ENT_QUOTES, 'UTF-8'); ?> text-indigo-600 dark:text-indigo-400 text-lg"></i>
                                    <input type="radio" name="payment_method" value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                                           <?php echo $first ? 'checked' : ''; ?>
                                           class="method-radio h-4 w-4 text-indigo-600 focus:ring-indigo-500" required>
                                </div>
                                <span class="text-xs font-bold text-slate-900 dark:text-white">
                                    <?php echo htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5 leading-tight">
                                    <?php echo htmlspecialchars($m['description'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </label>
                        <?php $first = false; endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($methods)): ?>
                <!-- Transaction Details -->
                <div class="border-t border-slate-100 dark:border-slate-800 pt-6 space-y-4">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">Payment Information</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 -mt-2">
                        After sending the payment, enter your transaction details below. Our team will verify and confirm.
                    </p>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Your Name *</label>
                        <input type="text" name="sender_name" required maxlength="30"
                               class="w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800 text-sm py-2.5 px-3 focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="Full name as on the payment">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Mobile Number *</label>
                        <input type="tel" name="sender_number" required maxlength="30"
                               class="w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800 text-sm py-2.5 px-3 focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="03XX XXXXXXX">
                    </div>

                    <div id="txnField">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                            Transaction ID / Reference
                            <span class="text-slate-400 font-normal">(required for JazzCash/Easypaisa/Bank/Card)</span>
                        </label>
                        <input type="text" name="transaction_id" id="transaction_id" maxlength="100"
                               class="w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800 text-sm py-2.5 px-3 focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="e.g. TXN123456789">
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/60 flex items-start gap-3">
                    <i class="fa-solid fa-shield-halved text-amber-600 dark:text-amber-400 mt-0.5"></i>
                    <div class="text-xs text-amber-800 dark:text-amber-300 leading-relaxed">
                        <strong>Secure verification:</strong> Your payment is not auto-marked as "paid." Our team
                        will verify the transaction ID before confirming your order. You'll receive a notification as soon as it's confirmed.
                    </div>
                </div>

                <button type="submit"
                        class="w-full py-3.5 px-6 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-700 hover:to-violet-700 text-white font-bold text-sm shadow-lg shadow-indigo-600/30 transition-all">
                    <i class="fa-solid fa-lock mr-2"></i> Submit Payment Details
                </button>
            <?php endif; ?>
        </form>

    <?php endif; ?>

</div>

<script>
(function () {
    const form = document.getElementById('checkoutForm');
    if (!form) return;

    const txnInput = document.getElementById('transaction_id');
    const txnField = document.getElementById('txnField');
    const methodCards = document.querySelectorAll('.method-card');
    const methodRadios = document.querySelectorAll('.method-radio');

    function updateTxnRequirement() {
        const checked = document.querySelector('input[name="payment_method"]:checked');
        if (!checked || !txnInput) return;
        const card = checked.closest('.method-card');
        const requires = card && card.dataset.requiresTxn === '1';
        txnInput.required = requires;
        if (txnField) txnField.style.display = requires ? '' : 'none';
    }

    methodRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            methodCards.forEach(function (card) {
                card.classList.remove('border-indigo-600', 'bg-indigo-50/50', 'dark:bg-indigo-950/30');
                card.classList.add('border-slate-200', 'dark:border-slate-700', 'bg-white', 'dark:bg-slate-800');
            });
            if (this.checked) {
                const parent = this.closest('.method-card');
                parent.classList.remove('border-slate-200', 'dark:border-slate-700', 'bg-white', 'dark:bg-slate-800');
                parent.classList.add('border-indigo-600', 'bg-indigo-50/50', 'dark:bg-indigo-950/30');
            }
            updateTxnRequirement();
        });
    });

    updateTxnRequirement();
})();
</script>
<?php require_once __DIR__ . '/includes/chat-widget.php'; ?>

</body>

</html>