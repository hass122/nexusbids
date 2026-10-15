<?php
require_once dirname(__DIR__) . "/_bootstrap.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: " . nb_url('/auth/login.php'));
    exit();
}

$user_id = (int) $_SESSION["user_id"];
$auction_id = (int) ($_GET["auction_id"] ?? 0);

if ($auction_id <= 0) {
    header("Location: " . nb_url('/user/orders.php'));
    exit();
}

$cfg = nb_gateway_config('bank_transfer');
if (!$cfg) {
    nb_gateway_die('Unavailable', 'Bank transfer is not enabled.', '/checkout.php?auction_id=' . $auction_id);
}

$stmt = $conn->prepare("
    SELECT o.order_id, o.order_number, o.total_amount, o.payment_status, o.winner_id
    FROM orders o WHERE o.auction_id = ? AND o.buyer_id = ? LIMIT 1
");
$stmt->bind_param("ii", $auction_id, $user_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    nb_gateway_die('Order not found', 'This order could not be located.', '/user/orders.php');
}

$error = '';
$success = '';
$already_submitted = ($order['payment_status'] === 'processing' || $order['payment_status'] === 'pending');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already_submitted) {
    nb_csrf_check();

    $txn_ref = trim($_POST['transaction_id'] ?? '');
    $sender_name = trim($_POST['sender_name'] ?? '');

    if ($txn_ref === '' || $sender_name === '') {
        $error = 'Please fill in all required fields.';
    } elseif (mb_strlen($txn_ref) > 100 || mb_strlen($sender_name) > 100) {
        $error = 'Input too long.';
    } else {
        $conn->begin_transaction();
        try {
            $chk = $conn->prepare("SELECT payment_id FROM payments WHERE auction_id = ? AND user_id = ? LIMIT 1");
            $chk->bind_param("ii", $auction_id, $user_id);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();

            $order_id_int = (int) $order['order_id'];
            $total_amount = (float) $order['total_amount'];

            if ($existing) {
                $payment_id = (int) $existing['payment_id'];
                $upd = $conn->prepare("
    UPDATE payments
    SET order_id = ?, winner_id = ?, payment_method = 'bank_transfer',
        transaction_id = ?, sender_name = ?,
        payment_status = 'processing', initiated_at = NOW()
    WHERE payment_id = ?
");
                $upd->bind_param("iissi", $order_id, $order['winner_id'], $txn_ref, $sender_name, $payment_id);
                $upd->execute();
                $upd->close();
            } else {
                $ins = $conn->prepare("
    INSERT INTO payments
        (order_id, auction_id, winner_id, user_id, amount, payment_method,
         transaction_id, sender_name, payment_status, initiated_at)
    VALUES (?, ?, ?, ?, ?, 'bank_transfer', ?, ?, 'processing', NOW())
");
                $ins->bind_param(
                    "iiiidss",
                    $order_id,
                    $auction_id,
                    $order['winner_id'],
                    $user_id,
                    $total_amount,
                    $txn_ref,
                    $sender_name
                );
                $ins->execute();
                $payment_id = (int) $ins->insert_id;
                $ins->close();
            }

            if (function_exists('nb_payment_log')) {
                nb_payment_log($conn, $payment_id, 'processing', "Bank transfer: $txn_ref", $user_id, 'buyer');
            }

            // Update order status
            $upd = $conn->prepare("UPDATE orders SET payment_status = 'pending' WHERE order_id = ?");
            $upd->bind_param("i", $order_id_int);
            $upd->execute();
            $upd->close();

            if (function_exists('nb_notify')) {
                nb_notify(
                    $conn,
                    $user_id,
                    'payment',
                    'Bank transfer submitted',
                    'Our team will verify your payment within 24 hours.',
                    'user/order-details.php?id=' . $auction_id
                );
            }

            $conn->commit();
            $success = 'Details submitted! We will verify your transfer shortly.';

        } catch (Throwable $e) {
            $conn->rollback();
            error_log("Bank transfer submission failed: " . $e->getMessage());
            $error = 'Could not save your details. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Transfer Instructions · NexusBids</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
</head>

<body class="bg-slate-50 font-sans min-h-screen py-12 px-4">
    <div class="max-w-3xl mx-auto">
        <a href="<?= htmlspecialchars(nb_url('/checkout.php?auction_id=' . $auction_id), ENT_QUOTES, 'UTF-8') ?>"
            class="inline-flex items-center text-sm font-semibold text-indigo-600 hover:underline mb-6">
            <i class="fa-solid fa-arrow-left mr-2"></i> Back to Checkout
        </a>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8 mb-6">
            <h1 class="text-2xl font-black text-slate-900 mb-2">
                <i class="fa-solid fa-building-columns text-blue-600 mr-2"></i> Bank Transfer
            </h1>
            <p class="text-sm text-slate-500">
                Order <span
                    class="font-mono font-semibold"><?= htmlspecialchars((string) $order['order_number'], ENT_QUOTES, 'UTF-8') ?></span>
                · Rs. <?= number_format((float) $order['total_amount']) ?>
            </p>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mb-6">
            <h2 class="font-bold text-slate-900 mb-4">Transfer to:</h2>
            <div class="space-y-3 text-sm">
                <div class="flex justify-between py-2 border-b border-slate-100">
                    <span class="text-slate-500">Bank</span>
                    <strong><?= htmlspecialchars($cfg['bank_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="flex justify-between py-2 border-b border-slate-100">
                    <span class="text-slate-500">Account Title</span>
                    <strong><?= htmlspecialchars($cfg['account_title'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="flex justify-between py-2 border-b border-slate-100">
                    <span class="text-slate-500">Account Number</span>
                    <strong
                        class="font-mono"><?= htmlspecialchars($cfg['account_number'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="flex justify-between py-2">
                    <span class="text-slate-500">IBAN</span>
                    <strong class="font-mono"><?= htmlspecialchars($cfg['iban'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
            </div>

            <div class="mt-6 p-4 rounded-xl bg-blue-50 border border-blue-200">
                <h3 class="font-bold text-sm text-blue-900 mb-2">
                    <i class="fa-solid fa-circle-info mr-1"></i> Instructions
                </h3>
                <ol class="text-xs text-blue-800 space-y-1 list-decimal list-inside">
                    <?php foreach (($cfg['instructions'] ?? []) as $step): ?>
                        <li><?= htmlspecialchars((string) $step, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-6 text-center">
                <i class="fa-solid fa-circle-check text-emerald-600 text-3xl mb-3"></i>
                <h3 class="font-bold text-emerald-900">Submitted for Verification</h3>
                <p class="text-sm text-emerald-700 mt-1"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
                <a href="<?= htmlspecialchars(nb_url('/user/order-details.php?id=' . $auction_id), ENT_QUOTES, 'UTF-8') ?>"
                    class="inline-block mt-4 px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-sm font-semibold">
                    <i class="fa-solid fa-receipt mr-2"></i> View Order Details
                </a>
            </div>
        <?php elseif ($already_submitted): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-center">
                <i class="fa-solid fa-hourglass-half text-amber-600 text-3xl mb-3"></i>
                <h3 class="font-bold text-amber-900">Already Submitted</h3>
                <p class="text-sm text-amber-700 mt-1">Your bank transfer details are pending verification.</p>
                <a href="<?= htmlspecialchars(nb_url('/user/order-details.php?id=' . $auction_id), ENT_QUOTES, 'UTF-8') ?>"
                    class="inline-block mt-4 px-5 py-2.5 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-sm font-semibold">
                    View Order
                </a>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
            <form method="POST" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-5">
                <?php nb_csrf_field(); ?>
                <h2 class="font-bold text-slate-900">Confirm Your Transfer</h2>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Transaction Reference / Slip Number *</label>
                    <input type="text" name="transaction_id" required maxlength="100"
                        class="w-full rounded-lg border-slate-300 text-sm py-2.5 px-3 focus:border-indigo-500 focus:ring-indigo-500"
                        placeholder="e.g. FT26091312345">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Account Holder Name *</label>
                    <input type="text" name="sender_name" required maxlength="100"
                        class="w-full rounded-lg border-slate-300 text-sm py-2.5 px-3 focus:border-indigo-500 focus:ring-indigo-500"
                        placeholder="Name on the bank account">
                </div>
                <button type="submit"
                    class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-sm">
                    <i class="fa-solid fa-paper-plane mr-2"></i> Submit for Verification
                </button>
            </form>
        <?php endif; ?>
    </div>
</body>

</html>